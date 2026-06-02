<?php
declare(strict_types=1);

namespace App\Services\Agenda;

use App\Services\Optimization\OptimizationContext;
use App\Services\Optimization\ScheduleOptimizer;
use App\Services\Optimization\DTO\OptimizationResult;
use App\Services\Optimization\DTO\SlotCandidate;
use App\Services\Optimization\DTO\TurmaDisciplinaPair;
use PDO;

/**
 * Carrega todos os dados do banco, monta o contexto e dispara a otimização.
 * É o ponto de entrada para qualquer processo de geração de agenda.
 */
final class AgendaService
{
    public function __construct(
        private readonly ScheduleOptimizer $optimizer,
        private readonly PDO               $pdo,
    ) {}

    // =========================================================================
    // API pública
    // =========================================================================

    /**
     * Cria uma nova versão de agenda e executa o otimizador.
     *
     * @param int    $semestreId
     * @param int    $usuarioId     ID de quem disparou a geração
     * @param string $descricao     Descrição opcional da versão
     * @param bool   $simulacao     Se true, cria versão com status='simulacao'
     */
    public function gerarAgenda(
        int $semestreId,
        int $usuarioId,
        string $descricao = '',
        bool $simulacao = false,
    ): OptimizationResult {
        $semestre = $this->loadSemestre($semestreId);
        if (!$semestre) {
            throw new \InvalidArgumentException("Semestre #{$semestreId} não encontrado.");
        }

        $versaoId = $this->criarVersao($semestreId, $usuarioId, $descricao, $simulacao);

        $pairs    = $this->loadPairs($semestreId, $semestre['referencia']);
        $semanas  = $this->loadSemanas($semestreId);
        $clinicas = $this->loadClinicas();
        $labs     = $this->loadLaboratorios();
        $allSlots = $this->gerarSlots($semanas, $clinicas, $labs, $pairs);
        $ctx      = $this->buildContext($semestreId, $semanas, $clinicas, $labs);

        return $this->optimizer->otimizar($versaoId, $usuarioId, $pairs, $allSlots, $ctx);
    }

    /**
     * Publica uma versão rascunho tornando-a a agenda oficial do semestre.
     * Arquiva qualquer versão publicada anterior.
     */
    public function publicarVersao(int $versaoId, int $usuarioId): void
    {
        $versao = $this->pdo->prepare('SELECT semestre_id FROM agenda_versoes WHERE id = ?');
        $versao->execute([$versaoId]);
        $row = $versao->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \InvalidArgumentException("Versão #{$versaoId} não encontrada.");
        }

        $this->pdo->beginTransaction();
        try {
            // Arquiva publicadas anteriores
            $this->pdo->prepare(
                "UPDATE agenda_versoes SET status='arquivada' WHERE semestre_id=? AND status='publicada'"
            )->execute([$row['semestre_id']]);

            // Publica a versão solicitada
            $this->pdo->prepare(
                "UPDATE agenda_versoes SET status='publicada', publicada_em=NOW(), publicada_por=? WHERE id=?"
            )->execute([$usuarioId, $versaoId]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    /** Retorna todas as versões de um semestre, da mais recente para a mais antiga. */
    public function listarVersoes(int $semestreId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT av.*, u.nome AS criado_por_nome
             FROM agenda_versoes av
             LEFT JOIN usuarios u ON u.id = av.created_by
             WHERE av.semestre_id = ?
             ORDER BY av.numero_versao DESC'
        );
        $stmt->execute([$semestreId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Retorna detalhes de uma versão com seus agendamentos agrupados por semana. */
    public function detalheVersao(int $versaoId): array
    {
        $versao = $this->pdo->prepare(
            'SELECT av.*, s.referencia AS semestre_ref
             FROM agenda_versoes av
             JOIN semestres s ON s.id = av.semestre_id
             WHERE av.id = ?'
        );
        $versao->execute([$versaoId]);
        $v = $versao->fetch(PDO::FETCH_ASSOC);
        if (!$v) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT ag.*,
                    t.nome AS turma_nome, d.nome AS disciplina_nome,
                    p.nome AS professor_nome, pr.nome AS preceptor_nome,
                    ss.numero_semana
             FROM agendamentos ag
             JOIN turmas t       ON t.id  = ag.turma_id
             JOIN disciplinas d  ON d.id  = ag.disciplina_id
             LEFT JOIN professores p   ON p.id  = ag.professor_id
             LEFT JOIN preceptores pr  ON pr.id = ag.preceptor_id
             JOIN semanas_semestre ss  ON ss.id = ag.semana_id
             WHERE ag.versao_id = ?
             ORDER BY ag.data_aula, ag.hora_inicio, ag.espaco_tipo, ag.espaco_id'
        );
        $stmt->execute([$versaoId]);
        $agendamentos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $conflitos = $this->pdo->prepare(
            'SELECT * FROM conflitos WHERE versao_id = ? ORDER BY severidade, tipo'
        );
        $conflitos->execute([$versaoId]);

        $v['agendamentos'] = $agendamentos;
        $v['conflitos']    = $conflitos->fetchAll(PDO::FETCH_ASSOC);
        return $v;
    }

    // =========================================================================
    // Editor manual por semana
    // =========================================================================

    /**
     * Retorna todos os dados necessários para o editor semanal.
     */
    public function getDadosEditor(int $versaoId, int $semana): array
    {
        $versao = $this->pdo->prepare(
            'SELECT av.id, av.numero_versao, av.status, av.descricao,
                    s.referencia AS semestre_ref, s.id AS semestre_id
             FROM agenda_versoes av JOIN semestres s ON s.id = av.semestre_id
             WHERE av.id = ?'
        );
        $versao->execute([$versaoId]);
        $versaoData = $versao->fetch(PDO::FETCH_ASSOC);
        if (!$versaoData) {
            throw new \InvalidArgumentException("Versão #{$versaoId} não encontrada.");
        }

        // Semana atual
        $semanaStmt = $this->pdo->prepare(
            'SELECT id, numero_semana, data_inicio, data_fim
             FROM semanas_semestre
             WHERE semestre_id = ? AND numero_semana = ? LIMIT 1'
        );
        $semanaStmt->execute([$versaoData['semestre_id'], $semana]);
        $semanaData = $semanaStmt->fetch(PDO::FETCH_ASSOC);

        // Todas as semanas da versão para o seletor
        $semanasStmt = $this->pdo->prepare(
            'SELECT id, numero_semana, data_inicio, data_fim
             FROM semanas_semestre WHERE semestre_id = ? ORDER BY numero_semana'
        );
        $semanasStmt->execute([$versaoData['semestre_id']]);
        $semanas = $semanasStmt->fetchAll(PDO::FETCH_ASSOC);

        // Agendamentos desta semana
        $agStmt = $this->pdo->prepare(
            'SELECT a.id, a.dia_semana, a.hora_inicio, a.hora_fim, a.espaco_tipo,
                    a.num_alunos, a.status, a.gerado_por_ia, a.observacoes,
                    t.id AS turma_id, t.nome AS turma_nome,
                    d.id AS disciplina_id, d.nome AS disciplina_nome,
                    p.id AS professor_id, p.nome AS professor_nome,
                    pr.id AS preceptor_id, pr.nome AS preceptor_nome
             FROM agendamentos a
             JOIN semanas_semestre ss ON ss.id = a.semana_id
             JOIN turmas t            ON t.id  = a.turma_id
             JOIN disciplinas d       ON d.id  = a.disciplina_id
             LEFT JOIN professores p  ON p.id  = a.professor_id
             LEFT JOIN preceptores pr ON pr.id = a.preceptor_id
             WHERE a.versao_id = ? AND ss.numero_semana = ? AND a.status != "cancelado"
             ORDER BY a.dia_semana, a.hora_inicio'
        );
        $agStmt->execute([$versaoId, $semana]);
        $agendamentos = $agStmt->fetchAll(PDO::FETCH_ASSOC);

        // Agrupa por dia da semana e por turno
        $porDia     = [];
        $porDiaTurno = [];
        foreach ($agendamentos as $ag) {
            $dia   = (int)$ag['dia_semana'];
            $turno = $this->turnoDeHora($ag['hora_inicio']);
            $porDia[$dia][]          = $ag;
            $porDiaTurno[$dia][$turno][] = $ag;
        }

        // Pares turma+disciplina sem agendamento nesta semana
        $pendStmt = $this->pdo->prepare(
            'SELECT td.turma_id, t.nome AS turma_nome, t.numero_alunos,
                    td.disciplina_id, d.nome AS disciplina_nome, d.codigo AS disciplina_codigo,
                    CASE WHEN d.usa_clinica = 1 THEN "clinica" ELSE "laboratorio" END AS espaco_preferido
             FROM turma_disciplina td
             JOIN turmas t      ON t.id  = td.turma_id
             JOIN disciplinas d ON d.id  = td.disciplina_id
             WHERE td.semestre_ref = ?
               AND t.ativo = 1
               AND NOT EXISTS (
                   SELECT 1 FROM agendamentos a
                   JOIN semanas_semestre ss ON ss.id = a.semana_id
                   WHERE a.versao_id = ? AND ss.numero_semana = ?
                     AND a.turma_id = td.turma_id AND a.disciplina_id = td.disciplina_id
                     AND a.status != "cancelado"
               )
             ORDER BY t.nome, d.nome'
        );
        $pendStmt->execute([$versaoData['semestre_ref'], $versaoId, $semana]);
        $pendentes = $pendStmt->fetchAll(PDO::FETCH_ASSOC);

        // Dados para formulário de novo agendamento
        $professores = $this->pdo->query(
            'SELECT id, nome FROM professores WHERE ativo = 1 ORDER BY nome'
        )->fetchAll(PDO::FETCH_ASSOC);

        $preceptores = $this->pdo->query(
            'SELECT id, nome FROM preceptores WHERE ativo = 1 ORDER BY nome'
        )->fetchAll(PDO::FETCH_ASSOC);

        $clinicas = $this->pdo->query(
            'SELECT id, nome FROM clinicas WHERE ativo = 1 ORDER BY nome'
        )->fetchAll(PDO::FETCH_ASSOC);

        $laboratorios = $this->pdo->query(
            'SELECT id, nome FROM laboratorios WHERE ativo = 1 ORDER BY nome'
        )->fetchAll(PDO::FETCH_ASSOC);

        return [
            'versao'        => $versaoData,
            'semana'        => $semanaData,
            'semanas'       => $semanas,
            'por_dia'       => $porDia,
            'por_dia_turno' => $porDiaTurno,
            'pendentes'     => $pendentes,
            'professores'   => $professores,
            'preceptores'   => $preceptores,
            'clinicas'      => $clinicas,
            'laboratorios'  => $laboratorios,
            'total_agendados' => count($agendamentos),
            'total_pendentes' => count($pendentes),
        ];
    }

    /** Determina o turno a partir da hora de início (string HH:MM:SS ou HH:MM). */
    private function turnoDeHora(string $hora): string
    {
        $h = (int)substr($hora, 0, 2);
        if ($h < 13) {
            return 'manha';
        }
        if ($h < 19) {
            return 'tarde';
        }
        return 'noturno';
    }

    /**
     * Cria um agendamento manual na versão de agenda especificada.
     */
    public function criarAgendamentoManual(array $data, int $usuarioId): int
    {
        // Valida versao_id e semana_id
        $semanaStmt = $this->pdo->prepare('SELECT id, data_inicio FROM semanas_semestre WHERE id = ? LIMIT 1');
        $semanaStmt->execute([$data['semana_id']]);
        $semana = $semanaStmt->fetch(PDO::FETCH_ASSOC);
        if (!$semana) {
            throw new \InvalidArgumentException('Semana não encontrada.');
        }

        // Calcula data_aula a partir do dia da semana e data_inicio da semana
        $dataBase = new \DateTime($semana['data_inicio']);
        $dataBase->modify('+' . ((int)$data['dia_semana'] - 1) . ' days');
        $dataAula = $dataBase->format('Y-m-d');

        $stmt = $this->pdo->prepare(
            'INSERT INTO agendamentos
                (versao_id, semana_id, turma_id, disciplina_id, espaco_tipo, espaco_id,
                 professor_id, preceptor_id, dia_semana, data_aula, hora_inicio, hora_fim,
                 num_alunos, status, gerado_por_ia, observacoes, created_by, updated_by)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,0,?,?,?)'
        );
        $stmt->execute([
            $data['versao_id'],
            $data['semana_id'],
            $data['turma_id'],
            $data['disciplina_id'],
            $data['espaco_tipo'],
            $data['espaco_id'],
            $data['professor_id'] ?: null,
            $data['preceptor_id'] ?: null,
            (int)$data['dia_semana'],
            $dataAula,
            $data['hora_inicio'],
            $data['hora_fim'],
            (int)$data['num_alunos'],
            'agendado',
            $data['observacoes'] ?? null,
            $usuarioId,
            $usuarioId,
        ]);
        return (int)$this->pdo->lastInsertId();
    }

    /**
     * Cancela um agendamento pelo ID.
     */
    public function cancelarAgendamento(int $id, int $usuarioId, string $motivo = ''): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE agendamentos
             SET status = "cancelado",
                 observacoes = CASE WHEN ? != ""
                     THEN CONCAT(COALESCE(observacoes, ""), "\nCancelado: ", ?)
                     ELSE observacoes END,
                 updated_by = ?
             WHERE id = ?'
        );
        $stmt->execute([$motivo, $motivo, $usuarioId, $id]);
    }

    /**
     * Lista todas as versões de agenda disponíveis.
     */
    public function listarTodasVersoes(): array
    {
        $stmt = $this->pdo->query(
            'SELECT av.id, av.numero_versao, av.status, av.descricao,
                    s.referencia AS semestre_ref
             FROM agenda_versoes av
             JOIN semestres s ON s.id = av.semestre_id
             WHERE av.status != "simulacao"
             ORDER BY av.created_at DESC
             LIMIT 20'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // Carregamento de dados do banco
    // =========================================================================

    private function loadSemestre(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM semestres WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Carrega todos os pares turma×disciplina do semestre.
     * @return TurmaDisciplinaPair[]
     */
    private function loadPairs(int $semestreId, string $semestreRef): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                td.id              AS turma_disc_id,
                td.turma_id,
                t.nome             AS turma_nome,
                t.numero_alunos    AS num_alunos,
                td.disciplina_id,
                d.nome             AS disciplina_nome,
                d.tipo             AS disciplina_tipo,
                d.usa_clinica,
                d.usa_laboratorio,
                d.minimo_encontros,
                d.duracao_encontro_min,
                d.semana_inicio,
                d.semana_fim,
                d.prioridade,
                d.permite_alternancia,
                td.professor_id,
                td.preceptor_id,
                td.semestre_ref,
                COALESCE(td.turno, t.turno, "manha")                              AS turno,
                COALESCE(td.dia_semana_preferencial, t.dia_semana_preferencial)    AS dia_semana_preferencial
             FROM turma_disciplina td
             JOIN turmas t       ON t.id = td.turma_id      AND t.ativo = 1
             JOIN disciplinas d  ON d.id = td.disciplina_id AND d.ativo = 1
             WHERE td.semestre_ref = :ref
               AND (d.usa_clinica = 1 OR d.usa_laboratorio = 1)'
        );
        $stmt->execute(['ref' => $semestreRef]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $r) => new TurmaDisciplinaPair(
            turmaDiscId:           (int)$r['turma_disc_id'],
            turmaId:               (int)$r['turma_id'],
            turmaNome:             $r['turma_nome'],
            numAlunos:             (int)$r['num_alunos'],
            disciplinaId:          (int)$r['disciplina_id'],
            disciplinaNome:        $r['disciplina_nome'],
            disciplinaTipo:        $r['disciplina_tipo'],
            usaClinica:            (bool)$r['usa_clinica'],
            usaLaboratorio:        (bool)$r['usa_laboratorio'],
            minimoEncontros:       (int)$r['minimo_encontros'],
            duracaoEncontroMin:    (int)$r['duracao_encontro_min'],
            semanaInicio:          (int)$r['semana_inicio'],
            semanaFim:             (int)$r['semana_fim'],
            prioridade:            (int)$r['prioridade'],
            permiteAlternancia:    (bool)$r['permite_alternancia'],
            professorId:           $r['professor_id'] ? (int)$r['professor_id'] : null,
            preceptorId:           $r['preceptor_id'] ? (int)$r['preceptor_id'] : null,
            semestreRef:           $r['semestre_ref'],
            turno:                 $r['turno'] ?? 'manha',
            diaSemanaPreferencial: $r['dia_semana_preferencial'] ? (int)$r['dia_semana_preferencial'] : null,
        ), $rows);
    }

    private function loadSemanas(int $semestreId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, numero_semana, data_inicio, data_fim, tem_feriado
             FROM semanas_semestre WHERE semestre_id = ? ORDER BY numero_semana'
        );
        $stmt->execute([$semestreId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function loadClinicas(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, quantidade_cadeiras, hora_abertura, hora_fechamento,
                    hora_abertura_sabado, hora_fechamento_sabado
             FROM clinicas WHERE ativo = 1'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function loadLaboratorios(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, quantidade_assentos, hora_abertura, hora_fechamento,
                    hora_abertura_sabado, hora_fechamento_sabado
             FROM laboratorios WHERE ativo = 1'
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // Geração de slots
    // =========================================================================

    /**
     * Gera todos os SlotCandidates possíveis para o semestre.
     * Cria um slot por bloco de tempo (duração = duracaoEncontroMin da disciplina).
     * Deduplicados por chave para não explodir a memória.
     *
     * @param TurmaDisciplinaPair[] $pairs
     * @return SlotCandidate[]
     */
    private function gerarSlots(array $semanas, array $clinicas, array $labs, array $pairs): array
    {
        // Coletar durações únicas para gerar blocos correspondentes
        $duracoes = array_unique(array_map(fn($p) => $p->duracaoEncontroMin, $pairs));

        $slotsByKey = [];

        foreach ($semanas as $semana) {
            $current = new \DateTime($semana['data_inicio']);
            $fim     = new \DateTime($semana['data_fim']);

            while ($current <= $fim) {
                $diaSemana = (int)$current->format('N'); // 1=Mon...7=Sun
                $dataStr   = $current->format('Y-m-d');

                if ($diaSemana !== 7) { // Pula domingo
                    $isSabado = ($diaSemana === 6);

                    foreach ($duracoes as $duracao) {
                        foreach ($clinicas as $c) {
                            $abertura   = $isSabado ? $c['hora_abertura_sabado']   : $c['hora_abertura'];
                            $fechamento = $isSabado ? $c['hora_fechamento_sabado'] : $c['hora_fechamento'];
                            $this->gerarBlocos(
                                $slotsByKey, (int)$semana['id'], (int)$semana['numero_semana'],
                                $dataStr, $diaSemana, 'clinica', (int)$c['id'],
                                $abertura, $fechamento, $duracao
                            );
                        }
                        foreach ($labs as $l) {
                            $abertura   = $isSabado ? $l['hora_abertura_sabado']   : $l['hora_abertura'];
                            $fechamento = $isSabado ? $l['hora_fechamento_sabado'] : $l['hora_fechamento'];
                            $this->gerarBlocos(
                                $slotsByKey, (int)$semana['id'], (int)$semana['numero_semana'],
                                $dataStr, $diaSemana, 'laboratorio', (int)$l['id'],
                                $abertura, $fechamento, $duracao
                            );
                        }
                    }
                }
                $current->modify('+1 day');
            }
        }

        return array_values($slotsByKey);
    }

    private function gerarBlocos(
        array  &$slotsByKey,
        int    $semanaId,
        int    $numeroSemana,
        string $data,
        int    $diaSemana,
        string $tipo,
        int    $espacoId,
        string $abertura,
        string $fechamento,
        int    $duracaoMin,
    ): void {
        $aberturaMin   = $this->toMinutes($abertura);
        $fechamentoMin = $this->toMinutes($fechamento);

        $inicio = $aberturaMin;
        while ($inicio + $duracaoMin <= $fechamentoMin) {
            $fim = $inicio + $duracaoMin;
            $slot = new SlotCandidate(
                semanaId:     $semanaId,
                numeroSemana: $numeroSemana,
                dataAula:     $data,
                diaSemana:    $diaSemana,
                horaInicio:   $this->fromMinutes($inicio),
                horaFim:      $this->fromMinutes($fim),
                espacoTipo:   $tipo,
                espacoId:     $espacoId,
            );
            $slotsByKey[$slot->key()] = $slot;
            $inicio = $fim;
        }
    }

    private function toMinutes(string $time): int
    {
        [$h, $m] = array_map('intval', explode(':', $time));
        return $h * 60 + $m;
    }

    private function fromMinutes(int $minutes): string
    {
        return sprintf('%02d:%02d:00', intdiv($minutes, 60), $minutes % 60);
    }

    // =========================================================================
    // Construção do OptimizationContext
    // =========================================================================

    private function buildContext(int $semestreId, array $semanas, array $clinicas, array $labs): OptimizationContext
    {
        return new OptimizationContext(
            clinicas:             $this->indexClinicas($clinicas),
            laboratorios:         $this->indexLaboratorios($labs),
            semanasBloqueadas:    $this->loadSemanasBloqueadas($semestreId),
            espacosBloqueados:    $this->loadEspacosBloqueados($semanas),
            diasBloqueados:       $this->loadDiasBloqueados($semestreId),
            professorDisp:        $this->loadProfessorDisponibilidade(),
            preceptorDisp:        $this->loadPreceptorDisponibilidade(),
            professorDisciplinas: $this->loadProfessorDisciplinas(),
            preceptorDisciplinas: $this->loadPreceptorDisciplinas(),
            preceptorMaxTurmas:   $this->loadPreceptorMaxTurmas(),
        );
    }

    private function indexClinicas(array $clinicas): array
    {
        $idx = [];
        foreach ($clinicas as $c) {
            $idx[(int)$c['id']] = ['capacidade' => (int)$c['quantidade_cadeiras']];
        }
        return $idx;
    }

    private function indexLaboratorios(array $labs): array
    {
        $idx = [];
        foreach ($labs as $l) {
            $idx[(int)$l['id']] = ['capacidade' => (int)$l['quantidade_assentos']];
        }
        return $idx;
    }

    private function loadSemanasBloqueadas(int $semestreId): array
    {
        // Considera semanas com feriado como bloqueadas somente se explicitamente marcadas
        // (Usamos dias_bloqueados, não a flag tem_feriado, pois feriado pode ser apenas 1 dia)
        return [];
    }

    private function loadDiasBloqueados(int $semestreId): array
    {
        $stmt = $this->pdo->prepare('SELECT data FROM dias_bloqueados WHERE semestre_id = ?');
        $stmt->execute([$semestreId]);
        return array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    private function loadEspacosBloqueados(array $semanas): array
    {
        if (empty($semanas)) {
            return [];
        }
        $dataInicio = $semanas[0]['data_inicio'];
        $dataFim    = end($semanas)['data_fim'];

        $stmt = $this->pdo->prepare(
            'SELECT espaco_tipo, espaco_id, data_inicio, data_fim
             FROM bloqueios_espaco
             WHERE data_fim >= ? AND data_inicio <= ?'
        );
        $stmt->execute([$dataInicio, $dataFim]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $index = [];
        foreach ($rows as $b) {
            $cur = new \DateTime($b['data_inicio']);
            $fim = new \DateTime($b['data_fim']);
            while ($cur <= $fim) {
                $key = "{$b['espaco_tipo']}:{$b['espaco_id']}:{$cur->format('Y-m-d')}";
                $index[$key] = true;
                $cur->modify('+1 day');
            }
        }
        return $index;
    }

    private function loadProfessorDisponibilidade(): array
    {
        $stmt = $this->pdo->query(
            'SELECT professor_id, dia_semana, hora_inicio, hora_fim, semana_inicio, semana_fim
             FROM professor_disponibilidade'
        );
        $rows  = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $index = [];
        foreach ($rows as $r) {
            $index[(int)$r['professor_id']][] = [
                'dia_semana'   => (int)$r['dia_semana'],
                'hora_inicio'  => $r['hora_inicio'],
                'hora_fim'     => $r['hora_fim'],
                'semana_inicio'=> (int)$r['semana_inicio'],
                'semana_fim'   => (int)$r['semana_fim'],
            ];
        }
        return $index;
    }

    private function loadPreceptorDisponibilidade(): array
    {
        $stmt = $this->pdo->query(
            'SELECT preceptor_id, dia_semana, hora_inicio, hora_fim, semana_inicio, semana_fim
             FROM preceptor_disponibilidade'
        );
        $rows  = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $index = [];
        foreach ($rows as $r) {
            $index[(int)$r['preceptor_id']][] = [
                'dia_semana'   => (int)$r['dia_semana'],
                'hora_inicio'  => $r['hora_inicio'],
                'hora_fim'     => $r['hora_fim'],
                'semana_inicio'=> (int)$r['semana_inicio'],
                'semana_fim'   => (int)$r['semana_fim'],
            ];
        }
        return $index;
    }

    private function loadProfessorDisciplinas(): array
    {
        $stmt = $this->pdo->query('SELECT professor_id, disciplina_id FROM professor_disciplina');
        $index = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $index[(int)$r['professor_id']][(int)$r['disciplina_id']] = true;
        }
        return $index;
    }

    private function loadPreceptorDisciplinas(): array
    {
        $stmt = $this->pdo->query('SELECT preceptor_id, disciplina_id FROM preceptor_disciplina');
        $index = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $index[(int)$r['preceptor_id']][(int)$r['disciplina_id']] = true;
        }
        return $index;
    }

    private function loadPreceptorMaxTurmas(): array
    {
        $stmt = $this->pdo->query('SELECT id, max_turmas_simultaneas FROM preceptores WHERE ativo = 1');
        $index = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $index[(int)$r['id']] = (int)$r['max_turmas_simultaneas'];
        }
        return $index;
    }

    // =========================================================================
    // Criação de versão
    // =========================================================================

    private function criarVersao(int $semestreId, int $usuarioId, string $descricao, bool $simulacao): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(MAX(numero_versao), 0) + 1 FROM agenda_versoes WHERE semestre_id = ?'
        );
        $stmt->execute([$semestreId]);
        $proximaVersao = (int)$stmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            'INSERT INTO agenda_versoes (semestre_id, numero_versao, status, descricao, created_by)
             VALUES (:sem, :num, :status, :desc, :criado)'
        );
        $stmt->execute([
            'sem'    => $semestreId,
            'num'    => $proximaVersao,
            'status' => $simulacao ? 'simulacao' : 'rascunho',
            'desc'   => $descricao ?: "Versão {$proximaVersao}",
            'criado' => $usuarioId,
        ]);
        return (int)$this->pdo->lastInsertId();
    }
}

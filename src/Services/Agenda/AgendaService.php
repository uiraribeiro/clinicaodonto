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
                td.semestre_ref
             FROM turma_disciplina td
             JOIN turmas t       ON t.id = td.turma_id      AND t.ativo = 1
             JOIN disciplinas d  ON d.id = td.disciplina_id AND d.ativo = 1
             WHERE td.semestre_ref = :ref
               AND (d.usa_clinica = 1 OR d.usa_laboratorio = 1)'
        );
        $stmt->execute(['ref' => $semestreRef]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(fn(array $r) => new TurmaDisciplinaPair(
            turmaDiscId:       (int)$r['turma_disc_id'],
            turmaId:           (int)$r['turma_id'],
            turmaNome:         $r['turma_nome'],
            numAlunos:         (int)$r['num_alunos'],
            disciplinaId:      (int)$r['disciplina_id'],
            disciplinaNome:    $r['disciplina_nome'],
            disciplinaTipo:    $r['disciplina_tipo'],
            usaClinica:        (bool)$r['usa_clinica'],
            usaLaboratorio:    (bool)$r['usa_laboratorio'],
            minimoEncontros:   (int)$r['minimo_encontros'],
            duracaoEncontroMin:(int)$r['duracao_encontro_min'],
            semanaInicio:      (int)$r['semana_inicio'],
            semanaFim:         (int)$r['semana_fim'],
            prioridade:        (int)$r['prioridade'],
            permiteAlternancia:(bool)$r['permite_alternancia'],
            professorId:       $r['professor_id'] ? (int)$r['professor_id'] : null,
            preceptorId:       $r['preceptor_id'] ? (int)$r['preceptor_id'] : null,
            semestreRef:       $r['semestre_ref'],
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

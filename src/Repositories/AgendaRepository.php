<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Leituras de agenda, indicadores e conflitos.
 * Todas as queries usam a versão publicada (ou a mais recente se não houver publicada).
 */
class AgendaRepository
{
    public function __construct(private readonly PDO $pdo) {}

    // =========================================================================
    // Indicadores do dashboard
    // =========================================================================

    public function getIndicadoresDashboard(): array
    {
        $versaoId = $this->getVersaoPublicadaId();
        if (!$versaoId) {
            return $this->indicadoresVazios();
        }

        // Score de alocação direto da versão (calculado pelo optimizer)
        $versaoRow = $this->pdo->prepare(
            'SELECT score_ocupacao, total_conflitos FROM agenda_versoes WHERE id = ?'
        );
        $versaoRow->execute([$versaoId]);
        $versao = $versaoRow->fetch() ?: ['score_ocupacao' => 0, 'total_conflitos' => 0];

        // Capacidade dos espaços físicos
        $capClinica = (int)$this->pdo->query(
            'SELECT SUM(quantidade_cadeiras * capacidade_por_cadeira) FROM clinicas WHERE ativo = 1'
        )->fetchColumn();
        $capLab = (int)$this->pdo->query(
            'SELECT SUM(quantidade_assentos) FROM laboratorios WHERE ativo = 1'
        )->fetchColumn();

        // Ocupação real (alunos alocados / capacidade × dias com aula)
        $ocClinica = $this->pdo->prepare(
            'SELECT SUM(num_alunos) AS alunos, COUNT(DISTINCT data_aula) AS dias
             FROM agendamentos
             WHERE versao_id = ? AND espaco_tipo = "clinica" AND status != "cancelado"'
        );
        $ocClinica->execute([$versaoId]);
        $oc = $ocClinica->fetch();
        $pctClinica = ($capClinica > 0 && (int)$oc['dias'] > 0)
            ? round($oc['alunos'] / ($capClinica * $oc['dias']) * 100, 1)
            : 0.0;

        $ocLab = $this->pdo->prepare(
            'SELECT SUM(num_alunos) AS alunos, COUNT(DISTINCT data_aula) AS dias
             FROM agendamentos
             WHERE versao_id = ? AND espaco_tipo = "laboratorio" AND status != "cancelado"'
        );
        $ocLab->execute([$versaoId]);
        $ol = $ocLab->fetch();
        $pctLab = ($capLab > 0 && (int)$ol['dias'] > 0)
            ? round($ol['alunos'] / ($capLab * $ol['dias']) * 100, 1)
            : 0.0;

        // Conflitos em aberto
        $confStmt = $this->pdo->prepare('SELECT COUNT(*) FROM conflitos WHERE versao_id = ? AND resolvido = 0');
        $confStmt->execute([$versaoId]);
        $totalConflitos = (int)$confStmt->fetchColumn();

        // Turmas com pelo menos uma disciplina sem nenhum agendamento
        $semAlocStmt = $this->pdo->prepare(
            'SELECT COUNT(DISTINCT td.turma_id)
             FROM turma_disciplina td
             WHERE td.semestre_ref = (SELECT referencia FROM semestres WHERE status = "ativo" LIMIT 1)
               AND NOT EXISTS (
                   SELECT 1 FROM agendamentos a
                   WHERE a.versao_id = ? AND a.turma_id = td.turma_id AND a.disciplina_id = td.disciplina_id
               )'
        );
        $semAlocStmt->execute([$versaoId]);
        $turmasSemAlocacao = (int)$semAlocStmt->fetchColumn();

        return [
            'ocupacao_clinica_pct'  => $pctClinica,
            'ocupacao_lab_pct'      => $pctLab,
            'total_conflitos'       => $totalConflitos,
            'turmas_sem_alocacao'   => $turmasSemAlocacao,
            'taxa_alocacao'         => (float)($versao['score_ocupacao'] ?? 0),
            'versao_id'             => $versaoId,
        ];
    }

    // =========================================================================
    // Agenda semanal
    // =========================================================================

    public function getAgendaSemana(int $semana, array $filtros = []): array
    {
        $versaoId = $this->getVersaoPublicadaId() ?? $this->getUltimaVersaoId();
        if (!$versaoId) {
            return [];
        }

        $where  = ['a.versao_id = :vid', 'ss.numero_semana = :semana'];
        $params = [':vid' => $versaoId, ':semana' => $semana];

        if (!empty($filtros['espaco'])) {
            $where[]            = 'a.espaco_tipo = :espaco';
            $params[':espaco']  = $filtros['espaco'];
        }
        if (!empty($filtros['turma_id'])) {
            $where[]              = 'a.turma_id = :turma_id';
            $params[':turma_id']  = (int)$filtros['turma_id'];
        }
        if (!empty($filtros['professor_id'])) {
            $where[]                 = 'a.professor_id = :professor_id';
            $params[':professor_id'] = (int)$filtros['professor_id'];
        }
        if (!empty($filtros['preceptor_id'])) {
            $where[]                 = 'a.preceptor_id = :preceptor_id';
            $params[':preceptor_id'] = (int)$filtros['preceptor_id'];
        }

        $stmt = $this->pdo->prepare(
            'SELECT a.*, t.nome AS turma_nome, d.nome AS disciplina_nome, d.codigo AS disciplina_codigo,
                    p.nome AS professor_nome, pr.nome AS preceptor_nome,
                    ss.numero_semana, ss.data_inicio AS semana_data_inicio
             FROM agendamentos a
             JOIN semanas_semestre ss ON ss.id = a.semana_id
             JOIN turmas t            ON t.id  = a.turma_id
             JOIN disciplinas d       ON d.id  = a.disciplina_id
             LEFT JOIN professores p  ON p.id  = a.professor_id
             LEFT JOIN preceptores pr ON pr.id = a.preceptor_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY a.dia_semana, a.hora_inicio, a.espaco_tipo'
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    // =========================================================================
    // Agenda diária
    // =========================================================================

    public function getAgendaDia(string $data): array
    {
        $versaoId = $this->getVersaoPublicadaId() ?? $this->getUltimaVersaoId();
        if (!$versaoId) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT a.*, t.nome AS turma_nome, d.nome AS disciplina_nome,
                    p.nome AS professor_nome, pr.nome AS preceptor_nome
             FROM agendamentos a
             JOIN turmas t            ON t.id  = a.turma_id
             JOIN disciplinas d       ON d.id  = a.disciplina_id
             LEFT JOIN professores p  ON p.id  = a.professor_id
             LEFT JOIN preceptores pr ON pr.id = a.preceptor_id
             WHERE a.versao_id = :vid AND a.data_aula = :data AND a.status != "cancelado"
             ORDER BY a.hora_inicio, a.espaco_tipo'
        );
        $stmt->execute([':vid' => $versaoId, ':data' => $data]);
        return $stmt->fetchAll();
    }

    public function getOcupacaoClinica(string $data): array
    {
        $versaoId = $this->getVersaoPublicadaId() ?? $this->getUltimaVersaoId();
        if (!$versaoId) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT hora_inicio, hora_fim, SUM(num_alunos) AS total_alunos
             FROM agendamentos
             WHERE versao_id = :vid AND data_aula = :data
               AND espaco_tipo = "clinica" AND status != "cancelado"
             GROUP BY hora_inicio, hora_fim
             ORDER BY hora_inicio'
        );
        $stmt->execute([':vid' => $versaoId, ':data' => $data]);
        return $stmt->fetchAll();
    }

    public function getOcupacaoLaboratorio(string $data): array
    {
        $versaoId = $this->getVersaoPublicadaId() ?? $this->getUltimaVersaoId();
        if (!$versaoId) {
            return [];
        }

        $stmt = $this->pdo->prepare(
            'SELECT hora_inicio, hora_fim, SUM(num_alunos) AS total_alunos
             FROM agendamentos
             WHERE versao_id = :vid AND data_aula = :data
               AND espaco_tipo = "laboratorio" AND status != "cancelado"
             GROUP BY hora_inicio, hora_fim
             ORDER BY hora_inicio'
        );
        $stmt->execute([':vid' => $versaoId, ':data' => $data]);
        return $stmt->fetchAll();
    }

    // =========================================================================
    // Calendário mensal
    // =========================================================================

    public function getCalendarioMensal(int $mes, int $ano): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.data_aula,
                    COUNT(DISTINCT a.id)                                                    AS total_agendamentos,
                    SUM(a.num_alunos)                                                       AS total_alunos,
                    SUM(CASE WHEN a.espaco_tipo = "clinica"     THEN a.num_alunos ELSE 0 END) AS alunos_clinica,
                    SUM(CASE WHEN a.espaco_tipo = "laboratorio" THEN a.num_alunos ELSE 0 END) AS alunos_lab
             FROM agendamentos a
             WHERE MONTH(a.data_aula) = :mes AND YEAR(a.data_aula) = :ano
               AND a.status != "cancelado"
             GROUP BY a.data_aula
             ORDER BY a.data_aula'
        );
        $stmt->execute([':mes' => $mes, ':ano' => $ano]);
        // Keyed by date string for O(1) lookup in Twig
        return $stmt->fetchAll(PDO::FETCH_UNIQUE);
    }

    // =========================================================================
    // Conflitos e gargalos
    // =========================================================================

    public function getConflitosAbertos(int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT c.*, av.numero_versao
             FROM conflitos c
             JOIN agenda_versoes av ON av.id = c.versao_id
             WHERE c.resolvido = 0
             ORDER BY
                 FIELD(c.severidade, "critico", "alto", "medio", "baixo"),
                 c.created_at DESC
             LIMIT :lim'
        );
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function getGargalosDetectados(): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ss.numero_semana, ss.data_inicio,
                    SUM(CASE WHEN a.espaco_tipo = "clinica" THEN a.num_alunos ELSE 0 END) AS alunos_clinica,
                    (SELECT c.quantidade_cadeiras * c.capacidade_por_cadeira FROM clinicas c WHERE c.ativo = 1 LIMIT 1) AS cap_clinica,
                    COUNT(CASE WHEN a.espaco_tipo = "clinica"     THEN 1 END) AS slots_clinica,
                    COUNT(CASE WHEN a.espaco_tipo = "laboratorio" THEN 1 END) AS slots_lab
             FROM agendamentos a
             JOIN semanas_semestre ss ON ss.id = a.semana_id
             WHERE a.status != "cancelado"
               AND a.versao_id = (SELECT id FROM agenda_versoes WHERE status = "publicada" ORDER BY numero_versao DESC LIMIT 1)
             GROUP BY ss.numero_semana, ss.data_inicio
             HAVING alunos_clinica > cap_clinica
             ORDER BY ss.numero_semana'
        );
        $stmt->execute();
        return $stmt->fetchAll();
    }

    // =========================================================================
    // Semana corrente do semestre ativo
    // =========================================================================

    public function getSemanaCorrente(): int
    {
        $stmt = $this->pdo->query(
            'SELECT ss.numero_semana
             FROM semanas_semestre ss
             JOIN semestres s ON s.id = ss.semestre_id
             WHERE s.status = "ativo"
               AND ss.data_inicio <= CURDATE()
               AND ss.data_fim    >= CURDATE()
             LIMIT 1'
        );
        $row = $stmt->fetch();
        if ($row) {
            return (int)$row['numero_semana'];
        }
        // Se antes do semestre começar, retorna semana 1; se depois, a última
        $last = $this->pdo->query(
            'SELECT COALESCE(MAX(ss.numero_semana), 1)
             FROM semanas_semestre ss
             JOIN semestres s ON s.id = ss.semestre_id
             WHERE s.status = "ativo" AND ss.data_inicio <= CURDATE()'
        )->fetchColumn();
        return max(1, (int)$last);
    }

    public function getTotalSemanas(): int
    {
        $row = $this->pdo->query(
            'SELECT num_semanas FROM semestres WHERE status = "ativo" LIMIT 1'
        )->fetch();
        return $row ? (int)$row['num_semanas'] : 20;
    }

    // =========================================================================
    // Estatísticas pré-computadas para evitar lógica complexa no Twig
    // =========================================================================

    /** Retorna dados de resumo da semana prontos para exibir no dashboard semanal. */
    public function getResumoSemana(array $agenda): array
    {
        $totalSlots     = count($agenda);
        $totalAlunos    = 0;
        $slotsClinica   = 0;
        $slotsLab       = 0;
        $turmasUnicas   = [];

        foreach ($agenda as $ag) {
            $totalAlunos += (int)$ag['num_alunos'];
            if ($ag['espaco_tipo'] === 'clinica') {
                $slotsClinica++;
            } else {
                $slotsLab++;
            }
            $turmasUnicas[$ag['turma_nome']] = true;
        }

        return [
            'total_slots'    => $totalSlots,
            'total_alunos'   => $totalAlunos,
            'slots_clinica'  => $slotsClinica,
            'slots_lab'      => $slotsLab,
            'turmas_count'   => count($turmasUnicas),
        ];
    }

    // =========================================================================
    // Indicadores JSON para refresh AJAX
    // =========================================================================

    public function getIndicadoresJson(): array
    {
        return $this->getIndicadoresDashboard();
    }

    // =========================================================================
    // Helpers privados
    // =========================================================================

    private function getVersaoPublicadaId(): ?int
    {
        $row = $this->pdo->query(
            'SELECT id FROM agenda_versoes WHERE status = "publicada" ORDER BY numero_versao DESC LIMIT 1'
        )->fetch();
        return $row ? (int)$row['id'] : null;
    }

    private function getUltimaVersaoId(): ?int
    {
        $row = $this->pdo->query(
            'SELECT id FROM agenda_versoes ORDER BY created_at DESC LIMIT 1'
        )->fetch();
        return $row ? (int)$row['id'] : null;
    }

    private function indicadoresVazios(): array
    {
        return [
            'ocupacao_clinica_pct' => 0.0,
            'ocupacao_lab_pct'     => 0.0,
            'total_conflitos'      => 0,
            'turmas_sem_alocacao'  => 0,
            'taxa_alocacao'        => 0.0,
            'versao_id'            => null,
        ];
    }
}

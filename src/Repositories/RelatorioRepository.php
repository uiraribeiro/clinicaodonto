<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Queries analíticas para os relatórios do sistema.
 * Sempre opera sobre a versão publicada (ou a mais recente se não houver publicada).
 */
class RelatorioRepository
{
    public function __construct(private readonly PDO $pdo) {}

    // =========================================================================
    // Helpers de versão e semestre
    // =========================================================================

    public function getVersaoAtiva(): ?array
    {
        $pub = $this->pdo->query(
            'SELECT av.*, s.referencia AS semestre_ref, s.num_semanas
             FROM agenda_versoes av
             JOIN semestres s ON s.id = av.semestre_id
             WHERE av.status = "publicada"
             ORDER BY av.numero_versao DESC LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC);
        if ($pub) return $pub;

        return $this->pdo->query(
            'SELECT av.*, s.referencia AS semestre_ref, s.num_semanas
             FROM agenda_versoes av
             JOIN semestres s ON s.id = av.semestre_id
             ORDER BY av.created_at DESC LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    public function getSemestreAtivo(): ?array
    {
        return $this->pdo->query(
            'SELECT * FROM semestres WHERE status = "ativo" LIMIT 1'
        )->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // =========================================================================
    // Relatório por semana
    // =========================================================================

    public function getAgendaSemana(int $semana, int $versaoId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.*,
                    t.nome  AS turma_nome, t.numero_alunos,
                    d.nome  AS disciplina_nome, d.codigo, d.tipo AS disciplina_tipo,
                    p.nome  AS professor_nome,
                    pr.nome AS preceptor_nome,
                    TIMESTAMPDIFF(MINUTE, a.hora_inicio, a.hora_fim) AS duracao_min
             FROM agendamentos a
             JOIN semanas_semestre ss ON ss.id = a.semana_id
             JOIN turmas t            ON t.id  = a.turma_id
             JOIN disciplinas d       ON d.id  = a.disciplina_id
             LEFT JOIN professores p  ON p.id  = a.professor_id
             LEFT JOIN preceptores pr ON pr.id = a.preceptor_id
             WHERE a.versao_id = :vid AND ss.numero_semana = :semana
               AND a.status != "cancelado"
             ORDER BY a.dia_semana, a.hora_inicio, a.espaco_tipo'
        );
        $stmt->execute([':vid' => $versaoId, ':semana' => $semana]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getOcupacaoSemanal(int $versaoId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ss.numero_semana, ss.data_inicio, ss.data_fim,
                    SUM(CASE WHEN a.espaco_tipo="clinica"     THEN 1 ELSE 0 END) AS slots_clinica,
                    SUM(CASE WHEN a.espaco_tipo="laboratorio" THEN 1 ELSE 0 END) AS slots_lab,
                    SUM(a.num_alunos) AS total_alunos,
                    SUM(TIMESTAMPDIFF(MINUTE,a.hora_inicio,a.hora_fim)) AS total_min
             FROM semanas_semestre ss
             LEFT JOIN agendamentos a ON a.semana_id = ss.id
                 AND a.versao_id = :vid AND a.status != "cancelado"
             WHERE ss.semestre_id = (SELECT semestre_id FROM agenda_versoes WHERE id = :vid)
             GROUP BY ss.numero_semana, ss.data_inicio, ss.data_fim
             ORDER BY ss.numero_semana'
        );
        $stmt->execute([':vid' => $versaoId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // Relatório por turma
    // =========================================================================

    public function getResumoTurmas(string $semestreRef, int $versaoId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT
                t.nome  AS turma_nome, t.numero_alunos, t.periodo,
                cu.nome AS curso_nome, cu.sigla AS curso_sigla,
                d.nome  AS disciplina_nome, d.tipo AS disciplina_tipo,
                d.minimo_encontros,
                td.professor_id, td.preceptor_id,
                p.nome  AS professor_nome,
                pr.nome AS preceptor_nome,
                COUNT(a.id)                                          AS encontros_alocados,
                COALESCE(SUM(TIMESTAMPDIFF(MINUTE,a.hora_inicio,a.hora_fim))/60,0) AS horas_alocadas,
                SUM(CASE WHEN a.espaco_tipo="clinica"     THEN 1 ELSE 0 END) AS enc_clinica,
                SUM(CASE WHEN a.espaco_tipo="laboratorio" THEN 1 ELSE 0 END) AS enc_lab
             FROM turma_disciplina td
             JOIN turmas t           ON t.id  = td.turma_id
             JOIN cursos cu          ON cu.id = t.curso_id
             JOIN disciplinas d      ON d.id  = td.disciplina_id
             LEFT JOIN professores p  ON p.id = td.professor_id
             LEFT JOIN preceptores pr ON pr.id= td.preceptor_id
             LEFT JOIN agendamentos a ON a.turma_id = td.turma_id
                 AND a.disciplina_id = td.disciplina_id
                 AND a.versao_id = :vid AND a.status != "cancelado"
             WHERE td.semestre_ref = :ref
             GROUP BY td.id, t.nome, t.numero_alunos, t.periodo,
                      cu.nome, cu.sigla, d.nome, d.tipo, d.minimo_encontros,
                      td.professor_id, td.preceptor_id, p.nome, pr.nome
             ORDER BY t.nome, d.nome'
        );
        $stmt->execute([':vid' => $versaoId, ':ref' => $semestreRef]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // Relatório por disciplina
    // =========================================================================

    public function getResumoDisciplinas(string $semestreRef, int $versaoId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT d.codigo, d.nome, d.tipo, d.minimo_encontros, d.duracao_encontro_min,
                    COUNT(DISTINCT td.turma_id) AS total_turmas,
                    COUNT(a.id)                 AS total_slots,
                    COALESCE(SUM(TIMESTAMPDIFF(MINUTE,a.hora_inicio,a.hora_fim))/60,0) AS total_horas,
                    SUM(CASE WHEN a.espaco_tipo="clinica"     THEN 1 ELSE 0 END) AS slots_clinica,
                    SUM(CASE WHEN a.espaco_tipo="laboratorio" THEN 1 ELSE 0 END) AS slots_lab,
                    COALESCE(SUM(a.num_alunos),0) AS total_alunos_x_slot
             FROM disciplinas d
             LEFT JOIN turma_disciplina td ON td.disciplina_id = d.id AND td.semestre_ref = :ref
             LEFT JOIN agendamentos a       ON a.disciplina_id = d.id
                 AND a.versao_id = :vid AND a.status != "cancelado"
             WHERE d.ativo = 1 AND (d.usa_clinica = 1 OR d.usa_laboratorio = 1)
             GROUP BY d.id, d.codigo, d.nome, d.tipo, d.minimo_encontros, d.duracao_encontro_min
             ORDER BY d.tipo, d.nome'
        );
        $stmt->execute([':vid' => $versaoId, ':ref' => $semestreRef]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // =========================================================================
    // Relatório por professor / preceptor
    // =========================================================================

    public function getResumoProfessores(int $versaoId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.id, p.nome, p.email, "professor" AS tipo,
                    COUNT(DISTINCT a.turma_id)     AS turmas,
                    COUNT(DISTINCT a.disciplina_id)AS disciplinas,
                    COUNT(a.id)                    AS total_slots,
                    COALESCE(SUM(TIMESTAMPDIFF(MINUTE,a.hora_inicio,a.hora_fim))/60,0) AS total_horas,
                    COUNT(DISTINCT a.data_aula)    AS dias_com_aula
             FROM professores p
             LEFT JOIN agendamentos a ON a.professor_id = p.id
                 AND a.versao_id = :vid AND a.status != "cancelado"
             WHERE p.ativo = 1
             GROUP BY p.id, p.nome, p.email
             ORDER BY total_horas DESC, p.nome'
        );
        $stmt->execute([':vid' => $versaoId]);
        $profs = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stmt2 = $this->pdo->prepare(
            'SELECT pr.id, pr.nome, pr.email, "preceptor" AS tipo,
                    pr.max_turmas_simultaneas,
                    COUNT(DISTINCT a.turma_id)      AS turmas,
                    COUNT(DISTINCT a.disciplina_id) AS disciplinas,
                    COUNT(a.id)                     AS total_slots,
                    COALESCE(SUM(TIMESTAMPDIFF(MINUTE,a.hora_inicio,a.hora_fim))/60,0) AS total_horas,
                    COUNT(DISTINCT a.data_aula)     AS dias_com_aula
             FROM preceptores pr
             LEFT JOIN agendamentos a ON a.preceptor_id = pr.id
                 AND a.versao_id = :vid AND a.status != "cancelado"
             WHERE pr.ativo = 1
             GROUP BY pr.id, pr.nome, pr.email, pr.max_turmas_simultaneas
             ORDER BY total_horas DESC, pr.nome'
        );
        $stmt2->execute([':vid' => $versaoId]);
        $precs = $stmt2->fetchAll(PDO::FETCH_ASSOC);

        return ['professores' => $profs, 'preceptores' => $precs];
    }

    // =========================================================================
    // Relatório por espaço
    // =========================================================================

    public function getResumoEspacos(int $versaoId): array
    {
        // Ocupação da clínica por semana
        $stmtC = $this->pdo->prepare(
            'SELECT ss.numero_semana, ss.data_inicio,
                    c.id AS espaco_id, c.nome AS espaco_nome,
                    c.quantidade_cadeiras * c.capacidade_por_cadeira AS capacidade,
                    COUNT(DISTINCT a.data_aula) AS dias_usados,
                    COUNT(a.id) AS total_slots,
                    COALESCE(SUM(a.num_alunos),0) AS total_alunos,
                    COALESCE(SUM(TIMESTAMPDIFF(MINUTE,a.hora_inicio,a.hora_fim))/60,0) AS total_horas
             FROM clinicas c
             CROSS JOIN semanas_semestre ss
             LEFT JOIN agendamentos a ON a.espaco_tipo = "clinica" AND a.espaco_id = c.id
                 AND a.semana_id = ss.id AND a.versao_id = :vid AND a.status != "cancelado"
             WHERE ss.semestre_id = (SELECT semestre_id FROM agenda_versoes WHERE id = :vid)
               AND c.ativo = 1
             GROUP BY ss.numero_semana, ss.data_inicio, c.id, c.nome, capacidade
             ORDER BY c.id, ss.numero_semana'
        );
        $stmtC->execute([':vid' => $versaoId]);

        $stmtL = $this->pdo->prepare(
            'SELECT ss.numero_semana, ss.data_inicio,
                    l.id AS espaco_id, l.nome AS espaco_nome,
                    l.quantidade_assentos AS capacidade,
                    COUNT(DISTINCT a.data_aula) AS dias_usados,
                    COUNT(a.id) AS total_slots,
                    COALESCE(SUM(a.num_alunos),0) AS total_alunos,
                    COALESCE(SUM(TIMESTAMPDIFF(MINUTE,a.hora_inicio,a.hora_fim))/60,0) AS total_horas
             FROM laboratorios l
             CROSS JOIN semanas_semestre ss
             LEFT JOIN agendamentos a ON a.espaco_tipo = "laboratorio" AND a.espaco_id = l.id
                 AND a.semana_id = ss.id AND a.versao_id = :vid AND a.status != "cancelado"
             WHERE ss.semestre_id = (SELECT semestre_id FROM agenda_versoes WHERE id = :vid)
               AND l.ativo = 1
             GROUP BY ss.numero_semana, ss.data_inicio, l.id, l.nome, capacidade
             ORDER BY l.id, ss.numero_semana'
        );
        $stmtL->execute([':vid' => $versaoId]);

        return [
            'clinicas'     => $stmtC->fetchAll(PDO::FETCH_ASSOC),
            'laboratorios' => $stmtL->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    // =========================================================================
    // Exportação completa (todos os agendamentos)
    // =========================================================================

    public function getTodosAgendamentos(int $versaoId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT a.data_aula, a.dia_semana, a.hora_inicio, a.hora_fim,
                    a.espaco_tipo, a.espaco_id, a.num_alunos, a.status, a.gerado_por_ia,
                    ss.numero_semana,
                    t.nome  AS turma, t.periodo,
                    cu.sigla AS curso,
                    d.codigo AS disc_codigo, d.nome AS disciplina, d.tipo AS disc_tipo,
                    p.nome  AS professor,
                    pr.nome AS preceptor,
                    TIMESTAMPDIFF(MINUTE,a.hora_inicio,a.hora_fim) AS duracao_min
             FROM agendamentos a
             JOIN semanas_semestre ss ON ss.id = a.semana_id
             JOIN turmas t            ON t.id  = a.turma_id
             JOIN cursos cu           ON cu.id = t.curso_id
             JOIN disciplinas d       ON d.id  = a.disciplina_id
             LEFT JOIN professores p  ON p.id  = a.professor_id
             LEFT JOIN preceptores pr ON pr.id = a.preceptor_id
             WHERE a.versao_id = :vid AND a.status != "cancelado"
             ORDER BY a.data_aula, a.hora_inicio, a.espaco_tipo, a.espaco_id'
        );
        $stmt->execute([':vid' => $versaoId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

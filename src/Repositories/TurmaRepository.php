<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

class TurmaRepository
{
    public function __construct(private readonly PDO $pdo) {}

    /**
     * Lista todas as turmas ativas.
     * Se semestreRef for fornecida, retorna apenas turmas que possuem
     * vínculo em turma_disciplina para aquele semestre.
     */
    public function findAll(string $semestreRef = ''): array
    {
        if ($semestreRef !== '') {
            $stmt = $this->pdo->prepare('
                SELECT DISTINCT t.*, c.nome AS curso_nome, c.sigla AS curso_sigla
                FROM turmas t
                JOIN cursos c ON c.id = t.curso_id
                JOIN turma_disciplina td ON td.turma_id = t.id
                    AND td.semestre_ref = :semestre_ref
                WHERE t.ativo = 1
                ORDER BY c.sigla, t.periodo, t.nome
            ');
            $stmt->execute([':semestre_ref' => $semestreRef]);
        } else {
            $stmt = $this->pdo->query('
                SELECT t.*, c.nome AS curso_nome, c.sigla AS curso_sigla
                FROM turmas t
                JOIN cursos c ON c.id = t.curso_id
                WHERE t.ativo = 1
                ORDER BY c.sigla, t.periodo, t.nome
            ');
        }

        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('
            SELECT t.*, c.nome AS curso_nome, c.sigla AS curso_sigla
            FROM turmas t
            JOIN cursos c ON c.id = t.curso_id
            WHERE t.id = :id
            LIMIT 1
        ');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Retorna a turma com a lista de disciplinas vinculadas no semestre informado.
     * Cada item da lista inclui dados da disciplina, professor e preceptor.
     */
    public function findComDisciplinas(int $id, string $semestreRef): array
    {
        $turma = $this->findById($id);
        if (!$turma) {
            return [];
        }

        $stmt = $this->pdo->prepare('
            SELECT
                td.id                      AS vinculo_id,
                td.professor_id,
                td.preceptor_id,
                td.turno,
                td.dia_semana_preferencial,
                d.id                       AS disciplina_id,
                d.codigo,
                d.nome                     AS disciplina_nome,
                d.tipo,
                d.usa_clinica,
                d.usa_laboratorio,
                p.nome                     AS professor_nome,
                pc.nome                    AS preceptor_nome
            FROM turma_disciplina td
            JOIN disciplinas d  ON d.id  = td.disciplina_id
            LEFT JOIN professores p  ON p.id  = td.professor_id
            LEFT JOIN preceptores pc ON pc.id = td.preceptor_id
            WHERE td.turma_id    = :turma_id
              AND td.semestre_ref = :semestre_ref
            ORDER BY d.nome
        ');
        $stmt->execute([
            ':turma_id'    => $id,
            ':semestre_ref' => $semestreRef,
        ]);

        $turma['disciplinas'] = $stmt->fetchAll();
        return $turma;
    }

    public function findAllCursos(): array
    {
        $stmt = $this->pdo->query('SELECT * FROM cursos ORDER BY nome');
        return $stmt->fetchAll();
    }

    public function create(array $data, int $usuarioId): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO turmas
                (curso_id, disciplina_id, professor_id, preceptor_id,
                 nome, periodo, numero_alunos, turno, dia_semana_preferencial, restricoes,
                 ativo, created_by, updated_by)
            VALUES
                (:curso_id, :disciplina_id, :professor_id, :preceptor_id,
                 :nome, :periodo, :numero_alunos, :turno, :dia_semana_preferencial, :restricoes,
                 1, :created_by, :updated_by)
        ');

        $stmt->execute([
            ':curso_id'                => (int) $data['curso_id'],
            ':disciplina_id'           => !empty($data['disciplina_id']) ? (int)$data['disciplina_id'] : null,
            ':professor_id'            => !empty($data['professor_id'])  ? (int)$data['professor_id']  : null,
            ':preceptor_id'            => !empty($data['preceptor_id'])  ? (int)$data['preceptor_id']  : null,
            ':nome'                    => trim($data['nome']),
            ':periodo'                 => (int) $data['periodo'],
            ':numero_alunos'           => (int) $data['numero_alunos'],
            ':turno'                   => $data['turno'] ?? 'manha',
            ':dia_semana_preferencial' => !empty($data['dia_semana_preferencial']) ? (int)$data['dia_semana_preferencial'] : null,
            ':restricoes'              => !empty($data['restricoes']) ? json_encode($data['restricoes']) : null,
            ':created_by'              => $usuarioId,
            ':updated_by'              => $usuarioId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $data, int $usuarioId): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE turmas SET
                curso_id                = :curso_id,
                disciplina_id           = :disciplina_id,
                professor_id            = :professor_id,
                preceptor_id            = :preceptor_id,
                nome                    = :nome,
                periodo                 = :periodo,
                numero_alunos           = :numero_alunos,
                turno                   = :turno,
                dia_semana_preferencial = :dia_semana_preferencial,
                restricoes              = :restricoes,
                updated_by              = :updated_by
            WHERE id = :id
        ');

        $stmt->execute([
            ':id'                      => $id,
            ':curso_id'                => (int) $data['curso_id'],
            ':disciplina_id'           => !empty($data['disciplina_id']) ? (int)$data['disciplina_id'] : null,
            ':professor_id'            => !empty($data['professor_id'])  ? (int)$data['professor_id']  : null,
            ':preceptor_id'            => !empty($data['preceptor_id'])  ? (int)$data['preceptor_id']  : null,
            ':nome'                    => trim($data['nome']),
            ':periodo'                 => (int) $data['periodo'],
            ':numero_alunos'           => (int) $data['numero_alunos'],
            ':turno'                   => $data['turno'] ?? 'manha',
            ':dia_semana_preferencial' => !empty($data['dia_semana_preferencial']) ? (int)$data['dia_semana_preferencial'] : null,
            ':restricoes'              => !empty($data['restricoes']) ? json_encode($data['restricoes']) : null,
            ':updated_by'              => $usuarioId,
        ]);
    }

    /**
     * Upsert do único registro turma_disciplina para este semestre.
     * Garante que o otimizador encontre o par turma×disciplina para o semestre correto.
     */
    public function syncDisciplinaSemestre(
        int $turmaId,
        int $disciplinaId,
        ?int $professorId,
        ?int $preceptorId,
        string $semestreRef,
        int $usuarioId
    ): void {
        $this->pdo->prepare('
            INSERT INTO turma_disciplina
                (turma_id, disciplina_id, professor_id, preceptor_id, semestre_ref)
            VALUES
                (:turma_id, :disciplina_id, :professor_id, :preceptor_id, :semestre_ref)
            ON DUPLICATE KEY UPDATE
                disciplina_id = VALUES(disciplina_id),
                professor_id  = VALUES(professor_id),
                preceptor_id  = VALUES(preceptor_id)
        ')->execute([
            ':turma_id'      => $turmaId,
            ':disciplina_id' => $disciplinaId,
            ':professor_id'  => $professorId,
            ':preceptor_id'  => $preceptorId,
            ':semestre_ref'  => $semestreRef,
        ]);
    }

    public function softDelete(int $id, int $usuarioId): void
    {
        $this->pdo->prepare(
            'UPDATE turmas SET ativo = 0, updated_by = :uid WHERE id = :id'
        )->execute([':uid' => $usuarioId, ':id' => $id]);
    }

    public function toggleAtivo(int $id, int $usuarioId): void
    {
        $this->pdo->prepare(
            'UPDATE turmas SET ativo = NOT ativo, updated_by = :uid WHERE id = :id'
        )->execute([':uid' => $usuarioId, ':id' => $id]);
    }

    public function hasAgendamentos(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM agendamentos WHERE turma_id = :id');
        $stmt->execute([':id' => $id]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function hardDelete(int $id): void
    {
        // turma_disciplina cascades; agendamentos verified before calling
        $this->pdo->prepare('DELETE FROM turmas WHERE id = :id')->execute([':id' => $id]);
    }

    /**
     * Apaga todos os vínculos turma_disciplina da turma no semestre informado
     * e recria a partir do array fornecido.
     *
     * Cada item de $disciplinas deve conter:
     *   disciplina_id (int), professor_id (int|null), preceptor_id (int|null)
     */
    public function saveDisciplinas(
        int $turmaId,
        array $disciplinas,
        string $semestreRef,
        int $usuarioId
    ): void {
        // Remove vínculos existentes para este semestre
        $stmtDel = $this->pdo->prepare('
            DELETE FROM turma_disciplina
            WHERE turma_id = :turma_id AND semestre_ref = :semestre_ref
        ');
        $stmtDel->execute([
            ':turma_id'    => $turmaId,
            ':semestre_ref' => $semestreRef,
        ]);

        if (empty($disciplinas)) {
            return;
        }

        $stmtIns = $this->pdo->prepare('
            INSERT INTO turma_disciplina
                (turma_id, disciplina_id, professor_id, preceptor_id, semestre_ref, turno, dia_semana_preferencial)
            VALUES
                (:turma_id, :disciplina_id, :professor_id, :preceptor_id, :semestre_ref, :turno, :dia)
        ');

        foreach ($disciplinas as $disc) {
            $disciplinaId = (int) ($disc['disciplina_id'] ?? 0);
            if ($disciplinaId <= 0) {
                continue;
            }

            $turnoValido = in_array($disc['turno'] ?? '', ['manha', 'tarde', 'noturno'], true)
                ? $disc['turno']
                : null;

            $stmtIns->execute([
                ':turma_id'      => $turmaId,
                ':disciplina_id' => $disciplinaId,
                ':professor_id'  => !empty($disc['professor_id']) ? (int) $disc['professor_id'] : null,
                ':preceptor_id'  => !empty($disc['preceptor_id']) ? (int) $disc['preceptor_id'] : null,
                ':semestre_ref'  => $semestreRef,
                ':turno'         => $turnoValido,
                ':dia'           => !empty($disc['dia_semana_preferencial']) ? (int) $disc['dia_semana_preferencial'] : null,
            ]);
        }
    }

    /**
     * Retorna turmas que possuem disciplinas vinculadas a um determinado tipo
     * de espaço (clinica ou laboratorio) no semestre informado.
     */
    public function findPorEspaco(string $espaco, string $semestreRef): array
    {
        $coluna = $espaco === 'clinica' ? 'usa_clinica' : 'usa_laboratorio';

        $stmt = $this->pdo->prepare("
            SELECT DISTINCT t.*, c.nome AS curso_nome, c.sigla AS curso_sigla
            FROM turmas t
            JOIN cursos c ON c.id = t.curso_id
            JOIN turma_disciplina td ON td.turma_id = t.id
                AND td.semestre_ref = :semestre_ref
            JOIN disciplinas d ON d.id = td.disciplina_id
                AND d.{$coluna} = 1
            WHERE t.ativo = 1
            ORDER BY c.sigla, t.periodo, t.nome
        ");
        $stmt->execute([':semestre_ref' => $semestreRef]);
        return $stmt->fetchAll();
    }
}

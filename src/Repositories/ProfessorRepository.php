<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

class ProfessorRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function findAll(bool $apenasAtivos = false): array
    {
        $where = $apenasAtivos ? 'WHERE p.ativo = 1' : '';

        $stmt = $this->pdo->query("
            SELECT
                p.*,
                GROUP_CONCAT(DISTINCT d.nome ORDER BY d.nome SEPARATOR ', ') AS disciplinas_nomes,
                COUNT(DISTINCT pd2.id) AS total_disponibilidades
            FROM professores p
            LEFT JOIN professor_disciplina pd ON pd.professor_id = p.id
                AND (pd.data_fim IS NULL OR pd.data_fim >= CURDATE())
            LEFT JOIN disciplinas d ON d.id = pd.disciplina_id AND d.ativo = 1
            LEFT JOIN professor_disponibilidade pd2 ON pd2.professor_id = p.id
            {$where}
            GROUP BY p.id
            ORDER BY p.nome
        ");

        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM professores WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findComDisponibilidade(int $id): array
    {
        $stmtProf = $this->pdo->prepare('SELECT * FROM professores WHERE id = :id LIMIT 1');
        $stmtProf->execute([':id' => $id]);
        $professor = $stmtProf->fetch();

        if (!$professor) {
            return [];
        }

        $stmtDisp = $this->pdo->prepare(
            'SELECT * FROM professor_disponibilidade
             WHERE professor_id = :professor_id
             ORDER BY dia_semana, hora_inicio, semana_inicio'
        );
        $stmtDisp->execute([':professor_id' => $id]);
        $disponibilidades = $stmtDisp->fetchAll();

        $professor['disponibilidades'] = $disponibilidades;
        return $professor;
    }

    public function findComDisciplinas(int $id): array
    {
        $stmtProf = $this->pdo->prepare('SELECT * FROM professores WHERE id = :id LIMIT 1');
        $stmtProf->execute([':id' => $id]);
        $professor = $stmtProf->fetch();

        if (!$professor) {
            return [];
        }

        $stmtDisc = $this->pdo->prepare('
            SELECT
                pd.id AS vinculo_id,
                pd.data_inicio,
                pd.data_fim,
                pd.observacoes,
                pd.created_at,
                d.id AS disciplina_id,
                d.codigo,
                d.nome,
                d.tipo
            FROM professor_disciplina pd
            JOIN disciplinas d ON d.id = pd.disciplina_id
            WHERE pd.professor_id = :professor_id
            ORDER BY d.nome
        ');
        $stmtDisc->execute([':professor_id' => $id]);
        $disciplinas = $stmtDisc->fetchAll();

        $professor['disciplinas'] = $disciplinas;
        return $professor;
    }

    public function create(array $data, int $usuarioId): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO professores
                (usuario_id, nome, email, telefone, matricula, ativo, created_by, updated_by)
            VALUES
                (:usuario_id, :nome, :email, :telefone, :matricula, 1, :created_by, :updated_by)
        ');

        $stmt->execute([
            ':usuario_id'  => !empty($data['usuario_id']) ? (int) $data['usuario_id'] : null,
            ':nome'        => trim($data['nome']),
            ':email'       => trim($data['email']),
            ':telefone'    => !empty($data['telefone']) ? trim($data['telefone']) : null,
            ':matricula'   => !empty($data['matricula']) ? trim($data['matricula']) : null,
            ':created_by'  => $usuarioId,
            ':updated_by'  => $usuarioId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $data, int $usuarioId): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE professores SET
                usuario_id  = :usuario_id,
                nome        = :nome,
                email       = :email,
                telefone    = :telefone,
                matricula   = :matricula,
                updated_by  = :updated_by
            WHERE id = :id
        ');

        $stmt->execute([
            ':id'          => $id,
            ':usuario_id'  => !empty($data['usuario_id']) ? (int) $data['usuario_id'] : null,
            ':nome'        => trim($data['nome']),
            ':email'       => trim($data['email']),
            ':telefone'    => !empty($data['telefone']) ? trim($data['telefone']) : null,
            ':matricula'   => !empty($data['matricula']) ? trim($data['matricula']) : null,
            ':updated_by'  => $usuarioId,
        ]);
    }

    public function softDelete(int $id, int $usuarioId): void
    {
        $this->pdo->prepare(
            'UPDATE professores SET ativo = 0, updated_by = :uid WHERE id = :id'
        )->execute([':uid' => $usuarioId, ':id' => $id]);
    }

    public function toggleAtivo(int $id, int $usuarioId): void
    {
        $this->pdo->prepare(
            'UPDATE professores SET ativo = NOT ativo, updated_by = :uid WHERE id = :id'
        )->execute([':uid' => $usuarioId, ':id' => $id]);
    }

    public function hasAgendamentos(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM agendamentos WHERE professor_id = :id');
        $stmt->execute([':id' => $id]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function hardDelete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM professor_disponibilidade WHERE professor_id = :id')->execute([':id' => $id]);
        $this->pdo->prepare('DELETE FROM professor_disciplina WHERE professor_id = :id')->execute([':id' => $id]);
        $this->pdo->prepare('DELETE FROM professores WHERE id = :id')->execute([':id' => $id]);
    }

    /**
     * Apaga todas as disponibilidades do professor e recria a partir do array fornecido.
     * Cada item do array deve conter: dia_semana, hora_inicio, hora_fim, semana_inicio, semana_fim.
     */
    public function saveDisponibilidade(int $professorId, array $disponibilidades): void
    {
        $stmtDelete = $this->pdo->prepare(
            'DELETE FROM professor_disponibilidade WHERE professor_id = :professor_id'
        );
        $stmtDelete->execute([':professor_id' => $professorId]);

        if (empty($disponibilidades)) {
            return;
        }

        $stmtInsert = $this->pdo->prepare('
            INSERT INTO professor_disponibilidade
                (professor_id, dia_semana, hora_inicio, hora_fim, semana_inicio, semana_fim)
            VALUES
                (:professor_id, :dia_semana, :hora_inicio, :hora_fim, :semana_inicio, :semana_fim)
        ');

        foreach ($disponibilidades as $disp) {
            $diaSemana    = (int) ($disp['dia_semana'] ?? 0);
            $semanaInicio = (int) ($disp['semana_inicio'] ?? 1);
            $semanaFim    = (int) ($disp['semana_fim'] ?? 20);

            if ($diaSemana < 1 || $diaSemana > 6) {
                continue;
            }
            if (empty($disp['hora_inicio']) || empty($disp['hora_fim'])) {
                continue;
            }

            $stmtInsert->execute([
                ':professor_id' => $professorId,
                ':dia_semana'   => $diaSemana,
                ':hora_inicio'  => $disp['hora_inicio'],
                ':hora_fim'     => $disp['hora_fim'],
                ':semana_inicio'=> $semanaInicio,
                ':semana_fim'   => $semanaFim,
            ]);
        }
    }

    /**
     * Verifica se o professor possui disponibilidade cadastrada que cobre o slot solicitado.
     */
    public function findDisponivelParaSlot(
        int $professorId,
        int $diaSemana,
        string $horaInicio,
        string $horaFim,
        int $semana
    ): bool {
        $stmt = $this->pdo->prepare('
            SELECT COUNT(*) FROM professor_disponibilidade
            WHERE professor_id  = :professor_id
              AND dia_semana     = :dia_semana
              AND hora_inicio   <= :hora_inicio
              AND hora_fim      >= :hora_fim
              AND semana_inicio <= :semana
              AND semana_fim    >= :semana
        ');
        $stmt->execute([
            ':professor_id' => $professorId,
            ':dia_semana'   => $diaSemana,
            ':hora_inicio'  => $horaInicio,
            ':hora_fim'     => $horaFim,
            ':semana'       => $semana,
        ]);
        return (int) $stmt->fetchColumn() > 0;
    }
}

<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

class PreceptorRepository
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
            FROM preceptores p
            LEFT JOIN preceptor_disciplina pd ON pd.preceptor_id = p.id
            LEFT JOIN disciplinas d ON d.id = pd.disciplina_id AND d.ativo = 1
            LEFT JOIN preceptor_disponibilidade pd2 ON pd2.preceptor_id = p.id
            {$where}
            GROUP BY p.id
            ORDER BY p.nome
        ");

        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM preceptores WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findComDisponibilidade(int $id): array
    {
        $stmtPrec = $this->pdo->prepare('SELECT * FROM preceptores WHERE id = :id LIMIT 1');
        $stmtPrec->execute([':id' => $id]);
        $preceptor = $stmtPrec->fetch();

        if (!$preceptor) {
            return [];
        }

        $stmtDisp = $this->pdo->prepare(
            'SELECT * FROM preceptor_disponibilidade
             WHERE preceptor_id = :preceptor_id
             ORDER BY dia_semana, hora_inicio, semana_inicio'
        );
        $stmtDisp->execute([':preceptor_id' => $id]);
        $disponibilidades = $stmtDisp->fetchAll();

        $preceptor['disponibilidades'] = $disponibilidades;
        return $preceptor;
    }

    public function findComDisciplinas(int $id): array
    {
        $stmtPrec = $this->pdo->prepare('SELECT * FROM preceptores WHERE id = :id LIMIT 1');
        $stmtPrec->execute([':id' => $id]);
        $preceptor = $stmtPrec->fetch();

        if (!$preceptor) {
            return [];
        }

        $stmtDisc = $this->pdo->prepare('
            SELECT
                pd.id AS vinculo_id,
                d.id  AS disciplina_id,
                d.codigo,
                d.nome,
                d.tipo
            FROM preceptor_disciplina pd
            JOIN disciplinas d ON d.id = pd.disciplina_id
            WHERE pd.preceptor_id = :preceptor_id
            ORDER BY d.nome
        ');
        $stmtDisc->execute([':preceptor_id' => $id]);
        $disciplinas = $stmtDisc->fetchAll();

        $preceptor['disciplinas'] = $disciplinas;
        return $preceptor;
    }

    public function create(array $data, int $usuarioId): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO preceptores
                (usuario_id, nome, email, telefone, matricula, max_turmas_simultaneas,
                 ativo, created_by, updated_by)
            VALUES
                (:usuario_id, :nome, :email, :telefone, :matricula, :max_turmas_simultaneas,
                 1, :created_by, :updated_by)
        ');

        $stmt->execute([
            ':usuario_id'            => !empty($data['usuario_id']) ? (int) $data['usuario_id'] : null,
            ':nome'                  => trim($data['nome']),
            ':email'                 => trim($data['email']),
            ':telefone'              => !empty($data['telefone']) ? trim($data['telefone']) : null,
            ':matricula'             => !empty($data['matricula']) ? trim($data['matricula']) : null,
            ':max_turmas_simultaneas'=> (int) ($data['max_turmas_simultaneas'] ?? 1),
            ':created_by'            => $usuarioId,
            ':updated_by'            => $usuarioId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $data, int $usuarioId): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE preceptores SET
                usuario_id             = :usuario_id,
                nome                   = :nome,
                email                  = :email,
                telefone               = :telefone,
                matricula              = :matricula,
                max_turmas_simultaneas = :max_turmas_simultaneas,
                updated_by             = :updated_by
            WHERE id = :id
        ');

        $stmt->execute([
            ':id'                    => $id,
            ':usuario_id'            => !empty($data['usuario_id']) ? (int) $data['usuario_id'] : null,
            ':nome'                  => trim($data['nome']),
            ':email'                 => trim($data['email']),
            ':telefone'              => !empty($data['telefone']) ? trim($data['telefone']) : null,
            ':matricula'             => !empty($data['matricula']) ? trim($data['matricula']) : null,
            ':max_turmas_simultaneas'=> (int) ($data['max_turmas_simultaneas'] ?? 1),
            ':updated_by'            => $usuarioId,
        ]);
    }

    public function softDelete(int $id, int $usuarioId): void
    {
        $this->pdo->prepare(
            'UPDATE preceptores SET ativo = 0, updated_by = :uid WHERE id = :id'
        )->execute([':uid' => $usuarioId, ':id' => $id]);
    }

    public function toggleAtivo(int $id, int $usuarioId): void
    {
        $this->pdo->prepare(
            'UPDATE preceptores SET ativo = NOT ativo, updated_by = :uid WHERE id = :id'
        )->execute([':uid' => $usuarioId, ':id' => $id]);
    }

    public function hasAgendamentos(int $id): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM agendamentos WHERE preceptor_id = :id');
        $stmt->execute([':id' => $id]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function hardDelete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM preceptor_disponibilidade WHERE preceptor_id = :id')->execute([':id' => $id]);
        $this->pdo->prepare('DELETE FROM preceptor_disciplina WHERE preceptor_id = :id')->execute([':id' => $id]);
        $this->pdo->prepare('DELETE FROM preceptores WHERE id = :id')->execute([':id' => $id]);
    }

    /**
     * Apaga todas as disponibilidades do preceptor e recria a partir do array fornecido.
     * Cada item deve conter: dia_semana, hora_inicio, hora_fim, semana_inicio, semana_fim.
     */
    public function saveDisponibilidade(int $preceptorId, array $disponibilidades): void
    {
        $stmtDelete = $this->pdo->prepare(
            'DELETE FROM preceptor_disponibilidade WHERE preceptor_id = :preceptor_id'
        );
        $stmtDelete->execute([':preceptor_id' => $preceptorId]);

        if (empty($disponibilidades)) {
            return;
        }

        $stmtInsert = $this->pdo->prepare('
            INSERT INTO preceptor_disponibilidade
                (preceptor_id, dia_semana, hora_inicio, hora_fim, semana_inicio, semana_fim)
            VALUES
                (:preceptor_id, :dia_semana, :hora_inicio, :hora_fim, :semana_inicio, :semana_fim)
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
                ':preceptor_id' => $preceptorId,
                ':dia_semana'   => $diaSemana,
                ':hora_inicio'  => $disp['hora_inicio'],
                ':hora_fim'     => $disp['hora_fim'],
                ':semana_inicio'=> $semanaInicio,
                ':semana_fim'   => $semanaFim,
            ]);
        }
    }

    /**
     * Verifica se o preceptor possui disponibilidade cadastrada que cobre o slot solicitado.
     */
    public function findDisponivelParaSlot(
        int $preceptorId,
        int $diaSemana,
        string $horaInicio,
        string $horaFim,
        int $semana
    ): bool {
        $stmt = $this->pdo->prepare('
            SELECT COUNT(*) FROM preceptor_disponibilidade
            WHERE preceptor_id  = :preceptor_id
              AND dia_semana     = :dia_semana
              AND hora_inicio   <= :hora_inicio
              AND hora_fim      >= :hora_fim
              AND semana_inicio <= :semana
              AND semana_fim    >= :semana
        ');
        $stmt->execute([
            ':preceptor_id' => $preceptorId,
            ':dia_semana'   => $diaSemana,
            ':hora_inicio'  => $horaInicio,
            ':hora_fim'     => $horaFim,
            ':semana'       => $semana,
        ]);
        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Retorna quantas turmas o preceptor tem agendadas na semana informada.
     * Usado para verificar o limite de max_turmas_simultaneas.
     */
    public function getCarregaSemana(int $preceptorId, int $semana): int
    {
        $stmt = $this->pdo->prepare('
            SELECT COUNT(DISTINCT a.turma_id)
            FROM agendamentos a
            JOIN semanas_semestre ss ON ss.id = a.semana_id
            WHERE a.preceptor_id  = :preceptor_id
              AND ss.numero_semana = :semana
              AND a.status        != "cancelado"
        ');
        $stmt->execute([
            ':preceptor_id' => $preceptorId,
            ':semana'       => $semana,
        ]);
        return (int) $stmt->fetchColumn();
    }
}

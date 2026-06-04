<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

class DisciplinaRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function findAll(bool $apenasAtivas = false): array
    {
        $where = $apenasAtivas ? 'WHERE d.ativo = 1' : '';
        $stmt  = $this->pdo->query("
            SELECT d.*,
                COUNT(DISTINCT t.id) AS num_turmas
            FROM disciplinas d
            LEFT JOIN turmas t ON t.disciplina_id = d.id
            {$where}
            GROUP BY d.id
            ORDER BY d.prioridade, d.nome
        ");
        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM disciplinas WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByTipo(string $tipo): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM disciplinas WHERE tipo = :tipo AND ativo = 1 ORDER BY prioridade, nome'
        );
        $stmt->execute([':tipo' => $tipo]);
        return $stmt->fetchAll();
    }

    public function create(array $data, int $usuarioId): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO disciplinas
                (codigo, nome, tipo, carga_horaria_pratica, usa_clinica, usa_laboratorio,
                 minimo_encontros, duracao_encontro_min, semana_inicio, semana_fim,
                 prioridade, permite_alternancia, observacoes, ativo, created_by, updated_by)
            VALUES
                (:codigo, :nome, :tipo, :carga_horaria_pratica, :usa_clinica, :usa_laboratorio,
                 :minimo_encontros, :duracao_encontro_min, :semana_inicio, :semana_fim,
                 :prioridade, :permite_alternancia, :observacoes, 1, :created_by, :updated_by)
        ');

        $stmt->execute([
            ':codigo'               => strtoupper(trim($data['codigo'])),
            ':nome'                 => trim($data['nome']),
            ':tipo'                 => $data['tipo'],
            ':carga_horaria_pratica'=> (int) $data['carga_horaria_pratica'],
            ':usa_clinica'          => isset($data['usa_clinica']) ? 1 : 0,
            ':usa_laboratorio'      => isset($data['usa_laboratorio']) ? 1 : 0,
            ':minimo_encontros'     => (int) ($data['minimo_encontros'] ?? 1),
            ':duracao_encontro_min' => (int) ($data['duracao_encontro_min'] ?? 180),
            ':semana_inicio'        => (int) ($data['semana_inicio'] ?? 1),
            ':semana_fim'           => (int) ($data['semana_fim'] ?? 20),
            ':prioridade'           => (int) ($data['prioridade'] ?? 5),
            ':permite_alternancia'  => isset($data['permite_alternancia']) ? 1 : 0,
            ':observacoes'          => $data['observacoes'] ?? null,
            ':created_by'           => $usuarioId,
            ':updated_by'           => $usuarioId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $data, int $usuarioId): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE disciplinas SET
                codigo               = :codigo,
                nome                 = :nome,
                tipo                 = :tipo,
                carga_horaria_pratica= :carga_horaria_pratica,
                usa_clinica          = :usa_clinica,
                usa_laboratorio      = :usa_laboratorio,
                minimo_encontros     = :minimo_encontros,
                duracao_encontro_min = :duracao_encontro_min,
                semana_inicio        = :semana_inicio,
                semana_fim           = :semana_fim,
                prioridade           = :prioridade,
                permite_alternancia  = :permite_alternancia,
                observacoes          = :observacoes,
                updated_by           = :updated_by
            WHERE id = :id
        ');

        $stmt->execute([
            ':id'                   => $id,
            ':codigo'               => strtoupper(trim($data['codigo'])),
            ':nome'                 => trim($data['nome']),
            ':tipo'                 => $data['tipo'],
            ':carga_horaria_pratica'=> (int) $data['carga_horaria_pratica'],
            ':usa_clinica'          => isset($data['usa_clinica']) ? 1 : 0,
            ':usa_laboratorio'      => isset($data['usa_laboratorio']) ? 1 : 0,
            ':minimo_encontros'     => (int) ($data['minimo_encontros'] ?? 1),
            ':duracao_encontro_min' => (int) ($data['duracao_encontro_min'] ?? 180),
            ':semana_inicio'        => (int) ($data['semana_inicio'] ?? 1),
            ':semana_fim'           => (int) ($data['semana_fim'] ?? 20),
            ':prioridade'           => (int) ($data['prioridade'] ?? 5),
            ':permite_alternancia'  => isset($data['permite_alternancia']) ? 1 : 0,
            ':observacoes'          => $data['observacoes'] ?? null,
            ':updated_by'           => $usuarioId,
        ]);
    }

    public function softDelete(int $id, int $usuarioId): void
    {
        $this->pdo->prepare('UPDATE disciplinas SET ativo = 0, updated_by = :uid WHERE id = :id')
                  ->execute([':uid' => $usuarioId, ':id' => $id]);
    }

    public function countTurmas(int $id): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM turmas WHERE disciplina_id = :id');
        $stmt->execute([':id' => $id]);
        return (int) $stmt->fetchColumn();
    }

    public function hasTurmas(int $id): bool
    {
        return $this->countTurmas($id) > 0;
    }

    public function hasAgendamentos(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM agendamentos WHERE disciplina_id = :id AND status != 'cancelado'"
        );
        $stmt->execute([':id' => $id]);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function hardDelete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM turma_disciplina WHERE disciplina_id = :id')->execute([':id' => $id]);
        $this->pdo->prepare('DELETE FROM disciplinas WHERE id = :id')->execute([':id' => $id]);
    }

    /**
     * Retorna disciplinas com seus professores vinculados — usado pelo otimizador.
     */
    public function findComProfessores(): array
    {
        $stmt = $this->pdo->query('
            SELECT
                d.*,
                GROUP_CONCAT(p.id ORDER BY p.nome) AS professor_ids,
                GROUP_CONCAT(p.nome ORDER BY p.nome SEPARATOR "||") AS professor_nomes
            FROM disciplinas d
            LEFT JOIN professor_disciplina pd ON pd.disciplina_id = d.id
                AND (pd.data_fim IS NULL OR pd.data_fim >= CURDATE())
            LEFT JOIN professores p ON p.id = pd.professor_id AND p.ativo = 1
            WHERE d.ativo = 1
            GROUP BY d.id
            ORDER BY d.prioridade, d.nome
        ');
        return $stmt->fetchAll();
    }
}

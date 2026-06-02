<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

class SemestreRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function findAll(): array
    {
        $stmt = $this->pdo->query('
            SELECT * FROM semestres
            ORDER BY referencia DESC
        ');
        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM semestres WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findAtivo(): ?array
    {
        $stmt = $this->pdo->prepare(
            "SELECT * FROM semestres WHERE status = 'ativo' LIMIT 1"
        );
        $stmt->execute();
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByReferencia(string $ref): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM semestres WHERE referencia = :ref LIMIT 1'
        );
        $stmt->execute([':ref' => $ref]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Cria o semestre e automaticamente gera as semanas_semestre.
     * As semanas são calculadas adicionando incrementos de 7 dias a partir de data_inicio.
     */
    public function create(array $data): int
    {
        $numSemanas = (int) ($data['num_semanas'] ?? 20);

        $stmt = $this->pdo->prepare('
            INSERT INTO semestres
                (referencia, data_inicio, data_fim, num_semanas, status)
            VALUES
                (:referencia, :data_inicio, :data_fim, :num_semanas, :status)
        ');

        $stmt->execute([
            ':referencia'  => trim($data['referencia']),
            ':data_inicio' => $data['data_inicio'],
            ':data_fim'    => $data['data_fim'],
            ':num_semanas' => $numSemanas,
            ':status'      => $data['status'] ?? 'planejamento',
        ]);

        $semestreId = (int) $this->pdo->lastInsertId();

        $this->gerarSemanas($semestreId, $data['data_inicio'], $numSemanas);

        return $semestreId;
    }

    public function update(int $id, array $data): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE semestres SET
                referencia  = :referencia,
                data_inicio = :data_inicio,
                data_fim    = :data_fim,
                num_semanas = :num_semanas,
                status      = :status
            WHERE id = :id
        ');

        $stmt->execute([
            ':id'          => $id,
            ':referencia'  => trim($data['referencia']),
            ':data_inicio' => $data['data_inicio'],
            ':data_fim'    => $data['data_fim'],
            ':num_semanas' => (int) ($data['num_semanas'] ?? 20),
            ':status'      => $data['status'],
        ]);
    }

    /**
     * Ativa o semestre informado e coloca todos os demais como 'planejamento'.
     * Semestres com status 'encerrado' não são alterados.
     */
    public function ativar(int $id): void
    {
        $this->pdo->prepare(
            "UPDATE semestres
             SET status = 'planejamento'
             WHERE status = 'ativo' AND id != :id"
        )->execute([':id' => $id]);

        $this->pdo->prepare(
            "UPDATE semestres SET status = 'ativo' WHERE id = :id"
        )->execute([':id' => $id]);
    }

    public function addDiaBloqueado(int $semestreId, array $data): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO dias_bloqueados
                (semestre_id, data, motivo, tipo)
            VALUES
                (:semestre_id, :data, :motivo, :tipo)
        ');

        $stmt->execute([
            ':semestre_id' => $semestreId,
            ':data'        => $data['data'],
            ':motivo'      => trim($data['motivo']),
            ':tipo'        => $data['tipo'],
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function removeDiaBloqueado(int $id): void
    {
        $this->pdo->prepare(
            'DELETE FROM dias_bloqueados WHERE id = :id'
        )->execute([':id' => $id]);
    }

    public function getDiasBloqueados(int $semestreId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM dias_bloqueados
            WHERE semestre_id = :semestre_id
            ORDER BY data
        ');
        $stmt->execute([':semestre_id' => $semestreId]);
        return $stmt->fetchAll();
    }

    public function getSemanas(int $semestreId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT * FROM semanas_semestre
            WHERE semestre_id = :semestre_id
            ORDER BY numero_semana
        ');
        $stmt->execute([':semestre_id' => $semestreId]);
        return $stmt->fetchAll();
    }

    // -------------------------------------------------------------------------
    // Métodos privados
    // -------------------------------------------------------------------------

    /**
     * Gera automaticamente as semanas_semestre para o semestre recém-criado.
     * Cada semana começa 7 dias após o início da anterior.
     * A primeira semana começa em data_inicio.
     */
    private function gerarSemanas(int $semestreId, string $dataInicio, int $numSemanas): void
    {
        $stmtIns = $this->pdo->prepare('
            INSERT INTO semanas_semestre
                (semestre_id, numero_semana, data_inicio, data_fim, tem_feriado)
            VALUES
                (:semestre_id, :numero_semana, :data_inicio, :data_fim, 0)
        ');

        $inicio = new \DateTimeImmutable($dataInicio);

        for ($i = 1; $i <= $numSemanas; $i++) {
            $semanaInicio = $inicio->modify(sprintf('+%d days', ($i - 1) * 7));
            $semanaFim    = $semanaInicio->modify('+6 days');

            $stmtIns->execute([
                ':semestre_id'  => $semestreId,
                ':numero_semana'=> $i,
                ':data_inicio'  => $semanaInicio->format('Y-m-d'),
                ':data_fim'     => $semanaFim->format('Y-m-d'),
            ]);
        }
    }
}

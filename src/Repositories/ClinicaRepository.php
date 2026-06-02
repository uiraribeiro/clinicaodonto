<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

class ClinicaRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function findAll(): array
    {
        $stmt = $this->pdo->query('
            SELECT * FROM clinicas
            WHERE ativo = 1
            ORDER BY nome
        ');
        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM clinicas WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function update(int $id, array $data, int $usuarioId): void
    {
        $stmt = $this->pdo->prepare('
            UPDATE clinicas SET
                nome                   = :nome,
                quantidade_cadeiras    = :quantidade_cadeiras,
                capacidade_por_cadeira = :capacidade_por_cadeira,
                hora_abertura          = :hora_abertura,
                hora_fechamento        = :hora_fechamento,
                hora_abertura_sabado   = :hora_abertura_sabado,
                hora_fechamento_sabado = :hora_fechamento_sabado,
                observacoes            = :observacoes,
                updated_by             = :updated_by
            WHERE id = :id
        ');

        $stmt->execute([
            ':id'                     => $id,
            ':nome'                   => trim($data['nome']),
            ':quantidade_cadeiras'    => (int) $data['quantidade_cadeiras'],
            ':capacidade_por_cadeira' => (int) $data['capacidade_por_cadeira'],
            ':hora_abertura'          => $data['hora_abertura'],
            ':hora_fechamento'        => $data['hora_fechamento'],
            ':hora_abertura_sabado'   => $data['hora_abertura_sabado'],
            ':hora_fechamento_sabado' => $data['hora_fechamento_sabado'],
            ':observacoes'            => $data['observacoes'] ?? null,
            ':updated_by'             => $usuarioId,
        ]);
    }

    /**
     * Retorna bloqueios futuros (data_fim >= hoje) para a clínica informada.
     */
    public function getBloqueios(int $clinicaId): array
    {
        $stmt = $this->pdo->prepare('
            SELECT be.*, u.nome AS criado_por_nome
            FROM bloqueios_espaco be
            LEFT JOIN usuarios u ON u.id = be.created_by
            WHERE be.espaco_tipo = \'clinica\'
              AND be.espaco_id   = :clinica_id
              AND be.data_fim   >= CURDATE()
            ORDER BY be.data_inicio, be.hora_inicio
        ');
        $stmt->execute([':clinica_id' => $clinicaId]);
        return $stmt->fetchAll();
    }

    public function addBloqueio(int $clinicaId, array $data, int $usuarioId): int
    {
        $stmt = $this->pdo->prepare('
            INSERT INTO bloqueios_espaco
                (espaco_tipo, espaco_id, data_inicio, data_fim,
                 hora_inicio, hora_fim, motivo, created_by)
            VALUES
                (\'clinica\', :espaco_id, :data_inicio, :data_fim,
                 :hora_inicio, :hora_fim, :motivo, :created_by)
        ');

        $stmt->execute([
            ':espaco_id'   => $clinicaId,
            ':data_inicio' => $data['data_inicio'],
            ':data_fim'    => $data['data_fim'],
            ':hora_inicio' => !empty($data['hora_inicio']) ? $data['hora_inicio'] : null,
            ':hora_fim'    => !empty($data['hora_fim']) ? $data['hora_fim'] : null,
            ':motivo'      => trim($data['motivo']),
            ':created_by'  => $usuarioId,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function removeBloqueio(int $bloqueioId): void
    {
        $this->pdo->prepare(
            "DELETE FROM bloqueios_espaco WHERE id = :id AND espaco_tipo = 'clinica'"
        )->execute([':id' => $bloqueioId]);
    }

    /**
     * Verifica se a clínica está disponível em uma data e intervalo de horas.
     * Retorna false se existir qualquer bloqueio que se sobreponha ao período.
     */
    public function estaDisponivelNaData(
        int $clinicaId,
        string $data,
        string $horaInicio,
        string $horaFim
    ): bool {
        $stmt = $this->pdo->prepare('
            SELECT COUNT(*) FROM bloqueios_espaco
            WHERE espaco_tipo  = \'clinica\'
              AND espaco_id    = :clinica_id
              AND data_inicio <= :data
              AND data_fim    >= :data
              AND (
                  hora_inicio IS NULL
                  OR (hora_inicio < :hora_fim AND hora_fim > :hora_inicio)
              )
        ');
        $stmt->execute([
            ':clinica_id'  => $clinicaId,
            ':data'        => $data,
            ':hora_inicio' => $horaInicio,
            ':hora_fim'    => $horaFim,
        ]);
        return (int) $stmt->fetchColumn() === 0;
    }
}

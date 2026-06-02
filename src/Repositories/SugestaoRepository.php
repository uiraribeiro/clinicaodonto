<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Persistência das sugestões geradas pelo Bedrock (tabela sugestoes_ia).
 */
class SugestaoRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function findByVersao(int $versaoId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT s.*, u.nome AS acao_por_nome
             FROM sugestoes_ia s
             LEFT JOIN usuarios u ON u.id = s.acao_por
             WHERE s.versao_id = ?
             ORDER BY
                 FIELD(s.prioridade, "critica","alta","media","baixa"),
                 s.created_at DESC'
        );
        $stmt->execute([$versaoId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM sugestoes_ia WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Persiste sugestões validadas retornadas pelo SuggestionValidator.
     * @param array<int,array> $sugestoes
     * @return int[] IDs inseridos
     */
    public function salvarLote(int $versaoId, array $sugestoes): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO sugestoes_ia
                (versao_id, tipo_sugestao, prioridade, problema_identificado,
                 sugestao, impacto_esperado, restricoes_respeitadas, payload_completo,
                 status, validada_pelo_sistema, motivo_invalidade)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)'
        );

        $ids = [];
        foreach ($sugestoes as $s) {
            $status   = $s['valida'] ? 'pendente' : 'invalida';
            $validado = $s['valida'] ? 1 : 0;

            $stmt->execute([
                $versaoId,
                $s['tipo_sugestao'],
                $s['prioridade'],
                $s['problema_identificado'],
                $s['sugestao'],
                $s['impacto_esperado'],
                $s['restricoes_respeitadas'],
                $s['payload_completo'],
                $status,
                $validado,
                $s['motivo_invalidade'],
            ]);
            $ids[] = (int)$this->pdo->lastInsertId();
        }
        return $ids;
    }

    public function aceitar(int $id, int $usuarioId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE sugestoes_ia SET status='aceita', acao_por=?, acao_em=NOW() WHERE id=? AND status='pendente'"
        );
        $stmt->execute([$usuarioId, $id]);
        return $stmt->rowCount() > 0;
    }

    public function rejeitar(int $id, int $usuarioId): bool
    {
        $stmt = $this->pdo->prepare(
            "UPDATE sugestoes_ia SET status='rejeitada', acao_por=?, acao_em=NOW() WHERE id=? AND status='pendente'"
        );
        $stmt->execute([$usuarioId, $id]);
        return $stmt->rowCount() > 0;
    }

    public function contarPendentes(int $versaoId): int
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM sugestoes_ia WHERE versao_id=? AND status='pendente'"
        );
        $stmt->execute([$versaoId]);
        return (int)$stmt->fetchColumn();
    }
}

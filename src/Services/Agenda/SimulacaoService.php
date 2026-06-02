<?php
declare(strict_types=1);

namespace App\Services\Agenda;

use App\Services\Optimization\DTO\OptimizationResult;
use PDO;

/**
 * Wraps AgendaService para execução em modo simulação (sandbox).
 * Versões de simulação ficam isoladas com status='simulacao' e
 * não afetam a agenda publicada. Podem ser descartadas facilmente.
 */
final class SimulacaoService
{
    public function __construct(
        private readonly AgendaService $agendaService,
        private readonly PDO           $pdo,
    ) {}

    public function simular(
        int $semestreId,
        int $usuarioId,
        string $descricao = '',
    ): OptimizationResult {
        return $this->agendaService->gerarAgenda(
            semestreId: $semestreId,
            usuarioId:  $usuarioId,
            descricao:  $descricao ?: 'Simulação',
            simulacao:  true,
        );
    }

    /** Remove uma versão de simulação (apenas simulações podem ser excluídas por aqui). */
    public function descartar(int $versaoId): void
    {
        $stmt = $this->pdo->prepare(
            "DELETE FROM agenda_versoes WHERE id = ? AND status = 'simulacao'"
        );
        $stmt->execute([$versaoId]);
    }

    /** Lista todas as simulações de um semestre. */
    public function listar(int $semestreId): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT av.*, u.nome AS criado_por_nome
             FROM agenda_versoes av
             LEFT JOIN usuarios u ON u.id = av.created_by
             WHERE av.semestre_id = ? AND av.status = 'simulacao'
             ORDER BY av.numero_versao DESC"
        );
        $stmt->execute([$semestreId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

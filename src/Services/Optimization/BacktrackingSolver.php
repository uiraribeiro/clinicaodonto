<?php
declare(strict_types=1);

namespace App\Services\Optimization;

use App\Services\Optimization\DTO\Allocation;
use App\Services\Optimization\DTO\SlotCandidate;
use App\Services\Optimization\DTO\TurmaDisciplinaPair;

/**
 * Algoritmo de backtracking com propagação de restrições.
 *
 * Estratégia:
 * - Pares ordenados por prioridade DESC (1=máxima) e depois por minimoEncontros DESC (MRV)
 * - Para cada par, tenta alocar minimoEncontros slots únicos via backtracking interno
 * - Falha parcial de um par não cancela os demais (modo best-effort)
 * - Limite de iterações evita loops infinitos em instâncias muito restritas
 */
final class BacktrackingSolver
{
    private int $iterations    = 0;
    private int $backtracks    = 0;

    // Limite conservador — 20 semanas × 6 dias × 4 slots × 10 espaços × 100 pares
    private const MAX_ITERATIONS = 500_000;

    public function __construct(
        private readonly ConstraintPropagator $propagator,
    ) {}

    /**
     * @param TurmaDisciplinaPair[] $pairs
     * @param SlotCandidate[]       $allSlots
     * @return Allocation[]
     */
    public function solve(array $pairs, array $allSlots, OptimizationContext $ctx): array
    {
        $this->iterations = 0;
        $this->backtracks = 0;

        // MRV: prioridade 1=máxima → processa primeiro; desempate por mais encontros
        usort($pairs, fn(TurmaDisciplinaPair $a, TurmaDisciplinaPair $b) =>
            $a->prioridade <=> $b->prioridade ?: $b->minimoEncontros <=> $a->minimoEncontros
        );

        $allocations = [];
        foreach ($pairs as $pair) {
            $pairAllocs = [];
            $this->allocatePair($pair, $pair->minimoEncontros, $allSlots, $ctx, $pairAllocs, []);
            $allocations = array_merge($allocations, $pairAllocs);
        }

        return $allocations;
    }

    /**
     * Tenta alocar `$remaining` encontros para o par, com backtracking interno.
     *
     * @param Allocation[] $pairAllocs Alocações já confirmadas para este par (passadas por referência)
     * @param string[]     $usedKeys   Chaves de slots já consumidos por este par
     */
    private function allocatePair(
        TurmaDisciplinaPair $pair,
        int $remaining,
        array $allSlots,
        OptimizationContext $ctx,
        array &$pairAllocs,
        array $usedKeys,
    ): bool {
        if ($remaining === 0) {
            return true;
        }

        if (++$this->iterations > self::MAX_ITERATIONS) {
            return false;
        }

        $candidates = $this->propagator->candidatosValidos($pair, $allSlots, $ctx, $usedKeys);

        if (empty($candidates)) {
            return false;
        }

        $encontroNumero = $pair->minimoEncontros - $remaining + 1;

        foreach ($candidates as $slot) {
            $alloc = new Allocation($pair, $slot, $encontroNumero);
            $ctx->addAllocation($alloc);
            $pairAllocs[] = $alloc;

            $nextUsedKeys = array_merge($usedKeys, [$slot->key()]);

            if ($this->allocatePair($pair, $remaining - 1, $allSlots, $ctx, $pairAllocs, $nextUsedKeys)) {
                return true;
            }

            // Backtrack
            $ctx->removeLastAllocation();
            array_pop($pairAllocs);
            $this->backtracks++;
        }

        return false;
    }

    public function getIterations(): int { return $this->iterations; }
    public function getBacktracks(): int  { return $this->backtracks; }
}

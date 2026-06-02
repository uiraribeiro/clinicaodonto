<?php
declare(strict_types=1);

namespace App\Services\Optimization\DTO;

/**
 * Resultado completo do otimizador.
 * Contém alocações realizadas, pares não alocados, conflitos e log de decisões.
 */
final class OptimizationResult
{
    /** @param Allocation[] $allocations */
    /** @param TurmaDisciplinaPair[] $naoAlocados */
    /** @param array[] $conflitos */
    /** @param array[] $log */
    public function __construct(
        public readonly array  $allocations,
        public readonly array  $naoAlocados,
        public readonly array  $conflitos,
        public readonly array  $log,
        public readonly int    $totalPairs,
        public readonly int    $duracaoMs,
    ) {}

    public function totalAlocados(): int
    {
        return count($this->allocations);
    }

    public function percentualSucesso(): float
    {
        if ($this->totalPairs === 0) {
            return 0.0;
        }
        $pairsAlocados = count(array_unique(
            array_map(fn(Allocation $a) => $a->pair->turmaDiscId, $this->allocations)
        ));
        return round(($pairsAlocados / $this->totalPairs) * 100, 1);
    }

    public function totalConflitos(): int
    {
        return count($this->conflitos);
    }

    public function conflitosGraves(): array
    {
        return array_filter(
            $this->conflitos,
            fn(array $c) => in_array($c['severidade'], ['critico', 'alto'], true)
        );
    }
}

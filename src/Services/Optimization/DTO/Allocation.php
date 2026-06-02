<?php
declare(strict_types=1);

namespace App\Services\Optimization\DTO;

/**
 * Uma alocação concreta: pair + slot + responsável.
 * É o resultado do otimizador para cada encontro individual.
 */
final class Allocation
{
    public function __construct(
        public readonly TurmaDisciplinaPair $pair,
        public readonly SlotCandidate       $slot,
        public readonly int                 $encontroNumero, // 1..minimoEncontros
        public readonly bool                $geradoPorIa = false,
    ) {}
}

<?php
declare(strict_types=1);

namespace App\Services\Optimization;

use App\Services\Optimization\DTO\SlotCandidate;
use App\Services\Optimization\DTO\TurmaDisciplinaPair;

/**
 * Filtra a lista global de slots para devolver apenas os válidos para um par dado o contexto atual.
 * Aplica heurísticas de ordenação para guiar o solver rumo a soluções de melhor qualidade.
 */
final class ConstraintPropagator
{
    public function __construct(
        private readonly RuleValidator $validator,
    ) {}

    /**
     * Retorna os slots válidos para o par, já ordenados por prioridade de tentativa.
     *
     * @param SlotCandidate[] $allSlots      Lista completa de slots do semestre
     * @param string[]        $usedKeys      Chaves de slots já usados por este par (mesmo par não repete slot)
     * @return SlotCandidate[]
     */
    public function candidatosValidos(
        TurmaDisciplinaPair $pair,
        array $allSlots,
        OptimizationContext $ctx,
        array $usedKeys = [],
    ): array {
        $usedSet = array_flip($usedKeys);
        $valid   = [];

        foreach ($allSlots as $slot) {
            if (isset($usedSet[$slot->key()])) {
                continue;
            }
            [$ok, ] = $this->validator->validate($pair, $slot, $ctx);
            if ($ok) {
                $valid[] = $slot;
            }
        }

        return $this->ordenar($valid, $pair, $usedKeys, $allSlots);
    }

    /**
     * Ordena candidatos priorizando:
     * 1. Semanas mais cedo (para distribuir encontros ao longo do semestre)
     * 2. Para disciplinas com alternância: semanas com paridade alternada à última usada
     * 3. Dias centrais da semana (terça-quinta) sobre extremos (segunda/sexta/sábado)
     *
     * @param SlotCandidate[] $candidates
     * @param string[]        $usedKeys
     * @param SlotCandidate[] $allSlots
     * @return SlotCandidate[]
     */
    private function ordenar(array $candidates, TurmaDisciplinaPair $pair, array $usedKeys, array $allSlots): array
    {
        if (empty($candidates)) {
            return $candidates;
        }

        // Semanas das alocações já confirmadas para este par
        $semanasUsadas = [];
        if (!empty($usedKeys)) {
            $usedSet = array_flip($usedKeys);
            foreach ($allSlots as $slot) {
                if (isset($usedSet[$slot->key()])) {
                    $semanasUsadas[] = $slot->numeroSemana;
                }
            }
        }

        $ultimaSemana = empty($semanasUsadas) ? 0 : max($semanasUsadas);

        usort($candidates, function (SlotCandidate $a, SlotCandidate $b) use ($pair, $ultimaSemana): int {
            // Alternância: prefere semana com distância 2 da última usada
            if ($pair->permiteAlternancia && $ultimaSemana > 0) {
                $aAlterna = abs($a->numeroSemana - $ultimaSemana) === 2 ? 0 : 1;
                $bAlterna = abs($b->numeroSemana - $ultimaSemana) === 2 ? 0 : 1;
                if ($aAlterna !== $bAlterna) {
                    return $aAlterna <=> $bAlterna;
                }
            }

            // Semana mais cedo primeiro
            if ($a->numeroSemana !== $b->numeroSemana) {
                return $a->numeroSemana <=> $b->numeroSemana;
            }

            // Dias centrais antes dos extremos (3=qua, 2=ter, 4=qui, 1=seg, 5=sex, 6=sáb)
            $priorDia = [3 => 0, 2 => 1, 4 => 1, 1 => 2, 5 => 2, 6 => 3];
            $pa = $priorDia[$a->diaSemana] ?? 99;
            $pb = $priorDia[$b->diaSemana] ?? 99;
            if ($pa !== $pb) {
                return $pa <=> $pb;
            }

            return $a->horaInicio <=> $b->horaInicio;
        });

        return $candidates;
    }
}

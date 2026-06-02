<?php
declare(strict_types=1);

namespace App\Services\Optimization;

use App\Services\Optimization\DTO\Allocation;

/**
 * Contexto mutável compartilhado pelo solver durante a otimização.
 * Separa dados imutáveis (capacidades, disponibilidades) do estado mutável (alocações).
 */
final class OptimizationContext
{
    /** @var Allocation[] */
    private array $allocations = [];

    /**
     * @param array<int,array{capacidade:int}>  $clinicas
     * @param array<int,array{capacidade:int}>  $laboratorios
     * @param array<int,true>                   $semanasBloqueadas    [semanaId => true]
     * @param array<string,true>                $espacosBloqueados    ['tipo:id:Y-m-d' => true]
     * @param array<string,true>                $diasBloqueados       ['Y-m-d' => true]
     * @param array<int,list<array{dia_semana:int,hora_inicio:string,hora_fim:string,semana_inicio:int,semana_fim:int}>> $professorDisp
     * @param array<int,list<array{dia_semana:int,hora_inicio:string,hora_fim:string,semana_inicio:int,semana_fim:int}>> $preceptorDisp
     * @param array<int,array<int,true>>        $professorDisciplinas [profId => [discId => true]]
     * @param array<int,array<int,true>>        $preceptorDisciplinas
     * @param array<int,int>                    $preceptorMaxTurmas   [preceptorId => maxTurmas]
     */
    public function __construct(
        private readonly array $clinicas,
        private readonly array $laboratorios,
        private readonly array $semanasBloqueadas,
        private readonly array $espacosBloqueados,
        private readonly array $diasBloqueados,
        private readonly array $professorDisp,
        private readonly array $preceptorDisp,
        private readonly array $professorDisciplinas,
        private readonly array $preceptorDisciplinas,
        private readonly array $preceptorMaxTurmas,
    ) {}

    public function addAllocation(Allocation $a): void
    {
        $this->allocations[] = $a;
    }

    public function removeLastAllocation(): void
    {
        array_pop($this->allocations);
    }

    /** @return Allocation[] */
    public function getAllocations(): array
    {
        return $this->allocations;
    }

    public function getCapacidadeClinica(int $id): int
    {
        return $this->clinicas[$id]['capacidade'] ?? 15;
    }

    public function getCapacidadeLaboratorio(int $id): int
    {
        return $this->laboratorios[$id]['capacidade'] ?? 30;
    }

    public function isSemanaBloqueada(int $semanaId): bool
    {
        return isset($this->semanasBloqueadas[$semanaId]);
    }

    public function isEspacoBloqueado(string $tipo, int $espacoId, string $data): bool
    {
        return isset($this->espacosBloqueados["{$tipo}:{$espacoId}:{$data}"]);
    }

    public function isDiaBloqueado(string $data): bool
    {
        return isset($this->diasBloqueados[$data]);
    }

    public function isProfessorDisponivel(int $profId, int $diaSemana, string $horaInicio, string $horaFim, int $numeroSemana): bool
    {
        $slots = $this->professorDisp[$profId] ?? [];
        foreach ($slots as $d) {
            if (
                $d['dia_semana'] === $diaSemana
                && $d['hora_inicio'] <= $horaInicio
                && $d['hora_fim'] >= $horaFim
                && $d['semana_inicio'] <= $numeroSemana
                && $d['semana_fim'] >= $numeroSemana
            ) {
                return true;
            }
        }
        return false;
    }

    public function isPreceptorDisponivel(int $preceptorId, int $diaSemana, string $horaInicio, string $horaFim, int $numeroSemana): bool
    {
        $slots = $this->preceptorDisp[$preceptorId] ?? [];
        foreach ($slots as $d) {
            if (
                $d['dia_semana'] === $diaSemana
                && $d['hora_inicio'] <= $horaInicio
                && $d['hora_fim'] >= $horaFim
                && $d['semana_inicio'] <= $numeroSemana
                && $d['semana_fim'] >= $numeroSemana
            ) {
                return true;
            }
        }
        return false;
    }

    public function isProfessorVinculadoDisciplina(int $profId, int $discId): bool
    {
        return isset($this->professorDisciplinas[$profId][$discId]);
    }

    public function isPreceptorVinculadoDisciplina(int $preceptorId, int $discId): bool
    {
        return isset($this->preceptorDisciplinas[$preceptorId][$discId]);
    }

    public function getMaxTurmasPreceptor(int $preceptorId): int
    {
        return $this->preceptorMaxTurmas[$preceptorId] ?? 1;
    }
}

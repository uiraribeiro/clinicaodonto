<?php
declare(strict_types=1);

namespace App\Services\Optimization\DTO;

/**
 * Um slot de tempo candidato a receber uma alocação.
 * Representa um bloco: espaço × dia × horário × semana.
 */
final class SlotCandidate
{
    public function __construct(
        public readonly int    $semanaId,
        public readonly int    $numeroSemana,
        public readonly string $dataAula,       // Y-m-d
        public readonly int    $diaSemana,      // 1=seg ... 6=sáb
        public readonly string $horaInicio,     // H:i:s
        public readonly string $horaFim,        // H:i:s
        public readonly string $espacoTipo,     // 'clinica' | 'laboratorio'
        public readonly int    $espacoId,
    ) {}

    /** Chave única para comparação rápida. */
    public function key(): string
    {
        return "{$this->espacoTipo}:{$this->espacoId}:{$this->dataAula}:{$this->horaInicio}:{$this->horaFim}";
    }

    /** Verifica sobreposição de horário com outro slot (mesmo espaço e data). */
    public function sobrepoe(SlotCandidate $outro): bool
    {
        if ($this->espacoTipo !== $outro->espacoTipo || $this->espacoId !== $outro->espacoId) {
            return false;
        }
        if ($this->dataAula !== $outro->dataAula) {
            return false;
        }
        // Sobreposição: um começa antes do outro terminar
        return $this->horaInicio < $outro->horaFim && $this->horaFim > $outro->horaInicio;
    }

    /** Verifica se dois slots ocorrem ao mesmo tempo (ignora espaço — para checar turma/responsável). */
    public function mesmoMomento(SlotCandidate $outro): bool
    {
        if ($this->dataAula !== $outro->dataAula) {
            return false;
        }
        return $this->horaInicio < $outro->horaFim && $this->horaFim > $outro->horaInicio;
    }
}

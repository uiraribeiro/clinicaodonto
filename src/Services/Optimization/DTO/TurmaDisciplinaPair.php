<?php
declare(strict_types=1);

namespace App\Services\Optimization\DTO;

/**
 * Representa uma combinação turma × disciplina que precisa ser alocada.
 * É a unidade de trabalho do otimizador: para cada pair, o solver
 * precisa encontrar minimo_encontros slots válidos no semestre.
 */
final class TurmaDisciplinaPair
{
    public function __construct(
        public readonly int     $turmaDiscId,           // turma_disciplina.id
        public readonly int     $turmaId,
        public readonly string  $turmaNome,
        public readonly int     $numAlunos,
        public readonly int     $disciplinaId,
        public readonly string  $disciplinaNome,
        public readonly string  $disciplinaTipo,        // 'estagio' | 'pratica_comum'
        public readonly bool    $usaClinica,
        public readonly bool    $usaLaboratorio,
        public readonly int     $minimoEncontros,
        public readonly int     $duracaoEncontroMin,
        public readonly int     $semanaInicio,
        public readonly int     $semanaFim,
        public readonly int     $prioridade,
        public readonly bool    $permiteAlternancia,
        public readonly ?int    $professorId,
        public readonly ?int    $preceptorId,
        public readonly string  $semestreRef,
        public readonly string  $turno = 'manha',       // matutino1 | matutino2 | manha | tarde | vespertino | noturno
        public readonly ?int    $diaSemanaPreferencial = null, // 1=seg...6=sáb, null=sem preferência
    ) {}

    /** Retorna a duração em horas (para logs legíveis). */
    public function duracaoHoras(): float
    {
        return $this->duracaoEncontroMin / 60;
    }

    /** Identificador único legível para logs. */
    public function label(): string
    {
        return "{$this->turmaNome} / {$this->disciplinaNome}";
    }
}

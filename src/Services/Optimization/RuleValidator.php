<?php
declare(strict_types=1);

namespace App\Services\Optimization;

use App\Services\Optimization\DTO\Allocation;
use App\Services\Optimization\DTO\SlotCandidate;
use App\Services\Optimization\DTO\TurmaDisciplinaPair;

/**
 * Valida se um slot é apto para receber uma alocação dado o contexto atual.
 * Pura e sem efeitos colaterais — ideal para testes unitários.
 *
 * Retorna [bool $valido, string $motivo].
 */
final class RuleValidator
{
    /**
     * Executa todas as regras em sequência e retorna na primeira violação.
     *
     * @return array{0:bool,1:string}
     */
    public function validate(
        TurmaDisciplinaPair $pair,
        SlotCandidate $slot,
        OptimizationContext $ctx,
    ): array {
        $rules = [
            fn() => $this->checkDuracao($pair, $slot),
            fn() => $this->checkEspacoTipo($pair, $slot),
            fn() => $this->checkCapacidade($pair, $slot, $ctx),
            fn() => $this->checkSemanaRange($pair, $slot),
            fn() => $this->checkDiaBloqueado($slot, $ctx),
            fn() => $this->checkSemanaBloqueada($slot, $ctx),
            fn() => $this->checkEspacoBloqueado($slot, $ctx),
            fn() => $this->checkVinculoProfessor($pair, $ctx),
            fn() => $this->checkVinculoPreceptor($pair, $ctx),
            fn() => $this->checkDisponibilidadeProfessor($pair, $slot, $ctx),
            fn() => $this->checkDisponibilidadePreceptor($pair, $slot, $ctx),
            fn() => $this->checkSobreposicaoEspaco($slot, $ctx),
            fn() => $this->checkSobreposicaoTurma($pair, $slot, $ctx),
            fn() => $this->checkSobreposicaoProfessor($pair, $slot, $ctx),
            fn() => $this->checkSobreposicaoPreceptor($pair, $slot, $ctx),
            fn() => $this->checkMaxTurmasPreceptor($pair, $slot, $ctx),
        ];

        foreach ($rules as $rule) {
            [$ok, $motivo] = $rule();
            if (!$ok) {
                return [false, $motivo];
            }
        }
        return [true, ''];
    }

    // -------------------------------------------------------------------------
    // Regras individuais
    // -------------------------------------------------------------------------

    /** @return array{0:bool,1:string} */
    private function checkDuracao(TurmaDisciplinaPair $pair, SlotCandidate $slot): array
    {
        [$h1, $m1] = array_map('intval', explode(':', $slot->horaInicio));
        [$h2, $m2] = array_map('intval', explode(':', $slot->horaFim));
        $duracaoSlot = ($h2 * 60 + $m2) - ($h1 * 60 + $m1);
        if ($duracaoSlot !== $pair->duracaoEncontroMin) {
            return [false, "duração slot ({$duracaoSlot}min) ≠ disciplina ({$pair->duracaoEncontroMin}min)"];
        }
        return [true, ''];
    }

    /** @return array{0:bool,1:string} */
    private function checkEspacoTipo(TurmaDisciplinaPair $pair, SlotCandidate $slot): array
    {
        if ($slot->espacoTipo === 'clinica' && !$pair->usaClinica) {
            return [false, 'disciplina não usa clínica'];
        }
        if ($slot->espacoTipo === 'laboratorio' && !$pair->usaLaboratorio) {
            return [false, 'disciplina não usa laboratório'];
        }
        return [true, ''];
    }

    /** @return array{0:bool,1:string} */
    private function checkCapacidade(TurmaDisciplinaPair $pair, SlotCandidate $slot, OptimizationContext $ctx): array
    {
        if ($slot->espacoTipo === 'clinica') {
            // Cada cadeira acomoda 2 alunos
            $capacidade = $ctx->getCapacidadeClinica($slot->espacoId) * 2;
            if ($pair->numAlunos > $capacidade) {
                return [false, "turma ({$pair->numAlunos} alunos) excede capacidade clínica ({$capacidade})"];
            }
        } elseif ($slot->espacoTipo === 'laboratorio') {
            $capacidade = $ctx->getCapacidadeLaboratorio($slot->espacoId);
            if ($pair->numAlunos > $capacidade) {
                return [false, "turma ({$pair->numAlunos} alunos) excede capacidade lab ({$capacidade})"];
            }
        }
        return [true, ''];
    }

    /** @return array{0:bool,1:string} */
    private function checkSemanaRange(TurmaDisciplinaPair $pair, SlotCandidate $slot): array
    {
        if ($slot->numeroSemana < $pair->semanaInicio || $slot->numeroSemana > $pair->semanaFim) {
            return [false, "semana {$slot->numeroSemana} fora do range [{$pair->semanaInicio},{$pair->semanaFim}]"];
        }
        return [true, ''];
    }

    /** @return array{0:bool,1:string} */
    private function checkDiaBloqueado(SlotCandidate $slot, OptimizationContext $ctx): array
    {
        if ($ctx->isDiaBloqueado($slot->dataAula)) {
            return [false, "dia {$slot->dataAula} é feriado/bloqueado"];
        }
        return [true, ''];
    }

    /** @return array{0:bool,1:string} */
    private function checkSemanaBloqueada(SlotCandidate $slot, OptimizationContext $ctx): array
    {
        if ($ctx->isSemanaBloqueada($slot->semanaId)) {
            return [false, "semana {$slot->numeroSemana} está bloqueada"];
        }
        return [true, ''];
    }

    /** @return array{0:bool,1:string} */
    private function checkEspacoBloqueado(SlotCandidate $slot, OptimizationContext $ctx): array
    {
        if ($ctx->isEspacoBloqueado($slot->espacoTipo, $slot->espacoId, $slot->dataAula)) {
            return [false, "{$slot->espacoTipo} #{$slot->espacoId} bloqueado em {$slot->dataAula}"];
        }
        return [true, ''];
    }

    /** @return array{0:bool,1:string} */
    private function checkVinculoProfessor(TurmaDisciplinaPair $pair, OptimizationContext $ctx): array
    {
        if ($pair->professorId === null) {
            return [true, ''];
        }
        if (!$ctx->isProfessorVinculadoDisciplina($pair->professorId, $pair->disciplinaId)) {
            return [false, "professor #{$pair->professorId} não vinculado à disciplina #{$pair->disciplinaId}"];
        }
        return [true, ''];
    }

    /** @return array{0:bool,1:string} */
    private function checkVinculoPreceptor(TurmaDisciplinaPair $pair, OptimizationContext $ctx): array
    {
        if ($pair->preceptorId === null) {
            return [true, ''];
        }
        if (!$ctx->isPreceptorVinculadoDisciplina($pair->preceptorId, $pair->disciplinaId)) {
            return [false, "preceptor #{$pair->preceptorId} não vinculado à disciplina #{$pair->disciplinaId}"];
        }
        return [true, ''];
    }

    /** @return array{0:bool,1:string} */
    private function checkDisponibilidadeProfessor(TurmaDisciplinaPair $pair, SlotCandidate $slot, OptimizationContext $ctx): array
    {
        if ($pair->professorId === null) {
            return [true, ''];
        }
        if (!$ctx->isProfessorDisponivel($pair->professorId, $slot->diaSemana, $slot->horaInicio, $slot->horaFim, $slot->numeroSemana)) {
            return [false, "professor #{$pair->professorId} indisponível no horário"];
        }
        return [true, ''];
    }

    /** @return array{0:bool,1:string} */
    private function checkDisponibilidadePreceptor(TurmaDisciplinaPair $pair, SlotCandidate $slot, OptimizationContext $ctx): array
    {
        if ($pair->preceptorId === null) {
            return [true, ''];
        }
        if (!$ctx->isPreceptorDisponivel($pair->preceptorId, $slot->diaSemana, $slot->horaInicio, $slot->horaFim, $slot->numeroSemana)) {
            return [false, "preceptor #{$pair->preceptorId} indisponível no horário"];
        }
        return [true, ''];
    }

    /** @return array{0:bool,1:string} */
    private function checkSobreposicaoEspaco(SlotCandidate $slot, OptimizationContext $ctx): array
    {
        foreach ($ctx->getAllocations() as $alloc) {
            if ($alloc->slot->sobrepoe($slot)) {
                return [false, "espaço já ocupado ({$alloc->pair->label()})"];
            }
        }
        return [true, ''];
    }

    /** @return array{0:bool,1:string} */
    private function checkSobreposicaoTurma(TurmaDisciplinaPair $pair, SlotCandidate $slot, OptimizationContext $ctx): array
    {
        foreach ($ctx->getAllocations() as $alloc) {
            if ($alloc->pair->turmaId !== $pair->turmaId) {
                continue;
            }
            if ($alloc->slot->mesmoMomento($slot)) {
                return [false, "turma já tem aula em outro espaço no mesmo horário ({$alloc->pair->label()})"];
            }
        }
        return [true, ''];
    }

    /** @return array{0:bool,1:string} */
    private function checkSobreposicaoProfessor(TurmaDisciplinaPair $pair, SlotCandidate $slot, OptimizationContext $ctx): array
    {
        if ($pair->professorId === null) {
            return [true, ''];
        }
        foreach ($ctx->getAllocations() as $alloc) {
            if ($alloc->pair->professorId !== $pair->professorId) {
                continue;
            }
            if ($alloc->slot->mesmoMomento($slot)) {
                return [false, "professor #{$pair->professorId} já alocado em outro horário ({$alloc->pair->label()})"];
            }
        }
        return [true, ''];
    }

    /** @return array{0:bool,1:string} */
    private function checkSobreposicaoPreceptor(TurmaDisciplinaPair $pair, SlotCandidate $slot, OptimizationContext $ctx): array
    {
        if ($pair->preceptorId === null) {
            return [true, ''];
        }
        foreach ($ctx->getAllocations() as $alloc) {
            if ($alloc->pair->preceptorId !== $pair->preceptorId) {
                continue;
            }
            if ($alloc->slot->mesmoMomento($slot)) {
                return [false, "preceptor #{$pair->preceptorId} já alocado em outro horário ({$alloc->pair->label()})"];
            }
        }
        return [true, ''];
    }

    /** @return array{0:bool,1:string} */
    private function checkMaxTurmasPreceptor(TurmaDisciplinaPair $pair, SlotCandidate $slot, OptimizationContext $ctx): array
    {
        if ($pair->preceptorId === null) {
            return [true, ''];
        }
        $max = $ctx->getMaxTurmasPreceptor($pair->preceptorId);
        $turmasSimultaneas = [];
        foreach ($ctx->getAllocations() as $alloc) {
            if ($alloc->pair->preceptorId !== $pair->preceptorId) {
                continue;
            }
            if ($alloc->slot->mesmoMomento($slot)) {
                $turmasSimultaneas[$alloc->pair->turmaId] = true;
            }
        }
        $total = count($turmasSimultaneas);
        if ($total >= $max) {
            return [false, "preceptor #{$pair->preceptorId} já atende {$total}/{$max} turmas simultâneas"];
        }
        return [true, ''];
    }
}

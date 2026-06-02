<?php
declare(strict_types=1);

namespace App\Services\Optimization;

use App\Services\Optimization\DTO\Allocation;

/**
 * Analisa o conjunto final de alocações e cataloga os conflitos remanescentes.
 * Executado APÓS o solver — detecta situações que o solver não conseguiu evitar
 * (ex.: pares sem professor definido, restrições de turma não modeladas no solver).
 */
final class ConflictDetector
{
    /**
     * @param Allocation[] $allocations
     * @param array<int,array{turmaDiscId:int,label:string,minimoEncontros:int}> $naoAlocadosInfo
     * @return array<int,array{tipo:string,severidade:string,descricao:string,ids:list<int>}>
     */
    public function detectar(array $allocations, array $naoAlocadosInfo = []): array
    {
        $conflitos = [];

        $n = count($allocations);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $allocations[$i];
                $b = $allocations[$j];

                // Conflito de espaço físico
                if ($a->slot->sobrepoe($b->slot)) {
                    $conflitos[] = [
                        'tipo'      => 'sobreposicao_espaco',
                        'severidade'=> 'critico',
                        'descricao' => sprintf(
                            'Conflito de espaço: %s e %s ocupam o mesmo %s #%d em %s %s–%s',
                            $a->pair->label(), $b->pair->label(),
                            $a->slot->espacoTipo, $a->slot->espacoId,
                            $a->slot->dataAula, $a->slot->horaInicio, $a->slot->horaFim
                        ),
                        'ids' => [$i, $j],
                    ];
                }

                // Turma em dois lugares ao mesmo tempo
                if ($a->pair->turmaId === $b->pair->turmaId && $a->slot->mesmoMomento($b->slot)) {
                    $conflitos[] = [
                        'tipo'      => 'sobreposicao_turma',
                        'severidade'=> 'critico',
                        'descricao' => sprintf(
                            'Turma %s alocada em dois espaços simultaneamente: %s e %s',
                            $a->pair->turmaNome, $a->pair->label(), $b->pair->label()
                        ),
                        'ids' => [$i, $j],
                    ];
                }

                // Professor em dois locais ao mesmo tempo
                if (
                    $a->pair->professorId !== null
                    && $a->pair->professorId === $b->pair->professorId
                    && $a->slot->mesmoMomento($b->slot)
                ) {
                    $conflitos[] = [
                        'tipo'      => 'sobreposicao_professor',
                        'severidade'=> 'alto',
                        'descricao' => sprintf(
                            'Professor #%d alocado simultaneamente em %s e %s',
                            $a->pair->professorId, $a->pair->label(), $b->pair->label()
                        ),
                        'ids' => [$i, $j],
                    ];
                }

                // Preceptor em dois locais ao mesmo tempo
                if (
                    $a->pair->preceptorId !== null
                    && $a->pair->preceptorId === $b->pair->preceptorId
                    && $a->slot->mesmoMomento($b->slot)
                ) {
                    $conflitos[] = [
                        'tipo'      => 'sobreposicao_preceptor',
                        'severidade'=> 'alto',
                        'descricao' => sprintf(
                            'Preceptor #%d alocado simultaneamente em %s e %s',
                            $a->pair->preceptorId, $a->pair->label(), $b->pair->label()
                        ),
                        'ids' => [$i, $j],
                    ];
                }
            }
        }

        // Pares não completamente alocados
        foreach ($naoAlocadosInfo as $info) {
            $conflitos[] = [
                'tipo'       => 'nao_alocado',
                'severidade' => 'medio',
                'descricao'  => "Não foi possível alocar todos os encontros de {$info['label']} (mínimo: {$info['minimoEncontros']})",
                'ids'        => [],
            ];
        }

        return $conflitos;
    }
}

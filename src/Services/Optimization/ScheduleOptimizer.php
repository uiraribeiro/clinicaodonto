<?php
declare(strict_types=1);

namespace App\Services\Optimization;

use App\Services\Optimization\DTO\Allocation;
use App\Services\Optimization\DTO\OptimizationResult;
use App\Services\Optimization\DTO\SlotCandidate;
use App\Services\Optimization\DTO\TurmaDisciplinaPair;
use PDO;

/**
 * Orquestra a execução completa do otimizador:
 *   1. Executa o BacktrackingSolver
 *   2. Detecta conflitos remanescentes
 *   3. Salva agendamentos no banco
 *   4. Salva conflitos
 *   5. Atualiza score da versão
 *   6. Retorna OptimizationResult auditável
 */
final class ScheduleOptimizer
{
    public function __construct(
        private readonly BacktrackingSolver $solver,
        private readonly ConflictDetector   $conflictDetector,
        private readonly OptimizationLogger $logger,
        private readonly PDO                $pdo,
    ) {}

    /**
     * @param TurmaDisciplinaPair[] $pairs
     * @param SlotCandidate[]       $allSlots
     */
    public function otimizar(
        int $versaoId,
        int $usuarioId,
        array $pairs,
        array $allSlots,
        OptimizationContext $ctx,
    ): OptimizationResult {
        $inicio = hrtime(true);

        $this->logger->logInicio($versaoId, count($pairs), count($allSlots));

        // ── Solver ──────────────────────────────────────────────────────────
        $allocations = $this->solver->solve($pairs, $allSlots, $ctx);

        // ── Detectar não-alocados ───────────────────────────────────────────
        $alocadosPorPair = [];
        foreach ($allocations as $a) {
            $alocadosPorPair[$a->pair->turmaDiscId] = ($alocadosPorPair[$a->pair->turmaDiscId] ?? 0) + 1;
        }

        $naoAlocados    = [];
        $naoAlocadosInfo = [];
        foreach ($pairs as $pair) {
            $count = $alocadosPorPair[$pair->turmaDiscId] ?? 0;
            if ($count < $pair->minimoEncontros) {
                $naoAlocados[]     = $pair;
                $naoAlocadosInfo[] = [
                    'turmaDiscId'   => $pair->turmaDiscId,
                    'label'         => $pair->label(),
                    'minimoEncontros'=> $pair->minimoEncontros,
                    'alocados'      => $count,
                ];
            }
        }

        // ── Detectar conflitos ──────────────────────────────────────────────
        $conflitos = $this->conflictDetector->detectar($allocations, $naoAlocadosInfo);

        // ── Persistir no banco ──────────────────────────────────────────────
        $this->pdo->beginTransaction();
        try {
            $agendamentoIds = $this->salvarAgendamentos($versaoId, $usuarioId, $allocations);
            $this->salvarConflitos($versaoId, $conflitos, $agendamentoIds);
            $this->atualizarVersao($versaoId, $allocations, $conflitos, $pairs);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        $duracaoMs = (int)(((hrtime(true) - $inicio)) / 1_000_000);

        $this->logger->logFim($versaoId, count($allocations), count($naoAlocados), count($conflitos), $duracaoMs);

        return new OptimizationResult(
            allocations: $allocations,
            naoAlocados: $naoAlocados,
            conflitos:   $conflitos,
            log:         [],
            totalPairs:  count($pairs),
            duracaoMs:   $duracaoMs,
        );
    }

    /**
     * @param Allocation[] $allocations
     * @return array<int,int>  índice-alocação => agendamento_id inserido
     */
    private function salvarAgendamentos(int $versaoId, int $usuarioId, array $allocations): array
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO agendamentos
                 (versao_id, semana_id, turma_id, disciplina_id,
                  espaco_tipo, espaco_id, professor_id, preceptor_id,
                  dia_semana, data_aula, hora_inicio, hora_fim,
                  num_alunos, status, gerado_por_ia, created_by)
             VALUES
                 (:versao, :semana, :turma, :disc,
                  :etipo, :eid, :prof, :prec,
                  :dia, :data, :inicio, :fim,
                  :nalunos, :status, :ia, :criado)'
        );

        $ids = [];
        foreach ($allocations as $i => $a) {
            $stmt->execute([
                'versao'  => $versaoId,
                'semana'  => $a->slot->semanaId,
                'turma'   => $a->pair->turmaId,
                'disc'    => $a->pair->disciplinaId,
                'etipo'   => $a->slot->espacoTipo,
                'eid'     => $a->slot->espacoId,
                'prof'    => $a->pair->professorId,
                'prec'    => $a->pair->preceptorId,
                'dia'     => $a->slot->diaSemana,
                'data'    => $a->slot->dataAula,
                'inicio'  => $a->slot->horaInicio,
                'fim'     => $a->slot->horaFim,
                'nalunos' => $a->pair->numAlunos,
                'status'  => 'agendado',
                'ia'      => $a->geradoPorIa ? 1 : 0,
                'criado'  => $usuarioId,
            ]);
            $ids[$i] = (int)$this->pdo->lastInsertId();
        }
        return $ids;
    }

    private function salvarConflitos(int $versaoId, array $conflitos, array $agendamentoIds): void
    {
        if (empty($conflitos)) {
            return;
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO conflitos (versao_id, tipo, severidade, descricao, agendamento_ids)
             VALUES (:versao, :tipo, :sev, :desc, :ids)'
        );
        foreach ($conflitos as $c) {
            $idsEnvolvidos = array_map(fn(int $i) => $agendamentoIds[$i] ?? null, $c['ids']);
            $idsEnvolvidos = array_filter($idsEnvolvidos);
            $stmt->execute([
                'versao' => $versaoId,
                'tipo'   => $c['tipo'],
                'sev'    => $c['severidade'],
                'desc'   => $c['descricao'],
                'ids'    => empty($idsEnvolvidos) ? null : json_encode(array_values($idsEnvolvidos)),
            ]);
        }
    }

    /** @param TurmaDisciplinaPair[] $pairs */
    private function atualizarVersao(int $versaoId, array $allocations, array $conflitos, array $pairs): void
    {
        // Score = percentual de encontros alocados vs necessário
        $totalNecessario = array_sum(array_map(fn($p) => $p->minimoEncontros, $pairs));
        $score = $totalNecessario > 0 ? round(count($allocations) / $totalNecessario * 100, 2) : 0.0;

        $stmt = $this->pdo->prepare(
            'UPDATE agenda_versoes
             SET score_ocupacao = :score, total_conflitos = :conf
             WHERE id = :id'
        );
        $stmt->execute([
            'score' => $score,
            'conf'  => count($conflitos),
            'id'    => $versaoId,
        ]);
    }
}

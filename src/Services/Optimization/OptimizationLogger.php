<?php
declare(strict_types=1);

namespace App\Services\Optimization;

use App\Services\Optimization\DTO\SlotCandidate;
use App\Services\Optimization\DTO\TurmaDisciplinaPair;
use PDO;

/**
 * Grava eventos do otimizador na tabela optimization_logs.
 * Usa INSERT em batch para minimizar round-trips ao banco.
 */
final class OptimizationLogger
{
    /** Buffer de registros pendentes de flush */
    private array $buffer = [];
    private const BATCH_SIZE = 50;

    public function __construct(private readonly PDO $pdo) {}

    public function logInicio(int $versaoId, int $totalPairs, int $totalSlots): void
    {
        $this->append($versaoId, 'inicio_otimizacao', null, null, null, 'sucesso', null);
        $this->flush();
    }

    public function logTentativa(
        int $versaoId,
        TurmaDisciplinaPair $pair,
        SlotCandidate $slot,
        bool $sucesso,
        string $motivo = '',
    ): void {
        $slotJson = json_encode([
            'semana'    => $slot->numeroSemana,
            'data'      => $slot->dataAula,
            'dia'       => $slot->diaSemana,
            'inicio'    => $slot->horaInicio,
            'fim'       => $slot->horaFim,
            'espaco'    => "{$slot->espacoTipo}#{$slot->espacoId}",
        ], JSON_UNESCAPED_UNICODE);

        $this->append(
            $versaoId,
            'tentativa_alocacao',
            $pair->turmaId,
            $pair->disciplinaId,
            $slotJson,
            $sucesso ? 'sucesso' : 'falha',
            $motivo ?: null,
        );

        if (count($this->buffer) >= self::BATCH_SIZE) {
            $this->flush();
        }
    }

    public function logBacktrack(int $versaoId, TurmaDisciplinaPair $pair): void
    {
        $this->append($versaoId, 'backtrack', $pair->turmaId, $pair->disciplinaId, null, 'backtrack', null);
    }

    public function logFim(int $versaoId, int $alocados, int $naoAlocados, int $conflitos, int $duracaoMs): void
    {
        $this->flush();
        $dados = json_encode([
            'alocados'    => $alocados,
            'nao_alocados'=> $naoAlocados,
            'conflitos'   => $conflitos,
        ], JSON_UNESCAPED_UNICODE);
        $this->append($versaoId, 'fim_otimizacao', null, null, $dados, 'sucesso', null, $duracaoMs);
        $this->flush();
    }

    public function flush(): void
    {
        if (empty($this->buffer)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($this->buffer), '(?,?,?,?,?,?,?,?)'));
        $sql = "INSERT INTO optimization_logs
                    (versao_id, acao, turma_id, disciplina_id, slot_tentado, resultado, motivo_falha, duracao_ms)
                VALUES {$placeholders}";

        $params = [];
        foreach ($this->buffer as $row) {
            $params = array_merge($params, $row);
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $this->buffer = [];
    }

    private function append(
        int $versaoId,
        string $acao,
        ?int $turmaId,
        ?int $disciplinaId,
        ?string $slotJson,
        string $resultado,
        ?string $motivo,
        ?int $duracaoMs = null,
    ): void {
        $this->buffer[] = [$versaoId, $acao, $turmaId, $disciplinaId, $slotJson, $resultado, $motivo, $duracaoMs];
    }
}

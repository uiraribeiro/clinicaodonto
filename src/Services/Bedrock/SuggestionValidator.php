<?php
declare(strict_types=1);

namespace App\Services\Bedrock;

use PDO;

/**
 * Valida sugestões do Bedrock antes de exibi-las ao usuário.
 *
 * Validações realizadas:
 * 1. Estrutura JSON correta (schema obrigatório)
 * 2. Tipo e prioridade dentro dos valores permitidos
 * 3. Checagem de conflito de espaço via DB (se acao.tipo == mover_agendamento)
 * 4. Agendamento referenciado existe e pertence à versão
 *
 * Sugestões que passam recebem validada_pelo_sistema=1.
 * Sugestões com estrutura inválida recebem status='invalida'.
 */
final class SuggestionValidator
{
    private const TIPOS_VALIDOS       = ['mover_agendamento', 'redistribuir_turma', 'sugerir_novo_slot', 'remover_conflito'];
    private const PRIORIDADES_VALIDAS = ['critica', 'alta', 'media', 'baixa'];
    private const TIPOS_SUGESTAO      = [
        'mover_agendamento', 'redistribuir_turma', 'sugerir_novo_slot',
        'remover_conflito', 'analise_gargalo', 'analise_geral',
    ];

    public function __construct(private readonly PDO $pdo) {}

    /**
     * Parseia o texto JSON do Bedrock e retorna lista de sugestões validadas.
     * Cada item terá 'valida' => bool e 'motivo_invalidade' => string|null.
     *
     * @return array<int,array>
     */
    public function parsearEValidar(string $textoJson, int $versaoId): array
    {
        $decoded = json_decode($textoJson, true);

        if (json_last_error() !== JSON_ERROR_NONE || !isset($decoded['sugestoes'])) {
            return []; // JSON inválido — salvo como erro no caller
        }

        $resultado = [];
        foreach ((array)$decoded['sugestoes'] as $sug) {
            [$valida, $motivo] = $this->validarSugestao($sug, $versaoId);
            $resultado[] = [
                'tipo_sugestao'          => $this->normalizarTipo($sug['tipo'] ?? $sug['acao']['tipo'] ?? 'analise_geral'),
                'prioridade'             => $this->normalizarPrioridade($sug['prioridade'] ?? 'media'),
                'problema_identificado'  => $sug['problema_identificado'] ?? $sug['problema'] ?? '',
                'sugestao'               => $sug['sugestao'] ?? '',
                'impacto_esperado'       => $sug['impacto_esperado'] ?? $sug['impacto'] ?? null,
                'restricoes_respeitadas' => json_encode(['validado_em' => date('Y-m-d H:i:s')], JSON_UNESCAPED_UNICODE),
                'payload_completo'       => json_encode($sug, JSON_UNESCAPED_UNICODE),
                'valida'                 => $valida,
                'motivo_invalidade'      => $motivo,
            ];
        }
        return $resultado;
    }

    // =========================================================================
    // Validação individual
    // =========================================================================

    /** @return array{0:bool,1:string|null} */
    private function validarSugestao(array $sug, int $versaoId): array
    {
        // 1. Campos obrigatórios presentes
        if (empty($sug['sugestao'])) {
            return [false, 'Campo "sugestao" ausente ou vazio'];
        }

        // 2. Prioridade válida
        $prio = strtolower($sug['prioridade'] ?? '');
        if ($prio && !in_array($prio, self::PRIORIDADES_VALIDAS, true)) {
            return [false, "Prioridade inválida: {$prio}"];
        }

        // 3. Para sugestões de mover agendamento: valida via DB
        $acao = $sug['acao'] ?? [];
        if (($acao['tipo'] ?? '') === 'mover_agendamento') {
            return $this->validarMoverAgendamento($acao, $versaoId);
        }

        return [true, null];
    }

    /** @return array{0:bool,1:string|null} */
    private function validarMoverAgendamento(array $acao, int $versaoId): array
    {
        // Agendamento referenciado existe e pertence à versão
        if (!empty($acao['agendamento_id'])) {
            $stmt = $this->pdo->prepare(
                'SELECT id, espaco_tipo, espaco_id FROM agendamentos WHERE id = ? AND versao_id = ?'
            );
            $stmt->execute([(int)$acao['agendamento_id'], $versaoId]);
            $ag = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$ag) {
                return [false, "Agendamento #{$acao['agendamento_id']} não encontrado na versão #{$versaoId}"];
            }
        }

        // Se há data e espaço destino, verifica se já está ocupado
        if (!empty($acao['nova_data']) && !empty($acao['nova_hora_inicio']) && !empty($acao['nova_hora_fim'])) {
            $espacoTipo = $acao['novo_espaco_tipo'] ?? ($ag['espaco_tipo'] ?? null);
            $espacoId   = (int)($acao['novo_espaco_id'] ?? ($ag['espaco_id'] ?? 0));

            if ($espacoTipo && $espacoId) {
                $stmt = $this->pdo->prepare(
                    'SELECT COUNT(*) FROM agendamentos
                     WHERE versao_id = ? AND espaco_tipo = ? AND espaco_id = ?
                       AND data_aula = ? AND status != "cancelado"
                       AND hora_inicio < ? AND hora_fim > ?'
                );
                $stmt->execute([
                    $versaoId, $espacoTipo, $espacoId,
                    $acao['nova_data'], $acao['nova_hora_fim'], $acao['nova_hora_inicio'],
                ]);
                if ((int)$stmt->fetchColumn() > 0) {
                    return [false, "Slot destino ({$espacoTipo} #{$espacoId} em {$acao['nova_data']}) já está ocupado"];
                }
            }
        }

        return [true, null];
    }

    // =========================================================================
    // Normalização de valores
    // =========================================================================

    private function normalizarTipo(string $tipo): string
    {
        $tipo = strtolower(trim($tipo));
        return in_array($tipo, self::TIPOS_SUGESTAO, true) ? $tipo : 'analise_geral';
    }

    private function normalizarPrioridade(string $prio): string
    {
        $prio = strtolower(trim($prio));
        return in_array($prio, self::PRIORIDADES_VALIDAS, true) ? $prio : 'media';
    }
}

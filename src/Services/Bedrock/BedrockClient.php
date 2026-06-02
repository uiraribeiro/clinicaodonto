<?php
declare(strict_types=1);

namespace App\Services\Bedrock;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Exception\AwsException;
use PDO;
use Predis\Client as Redis;

/**
 * Encapsula TODAS as chamadas ao AWS Bedrock.
 * Nenhum outro código do sistema chama o SDK diretamente.
 *
 * Responsabilidades:
 * - Construção e envio de requisições ao modelo
 * - Cache Redis por hash do prompt (TTL configurável)
 * - Log completo em bedrock_logs (tokens, custo estimado, status)
 * - Graceful degradation quando BEDROCK_ENABLED=false
 */
final class BedrockClient
{
    // Custos por token (Nova Lite: $0.06/$0.24 por 1M; Claude 3.5: $3/$15 por 1M)
    private const CUSTOS = [
        'amazon.nova' => ['in' => 0.00000006, 'out' => 0.00000024],
        'anthropic'   => ['in' => 0.000003,   'out' => 0.000015],
    ];

    private readonly BedrockRuntimeClient $aws;
    private readonly string $modelId;
    private readonly int $maxTokens;
    private readonly float $temperature;
    private readonly bool $enabled;
    private readonly int $cacheTtl;

    public function __construct(
        private readonly PDO   $pdo,
        private readonly Redis $redis,
    ) {
        $this->enabled     = filter_var($_ENV['BEDROCK_ENABLED'] ?? 'true', FILTER_VALIDATE_BOOLEAN);
        $this->modelId     = $_ENV['BEDROCK_MODEL_ID']     ?? 'anthropic.claude-3-5-sonnet-20241022-v2:0';
        $this->maxTokens   = (int)($_ENV['BEDROCK_MAX_TOKENS']    ?? 4096);
        $this->temperature = (float)($_ENV['BEDROCK_TEMPERATURE']  ?? 0.3);
        $this->cacheTtl    = (int)($_ENV['REDIS_CACHE_TTL']        ?? 300);

        if ($this->enabled) {
            $this->aws = new BedrockRuntimeClient([
                'version'     => 'latest',
                'region'      => $_ENV['AWS_BEDROCK_REGION'] ?? 'us-east-1',
                'credentials' => [
                    'key'    => $_ENV['AWS_ACCESS_KEY_ID']     ?? '',
                    'secret' => $_ENV['AWS_SECRET_ACCESS_KEY'] ?? '',
                ],
            ]);
        }
    }

    /**
     * Envia uma mensagem única ao modelo e retorna o texto da resposta.
     * Usa cache Redis para prompts idênticos.
     *
     * @param string      $systemPrompt  Instrução de sistema (papel, contexto, formato de saída)
     * @param string      $userMessage   Mensagem do usuário
     * @param array       $history       Histórico de mensagens [{role,content}, ...]
     * @param string      $tipoChamada   Para logging: 'sugestao_agenda' | 'analise_conflito' | 'chat'
     * @param int|null    $versaoId      Versão de agenda relacionada (para log)
     * @param int|null    $usuarioId     Usuário que disparou (para log)
     * @return array{texto:string, tokens_in:int, tokens_out:int, cache_hit:bool}
     */
    public function invocar(
        string $systemPrompt,
        string $userMessage,
        array  $history    = [],
        string $tipoChamada = 'chat',
        ?int   $versaoId   = null,
        ?int   $usuarioId  = null,
    ): array {
        if (!$this->enabled) {
            return $this->respostaDesabilitada();
        }

        $isNova = str_starts_with($this->modelId, 'amazon.nova');

        // Monta mensagens no formato correto para cada modelo
        $messages = [];
        foreach ($history as $h) {
            $messages[] = [
                'role'    => $h['role'],
                'content' => $isNova ? [['text' => $h['content']]] : $h['content'],
            ];
        }
        $messages[] = [
            'role'    => 'user',
            'content' => $isNova ? [['text' => $userMessage]] : $userMessage,
        ];

        $body = $isNova ? [
            'messages'        => $messages,
            'system'          => [['text' => $systemPrompt]],
            'inferenceConfig' => ['maxTokens' => $this->maxTokens, 'temperature' => $this->temperature],
        ] : [
            'anthropic_version' => 'bedrock-2023-05-31',
            'max_tokens'        => $this->maxTokens,
            'temperature'       => $this->temperature,
            'system'            => $systemPrompt,
            'messages'          => $messages,
        ];

        $promptHash = hash('sha256', $systemPrompt . json_encode($messages));

        // Tenta cache Redis (apenas para chamadas sem histórico longo — contexto não varia)
        if ($tipoChamada !== 'chat' && count($history) === 0) {
            $cached = $this->lerCache($promptHash);
            if ($cached !== null) {
                $this->registrarLog($versaoId, $tipoChamada, 0, 0, 0, 'sucesso', null, $promptHash, true);
                return ['texto' => $cached, 'tokens_in' => 0, 'tokens_out' => 0, 'cache_hit' => true];
            }
        }

        $inicio = hrtime(true);
        try {
            $result = $this->aws->invokeModel([
                'modelId'     => $this->modelId,
                'body'        => json_encode($body),
                'accept'      => 'application/json',
                'contentType' => 'application/json',
            ]);

            $duracaoMs = (int)((hrtime(true) - $inicio) / 1_000_000);
            $resposta  = json_decode($result->get('body')->getContents(), true);

            if ($isNova) {
                $texto     = $resposta['output']['message']['content'][0]['text'] ?? '';
                $tokensIn  = $resposta['usage']['inputTokens']  ?? 0;
                $tokensOut = $resposta['usage']['outputTokens'] ?? 0;
            } else {
                $texto     = $resposta['content'][0]['text'] ?? '';
                $tokensIn  = $resposta['usage']['input_tokens']  ?? 0;
                $tokensOut = $resposta['usage']['output_tokens'] ?? 0;
            }

            if ($tipoChamada !== 'chat' && count($history) === 0) {
                $this->gravarCache($promptHash, $texto);
            }

            $this->registrarLog($versaoId, $tipoChamada, $tokensIn, $tokensOut, $duracaoMs, 'sucesso', null, $promptHash, false);

            return ['texto' => $texto, 'tokens_in' => $tokensIn, 'tokens_out' => $tokensOut, 'cache_hit' => false];

        } catch (AwsException $e) {
            $duracaoMs = (int)((hrtime(true) - $inicio) / 1_000_000);
            $msg = $e->getAwsErrorMessage() ?: $e->getMessage();
            $this->registrarLog($versaoId, $tipoChamada, 0, 0, $duracaoMs, 'erro', $msg, $promptHash, false);
            throw new \RuntimeException("Bedrock error ({$e->getAwsErrorCode()}): {$msg}", 0, $e);
        } catch (\Throwable $e) {
            $duracaoMs = (int)((hrtime(true) - $inicio) / 1_000_000);
            $this->registrarLog($versaoId, $tipoChamada, 0, 0, $duracaoMs, 'erro', $e->getMessage(), $promptHash, false);
            throw $e;
        }
    }

    // =========================================================================
    // Cache Redis
    // =========================================================================

    private function lerCache(string $hash): ?string
    {
        try {
            $val = $this->redis->get("bedrock:{$hash}");
            return $val ?: null;
        } catch (\Throwable) {
            return null;
        }
    }

    private function gravarCache(string $hash, string $texto): void
    {
        try {
            $this->redis->setex("bedrock:{$hash}", $this->cacheTtl, $texto);
        } catch (\Throwable) {
            // Cache failure não é fatal
        }
    }

    // =========================================================================
    // Logging
    // =========================================================================

    private function registrarLog(
        ?int   $versaoId,
        string $tipo,
        int    $tokensIn,
        int    $tokensOut,
        int    $duracaoMs,
        string $status,
        ?string $erro,
        string $promptHash,
        bool   $cacheHit,
    ): void {
        $tabela = str_starts_with($this->modelId, 'amazon.nova') ? self::CUSTOS['amazon.nova'] : self::CUSTOS['anthropic'];
        $custo  = ($tokensIn * $tabela['in']) + ($tokensOut * $tabela['out']);
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO bedrock_logs
                    (versao_id, tipo_chamada, model_id, tokens_entrada, tokens_saida,
                     custo_estimado, duracao_ms, cache_hit, status, erro_mensagem, prompt_hash)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?)'
            );
            $stmt->execute([
                $versaoId, $tipo, $this->modelId, $tokensIn, $tokensOut,
                round($custo, 6), $duracaoMs, $cacheHit ? 1 : 0, $status, $erro, $promptHash,
            ]);
        } catch (\Throwable) {
            // Log failure não interrompe o fluxo
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function respostaDesabilitada(): array
    {
        return [
            'texto'      => json_encode([
                'sugestoes' => [],
                'resumo'    => 'Integração com Amazon Bedrock está desabilitada (BEDROCK_ENABLED=false).',
            ], JSON_UNESCAPED_UNICODE),
            'tokens_in'  => 0,
            'tokens_out' => 0,
            'cache_hit'  => false,
        ];
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}

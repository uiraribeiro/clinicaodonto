<?php
declare(strict_types=1);

namespace App\Services\Bedrock;

use PDO;

/**
 * Gerencia sessões de chat em linguagem natural com o Bedrock.
 * Persiste histórico em bedrock_chat para contexto multi-turno.
 */
final class ChatService
{
    private const MAX_HISTORICO  = 20;
    private const MAX_TOOL_ITERS = 6;

    public function __construct(
        private readonly BedrockClient $client,
        private readonly PromptBuilder $prompts,
        private readonly AgendaTools   $tools,
        private readonly PDO           $pdo,
    ) {}

    /**
     * Envia mensagem do usuário e retorna a resposta do assistente.
     * Quando versaoId é fornecido com modelo Nova, usa tool use para acessar dados reais da agenda.
     *
     * @return array{resposta:string, sessao_id:string, propostas:array}
     */
    public function enviar(string $sessaoId, int $usuarioId, string $mensagem, ?int $versaoId = null): array
    {
        $historico = $this->carregarHistorico($sessaoId);
        $this->salvarMensagem($sessaoId, $versaoId, $usuarioId, 'user', $mensagem);

        $isNova = str_starts_with($_ENV['BEDROCK_MODEL_ID'] ?? '', 'amazon.nova');

        if ($versaoId !== null && $isNova) {
            [$resposta, $propostas] = $this->enviarComTools($historico, $mensagem, $versaoId, $usuarioId);
        } else {
            $userPrompt = $versaoId
                ? $this->prompts->promptContextoChat($versaoId, $mensagem)
                : $mensagem;
            $result    = $this->client->invocar($this->prompts->systemChat(), $userPrompt, $historico, 'chat', $versaoId, $usuarioId);
            $resposta  = $result['texto'];
            $propostas = [];
        }

        $this->salvarMensagem($sessaoId, $versaoId, $usuarioId, 'assistant', $resposta);

        return ['resposta' => $resposta, 'sessao_id' => $sessaoId, 'propostas' => $propostas];
    }

    /**
     * Loop de tool use para Nova: itera chamadas ao modelo até ele parar de usar ferramentas.
     *
     * @return array{0:string, 1:array} [texto_final, lista_propostas]
     */
    private function enviarComTools(array $historico, string $mensagem, int $versaoId, int $usuarioId): array
    {
        $messages = [];
        foreach ($historico as $h) {
            $messages[] = ['role' => $h['role'], 'content' => [['text' => $h['content']]]];
        }
        $messages[] = ['role' => 'user', 'content' => [['text' => $mensagem]]];

        $toolSpecs = $this->tools->getSpecs();
        $propostas  = [];

        for ($i = 0; $i < self::MAX_TOOL_ITERS; $i++) {
            $raw = $this->client->invocarComTools(
                $this->prompts->systemChatComFerramentas(),
                $messages,
                $toolSpecs,
                $versaoId,
                $usuarioId,
            );

            $stopReason    = $raw['stopReason'] ?? 'end_turn';
            $assistContent = $raw['output']['message']['content'] ?? [['text' => '']];

            $messages[] = ['role' => 'assistant', 'content' => $assistContent];

            if ($stopReason !== 'tool_use') {
                $texto = implode('', array_map(fn($b) => $b['text'] ?? '', $assistContent));
                return [trim($texto), $propostas];
            }

            // Executa cada tool use e devolve resultados
            $toolResults = [];
            foreach ($assistContent as $block) {
                if (!isset($block['toolUse'])) {
                    continue;
                }
                $toolUse   = $block['toolUse'];
                $toolUseId = $toolUse['toolUseId'] ?? '';
                $nome      = $toolUse['name']  ?? '';
                $input     = $toolUse['input'] ?? [];

                $resultJson = $this->tools->executar($nome, $input);
                $resultArr  = json_decode($resultJson, true) ?? [];

                if (isset($resultArr['proposta'])) {
                    $propostas[] = $resultArr['proposta'];
                }

                $toolResults[] = [
                    'toolResult' => [
                        'toolUseId' => $toolUseId,
                        'content'   => [['text' => $resultJson]],
                        'status'    => 'success',
                    ],
                ];
            }

            $messages[] = ['role' => 'user', 'content' => $toolResults];
        }

        return ['Limite de chamadas de ferramentas atingido. Tente uma pergunta mais específica.', $propostas];
    }

    public function novoSessaoId(): string
    {
        return bin2hex(random_bytes(16));
    }

    public function carregarHistorico(string $sessaoId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT papel AS role, mensagem AS content
             FROM bedrock_chat
             WHERE sessao_id = ?
             ORDER BY created_at DESC
             LIMIT ' . self::MAX_HISTORICO
        );
        $stmt->execute([$sessaoId]);
        return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function listarSessoes(int $usuarioId, int $limit = 10): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT sessao_id, MAX(created_at) AS ultima_msg,
                    COUNT(*) AS total_msgs,
                    (SELECT mensagem FROM bedrock_chat b2 WHERE b2.sessao_id = b.sessao_id AND b2.papel = "user" ORDER BY created_at ASC LIMIT 1) AS primeira_pergunta
             FROM bedrock_chat b
             WHERE usuario_id = ?
             GROUP BY sessao_id
             ORDER BY ultima_msg DESC
             LIMIT ?'
        );
        $stmt->execute([$usuarioId, $limit]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function salvarMensagem(string $sessaoId, ?int $versaoId, int $usuarioId, string $papel, string $msg): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO bedrock_chat (sessao_id, versao_id, usuario_id, papel, mensagem) VALUES (?,?,?,?,?)'
        );
        $stmt->execute([$sessaoId, $versaoId, $usuarioId, $papel, $msg]);
    }
}

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
    private const MAX_HISTORICO = 20; // mensagens por sessão

    public function __construct(
        private readonly BedrockClient $client,
        private readonly PromptBuilder $prompts,
        private readonly PDO           $pdo,
    ) {}

    /**
     * Envia mensagem do usuário e retorna a resposta do assistente.
     *
     * @param string   $sessaoId  UUID da sessão de chat
     * @param int      $usuarioId
     * @param string   $mensagem  Texto do usuário
     * @param int|null $versaoId  Versão de agenda ativa (opcional, enriquece contexto)
     * @return array{resposta:string, sessao_id:string}
     */
    public function enviar(string $sessaoId, int $usuarioId, string $mensagem, ?int $versaoId = null): array
    {
        $historico = $this->carregarHistorico($sessaoId);
        $this->salvarMensagem($sessaoId, $versaoId, $usuarioId, 'user', $mensagem);

        $userPrompt = $versaoId
            ? $this->prompts->promptContextoChat($versaoId, $mensagem)
            : $mensagem;

        $result = $this->client->invocar(
            $this->prompts->systemChat(),
            $userPrompt,
            $historico,
            'chat',
            $versaoId,
            $usuarioId,
        );

        $resposta = $result['texto'];
        $this->salvarMensagem($sessaoId, $versaoId, $usuarioId, 'assistant', $resposta);

        return ['resposta' => $resposta, 'sessao_id' => $sessaoId];
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
        // Reverter para ordem cronológica
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

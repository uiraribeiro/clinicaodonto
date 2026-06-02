<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\SugestaoRepository;
use App\Services\Bedrock\ChatService;
use App\Services\Bedrock\SugestaoService;
use App\Services\CsrfService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

final class BedrockController
{
    public function __construct(
        private readonly Twig               $twig,
        private readonly SugestaoService    $sugestaoService,
        private readonly ChatService        $chatService,
        private readonly SugestaoRepository $sugestaoRepo,
        private readonly CsrfService        $csrfService,
    ) {}

    // =========================================================================
    // Sugestões de otimização
    // =========================================================================

    public function sugestoes(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $versaoId  = (int)$args['versao_id'];
        $sugestoes = $this->sugestaoRepo->findByVersao($versaoId);
        $pendentes = $this->sugestaoRepo->contarPendentes($versaoId);

        return $this->twig->render($response, 'ia/sugestoes.html.twig', $this->ctx([
            'versao_id' => $versaoId,
            'sugestoes' => $sugestoes,
            'pendentes' => $pendentes,
        ]));
    }

    public function solicitarSugestoes(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $data      = (array)$request->getParsedBody();
        $versaoId  = (int)($data['versao_id'] ?? 0);
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

        if ($versaoId <= 0) {
            $_SESSION['flash_error'] = 'Versão de agenda inválida.';
            return $response->withHeader('Location', '/agenda')->withStatus(302);
        }

        try {
            $resultado = $this->sugestaoService->solicitar($versaoId, $usuarioId);
            $_SESSION['flash_success'] = sprintf(
                'IA gerou %d sugestões (%d válidas%s).',
                $resultado['geradas'],
                $resultado['validas'],
                $resultado['cache_hit'] ? ', resposta do cache' : '',
            );
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Erro ao solicitar sugestões: ' . $e->getMessage();
        }

        return $response->withHeader("Location", "/ia/sugestoes/{$versaoId}")->withStatus(302);
    }

    public function aceitarSugestao(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $id        = (int)$args['id'];
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

        $sug = $this->sugestaoRepo->findById($id);
        if (!$sug) {
            return $response->withStatus(404);
        }

        $this->sugestaoRepo->aceitar($id, $usuarioId);
        $_SESSION['flash_success'] = 'Sugestão aceita. Aplique manualmente conforme indicado.';

        return $response->withHeader("Location", "/ia/sugestoes/{$sug['versao_id']}")->withStatus(302);
    }

    public function rejeitarSugestao(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $id        = (int)$args['id'];
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

        $sug = $this->sugestaoRepo->findById($id);
        if (!$sug) {
            return $response->withStatus(404);
        }

        $this->sugestaoRepo->rejeitar($id, $usuarioId);
        $_SESSION['flash_info'] = 'Sugestão rejeitada.';

        return $response->withHeader("Location", "/ia/sugestoes/{$sug['versao_id']}")->withStatus(302);
    }

    // =========================================================================
    // Chat
    // =========================================================================

    public function chatPage(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $params    = $request->getQueryParams();
        $versaoId  = isset($params['versao_id']) ? (int)$params['versao_id'] : null;
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);
        $sessaoId  = $params['sessao'] ?? null;

        $historico = $sessaoId ? $this->chatService->carregarHistorico($sessaoId) : [];
        $sessoes   = $this->chatService->listarSessoes($usuarioId);

        return $this->twig->render($response, 'ia/chat.html.twig', $this->ctx([
            'versao_id' => $versaoId,
            'sessao_id' => $sessaoId ?? $this->chatService->novoSessaoId(),
            'historico' => $historico,
            'sessoes'   => $sessoes,
        ]));
    }

    /** Endpoint JSON — chamado via fetch() do Alpine.js */
    public function chat(
        ServerRequestInterface $request,
        ResponseInterface $response,
    ): ResponseInterface {
        $data      = (array)$request->getParsedBody();
        $mensagem  = trim($data['mensagem'] ?? '');
        $sessaoId  = trim($data['sessao_id'] ?? '');
        $versaoId  = !empty($data['versao_id']) ? (int)$data['versao_id'] : null;
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

        if (empty($mensagem)) {
            return $this->json($response, ['erro' => 'Mensagem vazia.'], 400);
        }
        if (empty($sessaoId)) {
            $sessaoId = $this->chatService->novoSessaoId();
        }

        try {
            $resultado = $this->chatService->enviar($sessaoId, $usuarioId, $mensagem, $versaoId);
            return $this->json($response, [
                'resposta'  => $resultado['resposta'],
                'sessao_id' => $resultado['sessao_id'],
            ]);
        } catch (\Throwable $e) {
            return $this->json($response, ['erro' => $e->getMessage()], 500);
        }
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function ctx(array $extra = []): array
    {
        return array_merge([
            'active_menu'    => 'ia',
            'csrf_token'     => $this->csrfService->getToken(),
            'usuario_nome'   => $_SESSION['usuario_nome'] ?? '',
            'usuario_perfil' => $_SESSION['usuario_perfil'] ?? '',
        ], $extra);
    }

    private function json(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}

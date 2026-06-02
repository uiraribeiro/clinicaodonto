<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\CsrfService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

class AuthController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly AuthService $authService,
        private readonly CsrfService $csrfService
    ) {}

    public function showLogin(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($this->authService->usuarioLogado()) {
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $flash = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_error']);

        return $this->twig->render($response, 'auth/login.html.twig', [
            'csrf_token' => $this->csrfService->getToken(),
            'flash_error' => $flash,
        ]);
    }

    public function login(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $body  = (array) $request->getParsedBody();
        $email = trim($body['email'] ?? '');
        $senha = $body['senha'] ?? '';
        $ip    = $this->getClientIp($request);

        // Validação básica de formato
        if (empty($email) || empty($senha)) {
            return $this->redirectWithError($response, 'E-mail e senha são obrigatórios.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->redirectWithError($response, 'E-mail inválido.');
        }

        if ($this->authService->login($email, $senha, $ip)) {
            // Limpa token CSRF após login bem-sucedido
            $this->csrfService->regenerateToken();
            return $response->withHeader('Location', '/dashboard')->withStatus(302);
        }

        return $this->redirectWithError($response, 'E-mail ou senha incorretos.');
    }

    public function logout(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $this->authService->logout();
        return $response->withHeader('Location', '/login')->withStatus(302);
    }

    public function forbidden(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->twig->render($response->withStatus(403), 'auth/forbidden.html.twig');
    }

    private function redirectWithError(ResponseInterface $response, string $mensagem): ResponseInterface
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['flash_error'] = $mensagem;
        return $response->withHeader('Location', '/login')->withStatus(302);
    }

    private function getClientIp(ServerRequestInterface $request): string
    {
        $ip = $request->getServerParams()['HTTP_X_FORWARDED_FOR']
            ?? $request->getServerParams()['REMOTE_ADDR']
            ?? '0.0.0.0';
        return explode(',', $ip)[0];
    }
}

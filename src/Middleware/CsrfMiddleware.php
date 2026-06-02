<?php
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Valida CSRF token em requisições POST, PUT, PATCH, DELETE.
 * O token é gerado pelo CsrfService e embutido em cada formulário.
 */
class CsrfMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $method = strtoupper($request->getMethod());

        if (!in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $handler->handle($request);
        }

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $body  = (array) $request->getParsedBody();
        $token = $body['_csrf_token'] ?? '';

        if (!$this->isValidToken($token)) {
            $response = new Response();
            // Requisições AJAX recebem JSON; navegador recebe redirect
            if ($this->isAjax($request)) {
                $response->getBody()->write(json_encode(['error' => 'Token CSRF inválido ou expirado.']));
                return $response->withStatus(419)->withHeader('Content-Type', 'application/json');
            }
            $_SESSION['flash_error'] = 'Token de segurança inválido. Por favor, tente novamente.';
            return $response->withHeader('Location', $_SERVER['HTTP_REFERER'] ?? '/')->withStatus(302);
        }

        return $handler->handle($request);
    }

    private function isValidToken(string $token): bool
    {
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        if (empty($sessionToken) || empty($token)) {
            return false;
        }
        return hash_equals($sessionToken, $token);
    }

    private function isAjax(ServerRequestInterface $request): bool
    {
        return $request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest'
            || str_contains($request->getHeaderLine('Accept'), 'application/json');
    }
}

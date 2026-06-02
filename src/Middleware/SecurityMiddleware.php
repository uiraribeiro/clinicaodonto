<?php
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Aplica hardening de sessão e cabeçalhos de segurança em nível PHP.
 * Complementa o que o Nginx já faz — garante proteção mesmo em ambientes sem proxy.
 */
class SecurityMiddleware implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $isProduction = ($_ENV['APP_ENV'] ?? 'development') === 'production';
        $isHttps      = $this->isHttps($request);

        // Configura cookie de sessão antes de qualquer session_start()
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => (int)($_ENV['SESSION_LIFETIME'] ?? 7200),
                'path'     => '/',
                'domain'   => '',
                'secure'   => $isProduction && $isHttps,
                'httponly' => true,
                'samesite' => 'Strict',
            ]);
            $sessionName = $_ENV['SESSION_NAME'] ?? 'odonto_sess';
            session_name($sessionName);
        }

        $response = $handler->handle($request);

        // HSTS — só em produção com HTTPS
        if ($isProduction && $isHttps) {
            $response = $response->withHeader(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains'
            );
        }

        // Remove header que expõe tecnologia (belt-and-suspenders além do expose_php=Off)
        header_remove('X-Powered-By');

        return $response;
    }

    private function isHttps(ServerRequestInterface $request): bool
    {
        // Detecta HTTPS atrás de proxy reverso (Nginx, ALB, CloudFront)
        $proto = $request->getHeaderLine('X-Forwarded-Proto');
        if ($proto === 'https') {
            return true;
        }
        $scheme = $request->getUri()->getScheme();
        return $scheme === 'https';
    }
}

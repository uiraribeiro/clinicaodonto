<?php
declare(strict_types=1);

namespace App\Middleware;

use Predis\Client as Redis;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Bloqueia IP após N tentativas de login em janela de tempo configurável.
 */
class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Redis $redis) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $ip       = $this->getClientIp($request);
        $key      = "rate_limit:login:{$ip}";
        $maxAttempts = (int) ($_ENV['AUTH_MAX_ATTEMPTS'] ?? 5);
        $lockoutSec  = (int) ($_ENV['AUTH_LOCKOUT_MINUTES'] ?? 15) * 60;

        $attempts = (int) ($this->redis->get($key) ?? 0);

        if ($attempts >= $maxAttempts) {
            $ttl      = $this->redis->ttl($key);
            $response = new Response();
            if ($request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest') {
                $response->getBody()->write(json_encode([
                    'error' => "IP bloqueado. Tente novamente em {$ttl} segundos.",
                ]));
                return $response->withStatus(429)->withHeader('Content-Type', 'application/json');
            }
            session_start();
            $_SESSION['flash_error'] = "Muitas tentativas de login. Aguarde " . ceil($ttl / 60) . " minuto(s).";
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        // Incrementa contador na Redis com expiração
        $this->redis->incr($key);
        if ($attempts === 0) {
            $this->redis->expire($key, $lockoutSec);
        }

        return $handler->handle($request);
    }

    private function getClientIp(ServerRequestInterface $request): string
    {
        // Considera proxy reverso (Nginx)
        $ip = $request->getServerParams()['HTTP_X_FORWARDED_FOR']
            ?? $request->getServerParams()['REMOTE_ADDR']
            ?? '0.0.0.0';
        return explode(',', $ip)[0];
    }
}

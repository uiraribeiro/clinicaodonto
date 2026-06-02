<?php
declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

class PermissionMiddleware implements MiddlewareInterface
{
    /** @param string[] $allowedProfiles */
    public function __construct(private readonly array $allowedProfiles) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $userProfile = $_SESSION['usuario_perfil'] ?? '';

        if (!in_array($userProfile, $this->allowedProfiles, true)) {
            $response = new Response();
            if ($request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest') {
                $response->getBody()->write(json_encode(['error' => 'Acesso negado.']));
                return $response->withStatus(403)->withHeader('Content-Type', 'application/json');
            }
            return $response->withHeader('Location', '/acesso-negado')->withStatus(302);
        }

        return $handler->handle($request);
    }
}

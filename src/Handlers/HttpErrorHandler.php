<?php
declare(strict_types=1);

namespace App\Handlers;

use Psr\Http\Message\ResponseInterface;
use Slim\Exception\HttpForbiddenException;
use Slim\Exception\HttpNotFoundException;
use Slim\Exception\HttpUnauthorizedException;
use Slim\Handlers\ErrorHandler;
use Slim\Views\Twig;
use Throwable;

/**
 * Substitui o ErrorHandler padrão do Slim para renderizar páginas de erro amigáveis.
 * Em produção nunca expõe stack trace ou mensagem interna.
 */
class HttpErrorHandler extends ErrorHandler
{
    private ?Twig $twig = null;

    public function setTwig(Twig $twig): void
    {
        $this->twig = $twig;
    }

    protected function respond(): ResponseInterface
    {
        $exception  = $this->exception;
        $statusCode = $this->determineStatusCode();
        $debug      = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);

        // Requisições que esperam JSON recebem JSON
        $accept = $this->request->getHeaderLine('Accept');
        if (str_contains($accept, 'application/json') || $this->isAjaxRequest()) {
            return $this->respondJson($exception, $statusCode, $debug);
        }

        // Tenta renderizar template Twig amigável
        if ($this->twig !== null) {
            try {
                return $this->respondTwig($exception, $statusCode, $debug);
            } catch (Throwable) {
                // Fallback para HTML simples se Twig falhar
            }
        }

        return $this->respondHtmlSimple($statusCode);
    }

    private function respondJson(Throwable $e, int $status, bool $debug): ResponseInterface
    {
        $payload = ['error' => $this->friendlyMessage($status)];
        if ($debug) {
            $payload['exception'] = $e->getMessage();
            $payload['file']      = $e->getFile() . ':' . $e->getLine();
        }

        $response = $this->responseFactory->createResponse($status);
        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function respondTwig(Throwable $e, int $status, bool $debug): ResponseInterface
    {
        $template = match ($status) {
            403 => 'errors/403.html.twig',
            404 => 'errors/404.html.twig',
            default => 'errors/500.html.twig',
        };

        $ctx = [
            'status'    => $status,
            'mensagem'  => $this->friendlyMessage($status),
            'app_name'  => $_ENV['APP_NAME'] ?? 'Odonto Scheduler',
        ];

        if ($debug && $status >= 500) {
            $ctx['debug_mensagem'] = $e->getMessage();
            $ctx['debug_arquivo']  = $e->getFile() . ':' . $e->getLine();
            $ctx['debug_trace']    = $e->getTraceAsString();
        }

        $response = $this->responseFactory->createResponse($status);
        return $this->twig->render($response, $template, $ctx);
    }

    private function respondHtmlSimple(int $status): ResponseInterface
    {
        $msg  = htmlspecialchars($this->friendlyMessage($status), ENT_QUOTES, 'UTF-8');
        $html = "<!doctype html><html lang='pt-BR'><head><meta charset='utf-8'><title>Erro {$status}</title></head>"
              . "<body style='font-family:sans-serif;max-width:500px;margin:80px auto;text-align:center'>"
              . "<h1>{$status}</h1><p>{$msg}</p><a href='/'>Voltar ao início</a></body></html>";

        $response = $this->responseFactory->createResponse($status);
        $response->getBody()->write($html);
        return $response->withHeader('Content-Type', 'text/html; charset=UTF-8');
    }

    private function friendlyMessage(int $status): string
    {
        return match ($status) {
            400 => 'Requisição inválida.',
            401 => 'Autenticação necessária.',
            403 => 'Acesso não autorizado.',
            404 => 'Página não encontrada.',
            405 => 'Método não permitido.',
            419 => 'Token de segurança expirado. Recarregue a página.',
            429 => 'Muitas requisições. Aguarde alguns minutos.',
            default => 'Ocorreu um erro interno. Nossa equipe foi notificada.',
        };
    }

    private function determineStatusCode(): int
    {
        $e = $this->exception;
        return match (true) {
            $e instanceof HttpNotFoundException     => 404,
            $e instanceof HttpForbiddenException    => 403,
            $e instanceof HttpUnauthorizedException => 401,
            method_exists($e, 'getCode') && $e->getCode() >= 400 && $e->getCode() < 600 => $e->getCode(),
            default => 500,
        };
    }

    private function isAjaxRequest(): bool
    {
        return $this->request->getHeaderLine('X-Requested-With') === 'XMLHttpRequest';
    }
}

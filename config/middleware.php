<?php
declare(strict_types=1);

use App\Handlers\HttpErrorHandler;
use App\Middleware\SecurityMiddleware;
use Slim\Views\Twig;
use Slim\Views\TwigMiddleware;

$debug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);

// ── Error handler com páginas amigáveis ───────────────────────────────────
$errorMiddleware = $app->addErrorMiddleware($debug, true, true);

$errorHandler = new HttpErrorHandler(
    $app->getCallableResolver(),
    $app->getResponseFactory(),
);

// Injeta Twig no handler assim que o container estiver disponível
// (o container resolve Twig::class configurado em config/container.php)
try {
    $twig = $app->getContainer()->get(Twig::class);
    $errorHandler->setTwig($twig);
} catch (Throwable) {
    // Twig não disponível ainda — fallback para HTML simples
}

$errorMiddleware->setDefaultErrorHandler($errorHandler);

// ── Body parsing (JSON e form-data) — antes do routing ───────────────────
$app->addBodyParsingMiddleware();

// ── Roteamento Slim ───────────────────────────────────────────────────────
$app->addRoutingMiddleware();

// ── Twig ──────────────────────────────────────────────────────────────────
$app->add(TwigMiddleware::createFromContainer($app, Twig::class));

// ── Security: hardening de sessão e cabeçalhos ────────────────────────────
$app->add(SecurityMiddleware::class);

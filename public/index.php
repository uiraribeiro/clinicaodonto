<?php
declare(strict_types=1);

// Impede acesso direto a arquivos de configuração
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . preg_replace('#(\?.*)$#', '', $_SERVER['REQUEST_URI']))) {
    return false;
}

define('BASE_PATH', dirname(__DIR__));

require BASE_PATH . '/vendor/autoload.php';

// Carrega variáveis de ambiente
$dotenv = Dotenv\Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();

// Configura timezone
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'America/Sao_Paulo');

// Configura tratamento de erros
$debug = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
if (!$debug) {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_STRICT);
}

// Cria e configura o container DI
$container = require BASE_PATH . '/config/container.php';

// Cria a aplicação Slim
$app = \DI\Bridge\Slim\Bridge::create($container);

// Registra middlewares globais
require BASE_PATH . '/config/middleware.php';

// Registra rotas
require BASE_PATH . '/config/routes.php';

$app->run();

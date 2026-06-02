<?php
declare(strict_types=1);

use DI\ContainerBuilder;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Slim\Views\Twig;

$builder = new ContainerBuilder();

$builder->addDefinitions([

    // PDO — conexão ao MySQL via prepared statements
    PDO::class => function (): PDO {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $_ENV['DB_HOST'],
            $_ENV['DB_PORT'] ?? '3306',
            $_ENV['DB_NAME']
        );
        $pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASSWORD'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false, // segurança: prepared statements reais
        ]);
        return $pdo;
    },

    // Redis — cache e rate limiting
    \Predis\Client::class => function (): \Predis\Client {
        return new \Predis\Client([
            'scheme'   => 'tcp',
            'host'     => $_ENV['REDIS_HOST'] ?? 'redis',
            'port'     => (int) ($_ENV['REDIS_PORT'] ?? 6379),
            'database' => (int) ($_ENV['REDIS_DB'] ?? 0),
            'password' => $_ENV['REDIS_PASSWORD'] ?: null,
        ]);
    },

    // Twig — template engine com auto-escape XSS
    Twig::class => function (ContainerInterface $c): Twig {
        $debug    = filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $cachePath = $debug ? false : BASE_PATH . '/storage/cache';

        $twig = Twig::create(BASE_PATH . '/templates', [
            'cache'       => $cachePath,
            'auto_reload' => $debug,
            'autoescape'  => 'html', // proteção XSS automática com {{ var }}
            'debug'       => $debug,
        ]);

        // Variáveis globais disponíveis em todos os templates
        $twig->getEnvironment()->addGlobal('app_name', $_ENV['APP_NAME'] ?? 'Odonto Scheduler');
        $twig->getEnvironment()->addGlobal('app_url', rtrim($_ENV['APP_URL'] ?? '', '/'));

        // Extensões Twig úteis
        if ($debug) {
            $twig->getEnvironment()->addExtension(new \Twig\Extension\DebugExtension());
        }

        return $twig;
    },

    // Logger principal
    Logger::class => function (): Logger {
        $logger  = new Logger('odonto');
        $logPath = BASE_PATH . '/' . ($_ENV['LOG_PATH'] ?? 'storage/logs/app.log');
        $level   = $_ENV['LOG_LEVEL'] ?? 'debug';

        $levelMap = [
            'debug'   => Logger::DEBUG,
            'info'    => Logger::INFO,
            'warning' => Logger::WARNING,
            'error'   => Logger::ERROR,
        ];

        $logger->pushHandler(new StreamHandler($logPath, $levelMap[$level] ?? Logger::DEBUG));
        return $logger;
    },

]);

return $builder->build();

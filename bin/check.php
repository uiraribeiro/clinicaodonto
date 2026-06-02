#!/usr/bin/env php
<?php
/**
 * Verificação de requisitos do sistema.
 * Uso: php bin/check.php
 */
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(BASE_PATH);
$dotenv->safeLoad();

$ok    = 0;
$fail  = 0;
$warns = 0;

function pass(string $msg): void   { global $ok;    $ok++;    echo "\033[32m  ✓\033[0m {$msg}\n"; }
function fail(string $msg): void   { global $fail;  $fail++;  echo "\033[31m  ✗\033[0m {$msg}\n"; }
function warn(string $msg): void   { global $warns; $warns++; echo "\033[33m  !\033[0m {$msg}\n"; }
function section(string $t): void  { echo "\n\033[1m{$t}\033[0m\n"; }

echo "\033[1mOdonto Scheduler — Verificação de requisitos\033[0m\n";
echo str_repeat('─', 50) . "\n";

// ── PHP ────────────────────────────────────────────────────────────────────
section('PHP');
PHP_MAJOR_VERSION >= 8 && PHP_MINOR_VERSION >= 2
    ? pass('PHP ' . PHP_VERSION . ' (>= 8.2 requerido)')
    : fail('PHP ' . PHP_VERSION . ' — requer 8.2 ou superior');

foreach (['pdo_mysql','mbstring','gd','zip','opcache','redis','json','openssl'] as $ext) {
    extension_loaded($ext) ? pass("ext/{$ext}") : fail("ext/{$ext} não instalada");
}

// ── .env ──────────────────────────────────────────────────────────────────
section('.env');
file_exists(BASE_PATH . '/.env') ? pass('.env encontrado') : fail('.env não encontrado — copie .env.example');

$requiredEnv = ['APP_SECRET','DB_HOST','DB_NAME','DB_USER','DB_PASSWORD','AWS_BEDROCK_REGION'];
foreach ($requiredEnv as $var) {
    $val = $_ENV[$var] ?? '';
    if (empty($val)) {
        fail("{$var} não definida");
    } elseif (str_contains($val, 'TROQUE_AQUI') || str_contains($val, 'COLOQUE_SUA')) {
        warn("{$var} ainda está com valor de exemplo — altere antes de usar em produção");
    } else {
        pass("{$var} definida");
    }
}

$secret = $_ENV['APP_SECRET'] ?? '';
strlen($secret) >= 32
    ? pass('APP_SECRET com comprimento adequado (' . strlen($secret) . ' chars)')
    : fail('APP_SECRET muito curta — use pelo menos 32 caracteres aleatórios');

// ── Banco de Dados ────────────────────────────────────────────────────────
section('Banco de Dados (MySQL)');
try {
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $_ENV['DB_HOST']     ?? 'mysql',
        $_ENV['DB_PORT']     ?? '3306',
        $_ENV['DB_NAME']     ?? 'odonto_scheduler'
    );
    $pdo = new PDO($dsn, $_ENV['DB_USER'] ?? '', $_ENV['DB_PASSWORD'] ?? '', [
        PDO::ATTR_ERRMODE  => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_TIMEOUT  => 3,
    ]);
    pass('Conexão ao MySQL OK');

    $ver = $pdo->query('SELECT VERSION()')->fetchColumn();
    preg_match('/^(\d+)\.(\d+)/', $ver, $m);
    (int)$m[1] >= 8
        ? pass("MySQL {$ver} (>= 8.0 requerido)")
        : fail("MySQL {$ver} — requer 8.0 ou superior");

    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    count($tables) >= 10
        ? pass(count($tables) . ' tabelas encontradas (migrations aplicadas)')
        : warn('Apenas ' . count($tables) . ' tabelas — rode: php bin/migrate.php');

    $charset = $pdo->query("SELECT @@character_set_database")->fetchColumn();
    $charset === 'utf8mb4'
        ? pass('Charset utf8mb4 OK')
        : warn("Charset do banco: {$charset} (esperado utf8mb4)");

} catch (Throwable $e) {
    fail('Não foi possível conectar ao MySQL: ' . $e->getMessage());
}

// ── Redis ─────────────────────────────────────────────────────────────────
section('Redis');
try {
    $redis = new Predis\Client([
        'scheme'   => 'tcp',
        'host'     => $_ENV['REDIS_HOST'] ?? 'redis',
        'port'     => (int)($_ENV['REDIS_PORT'] ?? 6379),
        'password' => $_ENV['REDIS_PASSWORD'] ?: null,
        'timeout'  => 2,
    ]);
    $redis->ping();
    pass('Conexão ao Redis OK');
} catch (Throwable $e) {
    warn('Redis indisponível: ' . $e->getMessage() . ' (rate limiting e cache desativados)');
}

// ── Diretórios e permissões ───────────────────────────────────────────────
section('Diretórios');
$dirs = [
    BASE_PATH . '/storage/logs',
    BASE_PATH . '/storage/cache',
    BASE_PATH . '/storage/exports',
];
foreach ($dirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
        pass("Criado: " . str_replace(BASE_PATH . '/', '', $dir));
    }
    is_writable($dir)
        ? pass("Escrita em " . str_replace(BASE_PATH . '/', '', $dir))
        : fail("Sem permissão de escrita em " . str_replace(BASE_PATH . '/', '', $dir) . " — chown/chmod necessário");
}

$publicDir = BASE_PATH . '/public';
is_dir($publicDir)
    ? pass('public/ encontrado (webroot)')
    : fail('public/ não encontrado');

// ── Configurações de produção ─────────────────────────────────────────────
section('Produção');
$env = $_ENV['APP_ENV'] ?? 'development';
$debug = filter_var($_ENV['APP_DEBUG'] ?? true, FILTER_VALIDATE_BOOLEAN);

if ($env === 'production') {
    $debug
        ? fail('APP_DEBUG=true em produção — defina como false')
        : pass('APP_DEBUG=false (correto para produção)');
    filter_var($_ENV['BEDROCK_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN)
        ? pass('BEDROCK_ENABLED=true')
        : warn('BEDROCK_ENABLED=false — IA não funcionará');
} else {
    warn("APP_ENV={$env} — este não é ambiente de produção");
}

ini_get('expose_php') === '' || ini_get('expose_php') === '0'
    ? pass('expose_php desabilitado')
    : warn('expose_php habilitado — desative em php.ini (expose_php = Off)');

ini_get('display_errors') === '' || ini_get('display_errors') === '0'
    ? pass('display_errors desabilitado')
    : ($env === 'production' ? fail('display_errors habilitado em produção!') : warn('display_errors habilitado (OK em desenvolvimento)'));

// ── Resumo ─────────────────────────────────────────────────────────────────
echo "\n" . str_repeat('─', 50) . "\n";
$total = $ok + $fail + $warns;
echo "\033[1mResumo:\033[0m {$total} verificações — ";
echo "\033[32m{$ok} OK\033[0m, \033[31m{$fail} falha(s)\033[0m, \033[33m{$warns} aviso(s)\033[0m\n\n";

exit($fail > 0 ? 1 : 0);

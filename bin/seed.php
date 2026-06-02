#!/usr/bin/env php
<?php
/**
 * Insere dados iniciais (perfis de acesso e dados de exemplo do semestre 2026.1).
 * Idempotente: usa INSERT IGNORE / ON DUPLICATE KEY — seguro para rodar mais de uma vez.
 *
 * Uso:
 *   php bin/seed.php              # aplica apenas se tabelas estiverem vazias
 *   php bin/seed.php --force      # reaplicar mesmo com dados existentes
 *   php bin/seed.php --example    # incluir dados de exemplo (turmas, disciplinas...)
 */
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

require_once BASE_PATH . '/vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(BASE_PATH);
$dotenv->load();

$force   = in_array('--force', $argv, true);
$example = in_array('--example', $argv, true);

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $_ENV['DB_HOST'],
    $_ENV['DB_PORT'] ?? '3306',
    $_ENV['DB_NAME']
);

try {
    $pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASSWORD'], [
        PDO::ATTR_ERRMODE              => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE   => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
    ]);
} catch (PDOException $e) {
    echo "[ERRO] Não foi possível conectar ao banco: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

// Verifica se migrations foram aplicadas
try {
    $pdo->query("SELECT 1 FROM perfis LIMIT 1");
} catch (PDOException $e) {
    echo "[ERRO] Tabelas não encontradas. Rode primeiro: php bin/migrate.php" . PHP_EOL;
    exit(1);
}

// Verifica se já existem dados
$totalPerfis = (int) $pdo->query("SELECT COUNT(*) FROM perfis")->fetchColumn();
$totalUsers  = (int) $pdo->query("SELECT COUNT(*) FROM usuarios")->fetchColumn();

if ($totalPerfis > 0 && !$force) {
    echo "[INFO] Banco já possui dados ({$totalPerfis} perfis, {$totalUsers} usuário(s))." . PHP_EOL;
    echo "       Use --force para reaplicar os seeds." . PHP_EOL;
    exit(0);
}

echo "[SEED] Aplicando dados iniciais..." . PHP_EOL;

$seeds = [BASE_PATH . '/database/migrations/002_seed_perfis.sql'];
if ($example) {
    $seeds[] = BASE_PATH . '/database/migrations/003_seed_exemplo.sql';
    echo "[INFO] Incluindo dados de exemplo (--example)." . PHP_EOL;
}

foreach ($seeds as $file) {
    if (!file_exists($file)) {
        echo "[AVISO] Arquivo não encontrado: " . basename($file) . PHP_EOL;
        continue;
    }
    $sql = file_get_contents($file);
    if ($sql === false) {
        echo "[ERRO] Não foi possível ler " . basename($file) . PHP_EOL;
        exit(1);
    }
    try {
        $pdo->exec($sql);
        echo "[OK]   " . basename($file) . " aplicado." . PHP_EOL;
    } catch (PDOException $e) {
        echo "[ERRO] Falha em " . basename($file) . ": " . $e->getMessage() . PHP_EOL;
        exit(1);
    }
}

$admin = $pdo->query("SELECT email FROM usuarios WHERE email = 'admin@odonto.local'")->fetch();
if ($admin) {
    echo PHP_EOL;
    echo "Acesso inicial:" . PHP_EOL;
    echo "  URL:   http://localhost:8080" . PHP_EOL;
    echo "  Login: admin@odonto.local" . PHP_EOL;
    echo "  Senha: Admin@1234" . PHP_EOL;
    echo PHP_EOL;
    echo "[AVISO] Troque a senha do admin após o primeiro acesso!" . PHP_EOL;
}

echo PHP_EOL . "Seeds aplicados com sucesso." . PHP_EOL;

#!/usr/bin/env php
<?php
/**
 * Script de migrations incrementais.
 * Uso: php bin/migrate.php [--dry-run]
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$dryRun = in_array('--dry-run', $argv, true);

$dsn = sprintf(
    'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
    $_ENV['DB_HOST'],
    $_ENV['DB_PORT'] ?? '3306',
    $_ENV['DB_NAME']
);

try {
    $pdo = new PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASSWORD'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
    ]);
} catch (PDOException $e) {
    echo "[ERRO] Não foi possível conectar ao banco: " . $e->getMessage() . PHP_EOL;
    exit(1);
}

// Garante que a tabela de migrations existe
$pdo->exec("
    CREATE TABLE IF NOT EXISTS `migrations` (
        `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        `filename`   VARCHAR(255) NOT NULL UNIQUE,
        `applied_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
");

// Lista migrations já aplicadas
$applied = $pdo->query("SELECT `filename` FROM `migrations`")
               ->fetchAll(PDO::FETCH_COLUMN);

// Busca arquivos de migration em ordem numérica
$migrationsDir = __DIR__ . '/../database/migrations';
$files = glob($migrationsDir . '/[0-9][0-9][0-9]_*.sql');
sort($files);

$count = 0;
foreach ($files as $file) {
    $filename = basename($file);

    if (in_array($filename, $applied, true)) {
        echo "[OK]   $filename (já aplicada)" . PHP_EOL;
        continue;
    }

    echo "[EXEC] $filename" . PHP_EOL;

    if ($dryRun) {
        echo "       (dry-run: não executada)" . PHP_EOL;
        continue;
    }

    $sql = file_get_contents($file);
    if ($sql === false) {
        echo "[ERRO] Não foi possível ler $filename" . PHP_EOL;
        exit(1);
    }

    try {
        $pdo->exec($sql);
        echo "       Aplicada com sucesso." . PHP_EOL;
        $count++;
    } catch (PDOException $e) {
        echo "[ERRO] Falha em $filename: " . $e->getMessage() . PHP_EOL;
        exit(1);
    }
}

if ($count === 0) {
    echo PHP_EOL . "Nenhuma migration nova para aplicar." . PHP_EOL;
} else {
    echo PHP_EOL . "$count migration(s) aplicada(s) com sucesso." . PHP_EOL;
}

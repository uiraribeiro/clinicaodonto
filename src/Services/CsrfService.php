<?php
declare(strict_types=1);

namespace App\Services;

/**
 * Gera e valida tokens CSRF.
 * Token é armazenado na sessão PHP e embutido em todos os formulários.
 */
class CsrfService
{
    public function getToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = $this->generateToken();
        }

        return $_SESSION['csrf_token'];
    }

    public function regenerateToken(): string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['csrf_token'] = $this->generateToken();
        return $_SESSION['csrf_token'];
    }

    public function validate(string $token): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $sessionToken = $_SESSION['csrf_token'] ?? '';
        return !empty($sessionToken) && hash_equals($sessionToken, $token);
    }

    private function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }
}

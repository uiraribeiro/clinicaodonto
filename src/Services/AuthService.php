<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\UsuarioRepository;
use Monolog\Logger;
use PDO;

/**
 * Gerencia autenticação: login, logout, sessão segura, log de tentativas.
 */
class AuthService
{
    public function __construct(
        private readonly UsuarioRepository $usuarioRepo,
        private readonly PDO $pdo,
        private readonly Logger $logger
    ) {}

    /**
     * Tenta autenticar o usuário.
     * Retorna true em caso de sucesso, false caso contrário.
     * Registra tentativa na tabela login_tentativas.
     */
    public function login(string $email, string $senha, string $ip): bool
    {
        $email = strtolower(trim($email));

        $usuario = $this->usuarioRepo->findByEmail($email);

        $sucesso = false;

        if ($usuario && $usuario['ativo'] && password_verify($senha, $usuario['senha_hash'])) {
            $sucesso = true;
            $this->iniciarSessao($usuario);
            $this->usuarioRepo->updateUltimoLogin((int) $usuario['id']);
        }

        $this->registrarTentativa($email, $ip, $sucesso);

        if ($sucesso) {
            $this->logger->info('Login bem-sucedido', ['email' => $email, 'ip' => $ip]);
        } else {
            $this->logger->warning('Tentativa de login inválida', ['email' => $email, 'ip' => $ip]);
        }

        return $sucesso;
    }

    public function logout(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $usuarioId = $_SESSION['usuario_id'] ?? null;

        // Destrói dados da sessão antes de invalidar
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params['path'], $params['domain'],
                $params['secure'], $params['httponly']
            );
        }

        session_destroy();

        if ($usuarioId) {
            $this->logger->info('Logout', ['usuario_id' => $usuarioId]);
        }
    }

    public function usuarioLogado(): bool
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        return !empty($_SESSION['usuario_id']);
    }

    public function getUsuarioId(): ?int
    {
        return isset($_SESSION['usuario_id']) ? (int) $_SESSION['usuario_id'] : null;
    }

    public function getUsuarioPerfil(): string
    {
        return $_SESSION['usuario_perfil'] ?? '';
    }

    private function iniciarSessao(array $usuario): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Configura cookie seguro
        session_set_cookie_params([
            'lifetime' => (int) ($_ENV['SESSION_LIFETIME'] ?? 7200),
            'path'     => '/',
            'domain'   => '',
            'secure'   => filter_var($_ENV['APP_ENV'] ?? 'development', FILTER_VALIDATE_BOOLEAN)
                          ? true
                          : (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);

        session_regenerate_id(true);

        $_SESSION['usuario_id']     = $usuario['id'];
        $_SESSION['usuario_nome']   = $usuario['nome'];
        $_SESSION['usuario_email']  = $usuario['email'];
        $_SESSION['usuario_perfil'] = $usuario['perfil_slug'];
        $_SESSION['last_regeneration'] = time();
    }

    private function registrarTentativa(string $email, string $ip, bool $sucesso): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO login_tentativas (email, ip, sucesso) VALUES (:email, :ip, :sucesso)'
        );
        $stmt->execute([
            ':email'   => $email,
            ':ip'      => $ip,
            ':sucesso' => $sucesso ? 1 : 0,
        ]);
    }
}

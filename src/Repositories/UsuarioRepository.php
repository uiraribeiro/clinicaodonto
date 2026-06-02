<?php
declare(strict_types=1);

namespace App\Repositories;

use PDO;

class UsuarioRepository
{
    public function __construct(private readonly PDO $pdo) {}

    public function findByEmail(string $email): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.*, p.slug AS perfil_slug
             FROM usuarios u
             JOIN perfis p ON p.id = u.perfil_id
             WHERE u.email = :email
             LIMIT 1'
        );
        $stmt->execute([':email' => strtolower($email)]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.*, p.slug AS perfil_slug
             FROM usuarios u
             JOIN perfis p ON p.id = u.perfil_id
             WHERE u.id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public function findAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT u.id, u.nome, u.email, u.ativo, u.ultimo_login, p.nome AS perfil_nome, p.slug AS perfil_slug
             FROM usuarios u
             JOIN perfis p ON p.id = u.perfil_id
             ORDER BY u.nome'
        );
        return $stmt->fetchAll();
    }

    public function create(array $data): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO usuarios (perfil_id, nome, email, senha_hash, ativo)
             VALUES (:perfil_id, :nome, :email, :senha_hash, :ativo)'
        );
        $stmt->execute([
            ':perfil_id'  => $data['perfil_id'],
            ':nome'       => $data['nome'],
            ':email'      => strtolower($data['email']),
            ':senha_hash' => password_hash($data['senha'], PASSWORD_BCRYPT, ['cost' => 12]),
            ':ativo'      => 1,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $fields = [];
        $params = [':id' => $id];

        if (isset($data['nome'])) {
            $fields[] = 'nome = :nome';
            $params[':nome'] = $data['nome'];
        }
        if (isset($data['email'])) {
            $fields[] = 'email = :email';
            $params[':email'] = strtolower($data['email']);
        }
        if (isset($data['perfil_id'])) {
            $fields[] = 'perfil_id = :perfil_id';
            $params[':perfil_id'] = $data['perfil_id'];
        }
        if (isset($data['ativo'])) {
            $fields[] = 'ativo = :ativo';
            $params[':ativo'] = $data['ativo'];
        }
        if (isset($data['senha'])) {
            $fields[] = 'senha_hash = :senha_hash';
            $params[':senha_hash'] = password_hash($data['senha'], PASSWORD_BCRYPT, ['cost' => 12]);
        }

        if (empty($fields)) {
            return;
        }

        $sql = 'UPDATE usuarios SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $this->pdo->prepare($sql)->execute($params);
    }

    public function updateUltimoLogin(int $id): void
    {
        $this->pdo->prepare('UPDATE usuarios SET ultimo_login = NOW() WHERE id = :id')
                  ->execute([':id' => $id]);
    }

    public function contarTentativasRecentes(string $email, string $ip, int $minutos): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM login_tentativas
             WHERE (email = :email OR ip = :ip)
               AND sucesso = 0
               AND tentado_em >= DATE_SUB(NOW(), INTERVAL :minutos MINUTE)'
        );
        $stmt->execute([':email' => $email, ':ip' => $ip, ':minutos' => $minutos]);
        return (int) $stmt->fetchColumn();
    }

    public function findAllPerfis(): array
    {
        return $this->pdo->query('SELECT * FROM perfis ORDER BY nome')->fetchAll();
    }
}

<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\UsuarioRepository;
use App\Services\CsrfService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

class UsuarioController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly UsuarioRepository $repo,
        private readonly CsrfService $csrfService
    ) {}

    // ─── Contexto base ───────────────────────────────────────────────────────────

    private function ctx(array $extra = []): array
    {
        return array_merge([
            'csrf_token'     => $this->csrfService->getToken(),
            'usuario_nome'   => $_SESSION['usuario_nome'] ?? '',
            'usuario_perfil' => $_SESSION['usuario_perfil'] ?? '',
        ], $extra);
    }

    // ─── Listagem ─────────────────────────────────────────────────────────────────

    public function index(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $usuarios = $this->repo->findAll();

        $flash_success = $_SESSION['flash_success'] ?? null;
        $flash_error   = $_SESSION['flash_error']   ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        return $this->twig->render($response, 'usuarios/index.html.twig', $this->ctx([
            'active_menu'   => 'usuarios',
            'usuarios'      => $usuarios,
            'flash_success' => $flash_success,
            'flash_error'   => $flash_error,
        ]));
    }

    // ─── Formulário de criação ───────────────────────────────────────────────────

    public function create(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $perfis = $this->repo->findAllPerfis();

        return $this->twig->render($response, 'usuarios/form.html.twig', $this->ctx([
            'active_menu' => 'usuarios',
            'usuario'     => null,
            'perfis'      => $perfis,
            'modo'        => 'criar',
        ]));
    }

    // ─── Persistência (criação) ──────────────────────────────────────────────────

    public function store(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $data   = (array) $request->getParsedBody();
        $perfis = $this->repo->findAllPerfis();
        $errors = [];

        // Validação: nome obrigatório
        $nome = trim($data['nome'] ?? '');
        if ($nome === '') {
            $errors['nome'] = 'O nome é obrigatório.';
        }

        // Validação: e-mail
        $email = trim(strtolower($data['email'] ?? ''));
        if ($email === '') {
            $errors['email'] = 'O e-mail é obrigatório.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Informe um e-mail válido.';
        } elseif ($this->repo->findByEmail($email) !== null) {
            $errors['email'] = 'Este e-mail já está em uso.';
        }

        // Validação: perfil
        $perfilId = (int) ($data['perfil_id'] ?? 0);
        if ($perfilId <= 0) {
            $errors['perfil_id'] = 'Selecione um perfil.';
        }

        // Validação: senha
        $senha = $data['senha'] ?? '';
        if (strlen($senha) < 8) {
            $errors['senha'] = 'A senha deve ter pelo menos 8 caracteres.';
        }

        if (!empty($errors)) {
            return $this->twig->render($response->withStatus(422), 'usuarios/form.html.twig', $this->ctx([
                'active_menu' => 'usuarios',
                'usuario'     => $data,
                'perfis'      => $perfis,
                'errors'      => $errors,
                'modo'        => 'criar',
            ]));
        }

        $this->repo->create([
            'perfil_id' => $perfilId,
            'nome'      => $nome,
            'email'     => $email,
            'senha'     => $senha,
        ]);

        $_SESSION['flash_success'] = 'Usuário criado com sucesso.';
        return $response->withHeader('Location', '/usuarios')->withStatus(302);
    }

    // ─── Detalhe ─────────────────────────────────────────────────────────────────

    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id
    ): ResponseInterface {
        $usuario = $this->repo->findById((int) $id);
        if (!$usuario) {
            return $response->withStatus(404);
        }

        return $this->twig->render($response, 'usuarios/show.html.twig', $this->ctx([
            'active_menu' => 'usuarios',
            'usuario'     => $usuario,
        ]));
    }

    // ─── Formulário de edição ────────────────────────────────────────────────────

    public function edit(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id
    ): ResponseInterface {
        $usuario = $this->repo->findById((int) $id);
        if (!$usuario) {
            return $response->withStatus(404);
        }

        $perfis = $this->repo->findAllPerfis();

        return $this->twig->render($response, 'usuarios/form.html.twig', $this->ctx([
            'active_menu' => 'usuarios',
            'usuario'     => $usuario,
            'perfis'      => $perfis,
            'modo'        => 'editar',
        ]));
    }

    // ─── Persistência (atualização) ──────────────────────────────────────────────

    public function update(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id
    ): ResponseInterface {
        $id      = (int) $id;
        $data    = (array) $request->getParsedBody();
        $perfis  = $this->repo->findAllPerfis();
        $errors  = [];

        $usuario = $this->repo->findById($id);
        if (!$usuario) {
            return $response->withStatus(404);
        }

        // Validação: nome obrigatório
        $nome = trim($data['nome'] ?? '');
        if ($nome === '') {
            $errors['nome'] = 'O nome é obrigatório.';
        }

        // Validação: e-mail
        $email = trim(strtolower($data['email'] ?? ''));
        if ($email === '') {
            $errors['email'] = 'O e-mail é obrigatório.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Informe um e-mail válido.';
        } else {
            // Verificar unicidade ignorando o próprio usuário
            $existente = $this->repo->findByEmail($email);
            if ($existente !== null && (int) $existente['id'] !== $id) {
                $errors['email'] = 'Este e-mail já está em uso por outro usuário.';
            }
        }

        // Validação: perfil
        $perfilId = (int) ($data['perfil_id'] ?? 0);
        if ($perfilId <= 0) {
            $errors['perfil_id'] = 'Selecione um perfil.';
        }

        // Validação: senha (opcional no update — só valida se preenchida)
        $senha = $data['senha'] ?? '';
        if ($senha !== '' && strlen($senha) < 8) {
            $errors['senha'] = 'A senha deve ter pelo menos 8 caracteres.';
        }

        if (!empty($errors)) {
            $data['id'] = $id;
            return $this->twig->render($response->withStatus(422), 'usuarios/form.html.twig', $this->ctx([
                'active_menu' => 'usuarios',
                'usuario'     => $data,
                'perfis'      => $perfis,
                'errors'      => $errors,
                'modo'        => 'editar',
            ]));
        }

        $updateData = [
            'nome'      => $nome,
            'email'     => $email,
            'perfil_id' => $perfilId,
            'ativo'     => isset($data['ativo']) ? 1 : 0,
        ];

        // Só atualiza senha se foi fornecida
        if ($senha !== '') {
            $updateData['senha'] = $senha;
        }

        $this->repo->update($id, $updateData);

        $_SESSION['flash_success'] = 'Usuário atualizado com sucesso.';
        return $response->withHeader('Location', '/usuarios')->withStatus(302);
    }
}

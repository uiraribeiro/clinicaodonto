<?php
declare(strict_types=1);

namespace App\Controllers\cadastros;

use App\Repositories\DisciplinaRepository;
use App\Services\CsrfService;
use App\Validators\DisciplinaValidator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

class DisciplinaController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly DisciplinaRepository $repo,
        private readonly CsrfService $csrfService
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $disciplinas = $this->repo->findAll();

        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError   = $_SESSION['flash_error']   ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        return $this->twig->render($response, 'cadastros/disciplinas/index.html.twig', [
            'active_menu'    => 'disciplinas',
            'csrf_token'     => $this->csrfService->getToken(),
            'usuario_nome'   => $_SESSION['usuario_nome'] ?? '',
            'usuario_perfil' => $_SESSION['usuario_perfil'] ?? '',
            'disciplinas'    => $disciplinas,
            'flash_success'  => $flashSuccess,
            'flash_error'    => $flashError,
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->twig->render($response, 'cadastros/disciplinas/form.html.twig', [
            'active_menu'    => 'disciplinas',
            'csrf_token'     => $this->csrfService->getToken(),
            'usuario_nome'   => $_SESSION['usuario_nome'] ?? '',
            'usuario_perfil' => $_SESSION['usuario_perfil'] ?? '',
            'disciplina'     => null,
            'modo'           => 'criar',
        ]);
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data   = (array) $request->getParsedBody();
        $errors = DisciplinaValidator::validate($data);

        if (!empty($errors)) {
            return $this->twig->render($response->withStatus(422), 'cadastros/disciplinas/form.html.twig', [
                'active_menu'    => 'disciplinas',
                'csrf_token'     => $this->csrfService->getToken(),
                'usuario_nome'   => $_SESSION['usuario_nome'] ?? '',
                'usuario_perfil' => $_SESSION['usuario_perfil'] ?? '',
                'disciplina'     => $data,
                'errors'         => $errors,
                'modo'           => 'criar',
            ]);
        }

        $id = $this->repo->create($data, (int) ($_SESSION['usuario_id'] ?? 0));
        $_SESSION['flash_success'] = 'Disciplina criada com sucesso.';
        return $response->withHeader('Location', '/cadastros/disciplinas')->withStatus(302);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $disciplina = $this->repo->findById((int) $id);
        if (!$disciplina) {
            return $response->withStatus(404);
        }

        return $this->twig->render($response, 'cadastros/disciplinas/show.html.twig', [
            'active_menu'    => 'disciplinas',
            'csrf_token'     => $this->csrfService->getToken(),
            'usuario_nome'   => $_SESSION['usuario_nome'] ?? '',
            'usuario_perfil' => $_SESSION['usuario_perfil'] ?? '',
            'disciplina'     => $disciplina,
        ]);
    }

    public function edit(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $disciplina = $this->repo->findById((int) $id);
        if (!$disciplina) {
            return $response->withStatus(404);
        }

        return $this->twig->render($response, 'cadastros/disciplinas/form.html.twig', [
            'active_menu'    => 'disciplinas',
            'csrf_token'     => $this->csrfService->getToken(),
            'usuario_nome'   => $_SESSION['usuario_nome'] ?? '',
            'usuario_perfil' => $_SESSION['usuario_perfil'] ?? '',
            'disciplina'     => $disciplina,
            'modo'           => 'editar',
        ]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $id   = (int) $id;
        $data = (array) $request->getParsedBody();

        $disciplina = $this->repo->findById($id);
        if (!$disciplina) {
            return $response->withStatus(404);
        }

        $errors = DisciplinaValidator::validate($data, $id);
        if (!empty($errors)) {
            $data['id'] = $id;
            return $this->twig->render($response->withStatus(422), 'cadastros/disciplinas/form.html.twig', [
                'active_menu'    => 'disciplinas',
                'csrf_token'     => $this->csrfService->getToken(),
                'usuario_nome'   => $_SESSION['usuario_nome'] ?? '',
                'usuario_perfil' => $_SESSION['usuario_perfil'] ?? '',
                'disciplina'     => $data,
                'errors'         => $errors,
                'modo'           => 'editar',
            ]);
        }

        $this->repo->update($id, $data, (int) ($_SESSION['usuario_id'] ?? 0));
        $_SESSION['flash_success'] = 'Disciplina atualizada com sucesso.';
        return $response->withHeader('Location', '/cadastros/disciplinas')->withStatus(302);
    }

    public function destroy(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $id = (int) $id;

        $numTurmas = $this->repo->countTurmas($id);
        if ($numTurmas > 0) {
            $_SESSION['flash_error'] = "Não é possível excluir: esta disciplina possui {$numTurmas} turma(s) vinculada(s). Exclua as turmas primeiro.";
            return $response->withHeader('Location', '/cadastros/disciplinas')->withStatus(302);
        }

        if ($this->repo->hasAgendamentos($id)) {
            $_SESSION['flash_error'] = 'Não é possível excluir: existem agendamentos vinculados a esta disciplina.';
            return $response->withHeader('Location', '/cadastros/disciplinas')->withStatus(302);
        }

        $this->repo->hardDelete($id);
        $_SESSION['flash_success'] = 'Disciplina excluída com sucesso.';
        return $response->withHeader('Location', '/cadastros/disciplinas')->withStatus(302);
    }
}

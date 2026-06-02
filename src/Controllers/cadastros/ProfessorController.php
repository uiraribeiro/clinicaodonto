<?php
declare(strict_types=1);

namespace App\Controllers\cadastros;

use App\Repositories\ProfessorRepository;
use App\Services\CsrfService;
use App\Validators\ProfessorValidator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

class ProfessorController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly ProfessorRepository $repo,
        private readonly CsrfService $csrfService
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $professores = $this->repo->findAll();

        return $this->twig->render($response, 'cadastros/professores/index.html.twig', [
            'active_menu'    => 'professores',
            'csrf_token'     => $this->csrfService->getToken(),
            'usuario_nome'   => $_SESSION['usuario_nome'] ?? '',
            'usuario_perfil' => $_SESSION['usuario_perfil'] ?? '',
            'professores'    => $professores,
            'flash_success'  => $_SESSION['flash_success'] ?? null,
            'flash_error'    => $_SESSION['flash_error'] ?? null,
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        return $this->twig->render($response, 'cadastros/professores/form.html.twig', [
            'active_menu'     => 'professores',
            'csrf_token'      => $this->csrfService->getToken(),
            'usuario_nome'    => $_SESSION['usuario_nome'] ?? '',
            'usuario_perfil'  => $_SESSION['usuario_perfil'] ?? '',
            'professor'       => null,
            'disponibilidades'=> [],
            'modo'            => 'criar',
        ]);
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data   = (array) $request->getParsedBody();
        $errors = ProfessorValidator::validate($data);

        if (!empty($errors)) {
            return $this->twig->render($response->withStatus(422), 'cadastros/professores/form.html.twig', [
                'active_menu'     => 'professores',
                'csrf_token'      => $this->csrfService->getToken(),
                'usuario_nome'    => $_SESSION['usuario_nome'] ?? '',
                'usuario_perfil'  => $_SESSION['usuario_perfil'] ?? '',
                'professor'       => $data,
                'disponibilidades'=> $data['disponibilidade'] ?? [],
                'errors'          => $errors,
                'modo'            => 'criar',
            ]);
        }

        $usuarioId   = (int) ($_SESSION['usuario_id'] ?? 0);
        $professorId = $this->repo->create($data, $usuarioId);

        $disponibilidades = $data['disponibilidade'] ?? [];
        if (!empty($disponibilidades) && is_array($disponibilidades)) {
            $this->repo->saveDisponibilidade($professorId, array_values($disponibilidades));
        }

        $_SESSION['flash_success'] = 'Professor cadastrado com sucesso.';
        return $response->withHeader('Location', '/cadastros/professores')->withStatus(302);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $professor = $this->repo->findComDisponibilidade((int) $args['id']);
        if (empty($professor)) {
            return $response->withStatus(404);
        }

        $professorComDisc = $this->repo->findComDisciplinas((int) $args['id']);

        return $this->twig->render($response, 'cadastros/professores/show.html.twig', [
            'active_menu'     => 'professores',
            'csrf_token'      => $this->csrfService->getToken(),
            'usuario_nome'    => $_SESSION['usuario_nome'] ?? '',
            'usuario_perfil'  => $_SESSION['usuario_perfil'] ?? '',
            'professor'       => $professor,
            'disciplinas'     => $professorComDisc['disciplinas'] ?? [],
        ]);
    }

    public function edit(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $professor = $this->repo->findComDisponibilidade((int) $args['id']);
        if (empty($professor)) {
            return $response->withStatus(404);
        }

        return $this->twig->render($response, 'cadastros/professores/form.html.twig', [
            'active_menu'     => 'professores',
            'csrf_token'      => $this->csrfService->getToken(),
            'usuario_nome'    => $_SESSION['usuario_nome'] ?? '',
            'usuario_perfil'  => $_SESSION['usuario_perfil'] ?? '',
            'professor'       => $professor,
            'disponibilidades'=> $professor['disponibilidades'] ?? [],
            'modo'            => 'editar',
        ]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id        = (int) $args['id'];
        $data      = (array) $request->getParsedBody();
        $professor = $this->repo->findById($id);

        if (!$professor) {
            return $response->withStatus(404);
        }

        $errors = ProfessorValidator::validate($data, $id);
        if (!empty($errors)) {
            $data['id'] = $id;
            return $this->twig->render($response->withStatus(422), 'cadastros/professores/form.html.twig', [
                'active_menu'     => 'professores',
                'csrf_token'      => $this->csrfService->getToken(),
                'usuario_nome'    => $_SESSION['usuario_nome'] ?? '',
                'usuario_perfil'  => $_SESSION['usuario_perfil'] ?? '',
                'professor'       => $data,
                'disponibilidades'=> $data['disponibilidade'] ?? [],
                'errors'          => $errors,
                'modo'            => 'editar',
            ]);
        }

        $usuarioId = (int) ($_SESSION['usuario_id'] ?? 0);
        $this->repo->update($id, $data, $usuarioId);

        $disponibilidades = $data['disponibilidade'] ?? [];
        $this->repo->saveDisponibilidade($id, is_array($disponibilidades) ? array_values($disponibilidades) : []);

        $_SESSION['flash_success'] = 'Professor atualizado com sucesso.';
        return $response->withHeader('Location', '/cadastros/professores')->withStatus(302);
    }

    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];
        $this->repo->softDelete($id, (int) ($_SESSION['usuario_id'] ?? 0));
        $_SESSION['flash_success'] = 'Professor desativado com sucesso.';
        return $response->withHeader('Location', '/cadastros/professores')->withStatus(302);
    }
}

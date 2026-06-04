<?php
declare(strict_types=1);

namespace App\Controllers\cadastros;

use App\Repositories\PreceptorRepository;
use App\Services\CsrfService;
use App\Validators\PreceptorValidator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

class PreceptorController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly PreceptorRepository $repo,
        private readonly CsrfService $csrfService
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $preceptores = $this->repo->findAll();

        return $this->twig->render($response, 'cadastros/preceptores/index.html.twig', [
            'active_menu'    => 'preceptores',
            'csrf_token'     => $this->csrfService->getToken(),
            'usuario_nome'   => $_SESSION['usuario_nome'] ?? '',
            'usuario_perfil' => $_SESSION['usuario_perfil'] ?? '',
            'preceptores'    => $preceptores,
            'flash_success'  => $_SESSION['flash_success'] ?? null,
            'flash_error'    => $_SESSION['flash_error'] ?? null,
        ]);
    }

    public function create(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        return $this->twig->render($response, 'cadastros/preceptores/form.html.twig', [
            'active_menu'     => 'preceptores',
            'csrf_token'      => $this->csrfService->getToken(),
            'usuario_nome'    => $_SESSION['usuario_nome'] ?? '',
            'usuario_perfil'  => $_SESSION['usuario_perfil'] ?? '',
            'preceptor'       => null,
            'disponibilidades'=> [],
            'modo'            => 'criar',
        ]);
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data   = (array) $request->getParsedBody();
        $errors = PreceptorValidator::validate($data);

        if (!empty($errors)) {
            return $this->twig->render($response->withStatus(422), 'cadastros/preceptores/form.html.twig', [
                'active_menu'     => 'preceptores',
                'csrf_token'      => $this->csrfService->getToken(),
                'usuario_nome'    => $_SESSION['usuario_nome'] ?? '',
                'usuario_perfil'  => $_SESSION['usuario_perfil'] ?? '',
                'preceptor'       => $data,
                'disponibilidades'=> $data['disponibilidade'] ?? [],
                'errors'          => $errors,
                'modo'            => 'criar',
            ]);
        }

        $usuarioId   = (int) ($_SESSION['usuario_id'] ?? 0);
        $preceptorId = $this->repo->create($data, $usuarioId);

        $disponibilidades = $data['disponibilidade'] ?? [];
        if (!empty($disponibilidades) && is_array($disponibilidades)) {
            $this->repo->saveDisponibilidade($preceptorId, array_values($disponibilidades));
        }

        $_SESSION['flash_success'] = 'Preceptor cadastrado com sucesso.';
        return $response->withHeader('Location', '/cadastros/preceptores')->withStatus(302);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $preceptor = $this->repo->findComDisponibilidade((int) $id);
        if (empty($preceptor)) {
            return $response->withStatus(404);
        }

        $preceptorComDisc = $this->repo->findComDisciplinas((int) $id);

        return $this->twig->render($response, 'cadastros/preceptores/show.html.twig', [
            'active_menu'     => 'preceptores',
            'csrf_token'      => $this->csrfService->getToken(),
            'usuario_nome'    => $_SESSION['usuario_nome'] ?? '',
            'usuario_perfil'  => $_SESSION['usuario_perfil'] ?? '',
            'preceptor'       => $preceptor,
            'disciplinas'     => $preceptorComDisc['disciplinas'] ?? [],
        ]);
    }

    public function edit(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $preceptor = $this->repo->findComDisponibilidade((int) $id);
        if (empty($preceptor)) {
            return $response->withStatus(404);
        }

        return $this->twig->render($response, 'cadastros/preceptores/form.html.twig', [
            'active_menu'     => 'preceptores',
            'csrf_token'      => $this->csrfService->getToken(),
            'usuario_nome'    => $_SESSION['usuario_nome'] ?? '',
            'usuario_perfil'  => $_SESSION['usuario_perfil'] ?? '',
            'preceptor'       => $preceptor,
            'disponibilidades'=> $preceptor['disponibilidades'] ?? [],
            'modo'            => 'editar',
        ]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $id        = (int) $id;
        $data      = (array) $request->getParsedBody();
        $preceptor = $this->repo->findById($id);

        if (!$preceptor) {
            return $response->withStatus(404);
        }

        $errors = PreceptorValidator::validate($data, $id);
        if (!empty($errors)) {
            $data['id'] = $id;
            return $this->twig->render($response->withStatus(422), 'cadastros/preceptores/form.html.twig', [
                'active_menu'     => 'preceptores',
                'csrf_token'      => $this->csrfService->getToken(),
                'usuario_nome'    => $_SESSION['usuario_nome'] ?? '',
                'usuario_perfil'  => $_SESSION['usuario_perfil'] ?? '',
                'preceptor'       => $data,
                'disponibilidades'=> $data['disponibilidade'] ?? [],
                'errors'          => $errors,
                'modo'            => 'editar',
            ]);
        }

        $usuarioId = (int) ($_SESSION['usuario_id'] ?? 0);
        $this->repo->update($id, $data, $usuarioId);

        $disponibilidades = $data['disponibilidade'] ?? [];
        $this->repo->saveDisponibilidade($id, is_array($disponibilidades) ? array_values($disponibilidades) : []);

        $_SESSION['flash_success'] = 'Preceptor atualizado com sucesso.';
        return $response->withHeader('Location', '/cadastros/preceptores')->withStatus(302);
    }

    public function toggleAtivo(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $id        = (int) $id;
        $preceptor = $this->repo->findById($id);
        if (!$preceptor) {
            return $response->withStatus(404);
        }
        $this->repo->toggleAtivo($id, (int) ($_SESSION['usuario_id'] ?? 0));
        $_SESSION['flash_success'] = $preceptor['ativo']
            ? 'Preceptor desativado com sucesso.'
            : 'Preceptor ativado com sucesso.';
        return $response->withHeader('Location', '/cadastros/preceptores')->withStatus(302);
    }

    public function destroy(ServerRequestInterface $request, ResponseInterface $response, string $id): ResponseInterface
    {
        $id = (int) $id;
        if ($this->repo->hasAgendamentos($id)) {
            $_SESSION['flash_error'] = 'Não é possível excluir: preceptor possui agendamentos ativos.';
            return $response->withHeader('Location', '/cadastros/preceptores')->withStatus(302);
        }
        $this->repo->hardDelete($id);
        $_SESSION['flash_success'] = 'Preceptor excluído com sucesso.';
        return $response->withHeader('Location', '/cadastros/preceptores')->withStatus(302);
    }
}

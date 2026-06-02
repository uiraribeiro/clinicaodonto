<?php
declare(strict_types=1);

namespace App\Controllers\cadastros;

use App\Repositories\ClinicaRepository;
use App\Services\CsrfService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

class ClinicaController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly ClinicaRepository $repo,
        private readonly CsrfService $csrfService
    ) {}

    private function ctx(array $extra = []): array
    {
        return array_merge([
            'csrf_token'     => $this->csrfService->getToken(),
            'usuario_nome'   => $_SESSION['usuario_nome'] ?? '',
            'usuario_perfil' => $_SESSION['usuario_perfil'] ?? '',
            'active_menu'    => 'clinica',
        ], $extra);
    }

    /**
     * Lista todas as clínicas com suas capacidades e bloqueios futuros.
     */
    public function index(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $clinicas = $this->repo->findAll();

        // Adiciona bloqueios futuros a cada clínica
        foreach ($clinicas as &$clinica) {
            $clinica['bloqueios'] = $this->repo->getBloqueios((int) $clinica['id']);
        }
        unset($clinica);

        return $this->twig->render(
            $response,
            'cadastros/clinicas/index.html.twig',
            $this->ctx(['clinicas' => $clinicas])
        );
    }

    public function edit(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $clinica = $this->repo->findById((int) $args['id']);
        if (!$clinica) {
            return $response->withStatus(404);
        }

        return $this->twig->render(
            $response,
            'cadastros/clinicas/form.html.twig',
            $this->ctx(['clinica' => $clinica])
        );
    }

    public function update(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $id      = (int) $args['id'];
        $clinica = $this->repo->findById($id);
        if (!$clinica) {
            return $response->withStatus(404);
        }

        $data   = (array) $request->getParsedBody();
        $errors = $this->validarDados($data);

        if (!empty($errors)) {
            return $this->twig->render(
                $response->withStatus(422),
                'cadastros/clinicas/form.html.twig',
                $this->ctx([
                    'clinica' => array_merge($clinica, $data),
                    'errors'  => $errors,
                ])
            );
        }

        $this->repo->update($id, $data, (int) ($_SESSION['usuario_id'] ?? 0));
        $_SESSION['flash_success'] = 'Clínica atualizada com sucesso.';
        return $response->withHeader('Location', '/cadastros/clinica')->withStatus(302);
    }

    /**
     * Lista os bloqueios da clínica e exibe o formulário de novo bloqueio.
     */
    public function bloqueios(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $id      = (int) $args['id'];
        $clinica = $this->repo->findById($id);
        if (!$clinica) {
            return $response->withStatus(404);
        }

        $bloqueios = $this->repo->getBloqueios($id);

        return $this->twig->render(
            $response,
            'cadastros/clinicas/bloqueios.html.twig',
            $this->ctx([
                'clinica'   => $clinica,
                'bloqueios' => $bloqueios,
            ])
        );
    }

    public function addBloqueio(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $id      = (int) $args['id'];
        $clinica = $this->repo->findById($id);
        if (!$clinica) {
            return $response->withStatus(404);
        }

        $data   = (array) $request->getParsedBody();
        $errors = $this->validarBloqueio($data);

        if (!empty($errors)) {
            $bloqueios = $this->repo->getBloqueios($id);
            return $this->twig->render(
                $response->withStatus(422),
                'cadastros/clinicas/bloqueios.html.twig',
                $this->ctx([
                    'clinica'   => $clinica,
                    'bloqueios' => $bloqueios,
                    'form'      => $data,
                    'errors'    => $errors,
                ])
            );
        }

        $this->repo->addBloqueio($id, $data, (int) ($_SESSION['usuario_id'] ?? 0));
        $_SESSION['flash_success'] = 'Bloqueio adicionado com sucesso.';
        return $response->withHeader(
            'Location',
            "/cadastros/clinica/{$id}/bloqueios"
        )->withStatus(302);
    }

    // -------------------------------------------------------------------------
    // Validação local
    // -------------------------------------------------------------------------

    private function validarDados(array $data): array
    {
        $errors = [];

        if (empty(trim($data['nome'] ?? ''))) {
            $errors['nome'] = 'O nome é obrigatório.';
        }

        $cadeiras = (int) ($data['quantidade_cadeiras'] ?? 0);
        if ($cadeiras < 1 || $cadeiras > 100) {
            $errors['quantidade_cadeiras'] = 'Quantidade de cadeiras deve ser entre 1 e 100.';
        }

        $capCadeira = (int) ($data['capacidade_por_cadeira'] ?? 0);
        if ($capCadeira < 1 || $capCadeira > 10) {
            $errors['capacidade_por_cadeira'] = 'Capacidade por cadeira deve ser entre 1 e 10.';
        }

        return $errors;
    }

    private function validarBloqueio(array $data): array
    {
        $errors = [];

        if (empty($data['data_inicio'])) {
            $errors['data_inicio'] = 'A data de início é obrigatória.';
        }
        if (empty($data['data_fim'])) {
            $errors['data_fim'] = 'A data de fim é obrigatória.';
        }
        if (
            !empty($data['data_inicio']) && !empty($data['data_fim'])
            && $data['data_fim'] < $data['data_inicio']
        ) {
            $errors['data_fim'] = 'A data de fim deve ser igual ou posterior à data de início.';
        }
        if (empty(trim($data['motivo'] ?? ''))) {
            $errors['motivo'] = 'O motivo é obrigatório.';
        }

        return $errors;
    }
}

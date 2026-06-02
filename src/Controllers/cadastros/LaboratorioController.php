<?php
declare(strict_types=1);

namespace App\Controllers\cadastros;

use App\Repositories\LaboratorioRepository;
use App\Services\CsrfService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

class LaboratorioController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly LaboratorioRepository $repo,
        private readonly CsrfService $csrfService
    ) {}

    private function ctx(array $extra = []): array
    {
        return array_merge([
            'csrf_token'     => $this->csrfService->getToken(),
            'usuario_nome'   => $_SESSION['usuario_nome'] ?? '',
            'usuario_perfil' => $_SESSION['usuario_perfil'] ?? '',
            'active_menu'    => 'laboratorio',
        ], $extra);
    }

    /**
     * Lista todos os laboratórios com suas capacidades e bloqueios futuros.
     */
    public function index(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $laboratorios = $this->repo->findAll();

        // Adiciona bloqueios futuros a cada laboratório
        foreach ($laboratorios as &$lab) {
            $lab['bloqueios'] = $this->repo->getBloqueios((int) $lab['id']);
        }
        unset($lab);

        return $this->twig->render(
            $response,
            'cadastros/laboratorios/index.html.twig',
            $this->ctx(['laboratorios' => $laboratorios])
        );
    }

    public function edit(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $lab = $this->repo->findById((int) $args['id']);
        if (!$lab) {
            return $response->withStatus(404);
        }

        return $this->twig->render(
            $response,
            'cadastros/laboratorios/form.html.twig',
            $this->ctx(['laboratorio' => $lab])
        );
    }

    public function update(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $id  = (int) $args['id'];
        $lab = $this->repo->findById($id);
        if (!$lab) {
            return $response->withStatus(404);
        }

        $data   = (array) $request->getParsedBody();
        $errors = $this->validarDados($data);

        if (!empty($errors)) {
            return $this->twig->render(
                $response->withStatus(422),
                'cadastros/laboratorios/form.html.twig',
                $this->ctx([
                    'laboratorio' => array_merge($lab, $data),
                    'errors'      => $errors,
                ])
            );
        }

        $this->repo->update($id, $data, (int) ($_SESSION['usuario_id'] ?? 0));
        $_SESSION['flash_success'] = 'Laboratório atualizado com sucesso.';
        return $response->withHeader('Location', '/cadastros/laboratorio')->withStatus(302);
    }

    /**
     * Lista os bloqueios do laboratório e exibe o formulário de novo bloqueio.
     */
    public function bloqueios(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $id  = (int) $args['id'];
        $lab = $this->repo->findById($id);
        if (!$lab) {
            return $response->withStatus(404);
        }

        $bloqueios = $this->repo->getBloqueios($id);

        return $this->twig->render(
            $response,
            'cadastros/laboratorios/bloqueios.html.twig',
            $this->ctx([
                'laboratorio' => $lab,
                'bloqueios'   => $bloqueios,
            ])
        );
    }

    public function addBloqueio(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $id  = (int) $args['id'];
        $lab = $this->repo->findById($id);
        if (!$lab) {
            return $response->withStatus(404);
        }

        $data   = (array) $request->getParsedBody();
        $errors = $this->validarBloqueio($data);

        if (!empty($errors)) {
            $bloqueios = $this->repo->getBloqueios($id);
            return $this->twig->render(
                $response->withStatus(422),
                'cadastros/laboratorios/bloqueios.html.twig',
                $this->ctx([
                    'laboratorio' => $lab,
                    'bloqueios'   => $bloqueios,
                    'form'        => $data,
                    'errors'      => $errors,
                ])
            );
        }

        $this->repo->addBloqueio($id, $data, (int) ($_SESSION['usuario_id'] ?? 0));
        $_SESSION['flash_success'] = 'Bloqueio adicionado com sucesso.';
        return $response->withHeader(
            'Location',
            "/cadastros/laboratorio/{$id}/bloqueios"
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

        $assentos = (int) ($data['quantidade_assentos'] ?? 0);
        if ($assentos < 1 || $assentos > 200) {
            $errors['quantidade_assentos'] = 'Quantidade de assentos deve ser entre 1 e 200.';
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

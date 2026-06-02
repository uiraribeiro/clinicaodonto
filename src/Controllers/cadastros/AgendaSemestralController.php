<?php
declare(strict_types=1);

namespace App\Controllers\cadastros;

use App\Repositories\SemestreRepository;
use App\Services\CsrfService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

class AgendaSemestralController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly SemestreRepository $repo,
        private readonly CsrfService $csrfService
    ) {}

    private function ctx(array $extra = []): array
    {
        return array_merge([
            'csrf_token'     => $this->csrfService->getToken(),
            'usuario_nome'   => $_SESSION['usuario_nome'] ?? '',
            'usuario_perfil' => $_SESSION['usuario_perfil'] ?? '',
            'active_menu'    => 'semestres',
        ], $extra);
    }

    public function index(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $semestres = $this->repo->findAll();
        return $this->twig->render($response, 'cadastros/semestres/index.html.twig', $this->ctx(['semestres' => $semestres]));
    }

    public function create(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        return $this->twig->render($response, 'cadastros/semestres/form.html.twig', $this->ctx(['semestre' => null, 'modo' => 'criar']));
    }

    public function store(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $data   = (array) $request->getParsedBody();
        $errors = $this->validar($data);

        if (empty($errors['referencia'])) {
            $existente = $this->repo->findByReferencia(trim($data['referencia'] ?? ''));
            if ($existente) {
                $errors['referencia'] = "A referência '{$data['referencia']}' já está cadastrada.";
            }
        }

        if (!empty($errors)) {
            return $this->twig->render($response->withStatus(422), 'cadastros/semestres/form.html.twig', $this->ctx([
                'semestre' => $data, 'errors' => $errors, 'modo' => 'criar',
            ]));
        }

        $id = $this->repo->create($data);

        $_SESSION['flash_success'] = 'Semestre criado com sucesso. As ' . ($data['num_semanas'] ?? 20) . ' semanas foram geradas automaticamente.';
        return $response->withHeader('Location', "/cadastros/semestres/{$id}")->withStatus(302);
    }

    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id
    ): ResponseInterface {
        $semestreId = (int) $id;
        $semestre   = $this->repo->findById($semestreId);
        if (!$semestre) {
            return $response->withStatus(404);
        }

        $semanas        = $this->repo->getSemanas($semestreId);
        $diasBloqueados = $this->repo->getDiasBloqueados($semestreId);

        $formDia = $_SESSION['form_dia_bloqueado'] ?? null;
        $formErr = $_SESSION['form_dia_erros'] ?? null;
        unset($_SESSION['form_dia_bloqueado'], $_SESSION['form_dia_erros']);

        return $this->twig->render($response, 'cadastros/semestres/show.html.twig', $this->ctx([
            'semestre'        => $semestre,
            'semanas'         => $semanas,
            'dias_bloqueados' => $diasBloqueados,
            'form_dia'        => $formDia,
            'form_errors'     => $formErr,
        ]));
    }

    public function edit(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id
    ): ResponseInterface {
        $semestreId = (int) $id;
        $semestre   = $this->repo->findById($semestreId);
        if (!$semestre) {
            return $response->withStatus(404);
        }
        return $this->twig->render($response, 'cadastros/semestres/form.html.twig', $this->ctx([
            'semestre' => $semestre, 'modo' => 'editar',
        ]));
    }

    public function update(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id
    ): ResponseInterface {
        $semestreId = (int) $id;
        $semestre   = $this->repo->findById($semestreId);
        if (!$semestre) {
            return $response->withStatus(404);
        }

        $data   = (array) $request->getParsedBody();
        $errors = $this->validar($data);

        if (!empty($errors)) {
            $data['id'] = $semestreId;
            return $this->twig->render($response->withStatus(422), 'cadastros/semestres/form.html.twig', $this->ctx([
                'semestre' => $data, 'errors' => $errors, 'modo' => 'editar',
            ]));
        }

        $this->repo->update($semestreId, $data);
        $_SESSION['flash_success'] = 'Semestre atualizado com sucesso.';
        return $response->withHeader('Location', "/cadastros/semestres/{$semestreId}")->withStatus(302);
    }

    public function addDiaBloqueado(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id
    ): ResponseInterface {
        $semestreId = (int) $id;
        $semestre   = $this->repo->findById($semestreId);
        if (!$semestre) {
            return $response->withStatus(404);
        }

        $data   = (array) $request->getParsedBody();
        $errors = $this->validarDiaBloqueado($data, $semestre);

        if (!empty($errors)) {
            $_SESSION['form_dia_bloqueado'] = $data;
            $_SESSION['form_dia_erros']     = $errors;
            $_SESSION['flash_error']        = 'Corrija os erros no formulário.';
            return $response->withHeader('Location', "/cadastros/semestres/{$semestreId}")->withStatus(302);
        }

        $this->repo->addDiaBloqueado($semestreId, $data);
        $_SESSION['flash_success'] = 'Dia bloqueado adicionado com sucesso.';
        return $response->withHeader('Location', "/cadastros/semestres/{$semestreId}")->withStatus(302);
    }

    public function removeDiaBloqueado(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id,
        string $dia_id
    ): ResponseInterface {
        $semestreId = (int) $id;
        $this->repo->removeDiaBloqueado((int) $dia_id);
        $_SESSION['flash_success'] = 'Dia bloqueado removido com sucesso.';
        return $response->withHeader('Location', "/cadastros/semestres/{$semestreId}")->withStatus(302);
    }

    public function ativar(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id
    ): ResponseInterface {
        $semestreId = (int) $id;
        $semestre   = $this->repo->findById($semestreId);
        if (!$semestre) {
            return $response->withStatus(404);
        }

        $this->repo->ativar($semestreId);
        $_SESSION['flash_success'] = "Semestre '{$semestre['referencia']}' ativado com sucesso.";
        return $response->withHeader('Location', '/cadastros/semestres')->withStatus(302);
    }

    private function validar(array $data): array
    {
        $errors = [];
        $ref = trim($data['referencia'] ?? '');
        if (empty($ref)) {
            $errors['referencia'] = 'A referência é obrigatória (ex: 2026.1).';
        } elseif (!preg_match('/^\d{4}\.[12]$/', $ref)) {
            $errors['referencia'] = 'Formato inválido. Use AAAA.1 ou AAAA.2 (ex: 2026.1).';
        }
        if (empty($data['data_inicio'])) { $errors['data_inicio'] = 'A data de início é obrigatória.'; }
        if (empty($data['data_fim']))    { $errors['data_fim']    = 'A data de fim é obrigatória.'; }
        if (!empty($data['data_inicio']) && !empty($data['data_fim']) && $data['data_fim'] <= $data['data_inicio']) {
            $errors['data_fim'] = 'A data de fim deve ser posterior à data de início.';
        }
        $numSemanas = (int) ($data['num_semanas'] ?? 20);
        if ($numSemanas < 1 || $numSemanas > 30) {
            $errors['num_semanas'] = 'O número de semanas deve ser entre 1 e 30.';
        }
        return $errors;
    }

    private function validarDiaBloqueado(array $data, array $semestre): array
    {
        $errors = [];
        if (empty($data['data'])) {
            $errors['data'] = 'A data é obrigatória.';
        } elseif ($data['data'] < $semestre['data_inicio'] || $data['data'] > $semestre['data_fim']) {
            $errors['data'] = 'A data deve estar dentro do período do semestre (' . $semestre['data_inicio'] . ' a ' . $semestre['data_fim'] . ').';
        }
        if (empty(trim($data['motivo'] ?? ''))) { $errors['motivo'] = 'O motivo é obrigatório.'; }
        if (!in_array($data['tipo'] ?? '', ['feriado','recesso','evento','manutencao'], true)) {
            $errors['tipo'] = 'Tipo inválido. Escolha: feriado, recesso, evento ou manutenção.';
        }
        return $errors;
    }
}

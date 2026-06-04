<?php
declare(strict_types=1);

namespace App\Controllers\cadastros;

use App\Repositories\DisciplinaRepository;
use App\Repositories\ProfessorRepository;
use App\Repositories\PreceptorRepository;
use App\Repositories\SemestreRepository;
use App\Repositories\TurmaRepository;
use App\Services\CsrfService;
use App\Validators\TurmaValidator;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

class TurmaController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly TurmaRepository $repo,
        private readonly DisciplinaRepository $disciplinaRepo,
        private readonly ProfessorRepository $professorRepo,
        private readonly PreceptorRepository $preceptorRepo,
        private readonly SemestreRepository $semestreRepo,
        private readonly CsrfService $csrfService
    ) {}

    // -------------------------------------------------------------------------
    // Contexto base para todos os templates
    // -------------------------------------------------------------------------

    private function ctx(array $extra = []): array
    {
        return array_merge([
            'csrf_token'     => $this->csrfService->getToken(),
            'usuario_nome'   => $_SESSION['usuario_nome'] ?? '',
            'usuario_perfil' => $_SESSION['usuario_perfil'] ?? '',
            'active_menu'    => 'turmas',
        ], $extra);
    }

    // -------------------------------------------------------------------------
    // CRUD
    // -------------------------------------------------------------------------

    public function index(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $query        = $request->getQueryParams();
        $semestreRef  = trim($query['semestre'] ?? '');

        $turmas   = $this->repo->findAll($semestreRef);
        $semestre = $semestreRef !== ''
            ? $this->semestreRepo->findByReferencia($semestreRef)
            : $this->semestreRepo->findAtivo();

        $flashSuccess = $_SESSION['flash_success'] ?? null;
        $flashError   = $_SESSION['flash_error']   ?? null;
        unset($_SESSION['flash_success'], $_SESSION['flash_error']);

        return $this->twig->render(
            $response,
            'cadastros/turmas/index.html.twig',
            $this->ctx([
                'turmas'        => $turmas,
                'semestre'      => $semestre,
                'semestre_ref'  => $semestreRef,
                'flash_success' => $flashSuccess,
                'flash_error'   => $flashError,
            ])
        );
    }

    public function create(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $cursos      = $this->repo->findAllCursos();
        $disciplinas = $this->disciplinaRepo->findAll(true);
        $professores = $this->professorRepo->findAll(true);
        $preceptores = $this->preceptorRepo->findAll(true);
        $semestre    = $this->semestreRepo->findAtivo();

        return $this->twig->render(
            $response,
            'cadastros/turmas/form.html.twig',
            $this->ctx([
                'turma'       => null,
                'cursos'      => $cursos,
                'disciplinas' => $disciplinas,
                'professores' => $professores,
                'preceptores' => $preceptores,
                'semestre'    => $semestre,
                'modo'        => 'criar',
            ])
        );
    }

    public function store(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $data   = (array) $request->getParsedBody();
        $errors = TurmaValidator::validate($data);

        if (!empty($errors)) {
            $cursos = $this->repo->findAllCursos();
            return $this->twig->render(
                $response->withStatus(422),
                'cadastros/turmas/form.html.twig',
                $this->ctx([
                    'turma'  => $data,
                    'cursos' => $cursos,
                    'errors' => $errors,
                    'modo'   => 'criar',
                ])
            );
        }

        $usuarioId = (int) ($_SESSION['usuario_id'] ?? 0);
        $turmaId   = $this->repo->create($data, $usuarioId);

        $semestre = $this->semestreRepo->findAtivo();
        if ($semestre && !empty($data['disciplina_id'])) {
            $this->repo->syncDisciplinaSemestre(
                $turmaId,
                (int) $data['disciplina_id'],
                !empty($data['professor_id']) ? (int)$data['professor_id'] : null,
                !empty($data['preceptor_id']) ? (int)$data['preceptor_id'] : null,
                $semestre['referencia'],
                $usuarioId
            );
        }

        $_SESSION['flash_success'] = 'Turma criada com sucesso.';
        return $response->withHeader('Location', '/cadastros/turmas')->withStatus(302);
    }

    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id
    ): ResponseInterface {
        $id = (int) $id;

        $semestre    = $this->semestreRepo->findAtivo();
        $semestreRef = $semestre['referencia'] ?? '';

        $turma = $this->repo->findComDisciplinas($id, $semestreRef);
        if (!$turma) {
            return $response->withStatus(404);
        }

        return $this->twig->render(
            $response,
            'cadastros/turmas/show.html.twig',
            $this->ctx([
                'turma'       => $turma,
                'semestre'    => $semestre,
                'semestre_ref'=> $semestreRef,
            ])
        );
    }

    public function edit(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id
    ): ResponseInterface {
        $id = (int) $id;

        $turma = $this->repo->findById($id);
        if (!$turma) {
            return $response->withStatus(404);
        }

        $semestre     = $this->semestreRepo->findAtivo();
        $semestreRef  = $semestre['referencia'] ?? '';
        $turmaCompleta = $this->repo->findComDisciplinas($id, $semestreRef);

        $cursos      = $this->repo->findAllCursos();
        $disciplinas = $this->disciplinaRepo->findAll(true);
        $professores = $this->professorRepo->findAll(true);
        $preceptores = $this->preceptorRepo->findAll(true);

        return $this->twig->render(
            $response,
            'cadastros/turmas/form.html.twig',
            $this->ctx([
                'turma'        => $turmaCompleta ?: $turma,
                'cursos'       => $cursos,
                'disciplinas'  => $disciplinas,
                'professores'  => $professores,
                'preceptores'  => $preceptores,
                'semestre'     => $semestre,
                'semestre_ref' => $semestreRef,
                'modo'         => 'editar',
            ])
        );
    }

    public function update(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id
    ): ResponseInterface {
        $id   = (int) $id;
        $data = (array) $request->getParsedBody();

        $turma = $this->repo->findById($id);
        if (!$turma) {
            return $response->withStatus(404);
        }

        $errors = TurmaValidator::validate($data);
        if (!empty($errors)) {
            $data['id']  = $id;
            $cursos      = $this->repo->findAllCursos();
            $disciplinas = $this->disciplinaRepo->findAll(true);
            $professores = $this->professorRepo->findAll(true);
            $preceptores = $this->preceptorRepo->findAll(true);
            $semestre    = $this->semestreRepo->findAtivo();

            return $this->twig->render(
                $response->withStatus(422),
                'cadastros/turmas/form.html.twig',
                $this->ctx([
                    'turma'       => $data,
                    'cursos'      => $cursos,
                    'disciplinas' => $disciplinas,
                    'professores' => $professores,
                    'preceptores' => $preceptores,
                    'semestre'    => $semestre,
                    'errors'      => $errors,
                    'modo'        => 'editar',
                ])
            );
        }

        $usuarioId = (int) ($_SESSION['usuario_id'] ?? 0);
        $this->repo->update($id, $data, $usuarioId);

        $semestre = $this->semestreRepo->findAtivo();
        if ($semestre && !empty($data['disciplina_id'])) {
            $this->repo->syncDisciplinaSemestre(
                $id,
                (int) $data['disciplina_id'],
                !empty($data['professor_id']) ? (int)$data['professor_id'] : null,
                !empty($data['preceptor_id']) ? (int)$data['preceptor_id'] : null,
                $semestre['referencia'],
                $usuarioId
            );
        }

        $_SESSION['flash_success'] = 'Turma atualizada com sucesso.';
        return $response->withHeader('Location', '/cadastros/turmas')->withStatus(302);
    }

    public function toggleAtivo(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id
    ): ResponseInterface {
        $id    = (int) $id;
        $turma = $this->repo->findById($id);
        if (!$turma) {
            return $response->withStatus(404);
        }
        $this->repo->toggleAtivo($id, (int) ($_SESSION['usuario_id'] ?? 0));
        $_SESSION['flash_success'] = $turma['ativo']
            ? 'Turma desativada com sucesso.'
            : 'Turma ativada com sucesso.';
        return $response->withHeader('Location', '/cadastros/turmas')->withStatus(302);
    }

    public function destroy(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $id
    ): ResponseInterface {
        $id = (int) $id;
        if ($this->repo->hasAgendamentos($id)) {
            $_SESSION['flash_error'] = 'Não é possível excluir: esta turma possui agendamentos vinculados.';
            return $response->withHeader('Location', '/cadastros/turmas')->withStatus(302);
        }
        $this->repo->hardDelete($id);
        $_SESSION['flash_success'] = 'Turma excluída com sucesso.';
        return $response->withHeader('Location', '/cadastros/turmas')->withStatus(302);
    }
}

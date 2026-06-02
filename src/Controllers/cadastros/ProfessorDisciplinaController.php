<?php
declare(strict_types=1);

namespace App\Controllers\cadastros;

use App\Repositories\DisciplinaRepository;
use App\Repositories\ProfessorRepository;
use App\Services\CsrfService;
use PDO;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

class ProfessorDisciplinaController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly ProfessorRepository $professorRepo,
        private readonly DisciplinaRepository $disciplinaRepo,
        private readonly CsrfService $csrfService,
        private readonly PDO $pdo
    ) {}

    /**
     * Lista todos os vínculos professor–disciplina com dados das duas entidades.
     */
    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $stmt = $this->pdo->query('
            SELECT
                pd.id,
                pd.professor_id,
                pd.disciplina_id,
                pd.data_inicio,
                pd.data_fim,
                pd.observacoes,
                pd.created_at,
                p.nome  AS professor_nome,
                p.email AS professor_email,
                d.codigo AS disciplina_codigo,
                d.nome   AS disciplina_nome,
                d.tipo   AS disciplina_tipo
            FROM professor_disciplina pd
            JOIN professores  p ON p.id = pd.professor_id
            JOIN disciplinas  d ON d.id = pd.disciplina_id
            ORDER BY p.nome, d.nome
        ');
        $vinculos = $stmt->fetchAll();

        $professores = $this->professorRepo->findAll(true);
        $disciplinas = $this->disciplinaRepo->findAll(true);

        return $this->twig->render($response, 'cadastros/professor_disciplina/index.html.twig', [
            'active_menu'    => 'professor_disciplina',
            'csrf_token'     => $this->csrfService->getToken(),
            'usuario_nome'   => $_SESSION['usuario_nome'] ?? '',
            'usuario_perfil' => $_SESSION['usuario_perfil'] ?? '',
            'vinculos'       => $vinculos,
            'professores'    => $professores,
            'disciplinas'    => $disciplinas,
            'flash_success'  => $_SESSION['flash_success'] ?? null,
            'flash_error'    => $_SESSION['flash_error'] ?? null,
        ]);
    }

    /**
     * Cria um vínculo professor–disciplina.
     * Valida existência de ambos e rejeita duplicata (mesmo professor + disciplina).
     */
    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data        = (array) $request->getParsedBody();
        $professorId = (int) ($data['professor_id'] ?? 0);
        $disciplinaId= (int) ($data['disciplina_id'] ?? 0);

        if ($professorId <= 0 || $disciplinaId <= 0) {
            $_SESSION['flash_error'] = 'Selecione um professor e uma disciplina.';
            return $response->withHeader('Location', '/cadastros/professor-disciplinas')->withStatus(302);
        }

        $professor  = $this->professorRepo->findById($professorId);
        $disciplina = $this->disciplinaRepo->findById($disciplinaId);

        if (!$professor) {
            $_SESSION['flash_error'] = 'Professor não encontrado.';
            return $response->withHeader('Location', '/cadastros/professor-disciplinas')->withStatus(302);
        }
        if (!$disciplina) {
            $_SESSION['flash_error'] = 'Disciplina não encontrada.';
            return $response->withHeader('Location', '/cadastros/professor-disciplinas')->withStatus(302);
        }

        // Verifica duplicata: mesmo professor + disciplina sem data_fim ou data_fim no futuro
        $stmtDup = $this->pdo->prepare('
            SELECT COUNT(*) FROM professor_disciplina
            WHERE professor_id  = :professor_id
              AND disciplina_id = :disciplina_id
              AND (data_fim IS NULL OR data_fim >= CURDATE())
        ');
        $stmtDup->execute([
            ':professor_id'  => $professorId,
            ':disciplina_id' => $disciplinaId,
        ]);
        if ((int) $stmtDup->fetchColumn() > 0) {
            $_SESSION['flash_error'] = 'Este vínculo já existe e está ativo.';
            return $response->withHeader('Location', '/cadastros/professor-disciplinas')->withStatus(302);
        }

        $usuarioId  = (int) ($_SESSION['usuario_id'] ?? 0);
        $dataInicio = !empty($data['data_inicio']) ? $data['data_inicio'] : date('Y-m-d');
        $dataFim    = !empty($data['data_fim'])    ? $data['data_fim']    : null;
        $observacoes= !empty($data['observacoes']) ? trim($data['observacoes']) : null;

        $stmtInsert = $this->pdo->prepare('
            INSERT INTO professor_disciplina
                (professor_id, disciplina_id, data_inicio, data_fim, observacoes, created_by)
            VALUES
                (:professor_id, :disciplina_id, :data_inicio, :data_fim, :observacoes, :created_by)
        ');
        $stmtInsert->execute([
            ':professor_id'  => $professorId,
            ':disciplina_id' => $disciplinaId,
            ':data_inicio'   => $dataInicio,
            ':data_fim'      => $dataFim,
            ':observacoes'   => $observacoes,
            ':created_by'    => $usuarioId,
        ]);

        $_SESSION['flash_success'] = 'Vínculo criado com sucesso.';
        return $response->withHeader('Location', '/cadastros/professor-disciplinas')->withStatus(302);
    }

    /**
     * Remove um vínculo professor–disciplina (DELETE real, não soft delete).
     */
    public function destroy(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        $id = (int) $args['id'];

        $stmt = $this->pdo->prepare('SELECT id FROM professor_disciplina WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        if (!$stmt->fetch()) {
            $_SESSION['flash_error'] = 'Vínculo não encontrado.';
            return $response->withHeader('Location', '/cadastros/professor-disciplinas')->withStatus(302);
        }

        $this->pdo->prepare('DELETE FROM professor_disciplina WHERE id = :id')
                  ->execute([':id' => $id]);

        $_SESSION['flash_success'] = 'Vínculo removido com sucesso.';
        return $response->withHeader('Location', '/cadastros/professor-disciplinas')->withStatus(302);
    }
}

<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\SemestreRepository;
use App\Services\Agenda\AgendaService;
use App\Services\Agenda\SimulacaoService;
use App\Services\CsrfService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

final class AgendaController
{
    public function __construct(
        private readonly Twig               $twig,
        private readonly AgendaService      $agendaService,
        private readonly SimulacaoService   $simulacaoService,
        private readonly SemestreRepository $semestreRepo,
        private readonly CsrfService        $csrfService,
    ) {}

    // =========================================================================
    // Listagem de versões
    // =========================================================================

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $semestres     = $this->semestreRepo->findAll();
        $semestreAtivo = $this->semestreRepo->findAtivo();

        $versoes = [];
        if ($semestreAtivo) {
            $versoes = $this->agendaService->listarVersoes((int)$semestreAtivo['id']);
        }

        return $this->twig->render($response, 'agenda/index.html.twig', $this->ctx([
            'semestres'     => $semestres,
            'semestreAtivo' => $semestreAtivo,
            'versoes'       => $versoes,
        ]));
    }

    // =========================================================================
    // Geração de nova agenda
    // =========================================================================

    public function gerar(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $semestres     = $this->semestreRepo->findAll();
        $semestreAtivo = $this->semestreRepo->findAtivo();

        return $this->twig->render($response, 'agenda/gerar.html.twig', $this->ctx([
            'semestres'     => $semestres,
            'semestreAtivo' => $semestreAtivo,
        ]));
    }

    public function processar(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data       = (array)$request->getParsedBody();
        $semestreId = (int)($data['semestre_id'] ?? 0);
        $descricao  = trim($data['descricao'] ?? '');
        $usuarioId  = (int)($_SESSION['usuario_id'] ?? 0);

        if ($semestreId <= 0) {
            $_SESSION['flash_error'] = 'Selecione um semestre válido.';
            return $response->withHeader('Location', '/agenda/gerar')->withStatus(302);
        }

        try {
            $result = $this->agendaService->gerarAgenda($semestreId, $usuarioId, $descricao);
            $_SESSION['flash_success'] = sprintf(
                'Agenda gerada com sucesso! %d alocações (%.1f%%), %d não alocados, %d conflitos. Tempo: %dms.',
                $result->totalAlocados(),
                $result->percentualSucesso(),
                count($result->naoAlocados),
                $result->totalConflitos(),
                $result->duracaoMs,
            );
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Erro ao gerar agenda: ' . $e->getMessage();
            return $response->withHeader('Location', '/agenda/gerar')->withStatus(302);
        }

        return $response->withHeader('Location', '/agenda')->withStatus(302);
    }

    // =========================================================================
    // Visualização de versão
    // =========================================================================

    public function show(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $versaoId = (int)$args['id'];
        $versao   = $this->agendaService->detalheVersao($versaoId);

        if (!$versao) {
            return $response->withStatus(404);
        }

        $agendados = $this->agruparAgendamentos($versao['agendamentos']);

        return $this->twig->render($response, 'agenda/show.html.twig', $this->ctx([
            'versao'    => $versao,
            'agendados' => $agendados,
            'conflitos' => $versao['conflitos'],
        ]));
    }

    // =========================================================================
    // Publicação
    // =========================================================================

    public function publicar(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $versaoId  = (int)$args['id'];
        $usuarioId = (int)($_SESSION['usuario_id'] ?? 0);

        try {
            $this->agendaService->publicarVersao($versaoId, $usuarioId);
            $_SESSION['flash_success'] = "Versão #{$versaoId} publicada como agenda oficial.";
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Erro ao publicar: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/agenda')->withStatus(302);
    }

    // =========================================================================
    // Simulação
    // =========================================================================

    public function simulacao(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $semestreAtivo = $this->semestreRepo->findAtivo();
        $simulacoes    = $semestreAtivo
            ? $this->simulacaoService->listar((int)$semestreAtivo['id'])
            : [];

        return $this->twig->render($response, 'agenda/simulacao.html.twig', $this->ctx([
            'semestreAtivo' => $semestreAtivo,
            'simulacoes'    => $simulacoes,
        ]));
    }

    public function rodarSimulacao(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data       = (array)$request->getParsedBody();
        $semestreId = (int)($data['semestre_id'] ?? 0);
        $descricao  = trim($data['descricao'] ?? 'Simulação');
        $usuarioId  = (int)($_SESSION['usuario_id'] ?? 0);

        if ($semestreId <= 0) {
            $_SESSION['flash_error'] = 'Selecione um semestre válido.';
            return $response->withHeader('Location', '/agenda/simulacao')->withStatus(302);
        }

        try {
            $result = $this->simulacaoService->simular($semestreId, $usuarioId, $descricao);
            $_SESSION['flash_success'] = sprintf(
                'Simulação concluída: %.1f%% de sucesso, %d conflitos.',
                $result->percentualSucesso(),
                $result->totalConflitos(),
            );
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Erro na simulação: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/agenda/simulacao')->withStatus(302);
    }

    public function descartarSimulacao(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args,
    ): ResponseInterface {
        $this->simulacaoService->descartar((int)$args['id']);
        $_SESSION['flash_success'] = 'Simulação descartada.';
        return $response->withHeader('Location', '/agenda/simulacao')->withStatus(302);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function ctx(array $extra = []): array
    {
        return array_merge([
            'csrf_token'     => $this->csrfService->getToken(),
            'usuario_nome'   => $_SESSION['usuario_nome'] ?? '',
            'usuario_perfil' => $_SESSION['usuario_perfil'] ?? '',
            'active_menu'    => 'agenda',
        ], $extra);
    }

    /** @return array<int,array<string,list<array>>> */
    private function agruparAgendamentos(array $agendamentos): array
    {
        $grouped = [];
        foreach ($agendamentos as $ag) {
            $semana = (int)$ag['numero_semana'];
            $data   = $ag['data_aula'];
            $grouped[$semana][$data][] = $ag;
        }
        ksort($grouped);
        foreach ($grouped as &$semana) {
            ksort($semana);
        }
        return $grouped;
    }
}

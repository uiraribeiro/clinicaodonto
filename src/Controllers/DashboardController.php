<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\AgendaRepository;
use App\Services\CsrfService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

final class DashboardController
{
    public function __construct(
        private readonly Twig              $twig,
        private readonly AgendaRepository  $agendaRepo,
        private readonly CsrfService       $csrfService,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params       = $request->getQueryParams();
        $semanaAtual  = isset($params['semana']) ? (int)$params['semana'] : $this->agendaRepo->getSemanaCorrente();
        $totalSemanas = $this->agendaRepo->getTotalSemanas();

        $indicadores  = $this->agendaRepo->getIndicadoresDashboard();
        $agendaSemana = $this->agendaRepo->getAgendaSemana($semanaAtual);
        $conflitos    = $this->agendaRepo->getConflitosAbertos();
        $gargalos     = $this->agendaRepo->getGargalosDetectados();

        return $this->twig->render($response, 'dashboard/index.html.twig', $this->ctx([
            'indicadores'   => $indicadores,
            'agenda_semana' => $agendaSemana,
            'conflitos'     => $conflitos,
            'gargalos'      => $gargalos,
            'semana_atual'  => $semanaAtual,
            'total_semanas' => $totalSemanas,
        ]));
    }

    public function semana(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params      = $request->getQueryParams();
        $semana      = isset($params['semana']) ? (int)$params['semana'] : $this->agendaRepo->getSemanaCorrente();
        $filtros     = $this->extrairFiltros($params);
        $agenda      = $this->agendaRepo->getAgendaSemana($semana, $filtros);
        $resumo      = $this->agendaRepo->getResumoSemana($agenda);
        $totalSemanas= $this->agendaRepo->getTotalSemanas();

        return $this->twig->render($response, 'dashboard/agenda_semana.html.twig', $this->ctx([
            'agenda_semana' => $agenda,
            'resumo'        => $resumo,
            'semana_atual'  => $semana,
            'total_semanas' => $totalSemanas,
            'filtros'       => $filtros,
        ]));
    }

    public function dia(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params          = $request->getQueryParams();
        $data            = $params['data'] ?? date('Y-m-d');
        $agendaDia       = $this->agendaRepo->getAgendaDia($data);
        $ocupacaoClinica = $this->agendaRepo->getOcupacaoClinica($data);
        $ocupacaoLab     = $this->agendaRepo->getOcupacaoLaboratorio($data);

        return $this->twig->render($response, 'dashboard/agenda_dia.html.twig', $this->ctx([
            'agenda_dia'       => $agendaDia,
            'data_atual'       => $data,
            'ocupacao_clinica' => $ocupacaoClinica,
            'ocupacao_lab'     => $ocupacaoLab,
        ]));
    }

    public function mensal(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $params    = $request->getQueryParams();
        $mes       = (int)($params['mes'] ?? date('n'));
        $ano       = (int)($params['ano'] ?? date('Y'));
        $calendario = $this->agendaRepo->getCalendarioMensal($mes, $ano);

        return $this->twig->render($response, 'dashboard/agenda_mensal.html.twig', $this->ctx([
            'calendario' => $calendario,
            'mes'        => $mes,
            'ano'        => $ano,
        ]));
    }

    /** Endpoint JSON para refresh de indicadores via fetch() no frontend. */
    public function apiIndicadores(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = $this->agendaRepo->getIndicadoresJson();
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // =========================================================================
    // Helpers privados
    // =========================================================================

    private function ctx(array $extra = []): array
    {
        return array_merge([
            'active_menu'    => 'dashboard',
            'csrf_token'     => $this->csrfService->getToken(),
            'usuario_nome'   => $_SESSION['usuario_nome'] ?? '',
            'usuario_perfil' => $_SESSION['usuario_perfil'] ?? '',
        ], $extra);
    }

    private function extrairFiltros(array $params): array
    {
        return array_filter([
            'espaco'       => $params['espaco']       ?? null,
            'turma_id'     => isset($params['turma_id'])     ? (int)$params['turma_id']     : null,
            'professor_id' => isset($params['professor_id']) ? (int)$params['professor_id'] : null,
            'preceptor_id' => isset($params['preceptor_id']) ? (int)$params['preceptor_id'] : null,
        ]);
    }
}

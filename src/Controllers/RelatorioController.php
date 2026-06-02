<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\RelatorioRepository;
use App\Services\CsrfService;
use App\Services\Export\CsvExporter;
use App\Services\Export\ExcelExporter;
use App\Services\Export\PdfExporter;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

final class RelatorioController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly CsrfService $csrfService,
        private readonly RelatorioRepository $repo,
        private readonly CsvExporter $csv,
        private readonly ExcelExporter $excel,
        private readonly PdfExporter $pdf,
    ) {}

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $versao   = $this->repo->getVersaoAtiva();
        $semestre = $this->repo->getSemestreAtivo();

        return $this->twig->render($response, 'relatorios/index.html.twig', $this->ctx([
            'active_menu' => 'relatorios',
            'versao'      => $versao,
            'semestre'    => $semestre,
        ]));
    }

    public function porSemana(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $versao = $this->repo->getVersaoAtiva();
        if (!$versao) {
            return $this->semRedirecionamento($response);
        }

        $params     = $request->getQueryParams();
        $numSemanas = (int)($versao['num_semanas'] ?? 20);
        $semana     = max(1, min($numSemanas, (int)($params['semana'] ?? 1)));

        $agenda   = $this->repo->getAgendaSemana($semana, (int)$versao['id']);
        $ocupacao = $this->repo->getOcupacaoSemanal((int)$versao['id']);

        return $this->twig->render($response, 'relatorios/semana.html.twig', $this->ctx([
            'active_menu'    => 'relatorios',
            'versao'         => $versao,
            'semana_atual'   => $semana,
            'num_semanas'    => $numSemanas,
            'agenda'         => $agenda,
            'ocupacao'       => $ocupacao,
            'agenda_por_dia' => $this->agruparPorDia($agenda),
        ]));
    }

    public function porTurma(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $versao = $this->repo->getVersaoAtiva();
        if (!$versao) {
            return $this->semRedirecionamento($response);
        }

        $resumo = $this->repo->getResumoTurmas($versao['semestre_ref'], (int)$versao['id']);

        return $this->twig->render($response, 'relatorios/turma.html.twig', $this->ctx([
            'active_menu' => 'relatorios',
            'versao'      => $versao,
            'resumo'      => $resumo,
            'por_turma'   => $this->agruparPorChave($resumo, 'turma_nome'),
        ]));
    }

    public function porDisciplina(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $versao = $this->repo->getVersaoAtiva();
        if (!$versao) {
            return $this->semRedirecionamento($response);
        }

        $resumo = $this->repo->getResumoDisciplinas($versao['semestre_ref'], (int)$versao['id']);

        return $this->twig->render($response, 'relatorios/disciplina.html.twig', $this->ctx([
            'active_menu' => 'relatorios',
            'versao'      => $versao,
            'resumo'      => $resumo,
        ]));
    }

    public function porProfessor(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $versao = $this->repo->getVersaoAtiva();
        if (!$versao) {
            return $this->semRedirecionamento($response);
        }

        $resumo = $this->repo->getResumoProfessores((int)$versao['id']);

        return $this->twig->render($response, 'relatorios/professor.html.twig', $this->ctx([
            'active_menu' => 'relatorios',
            'versao'      => $versao,
            'professores' => $resumo['professores'],
            'preceptores' => $resumo['preceptores'],
        ]));
    }

    public function porEspaco(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $versao = $this->repo->getVersaoAtiva();
        if (!$versao) {
            return $this->semRedirecionamento($response);
        }

        $resumo = $this->repo->getResumoEspacos((int)$versao['id']);

        return $this->twig->render($response, 'relatorios/espaco.html.twig', $this->ctx([
            'active_menu'  => 'relatorios',
            'versao'       => $versao,
            'clinicas'     => $this->agruparPorChave($resumo['clinicas'], 'espaco_nome'),
            'laboratorios' => $this->agruparPorChave($resumo['laboratorios'], 'espaco_nome'),
        ]));
    }

    public function exportar(ServerRequestInterface $request, ResponseInterface $response, string $tipo, string $formato): ResponseInterface
    {
        // $tipo e $formato já foram injetados como parâmetros nomeados

        $tiposValidos    = ['semana','turma','disciplina','professor','espaco','completo'];
        $formatosValidos = ['pdf','xlsx','csv'];

        if (!in_array($tipo, $tiposValidos, true) || !in_array($formato, $formatosValidos, true)) {
            return $response->withStatus(400);
        }

        $versao = $this->repo->getVersaoAtiva();
        if (!$versao) {
            return $response->withStatus(404);
        }

        $dados = $this->fetchDados($tipo, $versao);
        $nome  = 'odonto_' . $tipo . '_' . date('Y-m-d');

        return match ($formato) {
            'csv'  => $this->stream($response, $this->csv->exportar($tipo, $dados), 'text/csv; charset=UTF-8', "{$nome}.csv"),
            'xlsx' => $this->stream($response, $this->excel->exportar($tipo, $dados), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', "{$nome}.xlsx"),
            'pdf'  => $this->stream($response, $this->pdf->exportar($tipo, $dados, $versao), 'application/pdf', "{$nome}.pdf"),
        };
    }

    // -------------------------------------------------------------------------

    private function fetchDados(string $tipo, array $versao): mixed
    {
        $vid = (int)$versao['id'];
        $ref = $versao['semestre_ref'];

        return match ($tipo) {
            'semana'     => $this->repo->getOcupacaoSemanal($vid),
            'turma'      => $this->repo->getResumoTurmas($ref, $vid),
            'disciplina' => $this->repo->getResumoDisciplinas($ref, $vid),
            'professor'  => $this->repo->getResumoProfessores($vid),
            'espaco'     => $this->repo->getResumoEspacos($vid),
            'completo'   => $this->repo->getTodosAgendamentos($vid),
        };
    }

    private function stream(
        ResponseInterface $response,
        string $content,
        string $contentType,
        string $filename
    ): ResponseInterface {
        $response->getBody()->write($content);
        return $response
            ->withHeader('Content-Type', $contentType)
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->withHeader('Content-Length', (string)strlen($content))
            ->withHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
    }

    private function semRedirecionamento(ResponseInterface $response): ResponseInterface
    {
        return $response->withHeader('Location', '/relatorios')->withStatus(302);
    }

    private function agruparPorDia(array $agenda): array
    {
        $diasPt = [1 => 'Segunda', 2 => 'Terça', 3 => 'Quarta', 4 => 'Quinta', 5 => 'Sexta', 6 => 'Sábado'];
        $por    = [];
        foreach ($agenda as $item) {
            $label        = $diasPt[(int)$item['dia_semana']] ?? "Dia {$item['dia_semana']}";
            $por[$label][] = $item;
        }
        return $por;
    }

    private function agruparPorChave(array $lista, string $chave): array
    {
        $out = [];
        foreach ($lista as $item) {
            $out[$item[$chave]][] = $item;
        }
        return $out;
    }

    private function ctx(array $extra = []): array
    {
        return array_merge([
            'csrf_token'     => $this->csrfService->getToken(),
            'usuario_nome'   => $_SESSION['usuario_nome'] ?? '',
            'usuario_perfil' => $_SESSION['usuario_perfil'] ?? '',
        ], $extra);
    }
}

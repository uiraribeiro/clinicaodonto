<?php
declare(strict_types=1);

namespace App\Controllers\cadastros;

use App\Repositories\ClinicaRepository;
use App\Repositories\LaboratorioRepository;
use App\Services\CsrfService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;

class HorarioController
{
    public function __construct(
        private readonly Twig $twig,
        private readonly ClinicaRepository $clinicaRepo,
        private readonly LaboratorioRepository $laboratorioRepo,
        private readonly CsrfService $csrfService
    ) {}

    private function ctx(array $extra = []): array
    {
        return array_merge([
            'csrf_token'     => $this->csrfService->getToken(),
            'usuario_nome'   => $_SESSION['usuario_nome'] ?? '',
            'usuario_perfil' => $_SESSION['usuario_perfil'] ?? '',
            'active_menu'    => 'horarios',
        ], $extra);
    }

    /**
     * Exibe a página de configuração de horários de todos os espaços.
     * Lista clínicas e laboratórios com seus horários de funcionamento
     * e os bloqueios pontuais já cadastrados.
     */
    public function index(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $clinicas     = $this->clinicaRepo->findAll();
        $laboratorios = $this->laboratorioRepo->findAll();

        // Enriquece cada espaço com os seus bloqueios futuros
        foreach ($clinicas as &$c) {
            $c['bloqueios'] = $this->clinicaRepo->getBloqueios((int) $c['id']);
        }
        unset($c);

        foreach ($laboratorios as &$l) {
            $l['bloqueios'] = $this->laboratorioRepo->getBloqueios((int) $l['id']);
        }
        unset($l);

        return $this->twig->render(
            $response,
            'cadastros/horarios/index.html.twig',
            $this->ctx([
                'clinicas'     => $clinicas,
                'laboratorios' => $laboratorios,
            ])
        );
    }

    /**
     * Adiciona um bloqueio/disponibilidade customizada para um espaço.
     *
     * Espera os campos:
     *   espaco_tipo (clinica|laboratorio), espaco_id, data_inicio, data_fim,
     *   hora_inicio (opcional), hora_fim (opcional), motivo
     */
    public function store(
        ServerRequestInterface $request,
        ResponseInterface $response
    ): ResponseInterface {
        $data   = (array) $request->getParsedBody();
        $errors = $this->validar($data);

        if (!empty($errors)) {
            $clinicas     = $this->clinicaRepo->findAll();
            $laboratorios = $this->laboratorioRepo->findAll();

            foreach ($clinicas as &$c) {
                $c['bloqueios'] = $this->clinicaRepo->getBloqueios((int) $c['id']);
            }
            unset($c);
            foreach ($laboratorios as &$l) {
                $l['bloqueios'] = $this->laboratorioRepo->getBloqueios((int) $l['id']);
            }
            unset($l);

            return $this->twig->render(
                $response->withStatus(422),
                'cadastros/horarios/index.html.twig',
                $this->ctx([
                    'clinicas'     => $clinicas,
                    'laboratorios' => $laboratorios,
                    'form'         => $data,
                    'errors'       => $errors,
                ])
            );
        }

        $usuarioId  = (int) ($_SESSION['usuario_id'] ?? 0);
        $espacoId   = (int) $data['espaco_id'];
        $espacoTipo = $data['espaco_tipo'];

        if ($espacoTipo === 'clinica') {
            $this->clinicaRepo->addBloqueio($espacoId, $data, $usuarioId);
        } else {
            $this->laboratorioRepo->addBloqueio($espacoId, $data, $usuarioId);
        }

        $_SESSION['flash_success'] = 'Bloqueio cadastrado com sucesso.';
        return $response->withHeader('Location', '/cadastros/horarios')->withStatus(302);
    }

    // -------------------------------------------------------------------------
    // Validação local
    // -------------------------------------------------------------------------

    private function validar(array $data): array
    {
        $errors = [];

        if (!in_array($data['espaco_tipo'] ?? '', ['clinica', 'laboratorio'], true)) {
            $errors['espaco_tipo'] = 'Tipo de espaço inválido.';
        }

        $espacoId = (int) ($data['espaco_id'] ?? 0);
        if ($espacoId <= 0) {
            $errors['espaco_id'] = 'Selecione um espaço válido.';
        }

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

        // Validação cruzada hora: ambos devem estar presentes ou ambos ausentes
        $temHoraInicio = !empty($data['hora_inicio']);
        $temHoraFim    = !empty($data['hora_fim']);
        if ($temHoraInicio !== $temHoraFim) {
            $errors['hora_fim'] = 'Informe hora de início e hora de fim juntos, ou deixe ambos em branco para bloquear o dia inteiro.';
        }
        if ($temHoraInicio && $temHoraFim && $data['hora_fim'] <= $data['hora_inicio']) {
            $errors['hora_fim'] = 'A hora de fim deve ser posterior à hora de início.';
        }

        return $errors;
    }
}

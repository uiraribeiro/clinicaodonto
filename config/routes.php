<?php
declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\DashboardController;
use App\Controllers\AgendaController;
use App\Controllers\BedrockController;
use App\Controllers\RelatorioController;
use App\Controllers\UsuarioController;
use App\Controllers\cadastros\DisciplinaController;
use App\Controllers\cadastros\ProfessorController;
use App\Controllers\cadastros\ProfessorDisciplinaController;
use App\Controllers\cadastros\PreceptorController;
use App\Controllers\cadastros\ClinicaController;
use App\Controllers\cadastros\LaboratorioController;
use App\Controllers\cadastros\TurmaController;
use App\Controllers\cadastros\HorarioController;
use App\Controllers\cadastros\AgendaSemestralController;
use App\Middleware\AuthMiddleware;
use App\Middleware\CsrfMiddleware;
use App\Middleware\PermissionMiddleware;
use Slim\Routing\RouteCollectorProxy;

// ─── Autenticação (público) ───────────────────────────────────────────────────
$app->get('/login',       [AuthController::class, 'showLogin'])->setName('auth.login');
$app->post('/login',      [AuthController::class, 'login'])->add(CsrfMiddleware::class);
$app->post('/logout',     [AuthController::class, 'logout'])->setName('auth.logout');
$app->get('/acesso-negado', [AuthController::class, 'forbidden'])->setName('auth.forbidden');

// ─── Área protegida (requer autenticação) ─────────────────────────────────────
$app->group('', function ($group) {

    // Dashboard
    $group->get('/',                    [DashboardController::class, 'index'])->setName('dashboard');
    $group->get('/dashboard',           [DashboardController::class, 'index']);
    $group->get('/dashboard/semana',    [DashboardController::class, 'semana'])->setName('dashboard.semana');
    $group->get('/dashboard/dia',       [DashboardController::class, 'dia'])->setName('dashboard.dia');
    $group->get('/dashboard/mensal',    [DashboardController::class, 'mensal'])->setName('dashboard.mensal');
    $group->get('/api/indicadores',     [DashboardController::class, 'apiIndicadores'])->setName('api.indicadores');

    // ─── Cadastros ──────────────────────────────────────────────────────────────
    $group->group('/cadastros', function ($c) {

        // Disciplinas
        $c->get('/disciplinas',           [DisciplinaController::class, 'index'])->setName('disciplinas.index');
        $c->get('/disciplinas/nova',      [DisciplinaController::class, 'create'])->setName('disciplinas.create');
        $c->post('/disciplinas',          [DisciplinaController::class, 'store'])->setName('disciplinas.store');
        $c->get('/disciplinas/{id}',      [DisciplinaController::class, 'show'])->setName('disciplinas.show');
        $c->get('/disciplinas/{id}/editar',[DisciplinaController::class, 'edit'])->setName('disciplinas.edit');
        $c->post('/disciplinas/{id}',     [DisciplinaController::class, 'update'])->setName('disciplinas.update');
        $c->post('/disciplinas/{id}/desativar',[DisciplinaController::class, 'toggleAtivo'])->setName('disciplinas.toggle');
        $c->post('/disciplinas/{id}/excluir',[DisciplinaController::class, 'destroy'])->setName('disciplinas.destroy');

        // Professores
        $c->get('/professores',           [ProfessorController::class, 'index'])->setName('professores.index');
        $c->get('/professores/novo',      [ProfessorController::class, 'create'])->setName('professores.create');
        $c->post('/professores',          [ProfessorController::class, 'store'])->setName('professores.store');
        $c->get('/professores/{id}',      [ProfessorController::class, 'show'])->setName('professores.show');
        $c->get('/professores/{id}/editar',[ProfessorController::class, 'edit'])->setName('professores.edit');
        $c->post('/professores/{id}',     [ProfessorController::class, 'update'])->setName('professores.update');
        $c->post('/professores/{id}/desativar',[ProfessorController::class, 'toggleAtivo'])->setName('professores.toggle');
        $c->post('/professores/{id}/excluir',[ProfessorController::class, 'destroy'])->setName('professores.destroy');

        // Professor × Disciplina
        $c->get('/professor-disciplina',  [ProfessorDisciplinaController::class, 'index'])->setName('pd.index');
        $c->post('/professor-disciplina', [ProfessorDisciplinaController::class, 'store'])->setName('pd.store');
        $c->post('/professor-disciplina/{id}/excluir',[ProfessorDisciplinaController::class, 'destroy'])->setName('pd.destroy');

        // Preceptores
        $c->get('/preceptores',           [PreceptorController::class, 'index'])->setName('preceptores.index');
        $c->get('/preceptores/novo',      [PreceptorController::class, 'create'])->setName('preceptores.create');
        $c->post('/preceptores',          [PreceptorController::class, 'store'])->setName('preceptores.store');
        $c->get('/preceptores/{id}',      [PreceptorController::class, 'show'])->setName('preceptores.show');
        $c->get('/preceptores/{id}/editar',[PreceptorController::class, 'edit'])->setName('preceptores.edit');
        $c->post('/preceptores/{id}',     [PreceptorController::class, 'update'])->setName('preceptores.update');
        $c->post('/preceptores/{id}/desativar',[PreceptorController::class, 'toggleAtivo'])->setName('preceptores.toggle');
        $c->post('/preceptores/{id}/excluir',[PreceptorController::class, 'destroy'])->setName('preceptores.destroy');

        // Clínica
        $c->get('/clinica',               [ClinicaController::class, 'index'])->setName('clinica.index');
        $c->get('/clinica/{id}/editar',   [ClinicaController::class, 'edit'])->setName('clinica.edit');
        $c->post('/clinica/{id}',         [ClinicaController::class, 'update'])->setName('clinica.update');
        $c->get('/clinica/{id}/bloqueios',[ClinicaController::class, 'bloqueios'])->setName('clinica.bloqueios');
        $c->post('/clinica/{id}/bloqueios',[ClinicaController::class, 'addBloqueio'])->setName('clinica.bloqueio.add');

        // Laboratório
        $c->get('/laboratorio',           [LaboratorioController::class, 'index'])->setName('laboratorio.index');
        $c->get('/laboratorio/{id}/editar',[LaboratorioController::class, 'edit'])->setName('laboratorio.edit');
        $c->post('/laboratorio/{id}',     [LaboratorioController::class, 'update'])->setName('laboratorio.update');

        // Turmas
        $c->get('/turmas',                [TurmaController::class, 'index'])->setName('turmas.index');
        $c->get('/turmas/nova',           [TurmaController::class, 'create'])->setName('turmas.create');
        $c->post('/turmas',               [TurmaController::class, 'store'])->setName('turmas.store');
        $c->get('/turmas/{id}',           [TurmaController::class, 'show'])->setName('turmas.show');
        $c->get('/turmas/{id}/editar',    [TurmaController::class, 'edit'])->setName('turmas.edit');
        $c->post('/turmas/{id}',          [TurmaController::class, 'update'])->setName('turmas.update');
        $c->post('/turmas/{id}/desativar',[TurmaController::class, 'toggleAtivo'])->setName('turmas.toggle');
        $c->post('/turmas/{id}/excluir',  [TurmaController::class, 'destroy'])->setName('turmas.destroy');

        // Horários disponíveis
        $c->get('/horarios',              [HorarioController::class, 'index'])->setName('horarios.index');
        $c->post('/horarios',             [HorarioController::class, 'store'])->setName('horarios.store');

        // Agenda semestral (configuração do semestre)
        $c->get('/semestres',             [AgendaSemestralController::class, 'index'])->setName('semestres.index');
        $c->get('/semestres/novo',        [AgendaSemestralController::class, 'create'])->setName('semestres.create');
        $c->post('/semestres',            [AgendaSemestralController::class, 'store'])->setName('semestres.store');
        $c->get('/semestres/{id}',              [AgendaSemestralController::class, 'show'])->setName('semestres.show');
        $c->get('/semestres/{id}/editar',       [AgendaSemestralController::class, 'edit'])->setName('semestres.edit');
        $c->post('/semestres/{id}',             [AgendaSemestralController::class, 'update'])->setName('semestres.update');
        $c->post('/semestres/{id}/bloqueios',                [AgendaSemestralController::class, 'addDiaBloqueado'])->setName('semestres.bloqueio.add');
        $c->post('/semestres/{id}/bloqueios/{dia_id}/remover',[AgendaSemestralController::class, 'removeDiaBloqueado'])->setName('semestres.bloqueio.remover');
        $c->post('/semestres/{id}/ativar',                   [AgendaSemestralController::class, 'ativar'])->setName('semestres.ativar');

    })->add(CsrfMiddleware::class);

    // ─── Agenda / Otimizador ────────────────────────────────────────────────────
    $group->group('/agenda', function ($g) {
        $g->get('',                         [AgendaController::class, 'index'])->setName('agenda.index');
        $g->get('/gerar',                   [AgendaController::class, 'gerar'])->setName('agenda.gerar');
        $g->post('/gerar',                  [AgendaController::class, 'processar'])->setName('agenda.processar');
        $g->get('/versao/{id}',             [AgendaController::class, 'show'])->setName('agenda.show');
        $g->post('/publicar/{id}',          [AgendaController::class, 'publicar'])->setName('agenda.publicar');
        $g->get('/simulacao',               [AgendaController::class, 'simulacao'])->setName('agenda.simulacao');
        $g->post('/simulacao/rodar',        [AgendaController::class, 'rodarSimulacao'])->setName('agenda.simulacao.rodar');
        $g->post('/simulacao/{id}/descartar',[AgendaController::class, 'descartarSimulacao'])->setName('agenda.simulacao.descartar');
        // Editor manual por semana
        $g->get('/editor',                  [AgendaController::class, 'editor'])->setName('agenda.editor');
        $g->post('/editor/agendamento',     [AgendaController::class, 'criarAgendamento'])->setName('agenda.editor.criar');
        $g->post('/editor/agendamento/{id}/cancelar',[AgendaController::class, 'cancelarAgendamento'])->setName('agenda.editor.cancelar');
    })->add(CsrfMiddleware::class);

    // ─── Bedrock / IA ───────────────────────────────────────────────────────────
    $group->group('/ia', function ($g) {
        $g->get('/sugestoes/{versao_id}',  [BedrockController::class, 'sugestoes'])->setName('ia.sugestoes');
        $g->post('/sugestoes/solicitar',   [BedrockController::class, 'solicitarSugestoes'])->setName('ia.solicitar');
        $g->post('/sugestoes/{id}/aceitar',[BedrockController::class, 'aceitarSugestao'])->setName('ia.aceitar');
        $g->post('/sugestoes/{id}/rejeitar',[BedrockController::class, 'rejeitarSugestao'])->setName('ia.rejeitar');
        $g->get('/chat',                   [BedrockController::class, 'chatPage'])->setName('ia.chat.page');
        $g->post('/chat',                  [BedrockController::class, 'chat'])->setName('ia.chat');
        $g->post('/proposta/aplicar',      [BedrockController::class, 'aplicarProposta'])->setName('ia.proposta.aplicar');
    })->add(CsrfMiddleware::class);

    // ─── Relatórios ─────────────────────────────────────────────────────────────
    $group->group('/relatorios', function ($g) {
        $g->get('',                       [RelatorioController::class, 'index'])->setName('relatorios.index');
        $g->get('/semana',                [RelatorioController::class, 'porSemana'])->setName('relatorios.semana');
        $g->get('/turma',                 [RelatorioController::class, 'porTurma'])->setName('relatorios.turma');
        $g->get('/disciplina',            [RelatorioController::class, 'porDisciplina'])->setName('relatorios.disciplina');
        $g->get('/professor',             [RelatorioController::class, 'porProfessor'])->setName('relatorios.professor');
        $g->get('/espaco',                [RelatorioController::class, 'porEspaco'])->setName('relatorios.espaco');
        $g->get('/exportar/{tipo}/{formato}', [RelatorioController::class, 'exportar'])->setName('relatorios.exportar');
    });

    // ─── Usuários (admin only) ──────────────────────────────────────────────────
    $group->group('/usuarios', function ($g) {
        $g->get('',          [UsuarioController::class, 'index'])->setName('usuarios.index');
        $g->get('/novo',     [UsuarioController::class, 'create'])->setName('usuarios.create');
        $g->post('',         [UsuarioController::class, 'store'])->setName('usuarios.store');
        $g->get('/{id}',     [UsuarioController::class, 'show'])->setName('usuarios.show');
        $g->get('/{id}/editar',[UsuarioController::class, 'edit'])->setName('usuarios.edit');
        $g->post('/{id}',    [UsuarioController::class, 'update'])->setName('usuarios.update');
    })->add(CsrfMiddleware::class)->add(new PermissionMiddleware(['admin']));

})->add(AuthMiddleware::class);

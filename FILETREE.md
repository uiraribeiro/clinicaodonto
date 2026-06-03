# FILETREE.md — Árvore de Arquivos do Projeto

```
odonto-scheduler/
│
├── .env.example                        # Template de variáveis (commitar)
├── .env                                # Variáveis reais (NÃO commitar)
├── .gitignore
├── CLAUDE.md                           # Guia de desenvolvimento
├── FILETREE.md                         # Este arquivo
├── composer.json                       # Dependências PHP
├── composer.lock
├── docker-compose.yml                  # Dev: MySQL exposto, sem HTTPS
├── docker-compose.prod.yml             # Prod overlay: sem porta MySQL, com HTTPS
│
├── bin/                                # Scripts CLI
│   ├── migrate.php                     # Aplica migrations incrementais
│   ├── seed.php                        # Insere dados iniciais / exemplo
│   └── check.php                       # Verifica requisitos do sistema
│
├── config/
│   ├── container.php                   # PHP-DI: PDO, Twig, Redis, Logger
│   ├── middleware.php                  # Error handler, Body parsing, Security, Twig
│   ├── routes.php                      # Todas as rotas (auth, cadastros, agenda, IA, relat.)
│   └── permissions.php                 # Mapa perfil → permissões por rota
│
├── database/
│   └── migrations/
│       ├── 000_template.sql            # Template para novas migrations
│       ├── 001_schema_inicial.sql      # Schema completo (todas as tabelas)
│       ├── 002_seed_perfis.sql         # Perfis + usuário admin@odonto.local
│       ├── 003_seed_exemplo.sql        # Dados de exemplo (semestre 2026.1)
│       ├── 004_turmas_turno.sql        # turno + dia_semana_preferencial em turmas/turma_disciplina
│       └── 005_turma_disciplina_1para1.sql  # disciplina_id/professor_id/preceptor_id em turmas; 1:1 enforçado
│
├── docker/
│   ├── nginx/
│   │   ├── default.conf                # Dev: HTTP, todas as regras de segurança
│   │   └── default.prod.conf           # Prod: HTTPS TLS 1.2/1.3, HSTS
│   ├── php/
│   │   ├── Dockerfile                  # Dev: PHP 8.2-FPM + extensões
│   │   ├── Dockerfile.prod             # Prod: sem dev tools, opcache agressivo
│   │   └── php.ini                     # expose_php=Off, sessão segura, opcache
│   └── mysql/
│       └── my.cnf                      # utf8mb4, strict mode, buffer 256M
│
├── public/                             # Único diretório acessível pelo Nginx
│   ├── index.php                       # Front controller (entry point)
│   ├── css/
│   │   └── app.css                     # Bootstrap customizado + componentes
│   └── js/
│       └── app.js                      # apiFetch helper + Alpine.js globals
│
├── src/
│   ├── Controllers/
│   │   ├── AuthController.php          # Login, logout, forbidden
│   │   ├── DashboardController.php     # Dashboard: index, semana, dia, mensal, JSON
│   │   ├── AgendaController.php        # Agenda: gerar, show, publicar, editor por semana
│   │   ├── BedrockController.php       # IA: sugestões, chat, aplicar proposta
│   │   ├── RelatorioController.php     # Relatórios: 5 views + exportar (PDF/Excel/CSV)
│   │   ├── UsuarioController.php       # CRUD usuários (admin only)
│   │   └── cadastros/
│   │       ├── AgendaSemestralController.php
│   │       ├── ClinicaController.php
│   │       ├── DisciplinaController.php
│   │       ├── HorarioController.php
│   │       ├── LaboratorioController.php
│   │       ├── PreceptorController.php
│   │       ├── ProfessorController.php
│   │       ├── ProfessorDisciplinaController.php
│   │       └── TurmaController.php     # CRUD turmas; sincroniza turma_disciplina via syncDisciplinaSemestre()
│   │
│   ├── Handlers/
│   │   └── HttpErrorHandler.php        # Páginas de erro amigáveis (404/403/500)
│   │
│   ├── Middleware/
│   │   ├── AuthMiddleware.php          # Requer sessão ativa; regenera ID/30min
│   │   ├── CsrfMiddleware.php          # Valida _csrf_token em POST/PUT/DELETE
│   │   ├── PermissionMiddleware.php    # Valida perfil do usuário por rota
│   │   ├── RateLimitMiddleware.php     # Bloqueia IP após N tentativas (Redis)
│   │   └── SecurityMiddleware.php      # Cookie seguro + HSTS em produção
│   │
│   ├── Repositories/
│   │   ├── AgendaRepository.php        # Queries de agenda, editor, conflitos
│   │   ├── ClinicaRepository.php
│   │   ├── DisciplinaRepository.php
│   │   ├── LaboratorioRepository.php
│   │   ├── PreceptorRepository.php
│   │   ├── ProfessorRepository.php
│   │   ├── RelatorioRepository.php     # Queries analíticas para relatórios
│   │   ├── SemestreRepository.php
│   │   ├── SugestaoRepository.php      # Sugestões Bedrock: salvar, aceitar, rejeitar
│   │   ├── TurmaRepository.php         # CRUD turmas + syncDisciplinaSemestre()
│   │   └── UsuarioRepository.php
│   │
│   ├── Services/
│   │   ├── AuthService.php             # Login, logout, log tentativas, brute force
│   │   ├── CsrfService.php             # Gera e valida token CSRF
│   │   ├── Agenda/
│   │   │   ├── AgendaService.php       # Orquestra geração, editor por semana, slots dinâmicos
│   │   │   └── SimulacaoService.php    # Modo simulação (versão descartável)
│   │   ├── Bedrock/
│   │   │   ├── AgendaTools.php         # 6 ferramentas de agenda para tool use da IA
│   │   │   ├── BedrockClient.php       # AWS SDK wrapper + tool use loop + cache Redis + log
│   │   │   ├── ChatService.php         # Chat multi-turno com tool use (Nova Lite)
│   │   │   ├── PromptBuilder.php       # Prompts PT-BR para análise e chat
│   │   │   ├── SugestaoService.php     # Solicita e persiste sugestões da IA
│   │   │   └── SuggestionValidator.php # Valida JSON da IA contra schema + DB
│   │   ├── Export/
│   │   │   ├── CsvExporter.php         # CSV UTF-8 BOM (compatível com Excel)
│   │   │   ├── ExcelExporter.php       # PhpSpreadsheet multi-sheet
│   │   │   └── PdfExporter.php         # mPDF A4 landscape com cabeçalho/rodapé
│   │   └── Optimization/
│   │       ├── BacktrackingSolver.php  # MRV + backtracking com MAX_ITERATIONS
│   │       ├── ConflictDetector.php    # Scan pós-geração O(n²) de conflitos
│   │       ├── ConstraintPropagator.php# Filtra e ordena slots candidatos (turno + dia preferencial)
│   │       ├── OptimizationContext.php # Estado mutável durante backtracking
│   │       ├── OptimizationLogger.php  # Batch INSERT em optimization_logs
│   │       ├── RuleValidator.php       # Regras puras e stateless (única fonte de verdade)
│   │       ├── ScheduleOptimizer.php   # Orquestrador: solve → conflitos → commit
│   │       └── DTO/
│   │           ├── Allocation.php
│   │           ├── OptimizationResult.php
│   │           ├── SlotCandidate.php
│   │           └── TurmaDisciplinaPair.php  # turno + diaSemanaPreferencial como soft-constraints
│   │
│   └── Validators/
│       ├── DisciplinaValidator.php
│       ├── PreceptorValidator.php
│       ├── ProfessorValidator.php
│       └── TurmaValidator.php          # Valida disciplina_id obrigatório
│
├── storage/                            # Runtime — NÃO commitar conteúdo
│   ├── cache/                          # Cache Twig compilado
│   ├── exports/                        # PDFs e planilhas gerados
│   └── logs/                           # app.log, php_errors.log
│
└── templates/
    ├── layout/
    │   └── base.html.twig              # Layout base Bootstrap 5.3 + Alpine.js
    ├── auth/
    │   ├── login.html.twig
    │   └── forbidden.html.twig
    ├── dashboard/
    │   ├── index.html.twig
    │   ├── agenda_semana.html.twig
    │   ├── agenda_dia.html.twig
    │   └── agenda_mensal.html.twig
    ├── agenda/
    │   ├── index.html.twig
    │   ├── editor.html.twig            # Editor manual por semana (tabela dia × turno)
    │   ├── gerar.html.twig
    │   ├── show.html.twig
    │   ├── simulacao.html.twig
    │   └── placeholder.html.twig
    ├── cadastros/
    │   ├── clinicas/
    │   │   ├── index.html.twig
    │   │   ├── form.html.twig
    │   │   └── bloqueios.html.twig
    │   ├── disciplinas/
    │   │   ├── index.html.twig
    │   │   ├── form.html.twig
    │   │   └── show.html.twig
    │   ├── horarios/
    │   │   └── index.html.twig
    │   ├── laboratorios/
    │   │   ├── index.html.twig
    │   │   ├── form.html.twig
    │   │   └── bloqueios.html.twig
    │   ├── preceptores/
    │   │   ├── index.html.twig
    │   │   ├── form.html.twig
    │   │   └── show.html.twig
    │   ├── professor_disciplina/
    │   │   └── index.html.twig
    │   ├── professores/
    │   │   ├── index.html.twig
    │   │   ├── form.html.twig
    │   │   └── show.html.twig
    │   ├── semestres/
    │   │   ├── index.html.twig
    │   │   ├── form.html.twig
    │   │   └── show.html.twig
    │   └── turmas/
    │       ├── index.html.twig
    │       ├── form.html.twig          # Disciplina/professor/preceptor 1:1 no formulário
    │       └── show.html.twig
    ├── ia/
    │   ├── sugestoes.html.twig         # Lista de sugestões com aprovação humana
    │   └── chat.html.twig              # Chat com tool use + cards de proposta
    ├── relatorios/
    │   ├── index.html.twig
    │   ├── semana.html.twig
    │   ├── turma.html.twig
    │   ├── disciplina.html.twig
    │   ├── professor.html.twig
    │   └── espaco.html.twig
    ├── usuarios/
    │   ├── index.html.twig
    │   ├── form.html.twig
    │   └── show.html.twig
    └── errors/
        ├── 404.html.twig
        ├── 403.html.twig
        └── 500.html.twig               # Stack trace visível apenas em dev
```

## Fluxo de dados

```
Request → Nginx → PHP-FPM → public/index.php
  → SecurityMiddleware (session, HSTS)
  → TwigMiddleware
  → AuthMiddleware (sessão obrigatória)
  → CsrfMiddleware (POST/PUT/DELETE)
  → PermissionMiddleware (por grupo de rotas)
  → Controller → Service → Repository → PDO → MySQL
               ↘ BedrockClient → AWS Bedrock (Nova Lite, tool use loop)
               ↘ CsvExporter / ExcelExporter / PdfExporter
  ← Response (HTML via Twig | JSON | arquivo binário)
```

## Comandos de desenvolvimento

```bash
# Primeira configuração
cp .env.example .env && nano .env
docker compose up -d
docker compose exec php composer install
docker compose exec php php bin/migrate.php
docker compose exec php php bin/seed.php --example

# Verificar requisitos
docker compose exec php php bin/check.php

# Nova migration (próxima: 006_)
cp database/migrations/000_template.sql database/migrations/006_minha_mudanca.sql
docker compose exec php php bin/migrate.php
```

## Deploy em produção

```bash
# Com docker-compose.prod.yml (overlay)
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --build

# Coloque os certificados SSL em:
#   docker/nginx/certs/odonto.crt
#   docker/nginx/certs/odonto.key
```

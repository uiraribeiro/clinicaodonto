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
│       ├── 001_schema_inicial.sql      # Schema completo (27 tabelas)
│       ├── 002_seed_perfis.sql         # Perfis + usuário admin@odonto.local
│       └── 003_seed_exemplo.sql        # Dados de exemplo (semestre 2026.1)
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
│   │   ├── AgendaController.php        # Agenda: gerar, show, publicar, simulação
│   │   ├── BedrockController.php       # IA: sugestões, aceitar, rejeitar, chat
│   │   ├── RelatorioController.php     # Relatórios: 5 views + exportar (PDF/Excel/CSV)
│   │   ├── UsuarioController.php       # CRUD usuários (admin only)
│   │   └── cadastros/
│   │       ├── DisciplinaController.php
│   │       ├── ProfessorController.php
│   │       ├── ProfessorDisciplinaController.php
│   │       ├── PreceptorController.php
│   │       ├── ClinicaController.php
│   │       ├── LaboratorioController.php
│   │       ├── TurmaController.php
│   │       ├── HorarioController.php
│   │       └── AgendaSemestralController.php
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
│   │   ├── AgendaRepository.php        # Queries de agenda, dashboard, conflitos
│   │   ├── ClinicaRepository.php
│   │   ├── DisciplinaRepository.php
│   │   ├── LaboratorioRepository.php
│   │   ├── PreceptorRepository.php
│   │   ├── ProfessorRepository.php
│   │   ├── RelatorioRepository.php     # 9 queries analíticas para relatórios
│   │   ├── SemestreRepository.php
│   │   ├── SugestaoRepository.php      # Sugestões Bedrock: salvar, aceitar, rejeitar
│   │   ├── TurmaRepository.php
│   │   └── UsuarioRepository.php
│   │
│   ├── Services/
│   │   ├── AuthService.php             # Login, logout, log tentativas, brute force
│   │   ├── CsrfService.php             # Gera e valida token CSRF
│   │   ├── Agenda/
│   │   │   ├── AgendaService.php       # Orquestra geração de agenda + slots dinâmicos
│   │   │   └── SimulacaoService.php    # Modo simulação (versão descartável)
│   │   ├── Bedrock/
│   │   │   ├── BedrockClient.php       # AWS SDK wrapper + cache Redis + log
│   │   │   ├── ChatService.php         # Chat multi-turno com histórico
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
│   │       ├── ConstraintPropagator.php# Filtra e ordena slots candidatos
│   │       ├── OptimizationContext.php # Estado mutável durante backtracking
│   │       ├── OptimizationLogger.php  # Batch INSERT em optimization_logs
│   │       ├── RuleValidator.php       # 16 regras puras e stateless
│   │       ├── ScheduleOptimizer.php   # Orquestrador: solve → conflitos → commit
│   │       └── DTO/
│   │           ├── Allocation.php
│   │           ├── OptimizationResult.php
│   │           ├── SlotCandidate.php
│   │           └── TurmaDisciplinaPair.php
│   │
│   └── Validators/
│       ├── DisciplinaValidator.php
│       ├── PreceptorValidator.php
│       ├── ProfessorValidator.php
│       └── TurmaValidator.php
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
    │   └── login.html.twig
    ├── dashboard/
    │   ├── index.html.twig
    │   ├── agenda_semana.html.twig
    │   ├── agenda_dia.html.twig
    │   └── agenda_mensal.html.twig
    ├── agenda/
    │   ├── index.html.twig
    │   ├── gerar.html.twig
    │   ├── show.html.twig
    │   ├── simulacao.html.twig
    │   └── placeholder.html.twig
    ├── cadastros/
    │   ├── disciplinas/
    │   ├── professores/
    │   ├── preceptores/
    │   ├── clinica/
    │   ├── laboratorio/
    │   ├── turmas/
    │   ├── horarios/
    │   └── semestres/
    ├── ia/
    │   ├── sugestoes.html.twig         # Lista de sugestões com aprovação humana
    │   └── chat.html.twig              # Chat multi-turno Alpine.js
    ├── relatorios/
    │   ├── index.html.twig             # Hub de relatórios com cards
    │   ├── semana.html.twig            # Agenda detalhada por semana
    │   ├── turma.html.twig             # Carga por turma com progresso
    │   ├── disciplina.html.twig        # Uso por disciplina com totais
    │   ├── professor.html.twig         # Carga prof./preceptor + alerta sobrecarga
    │   └── espaco.html.twig            # Ocupação semanal de clínicas e labs
    ├── usuarios/
    └── errors/
        ├── 404.html.twig
        ├── 403.html.twig
        └── 500.html.twig               # Debug stack trace visível apenas em dev
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
               ↘ BedrockClient → AWS Bedrock
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

# Nova migration
cp database/migrations/000_template.sql database/migrations/004_minha_mudanca.sql
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

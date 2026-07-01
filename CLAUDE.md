# CLAUDE.md — Odonto Scheduler

## O que é este projeto

Sistema web para gerenciamento de horários de clínica e laboratório de odontologia universitária ao longo de um semestre letivo de 20 semanas. Maximiza o uso da infraestrutura distribuindo turmas, disciplinas, professores, preceptores, cadeiras e assentos respeitando todas as restrições operacionais.

## Stack tecnológica

| Camada | Tecnologia |
|---|---|
| Backend | PHP 8.2 + Slim Framework 4 + PHP-DI |
| Templates | Twig 3 |
| Frontend | Bootstrap 5.3 + Alpine.js 3 |
| Banco | MySQL 8 + PDO + Prepared Statements |
| Otimizador | Backtracking + Constraint Propagation (PHP puro, auditável) |
| IA Copiloto | Amazon Bedrock — Claude 3.5 Sonnet |
| Export | mPDF + PhpSpreadsheet |
| Auth | Sessão PHP nativa + JWT interno para API interna |
| Deploy | Docker Compose (PHP-FPM 8.2 + Nginx + MySQL 8 + Redis) |
| Deps | Composer 2 |

## Estrutura de pastas

Ver `FILETREE.md` para a árvore completa comentada.

## Como rodar localmente

```bash
cp .env.example .env
# edite .env com suas credenciais AWS Bedrock
docker compose up -d
docker compose exec php composer install
docker compose exec php php bin/migrate.php
docker compose exec php php bin/seed.php
# acesse http://localhost:8080
```

Credenciais padrão após seed: `admin@odonto.local` / `Admin@1234`

## Deploy em produção

O servidor de produção é `universobh`, acessível via bastion `10.8.0.1`. O projeto fica em `/certificacao/clinicaodonto/`. O código é volume-montado (`.:/var/www/html`), então um `git pull` reflete imediatamente — sem rebuild de container.

```bash
# Deploy via git pull (comando único a partir da máquina local)
ssh -i ~/Documents/uribeiro.pem ec2-user@10.8.0.1 \
  'ssh -i ~/challenge.pem ec2-user@universobh \
  "cd /certificacao/clinicaodonto && sudo git pull"'
```

**Sempre que alterar templates Twig**, limpe o cache compilado logo após o pull (em produção `APP_DEBUG=false` o Twig cacheia em `storage/cache/`):
```bash
ssh -i ~/Documents/uribeiro.pem ec2-user@10.8.0.1 \
  'ssh -i ~/challenge.pem ec2-user@universobh \
  "rm -rf /certificacao/clinicaodonto/storage/cache/*"'
```

Migrations em produção (quando houver mudança de schema):
```bash
ssh -i ~/Documents/uribeiro.pem ec2-user@10.8.0.1 \
  'ssh -i ~/challenge.pem ec2-user@universobh \
  "cd /certificacao/clinicaodonto && sudo docker compose exec php php bin/migrate.php"'
```

## Regras de desenvolvimento obrigatórias

### Segurança
- **NUNCA** concatenar dados do usuário em SQL. Sempre PDO + prepared statements.
- **SEMPRE** usar `htmlspecialchars()` em saídas HTML (Twig faz por padrão com `{{ var }}`).
- **SEMPRE** validar e sanitizar inputs no backend (classe `InputValidator`).
- **SEMPRE** incluir CSRF token em formulários (`{{ csrf_field() }}`).
- **NUNCA** expor stack traces em produção (`APP_DEBUG=false`).
- Senhas com `password_hash(PASSWORD_BCRYPT)` e `password_verify()`.
- Sessões: `HttpOnly`, `SameSite=Strict`, `Secure` em produção.
- Registrar tentativas de login inválidas em `audit_logs`.
- Proteção brute force: bloquear IP após 5 tentativas em 15 min.

### Banco de dados
- Todas as mudanças de schema em arquivo `database/migrations/NNN_descricao.sql`.
- Nunca alterar migrations já aplicadas — criar nova migration incremental.
- Seeds em `database/seeds/` com dados de exemplo reais.
- Toda tabela de negócio tem: `created_at`, `updated_at`, `created_by`, `updated_by`.
- Tabelas críticas têm espelho em `audit_logs` (INSERT/UPDATE/DELETE).
- **NUNCA** usar o mesmo parâmetro nomeado PDO mais de uma vez na mesma query. O PDO está configurado com `ATTR_EMULATE_PREPARES => false` (prepared statements nativos), que proíbe parâmetros duplicados. Se precisar do mesmo valor duas vezes, use `:param` e `:param2` com o mesmo valor no `execute()`.

### Otimizador
- O `ScheduleOptimizer` é **determinístico**. Nenhuma aleatoriedade.
- Cada decisão gera um log auditável em `optimization_logs`.
- O validador `RuleValidator` é a **única fonte de verdade** para regras.
- Sugestões do Bedrock **SEMPRE** passam pelo `RuleValidator` antes de serem exibidas.
- Nenhuma sugestão da IA pode ser aplicada sem aprovação humana explícita.

### Integração Bedrock
- Classe `BedrockClient` encapsula todas as chamadas — nunca chamar AWS SDK diretamente nos controllers.
- Credenciais AWS **somente** via `.env` ou IAM Role (EC2). Nunca hardcoded.
- Toda chamada ao Bedrock é registrada em `bedrock_logs` (prompt, resposta, tokens, custo estimado).
- O JSON retornado pelo Bedrock sempre valida contra schema antes de processar.
- Usar cache Redis (TTL 5 min) para respostas idênticas de análise de conflitos.

### Código
- Arquitetura em camadas: Controller → Service → Repository → PDO.
- Controllers só recebem request, chamam service, retornam response. Sem lógica de negócio.
- Repositories só falam com o banco. Sem lógica de negócio.
- Services contêm a lógica de negócio. Podem chamar outros services.
- DTOs (Data Transfer Objects) entre camadas. Sem arrays associativos soltos.
- Sem comentários que explicam "o quê" — só "por quê" quando não óbvio.
- Nomes em inglês no código (classes, métodos, variáveis). Strings de UI em português.

### Git
- Branch `main` = produção. Branch `develop` = integração.
- Feature branches: `feature/NNN-descricao`.
- Commits em português, imperativo: "Adiciona cadastro de disciplinas".
- Nunca commitar `.env`, `vendor/`, `storage/logs/`, arquivos de export.

## Perfis de usuário e permissões

| Perfil | Acesso |
|---|---|
| `admin` | Tudo, incluindo configurações e usuários |
| `coordenador_curso` | Cadastros, agenda, relatórios do seu curso |
| `coordenador_clinica` | Clínica, laboratório, agenda completa, relatórios |
| `professor` | Visualiza agenda própria, solicita ajustes |
| `preceptor` | Visualiza agenda própria, confirma disponibilidade |
| `secretaria` | Cadastros, relatórios, sem acesso a otimização |

## Variáveis de ambiente obrigatórias

Ver `.env.example`. As mais críticas:

```
APP_SECRET=          # chave de 32+ chars para CSRF e sessão
DB_PASSWORD=         # senha do MySQL
AWS_ACCESS_KEY_ID=   # ou usar IAM Role em EC2
AWS_SECRET_ACCESS_KEY=
AWS_BEDROCK_REGION=us-east-1
BEDROCK_MODEL_ID=anthropic.claude-3-5-sonnet-20241022-v2:0
```

## Modelo de dados — decisões importantes

### Turma × Disciplina (1:1)
Cada turma pertence a **uma única disciplina**. `turmas.disciplina_id` é o vínculo canônico.
A tabela `turma_disciplina` é gerenciada automaticamente via `TurmaRepository::syncDisciplinaSemestre()` — ela existe para o otimizador (`loadPairs()`) filtrar por `semestre_ref` e armazenar overrides de turno/dia por semestre. Nunca manipular `turma_disciplina` manualmente pela UI.

### Turno e dia preferencial
Definidos na turma (`turmas.turno`, `turmas.dia_semana_preferencial`). O otimizador usa esses valores como soft-constraint (prioridade, não bloqueio). Se não houver slot disponível no turno/dia preferencial, aloca em outro.

Horários por turno:
- **matutino1**: 07:40 – 10:10
- **matutino2**: 10:10 – 12:40
- **manhã**: 09:20 – 12:00
- **tarde**: 13:10 – 15:55
- **vespertino**: 16:10 – 18:40
- **noturno**: 19:15 – 21:30

### Integração Bedrock (tool use)
`BedrockClient::invocarComTools()` implementa o loop de tool use da Amazon Nova Lite (até 6 iterações). As 6 ferramentas estão em `AgendaTools`. Todo resultado de ferramenta passa pelo `RuleValidator` antes de ser apresentado. Nenhuma sugestão é aplicada sem aprovação humana explícita (rota `POST /ia/proposta/aplicar`).

## Padrão de CRUD nos cadastros

Todos os cadastros (disciplinas, turmas, professores, preceptores) seguem o mesmo padrão de ações:

### Rotas por entidade
```
GET    /cadastros/{entidade}              → index
GET    /cadastros/{entidade}/novo         → create
POST   /cadastros/{entidade}              → store
GET    /cadastros/{entidade}/{id}         → show
GET    /cadastros/{entidade}/{id}/editar  → edit
POST   /cadastros/{entidade}/{id}         → update
POST   /cadastros/{entidade}/{id}/desativar → toggleAtivo  ← toggle ativo/inativo
POST   /cadastros/{entidade}/{id}/excluir   → destroy      ← hard delete com verificação
```

### Regras de exclusão (hard delete)
- **Disciplinas**: bloqueado se existirem turmas (`turmas.disciplina_id`) ou agendamentos vinculados. A lista exibe badge com contagem de turmas; botão fica `disabled` quando há turmas.
- **Turmas**: bloqueado se existirem agendamentos vinculados. `turma_disciplina` cascades automaticamente.
- **Professores**: bloqueado se existirem agendamentos vinculados. `professor_disponibilidade` e `professor_disciplina` cascades.
- **Preceptores**: bloqueado se existirem agendamentos vinculados. `preceptor_disponibilidade` e `preceptor_disciplina` cascades.

`hasAgendamentos()` verifica **todos** os status (incluindo cancelados) — o FK é NO ACTION e bloqueia qualquer registro.

### Flash messages
Controllers de index devem sempre passar `flash_success` e `flash_error` da sessão ao template (e fazer `unset` após ler). O layout base (`templates/layout/base.html.twig`) já renderiza os alertas se as variáveis estiverem definidas.

## Migrations

```bash
# Aplicar todas as migrations pendentes
docker compose exec php php bin/migrate.php

# Criar nova migration
cp database/migrations/000_template.sql database/migrations/006_minha_mudanca.sql
# edite o arquivo, depois:
docker compose exec php php bin/migrate.php
```

### Histórico de migrations
| Arquivo | O que faz |
|---|---|
| `001_schema_inicial.sql` | Schema completo (todas as tabelas) |
| `002_seed_perfis.sql` | Perfis + usuário admin |
| `003_seed_exemplo.sql` | Dados de exemplo (semestre 2026.1) |
| `004_turmas_turno.sql` | Adiciona `turno` e `dia_semana_preferencial` em `turmas` e `turma_disciplina` |
| `005_turma_disciplina_1para1.sql` | Adiciona `disciplina_id/professor_id/preceptor_id` direto em `turmas`; altera unique key de `turma_disciplina` para `(turma_id, semestre_ref)` |

## Fases de implementação

- **Fase 1** ✅ — Fundação: estrutura, Docker, auth, migrations
- **Fase 2** ✅ — Cadastros CRUD completos: disciplinas, turmas, professores, preceptores, clínicas, laboratórios, semestres (com toggle ativo/inativo e hard delete com verificação de dependências)
- **Fase 3** ✅ — Motor de otimização (backtracking + propagação de restrições + editor manual por semana)
- **Fase 4** — Dashboard (semanal, diário, mensal, indicadores)
- **Fase 5** ✅ (parcial) — Integração Amazon Bedrock: sugestões + chat com tool use (Nova Lite)
- **Fase 6** — Relatórios e exportação (PDF, Excel, CSV)
- **Fase 7** — Segurança, deploy e documentação final

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

## Migrations

```bash
# Aplicar todas as migrations pendentes
docker compose exec php php bin/migrate.php

# Criar nova migration
cp database/migrations/000_template.sql database/migrations/004_minha_mudanca.sql
# edite o arquivo, depois:
docker compose exec php php bin/migrate.php
```

## Fases de implementação

- **Fase 1** ✅ — Fundação: estrutura, Docker, auth, migrations
- **Fase 2** — Cadastros CRUD completos
- **Fase 3** — Motor de otimização (backtracking + propagação)
- **Fase 4** — Dashboard (semanal, diário, mensal, indicadores)
- **Fase 5** — Integração Amazon Bedrock (sugestões + chat)
- **Fase 6** — Relatórios e exportação (PDF, Excel, CSV)
- **Fase 7** — Segurança, deploy e documentação final

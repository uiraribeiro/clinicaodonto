# DEPLOY.md — Instalação em Produção

Guia completo para instalar o Odonto Scheduler em um servidor Linux (Ubuntu 22.04 LTS recomendado).

---

## Pré-requisitos do servidor

| Requisito | Mínimo | Recomendado |
|---|---|---|
| CPU | 2 vCPUs | 4 vCPUs |
| RAM | 2 GB | 4 GB |
| Disco | 20 GB SSD | 40 GB SSD |
| SO | Ubuntu 22.04 LTS | Ubuntu 22.04 LTS |
| Docker | 24+ | última estável |
| Docker Compose | 2.20+ | última estável |

**Portas que precisam estar abertas no firewall:**
- `80/tcp` — HTTP (redireciona para HTTPS)
- `443/tcp` — HTTPS
- `22/tcp` — SSH (restrinja ao seu IP)

---

## 1. Preparar o servidor

```bash
# Atualiza o sistema
sudo apt update && sudo apt upgrade -y

# Instala Docker
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker $USER
newgrp docker

# Instala Docker Compose plugin
sudo apt install -y docker-compose-plugin

# Verifica
docker --version
docker compose version
```

---

## 2. Obter o código

```bash
# Cria diretório da aplicação
sudo mkdir -p /opt/odonto
sudo chown $USER:$USER /opt/odonto

# Clona o repositório
git clone <URL_DO_REPOSITORIO> /opt/odonto
cd /opt/odonto
```

---

## 3. Configurar variáveis de ambiente

```bash
cp .env.example .env
nano .env
```

Valores obrigatórios para produção:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://agenda.suauniversidade.edu.br
APP_SECRET=<string aleatória de 64 caracteres — veja abaixo>

DB_ROOT_PASSWORD=<senha forte>
DB_PASSWORD=<senha forte diferente da root>

REDIS_PASSWORD=<senha forte>

AWS_BEDROCK_REGION=us-east-1
BEDROCK_MODEL_ID=amazon.nova-lite-v1:0
BEDROCK_ENABLED=true
```

Para gerar `APP_SECRET`:
```bash
openssl rand -hex 32
```

> **AWS Bedrock:** prefira IAM Role se o servidor for EC2/ECS.
> Deixe `AWS_ACCESS_KEY_ID` e `AWS_SECRET_ACCESS_KEY` em branco e configure a Role na instância.
> Se for servidor externo, crie um IAM User com permissão apenas `bedrock:InvokeModel` e coloque as chaves no `.env`.

---

## 4. Certificado SSL

### Opção A — Let's Encrypt (recomendado, domínio público)

```bash
sudo apt install -y certbot

# Obtém o certificado (servidor sem nada na porta 80 ainda)
sudo certbot certonly --standalone -d agenda.suauniversidade.edu.br

# Copia para o diretório do projeto
sudo mkdir -p /opt/odonto/docker/nginx/certs
sudo cp /etc/letsencrypt/live/agenda.suauniversidade.edu.br/fullchain.pem \
        /opt/odonto/docker/nginx/certs/odonto.crt
sudo cp /etc/letsencrypt/live/agenda.suauniversidade.edu.br/privkey.pem \
        /opt/odonto/docker/nginx/certs/odonto.key
sudo chown $USER:$USER /opt/odonto/docker/nginx/certs/*
```

Renovação automática (cron):
```bash
# Para a renovação — certbot precisa da porta 80 livre
# Adicione ao crontab do root:
sudo crontab -e
```
```
0 3 1 * * docker compose -f /opt/odonto/docker-compose.yml -f /opt/odonto/docker-compose.prod.yml stop nginx \
  && certbot renew --quiet \
  && cp /etc/letsencrypt/live/agenda.suauniversidade.edu.br/fullchain.pem /opt/odonto/docker/nginx/certs/odonto.crt \
  && cp /etc/letsencrypt/live/agenda.suauniversidade.edu.br/privkey.pem /opt/odonto/docker/nginx/certs/odonto.key \
  && docker compose -f /opt/odonto/docker-compose.yml -f /opt/odonto/docker-compose.prod.yml start nginx
```

### Opção B — Certificado institucional (`.crt` / `.key` fornecido pela TI)

```bash
mkdir -p /opt/odonto/docker/nginx/certs
cp seu_certificado.crt /opt/odonto/docker/nginx/certs/odonto.crt
cp sua_chave_privada.key /opt/odonto/docker/nginx/certs/odonto.key
```

---

## 5. Atualizar nginx.conf com o domínio real

Edite `docker/nginx/default.prod.conf` e substitua `server_name _;` pelo domínio:

```nginx
server_name agenda.suauniversidade.edu.br;
```

---

## 6. Build e subir os containers

```bash
cd /opt/odonto

# Build da imagem de produção (inclui composer install sem dev)
docker compose -f docker-compose.yml -f docker-compose.prod.yml build --no-cache

# Sobe tudo em background
docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d

# Verifica status
docker compose -f docker-compose.yml -f docker-compose.prod.yml ps
```

---

## 7. Migrations e dados iniciais

```bash
# Aplica todas as migrations
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
  exec php php bin/migrate.php

# Cria usuário admin (pergunta senha interativamente)
docker compose -f docker-compose.yml -f docker-compose.prod.yml \
  exec php php bin/seed.php
```

O seed cria:
- Usuário `admin@odonto.local` (você troca a senha no primeiro login)
- Perfis de acesso
- Dados de exemplo do semestre 2026.1 (pode remover depois)

---

## 8. Verificar a instalação

```bash
# Logs em tempo real
docker compose -f docker-compose.yml -f docker-compose.prod.yml logs -f

# Testa acesso HTTP (deve redirecionar para HTTPS)
curl -I http://agenda.suauniversidade.edu.br

# Testa HTTPS
curl -I https://agenda.suauniversidade.edu.br
```

Acesse `https://agenda.suauniversidade.edu.br` e faça login com `admin@odonto.local`.

---

## 9. Alias para facilitar operação

Adicione ao `~/.bashrc` ou `~/.zshrc`:

```bash
alias odonto='docker compose -f /opt/odonto/docker-compose.yml -f /opt/odonto/docker-compose.prod.yml'
```

Depois de `source ~/.bashrc`:

```bash
odonto ps                    # status dos containers
odonto logs -f php           # logs do PHP
odonto exec php php bin/migrate.php   # migrations
odonto restart nginx         # reinicia nginx
odonto down                  # para tudo
```

---

## 10. Atualizar para nova versão

```bash
cd /opt/odonto

# Baixa nova versão
git pull origin main

# Rebuild da imagem PHP (inclui o código novo)
odonto build --no-cache php

# Recria só o container PHP (zero downtime para nginx/mysql/redis)
odonto up -d --no-deps php

# Aplica migrations novas se houver
odonto exec php php bin/migrate.php
```

---

## 11. Backup do banco

```bash
# Backup manual
docker exec odonto_mysql mysqldump \
  -u root -p"$DB_ROOT_PASSWORD" \
  --single-transaction --routines --triggers \
  odonto_scheduler | gzip > backup_$(date +%Y%m%d_%H%M).sql.gz

# Backup automático diário (crontab)
sudo crontab -e
```
```
0 2 * * * docker exec odonto_mysql mysqldump -u root -p"SENHA_ROOT" --single-transaction odonto_scheduler | gzip > /opt/odonto/backups/db_$(date +\%Y\%m\%d).sql.gz && find /opt/odonto/backups -name "*.sql.gz" -mtime +30 -delete
```

```bash
mkdir -p /opt/odonto/backups
```

---

## 12. Monitoramento básico

```bash
# Uso de recursos
docker stats

# Logs de erro do PHP
odonto exec php tail -f storage/logs/app.log

# Espaço em disco
df -h /opt/odonto
du -sh /opt/odonto/storage/exports   # exportações geradas

# Limpar exportações antigas manualmente
find /opt/odonto/storage/exports -mtime +7 -delete
```

---

## Checklist pré-go-live

- [ ] `.env` com `APP_DEBUG=false` e `APP_ENV=production`
- [ ] `APP_SECRET` com 64+ caracteres aleatórios
- [ ] Senhas fortes em `DB_PASSWORD`, `DB_ROOT_PASSWORD`, `REDIS_PASSWORD`
- [ ] Certificado SSL instalado e funcionando
- [ ] `server_name` no nginx com domínio real
- [ ] Porta 3306 **não** exposta ao host (verificar com `docker compose ps`)
- [ ] Cron de renovação do certificado configurado
- [ ] Cron de backup do banco configurado
- [ ] Acesso SSH restrito ao IP da equipe (firewall)
- [ ] Credenciais AWS Bedrock configuradas (IAM Role ou chaves no `.env`)
- [ ] Login inicial feito e senha do admin trocada

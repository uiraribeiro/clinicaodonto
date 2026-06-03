podman stop odonto_php odonto_redis odonto_mysql odonto_nginx
podman rm odonto_redis odonto_mysql odonto_nginx odonto_php

podman run -d \
  --name odonto_php \
  -v /certificacao/clinicaodonto:/var/www/html:Z \
  -v /certificacao/clinicaodonto/docker/php/php.ini:/usr/local/etc/php/conf.d/custom.ini:ro,Z \
  -v /certificacao/clinicaodonto/storage:/var/www/html/storage \
  -e APP_ENV="${APP_ENV:-development}" \
  -p 9001:9000 \
  --network universo-network \
  --restart unless-stopped \
  odonto_php:latest

podman run -d \
  --name odonto_mysql \
  --label io.podman.compose.project=clinicaodonto \
  --label com.docker.compose.service=mysql \
  -e MYSQL_ROOT_PASSWORD='a44c2116cac4f5bb4f13d' \
  -e MYSQL_DATABASE='odonto_scheduler' \
  -e MYSQL_USER='odonto_user' \
  -e MYSQL_PASSWORD='71d87704c1d3ebd46fda' \
  -v clinicaodonto_mysql_data:/var/lib/mysql:Z \
  -v /certificacao/clinicaodonto/docker/mysql/my.cnf:/etc/mysql/conf.d/custom.cnf:ro,Z \
  --network universo-network \
  --network-alias mysql \
  -p 3306:3306 \
  --restart unless-stopped \
  --health-cmd="mysqladmin ping -h localhost -u root -pa44c2116cac4f5bb4f13d" \
  --health-interval=10s \
  --health-timeout=5s \
  --health-retries=5 \
  mysql:8.0

podman run -d \
  --name odonto_redis \
  --label io.podman.compose.project=clinicaodonto \
  --label com.docker.compose.service=redis \
  -v clinicaodonto_redis_data:/data:Z \
  --network universo-network \
  --network-alias redis \
  --restart unless-stopped \
  --health-cmd="redis-cli -a 4d025c68d723650dda ping" \
  --health-interval=10s \
  --health-timeout=3s \
  --health-retries=3 \
  redis:7-alpine \
  redis-server \
  --appendonly yes \
  --requirepass 4d025c68d723650dda \
  --maxmemory 128mb \
  --maxmemory-policy allkeys-lru

podman run -d \
  --name odonto_nginx \
  -v /certificacao/clinicaodonto:/var/www/html:Z \
  -v /certificacao/clinicaodonto/docker/nginx/default.conf:/etc/nginx/conf.d/default.conf:ro,Z \
  -v /certificacao/clinicaodonto/docker/nginx/certs:/etc/nginx/certs:ro,Z \
  --network universo-network \
  -p 8081:80 \
  --restart unless-stopped \
  nginx:1.25-alpine

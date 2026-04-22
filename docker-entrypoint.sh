#!/bin/bash
set -e

PORT=${PORT:-80}
echo "[entrypoint] Iniciando Apache na porta ${PORT}"

# Garante subdiretórios de uploads (necessário quando volume é montado vazio)
mkdir -p /var/www/html/uploads/imagens /var/www/html/uploads/pedidos
chown -R www-data:www-data /var/www/html/uploads

# Ajusta a porta do Apache para o valor dinâmico do Railway
sed -i "s/Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" \
    /etc/apache2/sites-available/000-default.conf

# Mostra quais MPMs estão ativos (ajuda a diagnosticar conflitos)
echo "[entrypoint] MPMs em mods-enabled:"
ls /etc/apache2/mods-enabled/ | grep mpm || echo "(nenhum mpm encontrado)"

exec apache2-foreground

#!/bin/bash
set -e

PORT=${PORT:-80}

# Garante subdiretórios de uploads (necessário quando volume é montado vazio)
mkdir -p /var/www/html/uploads/imagens /var/www/html/uploads/pedidos
chown -R www-data:www-data /var/www/html/uploads

# Ajusta a porta do Apache para o valor dinâmico do Railway
sed -i "s/Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" \
    /etc/apache2/sites-available/000-default.conf

exec apache2-foreground

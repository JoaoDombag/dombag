#!/bin/bash
set -e

PORT=${PORT:-80}

# ── Corrige MPM em runtime ────────────────────────────────────────────────────
# Remove event/worker e garante que só o prefork (compatível com mod_php) fique ativo.
# Feito aqui (não só no build) para ser imune ao cache do Railway.
find /etc/apache2/mods-enabled/ -name "mpm_event*"  -delete 2>/dev/null || true
find /etc/apache2/mods-enabled/ -name "mpm_worker*" -delete 2>/dev/null || true

if [ ! -f /etc/apache2/mods-enabled/mpm_prefork.load ]; then
    a2enmod mpm_prefork
fi

echo "[entrypoint] porta=${PORT} mpm=$(ls /etc/apache2/mods-enabled/ | grep mpm | tr '\n' ' ')"

# ── Uploads ───────────────────────────────────────────────────────────────────
mkdir -p /var/www/html/uploads/imagens /var/www/html/uploads/pedidos
chown -R www-data:www-data /var/www/html/uploads

# ── Porta dinâmica do Railway ─────────────────────────────────────────────────
sed -i "s/Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost *:${PORT}>/" \
    /etc/apache2/sites-available/000-default.conf

exec apache2-foreground

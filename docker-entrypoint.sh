#!/bin/bash
set -e

# ── Corrige MPM em runtime ────────────────────────────────────────────────────
# Remove event/worker e garante que só o prefork (compatível com mod_php) fique ativo.
find /etc/apache2/mods-enabled/ -name "mpm_event*"  -delete 2>/dev/null || true
find /etc/apache2/mods-enabled/ -name "mpm_worker*" -delete 2>/dev/null || true

if [ ! -f /etc/apache2/mods-enabled/mpm_prefork.load ]; then
    a2enmod mpm_prefork
fi

echo "[entrypoint] mpm=$(ls /etc/apache2/mods-enabled/ | grep mpm | tr '\n' ' ')"

# ── Uploads ───────────────────────────────────────────────────────────────────
mkdir -p /var/www/html/uploads/imagens /var/www/html/uploads/pedidos
chown -R www-data:www-data /var/www/html/uploads

exec apache2-foreground

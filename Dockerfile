FROM php:8.2-apache

# Dependências do sistema
RUN apt-get update && apt-get install -y \
        libpq-dev \
        unzip \
    && rm -rf /var/lib/apt/lists/*

# Extensões PHP (MySQL + PostgreSQL para o ERP Yzidro)
RUN docker-php-ext-install pdo pdo_mysql pgsql

# Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Remove fisicamente os módulos MPM conflitantes e garante só o mpm_prefork
RUN rm -f /etc/apache2/mods-enabled/mpm_event.conf \
          /etc/apache2/mods-enabled/mpm_event.load \
          /etc/apache2/mods-enabled/mpm_worker.conf \
          /etc/apache2/mods-enabled/mpm_worker.load \
    && a2enmod mpm_prefork rewrite

# Permite que o .htaccess funcione
RUN sed -i 's/AllowOverride None/AllowOverride All/g' /etc/apache2/apache2.conf

# Copia os arquivos da aplicação
COPY . /var/www/html/

# Instala dependências PHP (dompdf etc.) — vendor/ está no .gitignore
RUN cd /var/www/html \
    && composer install --no-dev --no-interaction --optimize-autoloader

# Remove secrets.php se existir (produção usa variáveis de ambiente)
RUN rm -f /var/www/html/config/secrets.php

# Ajusta permissões
RUN chown -R www-data:www-data /var/www/html \
    && find /var/www/html -type d -exec chmod 755 {} \; \
    && find /var/www/html -type f -exec chmod 644 {} \;

# Script de inicialização
COPY docker-entrypoint.sh /docker-entrypoint.sh
RUN sed -i 's/\r$//' /docker-entrypoint.sh && chmod +x /docker-entrypoint.sh

CMD ["/docker-entrypoint.sh"]

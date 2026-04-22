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

# Habilita mod_rewrite (necessário para as rotas do .htaccess)
RUN a2enmod rewrite

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

# Railway define a variável PORT dinamicamente — Apache precisa ouvir nela.
# O mkdir garante que os subdiretórios de uploads existam mesmo com volume vazio.
CMD mkdir -p /var/www/html/uploads/imagens /var/www/html/uploads/pedidos \
    && chown -R www-data:www-data /var/www/html/uploads \
    && sed -i "s/80/${PORT:-80}/g" /etc/apache2/ports.conf \
                                   /etc/apache2/sites-available/000-default.conf \
    && apache2-foreground

FROM php:8.4-apache

RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo pdo_pgsql \
    && rm -rf /var/lib/apt/lists/*
RUN a2enmod rewrite

# Apache serves from /var/www/html/public, rewrite rules handle routing
ENV APACHE_DOCUMENT_ROOT=/var/www/html/public
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf \
    && sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

# Allow .htaccess overrides only for the public document root
RUN printf '<Directory /var/www/html/public>\n    AllowOverride All\n</Directory>\n' \
      > /etc/apache2/conf-available/public-htaccess.conf \
    && a2enconf public-htaccess

# Set ServerName to avoid localhost detection by the Router
RUN echo 'ServerName bibleget-api' >> /etc/apache2/apache2.conf

# Use non-privileged port and run as www-data
RUN sed -ri -e 's/Listen 80/Listen 8080/' /etc/apache2/ports.conf \
    && sed -ri -e 's/:80>/:8080>/' /etc/apache2/sites-available/*.conf
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

RUN chown -R www-data:www-data /var/www/html /var/run/apache2 /var/log/apache2 /var/lock/apache2
EXPOSE 8080
USER www-data

ENTRYPOINT ["docker-entrypoint.sh"]

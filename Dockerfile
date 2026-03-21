FROM php:8.4-apache

RUN docker-php-ext-install mysqli && docker-php-ext-enable mysqli
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

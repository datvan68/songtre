FROM php:7.4-apache

# Install PDO MySQL extension and rewrite engine for Apache
RUN docker-php-ext-install pdo pdo_mysql \
    && a2enmod rewrite

# Set DocumentRoot to /var/www/html/public_html
ENV APACHE_DOCUMENT_ROOT /var/www/html/public_html

RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/sites-available/*.conf
RUN sed -ri -e 's!/var/www/html!${APACHE_DOCUMENT_ROOT}!g' /etc/apache2/apache2.conf /etc/apache2/conf-available/*.conf

WORKDIR /var/www/html

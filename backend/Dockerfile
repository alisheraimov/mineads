FROM php:8.4-cli-alpine

WORKDIR /var/www/html

RUN apk add --no-cache \
        bash \
        git \
        libpq-dev \
        unzip \
        $PHPIZE_DEPS \
    && docker-php-ext-install \
        bcmath \
        pcntl \
        pdo_pgsql \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del $PHPIZE_DEPS

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY composer.json composer.lock ./
RUN composer install --no-interaction --prefer-dist --optimize-autoloader --no-scripts

COPY . .

COPY docker/entrypoint.sh /usr/local/bin/mineads-entrypoint

RUN composer dump-autoload --optimize

EXPOSE 8000

ENTRYPOINT ["sh", "/usr/local/bin/mineads-entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]

FROM dunglas/frankenphp:1.12.1-php8.5

RUN mkdir -p /app/data

COPY .runtime/uploads.ini /usr/local/etc/php/conf.d/uploads.ini
COPY . /app
WORKDIR /app
COPY .runtime/Caddyfile /etc/frankenphp/Caddyfile

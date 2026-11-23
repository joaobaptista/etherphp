FROM php:8.4-fpm-alpine

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/

RUN apk add --no-cache \
    curl \
    zip \
    unzip \
    ffmpeg \
    imagemagick \
    libheif \
    nodejs \
    npm

RUN install-php-extensions pdo_pgsql mbstring exif pcntl bcmath gd imagick intl redis

COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

WORKDIR /var/www/html

ARG USER_ID=1000
ARG GROUP_ID=1000
RUN addgroup -g ${GROUP_ID} appgroup && \
    adduser -D -u ${USER_ID} -G appgroup appuser

RUN chown -R appuser:appgroup /var/www/html
USER appuser

EXPOSE 9000
CMD ["php-fpm"]

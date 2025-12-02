FROM php:8.2-fpm-alpine

# Install PostgreSQL extensions and common dependencies
RUN apk add --no-cache \
    postgresql-dev \
    icu-dev \
    libzip-dev \
    && docker-php-ext-install \
        pdo \
        pdo_pgsql \
        intl \
        zip

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Set user
RUN addgroup -g 1000 stonescript && \
    adduser -D -u 1000 -G stonescript stonescript

USER stonescript

LABEL maintainer="StoneScriptPHP Team <team@stonescriptphp.org>"
LABEL org.opencontainers.image.source="https://github.com/stonescriptphp/stonescriptphp"
LABEL org.opencontainers.image.description="PHP runtime for StoneScriptPHP applications"

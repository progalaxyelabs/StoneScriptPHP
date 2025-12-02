FROM php:8.3-cli-alpine

# Install dependencies
RUN apk add --no-cache \
    postgresql-dev \
    openssl \
    git \
    unzip

# Install PHP extensions
RUN docker-php-ext-install pdo pdo_pgsql

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Copy application files
COPY . .

# Install dependencies
RUN composer install --no-dev --optimize-autoloader

# Generate JWT keypair if not exists
RUN if [ ! -f stone-script-php-jwt.pem ]; then \
        ./generate-openssl-keypair.sh; \
    fi

# Expose port
EXPOSE 9100

# Start server
CMD ["php", "stone", "serve"]

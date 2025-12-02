# StoneScriptPHP PHP Runtime Docker Image

Official PHP runtime base image for StoneScriptPHP applications.

## Image Details

- **Base Image:** `php:8.2-fpm-alpine`
- **PHP Version:** 8.2
- **Extensions:** PDO, PDO_PostgreSQL, Intl, Zip
- **Composer:** Latest version included
- **User:** stonescript (UID 1000, GID 1000)
- **Working Directory:** `/var/www/html`

## Usage

### Pull from Docker Hub

```bash
# Latest version
docker pull stonescriptphp/php-runtime:latest

# Specific PHP version
docker pull stonescriptphp/php-runtime:8.2
```

### Using in Dockerfile

```dockerfile
FROM stonescriptphp/php-runtime:8.2

# Copy your application
COPY . /var/www/html

# Install dependencies
RUN composer install --no-dev --optimize-autoloader

# Your app-specific configuration
CMD ["php-fpm"]
```

### Using in docker-compose.yml

```yaml
version: '3.8'

services:
  api:
    image: stonescriptphp/php-runtime:8.2
    volumes:
      - ./api:/var/www/html
    working_dir: /var/www/html
    command: php-fpm
    networks:
      - stonescript-network

networks:
  stonescript-network:
    driver: bridge
```

## Building Locally

To build this image locally:

```bash
cd /path/to/StoneScriptPHP
bash scripts/build-images.sh
```

To build with a specific version tag:

```bash
bash scripts/build-images.sh 1.0.0
```

## Publishing to Docker Hub

### Prerequisites

1. Docker Hub account with access to `stonescriptphp` organization
2. Docker Hub access token

### Login to Docker Hub

```bash
docker login -u stonescriptphp
# Enter your access token when prompted
```

### Push Images

```bash
# Push all tags
docker push stonescriptphp/php-runtime:latest
docker push stonescriptphp/php-runtime:8.2

# Or use the build script which provides push instructions
bash scripts/build-images.sh 1.0.0
```

### Automated Publishing with GitHub Actions

Add Docker Hub credentials to GitHub Secrets:
- `DOCKERHUB_USERNAME`: stonescriptphp
- `DOCKERHUB_TOKEN`: Your Docker Hub access token

The GitHub Actions workflow will automatically build and push images on version tags.

## Installed Extensions

- **pdo** - PHP Data Objects
- **pdo_pgsql** - PostgreSQL driver for PDO
- **intl** - Internationalization extension
- **zip** - ZIP archive support

## Security

- Runs as non-root user `stonescript` (UID 1000)
- Alpine Linux base for minimal attack surface
- Regular updates with PHP security patches

## Support

- **GitHub:** https://github.com/stonescriptphp/stonescriptphp
- **Documentation:** https://stonescriptphp.org/docs
- **Issues:** https://github.com/stonescriptphp/stonescriptphp/issues

## License

MIT License - See LICENSE file in the repository

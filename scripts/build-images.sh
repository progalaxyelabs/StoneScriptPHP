#!/bin/bash
#
# Build script for StoneScriptPHP Docker images
# Usage: ./scripts/build-images.sh [VERSION]
#
# Examples:
#   ./scripts/build-images.sh        # Build with 'latest' tag
#   ./scripts/build-images.sh 1.0.0  # Build with version 1.0.0
#

set -e

# Configuration
DOCKER_NAMESPACE="stonescriptphp"
IMAGE_NAME="php-runtime"
PHP_VERSION="8.2"

# Get version from argument or use 'latest'
VERSION="${1:-latest}"

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# Get script directory
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

echo -e "${GREEN}Building StoneScriptPHP Docker Images${NC}"
echo "=========================================="
echo "Namespace:     ${DOCKER_NAMESPACE}"
echo "Image:         ${IMAGE_NAME}"
echo "PHP Version:   ${PHP_VERSION}"
echo "Tag Version:   ${VERSION}"
echo "=========================================="
echo ""

# Build php-runtime image
echo -e "${YELLOW}Building php-runtime image...${NC}"
cd "$PROJECT_ROOT/docker/php-runtime"

docker build \
    -t "${DOCKER_NAMESPACE}/${IMAGE_NAME}:${VERSION}" \
    -t "${DOCKER_NAMESPACE}/${IMAGE_NAME}:${PHP_VERSION}" \
    .

if [ "$VERSION" != "latest" ]; then
    docker tag "${DOCKER_NAMESPACE}/${IMAGE_NAME}:${VERSION}" \
               "${DOCKER_NAMESPACE}/${IMAGE_NAME}:latest"
fi

echo -e "${GREEN}✓ Successfully built ${DOCKER_NAMESPACE}/${IMAGE_NAME}:${VERSION}${NC}"
echo ""

# Display built images
echo -e "${YELLOW}Built images:${NC}"
docker images | grep "${DOCKER_NAMESPACE}/${IMAGE_NAME}" | head -3

echo ""
echo -e "${GREEN}Build complete!${NC}"
echo ""
echo "To push images to Docker Hub:"
echo "  docker login"
echo "  docker push ${DOCKER_NAMESPACE}/${IMAGE_NAME}:${VERSION}"
echo "  docker push ${DOCKER_NAMESPACE}/${IMAGE_NAME}:${PHP_VERSION}"
if [ "$VERSION" != "latest" ]; then
    echo "  docker push ${DOCKER_NAMESPACE}/${IMAGE_NAME}:latest"
fi

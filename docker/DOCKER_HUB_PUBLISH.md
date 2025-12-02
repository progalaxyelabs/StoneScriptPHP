# Docker Hub Publishing Guide for StoneScriptPHP

This guide covers publishing StoneScriptPHP Docker images to Docker Hub under the `stonescriptphp` organization.

## Prerequisites

### 1. Docker Hub Organization Setup

- Organization name: `stonescriptphp`
- Organization URL: https://hub.docker.com/u/stonescriptphp
- Visibility: Public

### 2. Docker Hub Access Token

Create an access token with push permissions:

1. Go to https://hub.docker.com/settings/security
2. Click "New Access Token"
3. Name: `stonescriptphp-ci` (or similar)
4. Permissions: Read & Write
5. Copy the token (you won't see it again)

### 3. Local Docker Setup

```bash
# Login to Docker Hub
docker login -u stonescriptphp
# Paste your access token when prompted for password
```

## Manual Publishing

### Step 1: Build the Image

```bash
cd /path/to/StoneScriptPHP
bash scripts/build-images.sh
```

This builds:
- `stonescriptphp/php-runtime:latest`
- `stonescriptphp/php-runtime:8.2`

### Step 2: Test the Image

```bash
# Run a test container
docker run --rm stonescriptphp/php-runtime:latest php -v

# Expected output:
# PHP 8.2.x (cli) (built: ...) (NTS)
# Copyright (c) The PHP Group
# Zend Engine v4.2.x, Copyright (c) Zend Technologies
```

### Step 3: Push to Docker Hub

```bash
# Push all tags
docker push stonescriptphp/php-runtime:latest
docker push stonescriptphp/php-runtime:8.2
```

### Step 4: Verify on Docker Hub

Visit: https://hub.docker.com/r/stonescriptphp/php-runtime

Check that both tags are visible:
- `latest`
- `8.2`

## Versioned Releases

When releasing a new version:

```bash
# Build with version tag
bash scripts/build-images.sh 1.0.0

# This creates:
# - stonescriptphp/php-runtime:1.0.0
# - stonescriptphp/php-runtime:8.2
# - stonescriptphp/php-runtime:latest

# Push all tags
docker push stonescriptphp/php-runtime:1.0.0
docker push stonescriptphp/php-runtime:8.2
docker push stonescriptphp/php-runtime:latest
```

## Automated Publishing with GitHub Actions

### Setup GitHub Secrets

Add these secrets to your GitHub repository:

1. Go to: https://github.com/stonescriptphp/stonescriptphp/settings/secrets/actions
2. Add secrets:
   - `DOCKERHUB_USERNAME`: `stonescriptphp`
   - `DOCKERHUB_TOKEN`: Your Docker Hub access token

### GitHub Actions Workflow

Create `.github/workflows/docker-publish.yml`:

```yaml
name: Publish Docker Images

on:
  push:
    tags:
      - 'v*'
  workflow_dispatch:

jobs:
  build-and-push:
    runs-on: ubuntu-latest
    steps:
      - name: Checkout code
        uses: actions/checkout@v4

      - name: Set up Docker Buildx
        uses: docker/setup-buildx-action@v3

      - name: Login to Docker Hub
        uses: docker/login-action@v3
        with:
          username: ${{ secrets.DOCKERHUB_USERNAME }}
          password: ${{ secrets.DOCKERHUB_TOKEN }}

      - name: Extract version from tag
        id: version
        run: |
          if [[ "${{ github.ref }}" == refs/tags/* ]]; then
            VERSION=${GITHUB_REF#refs/tags/v}
          else
            VERSION=latest
          fi
          echo "VERSION=$VERSION" >> $GITHUB_OUTPUT

      - name: Build and push php-runtime
        uses: docker/build-push-action@v5
        with:
          context: ./docker/php-runtime
          push: true
          tags: |
            stonescriptphp/php-runtime:${{ steps.version.outputs.VERSION }}
            stonescriptphp/php-runtime:8.2
            stonescriptphp/php-runtime:latest
          labels: |
            org.opencontainers.image.source=https://github.com/stonescriptphp/stonescriptphp
            org.opencontainers.image.description=PHP runtime for StoneScriptPHP applications
            org.opencontainers.image.version=${{ steps.version.outputs.VERSION }}
```

### Triggering Automated Builds

```bash
# Create and push a version tag
git tag -a v1.0.0 -m "Release version 1.0.0"
git push origin v1.0.0

# GitHub Actions will automatically:
# 1. Build the image
# 2. Tag it with version, PHP version, and latest
# 3. Push to Docker Hub
```

## Docker Hub Repository Configuration

### Repository Settings

1. **Description:**
   > PHP runtime base image for StoneScriptPHP applications. Includes PHP 8.2-FPM, PostgreSQL extensions, and Composer.

2. **README:**
   - Link to: `docker/php-runtime/README.md` in the GitHub repository
   - Or copy the content directly to Docker Hub

3. **Categories:**
   - Programming Languages
   - Base Images

4. **Source Repository:**
   - https://github.com/stonescriptphp/stonescriptphp

### Build Badges

Add to your main README.md:

```markdown
[![Docker Image Version](https://img.shields.io/docker/v/stonescriptphp/php-runtime?sort=semver)](https://hub.docker.com/r/stonescriptphp/php-runtime)
[![Docker Image Size](https://img.shields.io/docker/image-size/stonescriptphp/php-runtime/latest)](https://hub.docker.com/r/stonescriptphp/php-runtime)
[![Docker Pulls](https://img.shields.io/docker/pulls/stonescriptphp/php-runtime)](https://hub.docker.com/r/stonescriptphp/php-runtime)
```

## Multi-Architecture Support (Future)

To support multiple architectures (amd64, arm64):

```yaml
# In GitHub Actions workflow
- name: Build and push multi-arch
  uses: docker/build-push-action@v5
  with:
    context: ./docker/php-runtime
    platforms: linux/amd64,linux/arm64
    push: true
    tags: |
      stonescriptphp/php-runtime:latest
      stonescriptphp/php-runtime:8.2
```

## Troubleshooting

### Authentication Failed

```bash
# Re-login with access token
docker logout
docker login -u stonescriptphp
# Paste your access token
```

### Image Already Exists

- This is normal when pushing the same tag multiple times
- Docker Hub will update the existing image

### Rate Limiting

- Docker Hub allows 200 pulls per 6 hours for anonymous users
- Authenticated users get higher limits
- Paid accounts have unlimited pulls

## Image Maintenance

### Regular Updates

1. **Monthly:** Rebuild images to get latest Alpine and PHP security updates
2. **PHP Updates:** When new PHP 8.2.x versions are released
3. **Security Patches:** Immediately on CVE disclosures

### Deprecation Strategy

When moving to PHP 8.3:

1. Build new `stonescriptphp/php-runtime:8.3`
2. Update `latest` tag to point to 8.3
3. Keep 8.2 tag available for 6 months
4. Add deprecation notice to 8.2 description
5. Remove 8.2 after deprecation period

## Support

For issues with Docker images:
- GitHub Issues: https://github.com/stonescriptphp/stonescriptphp/issues
- Tag with: `docker`, `infrastructure`

## Security Scanning

Enable Docker Hub vulnerability scanning:

1. Go to repository settings
2. Enable "Security Scanning"
3. View scan results in "Tags" tab

Or use Docker Scout:

```bash
docker scout cves stonescriptphp/php-runtime:latest
```

## Checklist for First Publish

- [ ] Docker Hub organization created (`stonescriptphp`)
- [ ] Repository created (`stonescriptphp/php-runtime`)
- [ ] Access token generated and stored securely
- [ ] GitHub secrets configured (DOCKERHUB_USERNAME, DOCKERHUB_TOKEN)
- [ ] Local build successful
- [ ] Local test successful
- [ ] Pushed to Docker Hub
- [ ] Verified on Docker Hub
- [ ] README updated on Docker Hub
- [ ] GitHub Actions workflow tested
- [ ] Badges added to README

## Status: Ready to Publish

✅ **Dockerfile:** Updated with stonescriptphp namespace
✅ **Build Script:** Updated to use stonescriptphp/php-runtime
✅ **Local Build:** Successfully tested (image ID: 8613833a5e1e)
✅ **Documentation:** Complete

**Next Step:** Manual login to Docker Hub and push:

```bash
docker login -u stonescriptphp
docker push stonescriptphp/php-runtime:latest
docker push stonescriptphp/php-runtime:8.2
```

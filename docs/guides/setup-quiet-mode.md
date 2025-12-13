# Setup Command - Quiet Mode

The `php stone setup` command now supports a `--quiet` (or `-q`) flag for non-interactive setup.

## Usage

### Interactive Mode (Default)
```bash
php stone setup
```

Prompts for all configuration interactively:
- Project name
- Database credentials
- JWT configuration (issuer, expiry times, key paths, passphrase)
- CORS origins

### Quiet Mode
```bash
php stone setup --quiet
# or
php stone setup -q
```

Runs silently without user prompts:
- **If `.env` exists**: Uses existing values
- **If `.env` missing**: Creates `.env` with sensible defaults
- **If keys exist**: Skips key generation
- **If keys missing**: Generates new keys automatically

## Behavior in Quiet Mode

### 1. Configuration Loading

**When `.env` exists:**
```bash
php stone setup --quiet
# Loads all values from existing .env
# JWT_ISSUER: example.com (from .env)
# JWT_ACCESS_TOKEN_EXPIRY: 900 (from .env)
# etc.
```

**When `.env` is missing:**
```bash
php stone setup --quiet
# Creates .env with defaults:
# JWT_ISSUER: example.com
# JWT_ACCESS_TOKEN_EXPIRY: 900
# JWT_REFRESH_TOKEN_EXPIRY: 15552000
# DATABASE_HOST: localhost
# DATABASE_PORT: 5432
# etc.
```

### 2. Key Generation

**When keys exist:**
```bash
php stone setup --quiet
# Skips key generation silently
```

**When keys are missing:**
```bash
php stone setup --quiet
# Generates new RSA keypair silently
# No passphrase protection (use interactive mode for this)
```

### 3. Output

**Normal mode:**
```
╔═══════════════════════════════════════╗
║   StoneScriptPHP Project Setup        ║
╚═══════════════════════════════════════╝

📝 Generating .env file...

Project name [My API]:
...
✅ JWT keypair generated!

🎉 Setup complete!
```

**Quiet mode:**
```
(no output - completely silent)
```

## Use Cases

### 1. CI/CD Pipelines
```bash
# In your CI/CD script
php stone setup --quiet
# Uses defaults or existing .env
# No user interaction required
```

### 2. Docker Builds
```dockerfile
# Dockerfile
FROM php:8.2-cli
COPY . /app
WORKDIR /app
RUN composer install
RUN php stone setup --quiet
# Generates default config automatically
```

### 3. Automation Scripts
```bash
#!/bin/bash
# deploy.sh

# Setup without prompts
php stone setup --quiet

# Continue with deployment
php stone migrate
php stone serve
```

### 4. Testing Environments
```bash
# test-setup.sh
#!/bin/bash

# Create test .env
cat > .env << EOF
DATABASE_HOST=localhost
DATABASE_DBNAME=test_db
JWT_ISSUER=test.example.com
EOF

# Setup with existing config
php stone setup --quiet

# Run tests
phpunit
```

## Defaults Used in Quiet Mode

When creating a new `.env` file, these defaults are used:

```env
# Application
APP_NAME=My API
APP_ENV=development
APP_PORT=9100

# Database
DATABASE_HOST=localhost
DATABASE_PORT=5432
DATABASE_DBNAME=stonescriptphp
DATABASE_USER=postgres
DATABASE_PASSWORD=

# JWT
JWT_ISSUER=example.com
JWT_ACCESS_TOKEN_EXPIRY=900
JWT_REFRESH_TOKEN_EXPIRY=15552000
JWT_PRIVATE_KEY_PATH=./keys/jwt-private.pem
JWT_PUBLIC_KEY_PATH=./keys/jwt-public.pem
JWT_PRIVATE_KEY_PASSPHRASE=

# CORS
ALLOWED_ORIGINS=http://localhost:3000,http://localhost:4200
```

## Customizing Quiet Mode Behavior

### Pre-configure .env for Quiet Setup

1. **Create `.env` with your values:**
```bash
cat > .env << 'EOF'
DATABASE_HOST=prod-db.example.com
DATABASE_DBNAME=myapp
JWT_ISSUER=myapp.com
JWT_ACCESS_TOKEN_EXPIRY=1800
JWT_PRIVATE_KEY_PATH=./custom-key.pem
JWT_PUBLIC_KEY_PATH=./custom-key.pub
JWT_PRIVATE_KEY_PASSPHRASE=my-secret-pass
EOF
```

2. **Copy your existing keys:**
```bash
cp /path/to/custom-key.pem ./
cp /path/to/custom-key.pub ./
```

3. **Run quiet setup:**
```bash
php stone setup --quiet
# Uses your custom configuration
# Skips key generation (keys already exist)
```

## Migration Example

### Migrating ProGalaxy Project

```bash
#!/bin/bash
# migrate-progalaxy.sh

# 1. Copy existing ProGalaxy keys
cp ../progalaxy-platform/api/progalaxylocalkey.pem ./
cp ../progalaxy-platform/api/progalaxylocalkey.pub ./

# 2. Create .env with ProGalaxy settings
cat > .env << 'EOF'
DATABASE_HOST=localhost
DATABASE_DBNAME=progalaxy_db
DATABASE_USER=postgres
DATABASE_PASSWORD=your-password

JWT_PRIVATE_KEY_PATH=./progalaxylocalkey.pem
JWT_PUBLIC_KEY_PATH=./progalaxylocalkey.pub
JWT_PRIVATE_KEY_PASSPHRASE=12345678
JWT_ISSUER=progalaxy.in
JWT_ACCESS_TOKEN_EXPIRY=900
JWT_REFRESH_TOKEN_EXPIRY=15552000

ALLOWED_ORIGINS=https://progalaxy.in
EOF

# 3. Run setup in quiet mode
php stone setup --quiet

echo "✅ ProGalaxy migration complete!"
echo "JWT tokens will be compatible with existing ProGalaxy auth"
```

## Comparison

| Feature | Interactive Mode | Quiet Mode |
|---------|-----------------|------------|
| User prompts | ✅ Yes | ❌ No |
| Uses existing .env | ✅ Yes | ✅ Yes |
| Creates default .env | ❌ No (asks) | ✅ Yes |
| Generates keys | ✅ Yes | ✅ Yes (if missing) |
| Passphrase support | ✅ Yes (asks) | ❌ No (needs pre-config) |
| Output | ✅ Verbose | ❌ Silent |
| Best for | New projects | CI/CD, Docker, Scripts |

## Notes

- **Passphrase-protected keys**: Must be configured manually in `.env` before running quiet mode
- **Security**: Review generated `.env` and update passwords before production
- **Keys**: Generated keys are 2048-bit RSA with SHA-256 digest
- **Permissions**: Private key is automatically set to 600 (owner read/write only)

## See Also

- [JWT Configuration Guide](./jwt-configuration.md)
- [Environment Variables](./environment-variables.md)
- [CI/CD Integration](./ci-cd-integration.md)

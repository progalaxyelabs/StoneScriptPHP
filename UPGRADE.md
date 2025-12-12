# StoneScriptPHP Upgrade Guide

## Quick Upgrade (Recommended)

### 1. Update Framework
```bash
composer update progalaxyelabs/stonescriptphp
```

### 2. Update CLI Tools
```bash
php stone upgrade
```

### 3. Check Release Notes
After upgrading, check the [releases page](https://github.com/progalaxyelabs/StoneScriptPHP/releases) for breaking changes.

---

## Upgrading to v2.x (JWT Authentication Changes)

### New JWT Setup

Version 2.x introduces improved JWT authentication setup:

#### Option 1: Use New JWT Command (Recommended)
```bash
php stone generate jwt
```

This will:
- Generate RSA public/private keypair
- Create keys/ directory with proper permissions
- Update your .env file

#### Option 2: Manual Setup

**For HMAC (Simple, Most Projects):**

1. Generate a secret:
```bash
php -r "echo bin2hex(random_bytes(32));"
```

2. Add to `.env`:
```bash
JWT_SECRET=your-generated-secret-here
JWT_EXPIRY=3600
```

**For RSA (Advanced, Microservices):**

1. Generate keys:
```bash
ssh-keygen -t rsa -m pkcs8 -f keys/jwt-private.pem -N ""
ssh-keygen -f keys/jwt-private.pem -e -m pkcs8 > keys/jwt-public.pem
chmod 600 keys/jwt-private.pem
```

2. Add to `.env`:
```bash
JWT_PRIVATE_KEY_PATH=./keys/jwt-private.pem
JWT_PUBLIC_KEY_PATH=./keys/jwt-public.pem
JWT_EXPIRY=3600
```

### Migrating from Old JWTAuth Class

If you're using the old `Framework\Lib\Auth\JWTAuth` class:

**Before:**
```php
use Framework\Lib\Auth\JWTAuth;

list($accessToken, $refreshToken) = JWTAuth::create_tokens($userId);
$claims = (new JWTAuth())->decodeToken($token);
```

**After (HMAC):**
```php
use Framework\Auth\JwtHandler;

$jwt = new JwtHandler();
$accessToken = $jwt->generateToken(['user_id' => $userId], 0.0104); // 15 min
$refreshToken = $jwt->generateToken(['user_id' => $userId], 30);    // 30 days
$payload = $jwt->verifyToken($token);
```

**After (RSA - keeps similar behavior):**
```php
use Framework\Auth\RsaJwtHandler;

$jwt = new RsaJwtHandler();
$token = $jwt->generateToken(['user_id' => $userId]);
$payload = $jwt->verifyToken($token);
```

### Update .env.example (Optional)

Update your `.env.example` to include JWT placeholders for new team members:

```bash
# JWT Authentication
JWT_SECRET=your-secret-key-min-32-chars-change-this
# OR use RSA keys (more secure for distributed systems):
# JWT_PRIVATE_KEY_PATH=./keys/jwt-private.pem
# JWT_PUBLIC_KEY_PATH=./keys/jwt-public.pem
JWT_EXPIRY=3600
```

---

## Version-Specific Guides

### v2.1.0 → v2.2.0
- Added `php stone generate jwt` command
- Improved JWT documentation
- No breaking changes

### v2.0.0 → v2.1.0
- Introduced new JwtHandler classes
- Old JWTAuth class deprecated but still works
- Recommend migrating to new classes

---

## Troubleshooting

### "Command 'upgrade' not found"
Update your `stone` CLI script:
```bash
curl -o stone https://raw.githubusercontent.com/progalaxyelabs/StoneScriptPHP-Server/main/stone
chmod +x stone
```

### "JWT_SECRET not set" Error
Run: `php stone generate jwt` or manually add to `.env`

### Upgrade Failed
1. Check backups created by upgrade tool (`.backup-*` files)
2. Restore from backup if needed
3. Report issue on GitHub

---

## Manual Upgrade (Advanced)

If `php stone upgrade` doesn't work:

1. **Backup your project**:
```bash
cp stone stone.backup
cp -r cli cli.backup
```

2. **Download latest files**:
```bash
# Get latest stone CLI
curl -o stone https://raw.githubusercontent.com/progalaxyelabs/StoneScriptPHP-Server/main/stone
chmod +x stone

# Update CLI scripts
curl -o cli/generate-jwt-keys.php https://raw.githubusercontent.com/progalaxyelabs/StoneScriptPHP/main/cli/generate-jwt-keys.php
```

3. **Update framework**:
```bash
composer update progalaxyelabs/stonescriptphp
```

---

## Getting Help

- Documentation: https://stonescriptphp.org/docs
- GitHub Issues: https://github.com/progalaxyelabs/StoneScriptPHP/issues
- Release Notes: https://github.com/progalaxyelabs/StoneScriptPHP/releases

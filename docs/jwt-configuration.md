# JWT Configuration Guide

StoneScriptPHP supports flexible JWT (JSON Web Token) configuration compatible with custom implementations like ProGalaxy's authentication system.

## Table of Contents

- [Quick Start](#quick-start)
- [Interactive Setup](#interactive-setup)
- [Configuration Options](#configuration-options)
- [Custom Keys & Passphrases](#custom-keys--passphrases)
- [ProGalaxy Compatibility](#progalaxy-compatibility)
- [Usage Examples](#usage-examples)

## Quick Start

Run the interactive setup:

```bash
php stone setup
```

The CLI will ask for all JWT configuration interactively and store it in `.env`.

## Interactive Setup

When you run `php stone setup`, you'll be prompted for:

### 1. JWT Issuer
```
JWT issuer (your domain) [example.com]: progalaxy.in
```

The `iss` claim in your JWT tokens. Use your domain name.

### 2. Token Expiry Times
```
Access token expiry (seconds) [900]: 900
Refresh token expiry (seconds) [15552000]: 15552000
```

- **Access tokens**: Short-lived (default: 15 minutes = 900 seconds)
- **Refresh tokens**: Long-lived (default: 180 days = 15552000 seconds)

### 3. Key File Paths
```
JWT private key path [./keys/jwt-private.pem]: ./progalaxylocalkey.pem
JWT public key path [./keys/jwt-public.pem]: ./progalaxylocalkey.pub
```

Supports custom paths for compatibility with existing projects.

### 4. Passphrase Protection
```
Use passphrase-protected private key? (yes/no) [no]: yes
Enter passphrase for private key: ********
```

If you have an encrypted private key, provide the passphrase here.

## Configuration Options

All JWT settings are stored in `.env`:

```env
# JWT Configuration
JWT_PRIVATE_KEY_PATH=./keys/jwt-private.pem
JWT_PUBLIC_KEY_PATH=./keys/jwt-public.pem
JWT_PRIVATE_KEY_PASSPHRASE=your-secret-passphrase
JWT_ISSUER=example.com
JWT_ACCESS_TOKEN_EXPIRY=900
JWT_REFRESH_TOKEN_EXPIRY=15552000
```

### Environment Variables

| Variable | Type | Default | Description |
|----------|------|---------|-------------|
| `JWT_PRIVATE_KEY_PATH` | string | `./keys/jwt-private.pem` | Path to RSA private key (relative or absolute) |
| `JWT_PUBLIC_KEY_PATH` | string | `./keys/jwt-public.pem` | Path to RSA public key (relative or absolute) |
| `JWT_PRIVATE_KEY_PASSPHRASE` | string | `null` | Passphrase for encrypted private key (optional) |
| `JWT_ISSUER` | string | `example.com` | JWT issuer claim (`iss`) - use your domain |
| `JWT_ACCESS_TOKEN_EXPIRY` | int | `900` | Access token expiry in seconds (15 min) |
| `JWT_REFRESH_TOKEN_EXPIRY` | int | `15552000` | Refresh token expiry in seconds (180 days) |

## Custom Keys & Passphrases

### Generating Passphrase-Protected Keys

```bash
# Generate encrypted private key
openssl genrsa -aes256 -out keys/jwt-private.pem 2048

# Extract public key
openssl rsa -in keys/jwt-private.pem -pubout -out keys/jwt-public.pem

# Set permissions
chmod 600 keys/jwt-private.pem
```

### Using Existing Keys

If you already have JWT keys (e.g., from another project):

1. **Copy your keys** to the project directory
2. **Update `.env`** with the correct paths:
   ```env
   JWT_PRIVATE_KEY_PATH=./my-custom-key.pem
   JWT_PUBLIC_KEY_PATH=./my-custom-key.pub
   ```
3. **Add passphrase** if encrypted:
   ```env
   JWT_PRIVATE_KEY_PASSPHRASE=your-passphrase-here
   ```

## ProGalaxy Compatibility

StoneScriptPHP is fully compatible with ProGalaxy's custom JWT implementation:

### ProGalaxy Configuration Example

```env
# ProGalaxy-style configuration
JWT_PRIVATE_KEY_PATH=./progalaxylocalkey.pem
JWT_PUBLIC_KEY_PATH=./progalaxylocalkey.pub
JWT_PRIVATE_KEY_PASSPHRASE=12345678
JWT_ISSUER=progalaxy.in
JWT_ACCESS_TOKEN_EXPIRY=900
JWT_REFRESH_TOKEN_EXPIRY=15552000
```

### Migration from ProGalaxy

If you're migrating from an existing ProGalaxy implementation:

1. **Copy your existing keys**:
   ```bash
   cp /path/to/progalaxylocalkey.pem ./progalaxylocalkey.pem
   cp /path/to/progalaxylocalkey.pub ./progalaxylocalkey.pub
   chmod 600 ./progalaxylocalkey.pem
   ```

2. **Run setup** and provide the paths:
   ```bash
   php stone setup
   ```

3. **Your tokens will be compatible** with your existing ProGalaxy auth system!

## Usage Examples

### Generating Tokens

```php
use Framework\Auth\RsaJwtHandler;

$jwtHandler = new RsaJwtHandler();

// Generate access token (uses JWT_ACCESS_TOKEN_EXPIRY from .env)
$accessToken = $jwtHandler->generateToken([
    'user_id' => 123,
    'email' => 'user@example.com'
], null, 'access');

// Generate refresh token (uses JWT_REFRESH_TOKEN_EXPIRY from .env)
$refreshToken = $jwtHandler->generateToken([
    'user_id' => 123
], null, 'refresh');

// Custom expiry (override .env defaults)
$customToken = $jwtHandler->generateToken([
    'user_id' => 123
], 3600); // 1 hour
```

### Verifying Tokens

```php
// Verify with issuer check (default)
$payload = $jwtHandler->verifyToken($token);

if ($payload === false) {
    // Invalid, expired, or issuer mismatch
    return res_error('Invalid token');
}

// Access token data
$userId = $payload['user_id'];

// Skip issuer verification (for compatibility)
$payload = $jwtHandler->verifyToken($token, verifyIssuer: false);
```

### Token Structure

Generated tokens include standard JWT claims:

```json
{
  "iss": "example.com",
  "iat": 1703001600,
  "exp": 1703005200,
  "data": {
    "user_id": 123,
    "email": "user@example.com"
  }
}
```

## Security Best Practices

### 1. **Protect Your Private Key**
```bash
chmod 600 keys/jwt-private.pem
```

Never commit private keys to version control!

### 2. **Use Strong Passphrases**
If using encrypted keys, choose a strong passphrase:
- At least 16 characters
- Mix of letters, numbers, symbols
- Store in `.env` (which is .gitignored)

### 3. **Rotate Keys Regularly**
Generate new keypairs periodically:
```bash
php stone setup  # Will generate new keys
```

### 4. **Separate Access & Refresh Tokens**
- **Access tokens**: Short-lived, store in memory
- **Refresh tokens**: Long-lived, store in httpOnly cookies

### 5. **Verify Issuer in Production**
Always verify the `iss` claim matches your domain:
```php
$payload = $jwtHandler->verifyToken($token, verifyIssuer: true);
```

## Troubleshooting

### "Unable to load private key for JWT signing"

**Cause**: Incorrect passphrase or key file not found

**Solution**:
1. Check `JWT_PRIVATE_KEY_PATH` points to the correct file
2. Verify `JWT_PRIVATE_KEY_PASSPHRASE` is correct
3. Ensure file permissions: `chmod 600 keys/jwt-private.pem`

### "JWT issuer mismatch"

**Cause**: Token was generated with different `JWT_ISSUER`

**Solution**:
1. Check `JWT_ISSUER` in `.env` matches the token's `iss` claim
2. Or disable issuer verification: `verifyToken($token, verifyIssuer: false)`

### "Cannot read private key file"

**Cause**: File path is incorrect or file doesn't exist

**Solution**:
1. Verify the path in `.env` is correct
2. Use absolute paths if needed: `JWT_PRIVATE_KEY_PATH=/absolute/path/to/key.pem`
3. Or use relative paths from project root: `JWT_PRIVATE_KEY_PATH=./keys/jwt-private.pem`

## Advanced Configuration

### Multiple Key Pairs

For microservices, you might need different keys:

```php
// Service A
$serviceAHandler = new RsaJwtHandler();
// Uses keys from .env

// Service B - custom keys
$env = \Framework\Env::get_instance();
$env->JWT_PRIVATE_KEY_PATH = './service-b-keys/private.pem';
$env->JWT_PUBLIC_KEY_PATH = './service-b-keys/public.pem';
$serviceBHandler = new RsaJwtHandler();
```

### Dynamic Token Expiry

Override expiry based on user role:

```php
$expiry = $user->isAdmin() ? 7200 : 900; // 2 hours vs 15 min

$token = $jwtHandler->generateToken([
    'user_id' => $user->id,
    'role' => $user->role
], $expiry);
```

## API Reference

See [RsaJwtHandler.php](../src/Auth/RsaJwtHandler.php) for complete API documentation.

### Key Methods

- `generateToken(array $payload, ?int $expirySeconds = null, string $tokenType = 'access'): string`
- `verifyToken(string $token, bool $verifyIssuer = true): array|false`

## See Also

- [Authentication Guide](./authentication.md)
- [Security Best Practices](./security-best-practices.md)
- [Multi-Tenancy Setup](./multi-tenancy.md)

# Email + Password Authentication Template

Tenant-aware email and password authentication for StoneScriptPHP with email verification.

## Features

- User registration with email and password
- **Email verification with DNS/MX validation**
- **Disposable email detection**
- User login with credentials validation
- Password reset flow with secure tokens
- Resend verification email
- Full multi-tenancy support (per-tenant DB and shared DB strategies)
- BCrypt password hashing (cost factor 12)
- JWT token generation
- Input validation with comprehensive email checks

## Installation

### 1. Database Setup

Run the migration to create the users table:

```bash
# For per-tenant database strategy
php stone tenant:migrate your-tenant-slug

# For shared database strategy
psql -h localhost -U your_user -d your_database -f users.pgsql.template
```

### 2. Copy Route Templates

Copy the route files to your application:

```bash
cp src/Templates/Auth/email-password/*.php.template app/Routes/Auth/
# Rename files to remove .template extension
```

### 3. Configure Email Service

Set up your email provider environment variables in `.env`:

```env
# ZeptoMail Configuration
ZEPTOMAIL_BOUNCE_ADDRESS=bounce@yourdomain.com
ZEPTOMAIL_SENDER_EMAIL=noreply@yourdomain.com
ZEPTOMAIL_SENDER_NAME=Your App Name
ZEPTOMAIL_SEND_MAIL_TOKEN=your_zepto_mail_token

# Application Configuration
APP_NAME=Your Application
APP_URL=https://yourapp.com
APP_ENV=production  # or 'development'
```

### 4. Register Routes

Add routes to your main application file (e.g., `public/index.php`):

```php
use App\Routes\Auth\RegisterRoute;
use App\Routes\Auth\LoginRoute;
use App\Routes\Auth\VerifyEmailRoute;
use App\Routes\Auth\ResendVerificationRoute;
use App\Routes\Auth\PasswordResetRoute;
use App\Routes\Auth\PasswordResetConfirmRoute;

// Public routes
$router->post('/api/auth/register', RegisterRoute::class);
$router->post('/api/auth/login', LoginRoute::class);
$router->get('/api/auth/verify-email', VerifyEmailRoute::class);
$router->post('/api/auth/resend-verification', ResendVerificationRoute::class);
$router->post('/api/auth/password-reset', PasswordResetRoute::class);
$router->post('/api/auth/password-reset/confirm', PasswordResetConfirmRoute::class);
```

## Usage

### Register a New User

```bash
curl -X POST http://localhost:8000/api/auth/register \
  -H "Content-Type: application/json" \
  -H "X-Tenant-Slug: acme" \
  -d '{
    "email": "user@example.com",
    "password": "secret123",
    "display_name": "John Doe"
  }'
```

Response:
```json
{
  "status": "success",
  "message": "Registration successful. Please check your email to verify your account.",
  "data": {
    "user_id": 1,
    "email": "user@example.com",
    "display_name": "John Doe",
    "email_verified": false,
    "verification_token": "abc123...",
    "verification_url": "http://localhost:8000/api/auth/verify-email?token=abc123..."
  }
}
```

**Note:** `verification_token` and `verification_url` are only returned in development mode (`APP_ENV=development`). Remove these in production.

### Verify Email

User clicks the link in their email, or you can call:

```bash
curl -X GET "http://localhost:8000/api/auth/verify-email?token=abc123..." \
  -H "X-Tenant-Slug: acme"
```

Response:
```json
{
  "status": "success",
  "message": "Email verified successfully",
  "data": {
    "user_id": 1,
    "email": "user@example.com",
    "display_name": "John Doe",
    "email_verified": true,
    "token": "eyJ0eXAiOiJKV1QiLCJhbGc..."
  }
}
```

After verification, user is automatically logged in with a JWT token.

### Resend Verification Email

If user didn't receive the verification email:

```bash
curl -X POST http://localhost:8000/api/auth/resend-verification \
  -H "Content-Type: application/json" \
  -H "X-Tenant-Slug: acme" \
  -d '{
    "email": "user@example.com"
  }'
```

Response:
```json
{
  "status": "success",
  "message": "Verification email sent. Please check your inbox.",
  "data": {
    "verification_token": "xyz789...",
    "verification_url": "http://localhost:8000/api/auth/verify-email?token=xyz789..."
  }
}
```

### Login

```bash
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -H "X-Tenant-Slug: acme" \
  -d '{
    "email": "user@example.com",
    "password": "secret123"
  }'
```

Response:
```json
{
  "status": "success",
  "message": "Login successful",
  "data": {
    "user_id": 1,
    "email": "user@example.com",
    "display_name": "John Doe",
    "token": "eyJ0eXAiOiJKV1QiLCJhbGc..."
  }
}
```

### Request Password Reset

```bash
curl -X POST http://localhost:8000/api/auth/password-reset \
  -H "Content-Type: application/json" \
  -H "X-Tenant-Slug: acme" \
  -d '{
    "email": "user@example.com"
  }'
```

Response:
```json
{
  "status": "success",
  "message": "If the email exists, a reset link will be sent",
  "data": {
    "reset_token": "abc123...",
    "expires_at": "2025-12-13 15:30:00"
  }
}
```

### Confirm Password Reset

```bash
curl -X POST http://localhost:8000/api/auth/password-reset/confirm \
  -H "Content-Type: application/json" \
  -H "X-Tenant-Slug: acme" \
  -d '{
    "token": "abc123...",
    "new_password": "newsecret456"
  }'
```

Response:
```json
{
  "status": "success",
  "message": "Password has been reset successfully"
}
```

## Email Validation

The registration process includes comprehensive email validation to reduce bounce rates and prevent spam:

### 1. Format Validation

Uses PHP's `filter_var()` with `FILTER_VALIDATE_EMAIL` (RFC 5322 compliant):

```php
use Framework\Lib\Email\EmailValidator;

$validator = new EmailValidator();
if (!$validator->validateFormat('user@example.com')) {
    // Invalid email format
}
```

### 2. DNS/MX Record Verification

Checks if the domain can actually receive emails:

```php
$validator = new EmailValidator();
if (!$validator->validateMxRecord('user@example.com')) {
    // Email domain doesn't have valid MX records
}
```

This prevents typos like `user@gmial.com` and ensures the domain is configured for email.

### 3. Disposable Email Detection

Blocks temporary/disposable email addresses:

```php
$validator = new EmailValidator();
if ($validator->isDisposable('user@tempmail.com')) {
    // Disposable email not allowed
}
```

Built-in list includes: tempmail.com, 10minutemail.com, guerrillamail.com, mailinator.com, and more.

### 4. Role-Based Email Detection (Optional)

Optionally block role-based emails like `admin@`, `info@`, `support@`:

```php
$validator = new EmailValidator();
if ($validator->isRoleBased('admin@example.com')) {
    // Role-based email not allowed
}
```

### Full Validation Example

```php
use Framework\Lib\Email\EmailValidator;

$validator = new EmailValidator();

// Standard validation (format + MX + disposable check)
if (!$validator->validate($email, true, true, false)) {
    echo $validator->getError();
    // Possible errors:
    // - "Invalid email format"
    // - "Email domain does not have valid MX records"
    // - "Disposable email addresses are not allowed"
}

// Quick validation (format + MX only)
if (EmailValidator::isValid($email)) {
    // Email is valid
}

// Strict validation (all checks)
if (EmailValidator::isValidStrict($email)) {
    // Email passed all validation checks
}
```

### Why This Matters

Email providers like ZeptoMail, SendGrid, and AWS SES monitor bounce rates. High bounce rates can:
- Temporarily suspend your sending account
- Damage your domain reputation
- Reduce email deliverability
- Increase costs

By validating emails upfront, you:
- Reduce bounce rates significantly
- Protect your sender reputation
- Save on email sending costs
- Improve user experience

## Multi-Tenancy Support

### Per-Tenant Database Strategy

Each tenant has their own database. The tenant context is resolved from JWT, headers, or subdomain:

```php
// Tenant is automatically resolved by TenantMiddleware
// Database connection is automatically scoped to tenant
$db = tenant_db();  // Returns PDO connection to tenant's database
```

### Shared Database Strategy

All tenants share the same database with `tenant_id` column:

```php
// Uncomment the tenant_id index in users.pgsql.template
// Queries automatically filter by tenant_id when using TenantQueryBuilder

use Framework\Tenancy\TenantQueryBuilder;

$builder = new TenantQueryBuilder($db, 'users');
$users = $builder->all();  // Automatically adds WHERE tenant_id = ?
```

## Security Features

- **Password Hashing**: BCrypt with cost factor 12
- **Token Expiration**: Reset tokens expire after 1 hour
- **Email Enumeration Protection**: Always returns success for password reset
- **Account Status Check**: Only active accounts can login
- **Tenant Isolation**: Complete data isolation between tenants

## Customization

### Add Email Verification

Uncomment email verification fields in the routes and implement email sending:

```php
// In RegisterRoute.php
$verificationToken = bin2hex(random_bytes(32));
// Send verification email
mail($this->email, 'Verify Email', "Token: {$verificationToken}");
```

### Add User Roles

Extend the users table with a role field:

```sql
ALTER TABLE users ADD COLUMN role VARCHAR(50) DEFAULT 'user';
```

Update JWT generation:

```php
$token = $jwtHandler->generateToken([
    'user_id' => $userId,
    'email' => $this->email,
    'user_role' => $user['role']  // Use actual role from database
]);
```

### Email Integration

Replace the TODO in `PasswordResetRoute.php` with your email service:

```php
// Example with PHPMailer
$mail = new PHPMailer(true);
$mail->setFrom('noreply@example.com');
$mail->addAddress($this->email);
$mail->Subject = 'Password Reset';
$mail->Body = "Reset link: https://example.com/reset?token={$resetToken}";
$mail->send();
```

## Production Checklist

- [ ] Remove `reset_token` and `expires_at` from password reset response
- [ ] Implement email sending for password reset
- [ ] Add rate limiting to prevent brute force attacks
- [ ] Enable email verification
- [ ] Set up proper CORS headers
- [ ] Use HTTPS in production
- [ ] Configure secure JWT key rotation
- [ ] Add logging and monitoring
- [ ] Implement account lockout after failed attempts

# Mobile + OTP Authentication Template

Tenant-aware mobile number and one-time password (OTP) authentication for StoneScriptPHP.

## Features

- Passwordless authentication via mobile OTP
- User registration with mobile number
- Login with OTP verification
- Full multi-tenancy support (per-tenant DB and shared DB strategies)
- Rate limiting via attempt tracking
- OTP expiration (10 minutes default)
- E.164 mobile number format validation
- SMS integration ready (Twilio, AWS SNS, etc.)

## Installation

### 1. Database Setup

Run the migrations to create the required tables:

```bash
# For per-tenant database strategy
php stone tenant:migrate your-tenant-slug

# For shared database strategy
psql -h localhost -U your_user -d your_database -f mobile_users.pgsql.template
psql -h localhost -U your_user -d your_database -f otp_codes.pgsql.template
```

### 2. Copy Route Templates

Copy the route files to your application:

```bash
cp src/Templates/Auth/mobile-otp/*.php.template app/Routes/Auth/
# Rename files to remove .template extension
```

### 3. Register Routes

Add routes to your main application file (e.g., `public/index.php`):

```php
use App\Routes\Auth\SendOtpRoute;
use App\Routes\Auth\VerifyOtpRoute;

// Public routes
$router->post('/api/auth/otp/send', SendOtpRoute::class);
$router->post('/api/auth/otp/verify', VerifyOtpRoute::class);
```

### 4. Configure SMS Provider (Production)

Integrate with an SMS service provider. Example with Twilio:

```bash
composer require twilio/sdk
```

Update `SendOtpRoute.php`:

```php
private function sendSms(string $mobileNumber, string $message): void
{
    $twilio = new \Twilio\Rest\Client(
        getenv('TWILIO_ACCOUNT_SID'),
        getenv('TWILIO_AUTH_TOKEN')
    );

    $twilio->messages->create($mobileNumber, [
        'from' => getenv('TWILIO_PHONE_NUMBER'),
        'body' => $message
    ]);
}
```

## Usage

### Send OTP for Registration

```bash
curl -X POST http://localhost:8000/api/auth/otp/send \
  -H "Content-Type: application/json" \
  -H "X-Tenant-Slug: acme" \
  -d '{
    "mobile_number": "+1234567890",
    "purpose": "registration"
  }'
```

Response:
```json
{
  "status": "success",
  "message": "OTP sent successfully",
  "data": {
    "otp_code": "123456",
    "expires_at": "2025-12-13 14:45:00",
    "mobile_number": "+1234567890"
  }
}
```

### Send OTP for Login

```bash
curl -X POST http://localhost:8000/api/auth/otp/send \
  -H "Content-Type: application/json" \
  -H "X-Tenant-Slug: acme" \
  -d '{
    "mobile_number": "+1234567890",
    "purpose": "login"
  }'
```

### Verify OTP

For registration (creates new user):

```bash
curl -X POST http://localhost:8000/api/auth/otp/verify \
  -H "Content-Type: application/json" \
  -H "X-Tenant-Slug: acme" \
  -d '{
    "mobile_number": "+1234567890",
    "otp_code": "123456",
    "display_name": "John Doe"
  }'
```

For login (existing user):

```bash
curl -X POST http://localhost:8000/api/auth/otp/verify \
  -H "Content-Type: application/json" \
  -H "X-Tenant-Slug: acme" \
  -d '{
    "mobile_number": "+1234567890",
    "otp_code": "123456"
  }'
```

Response:
```json
{
  "status": "success",
  "message": "Authentication successful",
  "data": {
    "user_id": 1,
    "mobile_number": "+1234567890",
    "display_name": "John Doe",
    "token": "eyJ0eXAiOiJKV1QiLCJhbGc..."
  }
}
```

## Multi-Tenancy Support

### Per-Tenant Database Strategy

Each tenant has their own database:

```php
// Tenant is automatically resolved by TenantMiddleware
$db = tenant_db();  // Returns PDO connection to tenant's database

// Mobile numbers are unique within each tenant database
```

### Shared Database Strategy

All tenants share the same database with `tenant_id` column:

```php
// Uncomment the tenant_id indexes in migration files
// Queries automatically filter by tenant_id

use Framework\Tenancy\TenantQueryBuilder;

$builder = new TenantQueryBuilder($db, 'mobile_users');
$users = $builder->all();  // Automatically adds WHERE tenant_id = ?
```

## Security Features

- **E.164 Validation**: Mobile numbers must be in international format (+1234567890)
- **OTP Expiration**: OTPs expire after 10 minutes
- **Attempt Limiting**: Maximum 3 verification attempts per OTP
- **One-Time Use**: OTPs are marked as verified after successful use
- **Automatic Invalidation**: Old OTPs are invalidated when new ones are generated
- **Tenant Isolation**: Complete data isolation between tenants

## Supported SMS Providers

### Twilio

```php
composer require twilio/sdk
```

```php
$twilio = new \Twilio\Rest\Client($accountSid, $authToken);
$twilio->messages->create($mobileNumber, [
    'from' => '+1234567890',
    'body' => "Your OTP: {$otpCode}"
]);
```

### AWS SNS

```php
composer require aws/aws-sdk-php
```

```php
$sns = new \Aws\Sns\SnsClient([
    'version' => 'latest',
    'region' => 'us-east-1'
]);

$sns->publish([
    'Message' => "Your OTP: {$otpCode}",
    'PhoneNumber' => $mobileNumber
]);
```

### Vonage (Nexmo)

```php
composer require vonage/client
```

```php
$client = new \Vonage\Client(new \Vonage\Client\Credentials\Basic($apiKey, $apiSecret));
$client->sms()->send(
    new \Vonage\SMS\Message\SMS($mobileNumber, 'YourBrand', "Your OTP: {$otpCode}")
);
```

## Customization

### Change OTP Length

Modify `generateOtp()` in [SendOtpRoute.php.template](SendOtpRoute.php.template):

```php
// 4-digit OTP
return str_pad((string) random_int(1000, 9999), 4, '0', STR_PAD_LEFT);

// 8-digit OTP
return str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT);
```

### Change OTP Expiration Time

Modify in [SendOtpRoute.php.template](SendOtpRoute.php.template):

```php
// 5 minutes
$expiresAt = new \DateTime('+5 minutes');

// 30 minutes
$expiresAt = new \DateTime('+30 minutes');
```

### Change Max Attempts

Update the database migration:

```sql
max_attempts INTEGER DEFAULT 5,  -- Change from 3 to 5
```

### Add Rate Limiting

Limit OTP requests per mobile number:

```php
// In SendOtpRoute.php
private function checkRateLimit(PDO $db, string $mobileNumber): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*) as count FROM otp_codes
         WHERE mobile_number = ? AND created_at > NOW() - INTERVAL \'1 hour\''
    );
    $stmt->execute([$mobileNumber]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    return $result['count'] < 5;  // Max 5 OTP requests per hour
}
```

### Add Country-Specific Logic

```php
// Extract country code
preg_match('/^(\+\d{1,3})/', $mobileNumber, $matches);
$countryCode = $matches[1];

// Country-specific OTP length
$otpLength = match($countryCode) {
    '+1' => 6,   // US/Canada: 6 digits
    '+91' => 6,  // India: 6 digits
    '+44' => 4,  // UK: 4 digits
    default => 6
};
```

## Cleanup Old OTPs

Create a cron job or scheduled task:

```php
// cleanup-otps.php
<?php
require_once __DIR__ . '/vendor/autoload.php';

$db = db_connection(getenv('DB_NAME'));

// Delete OTPs older than 24 hours
$stmt = $db->prepare('DELETE FROM otp_codes WHERE created_at < NOW() - INTERVAL \'24 hours\'');
$stmt->execute();

echo "Cleaned up old OTPs\n";
```

Run daily:
```bash
0 0 * * * php /path/to/cleanup-otps.php
```

## Production Checklist

- [ ] Remove `otp_code` from send OTP response
- [ ] Integrate with real SMS provider (Twilio, AWS SNS, etc.)
- [ ] Add rate limiting (max OTPs per hour/day)
- [ ] Set up OTP cleanup cron job
- [ ] Add monitoring and alerting for SMS failures
- [ ] Implement cost tracking for SMS usage
- [ ] Add CAPTCHA to prevent abuse
- [ ] Use HTTPS in production
- [ ] Configure secure JWT key rotation
- [ ] Add logging and monitoring
- [ ] Test with multiple countries and carriers
- [ ] Add SMS delivery status tracking
- [ ] Implement fallback for SMS failures (email, voice call)

## Testing

### Test without SMS integration

The templates include the OTP code in the response for development. Remove this in production.

### Test with different mobile formats

```bash
# US format
curl ... -d '{"mobile_number": "+12025551234"}'

# India format
curl ... -d '{"mobile_number": "+919876543210"}'

# UK format
curl ... -d '{"mobile_number": "+447911123456"}'
```

## Troubleshooting

**OTP not received:**
- Check SMS provider configuration
- Verify mobile number format (E.164)
- Check SMS provider balance/credits
- Review SMS provider logs

**"Invalid OTP code" error:**
- OTP may have expired (10 minutes default)
- Maximum attempts exceeded (3 default)
- OTP already used
- Check for timing issues between send and verify

**"Mobile number already registered":**
- User tried to register with existing number
- Use "login" purpose instead of "registration"

**Tenant isolation issues:**
- Verify TenantMiddleware is applied
- Check tenant_id in JWT token
- Ensure database indexes are created for shared DB strategy

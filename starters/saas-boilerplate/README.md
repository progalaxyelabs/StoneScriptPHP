# SaaS Boilerplate Starter Template

A production-ready SaaS application starter with multi-tenancy, subscription management, and all essential SaaS features built on StoneScriptPHP.

## What's Included

- ✅ **Multi-tenant Architecture** - Tenant isolation and data segregation
- ✅ **User Management** - Authentication, authorization, roles & permissions
- ✅ **Subscription Management** - Plans, billing, usage tracking
- ✅ **Payment Integration** - Stripe integration ready
- ✅ **Team Management** - Organizations, team members, invitations
- ✅ **API & Dashboard** - REST API + Admin dashboard
- ✅ **Email Notifications** - Transactional emails
- ✅ **Audit Logging** - Track all user actions
- ✅ **Feature Flags** - Toggle features per plan
- ✅ **Usage Metering** - Track API calls, storage, etc.
- ✅ **Webhooks** - Event-driven integrations
- ✅ **Admin Panel** - Manage tenants, users, subscriptions

## Architecture

```
┌──────────────────────────────────────────────────────┐
│                  SaaS Application                     │
├──────────────────────────────────────────────────────┤
│                                                      │
│  ┌─────────┐  ┌─────────┐  ┌─────────┐  ┌────────┐ │
│  │  Admin  │  │   API   │  │ Worker │  │   DB   │ │
│  │Dashboard│  │  Multi- │  │  Jobs  │  │Postgres│ │
│  │  :4200  │  │ Tenant  │  │ Queue  │  │ :5432  │ │
│  │         │  │  :9100  │  │ :6379  │  │        │ │
│  └─────────┘  └─────────┘  └─────────┘  └────────┘ │
│                                                      │
└──────────────────────────────────────────────────────┘
```

## Key Features

### 1. Multi-Tenancy

**Tenant Isolation Models:**
- **Shared Database, Separate Schema** (default)
- **Separate Database per Tenant** (enterprise)
- **Row-Level Tenancy** (simple)

```php
// Automatic tenant context in every request
public function process(): ApiResponse
{
    $tenantId = $this->getTenantId(); // Automatically resolved
    $users = User::forTenant($tenantId)->all();
    return new ApiResponse('ok', 'Users', ['users' => $users]);
}
```

### 2. Subscription Plans

```php
// Define plans in src/App/Config/plans.php
return [
    'free' => [
        'name' => 'Free',
        'price' => 0,
        'features' => [
            'max_users' => 5,
            'max_projects' => 10,
            'api_calls_per_month' => 1000,
            'storage_gb' => 1,
        ],
    ],
    'pro' => [
        'name' => 'Professional',
        'price' => 29,
        'features' => [
            'max_users' => 50,
            'max_projects' => 100,
            'api_calls_per_month' => 100000,
            'storage_gb' => 50,
        ],
    ],
    'enterprise' => [
        'name' => 'Enterprise',
        'price' => 299,
        'features' => [
            'max_users' => -1, // unlimited
            'max_projects' => -1,
            'api_calls_per_month' => -1,
            'storage_gb' => 500,
        ],
    ],
];
```

### 3. Role-Based Access Control (RBAC)

```php
// Roles: owner, admin, member, viewer
use Framework\Middleware\RequireRole;

class DeleteProjectRoute extends BaseRoute
{
    protected array $middleware = [RequireRole::class . ':admin'];

    public function process(): ApiResponse
    {
        // Only admins and owners can access
    }
}
```

### 4. Usage Metering

```php
// Track API usage
UsageMeter::record('api_calls', $tenantId, 1);

// Check limits
if (UsageMeter::hasReachedLimit($tenantId, 'api_calls')) {
    return new ApiResponse('error', 'Usage limit reached', [], 429);
}
```

### 5. Stripe Integration

```php
// Create subscription
$subscription = StripeService::createSubscription(
    $customerId,
    $planId,
    $tenantId
);

// Handle webhooks
// POST /webhooks/stripe
class StripeWebhookRoute extends BaseRoute
{
    public function process(): ApiResponse
    {
        $event = $this->getWebhookEvent();

        switch ($event['type']) {
            case 'customer.subscription.updated':
                // Update subscription in database
                break;
            case 'invoice.payment_succeeded':
                // Grant access
                break;
        }
    }
}
```

## Quick Start

### Prerequisites

- Docker & Docker Compose
- Stripe account (for payments)
- SMTP server (for emails)

### Setup

```bash
# 1. Clone/copy this starter
cp -r starters/saas-boilerplate my-saas
cd my-saas

# 2. Configure environment
cp .env.example .env
# Edit .env with your Stripe keys, SMTP config, etc.

# 3. Start all services
docker-compose up -d

# 4. Run migrations
docker-compose exec api php stone migrate verify

# 5. Seed initial data (plans, roles)
docker-compose exec api php stone seed

# 6. Create first tenant/organization
curl -X POST http://localhost:9100/api/v1/signup \
  -H "Content-Type: application/json" \
  -d '{
    "company_name": "Acme Inc",
    "admin_email": "admin@acme.com",
    "admin_password": "secure_password",
    "plan": "free"
  }'
```

## Project Structure

```
saas-boilerplate/
├── docker-compose.yaml
├── .env.example
│
├── api/                              # Backend API
│   ├── src/
│   │   ├── App/
│   │   │   ├── Routes/
│   │   │   │   ├── Auth/             # Authentication
│   │   │   │   ├── Tenant/           # Tenant management
│   │   │   │   ├── User/             # User management
│   │   │   │   ├── Subscription/     # Billing
│   │   │   │   ├── Team/             # Team/org management
│   │   │   │   └── Webhook/          # Webhooks
│   │   │   ├── Models/
│   │   │   │   ├── Tenant.php
│   │   │   │   ├── User.php
│   │   │   │   ├── Subscription.php
│   │   │   │   └── UsageMetric.php
│   │   │   ├── Middleware/
│   │   │   │   ├── TenantContext.php
│   │   │   │   ├── CheckPlanLimit.php
│   │   │   │   └── RequireRole.php
│   │   │   ├── Services/
│   │   │   │   ├── StripeService.php
│   │   │   │   ├── EmailService.php
│   │   │   │   └── UsageMeter.php
│   │   │   └── Config/
│   │   │       ├── plans.php
│   │   │       └── features.php
│   │   └── postgresql/
│   │       ├── tables/
│   │       │   ├── tenants.pgsql
│   │       │   ├── users.pgsql
│   │       │   ├── subscriptions.pgsql
│   │       │   ├── usage_metrics.pgsql
│   │       │   └── audit_logs.pgsql
│   │       └── functions/
│
├── admin/                            # Admin Dashboard (Angular)
│   ├── src/app/
│   │   ├── pages/
│   │   │   ├── tenants/
│   │   │   ├── users/
│   │   │   ├── subscriptions/
│   │   │   └── analytics/
│   │   └── services/
│
└── worker/                           # Background Jobs
    ├── jobs/
    │   ├── send-email.js
    │   ├── process-usage.js
    │   └── cleanup-expired.js
```

## Database Schema

### Core Tables

**tenants** - Organizations/Companies
```sql
- id
- name
- slug (subdomain)
- plan_id
- status (active, suspended, cancelled)
- created_at
```

**users**
```sql
- id
- tenant_id
- email
- password_hash
- role (owner, admin, member, viewer)
- status
- last_login_at
```

**subscriptions**
```sql
- id
- tenant_id
- stripe_subscription_id
- plan_id
- status
- current_period_start
- current_period_end
- cancel_at
```

**usage_metrics**
```sql
- id
- tenant_id
- metric_name (api_calls, storage_gb, etc.)
- value
- period (YYYY-MM)
```

**audit_logs**
```sql
- id
- tenant_id
- user_id
- action (created, updated, deleted)
- entity_type
- entity_id
- metadata (JSON)
- created_at
```

## API Endpoints

### Authentication
```
POST /api/v1/signup              # Sign up new tenant
POST /api/v1/login               # Login
POST /api/v1/logout              # Logout
POST /api/v1/refresh-token       # Refresh JWT token
POST /api/v1/forgot-password     # Password reset request
POST /api/v1/reset-password      # Reset password
```

### Tenant Management
```
GET    /api/v1/tenant            # Get current tenant info
PUT    /api/v1/tenant            # Update tenant
DELETE /api/v1/tenant            # Delete tenant
GET    /api/v1/tenant/usage      # Get usage statistics
```

### User Management
```
GET    /api/v1/users             # List users in tenant
POST   /api/v1/users             # Invite user
GET    /api/v1/users/:id         # Get user
PUT    /api/v1/users/:id         # Update user
DELETE /api/v1/users/:id         # Remove user
POST   /api/v1/users/:id/role    # Change user role
```

### Subscription Management
```
GET    /api/v1/subscription              # Get current subscription
POST   /api/v1/subscription/upgrade      # Upgrade plan
POST   /api/v1/subscription/downgrade    # Downgrade plan
POST   /api/v1/subscription/cancel       # Cancel subscription
GET    /api/v1/subscription/invoices     # List invoices
GET    /api/v1/plans                     # List available plans
```

### Webhooks
```
POST /webhooks/stripe            # Stripe webhook
```

## Environment Variables

```env
# Database
DB_HOST=db
DB_PORT=5432
DB_NAME=saas_db
DB_USER=postgres
DB_PASSWORD=postgres

# JWT
JWT_SECRET=your-secret-key
JWT_EXPIRY=3600

# Stripe
STRIPE_SECRET_KEY=sk_test_...
STRIPE_PUBLISHABLE_KEY=pk_test_...
STRIPE_WEBHOOK_SECRET=whsec_...

# Email (SMTP)
SMTP_HOST=smtp.mailtrap.io
SMTP_PORT=2525
SMTP_USER=your-user
SMTP_PASSWORD=your-password
FROM_EMAIL=noreply@yoursaas.com
FROM_NAME=Your SaaS

# App
APP_NAME=Your SaaS
APP_URL=https://yoursaas.com
ADMIN_URL=https://admin.yoursaas.com

# Redis (for queues)
REDIS_HOST=redis
REDIS_PORT=6379

# Features
ENABLE_TRIAL_PERIOD=true
TRIAL_DAYS=14
```

## Multi-Tenancy Implementation

### Automatic Tenant Resolution

```php
// Framework/Middleware/TenantContext.php
class TenantContext
{
    public function handle($request, $next)
    {
        // Resolve tenant from:
        // 1. Subdomain (tenant1.yoursaas.com)
        // 2. Custom domain (customdomain.com)
        // 3. API key header
        // 4. JWT token

        $tenant = $this->resolveTenant($request);
        $request->setTenant($tenant);

        return $next($request);
    }
}
```

### Tenant-Scoped Queries

```php
// All queries automatically scoped to current tenant
class User extends BaseModel
{
    protected static $tenantScoped = true;

    // This query automatically filters by tenant_id
    public static function all()
    {
        return self::where('tenant_id', self::getCurrentTenantId())->get();
    }
}
```

## Subscription Flow

### 1. Signup with Free Plan
```
User signs up → Create tenant → Create owner user → Assign free plan
```

### 2. Upgrade to Paid Plan
```
Click upgrade → Redirect to Stripe Checkout → Payment succeeds →
Webhook received → Update subscription → Grant access
```

### 3. Usage Limits
```
API request → Check plan limits → Allow/Deny → Record usage
```

## Background Jobs

```javascript
// worker/jobs/send-email.js
async function sendEmail(job) {
  const { to, subject, template, data } = job.data;

  await emailService.send({
    to,
    subject,
    html: renderTemplate(template, data)
  });
}

// Queue job from PHP
EmailQueue::dispatch('welcome-email', [
    'to' => $user->email,
    'subject' => 'Welcome to Your SaaS',
    'template' => 'welcome',
    'data' => ['name' => $user->name]
]);
```

## Admin Panel Features

- **Dashboard** - Key metrics, revenue, MRR, churn
- **Tenant Management** - List, search, suspend tenants
- **User Management** - View all users across tenants
- **Subscription Management** - View, modify subscriptions
- **Analytics** - Usage charts, revenue graphs
- **Audit Logs** - View all system events
- **Feature Flags** - Enable/disable features

## Security Best Practices

1. **Tenant Isolation** - Always enforce tenant_id in queries
2. **RBAC** - Use middleware for role checks
3. **Input Validation** - Validate all inputs
4. **Rate Limiting** - Prevent abuse
5. **Audit Logging** - Log all sensitive actions
6. **HTTPS Only** - Enforce SSL in production
7. **Password Policy** - Enforce strong passwords
8. **2FA** - Optional two-factor authentication
9. **API Key Rotation** - Allow users to regenerate keys
10. **Webhook Signature Verification** - Verify Stripe webhooks

## Deployment

### Production Checklist

- [ ] Set strong JWT_SECRET
- [ ] Configure production Stripe keys
- [ ] Set up SSL certificates
- [ ] Configure SMTP for emails
- [ ] Set up Redis for queues
- [ ] Enable database backups
- [ ] Configure monitoring (Sentry, New Relic)
- [ ] Set up CDN for assets
- [ ] Configure domain and DNS
- [ ] Test payment flow end-to-end

### Docker Deployment

```bash
# Build for production
docker-compose -f docker-compose.yaml -f docker-compose.prod.yaml build

# Deploy
docker-compose -f docker-compose.yaml -f docker-compose.prod.yaml up -d

# Scale API service
docker-compose up -d --scale api=3
```

## Monitoring & Analytics

### Key Metrics to Track

- **MRR** (Monthly Recurring Revenue)
- **Churn Rate**
- **Customer Lifetime Value (LTV)**
- **Conversion Rate** (free to paid)
- **Active Users**
- **API Usage per Tenant**
- **Error Rates**
- **Response Times**

## Customization Guide

### Adding a New Feature

1. Add to `src/App/Config/features.php`
2. Update plan limits in `src/App/Config/plans.php`
3. Create feature flag check middleware
4. Implement feature routes
5. Update admin panel to manage feature

### Adding a New Plan

1. Add to `src/App/Config/plans.php`
2. Create Stripe product/price
3. Update frontend pricing page
4. Test upgrade/downgrade flow

## Documentation

- [StoneScriptPHP Docs](https://stonescriptphp.org/docs)
- [Stripe Billing Docs](https://stripe.com/docs/billing)
- [SaaS Metrics Guide](https://www.cobloom.com/blog/saas-metrics)

## Support

- **Issues**: https://github.com/progalaxyelabs/StoneScriptPHP/issues
- **Documentation**: https://stonescriptphp.org/docs

## License

MIT

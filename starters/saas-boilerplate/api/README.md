# SaaS API Service

This is the multi-tenant backend API for the SaaS application.

## Structure

This extends the `basic-api` starter with additional SaaS-specific features:

### Additional Routes
- `src/App/Routes/Auth/` - Authentication (signup, login, password reset)
- `src/App/Routes/Tenant/` - Tenant management
- `src/App/Routes/User/` - User management
- `src/App/Routes/Subscription/` - Billing and subscriptions
- `src/App/Routes/Team/` - Team/organization management
- `src/App/Routes/Webhook/` - Stripe webhooks

### Additional Models
- `Tenant.php` - Multi-tenant organizations
- `Subscription.php` - Subscription management
- `UsageMetric.php` - Usage tracking
- `AuditLog.php` - Audit logging

### Middleware
- `TenantContext.php` - Automatic tenant resolution
- `CheckPlanLimit.php` - Enforce plan limits
- `RequireRole.php` - RBAC enforcement

### Services
- `StripeService.php` - Stripe integration
- `EmailService.php` - Email sending
- `UsageMeter.php` - Usage tracking and limiting

### Configuration
- `src/App/Config/plans.php` - Subscription plans
- `src/App/Config/features.php` - Feature flags per plan

## Key Database Tables

Refer to `src/postgresql/tables/` for full schema:
- `tenants` - Organizations/companies
- `users` - User accounts
- `subscriptions` - Billing subscriptions
- `usage_metrics` - Usage tracking
- `audit_logs` - System audit trail

## Setup

Copy files from `starters/basic-api/` as a base, then add SaaS-specific files.

```bash
# Initialize from basic-api
cp -r ../basic-api/src/* src/

# Add SaaS-specific tables and functions
# These should be in src/postgresql/

# Run setup
php stone setup

# Run migrations
php stone migrate verify
```

## Multi-Tenancy

All routes automatically have tenant context via middleware:

```php
public function process(): ApiResponse
{
    $tenantId = $this->getTenantId();
    // All queries scoped to this tenant
}
```

## Usage Limits

Check plan limits before processing:

```php
use App\Services\UsageMeter;

if (UsageMeter::hasReachedLimit($tenantId, 'api_calls')) {
    return new ApiResponse('error', 'API limit reached', [], 429);
}

UsageMeter::record('api_calls', $tenantId, 1);
```

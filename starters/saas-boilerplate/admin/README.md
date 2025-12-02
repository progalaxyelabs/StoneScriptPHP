# SaaS Admin Dashboard

Angular-based admin dashboard for managing the SaaS application.

## Features

- **Dashboard** - Key metrics (MRR, active tenants, new signups)
- **Tenant Management** - List, search, view, suspend tenants
- **User Management** - View all users across all tenants
- **Subscription Management** - View and modify subscriptions
- **Analytics** - Charts and graphs for business metrics
- **Audit Logs** - System-wide audit trail
- **Feature Flags** - Enable/disable features per plan

## Setup

```bash
# Create Angular app
ng new admin --routing --style=scss

cd admin

# Install dependencies
npm install

# Install additional packages
npm install @angular/material
npm install chart.js ng2-charts
npm install socket.io-client

# Install API client
npm install file:../api/client

# Start dev server
npm start
```

## Key Components

- `pages/dashboard/` - Main dashboard with KPIs
- `pages/tenants/` - Tenant list and details
- `pages/users/` - User management
- `pages/subscriptions/` - Subscription management
- `pages/analytics/` - Analytics and reports

## Environment

Configure in `src/environments/environment.ts`:

```typescript
export const environment = {
  production: false,
  apiUrl: 'http://localhost:9100',
  adminApiUrl: 'http://localhost:9100/api/v1/admin',
};
```

## Authentication

Admin dashboard requires super-admin authentication:

```typescript
// Login as super admin
this.authService.login('admin@yoursaas.com', 'password');
```

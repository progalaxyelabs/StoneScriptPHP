# Fullstack Angular Starter Template

A complete fullstack application starter with StoneScriptPHP backend, Angular frontend, and Socket.IO real-time notifications.

## What's Included

- ✅ **API Service** - StoneScriptPHP backend with PostgreSQL
- ✅ **WWW Service** - Angular 19 frontend
- ✅ **Alert Service** - Node.js Socket.IO for real-time notifications
- ✅ **Database** - PostgreSQL 16
- ✅ **Docker Compose** - Complete orchestration
- ✅ **Auto-generated TypeScript Client** - Type-safe API communication
- ✅ **Authentication** - JWT-based auth system
- ✅ **Real-time Updates** - Socket.IO integration

## Architecture

```
┌─────────────────────────────────────────────────────┐
│                  Fullstack App                      │
├─────────────────────────────────────────────────────┤
│                                                     │
│  ┌─────────┐  ┌─────────┐  ┌─────────┐  ┌────────┐│
│  │   WWW   │  │   API   │  │ Alert  │  │   DB   ││
│  │ Angular │◄─┤  PHP    │  │Socket.IO│  │Postgres││
│  │  :4200  │  │  :9100  │  │  :3001 │  │ :5432  ││
│  └─────────┘  └─────────┘  └─────────┘  └────────┘│
│                                                     │
└─────────────────────────────────────────────────────┘
```

## Quick Start

### Prerequisites

- Docker & Docker Compose
- Node.js >= 18 (for local frontend development)
- PHP >= 8.2 (for local API development)

### Setup

```bash
# 1. Clone/copy this starter
cp -r starters/fullstack-angular my-app
cd my-app

# 2. Configure environment
cp .env.example .env
# Edit .env with your settings

# 3. Start all services
docker-compose up -d

# 4. Check service health
docker-compose ps

# 5. Run database migrations
docker-compose exec api php stone migrate verify

# 6. View logs
docker-compose logs -f
```

### Accessing Services

- **Frontend**: http://localhost:4200
- **API**: http://localhost:9100
- **Alert Service**: http://localhost:3001
- **Database**: localhost:5432

## Project Structure

```
fullstack-angular/
├── docker-compose.yaml          # Service orchestration
├── .env.example                 # Environment template
│
├── api/                         # StoneScriptPHP Backend
│   ├── Dockerfile
│   ├── src/
│   │   ├── App/
│   │   │   ├── Routes/          # API route handlers
│   │   │   ├── Models/          # Database models
│   │   │   └── Config/
│   │   │       └── routes.php   # Route mapping
│   │   └── postgresql/
│   │       ├── tables/          # Table schemas
│   │       ├── functions/       # SQL functions
│   │       └── seeds/           # Seed data
│   └── docker/
│
├── www/                         # Angular Frontend
│   ├── Dockerfile
│   ├── angular.json
│   ├── package.json
│   ├── src/
│   │   ├── app/
│   │   │   ├── components/      # Reusable components
│   │   │   ├── pages/           # Page components
│   │   │   ├── services/        # Angular services
│   │   │   │   ├── api.service.ts    # Generated API client
│   │   │   │   └── socket.service.ts # Socket.IO client
│   │   │   └── guards/          # Auth guards
│   │   └── environments/
│   └── docker/
│
└── alert/                       # Socket.IO Service
    ├── Dockerfile
    ├── package.json
    └── server.js                # Socket.IO server
```

## Development Workflow

### Backend Development

```bash
# Navigate to API directory
cd api

# Generate a new route
php stone generate route products

# Generate model from SQL function
php stone generate model get_products.pgsql

# Start dev server (without Docker)
php stone serve

# Run tests
php stone test
```

### Frontend Development

```bash
# Navigate to www directory
cd www

# Install dependencies
npm install

# Start dev server
npm start

# Build for production
npm run build

# Run tests
npm test
```

### Generate TypeScript Client

After creating/updating API routes:

```bash
# In api directory
cd api
php stone generate client

# This generates TypeScript interfaces and client in api/client/

# Install client in frontend
cd ../www
npm install file:../api/client
```

Now use the type-safe client in Angular:

```typescript
import { ApiClient } from '@api/client';

export class ProductsService {
  constructor(private api: ApiClient) {}

  async getProducts() {
    const response = await this.api.get('/products');
    return response.data.products; // Fully typed!
  }
}
```

### Real-time Notifications

The alert service provides Socket.IO for real-time updates:

```typescript
// www/src/app/services/socket.service.ts
import { io } from 'socket.io-client';

export class SocketService {
  socket = io('http://localhost:3001');

  listenForNotifications() {
    this.socket.on('notification', (data) => {
      console.log('New notification:', data);
    });
  }

  emit(event: string, data: any) {
    this.socket.emit(event, data);
  }
}
```

```javascript
// alert/server.js
io.on('connection', (socket) => {
  // Emit notification to specific user
  socket.emit('notification', {
    type: 'info',
    message: 'Welcome!',
  });
});
```

## API Authentication

The starter includes JWT authentication:

```typescript
// Login
const response = await this.api.post('/auth/login', {
  email: 'user@example.com',
  password: 'password',
});

const token = response.data.token;
localStorage.setItem('token', token);

// Use token in subsequent requests
this.api.setAuthToken(token);
```

## Database Migrations

```bash
# Verify database schema
docker-compose exec api php stone migrate verify

# Run migrations (applies all .pgsql files)
docker-compose exec api php stone migrate up

# Check migration status
docker-compose exec api php stone migrate status
```

## Environment Variables

### Backend (.env)

```env
# Database
DB_HOST=db
DB_PORT=5432
DB_NAME=fullstack_app
DB_USER=postgres
DB_PASSWORD=postgres

# JWT
JWT_SECRET=your-secret-key
JWT_EXPIRY=3600

# CORS
CORS_ALLOWED_ORIGINS=http://localhost:4200

# Alert Service
ALERT_SERVICE_URL=http://alert:3001
```

### Frontend (www/src/environments/environment.ts)

```typescript
export const environment = {
  production: false,
  apiUrl: 'http://localhost:9100',
  socketUrl: 'http://localhost:3001',
};
```

## Docker Services

### Start all services

```bash
docker-compose up -d
```

### Start specific service

```bash
docker-compose up -d www
docker-compose up -d api
```

### View logs

```bash
# All services
docker-compose logs -f

# Specific service
docker-compose logs -f api
```

### Rebuild services

```bash
docker-compose build
docker-compose up -d
```

## Production Deployment

1. Update environment variables for production
2. Build frontend for production: `npm run build`
3. Use production Dockerfile for optimized images
4. Enable HTTPS with reverse proxy (nginx/traefik)
5. Secure database with strong credentials
6. Set up monitoring and logging

```bash
# Build production images
docker-compose -f docker-compose.yaml -f docker-compose.prod.yaml build

# Deploy
docker-compose -f docker-compose.yaml -f docker-compose.prod.yaml up -d
```

## Testing

### Backend Tests

```bash
docker-compose exec api php stone test
```

### Frontend Tests

```bash
docker-compose exec www npm test
```

### E2E Tests

```bash
cd www
npm run e2e
```

## Common Tasks

### Add a new API endpoint

1. Create SQL function: `api/src/postgresql/functions/my_function.pgsql`
2. Generate model: `php stone generate model my_function.pgsql`
3. Create route: `php stone generate route my-endpoint`
4. Map URL in `api/src/App/Config/routes.php`
5. Generate TypeScript client: `php stone generate client`
6. Use in Angular: Import from `@api/client`

### Add a new page to frontend

```bash
cd www
ng generate component pages/my-page
```

### Add real-time feature

1. Emit from backend after data change
2. Listen in alert service
3. Broadcast to connected clients
4. Update Angular UI reactively

## Documentation

- [StoneScriptPHP Docs](https://stonescriptphp.org/docs)
- [Angular Docs](https://angular.dev)
- [Socket.IO Docs](https://socket.io/docs/)

## Support

- **Issues**: https://github.com/progalaxyelabs/StoneScriptPHP/issues
- **Documentation**: https://stonescriptphp.org/docs

## License

MIT

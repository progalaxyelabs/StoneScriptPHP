# StoneScriptPHP Starter Templates

Official starter templates for quickly scaffolding new StoneScriptPHP projects.

## Available Templates

### 1. Basic API
**Path:** `starters/basic-api/`

A minimal REST API starter with PostgreSQL database.

**Best for:**
- Simple REST APIs
- CRUD applications
- Microservices
- Learning StoneScriptPHP

**Includes:**
- Sample routes and models
- PostgreSQL integration
- JWT authentication
- Docker setup
- Testing configuration

**Quick Start:**
```bash
composer create-project progalaxyelabs/stonescriptphp my-api
cp -r starters/basic-api/* my-api/
cd my-api
php stone setup
php stone serve
```

---

### 2. Microservice
**Path:** `starters/microservice/`

Lightweight microservice template for building distributed systems.

**Best for:**
- Distributed architectures
- Domain-driven design
- Independent scaling
- Service mesh

**Includes:**
- Health check endpoints
- Service-to-service communication
- Service registry
- Inter-service authentication
- Horizontal scaling support
- API gateway ready

**Quick Start:**
```bash
# Create multiple services
cp -r starters/microservice user-service
cp -r starters/microservice product-service

# Update ports and start
cd user-service
docker-compose up -d
```

**Features:**
- `/health` - Health check endpoint
- `/info` - Service metadata
- `ServiceClient` - HTTP client for calling other services
- Docker health checks
- Auto-scaling ready

---

### 3. SaaS Boilerplate
**Path:** `starters/saas-boilerplate/`

Production-ready SaaS application with multi-tenancy, subscriptions, and billing.

**Best for:**
- B2B SaaS applications
- Multi-tenant platforms
- Subscription-based services
- Enterprise applications

**Includes:**
- Multi-tenant architecture
- Subscription management (Stripe)
- User & team management
- Role-based access control (RBAC)
- Usage metering & limits
- Admin dashboard
- Background job processing
- Email notifications
- Audit logging

**Quick Start:**
```bash
cp -r starters/saas-boilerplate my-saas
cd my-saas
cp .env.example .env
# Configure Stripe keys in .env
docker-compose up -d
```

**Services:**
- API: http://localhost:9100
- Admin Dashboard: http://localhost:4200
- PostgreSQL: localhost:5432
- Redis: localhost:6379

---

## Choosing a Template

| Template | Complexity | Use Case | Best For |
|----------|-----------|----------|----------|
| **basic-api** | ⭐ Simple | REST APIs | Beginners, MVPs |
| **microservice** | ⭐⭐ Medium | Distributed Systems | Scaling, DDD |
| **saas-boilerplate** | ⭐⭐⭐ Advanced | SaaS Products | Multi-tenant apps |

## General Usage

### 1. Choose a Template

Select the template that matches your project requirements.

### 2. Copy Template

```bash
cp -r starters/<template-name> /path/to/your-project
cd /path/to/your-project
```

### 3. Configure Environment

```bash
cp .env.example .env
# Edit .env with your settings
```

### 4. Install Dependencies

```bash
composer install
```

### 5. Setup Project

```bash
php stone setup
```

### 6. Run Development Server

**Without Docker:**
```bash
php stone serve
```

**With Docker:**
```bash
docker-compose up -d
```

## Template Structure

All templates follow a similar structure:

```
template-name/
├── README.md                # Template-specific documentation
├── .env.example             # Environment variables template
├── .gitignore               # Git ignore rules
├── docker-compose.yaml      # Docker orchestration (if applicable)
├── Dockerfile               # Docker image (if applicable)
├── src/
│   ├── App/
│   │   ├── Routes/          # HTTP route handlers
│   │   ├── Models/          # Database models
│   │   ├── Services/        # Business logic services
│   │   ├── Middleware/      # HTTP middleware
│   │   └── Config/          # Configuration files
│   └── postgresql/
│       ├── tables/          # Database table definitions
│       ├── functions/       # PostgreSQL functions
│       └── seeds/           # Seed data
└── tests/                   # PHPUnit tests
```

## Customization

After copying a template:

1. **Update Package Info** - Edit `composer.json` with your project details
2. **Configure Database** - Set database credentials in `.env`
3. **Add Routes** - Create new routes using `php stone generate route <name>`
4. **Create Models** - Generate models from SQL functions
5. **Write Tests** - Add tests in `tests/` directory

## Common Commands

```bash
# Setup project
php stone setup

# Start dev server
php stone serve

# Generate route
php stone generate route <name>

# Generate model
php stone generate model <function>.pgsql

# Run migrations
php stone migrate verify

# Run tests
php stone test
```

## Docker Commands

```bash
# Start all services
docker-compose up -d

# Stop services
docker-compose down

# View logs
docker-compose logs -f

# Rebuild services
docker-compose build

# Scale service
docker-compose up -d --scale api=3
```

## Support & Documentation

- **StoneScriptPHP Documentation**: https://stonescriptphp.org/docs
- **Getting Started Guide**: [docs/getting-started.md](../docs/getting-started.md)
- **CLI Usage**: [CLI-USAGE.md](../CLI-USAGE.md)
- **Issues**: https://github.com/progalaxyelabs/StoneScriptPHP/issues

## Contributing

Found a bug or want to improve a template?

1. Fork the repository
2. Make your changes
3. Submit a pull request

## License

MIT License - See [LICENSE](../LICENSE) for details

# StoneScriptPHP - High Level Design (HLD)

**[← Back to README](README.md)** | **[🌐 Website](https://stonescriptphp.org)** | **[📖 Documentation](https://stonescriptphp.org/docs)**

**Version:** 2.4.2
**Document Version:** 1.3
**Last Updated:** January 13, 2026
**Status:** Production

---

## Executive Summary

StoneScriptPHP is a **modern PHP backend framework** for building RESTful APIs with PostgreSQL, inspired by Angular's developer experience. It follows a **function-first architecture** where business logic lives in PostgreSQL functions, and PHP serves as the orchestration layer.

### Core Philosophy
- **API-Only**: No HTML rendering, pure JSON APIs
- **PostgreSQL-First**: Business logic in database functions
- **Type-Safe**: Auto-generated models and TypeScript clients
- **CLI-Driven**: Code generators for rapid development
- **Composer-Based**: Framework in vendor/ for seamless upgrades

---

## Table of Contents

1. [Architecture Overview](#architecture-overview)
2. [System Components](#system-components)
3. [Data Flow](#data-flow)
4. [Technology Stack](#technology-stack)
5. [Design Patterns](#design-patterns)
6. [Security Architecture](#security-architecture)
7. [Performance Considerations](#performance-considerations)
8. [Deployment Architecture](#deployment-architecture)
9. [Future Enhancements](#future-enhancements)

---

## 1. Architecture Overview

### 1.1 Layered Architecture

```
┌─────────────────────────────────────────────────────┐
│                     HTTP Client                     │
│             (Browser, Mobile App, etc.)             │
└─────────────────────────────────────────────────────┘
                         │
                         │ HTTP/HTTPS (JSON)
                         ▼
┌─────────────────────────────────────────────────────┐
│                  Middleware Layer                   │
│  ┌───────────┬──────────┬──────────┬─────────────┐  │
│  │   CORS    │   Auth   │   Rate   │  Security   │  │
│  │           │          │  Limit   │   Headers   │  │
│  └───────────┴──────────┴──────────┴─────────────┘  │
└─────────────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────┐
│                    Routing Layer                    │
│                (URL → Route Handler)                │
│            Route Compilation & Matching             │
└─────────────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────┐
│                 Route Handler Layer                 │
│    ┌─────────────┐      ┌──────────────┐            │
│    │ Validation  │ ───▶ │   Service    │            │
│    │    Rules    │      │    Layer     │            │
│    └─────────────┘      └──────────────┘            │
└─────────────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────┐
│                    Service Layer                    │
│               (Business Logic - PHP)                │
│     ┌─────────────────────────────────────────┐     │
│     │     Calls Database Functions (ORM)      │     │
│     └─────────────────────────────────────────┘     │
└─────────────────────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────┐
│             Database Layer (PostgreSQL)             │
│    ┌─────────────┬─────────────┬─────────────┐      │
│    │  Functions  │   Tables    │  Triggers   │      │
│    │  (Business  │  (Schema)   │  (Events)   │      │
│    │   Logic)    │             │             │      │
│    └─────────────┴─────────────┴─────────────┘      │
└─────────────────────────────────────────────────────┘
                         │
                    ┌────┴────┐
                    ▼         ▼
          ┌─────────────┬─────────────┐
          │    Redis    │  External   │
          │    Cache    │    APIs     │
          └─────────────┴─────────────┘
```

### 1.2 Request Lifecycle

```
1. HTTP Request →
2. Middleware Pipeline (CORS, Auth, Rate Limit, Security) →
3. Router (Match URL to Handler) →
4. Route Handler Instantiation →
5. Input Validation →
6. Service Layer Invocation →
7. Database Function Call (via ORM) →
8. Cache Check/Store (if applicable) →
9. Response Transformation →
10. JSON Response ← HTTP
```

---

## 2. System Components

### 2.1 Framework Core (`Framework/`)

#### Router
- **File:** `Framework/Router.php`
- **Purpose:** Route matching and request dispatch
- **Features:**
  - Contract-based routing (URL → Class)
  - Route compilation for performance
  - Support for GET, POST, PUT, DELETE, OPTIONS
  - Dynamic path parameters

#### Database Abstraction
- **File:** `Framework/Database.php`
- **Purpose:** PostgreSQL connection and query execution
- **Features:**
  - Singleton connection pool
  - Parameterized queries (SQL injection prevention)
  - Type mapping (PostgreSQL ↔ PHP)
  - Array parameter support

#### Logger
- **File:** `Framework/Logger.php`
- **Purpose:** Production-ready logging system
- **Features:**
  - PSR-3 compatible (8 log levels)
  - Dual output (console + file)
  - ANSI color-coded console
  - Structured JSON logging (optional)
  - Context support

#### Exception Handler
- **Files:** `Framework/Exceptions.php`, `Framework/ExceptionHandler.php`
- **Purpose:** Global exception handling
- **Features:**
  - 12+ custom exception types
  - HTTP status code mapping
  - Structured error responses
  - Debug vs Production modes

#### Validator
- **File:** `Framework/Validator.php`
- **Purpose:** Input validation
- **Features:**
  - 12+ built-in rules (required, email, min, max, etc.)
  - Custom validation rules
  - Array validation
  - Nested object validation

#### Cache Manager
- **Files:** `Framework/Cache.php`, `Framework/CacheManager.php`
- **Purpose:** Redis integration
- **Features:**
  - Cache tags for grouped invalidation
  - TTL support
  - Automatic invalidation
  - Cache-aside pattern

### 2.2 Authentication & Authorization (`Framework/Auth/`)

#### JWT Handler
- **Files:** `Framework/Auth/JwtHandler.php`, `Framework/Auth/RsaJwtHandler.php`
- **Purpose:** Token-based authentication
- **Algorithms:** RSA (RS256), HMAC (HS256)
- **Features:**
  - Token generation
  - Token verification
  - Claims extraction
  - Expiration handling

#### OAuth Providers
- **File:** `Framework/Oauth/Google.php`
- **Purpose:** Third-party authentication
- **Providers:** Google OAuth 2.0
- **Flow:** Authorization Code flow

#### RBAC System
- **Files:** `src/App/Repositories/UserRepository.php`, `PermissionRepository.php`, `RoleRepository.php`
- **Purpose:** Role-Based Access Control
- **Features:**
  - Users, Roles, Permissions hierarchy
  - Attribute-based access control
  - Middleware-based enforcement

### 2.3 Middleware (`Framework/Http/Middleware/`)

#### Built-in Middleware
1. **CorsMiddleware** - Cross-Origin Resource Sharing
2. **AuthMiddleware** - JWT authentication
3. **RoleMiddleware** - Role-based access
4. **PermissionMiddleware** - Permission-based access
5. **RateLimitMiddleware** - Rate limiting (file-based, Redis planned)
6. **SecurityHeadersMiddleware** - Security headers (CSP, X-Frame-Options, etc.)
7. **LoggingMiddleware** - HTTP request/response logging
8. **AttributeAuthMiddleware** - Attribute-based auth

#### Middleware Pipeline
```php
Request → Middleware1 → Middleware2 → ... → Route Handler → ... → Middleware2 → Middleware1 → Response
```

### 2.4 CLI Tools (`Framework/cli/`)

#### Code Generators
- `generate-route.php` - Generate route handler classes
- `generate-model.php` - Generate models from SQL functions
- `generate-client.php` - Generate TypeScript clients
- `generate-env.php` - Generate .env configuration

#### Utilities
- `cli-server-router.php` - Built-in development server
- `setup.php` - Interactive project setup
- `migrate.php` - Database migration management

---

## 3. Data Flow

### 3.1 Typical API Request Flow

```
┌─────────────┐
│   Client    │
│ (HTTP GET)  │
└──────┬──────┘
       │
       │ GET /api/users/123
       ▼
┌─────────────────────────────────┐
│   Web Server (Nginx/Apache)     │
└──────────┬──────────────────────┘
           │
           │ Forward to PHP
           ▼
┌─────────────────────────────────┐
│     bootstrap.php               │
│  - Load environment             │
│  - Register autoloader          │
│  - Initialize logger            │
│  - Register exception handler   │
└──────────┬──────────────────────┘
           │
           ▼
┌─────────────────────────────────┐
│   Middleware Pipeline           │
│  1. CORS Check                  │
│  2. Auth Token Validation       │
│  3. Rate Limit Check            │
│  4. Security Headers            │
└──────────┬──────────────────────┘
           │
           ▼
┌─────────────────────────────────┐
│   Router::process_route()       │
│  - Match /api/users/123         │
│  - Map to GetUserRoute::class   │
└──────────┬──────────────────────┘
           │
           ▼
┌─────────────────────────────────┐
│   GetUserRoute::process()       │
│  1. Extract user_id = 123       │
│  2. Validate input              │
│  3. Call UserService            │
└──────────┬──────────────────────┘
           │
           ▼
┌─────────────────────────────────┐
│   UserService::getUser(123)     │
│  - Call FnGetUser::run(123)     │
└──────────┬──────────────────────┘
           │
           ▼
┌─────────────────────────────────┐
│   Database::query()             │
│  SELECT * FROM get_user(123)    │
└──────────┬──────────────────────┘
           │
           ▼
┌─────────────────────────────────┐
│   PostgreSQL                    │
│  FUNCTION get_user(p_id INT)    │
│  RETURNS TABLE (...)            │
└──────────┬──────────────────────┘
           │
           │ User data
           ▼
┌─────────────────────────────────┐
│   Transform & Cache             │
│  - Cache user:123 in Redis      │
│  - Format as ApiResponse        │
└──────────┬──────────────────────┘
           │
           │ JSON Response
           ▼
┌─────────────────────────────────┐
│   HTTP Response                 │
│  {                              │
│    "status": "ok",              │
│    "data": { "user": {...} }    │
│  }                              │
└─────────────────────────────────┘
```

### 3.2 Write Operation Flow

```
POST /api/orders

Client Request Body:
{
  "product_id": 456,
  "quantity": 2,
  "total": 99.99
}

↓ Middleware (Auth, Validation)
↓ Router → CreateOrderRoute
↓ Validation Rules Check
↓ Service Layer
↓ Database Function: create_order($product_id, $quantity, $total)
↓ PostgreSQL Transaction
↓ Cache Invalidation (orders:user:123)
↓ Response

Response:
{
  "status": "ok",
  "message": "Order created",
  "data": {
    "order_id": 789,
    "status": "pending"
  }
}
```

---

## 4. Technology Stack

### 4.1 Core Technologies

| Component | Technology | Version | Purpose |
|-----------|------------|---------|---------|
| **Runtime** | PHP | 8.2+ | Application runtime |
| **Database** | PostgreSQL | 13+ | Primary data store |
| **Cache** | Redis | 6+ | Caching layer |
| **Package Manager** | Composer | 2.0+ | Dependency management |
| **HTTP Server** | Nginx/Apache | Latest | Reverse proxy |
| **Container** | Docker | 20.10+ | Deployment |

### 4.2 PHP Extensions Required

- `pdo` - Database abstraction
- `pdo_pgsql` - PostgreSQL driver
- `json` - JSON encoding/decoding
- `redis` - Redis integration
- `openssl` - JWT signing (RSA)
- `mbstring` - String handling
- `curl` - External API calls

### 4.3 Development Tools

- **PHPUnit** - Unit testing framework
- **VS Code Extension** - Snippets and IntelliSense
- **Stone CLI** - Code generation tool
- **pgAdmin 4** - Database management

---

## 5. Design Patterns

### 5.1 Architectural Patterns

#### 1. **Function-First Architecture**
Business logic in PostgreSQL functions, PHP as orchestration layer.

**Benefits:**
- Logic close to data
- Consistent across clients
- Testable in database
- Type-safe

#### 2. **Repository Pattern**
Abstraction layer between domain and data mapping.

```php
UserRepository → Database::query('SELECT * FROM get_user($1)')
```

#### 3. **Service Layer Pattern**
Business logic separated from HTTP concerns.

```php
Route Handler → Service → Repository → Database
```

#### 4. **Middleware Pattern**
Cross-cutting concerns (auth, logging, etc.) as composable middleware.

```php
Pipeline: Middleware1 → Middleware2 → Handler
```

#### 5. **Singleton Pattern**
Database connection, Logger, Cache manager use singleton.

```php
Database::getInstance()
Logger::getInstance()
CacheManager::instance()
```

### 5.2 Code Organization Patterns

#### Route Handlers
```php
class CreateUserRoute implements IRouteHandler
{
    public function validation_rules(): array { }
    public function process(): ApiResponse { }
}
```

#### Database Functions
```php
class FnGetUser
{
    public static function run(int $user_id): ?array
    {
        return Database::query('SELECT * FROM get_user($1)', [$user_id]);
    }
}
```

#### Services
```php
class UserService
{
    public function getUser(int $id): ?User
    {
        return FnGetUser::run($id);
    }
}
```

---

## 6. Security Architecture

### 6.1 Defense in Depth

```
Layer 1: Network Security (Firewall, DDoS Protection)
   ↓
Layer 2: Web Server (Nginx, SSL/TLS)
   ↓
Layer 3: Application Middleware (Rate Limit, CORS)
   ↓
Layer 4: Authentication (JWT, OAuth)
   ↓
Layer 5: Authorization (RBAC)
   ↓
Layer 6: Input Validation
   ↓
Layer 7: SQL Injection Prevention (Parameterized Queries)
   ↓
Layer 8: Output Encoding (JSON only)
```

### 6.2 Security Features

| Feature | Implementation | Status |
|---------|---------------|--------|
| **SQL Injection** | Parameterized queries | ✅ |
| **XSS** | JSON-only responses | ✅ |
| **CSRF** | Token validation | 🔲 Planned |
| **Authentication** | JWT (RS256, HS256) | ✅ |
| **Authorization** | RBAC | ✅ |
| **Rate Limiting** | IP-based | ✅ |
| **CORS** | Whitelist | ✅ |
| **Security Headers** | Helmet-style | ✅ |
| **Password Hashing** | Argon2id | ✅ |
| **Audit Logging** | Via logging system | ⚠️ Implement |

### 6.3 Authentication Flow

```
1. User → POST /auth/login {email, password}
2. Server → Validate credentials
3. Server → Generate JWT token
4. Server → Response {token: "eyJ..."}
5. User → Store token (localStorage, cookie)
6. User → Subsequent requests: Authorization: Bearer eyJ...
7. Server → Middleware validates token
8. Server → Extract user_id from claims
9. Server → Process request with user context
```

---

## 7. Performance Considerations

### 7.1 Optimization Strategies

#### Database
- Connection pooling (singleton)
- Prepared statements (query caching)
- Function-based queries (database compilation)
- Index optimization (PostgreSQL)

#### Caching
- Redis for frequently accessed data
- Cache tags for invalidation
- TTL-based expiration
- Cache-aside pattern

#### Application
- Route compilation (pre-matched routes)
- Autoloader optimization (Composer)
- Minimal framework overhead (<3ms)
- Opcode caching (OPcache)

### 7.2 Performance Metrics

| Operation | Target | Typical |
|-----------|--------|---------|
| Route matching | <1ms | 0.8ms |
| Database query | <10ms | 3-5ms |
| Cache read | <2ms | 1.5ms |
| JWT validation | <3ms | 2.1ms |
| Full request | <50ms | 20-30ms |

### 7.3 Scalability

#### Horizontal Scaling
- Stateless application (JWT, no sessions)
- Redis for shared cache
- PostgreSQL read replicas
- Load balancer (Nginx, HAProxy)

#### Vertical Scaling
- Increase PHP-FPM workers
- PostgreSQL connection pooling (PgBouncer)
- Redis memory increase
- Dedicated cache server

---

## 8. Deployment Architecture

### 8.1 Production Deployment

```
┌──────────────────────────────────────────┐
│         Load Balancer (HAProxy)          │
│              SSL Termination             │
└────────────┬─────────────────────────────┘
             │
     ┌───────┴───────┐
     │               │
     ▼               ▼
┌─────────┐     ┌─────────┐
│  Web 1  │     │  Web 2  │
│  Nginx  │     │  Nginx  │
│ PHP-FPM │     │ PHP-FPM │
└────┬────┘     └────┬────┘
     │               │
     └───────┬───────┘
             │
     ┌───────┴───────┐
     │               │
     ▼               ▼
┌──────────┐   ┌──────────┐
│PostgreSQL│   │  Redis   │
│ Primary  │   │  Cache   │
└────┬─────┘   └──────────┘
     │
     ▼
┌──────────┐
│PostgreSQL│
│ Replica  │
└──────────┘
```

### 8.2 Docker Deployment

```yaml
services:
  web:
    image: php:8.2-fpm
    volumes: [./:/var/www]

  nginx:
    image: nginx:latest
    depends_on: [web]

  postgres:
    image: postgres:15

  redis:
    image: redis:7
```

### 8.3 Cloud Platforms

- **AWS:** EC2, RDS (PostgreSQL), ElastiCache (Redis), ALB
- **Azure:** App Service, Azure Database for PostgreSQL, Azure Cache for Redis
- **GCP:** Compute Engine, Cloud SQL, Memorystore
- **Heroku:** Dyno, Heroku Postgres, Heroku Redis

---

## 9. Future Enhancements

### 9.1 Planned Features (v1.1+)

#### Storage Providers
- Azure Blob Storage adapter
- AWS S3 adapter
- File upload handling
- Streaming support

#### Dependency Injection
- Service container
- Auto-wiring
- Service providers
- Factory pattern

#### Advanced Features
- WebSocket support
- Background job queue (Redis Queue)
- Event/Observer pattern
- Email template system
- Multi-tenancy support

#### Developer Tools
- Hot reload for development
- Debugging tools
- Profiling integration
- GraphQL support (experimental)

### 9.2 API Evolution

#### OpenAPI Specification
- Auto-generate OpenAPI 3.0 spec
- Swagger UI integration
- API versioning strategy (/v1/, /v2/)
- Deprecation warnings

#### TypeScript Client
- Auto-generated TypeScript SDK
- Type-safe API calls
- Angular/React integration
- WebSocket client

---

## 10. Appendix

### 10.1 File Structure

```
StoneScriptPHP/
├── Framework/              # Core framework (read-only)
│   ├── Auth/              # JWT, OAuth
│   ├── Http/              # Request, Response, Middleware
│   ├── cli/               # CLI tools
│   ├── Router.php
│   ├── Database.php
│   ├── Logger.php
│   ├── Exceptions.php
│   ├── ExceptionHandler.php
│   ├── Validator.php
│   └── Cache.php
│
├── src/
│   ├── App/               # Application code
│   │   ├── Routes/        # Route handlers
│   │   ├── Models/        # Generated models
│   │   ├── Services/      # Business logic
│   │   ├── Repositories/  # Data access
│   │   └── Lib/           # Utilities
│   │
│   ├── config/            # Configuration
│   │   ├── routes.php     # URL mappings
│   │   └── allowed-origins.php
│   │
│   └── postgresql/        # Database definitions
│       ├── tables/        # Schema (.pssql)
│       ├── functions/     # Functions (.pssql)
│       └── seeds/         # Seed data
│
├── public/                # Web root
│   └── index.php          # Entry point
│
├── tests/                 # PHPUnit tests
├── docs/                  # Documentation
├── logs/                  # Application logs
├── .env                   # Environment config
└── stone                  # CLI tool
```

### 10.2 References

- **Website:** https://stonescriptphp.org
- **GitHub:** https://github.com/progalaxyelabs/StoneScriptPHP
- **Documentation:** https://stonescriptphp.org/docs
- **Changelog:** [CHANGELOG.md](CHANGELOG.md)
- **API Reference:** https://stonescriptphp.org/docs/api-reference
- **Security Guide:** https://stonescriptphp.org/docs/security

---

**Document Revision History:**

| Version | Date | Changes | Author |
|---------|------|---------|--------|
| 1.0 | 2025-01-01 | Initial HLD | Team |
| 1.1 | 2025-11-30 | Added RBAC, Caching | Team |
| 1.2 | 2025-12-05 | Logging & Exception Handling | Team |
| 1.3 | 2026-01-13 | Updated to v2.4.2, Fixed broken documentation links | Team |

---

## Related Documentation

- **[← Back to README](README.md)** - Main project overview
- **[🌐 StoneScriptPHP Website](https://stonescriptphp.org)** - Official website
- **[📖 Online Documentation](https://stonescriptphp.org/docs)** - Complete documentation
- **[🚀 Getting Started](https://stonescriptphp.org/docs/getting-started)** - Quick start guide
- **[📦 Application Skeleton](https://github.com/progalaxyelabs/StoneScriptPHP-Server)** - Ready-to-use project template

---

**[StoneScriptPHP](https://stonescriptphp.org)** - Production-Ready API Framework

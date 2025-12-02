# Basic API Starter Template

A minimal StoneScriptPHP starter for building REST APIs with PostgreSQL.

## What's Included

- ✅ Basic StoneScriptPHP setup
- ✅ PostgreSQL database configuration
- ✅ Sample routes and models
- ✅ JWT authentication setup
- ✅ Docker development environment
- ✅ Testing configuration

## Quick Start

### Prerequisites

- PHP >= 8.2
- PostgreSQL >= 13
- Composer
- Docker & Docker Compose (optional)

### Setup

```bash
# 1. Create new project
composer create-project progalaxyelabs/stonescriptphp my-api

# 2. Copy this starter template
cp -r starters/basic-api/* my-api/

# 3. Navigate to project
cd my-api

# 4. Run interactive setup (creates .env, generates JWT keys)
php stone setup

# 5. Start development server
php stone serve
```

Your API is now running at http://localhost:9100

### Using Docker

```bash
# Start PostgreSQL and API
docker-compose up -d

# Run migrations
docker-compose exec api php stone migrate verify

# View logs
docker-compose logs -f api
```

## Project Structure

```
basic-api/
├── src/
│   ├── App/
│   │   ├── Routes/              # API route handlers
│   │   │   ├── HelloRoute.php   # Sample GET route
│   │   │   └── UsersRoute.php   # Sample POST route with validation
│   │   ├── Models/              # Database models
│   │   │   └── User.php         # Sample model
│   │   ├── Lib/                 # Custom utilities
│   │   └── Config/
│   │       └── routes.php       # URL routing configuration
│   └── postgresql/
│       ├── tables/              # Table definitions
│       │   └── users.pgsql      # Sample users table
│       ├── functions/           # SQL functions
│       │   └── get_user.pgsql   # Sample function
│       └── seeds/               # Seed data
├── tests/                       # PHPUnit tests
├── docker/                      # Docker configurations
├── .env.example                 # Environment template
├── docker-compose.yaml          # Docker orchestration
└── README.md                    # This file
```

## Development Workflow

### 1. Create Database Tables

Create `.pgsql` files in `src/postgresql/tables/`:

```sql
-- src/postgresql/tables/products.pgsql
CREATE TABLE IF NOT EXISTS products (
    id SERIAL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    created_at TIMESTAMP DEFAULT NOW()
);
```

### 2. Create SQL Functions

Create `.pgsql` files in `src/postgresql/functions/`:

```sql
-- src/postgresql/functions/get_products.pgsql
CREATE OR REPLACE FUNCTION get_products()
RETURNS TABLE (
    id INT,
    name VARCHAR,
    price DECIMAL,
    created_at TIMESTAMP
) AS $$
BEGIN
    RETURN QUERY SELECT * FROM products ORDER BY created_at DESC;
END;
$$ LANGUAGE plpgsql;
```

### 3. Generate PHP Model

```bash
php stone generate model get_products.pgsql
```

This creates `src/App/Models/GetProducts.php` with proper typing.

### 4. Create API Route

```bash
php stone generate route products
```

This creates `src/App/Routes/ProductsRoute.php`.

### 5. Map URL to Route

Edit `src/App/Config/routes.php`:

```php
return [
    'GET' => [
        '/products' => ProductsRoute::class,
    ],
    'POST' => [
        // ...
    ]
];
```

### 6. Implement Route Logic

```php
// src/App/Routes/ProductsRoute.php
public function process(): ApiResponse
{
    $products = GetProducts::run();

    return new ApiResponse('ok', 'Products retrieved', [
        'products' => $products
    ]);
}
```

### 7. Run Migrations

```bash
php stone migrate verify
```

## Available Commands

```bash
php stone setup              # Interactive project setup
php stone serve              # Start dev server (port 9100)
php stone generate route <name>   # Generate route handler
php stone generate model <file>   # Generate model from SQL
php stone migrate verify     # Verify database schema
php stone test               # Run PHPUnit tests
php stone env                # Generate .env file
```

## Sample API Endpoints

### GET /hello
```bash
curl http://localhost:9100/hello
```

Response:
```json
{
    "status": "ok",
    "message": "Hello from StoneScriptPHP!",
    "data": {
        "timestamp": "2025-12-01T10:30:00Z"
    }
}
```

### POST /users (with validation)
```bash
curl -X POST http://localhost:9100/users \
  -H "Content-Type: application/json" \
  -d '{
    "email": "user@example.com",
    "name": "John Doe",
    "age": 25
  }'
```

Response:
```json
{
    "status": "ok",
    "message": "User created successfully",
    "data": {
        "user_id": 1,
        "email": "user@example.com"
    }
}
```

## Testing

```bash
# Run all tests
php stone test

# Or use composer
composer test

# Run specific test
vendor/bin/phpunit tests/Routes/HelloRouteTest.php
```

## Environment Variables

Copy `.env.example` to `.env` and configure:

```env
# Database
DB_HOST=localhost
DB_PORT=5432
DB_NAME=my_api
DB_USER=postgres
DB_PASSWORD=postgres

# JWT Authentication
JWT_SECRET=your-secret-key-here
JWT_EXPIRY=3600

# Server
SERVER_PORT=9100
APP_ENV=development
```

## Authentication

This starter includes JWT authentication setup. To protect routes:

```php
use Framework\Middleware\AuthMiddleware;

class ProtectedRoute extends BaseRoute
{
    protected array $middleware = [AuthMiddleware::class];

    public function process(): ApiResponse
    {
        $userId = $this->getUserId(); // From JWT token
        // ... your logic
    }
}
```

## Next Steps

1. **Add more routes** - Use `php stone generate route <name>`
2. **Create database schema** - Add tables in `src/postgresql/tables/`
3. **Write SQL functions** - Add business logic in `src/postgresql/functions/`
4. **Add validation** - Use built-in validation rules or create custom ones
5. **Write tests** - Add test cases in `tests/`
6. **Deploy** - Use Docker Compose for production deployment

## Documentation

- [StoneScriptPHP Docs](https://stonescriptphp.org/docs)
- [CLI Usage Guide](https://github.com/progalaxyelabs/StoneScriptPHP/blob/main/CLI-USAGE.md)
- [Validation Guide](https://github.com/progalaxyelabs/StoneScriptPHP/blob/main/docs/validation.md)

## Support

- **Issues**: https://github.com/progalaxyelabs/StoneScriptPHP/issues
- **Documentation**: https://stonescriptphp.org/docs

## License

MIT

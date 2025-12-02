# Microservice Starter Template

A lightweight StoneScriptPHP microservice template for building distributed systems with inter-service communication.

## What's Included

- ✅ Minimal StoneScriptPHP API
- ✅ PostgreSQL database
- ✅ Service-to-service authentication
- ✅ Health check endpoints
- ✅ Docker containerization
- ✅ API gateway ready
- ✅ Message queue integration patterns
- ✅ Horizontal scaling support

## Architecture

```
┌─────────────────────────────────────────┐
│         Microservices Architecture       │
├─────────────────────────────────────────┤
│                                         │
│  ┌────────────┐      ┌────────────┐    │
│  │ Service A  │◄────►│ Service B  │    │
│  │  API:9100  │      │  API:9101  │    │
│  │   DB:5432  │      │   DB:5433  │    │
│  └────────────┘      └────────────┘    │
│         │                    │          │
│         └────────┬───────────┘          │
│                  ▼                      │
│          ┌──────────────┐               │
│          │ API Gateway  │               │
│          │   :8080      │               │
│          └──────────────┘               │
│                                         │
└─────────────────────────────────────────┘
```

## Use Cases

- **Distributed Systems** - Break monoliths into services
- **Domain-Driven Design** - Separate bounded contexts
- **Independent Scaling** - Scale services independently
- **Polyglot Architecture** - Mix technologies per service
- **Team Autonomy** - Independent deployment cycles

## Quick Start

### Single Microservice

```bash
# 1. Copy this starter
cp -r starters/microservice my-service
cd my-service

# 2. Configure environment
cp .env.example .env

# 3. Start service
docker-compose up -d

# 4. Check health
curl http://localhost:9100/health
```

### Multiple Microservices

```bash
# Create multiple services
cp -r starters/microservice user-service
cp -r starters/microservice product-service
cp -r starters/microservice order-service

# Update ports in each .env file
# user-service: API_PORT=9100, DB_PORT=5432
# product-service: API_PORT=9101, DB_PORT=5433
# order-service: API_PORT=9102, DB_PORT=5434

# Start all services
cd user-service && docker-compose up -d
cd product-service && docker-compose up -d
cd order-service && docker-compose up -d
```

## Project Structure

```
microservice/
├── docker-compose.yaml          # Service orchestration
├── .env.example                 # Environment template
├── nginx.conf                   # API Gateway config (optional)
│
├── src/
│   ├── App/
│   │   ├── Routes/
│   │   │   ├── HealthRoute.php      # Health check
│   │   │   ├── ServiceInfoRoute.php # Service metadata
│   │   │   └── ... your routes
│   │   ├── Models/              # Database models
│   │   ├── Services/            # Business logic
│   │   │   └── ServiceClient.php # Inter-service HTTP client
│   │   └── Config/
│   │       ├── routes.php       # Route mapping
│   │       └── services.php     # Service registry
│   └── postgresql/
│       ├── tables/
│       ├── functions/
│       └── seeds/
│
└── tests/
```

## Key Features

### 1. Health Checks

Every microservice exposes health endpoints:

```bash
# Basic health check
GET /health

Response:
{
  "status": "ok",
  "service": "user-service",
  "version": "1.0.0",
  "timestamp": "2025-12-01T10:00:00Z"
}

# Detailed health check
GET /health/detailed

Response:
{
  "status": "ok",
  "service": "user-service",
  "database": "connected",
  "uptime": 3600,
  "memory_usage": "45MB"
}
```

### 2. Service-to-Service Communication

Use the `ServiceClient` to call other microservices:

```php
// src/App/Services/ServiceClient.php
use App\Services\ServiceClient;

$client = new ServiceClient('product-service');

// GET request
$products = $client->get('/products');

// POST request with authentication
$newProduct = $client->post('/products', [
    'name' => 'Product Name',
    'price' => 99.99
], [
    'Authorization' => 'Bearer ' . $serviceToken
]);
```

### 3. Service Registry

Configure service endpoints in `src/App/Config/services.php`:

```php
return [
    'user-service' => [
        'url' => env('USER_SERVICE_URL', 'http://user-service:9100'),
        'timeout' => 5000,
    ],
    'product-service' => [
        'url' => env('PRODUCT_SERVICE_URL', 'http://product-service:9101'),
        'timeout' => 5000,
    ],
    'order-service' => [
        'url' => env('ORDER_SERVICE_URL', 'http://order-service:9102'),
        'timeout' => 5000,
    ],
];
```

### 4. Inter-Service Authentication

Use JWT tokens for service-to-service auth:

```php
use Framework\JWT\JWTService;

// Generate service token
$token = JWTService::generateServiceToken('user-service', [
    'permissions' => ['read:products', 'write:orders']
]);

// Validate service token
$payload = JWTService::validateServiceToken($token);
```

## Docker Compose Setup

### Single Service

```yaml
version: '3.8'

services:
  db:
    image: postgres:16-alpine
    environment:
      POSTGRES_DB: user_service_db
      POSTGRES_USER: postgres
      POSTGRES_PASSWORD: postgres
    ports:
      - "5432:5432"

  api:
    build: .
    ports:
      - "9100:9100"
    environment:
      DB_HOST: db
      SERVICE_NAME: user-service
    depends_on:
      - db
```

### Multiple Services with Gateway

```yaml
version: '3.8'

services:
  gateway:
    image: nginx:alpine
    ports:
      - "8080:8080"
    volumes:
      - ./nginx.conf:/etc/nginx/nginx.conf
    depends_on:
      - user-service
      - product-service

  user-service:
    build: ./user-service
    ports:
      - "9100:9100"

  product-service:
    build: ./product-service
    ports:
      - "9101:9101"
```

## API Gateway (Nginx)

Route requests to appropriate microservices:

```nginx
upstream user_service {
    server user-service:9100;
}

upstream product_service {
    server product-service:9101;
}

server {
    listen 8080;

    location /api/users {
        proxy_pass http://user_service;
    }

    location /api/products {
        proxy_pass http://product_service;
    }

    location /health {
        # Aggregate health from all services
        return 200 '{"status": "ok"}';
    }
}
```

## Communication Patterns

### 1. Synchronous (HTTP)

```php
// Direct HTTP call
$client = new ServiceClient('product-service');
$products = $client->get('/products');
```

### 2. Asynchronous (Message Queue)

```php
// Publish event
$eventBus->publish('order.created', [
    'order_id' => 123,
    'user_id' => 456
]);

// Subscribe to event
$eventBus->subscribe('order.created', function($event) {
    // Handle order created event
});
```

### 3. Event Sourcing

```php
// Store events
$eventStore->append('user-stream-123', [
    'type' => 'UserCreated',
    'data' => ['email' => 'user@example.com']
]);

// Replay events
$events = $eventStore->read('user-stream-123');
```

## Deployment Patterns

### 1. Docker Swarm

```bash
docker stack deploy -c docker-compose.yaml my-services
```

### 2. Kubernetes

```yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: user-service
spec:
  replicas: 3
  selector:
    matchLabels:
      app: user-service
  template:
    spec:
      containers:
      - name: api
        image: user-service:latest
        ports:
        - containerPort: 9100
```

### 3. Horizontal Scaling

```bash
# Scale specific service
docker-compose up -d --scale user-service=3
```

## Monitoring & Observability

### Health Checks

```bash
# Check all services
curl http://gateway:8080/health
curl http://user-service:9100/health
curl http://product-service:9101/health
```

### Logging

```php
use Framework\Logger;

Logger::info('Service started', [
    'service' => 'user-service',
    'port' => 9100
]);
```

### Metrics

Export metrics for Prometheus/Grafana:

```php
// GET /metrics
return new ApiResponse('ok', 'Metrics', [
    'requests_total' => 1234,
    'requests_per_second' => 45,
    'average_response_time' => 120
]);
```

## Best Practices

1. **Keep Services Small** - Single responsibility per service
2. **Database per Service** - Don't share databases
3. **API Versioning** - Use `/v1/`, `/v2/` prefixes
4. **Circuit Breakers** - Handle service failures gracefully
5. **Idempotency** - Make operations repeatable
6. **Async Communication** - Use message queues for heavy tasks
7. **Centralized Logging** - Aggregate logs from all services
8. **Service Discovery** - Use Consul, Eureka, or DNS
9. **Configuration Management** - Externalize configuration
10. **Automated Testing** - Test service contracts

## Common Patterns

### Saga Pattern (Distributed Transactions)

```php
// OrderService
$saga = new Saga();
$saga->addStep('reserve-inventory', function() {
    return $this->inventoryService->reserve($items);
});
$saga->addStep('charge-payment', function() {
    return $this->paymentService->charge($amount);
});
$saga->addStep('create-order', function() {
    return $this->orderService->create($order);
});
$saga->execute();
```

### CQRS (Command Query Responsibility Segregation)

```php
// Command (Write)
class CreateUserCommand {
    public function execute($data) {
        // Write to database
    }
}

// Query (Read)
class GetUserQuery {
    public function execute($userId) {
        // Read from cache/read replica
    }
}
```

## Testing Microservices

```bash
# Unit tests
php stone test

# Integration tests (test service communication)
docker-compose -f docker-compose.test.yaml up --abort-on-container-exit

# Contract tests (verify API contracts)
vendor/bin/phpunit tests/Contracts/
```

## Documentation

- [StoneScriptPHP Docs](https://stonescriptphp.org/docs)
- [Microservices Patterns](https://microservices.io/patterns/)
- [12-Factor App](https://12factor.net/)

## Support

- **Issues**: https://github.com/progalaxyelabs/StoneScriptPHP/issues
- **Documentation**: https://stonescriptphp.org/docs

## License

MIT

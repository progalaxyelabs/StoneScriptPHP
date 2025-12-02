# StoneScriptPHP Framework - High Level Design

## Overview
Modern PHP backend framework for building RESTful APIs with PostgreSQL, inspired by Angular's developer experience.

## Architecture

### Core Components
- **Framework/Http/** - HTTP request/response handling
- **Framework/Routing/** - Contract-based routing system
- **Framework/Database/** - PostgreSQL integration with ORM
- **Framework/Auth/** - JWT authentication and OAuth providers
- **Framework/Validation/** - Request validation layer
- **Framework/Middleware/** - Middleware pipeline system
- **Framework/CLI/** - Stone CLI tool for code generation

### Design Principles
1. **API-Only** - No HTML rendering, pure JSON APIs
2. **PostgreSQL-First** - SQL functions as business logic layer
3. **Type-Safe** - Auto-generate TypeScript clients from PHP DTOs
4. **CLI-Driven** - Code generators for routes, models, migrations
5. **Composer-Based** - Framework lives in vendor/ for upgradeable architecture

## Tech Stack
- PHP >= 8.2
- PostgreSQL >= 13
- Composer for dependency management
- Built-in PHP server for development

## Deployment
- Docker containers
- Nginx reverse proxy to `php stone serve`
- Cloud platforms (Heroku, AWS, Azure, GCP)

## Related Projects
- ngx-stonescriptphp-client - Angular HTTP client
- sunbird-garden - Reference implementation
- stonescriptphp-www - Marketing website

# StoneScriptPHP Documentation

**Version:** 1.0.0
**Last Updated:** December 5, 2025

---

## 📚 Table of Contents

### Getting Started
- [**Getting Started Guide**](getting-started.md) - Complete tutorial from installation to deployment
- [**CLI Usage**](CLI-USAGE.md) - Command reference for the Stone CLI tool
- [**Environment Configuration**](environment-configuration.md) - Type-safe environment setup

### Core Concepts
- [**API Reference**](api-reference.md) - Complete framework API documentation
- [**Routing & Handlers**](getting-started.md#routes-and-url-mapping) - Route configuration and handlers
- [**Database & Models**](getting-started.md#sql-functions--php-models) - PostgreSQL functions and PHP models
- [**Validation**](validation.md) - Request validation system

### Features
- [**Authentication**](authentication.md) - JWT and OAuth (Google)
- [**Caching**](CACHING.md) - Redis integration with cache tags
- [**Middleware**](MIDDLEWARE.md) - HTTP middleware pipeline
- [**RBAC (Role-Based Access Control)**](RBAC.md) - Permissions and roles system
- [**Logging & Exceptions**](logging-and-exceptions.md) - Production-ready logging

### Security
- [**Security Best Practices**](security-best-practices.md) - Comprehensive security guide
- [**RBAC Implementation**](RBAC_IMPLEMENTATION_SUMMARY.md) - Access control implementation
- [**RBAC Quickstart**](RBAC_QUICKSTART.md) - Quick guide to RBAC

### Architecture & Design
- [**API Design Guidelines**](api-design-guidelines.md) - REST API design patterns
- [**Coding Standards**](coding-standards.md) - PHP coding conventions
- [**Performance Guidelines**](performance-guidelines.md) - Optimization best practices
- [**Migration Playbook**](migration-playbook.md) - Database migration strategies

### Advanced Topics
- [**CLI API Server**](cli-api-server.md) - Built-in development server
- [**RBAC Complete Example**](RBAC_COMPLETE_EXAMPLE.md) - Full RBAC implementation
- [**Cache Integration Summary**](CACHE-INTEGRATION-SUMMARY.md) - Redis caching details
- [**Test Coverage**](test-coverage-summary.md) - Testing guidelines

---

## 🔍 Quick Links

### New to StoneScriptPHP?
1. Start with [Getting Started Guide](getting-started.md)
2. Learn [CLI Usage](../CLI-USAGE.md)
3. Read [API Reference](api-reference.md)

### Building an API?
1. [API Design Guidelines](api-design-guidelines.md)
2. [Authentication](authentication.md)
3. [Validation](validation.md)
4. [Security Best Practices](security-best-practices.md)

### Going to Production?
1. [Security Best Practices](security-best-practices.md)
2. [Performance Guidelines](performance-guidelines.md)
3. [Logging & Exceptions](logging-and-exceptions.md)
4. [Environment Configuration](environment-configuration.md)

---

## 📖 Documentation Structure

```
docs/
├── INDEX.md (this file)
│
├── Getting Started
│   ├── getting-started.md
│   ├── environment-configuration.md
│   └── ../CLI-USAGE.md
│
├── Core Features
│   ├── api-reference.md
│   ├── validation.md
│   ├── authentication.md
│   ├── CACHING.md
│   └── MIDDLEWARE.md
│
├── Security & RBAC
│   ├── security-best-practices.md
│   ├── RBAC.md
│   ├── RBAC_QUICKSTART.md
│   ├── RBAC_IMPLEMENTATION_SUMMARY.md
│   └── RBAC_COMPLETE_EXAMPLE.md
│
├── Logging & Errors
│   └── logging-and-exceptions.md
│
├── Best Practices
│   ├── api-design-guidelines.md
│   ├── coding-standards.md
│   ├── performance-guidelines.md
│   └── migration-playbook.md
│
└── Advanced
    ├── cli-api-server.md
    ├── test-coverage-summary.md
    └── CACHE-INTEGRATION-SUMMARY.md
```

---

## 🎯 By Use Case

### I want to...

**Build a REST API**
→ [Getting Started](getting-started.md) → [API Design](api-design-guidelines.md) → [Validation](validation.md)

**Add Authentication**
→ [Authentication Guide](authentication.md) → [RBAC Quickstart](RBAC_QUICKSTART.md)

**Improve Performance**
→ [Caching Guide](CACHING.md) → [Performance Guidelines](performance-guidelines.md)

**Secure My API**
→ [Security Best Practices](security-best-practices.md) → [RBAC](RBAC.md)

**Debug Issues**
→ [Logging & Exceptions](logging-and-exceptions.md) → [Test Coverage](test-coverage-summary.md)

**Deploy to Production**
→ [Getting Started: Deployment](getting-started.md#deployment) → [Security](security-best-practices.md)

---

## 📌 Key Concepts

### Framework Architecture
StoneScriptPHP follows a **function-first approach**:
1. Write SQL functions (business logic in PostgreSQL)
2. Generate PHP models from SQL functions
3. Create route handlers that call models
4. Map URLs to route handlers

### Core Principles
- ✅ **API-Only** - No HTML rendering, pure JSON APIs
- ✅ **PostgreSQL-First** - Business logic in database functions
- ✅ **Type-Safe** - Auto-generated models and TypeScript clients
- ✅ **CLI-Driven** - Code generators for everything
- ✅ **Composer-Based** - Framework upgrades without touching your code

---

## 🆘 Help & Support

- **Website:** [https://stonescriptphp.org](https://stonescriptphp.org)
- **GitHub Issues:** [https://github.com/progalaxyelabs/StoneScriptPHP/issues](https://github.com/progalaxyelabs/StoneScriptPHP/issues)
- **Examples:** See `/examples` folder in the repository

---

## 📝 Contributing to Documentation

Found an issue or want to improve the docs? Please:
1. Open an issue on GitHub
2. Submit a pull request
3. Follow the [Coding Standards](coding-standards.md)

---

**Happy Coding with StoneScriptPHP! 🚀**

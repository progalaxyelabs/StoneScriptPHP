# StoneScriptPHP Documentation

**Version:** 1.0.0
**Last Updated:** December 13, 2025

---

## 📚 Table of Contents

### Getting Started
- [**Getting Started Guide**](guides/getting-started.md) - Complete tutorial from installation to deployment
- [**CLI Usage**](reference/cli-usage.md) - Command reference for the Stone CLI tool
- [**Environment Configuration**](reference/environment-configuration.md) - Type-safe environment setup
- [**Setup Quiet Mode**](guides/setup-quiet-mode.md) - Automated setup for CI/CD

### User Guides
- [**Authentication**](guides/authentication.md) - JWT and OAuth (Google)
- [**JWT Configuration**](guides/jwt-configuration.md) - Interactive JWT setup
- [**RBAC Quickstart**](guides/rbac-quickstart.md) - Quick guide to role-based access control
- [**RBAC Complete Example**](guides/rbac-complete-example.md) - Full RBAC implementation

### Reference Documentation
- [**API Reference**](reference/api-reference.md) - Complete framework API documentation
- [**API Design Guidelines**](reference/api-design-guidelines.md) - REST API design patterns
- [**Coding Standards**](reference/coding-standards.md) - PHP coding conventions
- [**Caching**](reference/caching.md) - Redis integration with cache tags
- [**Middleware**](reference/middleware.md) - HTTP middleware pipeline
- [**RBAC**](reference/rbac.md) - Permissions and roles system

### Security
- [**Security Best Practices**](security/security-best-practices.md) - Comprehensive security guide
- [**CSRF Protection**](security/csrf-protection.md) - Cross-site request forgery prevention
- [**hCaptcha Integration**](security/hcaptcha-integration.md) - CAPTCHA for bot protection
- [**Bot Protection Strategy**](security/bot-protection-strategy.md) - Multi-layer bot defense
- [**Proof of Work Integration**](security/proof-of-work-integration.md) - Client-side PoW challenges

### General Documentation
- [**Validation**](validation.md) - Request validation system
- [**Logging & Exceptions**](logging-and-exceptions.md) - Production-ready logging
- [**Performance Guidelines**](performance-guidelines.md) - Optimization best practices
- [**CLI API Server**](cli-api-server.md) - Built-in development server
- [**Upgrade Guide**](UPGRADE.md) - Version upgrade instructions
- [**Release Notes**](releases.md) - Framework release history

### For Contributors
- [**Internal Documentation**](internal/) - Implementation summaries and development guides

---

## 🔍 Quick Links

### New to StoneScriptPHP?
1. Start with [Getting Started Guide](guides/getting-started.md)
2. Learn [CLI Usage](reference/cli-usage.md)
3. Read [API Reference](reference/api-reference.md)

### Building an API?
1. [API Design Guidelines](reference/api-design-guidelines.md)
2. [Authentication](guides/authentication.md)
3. [Validation](validation.md)
4. [Security Best Practices](security/security-best-practices.md)

### Going to Production?
1. [Security Best Practices](security/security-best-practices.md)
2. [Performance Guidelines](performance-guidelines.md)
3. [Logging & Exceptions](logging-and-exceptions.md)
4. [Environment Configuration](reference/environment-configuration.md)

---

## 📖 Documentation Structure

```
docs/
├── INDEX.md (this file)
│
├── guides/                          # User-facing tutorials and how-tos
│   ├── getting-started.md
│   ├── authentication.md
│   ├── jwt-configuration.md
│   ├── setup-quiet-mode.md
│   ├── rbac-quickstart.md
│   └── rbac-complete-example.md
│
├── reference/                       # Technical specifications and API docs
│   ├── api-reference.md
│   ├── api-design-guidelines.md
│   ├── coding-standards.md
│   ├── environment-configuration.md
│   ├── cli-usage.md
│   ├── caching.md
│   ├── middleware.md
│   └── rbac.md
│
├── security/                        # Security features and best practices
│   ├── security-best-practices.md
│   ├── csrf-protection.md
│   ├── hcaptcha-integration.md
│   ├── bot-protection-strategy.md
│   └── proof-of-work-integration.md
│
├── internal/                        # Implementation details (for contributors)
│   ├── CACHE-INTEGRATION-SUMMARY.md
│   ├── DOCUMENTATION-SUMMARY.md
│   ├── DUAL-MODE-IMPLEMENTATION.md
│   ├── LOGGING-IMPLEMENTATION-SUMMARY.md
│   ├── RBAC_IMPLEMENTATION_SUMMARY.md
│   ├── MIDDLEWARE_IMPLEMENTATION.md
│   ├── RELEASE.md
│   ├── SECURITY_IMPLEMENTATION_SUMMARY.md
│   ├── TESTING-MULTI-TENANCY.md
│   ├── test-coverage-summary.md
│   ├── diag-report.md
│   └── migration-playbook.md
│
└── General documentation (docs root)
    ├── validation.md
    ├── logging-and-exceptions.md
    ├── performance-guidelines.md
    ├── cli-api-server.md
    ├── UPGRADE.md
    └── releases.md
```

---

## 🎯 By Use Case

### I want to...

**Build a REST API**
→ [Getting Started](guides/getting-started.md) → [API Design](reference/api-design-guidelines.md) → [Validation](validation.md)

**Add Authentication**
→ [Authentication Guide](guides/authentication.md) → [JWT Configuration](guides/jwt-configuration.md) → [RBAC Quickstart](guides/rbac-quickstart.md)

**Improve Performance**
→ [Caching Guide](reference/caching.md) → [Performance Guidelines](performance-guidelines.md)

**Secure My API**
→ [Security Best Practices](security/security-best-practices.md) → [CSRF Protection](security/csrf-protection.md) → [Bot Protection](security/bot-protection-strategy.md)

**Debug Issues**
→ [Logging & Exceptions](logging-and-exceptions.md) → [Test Coverage](internal/test-coverage-summary.md)

**Deploy to Production**
→ [Getting Started: Deployment](guides/getting-started.md#deployment) → [Security](security/security-best-practices.md) → [Setup Quiet Mode](guides/setup-quiet-mode.md)

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

# Release 1.3.0 Roadmap

**Target Date:** December 15, 2025
**Status:** Planning

## Planned Features

1. ✅ Fix 34 failing unit tests (database mocking)
2. ⏳ Move rate limiting to Redis (from file-based)
3. ⏳ Add `/health` endpoint for monitoring
4. ⏳ Azure Blob Storage provider
5. ⏳ AWS S3 storage provider
6. ⏳ Dependency Injection container
7. ⏳ CSRF protection middleware
8. ⏳ Integration test suite
9. ⏳ File upload handling
10. ⏳ Audit logging system

## Known Issues

- Database tests require PostgreSQL connection (workaround: active DB connection)
- File-based rate limiting not suitable for distributed systems

**For complete release history, see [docs/releases.md](docs/releases.md)**

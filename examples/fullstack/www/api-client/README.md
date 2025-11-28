# API Client

Auto-generated TypeScript API client for StoneScriptPHP backend.

**DO NOT EDIT MANUALLY** - Regenerate using: `php generate client`

## Installation

### For Angular Projects

```bash
npm install file:../client
```

### Import and Use

```typescript
import { api } from '@stonescript/api-client';

// Use the typed API client
const result = await api.postLogin({
  email: 'user@example.com',
  password: 'secret'
});

console.log(result.token);
```

## Development

### Build

```bash
npm run build
```

This compiles TypeScript to JavaScript in the `dist/` directory.

## Regenerating

When routes change on the backend:

```bash
cd /path/to/backend
php generate client
```

This will update all types and API functions automatically.

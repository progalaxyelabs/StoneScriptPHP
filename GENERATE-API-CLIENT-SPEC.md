# TypeScript API Client Generator Specification

**Command:** `php stone generate client`
**Version:** 2.0.0
**Last Updated:** 2026-01-14
**Status:** Specification Document

---

## Table of Contents

1. [Overview](#overview)
2. [Developer Workflow](#developer-workflow)
3. [Command Interface](#command-interface)
4. [Architecture](#architecture)
5. [Generated Output Structure](#generated-output-structure)
6. [Integration with ngx-stonescriptphp-client](#integration-with-ngx-stonescriptphp-client)
7. [Type Generation](#type-generation)
8. [Error Handling](#error-handling)
9. [Examples](#examples)
10. [Implementation Checklist](#implementation-checklist)

---

## Overview

The `php stone generate client` command generates a type-safe TypeScript API client from PHP routes. This client is designed to work seamlessly with `@progalaxyelabs/ngx-stonescriptphp-client`, separating concerns between:

- **Generated API Client**: Type-safe endpoints, request/response models, platform-specific CRUD operations
- **ngx-stonescriptphp-client**: Generic HTTP transport, authentication, token management, 401 retry logic

### Key Principles

1. **Type Safety**: Full TypeScript types from PHP DTOs
2. **Separation of Concerns**: API operations vs HTTP transport
3. **Developer Experience**: Simple `npm install` workflow
4. **Auto-generation**: Regenerate when backend changes
5. **Framework Agnostic**: Works with Angular, React, Vue, vanilla JS

---

## Developer Workflow

### Initial Project Setup

```bash
# 1. Create backend API
composer create-project progalaxyelabs/stonescriptphp-server my-api
cd my-api

# 2. Create frontend
cd ..
ng new my-portal
cd my-portal

# 3. Install ngx-client
npm install @progalaxyelabs/ngx-stonescriptphp-client
```

### Backend Development

```bash
# In api/ directory

# 1. Create route
php stone generate route create-project

# 2. Define DTOs (Request/Response models)
# Created in src/App/DTO/

# 3. Implement route handler
# Created in src/App/Routes/

# 4. Register route in config/routes.php

# 5. Generate TypeScript client
php stone generate client --output=../my-portal/src/api-client

# Output: ../my-portal/src/api-client/ package created
```

### Frontend Development

```bash
# In my-portal/ directory

# 1. Install generated API client
npm install file:./src/api-client

# 2. Use in Angular services
# import { api } from '@myapi/client';
# await api.projects.create({ name: 'My Project' });
```

### Iterative Development

```bash
# Backend changes → Regenerate client → Auto-updates in frontend

# 1. Modify backend route/DTOs
# 2. Regenerate client
cd api && php stone generate client --output=../my-portal/src/api-client

# 3. Frontend sees new types immediately (no reinstall needed with file: protocol)
```

---

## Command Interface

### Basic Usage

```bash
php stone generate client
```

**Default behavior:**
- Scans `src/config/routes.php`
- Generates client in `api-client/` directory
- Package name: `@myapi/client` (derived from project name)

### Options

```bash
php stone generate client [options]

Options:
  --output=<dir>         Output directory (default: client)
  --name=<package>       Package name (default: @<project>/client)
  --base-url=<url>       Base API URL for development (default: http://localhost:9100)
  --help, -h             Show help message

Examples:
  php stone generate client
  php stone generate client --output=../portal/src/api-client
  php stone generate client --name=@progalaxy/api --output=../www/src/api-client
  php stone generate client --base-url=https://api.progalaxy.in
```

---

## Architecture

### Current Implementation (v1)

The existing `generate-client.php` creates a standalone client with `fetch()` calls:

```typescript
// Current output
export const api = {
  async postLogin(data: PostLoginRequest): Promise<PostLoginResponse> {
    const response = await fetch('/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data),
    });

    if (!response.ok) {
      throw new Error(`HTTP error! status: ${response.status}`);
    }

    const json = await response.json();
    return json.data;
  }
};
```

**Problems:**
- ❌ No authentication handling
- ❌ No token refresh on 401
- ❌ No CSRF protection
- ❌ No consistent error handling
- ❌ Duplicated HTTP logic across endpoints

### Proposed Architecture (v2)

Generate a client that **uses** `ngx-stonescriptphp-client` for HTTP transport:

```typescript
// Proposed output
import { ApiConnectionService, ApiResponse } from '@progalaxyelabs/ngx-stonescriptphp-client';

export class ApiClient {
  constructor(private connection: ApiConnectionService) {}

  // Grouped by resource
  projects = {
    create: async (data: CreateProjectRequest): Promise<CreateProjectResponse> => {
      const response = await this.connection.post<CreateProjectResponse>(
        '/projects/create',
        data
      );

      return new Promise((resolve, reject) => {
        response
          .onOk((project) => resolve(project))
          .onNotOk((message) => reject(new Error(message)))
          .onError(() => reject(new Error('Server error')));
      });
    },

    list: async (): Promise<ListProjectsResponse> => {
      const response = await this.connection.get<ListProjectsResponse>('/projects');

      return new Promise((resolve, reject) => {
        response
          .onOk((projects) => resolve(projects))
          .onNotOk((message) => reject(new Error(message)))
          .onError(() => reject(new Error('Server error')));
      });
    }
  };
}
```

**Benefits:**
- ✅ Uses ngx-client for auth, refresh, CSRF
- ✅ Consistent error handling via ApiResponse
- ✅ Type-safe request/response
- ✅ Grouped by resource for better organization
- ✅ Works with Angular DI or standalone

---

## Generated Output Structure

```
api-client/
├── package.json          # NPM package config
├── tsconfig.json         # TypeScript config
├── README.md            # Installation instructions
├── .gitignore           # Ignore node_modules, dist
└── src/
    ├── index.ts         # Main exports
    ├── types.ts         # Generated interfaces from DTOs
    ├── client.ts        # ApiClient class
    └── resources/       # Resource-grouped endpoints
        ├── projects.ts
        ├── users.ts
        └── tasks.ts
```

### package.json

```json
{
  "name": "@myapi/client",
  "version": "1.0.0",
  "description": "Auto-generated TypeScript API client",
  "main": "dist/index.js",
  "types": "dist/index.d.ts",
  "scripts": {
    "build": "tsc",
    "watch": "tsc --watch"
  },
  "keywords": ["api", "client", "typescript"],
  "author": "",
  "license": "MIT",
  "peerDependencies": {
    "@progalaxyelabs/ngx-stonescriptphp-client": "^2.0.0"
  },
  "devDependencies": {
    "typescript": "^5.8.0",
    "@progalaxyelabs/ngx-stonescriptphp-client": "^2.0.0"
  },
  "files": [
    "dist",
    "src",
    "README.md"
  ]
}
```

### tsconfig.json

```json
{
  "compilerOptions": {
    "target": "ES2020",
    "module": "ES2020",
    "lib": ["ES2020", "DOM"],
    "declaration": true,
    "outDir": "./dist",
    "rootDir": "./src",
    "strict": true,
    "esModuleInterop": true,
    "skipLibCheck": true,
    "forceConsistentCasingInFileNames": true,
    "moduleResolution": "node"
  },
  "include": ["src/**/*"],
  "exclude": ["node_modules", "dist"]
}
```

### src/index.ts

```typescript
/**
 * Auto-generated TypeScript API Client
 * Generated from PHP routes
 *
 * DO NOT EDIT MANUALLY - Regenerate using: php stone generate api
 */

export * from './types';
export * from './client';
export { ApiClient } from './client';
```

### src/types.ts

```typescript
/**
 * Auto-generated type definitions
 * Generated from PHP DTOs
 */

// Request DTOs
export interface CreateProjectRequest {
  name: string;
  description: string;
  start_date?: string | null;
  tenant_id?: string | null;
}

export interface UpdateProjectRequest {
  project_id: number;
  name?: string | null;
  description?: string | null;
  status?: string | null;
}

// Response DTOs
export interface CreateProjectResponse {
  project_id: number;
  name: string;
  description: string;
  created_at: string;
  created_by: number;
}

export interface ListProjectsResponse {
  projects: Project[];
  total: number;
}

// Nested types
export interface Project {
  project_id: number;
  name: string;
  description: string;
  status: string;
  created_at: string;
}
```

### src/client.ts

```typescript
import { ApiConnectionService, ApiResponse } from '@progalaxyelabs/ngx-stonescriptphp-client';
import * as Types from './types';

export class ApiClient {
  constructor(private connection: ApiConnectionService) {}

  /**
   * Project-related endpoints
   */
  projects = {
    /**
     * Create a new project
     */
    create: async (data: Types.CreateProjectRequest): Promise<Types.CreateProjectResponse> => {
      const response = await this.connection.post<Types.CreateProjectResponse>(
        '/projects/create',
        data
      );

      return new Promise((resolve, reject) => {
        response
          .onOk((project) => resolve(project))
          .onNotOk((message) => reject(new Error(message)))
          .onError(() => reject(new Error('Server error')));
      });
    },

    /**
     * List all projects
     */
    list: async (): Promise<Types.ListProjectsResponse> => {
      const response = await this.connection.get<Types.ListProjectsResponse>('/projects');

      return new Promise((resolve, reject) => {
        response
          .onOk((projects) => resolve(projects))
          .onNotOk((message) => reject(new Error(message)))
          .onError(() => reject(new Error('Server error')));
      });
    },

    /**
     * Update project by ID
     */
    update: async (data: Types.UpdateProjectRequest): Promise<Types.CreateProjectResponse> => {
      const response = await this.connection.put<Types.CreateProjectResponse>(
        `/projects/${data.project_id}`,
        data
      );

      return new Promise((resolve, reject) => {
        response
          .onOk((project) => resolve(project))
          .onNotOk((message) => reject(new Error(message)))
          .onError(() => reject(new Error('Server error')));
      });
    },

    /**
     * Delete project by ID
     */
    delete: async (project_id: number): Promise<void> => {
      const response = await this.connection.delete<void>(`/projects/${project_id}`);

      return new Promise((resolve, reject) => {
        response
          .onOk(() => resolve())
          .onNotOk((message) => reject(new Error(message)))
          .onError(() => reject(new Error('Server error')));
      });
    }
  };

  /**
   * User-related endpoints
   */
  users = {
    list: async (): Promise<Types.ListUsersResponse> => {
      const response = await this.connection.get<Types.ListUsersResponse>('/users');

      return new Promise((resolve, reject) => {
        response
          .onOk((users) => resolve(users))
          .onNotOk((message) => reject(new Error(message)))
          .onError(() => reject(new Error('Server error')));
      });
    }
  };
}
```

### README.md

```markdown
# API Client

Auto-generated TypeScript API client for your StoneScriptPHP backend.

**DO NOT EDIT MANUALLY** - Regenerate using: `php stone generate client`

## Installation

### In Angular Project

```bash
npm install file:./src/api-client
```

### Setup in app.config.ts

```typescript
import { ApiClient } from '@myapi/client';
import { ApiConnectionService } from '@progalaxyelabs/ngx-stonescriptphp-client';

export const appConfig: ApplicationConfig = {
  providers: [
    // ... other providers
    {
      provide: ApiClient,
      useFactory: (connection: ApiConnectionService) => new ApiClient(connection),
      deps: [ApiConnectionService]
    }
  ]
};
```

## Usage

### In Angular Services

```typescript
import { Injectable } from '@angular/core';
import { ApiClient } from '@myapi/client';

@Injectable({ providedIn: 'root' })
export class ProjectService {
  constructor(private api: ApiClient) {}

  async createProject(name: string, description: string) {
    try {
      const project = await this.api.projects.create({
        name,
        description
      });

      console.log('Created:', project);
      return project;
    } catch (error) {
      console.error('Failed to create project:', error.message);
      throw error;
    }
  }

  async listProjects() {
    const response = await this.api.projects.list();
    return response.projects;
  }
}
```

### In Components

```typescript
export class ProjectListComponent implements OnInit {
  projects: Project[] = [];

  constructor(private projectService: ProjectService) {}

  async ngOnInit() {
    this.projects = await this.projectService.listProjects();
  }

  async createNew() {
    await this.projectService.createProject('New Project', 'Description');
    this.projects = await this.projectService.listProjects(); // Refresh
  }
}
```

## Regenerating

When backend routes change:

```bash
cd /path/to/backend
php stone generate client --output=../portal/src/api-client
```

Frontend will automatically see new types (no reinstall needed with `file:` protocol).

## Type Safety

All request and response types are generated from PHP DTOs:

```typescript
// TypeScript knows the exact shape
const request: CreateProjectRequest = {
  name: 'My Project',        // ✅ string
  description: 'Desc',       // ✅ string
  start_date: '2026-01-14'   // ✅ string | null
  // tenant_id is optional
};

const response: CreateProjectResponse = await api.projects.create(request);
// response.project_id is number
// response.name is string
// response.created_at is string
```

## Error Handling

Errors are automatically wrapped in standard Error objects:

```typescript
try {
  await api.projects.create(data);
} catch (error) {
  console.error(error.message); // User-friendly message from API
}
```
```

---

## Integration with ngx-stonescriptphp-client

### Dependency Injection (Angular)

```typescript
// app.config.ts
import { ApiClient } from '@myapi/client';
import { ApiConnectionService } from '@progalaxyelabs/ngx-stonescriptphp-client';

export const appConfig: ApplicationConfig = {
  providers: [
    // ... ngx-client providers
    {
      provide: ApiClient,
      useFactory: (connection: ApiConnectionService) => new ApiClient(connection),
      deps: [ApiConnectionService]
    }
  ]
};
```

### Standalone Usage (React, Vue, vanilla JS)

```typescript
import { ApiConnectionService } from '@progalaxyelabs/ngx-stonescriptphp-client';
import { ApiClient } from '@myapi/client';

// Create connection service manually
const connection = new ApiConnectionService({
  apiServer: { host: 'http://localhost:9100' },
  platformCode: 'myapp',
  auth: {
    mode: 'cookie',
    refreshEndpoint: '/auth/refresh',
    useCsrf: true
  }
});

const api = new ApiClient(connection);

// Use it
const projects = await api.projects.list();
```

### Benefits of This Approach

1. **Automatic Token Refresh**: ngx-client handles 401 responses
2. **CSRF Protection**: Automatically includes CSRF tokens
3. **Consistent Error Handling**: ApiResponse pattern across all endpoints
4. **Auth Integration**: Works with centralized accounts platform
5. **Type Safety**: Full TypeScript types from backend
6. **Separation of Concerns**: API client focuses on endpoints, not HTTP

---

## Type Generation

### PHP DTO to TypeScript Interface

**PHP DTO:**

```php
<?php

namespace App\DTO;

class CreateProjectRequest
{
    public function __construct(
        public string $name,
        public string $description,
        public ?string $start_date = null,
        public ?string $tenant_id = null,
    ) {}
}
```

**Generated TypeScript:**

```typescript
export interface CreateProjectRequest {
  name: string;
  description: string;
  start_date?: string | null;
  tenant_id?: string | null;
}
```

### Type Mapping

| PHP Type | TypeScript Type |
|----------|----------------|
| `string` | `string` |
| `int`, `integer` | `number` |
| `float`, `double` | `number` |
| `bool`, `boolean` | `boolean` |
| `array` | `any[]` |
| `?Type` (nullable) | `Type \| null` |
| `Type` (optional param) | `Type?` (optional property) |
| Custom class | Generated interface |

### Nested DTOs

**PHP:**

```php
class ProjectMember
{
    public function __construct(
        public int $user_id,
        public string $role
    ) {}
}

class CreateProjectResponse
{
    public function __construct(
        public int $project_id,
        public string $name,
        public array $members // array<ProjectMember>
    ) {}
}
```

**Generated TypeScript:**

```typescript
export interface ProjectMember {
  user_id: number;
  role: string;
}

export interface CreateProjectResponse {
  project_id: number;
  name: string;
  members: ProjectMember[];
}
```

### Route Grouping

Routes are automatically grouped by resource based on URL structure:

| Route Path | HTTP Method | Generated As |
|------------|-------------|--------------|
| `/projects` | GET | `api.projects.list()` |
| `/projects/create` | POST | `api.projects.create()` |
| `/projects/{id}` | GET | `api.projects.get(id)` |
| `/projects/{id}` | PUT | `api.projects.update(data)` |
| `/projects/{id}` | DELETE | `api.projects.delete(id)` |
| `/users` | GET | `api.users.list()` |
| `/users/{id}/profile` | GET | `api.users.getProfile(id)` |

**Grouping Algorithm:**

1. Extract first segment from path (`/projects/create` → `projects`)
2. Group all routes with same first segment
3. Generate method names from remaining path + HTTP method

---

## Error Handling

### API Response Errors

```typescript
// Generated wrapper handles all error cases
try {
  const project = await api.projects.create(data);
} catch (error) {
  // error.message contains user-friendly message from backend
  if (error.message.includes('already exists')) {
    // Handle duplicate
  } else {
    // Generic error
  }
}
```

### Network Errors

```typescript
try {
  const projects = await api.projects.list();
} catch (error) {
  if (error.message === 'Server error') {
    // Network/server issue
  } else {
    // Application error (validation, business logic)
  }
}
```

### TypeScript Compilation Errors

When regenerating after backend changes:

```bash
php stone generate client --output=../portal/src/api-client

# Frontend TypeScript compiler will show errors:
# Error: Property 'old_field' does not exist on type 'CreateProjectRequest'
# Solution: Update frontend code to use new field names
```

This is a **feature** - breaking changes are caught at compile time, not runtime.

---

## Examples

### Example 1: CRUD Operations

**Backend (PHP):**

```php
// routes.php
return [
    'GET' => [
        '/projects' => ListProjectsRoute::class,
        '/projects/{id}' => GetProjectRoute::class,
    ],
    'POST' => [
        '/projects/create' => CreateProjectRoute::class,
    ],
    'PUT' => [
        '/projects/{id}' => UpdateProjectRoute::class,
    ],
    'DELETE' => [
        '/projects/{id}' => DeleteProjectRoute::class,
    ],
];
```

**Generated Client:**

```typescript
api.projects = {
  list: async (): Promise<ListProjectsResponse> => { ... },
  get: async (id: number): Promise<GetProjectResponse> => { ... },
  create: async (data: CreateProjectRequest): Promise<CreateProjectResponse> => { ... },
  update: async (data: UpdateProjectRequest): Promise<UpdateProjectResponse> => { ... },
  delete: async (id: number): Promise<void> => { ... }
};
```

**Frontend Usage:**

```typescript
// List
const { projects } = await api.projects.list();

// Get
const project = await api.projects.get(123);

// Create
const newProject = await api.projects.create({
  name: 'My Project',
  description: 'Description'
});

// Update
await api.projects.update({
  project_id: 123,
  name: 'Updated Name'
});

// Delete
await api.projects.delete(123);
```

### Example 2: Nested Resources

**Backend:**

```php
return [
    'GET' => [
        '/projects/{project_id}/tasks' => ListProjectTasksRoute::class,
        '/projects/{project_id}/tasks/{task_id}' => GetTaskRoute::class,
    ],
    'POST' => [
        '/projects/{project_id}/tasks/create' => CreateTaskRoute::class,
    ],
];
```

**Generated:**

```typescript
api.projects = {
  tasks: {
    list: async (project_id: number): Promise<ListTasksResponse> => { ... },
    get: async (project_id: number, task_id: number): Promise<GetTaskResponse> => { ... },
    create: async (project_id: number, data: CreateTaskRequest): Promise<CreateTaskResponse> => { ... }
  }
};
```

**Usage:**

```typescript
const tasks = await api.projects.tasks.list(123);
const task = await api.projects.tasks.get(123, 456);
await api.projects.tasks.create(123, { name: 'Task Name' });
```

---

## Implementation Checklist

### Phase 1: Update generate-client.php

- [ ] Add `--name` option for package name
- [ ] Add `--base-url` option for API base URL
- [ ] Generate `ApiClient` class instead of plain object
- [ ] Import `ApiConnectionService` from ngx-client
- [ ] Wrap responses in Promise with `.onOk()/.onNotOk()/.onError()` handlers
- [ ] Group endpoints by resource
- [ ] Add peer dependency on ngx-client in package.json
- [ ] Update README with Angular DI setup

### Phase 2: Improve Type Generation

- [ ] Handle nested DTOs recursively
- [ ] Detect array types (`array<Type>` → `Type[]`)
- [ ] Support union types (`string|int` → `string | number`)
- [ ] Support enum types
- [ ] Add JSDoc comments from PHP DocBlocks

### Phase 3: Advanced Features

- [ ] Generate separate resource files (`resources/projects.ts`)
- [ ] Support query parameters for GET requests
- [ ] Generate mock data for testing
- [ ] Add validation schemas (Zod, Yup)
- [ ] Watch mode: `php stone generate client --watch`

### Phase 4: Documentation

- [ ] Update StoneScriptPHP README with new workflow
- [ ] Create video tutorial
- [ ] Add examples to stonescriptphp.org
- [ ] Update ngx-client docs with API client integration

---

## Future Enhancements

### React/Vue Support

Generate hooks/composables:

```typescript
// React hooks
export const useProjects = () => {
  const api = useApiClient();
  return useMutation(() => api.projects.list());
};

// Vue composables
export const useProjects = () => {
  const api = inject(ApiClient);
  return {
    projects: ref([]),
    load: async () => { ... }
  };
};
```

### GraphQL-style Field Selection

```typescript
await api.projects.list({
  select: ['project_id', 'name'],
  include: ['members', 'tasks']
});
```

### Real-time Subscriptions

```typescript
api.projects.subscribe((project) => {
  console.log('Project updated:', project);
});
```

---

**Document Status:** Specification
**Implementation:** Pending
**Target Version:** StoneScriptPHP 2.5.0
**Next Steps:**
1. Review and approve specification
2. Update `cli/generate-client.php`
3. Test with real-world projects
4. Update documentation
5. Release StoneScriptPHP 2.5.0

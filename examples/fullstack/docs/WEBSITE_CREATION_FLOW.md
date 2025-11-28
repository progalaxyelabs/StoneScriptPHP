# Website Creation Flow

This document explains how the website wizard stores data using the StoneScriptPHP API.

## Architecture Overview

```
┌─────────────┐       ┌─────────────┐       ┌──────────────┐
│   Angular   │──────►│ StoneScript │──────►│  PostgreSQL  │
│   Wizard    │ HTTP  │     API     │  SQL  │   Database   │
└─────────────┘       └─────────────┘       └──────────────┘
     (www)                (api)                   (db)
```

## Complete Workflow

### 1. User Fills Website Wizard

**Location**: `www/src/app/pages/website-wizard/`

User selects:
- Website type: `portfolio | business | ecommerce | blog`
- Website name: e.g., "My Portfolio"

### 2. Angular Calls Backend API

**Component**: `website-wizard.component.ts`

```typescript
import { api, WebsitesRequest } from '@stonescript/api-client';

async onWebsiteTypeFormSubmit() {
  const request: WebsitesRequest = {
    name: 'My Portfolio',
    type: 'portfolio'
  };

  const response = await api.postWebsites(request);

  // Response contains:
  // {
  //   id: "uuid-here",
  //   name: "My Portfolio",
  //   type: "portfolio",
  //   status: "draft",
  //   createdAt: "2025-01-01T00:00:00Z"
  // }
}
```

### 3. API Route Processes Request

**Backend**: `api/src/App/Routes/WebsitesRoute.php`

```php
class WebsitesRoute implements IRouteHandler, IWebsitesRoute
{
    public string $name;
    public string $type;

    public function validation_rules(): array {
        return [
            'name' => 'required|min:3|max:255',
            'type' => 'required|in:portfolio,business,ecommerce,blog',
        ];
    }

    public function execute(WebsitesRequest $request): WebsitesResponse {
        // Insert into database
        $sql = "
            INSERT INTO websites (name, type, status)
            VALUES (:name, :type, 'draft')
            RETURNING id, name, type, status, created_at
        ";

        // Execute and return response
    }
}
```

### 4. Database Stores Data

**Table**: `websites`

```sql
CREATE TABLE websites (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name VARCHAR(255) NOT NULL,
    type VARCHAR(50) NOT NULL,  -- portfolio|business|ecommerce|blog
    status VARCHAR(50) DEFAULT 'draft',  -- draft|published|archived
    user_id UUID,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### 5. Response Flow

**Database** → **API** → **TypeScript Client** → **Angular**

```
PostgreSQL Result
  ↓
PHP DTO (WebsitesResponse)
  ↓
JSON Response
  ↓
TypeScript Interface (WebsitesResponse)
  ↓
Angular Component
```

## Type Safety End-to-End

### Backend (PHP)

```php
// api/src/App/DTO/WebsitesRequest.php
class WebsitesRequest {
    public function __construct(
        public readonly string $name,
        public readonly string $type,
    ) {}
}

// api/src/App/DTO/WebsitesResponse.php
class WebsitesResponse {
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $type,
        public readonly string $status,
        public readonly string $createdAt,
    ) {}
}
```

### Generated TypeScript (Auto)

```typescript
// www/api-client/src/index.ts
export interface WebsitesRequest {
  name: string;
  type: string;
}

export interface WebsitesResponse {
  id: string;
  name: string;
  type: string;
  status: string;
  createdAt: string;
}

export const api = {
  async postWebsites(data: WebsitesRequest): Promise<WebsitesResponse> {
    const response = await fetch('/websites', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data),
    });
    const json = await response.json();
    return json.data;
  }
};
```

## Adding New Routes - Developer Workflow

### Step 1: Generate Backend Route

```bash
cd api
php generate route post /websites
```

This creates:
- ✅ `src/App/Routes/WebsitesRoute.php` - Handler
- ✅ `src/App/Contracts/IWebsitesRoute.php` - Interface
- ✅ `src/App/DTO/WebsitesRequest.php` - Request DTO
- ✅ `src/App/DTO/WebsitesResponse.php` - Response DTO
- ✅ Updates `src/config/routes.php`

### Step 2: Define DTOs

Edit the request and response DTOs with proper types.

### Step 3: Implement Logic

Add database queries and business logic to the route handler.

### Step 4: Generate TypeScript Client

```bash
php generate client --output=../www/api-client
cd ../www/api-client
npm run build
```

This generates:
- ✅ TypeScript interfaces matching PHP DTOs
- ✅ Typed API functions
- ✅ Full autocomplete in Angular

### Step 5: Use in Angular

```typescript
import { api } from '@stonescript/api-client';

const result = await api.postWebsites({ name: '...', type: '...' });
// ✅ result is fully typed
// ✅ TypeScript knows all properties
// ✅ Compile-time errors if types don't match
```

## Running the Stack

### Start All Services

```bash
docker-compose up -d
```

Services:
- **db**: PostgreSQL 16 on port 5432
- **api**: StoneScriptPHP on port 8080
- **www**: Angular on port 80
- **alert**: Socket.IO on port 3001

### Apply Database Migrations

```bash
docker-compose exec db psql -U webmeteor_user -d webmeteor -f /docker-entrypoint-initdb.d/001_create_websites_table.sql
```

### Test the API

```bash
curl -X POST http://localhost:8080/websites \
  -H "Content-Type: application/json" \
  -d '{"name": "My Portfolio", "type": "portfolio"}'
```

Expected response:
```json
{
  "status": "ok",
  "message": "Website created successfully",
  "data": {
    "id": "uuid-here",
    "name": "My Portfolio",
    "type": "portfolio",
    "status": "draft",
    "createdAt": "2025-01-01T00:00:00Z"
  }
}
```

## Benefits

1. **Type Safety**: PHP → TypeScript types auto-synced
2. **No Manual Work**: Generate route, DTOs, and client in seconds
3. **Compile-Time Checks**: Catch errors before runtime
4. **Autocomplete**: Full IDE support in Angular
5. **Single Source of Truth**: PHP DTOs define the contract
6. **Database-First**: Schema drives the implementation

## Example: Complete Feature

Let's say you want to add a "GET /websites/{id}" endpoint:

```bash
# 1. Generate route
php generate route get /websites/{id}

# 2. Edit WebsitesRequest.php (empty - no body)
# 3. Edit WebsitesResponse.php (same as POST)

# 4. Implement in WebsitesRoute.php
public function execute(WebsitesRequest $request): WebsitesResponse {
    $db = Database::getConnection();
    $stmt = $db->prepare("SELECT * FROM websites WHERE id = :id");
    $stmt->execute(['id' => $this->id]);  // $this->id from path
    $result = $stmt->fetch();
    return new WebsitesResponse(...$result);
}

# 5. Generate TypeScript client
php generate client --output=../www/api-client
cd ../www/api-client && npm run build

# 6. Use in Angular
const website = await api.getWebsites(websiteId);
```

**Total time**: ~5 minutes for a fully-typed, working endpoint! 🚀

# CLI API Server

HTTP server for code generation via REST API.

## Start Server

```bash
php stone cli-server
# Server listening on http://localhost:9810
```

## Endpoints

### POST /generate/route

Generate a new route with DTOs.

```bash
curl -X POST http://localhost:9810/generate/route \
  -H "Content-Type: application/json" \
  -d '{
    "path": "/products",
    "handler": "ProductsRoute::class",
    "RequestDTO": {
      "name": "string",
      "price": "int",
      "description": "string"
    },
    "ResponseDTOData": {
      "productId": "int",
      "status": "string"
    }
  }'
```

Response:
```json
{
  "success": true,
  "data": {
    "message": "Route generated successfully",
    "files": {
      "route": "/path/to/ProductsRoute.php",
      "requestDTO": "/path/to/ProductsRequest.php",
      "responseDTO": "/path/to/ProductsResponse.php"
    }
  }
}
```

### POST /generate/model

```bash
curl -X POST http://localhost:9810/generate/model \
  -d '{"functionFile": "get_users.pssql"}'
```

### POST /generate/env

```bash
curl -X POST http://localhost:9810/generate/env \
  -d '{"force": true}'
```

### GET /health

Health check endpoint.

```bash
curl http://localhost:9810/health
```

## Testing

1. Start server: `php stone cli-server`
2. Test health: `curl http://localhost:9810/health`
3. Test route generation with the example above
4. Verify files are created correctly
5. Test error handling (missing fields, invalid types)

## Acceptance Criteria

- [x] `php stone cli-server` starts HTTP server on port 9810
- [x] POST /generate/route creates route file
- [x] POST /generate/route creates DTO files when specified
- [x] Route names are converted from paths correctly (/products -> Products)
- [x] DTO types are mapped correctly (string -> string, int -> int, etc.)
- [x] Error responses return proper HTTP status codes
- [x] CORS headers allow browser requests
- [x] Documentation includes all endpoints and examples
- [x] Health endpoint returns version info

## Use Cases Enabled

1. **VSCode Extension** - Generate routes from GUI
2. **AI Assistants** - LLMs can call API to scaffold code
3. **Build Tools** - Automated code generation in CI/CD
4. **Remote Development** - Generate code on server from local IDE

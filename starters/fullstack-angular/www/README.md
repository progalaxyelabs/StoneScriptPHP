# WWW Service (Angular Frontend)

This is the Angular frontend for the fullstack application.

## Quick Setup

```bash
# Create new Angular project
ng new www --routing --style=scss

# Navigate to www directory
cd www

# Install dependencies
npm install

# Install Socket.IO client
npm install socket.io-client

# Install API client (after generating from backend)
npm install file:../api/client

# Start dev server
npm start
```

## Project Structure

```
www/
├── src/
│   ├── app/
│   │   ├── components/          # Reusable components
│   │   ├── pages/               # Page components
│   │   ├── services/            # Angular services
│   │   │   ├── api.service.ts   # API client wrapper
│   │   │   └── socket.service.ts # Socket.IO service
│   │   ├── guards/              # Route guards
│   │   └── interceptors/        # HTTP interceptors
│   ├── environments/
│   │   ├── environment.ts       # Development config
│   │   └── environment.prod.ts  # Production config
│   └── assets/
├── angular.json
└── package.json
```

## Environment Configuration

Create `src/environments/environment.ts`:

```typescript
export const environment = {
  production: false,
  apiUrl: 'http://localhost:9100',
  socketUrl: 'http://localhost:3001',
};
```

## API Service Example

```typescript
// src/app/services/api.service.ts
import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { environment } from '../../environments/environment';

@Injectable({ providedIn: 'root' })
export class ApiService {
  constructor(private http: HttpClient) {}

  get(path: string) {
    return this.http.get(`${environment.apiUrl}${path}`);
  }

  post(path: string, data: any) {
    return this.http.post(`${environment.apiUrl}${path}`, data);
  }
}
```

## Socket Service Example

```typescript
// src/app/services/socket.service.ts
import { Injectable } from '@angular/core';
import { io, Socket } from 'socket.io-client';
import { environment } from '../../environments/environment';
import { Observable } from 'rxjs';

@Injectable({ providedIn: 'root' })
export class SocketService {
  private socket: Socket;

  constructor() {
    this.socket = io(environment.socketUrl);
  }

  listen(event: string): Observable<any> {
    return new Observable((observer) => {
      this.socket.on(event, (data: any) => {
        observer.next(data);
      });
    });
  }

  emit(event: string, data: any) {
    this.socket.emit(event, data);
  }
}
```

## Using Type-Safe API Client

After generating TypeScript client from backend:

```typescript
import { ApiClient } from '@api/client';

export class ProductsComponent {
  constructor(private api: ApiClient) {}

  async loadProducts() {
    const response = await this.api.get('/products');
    this.products = response.data.products; // Fully typed!
  }
}
```

## Commands

```bash
ng serve                    # Start dev server
ng build                    # Build for production
ng test                     # Run unit tests
ng e2e                      # Run e2e tests
ng generate component <name>  # Generate component
ng generate service <name>    # Generate service
```

## Docker

When running in Docker Compose:

```bash
docker-compose exec www npm install
docker-compose logs -f www
```

## Production Build

```bash
ng build --configuration production
```

This creates optimized files in `dist/` directory.

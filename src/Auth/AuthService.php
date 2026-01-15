<?php

namespace StoneScriptPHP\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;

/**
 * Dual-mode authentication service
 *
 * STANDALONE MODE (default):
 * - Uses local database for user storage
 * - Generates JWT locally
 * - Perfect for OSS users
 *
 * CENTRALIZED MODE (ProGalaxy):
 * - Proxies to external auth service
 * - Validates JWT using JWKS
 * - Shared identity across platforms
 */
class AuthService
{
    private string $mode;
    private ?StandaloneAuth $standaloneAuth = null;
    private ?CentralizedAuth $centralizedAuth = null;

    public function __construct($dbConnection = null, array $config = [])
    {
        // Detect mode from environment (defaults to standalone)
        $this->mode = getenv('AUTH_MODE') ?: 'standalone';

        if ($this->mode === 'centralized') {
            $this->centralizedAuth = new CentralizedAuth($config);
        } else {
            $this->standaloneAuth = new StandaloneAuth($dbConnection, $config);
        }
    }

    /**
     * Get authentication mode
     */
    public function getMode(): string
    {
        return $this->mode;
    }

    /**
     * Validate request and extract user info
     */
    public function validateRequest(): ?array
    {
        return $this->mode === 'centralized'
            ? $this->centralizedAuth->validateRequest()
            : $this->standaloneAuth->validateRequest();
    }

    /**
     * Get current authenticated user
     */
    public function getCurrentUser(): ?array
    {
        return $this->mode === 'centralized'
            ? $this->centralizedAuth->getCurrentUser()
            : $this->standaloneAuth->getCurrentUser();
    }

    /**
     * Login with email and password
     */
    public function login(string $email, string $password): array
    {
        return $this->mode === 'centralized'
            ? $this->centralizedAuth->login($email, $password)
            : $this->standaloneAuth->login($email, $password);
    }

    /**
     * Register new user
     */
    public function register(string $email, string $password, ?string $name = null): array
    {
        return $this->mode === 'centralized'
            ? $this->centralizedAuth->register($email, $password, $name)
            : $this->standaloneAuth->register($email, $password, $name);
    }

    /**
     * Refresh access token
     */
    public function refresh(string $refreshToken): array
    {
        return $this->mode === 'centralized'
            ? $this->centralizedAuth->refresh($refreshToken)
            : $this->standaloneAuth->refresh($refreshToken);
    }

    /**
     * Logout
     */
    public function logout(string $refreshToken): array
    {
        return $this->mode === 'centralized'
            ? $this->centralizedAuth->logout($refreshToken)
            : $this->standaloneAuth->logout($refreshToken);
    }
}

/**
 * Standalone Authentication Implementation
 * Self-contained, works out-of-the-box for OSS users
 */
class StandaloneAuth
{
    private $db;
    private ?string $jwtSecret;
    private ?string $jwtPrivateKey;
    private ?string $jwtPublicKey;
    private int $accessTokenTTL = 900; // 15 minutes
    private int $refreshTokenTTL = 15552000; // 180 days

    public function __construct($dbConnection, array $config = [])
    {
        $this->db = $dbConnection;

        // JWT can use either secret (HS256) or RSA keys (RS256)
        $this->jwtSecret = $config['jwt_secret'] ?? getenv('JWT_SECRET');
        $this->jwtPrivateKey = $config['jwt_private_key'] ?? getenv('JWT_PRIVATE_KEY');
        $this->jwtPublicKey = $config['jwt_public_key'] ?? getenv('JWT_PUBLIC_KEY');

        // Auto-generate secret if not provided (for quick start)
        if (!$this->jwtSecret && !$this->jwtPrivateKey) {
            $this->jwtSecret = bin2hex(random_bytes(32));
            error_log("Warning: Using auto-generated JWT secret. Set JWT_SECRET env var for production.");
        }

        $this->ensureTablesExist();
    }

    /**
     * Create auth tables if they don't exist
     */
    private function ensureTablesExist(): void
    {
        // Create users table
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS users (
                id SERIAL PRIMARY KEY,
                email VARCHAR(255) UNIQUE NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                name VARCHAR(255),
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Create refresh_tokens table
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS refresh_tokens (
                id SERIAL PRIMARY KEY,
                user_id INTEGER NOT NULL,
                token VARCHAR(512) UNIQUE NOT NULL,
                expires_at TIMESTAMP NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ");
    }

    /**
     * Validate JWT from request
     */
    public function validateRequest(): ?array
    {
        $jwt = $this->extractJWT();
        if (!$jwt) {
            return null;
        }

        return $this->validateJWT($jwt);
    }

    /**
     * Extract JWT from Authorization header
     */
    private function extractJWT(): ?string
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

        if (!$authHeader) {
            return null;
        }

        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Validate JWT token
     */
    private function validateJWT(string $jwt): ?array
    {
        try {
            if ($this->jwtPublicKey) {
                // RS256 verification
                $decoded = JWT::decode($jwt, new Key($this->jwtPublicKey, 'RS256'));
            } else {
                // HS256 verification
                $decoded = JWT::decode($jwt, new Key($this->jwtSecret, 'HS256'));
            }

            return (array) $decoded;
        } catch (\Exception $e) {
            error_log("JWT validation failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Get current user from JWT
     */
    public function getCurrentUser(): ?array
    {
        $claims = $this->validateRequest();
        if (!$claims) {
            return null;
        }

        return [
            'id' => $claims['sub'] ?? null,
            'email' => $claims['email'] ?? null,
        ];
    }

    /**
     * Login with email and password
     */
    public function login(string $email, string $password): array
    {
        // Find user
        $stmt = $this->db->prepare("SELECT * FROM users WHERE email = ?");
        $stmt->execute([$email]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$user) {
            return ['error' => 'Invalid credentials'];
        }

        // Verify password
        if (!password_verify($password, $user['password_hash'])) {
            return ['error' => 'Invalid credentials'];
        }

        // Generate tokens
        $accessToken = $this->generateAccessToken($user);
        $refreshToken = $this->generateRefreshToken($user);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $this->accessTokenTTL,
        ];
    }

    /**
     * Register new user
     */
    public function register(string $email, string $password, ?string $name = null): array
    {
        // Check if user exists
        $stmt = $this->db->prepare("SELECT id FROM users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            return ['error' => 'Email already registered'];
        }

        // Create user
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $this->db->prepare("
            INSERT INTO users (email, password_hash, name)
            VALUES (?, ?, ?)
            RETURNING id
        ");
        $stmt->execute([$email, $passwordHash, $name]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        $userId = $result['id'];
        $user = [
            'id' => $userId,
            'email' => $email,
            'name' => $name,
        ];

        // Generate tokens
        $accessToken = $this->generateAccessToken($user);
        $refreshToken = $this->generateRefreshToken($user);

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $this->accessTokenTTL,
        ];
    }

    /**
     * Refresh access token
     */
    public function refresh(string $refreshToken): array
    {
        // Find refresh token
        $stmt = $this->db->prepare("
            SELECT rt.*, u.*
            FROM refresh_tokens rt
            JOIN users u ON rt.user_id = u.id
            WHERE rt.token = ? AND rt.expires_at > NOW()
        ");
        $stmt->execute([$refreshToken]);
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$result) {
            return ['error' => 'Invalid or expired refresh token'];
        }

        $user = [
            'id' => $result['user_id'],
            'email' => $result['email'],
            'name' => $result['name'],
        ];

        // Generate new access token
        $accessToken = $this->generateAccessToken($user);

        return [
            'access_token' => $accessToken,
            'expires_in' => $this->accessTokenTTL,
        ];
    }

    /**
     * Logout
     */
    public function logout(string $refreshToken): array
    {
        // Delete refresh token
        $stmt = $this->db->prepare("DELETE FROM refresh_tokens WHERE token = ?");
        $stmt->execute([$refreshToken]);

        return ['success' => true];
    }

    /**
     * Generate access token (JWT)
     */
    private function generateAccessToken(array $user): string
    {
        $now = time();
        $payload = [
            'iss' => 'stonescriptphp-standalone',
            'sub' => (string) $user['id'],
            'email' => $user['email'],
            'iat' => $now,
            'exp' => $now + $this->accessTokenTTL,
        ];

        if ($this->jwtPrivateKey) {
            // RS256 signing
            return JWT::encode($payload, $this->jwtPrivateKey, 'RS256');
        } else {
            // HS256 signing
            return JWT::encode($payload, $this->jwtSecret, 'HS256');
        }
    }

    /**
     * Generate refresh token
     */
    private function generateRefreshToken(array $user): string
    {
        $token = bin2hex(random_bytes(32));
        $expiresAt = date('Y-m-d H:i:s', time() + $this->refreshTokenTTL);

        $stmt = $this->db->prepare("
            INSERT INTO refresh_tokens (user_id, token, expires_at)
            VALUES (?, ?, ?)
        ");
        $stmt->execute([$user['id'], $token, $expiresAt]);

        return $token;
    }
}

/**
 * Centralized Authentication Implementation
 * For ProGalaxy platforms with external auth service
 */
class CentralizedAuth
{
    private string $authServiceUrl;
    private ?array $jwksCache = null;
    private int $jwksCacheTime = 0;
    private int $jwksCacheTTL = 3600; // 1 hour

    public function __construct(array $config = [])
    {
        // Internal swarm service URL
        $this->authServiceUrl = $config['auth_service_url'] ??
            getenv('AUTH_SERVICE_URL') ??
            'http://progalaxyelabs-auth_auth:3139';
    }

    /**
     * Validate JWT token from request header
     * @return array|null Decoded JWT claims or null if invalid
     */
    public function validateRequest(): ?array
    {
        $jwt = $this->extractJWT();
        if (!$jwt) {
            return null;
        }

        return $this->validateJWT($jwt);
    }

    /**
     * Extract JWT from Authorization header
     */
    private function extractJWT(): ?string
    {
        $headers = getallheaders();
        $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? null;

        if (!$authHeader) {
            return null;
        }

        // Remove "Bearer " prefix
        if (preg_match('/Bearer\s+(.*)$/i', $authHeader, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Validate JWT using public key from JWKS endpoint
     * @param string $jwt JWT token
     * @return array|null Decoded claims or null if invalid
     */
    public function validateJWT(string $jwt): ?array
    {
        try {
            $jwks = $this->getJWKS();

            // Decode and verify
            $decoded = JWT::decode($jwt, $jwks);

            return (array) $decoded;
        } catch (\Exception $e) {
            error_log("JWT validation failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Fetch JWKS from auth service (with caching)
     */
    private function getJWKS(): array
    {
        $now = time();

        // Return cached JWKS if still valid
        if ($this->jwksCache && ($now - $this->jwksCacheTime) < $this->jwksCacheTTL) {
            return $this->jwksCache;
        }

        // Fetch fresh JWKS
        $url = $this->authServiceUrl . '/auth/jwks';
        $response = file_get_contents($url);

        if (!$response) {
            throw new \Exception("Failed to fetch JWKS from auth service");
        }

        $jwksData = json_decode($response, true);
        $this->jwksCache = JWK::parseKeySet($jwksData);
        $this->jwksCacheTime = $now;

        return $this->jwksCache;
    }

    /**
     * Get current authenticated user info from JWT
     * @return array|null User info or null if not authenticated
     */
    public function getCurrentUser(): ?array
    {
        $claims = $this->validateRequest();

        if (!$claims) {
            return null;
        }

        return [
            'id' => $claims['sub'] ?? null,
            'email' => $claims['email'] ?? null,
            'tenant_id' => $claims['tenant_id'] ?? null,
            'tenant_role' => $claims['tenant_role'] ?? null,
        ];
    }

    /**
     * Proxy login request to auth service
     */
    public function login(string $email, string $password): array
    {
        return $this->proxyAuthRequest('/auth/login', [
            'email' => $email,
            'password' => $password,
        ]);
    }

    /**
     * Proxy registration request to auth service
     */
    public function register(string $email, string $password, ?string $name = null): array
    {
        return $this->proxyAuthRequest('/auth/register', [
            'email' => $email,
            'password' => $password,
            'name' => $name,
        ]);
    }

    /**
     * Proxy token refresh request to auth service
     */
    public function refresh(string $refreshToken): array
    {
        return $this->proxyAuthRequest('/auth/refresh', [
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * Proxy logout request to auth service
     */
    public function logout(string $refreshToken): array
    {
        return $this->proxyAuthRequest('/auth/logout', [
            'refresh_token' => $refreshToken,
        ]);
    }

    /**
     * Generic auth request proxy
     */
    private function proxyAuthRequest(string $endpoint, array $data): array
    {
        $url = $this->authServiceUrl . $endpoint;

        $options = [
            'http' => [
                'header'  => "Content-type: application/json\r\n",
                'method'  => 'POST',
                'content' => json_encode($data),
            ],
        ];

        $context = stream_context_create($options);
        $response = file_get_contents($url, false, $context);

        if ($response === false) {
            return ['error' => 'Failed to connect to auth service'];
        }

        return json_decode($response, true);
    }
}

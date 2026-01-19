<?php
/**
 * Authentication handling
 */
class Auth {
    /**
     * Generate a secure random token
     */
    public static function generateToken(): string {
        return bin2hex(random_bytes(32));
    }

    /**
     * Generate a UUID v4
     */
    public static function generateUuid(): string {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Hash a password using bcrypt
     */
    public static function hashPassword(string $password): string {
        return password_hash($password, PASSWORD_BCRYPT, ['cost' => BCRYPT_COST]);
    }

    /**
     * Verify a password against a hash
     */
    public static function verifyPassword(string $password, string $hash): bool {
        return password_verify($password, $hash);
    }

    /**
     * Hash a token for storage (using SHA-256)
     */
    public static function hashToken(string $token): string {
        return hash('sha256', $token);
    }

    /**
     * Create a new auth token for a user
     */
    public static function createToken(int $userId): string {
        $token = self::generateToken();
        $tokenHash = self::hashToken($token);
        $expiresAt = date('Y-m-d H:i:s', strtotime('+' . TOKEN_EXPIRY_DAYS . ' days'));

        Database::insert('auth_tokens', [
            'user_id' => $userId,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt
        ]);

        return $token;
    }

    /**
     * Validate a token and return user data
     */
    public static function validateToken(string $token): ?array {
        $tokenHash = self::hashToken($token);

        $result = Database::queryOne(
            "SELECT u.* FROM auth_tokens t
             JOIN users u ON t.user_id = u.id
             WHERE t.token_hash = ? AND t.expires_at > NOW()",
            [$tokenHash]
        );

        return $result;
    }

    /**
     * Revoke a token
     */
    public static function revokeToken(string $token): bool {
        $tokenHash = self::hashToken($token);
        $affected = Database::execute(
            "DELETE FROM auth_tokens WHERE token_hash = ?",
            [$tokenHash]
        );
        return $affected > 0;
    }

    /**
     * Clean up expired tokens
     */
    public static function cleanupExpiredTokens(): int {
        return Database::execute("DELETE FROM auth_tokens WHERE expires_at < NOW()");
    }

    /**
     * Get the Bearer token from the Authorization header
     */
    public static function getBearerToken(): ?string {
        $authHeader = '';

        // Try getallheaders() first
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }

        // Fallback to $_SERVER (for CGI/FastCGI)
        if (empty($authHeader)) {
            $authHeader = $_SERVER['HTTP_AUTHORIZATION']
                ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
                ?? '';
        }

        if (preg_match('/Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Require authentication - returns user or sends error response
     */
    public static function requireAuth(): array {
        $token = self::getBearerToken();

        if (!$token) {
            Response::error('Authentication required', 401);
        }

        $user = self::validateToken($token);

        if (!$user) {
            Response::error('Invalid or expired token', 401);
        }

        return $user;
    }

    /**
     * Register a new user
     */
    public static function register(string $username, string $password, ?string $email = null): array {
        // Check if username exists
        $existing = Database::queryOne(
            "SELECT id FROM users WHERE username = ?",
            [$username]
        );

        if ($existing) {
            Response::error('Username already exists', 409);
        }

        // Create user
        $uuid = self::generateUuid();
        $passwordHash = self::hashPassword($password);

        $userId = Database::insert('users', [
            'uuid' => $uuid,
            'username' => $username,
            'password_hash' => $passwordHash,
            'email' => $email
        ]);

        // Create token
        $token = self::createToken($userId);

        return [
            'user' => [
                'id' => $userId,
                'uuid' => $uuid,
                'username' => $username,
                'email' => $email
            ],
            'token' => $token
        ];
    }

    /**
     * Login a user
     */
    public static function login(string $username, string $password): array {
        $user = Database::queryOne(
            "SELECT * FROM users WHERE username = ?",
            [$username]
        );

        if (!$user || !self::verifyPassword($password, $user['password_hash'])) {
            Response::error('Invalid username or password', 401);
        }

        // Create token
        $token = self::createToken($user['id']);

        return [
            'user' => [
                'id' => $user['id'],
                'uuid' => $user['uuid'],
                'username' => $user['username'],
                'email' => $user['email']
            ],
            'token' => $token
        ];
    }
}

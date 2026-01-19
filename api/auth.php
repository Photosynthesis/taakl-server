<?php
/**
 * Authentication API handlers
 */

/**
 * POST /api/register
 * Register a new user account
 */
function handleRegister(): void {
    $data = Response::getJsonBody();
    Response::requireFields($data, ['username', 'password']);

    $username = trim($data['username']);
    $password = $data['password'];
    $email = isset($data['email']) ? trim($data['email']) : null;

    // Validate username
    if (strlen($username) < 3 || strlen($username) > 50) {
        Response::error('Username must be between 3 and 50 characters', 400);
    }

    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $username)) {
        Response::error('Username can only contain letters, numbers, underscores, and hyphens', 400);
    }

    // Validate password
    if (strlen($password) < 8) {
        Response::error('Password must be at least 8 characters', 400);
    }

    // Validate email if provided
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        Response::error('Invalid email address', 400);
    }

    $result = Auth::register($username, $password, $email);

    Response::success([
        'user' => $result['user'],
        'token' => $result['token']
    ], 201);
}

/**
 * POST /api/login
 * Login with username and password
 */
function handleLogin(): void {
    $data = Response::getJsonBody();
    Response::requireFields($data, ['username', 'password']);

    $username = trim($data['username']);
    $password = $data['password'];

    $result = Auth::login($username, $password);

    Response::success([
        'user' => $result['user'],
        'token' => $result['token']
    ]);
}

/**
 * POST /api/logout
 * Invalidate the current token
 */
function handleLogout(): void {
    $token = Auth::getBearerToken();

    if ($token) {
        Auth::revokeToken($token);
    }

    Response::success(['message' => 'Logged out successfully']);
}

/**
 * GET /api/me
 * Get current user info
 */
function handleMe(): void {
    $user = Auth::requireAuth();

    Response::success([
        'user' => [
            'id' => $user['id'],
            'uuid' => $user['uuid'],
            'username' => $user['username'],
            'email' => $user['email']
        ]
    ]);
}

<?php
/**
 * Taakl Server - Main Entry Point / Router
 */

// Load configuration
require_once __DIR__ . '/config.php';

// Load libraries
require_once __DIR__ . '/lib/Database.php';
require_once __DIR__ . '/lib/Response.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Sync.php';
require_once __DIR__ . '/lib/Share.php';

// Load API handlers
require_once __DIR__ . '/api/auth.php';
require_once __DIR__ . '/api/sync.php';
require_once __DIR__ . '/api/settings.php';
require_once __DIR__ . '/api/share.php';

// Set up CORS
Response::cors();

// Get request method and path
$method = $_SERVER['REQUEST_METHOD'];
$uri = $_SERVER['REQUEST_URI'];

// Remove query string from URI
$uri = parse_url($uri, PHP_URL_PATH);

// Normalize URI
$uri = '/' . trim($uri, '/');

// Route the request
try {
    route($method, $uri);
} catch (PDOException $e) {
    error_log('Database error: ' . $e->getMessage());
    Response::error('Database error', 500);
} catch (Exception $e) {
    error_log('Server error: ' . $e->getMessage());
    Response::error('Server error: ' . $e->getMessage(), 500);
}

/**
 * Route requests to appropriate handlers
 */
function route(string $method, string $uri): void {
    // Define routes
    $routes = [
        // Auth routes
        'POST /api/register' => 'handleRegister',
        'POST /api/login' => 'handleLogin',
        'POST /api/logout' => 'handleLogout',
        'GET /api/me' => 'handleMe',

        // Sync routes
        'POST /api/sync' => 'handleSync',
        'POST /api/sync/full' => 'handleFullSyncUpload',
        'GET /api/sync/full' => 'handleFullSyncDownload',

        // Settings routes
        'GET /api/settings' => 'handleGetSettings',
        'PUT /api/settings' => 'handleUpdateSettings',

        // Share routes (owner)
        'POST /api/shares' => 'handleCreateShare',
        'GET /api/shares' => 'handleListShares',
    ];

    // Routes with a path parameter, matched by regex; capture is passed to the handler
    $patternRoutes = [
        ['GET', '#^/api/share/([a-f0-9]{64})$#', 'handlePublicShare'],
        ['DELETE', '#^/api/shares/(\d+)$#', 'handleRevokeShare'],
    ];

    $routeKey = "$method $uri";

    // Check for exact match
    if (isset($routes[$routeKey])) {
        $handler = $routes[$routeKey];
        $handler();
        return;
    }

    // Check for route with trailing slash variation
    $altUri = rtrim($uri, '/');
    $altRouteKey = "$method $altUri";
    if (isset($routes[$altRouteKey])) {
        $handler = $routes[$altRouteKey];
        $handler();
        return;
    }

    // Check pattern routes
    foreach ($patternRoutes as $route) {
        if ($method === $route[0] && preg_match($route[1], $altUri, $matches)) {
            $route[2]($matches[1]);
            return;
        }
    }

    // Root path - show API info
    if ($uri === '/' || $uri === '') {
        Response::success([
            'name' => 'Taakl API',
            'version' => '2.0.0',
            'endpoints' => [
                'POST /api/register' => 'Register a new user',
                'POST /api/login' => 'Login with username/password',
                'POST /api/logout' => 'Logout (invalidate token)',
                'GET /api/me' => 'Get current user info',
                'POST /api/sync' => 'Incremental sync',
                'POST /api/sync/full' => 'Upload full data',
                'GET /api/sync/full' => 'Download full data',
                'GET /api/settings' => 'Get user settings',
                'PUT /api/settings' => 'Update user settings',
                'POST /api/shares' => 'Create a read-only share link',
                'GET /api/shares' => 'List your share links',
                'DELETE /api/shares/{id}' => 'Revoke a share link',
                'GET /api/share/{token}' => 'Public share payload',
            ]
        ]);
        return;
    }

    // 404 Not Found
    Response::error('Endpoint not found: ' . $routeKey, 404);
}

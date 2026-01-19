<?php
/**
 * Taakl Server Configuration
 *
 * Copy this file to config.local.php and update with your credentials.
 * config.local.php is gitignored and will override these defaults.
 */

// Database configuration
define('DB_HOST', 'localhost');
define('DB_NAME', 'taakl');
define('DB_USER', 'taakl_user');
define('DB_PASS', 'change_this_password');
define('DB_CHARSET', 'utf8mb4');

// Authentication settings
define('TOKEN_EXPIRY_DAYS', 30);
define('BCRYPT_COST', 12);

// Rate limiting (requests per minute)
define('RATE_LIMIT', 100);

// CORS - set to specific origin in production
define('CORS_ORIGIN', '*');

// Timezone
date_default_timezone_set('UTC');

// Load local config overrides if present
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

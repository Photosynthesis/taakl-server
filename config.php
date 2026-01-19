<?php
/**
 * Taakl Server Configuration
 *
 * Copy this file to config.local.php and update with your credentials.
 * config.local.php is gitignored and will override these defaults.
 */

// Load local config first if present (so it can override defaults)
if (file_exists(__DIR__ . '/config.local.php')) {
    require_once __DIR__ . '/config.local.php';
}

// Database configuration (only define if not already set in local config)
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', 'taakl');
if (!defined('DB_USER')) define('DB_USER', 'taakl_user');
if (!defined('DB_PASS')) define('DB_PASS', 'change_this_password');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

// Authentication settings
if (!defined('TOKEN_EXPIRY_DAYS')) define('TOKEN_EXPIRY_DAYS', 30);
if (!defined('BCRYPT_COST')) define('BCRYPT_COST', 12);

// Rate limiting (requests per minute)
if (!defined('RATE_LIMIT')) define('RATE_LIMIT', 100);

// CORS - set to specific origin in production
if (!defined('CORS_ORIGIN')) define('CORS_ORIGIN', '*');

// Timezone
date_default_timezone_set('UTC');

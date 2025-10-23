<?php
// Copy this file to config.php and fill in production credentials.

define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'punchlist');
define('DB_USER', 'punchlist');
define('DB_PASS', 'secret');
define('DB_CHARSET', 'utf8mb4');

define('BASE_URL', 'http://localhost'); // no trailing slash

// S3-compatible storage credentials
// Example for MinIO running locally: http://127.0.0.1:9000

define('S3_ENDPOINT', 'http://148.230.105.29:9000');
define('S3_KEY', 'punchlist');
define('S3_SECRET', 'StrongPassword123!');
define('S3_BUCKET', 'punchlist');
define('S3_REGION', 'us-east-1');
define('S3_USE_PATH_STYLE', true); // set false if using virtual-hosted-style endpoints

// Optional: override the URL base used to serve files.
define('S3_URL_BASE', ''); // leave blank to derive from endpoint + bucket.

// Security

define('SESSION_NAME', 'punchlist_session');
define('CSRF_TOKEN_NAME', 'csrf_token');

define('APP_TIMEZONE', 'UTC');

define('APP_TITLE', 'Punch List Manager');

// Dual database support (core governance + application data)
define('APPS_DSN', getenv('APPS_DSN') ?: sprintf('mysql:host=%s;dbname=%s;charset=%s', DB_HOST, DB_NAME, DB_CHARSET));
define('APPS_DB_USER', getenv('APPS_DB_USER') ?: DB_USER);
define('APPS_DB_PASS', getenv('APPS_DB_PASS') ?: DB_PASS);

define('CORE_DSN', getenv('CORE_DSN') ?: APPS_DSN);
define('CORE_DB_USER', getenv('CORE_DB_USER') ?: APPS_DB_USER);
define('CORE_DB_PASS', getenv('CORE_DB_PASS') ?: APPS_DB_PASS);

<?php
/**
 * Shared database connection for Sentiment Vision.
 *
 * Reads credentials from the .env file in the project root.
 * Usage: require_once __DIR__ . '/../includes/db_connect.php';
 *        — this provides a ready-to-use $db (mysqli) connection.
 */

/**
 * Parse a .env file into an associative array.
 * Supports KEY=VALUE, optionally quoted values, and # comments.
 */
function sv_load_env(string $path): array {
    if (!file_exists($path)) {
        return [];
    }
    $vars = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));
        // Strip surrounding quotes if present
        if ((str_starts_with($val, '"') && str_ends_with($val, '"'))
            || (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
            $val = substr($val, 1, -1);
        }
        $vars[$key] = $val;
    }
    return $vars;
}

// Load environment variables
$_sv_env = sv_load_env(__DIR__ . '/../.env');

$db = new mysqli(
    $_sv_env['DB_HOST'] ?? 'localhost',
    $_sv_env['DB_USER'] ?? '',
    $_sv_env['DB_PASSWORD'] ?? '',
    $_sv_env['DB_NAME'] ?? '',
    (int)($_sv_env['DB_PORT'] ?? 3306)
);

if ($db->connect_error) {
    die('DB connection failed: ' . htmlspecialchars($db->connect_error));
}
$db->set_charset('utf8mb4');

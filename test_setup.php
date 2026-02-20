<?php
/**
 * Sentiment Vision - Setup Diagnostic Tool
 * Tests environment, .env loading, DB connection, and schema.
 * DELETE THIS FILE after setup is confirmed working.
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$checks = [];

// =========================================================================
// 1. PHP Version
// =========================================================================
$php_ver = phpversion();
$php_ok = version_compare($php_ver, '8.0', '>=');
$checks[] = [
    'label' => 'PHP Version',
    'ok' => $php_ok,
    'value' => $php_ver,
    'hint' => $php_ok ? '' : 'PHP 8.0+ required (for str_starts_with/str_ends_with in db_connect.php)',
];

// =========================================================================
// 2. Required PHP Extensions
// =========================================================================
$required_exts = ['mysqli', 'json', 'mbstring'];
foreach ($required_exts as $ext) {
    $loaded = extension_loaded($ext);
    $checks[] = [
        'label' => "PHP Extension: $ext",
        'ok' => $loaded,
        'value' => $loaded ? 'Loaded' : 'MISSING',
        'hint' => $loaded ? '' : "Enable the $ext extension in php.ini",
    ];
}

// =========================================================================
// 3. .env File
// =========================================================================
$env_path = __DIR__ . '/.env';
$env_exists = file_exists($env_path);
$checks[] = [
    'label' => '.env file exists',
    'ok' => $env_exists,
    'value' => $env_exists ? $env_path : 'NOT FOUND',
    'hint' => $env_exists ? '' : 'Create .env in the project root. Copy from .env.example and fill in your DB credentials.',
];

$env_readable = $env_exists && is_readable($env_path);
if ($env_exists) {
    $checks[] = [
        'label' => '.env file readable',
        'ok' => $env_readable,
        'value' => $env_readable ? 'Yes' : 'NO — permission denied',
        'hint' => $env_readable ? '' : 'Check file permissions (should be 644)',
    ];
}

// =========================================================================
// 4. Parse .env and check required keys
// =========================================================================
$env_vars = [];
$required_keys = ['DB_HOST', 'DB_PORT', 'DB_USER', 'DB_PASSWORD', 'DB_NAME'];

if ($env_readable) {
    // Inline parser (same logic as db_connect.php)
    foreach (file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') continue;
        $pos = strpos($line, '=');
        if ($pos === false) continue;
        $key = trim(substr($line, 0, $pos));
        $val = trim(substr($line, $pos + 1));
        if (strlen($val) >= 2 && $val[0] === $val[strlen($val)-1] && in_array($val[0], ['"', "'"])) {
            $val = substr($val, 1, -1);
        }
        $env_vars[$key] = $val;
    }

    foreach ($required_keys as $key) {
        $present = isset($env_vars[$key]) && $env_vars[$key] !== '';
        // Mask password for display
        if ($key === 'DB_PASSWORD') {
            $display = $present ? str_repeat('*', min(strlen($env_vars[$key]), 8)) : '(empty)';
        } else {
            $display = $present ? htmlspecialchars($env_vars[$key]) : '(empty)';
        }
        $checks[] = [
            'label' => ".env key: $key",
            'ok' => $present,
            'value' => $display,
            'hint' => $present ? '' : "Add $key=your_value to .env",
        ];
    }
} else {
    $checks[] = [
        'label' => '.env parsing',
        'ok' => false,
        'value' => 'Skipped',
        'hint' => 'Fix .env file first (see above)',
    ];
}

// =========================================================================
// 5. includes/ directory and db_connect.php
// =========================================================================
$inc_path = __DIR__ . '/includes/db_connect.php';
$inc_exists = file_exists($inc_path);
$checks[] = [
    'label' => 'includes/db_connect.php exists',
    'ok' => $inc_exists,
    'value' => $inc_exists ? 'Yes' : 'NOT FOUND',
    'hint' => $inc_exists ? '' : 'The includes/ directory or db_connect.php is missing. Make sure git pull completed.',
];

// =========================================================================
// 6. Database Connection
// =========================================================================
$db = null;
$db_connected = false;

if (!empty($env_vars['DB_USER']) && !empty($env_vars['DB_NAME'])) {
    $db = @new mysqli(
        $env_vars['DB_HOST'] ?? 'localhost',
        $env_vars['DB_USER'] ?? '',
        $env_vars['DB_PASSWORD'] ?? '',
        $env_vars['DB_NAME'] ?? '',
        (int)($env_vars['DB_PORT'] ?? 3306)
    );

    if ($db->connect_error) {
        $checks[] = [
            'label' => 'Database Connection',
            'ok' => false,
            'value' => 'FAILED',
            'hint' => htmlspecialchars($db->connect_error),
        ];
    } else {
        $db_connected = true;
        $db->set_charset('utf8mb4');
        $checks[] = [
            'label' => 'Database Connection',
            'ok' => true,
            'value' => "Connected to {$env_vars['DB_NAME']} on {$env_vars['DB_HOST']}",
            'hint' => '',
        ];
    }
} else {
    $checks[] = [
        'label' => 'Database Connection',
        'ok' => false,
        'value' => 'Skipped — missing credentials',
        'hint' => 'Fix .env first',
    ];
}

// =========================================================================
// 7. Check Required Tables
// =========================================================================
$expected_tables = ['clients', 'sources', 'articles', 'fetch_log', 'tags', 'article_tags'];
$existing_tables = [];

if ($db_connected) {
    $res = $db->query("SHOW TABLES");
    if ($res) {
        while ($row = $res->fetch_row()) {
            $existing_tables[] = $row[0];
        }
    }

    foreach ($expected_tables as $table) {
        $found = in_array($table, $existing_tables);
        $checks[] = [
            'label' => "Table: $table",
            'ok' => $found,
            'value' => $found ? 'Exists' : 'MISSING',
            'hint' => $found ? '' : "Table will be auto-created on first Python run. Or run: python -m src.main --dry-run --verbose",
        ];
    }

    // Show any extra tables too
    $extra = array_diff($existing_tables, $expected_tables);
    if (!empty($extra)) {
        $checks[] = [
            'label' => 'Extra tables found',
            'ok' => true,
            'value' => implode(', ', $extra),
            'hint' => '(informational only)',
        ];
    }
}

// =========================================================================
// 8. Check Key Columns (if articles table exists)
// =========================================================================
if ($db_connected && in_array('articles', $existing_tables)) {
    $expected_cols = [
        'articles' => ['id','client_id','source_id','url','title','content_text','sentiment_score',
                        'sentiment_label','media_tier','esg_tags','tags','summary','word_count'],
        'tags' => ['id','name','tag_type','scope','client_id','keywords','match_method','color','enabled'],
        'article_tags' => ['id','article_id','tag_id','confidence','matched_keyword','match_method'],
    ];

    foreach ($expected_cols as $table => $cols) {
        if (!in_array($table, $existing_tables)) continue;
        $res = $db->query("SHOW COLUMNS FROM `$table`");
        $actual_cols = [];
        while ($row = $res->fetch_assoc()) {
            $actual_cols[] = $row['Field'];
        }
        $missing = array_diff($cols, $actual_cols);
        if (empty($missing)) {
            $checks[] = [
                'label' => "Columns: $table",
                'ok' => true,
                'value' => count($actual_cols) . ' columns — all expected columns present',
                'hint' => '',
            ];
        } else {
            $checks[] = [
                'label' => "Columns: $table",
                'ok' => false,
                'value' => 'Missing: ' . implode(', ', $missing),
                'hint' => 'Run a Python fetch to trigger migrations, or add columns manually.',
            ];
        }
    }
}

// =========================================================================
// 9. Check logs/ directory
// =========================================================================
$logs_dir = __DIR__ . '/logs';
$logs_exists = is_dir($logs_dir);
$logs_writable = $logs_exists && is_writable($logs_dir);
$checks[] = [
    'label' => 'logs/ directory',
    'ok' => $logs_writable,
    'value' => !$logs_exists ? 'MISSING' : ($logs_writable ? 'Exists & writable' : 'Exists but NOT writable'),
    'hint' => !$logs_exists ? 'Create a logs/ directory in the project root' : ($logs_writable ? '' : 'chmod 755 logs/'),
];

// =========================================================================
// 10. Check config files
// =========================================================================
$config_files = [
    'config/settings.yaml' => 'Global settings (fetching, sentiment thresholds, global sources)',
    'config/clients.yaml' => 'Client definitions (names, industries, sources)',
];
foreach ($config_files as $file => $desc) {
    $full = __DIR__ . '/' . $file;
    $exists = file_exists($full);
    $checks[] = [
        'label' => $file,
        'ok' => $exists,
        'value' => $exists ? 'Found' : 'MISSING',
        'hint' => $exists ? $desc : "This file should have come from git. Check that git pull completed.",
    ];
}

// =========================================================================
// 11. Python check (informational)
// =========================================================================
$python_paths = [
    'System python3' => 'python3 --version 2>&1',
];
foreach ($python_paths as $label => $cmd) {
    $output = @shell_exec($cmd);
    $version = $output ? trim($output) : 'Not found / shell_exec disabled';
    $checks[] = [
        'label' => $label,
        'ok' => (bool)$output && strpos($output, 'Python') !== false,
        'value' => $version,
        'hint' => 'Python is needed for the cron job, not for the PHP web UI.',
    ];
}

// =========================================================================
// 12. Quick DB require_once test (does db_connect.php actually work?)
// =========================================================================
if ($inc_exists && $env_exists) {
    try {
        // We already have $db from our manual connection above.
        // This tests if the actual include file parses without fatal errors.
        // We'll do it in a subprocess to avoid double-connection issues.
        $test_code = '<?php error_reporting(E_ALL); require_once "' . addslashes($inc_path) . '"; echo $db->ping() ? "OK" : "FAIL";';
        $tmp = tempnam(sys_get_temp_dir(), 'sv_test_');
        file_put_contents($tmp, $test_code);
        $result = @shell_exec("php " . escapeshellarg($tmp) . " 2>&1");
        @unlink($tmp);

        if ($result !== null) {
            $inc_ok = trim($result) === 'OK';
            $checks[] = [
                'label' => 'db_connect.php include test',
                'ok' => $inc_ok,
                'value' => $inc_ok ? 'Works — $db connected successfully' : trim($result),
                'hint' => $inc_ok ? '' : 'The include file failed. Check the error above.',
            ];
        }
    } catch (Exception $e) {
        // shell_exec may be disabled
    }
}

if ($db_connected) {
    $db->close();
}

// =========================================================================
// Render
// =========================================================================
$pass = count(array_filter($checks, fn($c) => $c['ok']));
$fail = count(array_filter($checks, fn($c) => !$c['ok']));
$total = count($checks);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Setup Diagnostics - Sentiment Vision</title>
<style>
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f1f5f9; padding: 20px; color: #1e293b; }
    .container { max-width: 800px; margin: 0 auto; }
    h1 { font-size: 22px; color: #043546; margin-bottom: 4px; }
    .subtitle { color: #6c757d; font-size: 14px; margin-bottom: 20px; }
    .summary { display: flex; gap: 16px; margin-bottom: 24px; }
    .summary-box { padding: 16px 24px; border-radius: 8px; font-size: 14px; font-weight: 600; }
    .summary-pass { background: #dcfce7; color: #166534; }
    .summary-fail { background: #fee2e2; color: #991b1b; }
    .summary-total { background: #e0e7ff; color: #3730a3; }
    .check { display: flex; align-items: start; padding: 12px 16px; border-bottom: 1px solid #e5e7eb; background: #fff; }
    .check:first-child { border-radius: 8px 8px 0 0; }
    .check:last-child { border-radius: 0 0 8px 8px; border-bottom: none; }
    .check:only-child { border-radius: 8px; }
    .icon { width: 24px; height: 24px; flex-shrink: 0; margin-right: 12px; font-size: 16px; line-height: 24px; text-align: center; }
    .icon-pass { color: #16a34a; }
    .icon-fail { color: #dc2626; }
    .check-body { flex: 1; }
    .check-label { font-weight: 600; font-size: 14px; color: #1e293b; }
    .check-value { font-size: 13px; color: #475569; margin-top: 2px; font-family: 'SF Mono', Monaco, Consolas, monospace; }
    .check-hint { font-size: 12px; color: #dc2626; margin-top: 4px; }
    .check.pass .check-hint { color: #6c757d; }
    .warn { background: #fffbeb; padding: 16px; border-radius: 8px; border: 1px solid #fde68a; margin-top: 20px; font-size: 13px; color: #92400e; }
    .warn strong { color: #78350f; }
</style>
</head>
<body>
<div class="container">

    <h1>Sentiment Vision - Setup Diagnostics</h1>
    <p class="subtitle">Checking environment, database, and schema. Delete this file when everything works.</p>

    <div class="summary">
        <div class="summary-box summary-pass"><?= $pass ?> passed</div>
        <?php if ($fail > 0): ?>
            <div class="summary-box summary-fail"><?= $fail ?> failed</div>
        <?php endif; ?>
        <div class="summary-box summary-total"><?= $total ?> total checks</div>
    </div>

    <?php foreach ($checks as $c): ?>
    <div class="check <?= $c['ok'] ? 'pass' : 'fail' ?>">
        <div class="icon <?= $c['ok'] ? 'icon-pass' : 'icon-fail' ?>">
            <?= $c['ok'] ? '&#10003;' : '&#10007;' ?>
        </div>
        <div class="check-body">
            <div class="check-label"><?= htmlspecialchars($c['label']) ?></div>
            <div class="check-value"><?= $c['value'] ?></div>
            <?php if ($c['hint']): ?>
                <div class="check-hint"><?= $c['hint'] ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if ($fail === 0): ?>
    <div class="warn" style="background: #dcfce7; border-color: #86efac; color: #166534;">
        <strong>All checks passed!</strong> Your setup looks good. You can now:
        <ul style="margin-top: 8px; padding-left: 20px;">
            <li>Visit <a href="index.php">index.php</a> to test the dashboard</li>
            <li>Visit <a href="tags.php">tags.php</a> to seed default ESG tags</li>
            <li>Set up your cron job to run <code>run.sh</code></li>
            <li><strong>Delete this test_setup.php file!</strong></li>
        </ul>
    </div>
    <?php endif; ?>

    <div class="warn">
        <strong>Security reminder:</strong> Delete <code>test_setup.php</code> after you're done debugging.
        It exposes server details that should not be publicly accessible.
    </div>

</div>
</body>
</html>

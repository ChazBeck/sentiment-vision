<?php
/**
 * Sentiment Vision - Cron Runner (PHP wrapper)
 *
 * Use this for shared hosts where cron only supports PHP commands:
 *   /usr/local/bin/php /home/charle22/public_html/tools.veerl.es/dev/sentiment_vision/cron.php
 *
 * This script discovers the Python virtualenv and runs the gatherer.
 * Output goes to logs/cron.log.
 */

// Prevent web access — CLI only
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('This script can only be run from the command line (cron).');
}

$project_dir = __DIR__;
$log_file = $project_dir . '/logs/cron.log';

// Ensure logs directory exists
if (!is_dir($project_dir . '/logs')) {
    mkdir($project_dir . '/logs', 0755, true);
}

// Timestamp for log
$timestamp = date('Y-m-d H:i:s');
file_put_contents($log_file, "\n=== Cron run: $timestamp ===\n", FILE_APPEND);

// ---------------------------------------------------------------------------
// Find the Python virtualenv
// ---------------------------------------------------------------------------
// GreenGeeks/cPanel typically puts virtualenvs here:
$possible_venvs = [
    // cPanel Setup Python App default locations
    "/home/charle22/virtualenv/tools.veerl.es/dev/sentiment_vision/3.13",
    "/home/charle22/virtualenv/tools.veerl.es/dev/sentiment_vision/3.12",
    "/home/charle22/virtualenv/tools.veerl.es/dev/sentiment_vision/3.11",
    "/home/charle22/virtualenv/tools.veerl.es/dev/sentiment_vision/3.10",
    // Local venv in project
    "$project_dir/venv",
];

$python_bin = null;
foreach ($possible_venvs as $venv) {
    $candidate = "$venv/bin/python";
    if (file_exists($candidate)) {
        $python_bin = $candidate;
        break;
    }
    // Also try python3
    $candidate = "$venv/bin/python3";
    if (file_exists($candidate)) {
        $python_bin = $candidate;
        break;
    }
}

if (!$python_bin) {
    $msg = "ERROR: No Python virtualenv found. Checked:\n" . implode("\n", $possible_venvs) . "\n";
    file_put_contents($log_file, $msg, FILE_APPEND);
    echo $msg;
    exit(1);
}

file_put_contents($log_file, "Using Python: $python_bin\n", FILE_APPEND);

// ---------------------------------------------------------------------------
// Build and run the command
// ---------------------------------------------------------------------------
// Pass through any extra arguments (e.g. --verbose, --client "Foo")
$extra_args = '';
if ($argc > 1) {
    $extra_args = ' ' . implode(' ', array_map('escapeshellarg', array_slice($argv, 1)));
}

// Standalone Python builds don't include CA certs — use certifi's bundle
$ssl_cert_cmd = escapeshellarg($python_bin) . " -c \"import certifi; print(certifi.where())\" 2>/dev/null";
$ssl_cert_file = trim(shell_exec($ssl_cert_cmd) ?? '');
$ssl_export = $ssl_cert_file ? "export SSL_CERT_FILE=" . escapeshellarg($ssl_cert_file) . " && " : "";

$cmd = $ssl_export
     . "cd " . escapeshellarg($project_dir)
     . " && " . escapeshellarg($python_bin)
     . " -m src.main --analyze" . $extra_args
     . " 2>&1";

file_put_contents($log_file, "Command: $cmd\n", FILE_APPEND);

// Execute and capture output
$output = [];
$return_code = 0;
exec($cmd, $output, $return_code);

$output_text = implode("\n", $output) . "\n";
file_put_contents($log_file, $output_text, FILE_APPEND);

$status = $return_code === 0 ? "SUCCESS" : "FAILED (exit code: $return_code)";
file_put_contents($log_file, "=== $status ===\n", FILE_APPEND);

echo $output_text;
echo "$status\n";
exit($return_code);

<?php
/**
 * Sentiment Vision - Source Manager
 * Manage global and client-specific RSS/HTML/search sources.
 */

require_once __DIR__ . '/includes/db_connect.php';

$message = '';
$message_type = '';

// Load clients for dropdown
$clients_result = $db->query("SELECT id, name FROM clients ORDER BY name");
$all_clients = [];
while ($row = $clients_result->fetch_assoc()) {
    $all_clients[] = $row;
}

// Parse GET params
$edit_id = isset($_GET['edit']) ? (int)$_GET['edit'] : 0;
$show_add = isset($_GET['add']);

// ---------------------------------------------------------------------------
// Handle form submissions
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save') {
        $name = trim($_POST['name'] ?? '');
        $url = trim($_POST['url'] ?? '');
        $source_type = $_POST['source_type'] ?? 'rss';
        $media_tier = (int)($_POST['media_tier'] ?? 3);
        $scope = $_POST['scope'] ?? 'global';
        $client_id = ($scope === 'client') ? (int)($_POST['client_id'] ?? 0) : null;
        $enabled = isset($_POST['enabled']) ? 1 : 0;
        $save_edit_id = (int)($_POST['edit_id'] ?? 0);

        $is_global = ($scope === 'global') ? 1 : 0;

        if (!$name || !$url) {
            $message = 'Name and URL are required.';
            $message_type = 'error';
        } elseif ($scope === 'client' && !$client_id) {
            $message = 'Please select a client for client-specific sources.';
            $message_type = 'error';
        } elseif (!in_array($source_type, ['rss', 'html', 'search'])) {
            $message = 'Invalid source type.';
            $message_type = 'error';
        } else {
            if ($save_edit_id > 0) {
                // Update existing source
                $stmt = $db->prepare(
                    "UPDATE sources SET name = ?, url = ?, source_type = ?, media_tier = ?,
                     enabled = ?, is_global = ?, client_id = ? WHERE id = ?"
                );
                $stmt->bind_param('sssiiiii', $name, $url, $source_type, $media_tier,
                    $enabled, $is_global, $client_id, $save_edit_id);
                if ($stmt->execute()) {
                    $message = "Source updated: $name";
                    $message_type = 'success';
                } else {
                    $message = 'Failed to update source: ' . $db->error;
                    $message_type = 'error';
                }
                $stmt->close();
                $edit_id = 0; // close the form
            } else {
                // Check for duplicate URL (global sources)
                $duplicate = false;
                if ($is_global) {
                    $chk = $db->prepare("SELECT id FROM sources WHERE is_global = 1 AND url = ?");
                    $chk->bind_param('s', $url);
                    $chk->execute();
                    if ($chk->get_result()->num_rows > 0) {
                        $duplicate = true;
                        $message = 'A global source with this URL already exists.';
                        $message_type = 'error';
                    }
                    $chk->close();
                } else {
                    $chk = $db->prepare("SELECT id FROM sources WHERE client_id = ? AND url = ?");
                    $chk->bind_param('is', $client_id, $url);
                    $chk->execute();
                    if ($chk->get_result()->num_rows > 0) {
                        $duplicate = true;
                        $message = 'This client already has a source with this URL.';
                        $message_type = 'error';
                    }
                    $chk->close();
                }

                if (!$duplicate) {
                    $stmt = $db->prepare(
                        "INSERT INTO sources (client_id, name, source_type, url, enabled, media_tier, is_global)
                         VALUES (?, ?, ?, ?, ?, ?, ?)"
                    );
                    $stmt->bind_param('isssiii', $client_id, $name, $source_type, $url,
                        $enabled, $media_tier, $is_global);
                    if ($stmt->execute()) {
                        $message = "Source added: $name";
                        $message_type = 'success';
                    } else {
                        $message = 'Failed to add source: ' . $db->error;
                        $message_type = 'error';
                    }
                    $stmt->close();
                }
            }
        }
    }

    if ($action === 'delete') {
        $source_id = (int)($_POST['source_id'] ?? 0);
        if ($source_id > 0) {
            // 3-step delete to respect FK constraints
            $db->query("DELETE FROM fetch_log WHERE source_id = $source_id");
            $db->query("UPDATE articles SET source_id = NULL WHERE source_id = $source_id");
            $stmt = $db->prepare("DELETE FROM sources WHERE id = ?");
            $stmt->bind_param('i', $source_id);
            if ($stmt->execute()) {
                $message = 'Source deleted.';
                $message_type = 'success';
            } else {
                $message = 'Failed to delete source: ' . $db->error;
                $message_type = 'error';
            }
            $stmt->close();
        }
    }

    if ($action === 'toggle') {
        $source_id = (int)($_POST['source_id'] ?? 0);
        if ($source_id > 0) {
            $db->query("UPDATE sources SET enabled = NOT enabled WHERE id = $source_id");
            $message = 'Source toggled.';
            $message_type = 'success';
        }
    }
}

// ---------------------------------------------------------------------------
// Load data for display
// ---------------------------------------------------------------------------

// Load edit source if editing
$edit_source = null;
if ($edit_id > 0) {
    $stmt = $db->prepare("SELECT * FROM sources WHERE id = ?");
    $stmt->bind_param('i', $edit_id);
    $stmt->execute();
    $edit_source = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

// Load all sources with client names
$sources_result = $db->query(
    "SELECT s.*, c.name AS client_name
     FROM sources s
     LEFT JOIN clients c ON s.client_id = c.id
     ORDER BY s.is_global DESC, c.name ASC, s.name ASC"
);
$all_sources = [];
while ($row = $sources_result->fetch_assoc()) {
    $all_sources[] = $row;
}

// Load fetch stats aggregated per source
$fetch_stats = [];
$stats_result = $db->query(
    "SELECT source_id,
            COUNT(*) AS total_runs,
            SUM(articles_found) AS total_found,
            SUM(articles_new) AS total_new,
            MAX(run_started_at) AS last_run_at
     FROM fetch_log
     GROUP BY source_id"
);
while ($row = $stats_result->fetch_assoc()) {
    $fetch_stats[$row['source_id']] = $row;
}

// Load latest status per source
$latest_result = $db->query(
    "SELECT fl.source_id, fl.status AS last_status, fl.error_message AS last_error
     FROM fetch_log fl
     INNER JOIN (
         SELECT source_id, MAX(id) AS max_id
         FROM fetch_log
         GROUP BY source_id
     ) latest ON fl.id = latest.max_id"
);
while ($row = $latest_result->fetch_assoc()) {
    if (isset($fetch_stats[$row['source_id']])) {
        $fetch_stats[$row['source_id']]['last_status'] = $row['last_status'];
        $fetch_stats[$row['source_id']]['last_error'] = $row['last_error'];
    }
}

// Separate into global and per-client
$global_sources = [];
$client_sources = []; // grouped by client_id
foreach ($all_sources as $src) {
    if ($src['is_global']) {
        $global_sources[] = $src;
    } else {
        $cid = $src['client_id'];
        if (!isset($client_sources[$cid])) {
            $client_sources[$cid] = [
                'client_name' => $src['client_name'] ?? 'Unknown',
                'sources' => [],
            ];
        }
        $client_sources[$cid]['sources'][] = $src;
    }
}

$db->close();

// Determine if accordion should be open
$accordion_open = $show_add || $edit_source;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Sources - Sentiment Vision</title>
<link rel="stylesheet" href="css/style.css">
<style>
    .section-header { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 12px; margin-top: 28px; }
    .section-header h2 { font-size: 18px; color: #043546; margin: 0; }
    .section-header .count { font-size: 13px; color: #6c757d; font-weight: normal; }

    .source-grid { display: flex; flex-direction: column; gap: 10px; }
    .source-card {
        background: #fff; border: 1px solid #e5e7eb; border-radius: 8px;
        padding: 14px 18px; transition: border-color 0.2s;
    }
    .source-card:hover { border-color: rgba(229, 131, 37, 0.4); }
    .source-card.disabled { opacity: 0.5; }
    .source-header { display: flex; justify-content: space-between; align-items: start; gap: 12px; }
    .source-name {
        font-size: 15px; font-weight: 600; color: #043546; display: flex;
        align-items: center; gap: 8px; flex-wrap: wrap; flex: 1;
        font-family: 'Archivo', sans-serif;
    }
    .source-actions { display: flex; gap: 6px; flex-shrink: 0; }
    .source-url { font-size: 12px; color: #6c757d; margin-top: 4px; max-width: 600px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .source-stats { font-size: 12px; color: #6c757d; margin-top: 6px; display: flex; gap: 14px; flex-wrap: wrap; }
    .source-stats strong { color: #043546; }

    .type-badge {
        font-size: 10px; font-weight: 700; text-transform: uppercase; padding: 2px 8px;
        border-radius: 4px; letter-spacing: 0.5px; font-family: 'Archivo', sans-serif;
    }
    .type-rss { background: #D7E5EF; color: #043546; }
    .type-html { background: #F8E0C8; color: #92400e; }
    .type-search { background: #ECE9E1; color: #736547; }

    .tier-badge {
        font-size: 10px; font-weight: 700; padding: 2px 8px; border-radius: 4px;
        letter-spacing: 0.5px; font-family: 'Archivo', sans-serif;
    }
    .tier-1 { background: #D7E5EF; color: #043546; }
    .tier-2 { background: #DBEDE9; color: #00434F; }
    .tier-3 { background: #CCD8D1; color: #415649; }
    .tier-4 { background: #F8E0C8; color: #E58325; }

    .status-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 4px; vertical-align: middle; }
    .status-success { background: #16a34a; }
    .status-error { background: #dc2626; }
    .status-skipped { background: #eab308; }
</style>
</head>
<body>

<div class="sub-nav-wrap">
<nav class="sub-nav">
    <a href="index.php">Dashboard</a>
    <a href="clients.php">Clients</a>
    <a href="tags.php">Tags</a>
    <a href="sources.php" class="active">Sources</a>
</nav>
</div>

<div class="page-bg">
<div class="content-card">

    <?php if ($message): ?>
        <div class="alert alert-<?= $message_type ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- Add New Source accordion toggle -->
    <div style="margin-bottom: 16px;">
        <button type="button" class="btn btn-primary" onclick="toggleAccordion('add-source-form')">
            <?= $edit_source ? 'Edit Source: ' . htmlspecialchars($edit_source['name']) : '+ Source' ?> &#9662;
        </button>
    </div>

    <div id="add-source-form" class="accordion-body <?= $accordion_open ? 'open' : '' ?>">
    <div class="card" style="margin-bottom: 20px;">
        <h2><?= $edit_source ? 'Edit Source' : 'Add New Source' ?></h2>

        <form method="post" action="sources.php">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="edit_id" value="<?= $edit_source['id'] ?? 0 ?>">

            <div class="form-row">
                <div class="form-group" style="flex: 2;">
                    <label>Source Name</label>
                    <input type="text" name="name" required placeholder="e.g. Reuters via Google News"
                           value="<?= htmlspecialchars($edit_source['name'] ?? '') ?>">
                </div>
                <div class="form-group" style="flex: 0 0 140px;">
                    <label>Source Type</label>
                    <select name="source_type">
                        <option value="rss" <?= ($edit_source && $edit_source['source_type'] === 'rss') ? 'selected' : '' ?>>RSS</option>
                        <option value="html" <?= ($edit_source && $edit_source['source_type'] === 'html') ? 'selected' : '' ?>>HTML</option>
                        <option value="search" <?= ($edit_source && $edit_source['source_type'] === 'search') ? 'selected' : '' ?>>Search</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>URL</label>
                <input type="url" name="url" required placeholder="https://feeds.example.com/rss/news"
                       value="<?= htmlspecialchars($edit_source['url'] ?? '') ?>">
            </div>

            <div class="form-row">
                <div class="form-group" style="flex: 0 0 200px;">
                    <label>Media Tier</label>
                    <select name="media_tier">
                        <option value="1" <?= ($edit_source && $edit_source['media_tier'] == 1) ? 'selected' : '' ?>>Tier 1 — Major Media</option>
                        <option value="2" <?= ($edit_source && $edit_source['media_tier'] == 2) ? 'selected' : '' ?>>Tier 2 — Business/Tech</option>
                        <option value="3" <?= (!$edit_source || $edit_source['media_tier'] == 3) ? 'selected' : '' ?>>Tier 3 — Industry Trade</option>
                        <option value="4" <?= ($edit_source && $edit_source['media_tier'] == 4) ? 'selected' : '' ?>>Tier 4 — Vendor/Marketing</option>
                    </select>
                </div>
                <div class="form-group" style="flex: 0 0 200px;">
                    <label>Scope</label>
                    <select name="scope" id="scope-select" onchange="toggleClientDropdown()">
                        <option value="global" <?= (!$edit_source || $edit_source['is_global']) ? 'selected' : '' ?>>Global (all clients)</option>
                        <option value="client" <?= ($edit_source && !$edit_source['is_global']) ? 'selected' : '' ?>>Client-specific</option>
                    </select>
                </div>
                <div class="form-group" id="client-dropdown-group" style="flex: 1; min-width: 160px; <?= (!$edit_source || $edit_source['is_global']) ? 'display:none;' : '' ?>">
                    <label>Client</label>
                    <select name="client_id">
                        <option value="">— Select client —</option>
                        <?php foreach ($all_clients as $c): ?>
                            <option value="<?= $c['id'] ?>" <?= ($edit_source && $edit_source['client_id'] == $c['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="checkbox-row">
                <input type="checkbox" name="enabled" value="1" id="enabled-check"
                       <?= (!$edit_source || $edit_source['enabled']) ? 'checked' : '' ?>>
                <label for="enabled-check">Enabled</label>
            </div>

            <div style="margin-top: 20px; display: flex; gap: 10px;">
                <button type="submit" class="btn btn-primary">
                    <?= $edit_source ? 'Update Source' : 'Add Source' ?>
                </button>
                <?php if ($edit_source): ?>
                    <a href="sources.php" class="btn btn-outline">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
    </div><!-- /accordion -->

    <!-- ================================================================= -->
    <!-- GLOBAL SOURCES                                                     -->
    <!-- ================================================================= -->
    <?php if (!empty($global_sources)): ?>
    <div class="section-header">
        <h2>Global Sources <span class="count">(<?= count($global_sources) ?>)</span></h2>
    </div>
    <div class="source-grid">
        <?php foreach ($global_sources as $src): ?>
        <div class="source-card <?= $src['enabled'] ? '' : 'disabled' ?>">
            <div class="source-header">
                <div class="source-name">
                    <?= htmlspecialchars($src['name']) ?>
                    <span class="type-badge type-<?= $src['source_type'] ?>"><?= strtoupper($src['source_type']) ?></span>
                    <span class="tier-badge tier-<?= $src['media_tier'] ?>">Tier <?= $src['media_tier'] ?></span>
                    <?php if (!$src['enabled']): ?>
                        <span style="font-size: 11px; color: #6c757d;">(disabled)</span>
                    <?php endif; ?>
                </div>
                <div class="source-actions">
                    <a href="sources.php?edit=<?= $src['id'] ?>" class="btn btn-sm btn-outline">Edit</a>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="source_id" value="<?= $src['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline"><?= $src['enabled'] ? 'Disable' : 'Enable' ?></button>
                    </form>
                    <form method="post" style="display:inline;"
                          onsubmit="return confirm('Delete source: <?= htmlspecialchars(addslashes($src['name'])) ?>?\nThis will also delete all fetch logs for this source.')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="source_id" value="<?= $src['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                    </form>
                </div>
            </div>
            <div class="source-url" title="<?= htmlspecialchars($src['url']) ?>">
                <?= htmlspecialchars($src['url']) ?>
            </div>
            <?php $stats = $fetch_stats[$src['id']] ?? null; ?>
            <div class="source-stats">
                <?php if ($stats): ?>
                    <span>
                        <span class="status-dot status-<?= $stats['last_status'] ?? 'skipped' ?>"></span>
                        Last: <?= date('M j, g:ia', strtotime($stats['last_run_at'])) ?>
                    </span>
                    <span><strong><?= number_format($stats['total_runs']) ?></strong> runs</span>
                    <span><strong><?= number_format($stats['total_found']) ?></strong> found</span>
                    <span><strong><?= number_format($stats['total_new']) ?></strong> new</span>
                    <?php if (($stats['last_status'] ?? '') === 'error' && !empty($stats['last_error'])): ?>
                        <span style="color: #dc2626;" title="<?= htmlspecialchars($stats['last_error']) ?>">⚠ Error</span>
                    <?php endif; ?>
                <?php else: ?>
                    <span style="color: #d1d5db;">Never fetched</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ================================================================= -->
    <!-- PER-CLIENT SOURCES                                                 -->
    <!-- ================================================================= -->
    <?php foreach ($client_sources as $cid => $group): ?>
    <hr class="section-divider">
    <div class="section-header">
        <h2><?= htmlspecialchars($group['client_name']) ?> Sources <span class="count">(<?= count($group['sources']) ?>)</span></h2>
    </div>
    <div class="source-grid">
        <?php foreach ($group['sources'] as $src): ?>
        <div class="source-card <?= $src['enabled'] ? '' : 'disabled' ?>">
            <div class="source-header">
                <div class="source-name">
                    <?= htmlspecialchars($src['name']) ?>
                    <span class="type-badge type-<?= $src['source_type'] ?>"><?= strtoupper($src['source_type']) ?></span>
                    <span class="tier-badge tier-<?= $src['media_tier'] ?>">Tier <?= $src['media_tier'] ?></span>
                    <?php if (!$src['enabled']): ?>
                        <span style="font-size: 11px; color: #6c757d;">(disabled)</span>
                    <?php endif; ?>
                </div>
                <div class="source-actions">
                    <a href="sources.php?edit=<?= $src['id'] ?>" class="btn btn-sm btn-outline">Edit</a>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="source_id" value="<?= $src['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline"><?= $src['enabled'] ? 'Disable' : 'Enable' ?></button>
                    </form>
                    <form method="post" style="display:inline;"
                          onsubmit="return confirm('Delete source: <?= htmlspecialchars(addslashes($src['name'])) ?>?\nThis will also delete all fetch logs for this source.')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="source_id" value="<?= $src['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                    </form>
                </div>
            </div>
            <div class="source-url" title="<?= htmlspecialchars($src['url']) ?>">
                <?= htmlspecialchars($src['url']) ?>
            </div>
            <?php $stats = $fetch_stats[$src['id']] ?? null; ?>
            <div class="source-stats">
                <?php if ($stats): ?>
                    <span>
                        <span class="status-dot status-<?= $stats['last_status'] ?? 'skipped' ?>"></span>
                        Last: <?= date('M j, g:ia', strtotime($stats['last_run_at'])) ?>
                    </span>
                    <span><strong><?= number_format($stats['total_runs']) ?></strong> runs</span>
                    <span><strong><?= number_format($stats['total_found']) ?></strong> found</span>
                    <span><strong><?= number_format($stats['total_new']) ?></strong> new</span>
                    <?php if (($stats['last_status'] ?? '') === 'error' && !empty($stats['last_error'])): ?>
                        <span style="color: #dc2626;" title="<?= htmlspecialchars($stats['last_error']) ?>">⚠ Error</span>
                    <?php endif; ?>
                <?php else: ?>
                    <span style="color: #d1d5db;">Never fetched</span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endforeach; ?>

    <!-- Empty state -->
    <?php if (empty($global_sources) && empty($client_sources)): ?>
    <div style="text-align: center; padding: 60px 20px; color: #6c757d;">
        <h2 style="color: #043546; font-size: 20px; margin-bottom: 8px;">No sources configured</h2>
        <p>Click "Add New Source" above to add your first RSS feed or search source.</p>
    </div>
    <?php endif; ?>

</div><!-- /content-card -->
</div><!-- /page-bg -->

<footer>Sentiment Vision &middot; Source Manager</footer>

<script>
function toggleAccordion(id) {
    document.getElementById(id).classList.toggle('open');
}

function toggleClientDropdown() {
    var scope = document.getElementById('scope-select').value;
    var group = document.getElementById('client-dropdown-group');
    group.style.display = (scope === 'client') ? '' : 'none';
}

document.addEventListener('DOMContentLoaded', function() {
    toggleClientDropdown();
});
</script>

</body>
</html>

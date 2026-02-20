<?php
/**
 * Sentiment Vision - Client Manager
 * Add, edit, and delete clients in clients.yaml via a simple form.
 */

$yaml_path = __DIR__ . '/config/clients.yaml';

// ---------------------------------------------------------------------------
// Helper: read clients.yaml via Python (uses venv's pyyaml)
// ---------------------------------------------------------------------------
function load_clients_yaml(string $path): array {
    if (!file_exists($path)) {
        return [];
    }
    $venv_python = dirname($path) . '/../venv/bin/python3';
    if (!file_exists($venv_python)) {
        $venv_python = 'python3'; // fallback to system python
    }
    $cmd = escapeshellarg($venv_python)
         . ' -c "import yaml, json, sys; data = yaml.safe_load(open(sys.argv[1])); print(json.dumps(data))" '
         . escapeshellarg($path)
         . ' 2>&1';
    $json = shell_exec($cmd);
    $data = json_decode(trim($json ?? ''), true);
    return $data['clients'] ?? [];
}

// ---------------------------------------------------------------------------
// Helper: write clients.yaml (pure PHP -- generates clean YAML for our schema)
// ---------------------------------------------------------------------------
function save_clients_yaml(string $path, array $clients): bool {
    $lines = ["clients:"];
    foreach ($clients as $c) {
        $lines[] = "  - name: " . yaml_quote($c['name'] ?? '');

        $lines[] = "    industries:";
        foreach ($c['industries'] ?? [] as $v) {
            $lines[] = "      - " . yaml_quote($v);
        }

        $lines[] = "    competitors:";
        foreach ($c['competitors'] ?? [] as $v) {
            $lines[] = "      - " . yaml_quote($v);
        }

        $lines[] = "    sources:";
        foreach ($c['sources'] ?? [] as $s) {
            $lines[] = "      - name: " . yaml_quote($s['name'] ?? '');
            $lines[] = "        type: " . ($s['type'] ?? 'rss');
            $lines[] = "        url: " . yaml_quote($s['url'] ?? '');
        }
    }
    $yaml = implode("\n", $lines) . "\n";
    $result = file_put_contents($path, $yaml);
    if ($result === false) {
        error_log("save_clients_yaml: failed to write $path — check permissions");
    }
    return $result !== false;
}

// Quote a YAML string value if it contains special chars
function yaml_quote(string $val): string {
    if ($val === '') return '""';
    // Quote if it contains characters that could confuse YAML parsers
    if (preg_match('/[:#\[\]{}&*!|>\'"%@`,?\\\\]/', $val) || $val !== trim($val)) {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $val) . '"';
    }
    return '"' . $val . '"';
}

// ---------------------------------------------------------------------------
// Handle form submissions
// ---------------------------------------------------------------------------
$message = '';
$message_type = '';
$edit_index = isset($_GET['edit']) ? (int)$_GET['edit'] : -1;
$adding_new = isset($_GET['add']);
$show_form = $adding_new || $edit_index >= 0;

$clients = load_clients_yaml($yaml_path);

// DELETE
if (isset($_POST['action']) && $_POST['action'] === 'delete') {
    $del = (int)$_POST['index'];
    if (isset($clients[$del])) {
        $deleted_name = $clients[$del]['name'];
        array_splice($clients, $del, 1);
        if (save_clients_yaml($yaml_path, $clients)) {
            $message = "Deleted client: $deleted_name";
            $message_type = 'info';
        } else {
            $message = "Failed to delete. Check that config/ is writable by the web server.";
            $message_type = 'error';
            $clients = load_clients_yaml($yaml_path); // reload original
        }
        $edit_index = -1;
    }
}

// SAVE (add or update)
if (isset($_POST['action']) && $_POST['action'] === 'save') {
    $name = trim($_POST['name'] ?? '');
    $industries_raw = trim($_POST['industries'] ?? '');
    $competitors_raw = trim($_POST['competitors'] ?? '');
    $sources_urls = array_filter(array_map('trim', $_POST['source_url'] ?? []));
    $sources_names = $_POST['source_name'] ?? [];
    $sources_types = $_POST['source_type'] ?? [];

    if ($name === '') {
        $message = 'Client name is required.';
        $message_type = 'error';
        $show_form = true;
    } elseif (empty($sources_urls)) {
        $message = 'At least one source URL is required.';
        $message_type = 'error';
        $show_form = true;
    } else {
        // Build arrays from textarea lines
        $industries = array_values(array_filter(array_map('trim', explode("\n", $industries_raw))));
        $competitors = array_values(array_filter(array_map('trim', explode("\n", $competitors_raw))));

        // Build sources
        $sources = [];
        foreach ($sources_urls as $i => $url) {
            if ($url === '') continue;
            $sname = trim($sources_names[$i] ?? '');
            $stype = $sources_types[$i] ?? 'rss';
            if ($sname === '') {
                // Auto-generate a label from the URL domain
                $parsed = parse_url($url);
                $sname = ($parsed['host'] ?? 'Feed') . ' RSS';
            }
            $sources[] = [
                'name' => $sname,
                'type' => $stype,
                'url' => $url,
            ];
        }

        // Also auto-add a Google News search source for the client name
        $has_search = false;
        foreach ($sources as $s) {
            if ($s['type'] === 'search') { $has_search = true; break; }
        }
        if (!$has_search && isset($_POST['auto_google_news']) && $_POST['auto_google_news'] === '1') {
            $sources[] = [
                'name' => "Google News - $name",
                'type' => 'search',
                'url' => 'https://news.google.com/rss/search?q=' . urlencode($name),
            ];
        }

        $client_entry = [
            'name' => $name,
            'industries' => $industries,
            'competitors' => $competitors,
            'sources' => $sources,
        ];

        $save_index = isset($_POST['edit_index']) ? (int)$_POST['edit_index'] : -1;
        if ($save_index >= 0 && isset($clients[$save_index])) {
            $clients[$save_index] = $client_entry;
            $message = "Updated client: $name";
        } else {
            $clients[] = $client_entry;
            $message = "Added client: $name";
        }
        if (save_clients_yaml($yaml_path, $clients)) {
            $message_type = 'success';
        } else {
            $message_type = 'error';
            $message = "Failed to write config file. Check that config/ is writable by the web server.";
        }
        $edit_index = -1;

        // Reload
        $clients = load_clients_yaml($yaml_path);
    }
}

// Prepare edit data
$edit_data = null;
if ($edit_index >= 0 && isset($clients[$edit_index])) {
    $edit_data = $clients[$edit_index];
}

// ---------------------------------------------------------------------------
// Database connection (used for client IDs, tag CRUD, and tag display)
// ---------------------------------------------------------------------------
$client_db_ids = [];
$edit_client_tags = [];
$tag_message = '';
$tag_message_type = '';

require_once __DIR__ . '/includes/db_connect.php';
$db_ok = true;
{

    // Look up DB IDs for client "View" links
    $res = $db->query("SELECT id, name FROM clients");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $client_db_ids[$row['name']] = $row['id'];
        }
    }

    // Handle tag actions (separate from client save/delete)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['tag_action'])) {
        $tag_action = $_POST['tag_action'];

        if ($tag_action === 'save_tag') {
            $t_name = trim($_POST['tag_name'] ?? '');
            $t_type = $_POST['tag_type'] ?? 'custom';
            $t_keywords_raw = trim($_POST['tag_keywords'] ?? '');
            $t_color = $_POST['tag_color'] ?? '#f59e0b';
            $t_client_id = (int)($_POST['tag_client_id'] ?? 0);
            $t_edit_id = (int)($_POST['tag_edit_id'] ?? 0);

            $t_keywords = array_values(array_filter(array_map('trim', explode("\n", $t_keywords_raw))));

            if ($t_name === '') {
                $tag_message = 'Tag name is required.';
                $tag_message_type = 'error';
            } elseif (empty($t_keywords)) {
                $tag_message = 'At least one keyword is required.';
                $tag_message_type = 'error';
            } elseif ($t_client_id <= 0) {
                $tag_message = 'Invalid client for tag.';
                $tag_message_type = 'error';
            } else {
                $kw_json = json_encode($t_keywords);
                if ($t_edit_id > 0) {
                    $stmt = $db->prepare("UPDATE tags SET name = ?, tag_type = ?, keywords = ?, color = ? WHERE id = ? AND client_id = ?");
                    $stmt->bind_param('ssssii', $t_name, $t_type, $kw_json, $t_color, $t_edit_id, $t_client_id);
                    if ($stmt->execute()) {
                        $tag_message = "Updated tag: $t_name";
                        $tag_message_type = 'success';
                    } else {
                        $tag_message = "Failed to update tag.";
                        $tag_message_type = 'error';
                    }
                } else {
                    $scope = 'client';
                    $stmt = $db->prepare("INSERT INTO tags (name, tag_type, scope, client_id, keywords, color) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param('sssiss', $t_name, $t_type, $scope, $t_client_id, $kw_json, $t_color);
                    if ($stmt->execute()) {
                        $tag_message = "Added tag: $t_name";
                        $tag_message_type = 'success';
                    } else {
                        $tag_message = "Failed to add tag. It may already exist for this client.";
                        $tag_message_type = 'error';
                    }
                }
            }
        }

        if ($tag_action === 'delete_tag') {
            $del_tag_id = (int)$_POST['tag_id'];
            $stmt = $db->prepare("DELETE FROM tags WHERE id = ?");
            $stmt->bind_param('i', $del_tag_id);
            $stmt->execute();
            $tag_message = "Tag deleted.";
            $tag_message_type = 'info';
        }

        if ($tag_action === 'toggle_tag') {
            $tog_id = (int)$_POST['tag_id'];
            $stmt = $db->prepare("UPDATE tags SET enabled = NOT enabled WHERE id = ?");
            $stmt->bind_param('i', $tog_id);
            $stmt->execute();
            $tag_message = "Tag toggled.";
            $tag_message_type = 'info';
        }
    }

    // Load client-specific tags for the client being edited
    if ($edit_data && isset($client_db_ids[$edit_data['name']])) {
        $edit_cid = $client_db_ids[$edit_data['name']];
        $stmt = $db->prepare("
            SELECT t.*, COUNT(at2.id) AS usage_count
            FROM tags t
            LEFT JOIN article_tags at2 ON t.id = at2.tag_id
            WHERE t.scope = 'client' AND t.client_id = ?
            GROUP BY t.id
            ORDER BY t.name
        ");
        $stmt->bind_param('i', $edit_cid);
        $stmt->execute();
        $tres = $stmt->get_result();
        while ($row = $tres->fetch_assoc()) {
            $row['keywords'] = json_decode($row['keywords'], true) ?: [];
            $edit_client_tags[] = $row;
        }
    }

    // Load all client tags for display in the client cards
    $all_client_tags = [];
    $tres = $db->query("SELECT t.client_id, t.name, t.color, t.enabled FROM tags t WHERE t.scope = 'client' ORDER BY t.name");
    if ($tres) {
        while ($row = $tres->fetch_assoc()) {
            $cid = $row['client_id'];
            if (!isset($all_client_tags[$cid])) $all_client_tags[$cid] = [];
            $all_client_tags[$cid][] = $row;
        }
    }

    // Check if editing a specific tag (inline edit)
    $edit_tag = null;
    if (isset($_GET['edit_tag'])) {
        $et_id = (int)$_GET['edit_tag'];
        $stmt = $db->prepare("SELECT * FROM tags WHERE id = ?");
        $stmt->bind_param('i', $et_id);
        $stmt->execute();
        $edit_tag = $stmt->get_result()->fetch_assoc();
        if ($edit_tag) {
            $edit_tag['keywords'] = json_decode($edit_tag['keywords'], true) ?: [];
        }
    }

    $db->close();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Manage Clients - Sentiment Vision</title>
<link rel="stylesheet" href="css/style.css">
<style>
    .source-row { display: flex; gap: 8px; align-items: start; margin-bottom: 8px; }
    .source-row input[type=url] { flex: 3; }
    .source-row input[type=text] { flex: 2; }
    .source-row select { flex: 0 0 90px; }
    .source-row button { flex: 0 0 36px; height: 36px; border: 1px solid #fecaca; background: #fff; color: #dc2626; border-radius: 4px; cursor: pointer; font-size: 16px; }
    .source-row button:hover { background: #fee2e2; }

    .client-list { display: grid; gap: 12px; }
    .client-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px 20px; }
    .client-card:hover { border-color: rgba(229, 131, 37, 0.4); }
    .client-header { display: flex; justify-content: space-between; align-items: start; margin-bottom: 8px; }
    .client-name { font-size: 16px; font-weight: 600; color: #043546; font-family: 'Archivo', sans-serif; }
    .client-actions { display: flex; gap: 6px; }
    .tag-list { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 4px; }
    .client-meta { font-size: 13px; color: #6c757d; margin-top: 6px; }
    .source-list { margin-top: 8px; }
    .source-item { font-size: 13px; color: #374151; padding: 2px 0; display: flex; align-items: center; gap: 6px; }
    .source-type-badge {
        font-size: 10px; font-weight: 700; text-transform: uppercase; padding: 1px 6px;
        border-radius: 4px; background: #f1f5f9; color: #6c757d; letter-spacing: 0.5px;
        font-family: 'Archivo', sans-serif;
    }

    .tags-section { border-top: 1px solid #e5e7eb; margin-top: 24px; padding-top: 20px; }
    .tags-section h3 { font-size: 16px; color: #043546; margin-bottom: 4px; }
    .tags-section .hint { font-size: 13px; color: #6c757d; margin-bottom: 12px; }
    .tag-form-row { display: flex; gap: 8px; align-items: end; margin-bottom: 12px; flex-wrap: wrap; }
    .tag-form-row .form-group { margin-bottom: 0; }
    .tag-item {
        display: flex; align-items: center; justify-content: space-between;
        padding: 10px 14px; background: #f9fafb; border: 1px solid #e5e7eb;
        border-radius: 4px; margin-bottom: 6px;
    }
    .tag-item.disabled { opacity: 0.5; }
    .tag-item-info { display: flex; align-items: center; gap: 8px; flex: 1; flex-wrap: wrap; }
    .tag-item-badge { display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: 12px; font-weight: 600; color: #fff; }
    .tag-item-kws { display: flex; flex-wrap: wrap; gap: 3px; }
    .tag-item-kw { font-size: 11px; padding: 1px 6px; border-radius: 20px; background: #e5e7eb; color: #374151; }
    .tag-item-meta { font-size: 11px; color: #6c757d; }
    .tag-item-actions { display: flex; gap: 4px; flex-shrink: 0; margin-left: 8px; }
</style>
</head>
<body>

<div class="sub-nav-wrap">
<nav class="sub-nav">
    <a href="index.php">Dashboard</a>
    <a href="clients.php" class="active">Clients</a>
    <a href="tags.php">Tags</a>
    <a href="sources.php">Sources</a>
</nav>
</div>

<div class="page-bg">
<div class="content-card">

    <?php if ($message): ?>
        <div class="alert alert-<?= $message_type ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- Add New Client accordion toggle -->
    <div style="margin-bottom: 20px;">
        <button type="button" class="btn btn-primary" onclick="toggleAccordion('client-form-accordion')">
            <?= $edit_data ? 'Edit Client: ' . htmlspecialchars($edit_data['name']) : '+ Client' ?> &#9662;
        </button>
    </div>

    <!-- Add / Edit Form (inside accordion, above client list) -->
    <div id="client-form-accordion" class="accordion-body <?= $show_form ? 'open' : '' ?>">
    <div class="card" style="margin-bottom: 20px;">
        <h2><?= $edit_data ? 'Edit Client: ' . htmlspecialchars($edit_data['name']) : 'Add New Client' ?></h2>

        <form method="post" action="clients.php">
            <input type="hidden" name="action" value="save">
            <?php if ($edit_index >= 0): ?>
                <input type="hidden" name="edit_index" value="<?= $edit_index ?>">
            <?php endif; ?>

            <div class="form-group">
                <label for="name">Client Name</label>
                <input type="text" id="name" name="name" required
                       placeholder="e.g. Cologix"
                       value="<?= htmlspecialchars($edit_data['name'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="industries">Industries</label>
                <div class="hint">One per line</div>
                <textarea id="industries" name="industries" rows="3"
                          placeholder="Data Centers&#10;Colocation"
                ><?= htmlspecialchars(implode("\n", $edit_data['industries'] ?? [])) ?></textarea>
            </div>

            <div class="form-group">
                <label for="competitors">Competitors</label>
                <div class="hint">One per line</div>
                <textarea id="competitors" name="competitors" rows="3"
                          placeholder="Equinix&#10;Digital Realty&#10;Digital Edge"
                ><?= htmlspecialchars(implode("\n", $edit_data['competitors'] ?? [])) ?></textarea>
            </div>

            <div class="form-group">
                <label>Sources</label>
                <div class="hint">Add RSS feeds, HTML page URLs, or Google News search URLs</div>
                <div id="sources-container">
                    <?php
                    $existing_sources = $edit_data['sources'] ?? [];
                    if (empty($existing_sources)) {
                        // Show one empty row by default
                        $existing_sources = [['url' => '', 'name' => '', 'type' => 'rss']];
                    }
                    foreach ($existing_sources as $si => $src):
                    ?>
                    <div class="source-row">
                        <input type="url" name="source_url[]" placeholder="https://example.com/rss/feed"
                               value="<?= htmlspecialchars($src['url'] ?? '') ?>">
                        <input type="text" name="source_name[]" placeholder="Label (optional)"
                               value="<?= htmlspecialchars($src['name'] ?? '') ?>">
                        <select name="source_type[]">
                            <option value="rss" <?= ($src['type'] ?? '') === 'rss' ? 'selected' : '' ?>>RSS</option>
                            <option value="html" <?= ($src['type'] ?? '') === 'html' ? 'selected' : '' ?>>HTML</option>
                            <option value="search" <?= ($src['type'] ?? '') === 'search' ? 'selected' : '' ?>>Search</option>
                        </select>
                        <button type="button" onclick="removeSource(this)" title="Remove">&times;</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="btn btn-sm btn-outline" onclick="addSource()" style="margin-top: 6px;">+ Add Source</button>
            </div>

            <div class="checkbox-row">
                <input type="checkbox" id="auto_google_news" name="auto_google_news" value="1"
                       <?= !$edit_data ? 'checked' : '' ?>>
                <label for="auto_google_news">Auto-add a Google News search source for this client name</label>
            </div>

            <div style="margin-top: 20px; display: flex; gap: 10px;">
                <button type="submit" class="btn btn-primary">
                    <?= $edit_data ? 'Update Client' : 'Add Client' ?>
                </button>
                <a href="clients.php" class="btn btn-outline">Cancel</a>
            </div>
        </form>

        <?php
        // Show client-specific tags section when editing an existing client that exists in the DB
        $edit_cid = ($edit_data && isset($client_db_ids[$edit_data['name']])) ? $client_db_ids[$edit_data['name']] : 0;
        if ($edit_data && $edit_cid > 0):
        ?>
        <div class="tags-section">
            <h3>Client-Specific Tags</h3>
            <div class="hint">Tags specific to <?= htmlspecialchars($edit_data['name']) ?>. Use for team members, projects, topics, etc.</div>

            <?php if ($tag_message): ?>
                <div class="alert alert-<?= $tag_message_type ?>" style="margin-bottom: 12px;"><?= htmlspecialchars($tag_message) ?></div>
            <?php endif; ?>

            <!-- Add / Edit tag form -->
            <form method="post" action="clients.php?edit=<?= $edit_index ?>">
                <input type="hidden" name="tag_action" value="save_tag">
                <input type="hidden" name="tag_client_id" value="<?= $edit_cid ?>">
                <input type="hidden" name="tag_edit_id" value="<?= $edit_tag ? $edit_tag['id'] : 0 ?>">

                <div class="tag-form-row">
                    <div class="form-group" style="flex: 2;">
                        <label>Tag Name</label>
                        <input type="text" name="tag_name" required placeholder="e.g. John Smith, Project Phoenix"
                               value="<?= $edit_tag ? htmlspecialchars($edit_tag['name']) : '' ?>">
                    </div>
                    <div class="form-group" style="flex: 0 0 110px;">
                        <label>Type</label>
                        <select name="tag_type">
                            <option value="custom" <?= (!$edit_tag || $edit_tag['tag_type'] === 'custom') ? 'selected' : '' ?>>Custom</option>
                            <option value="esg" <?= ($edit_tag && $edit_tag['tag_type'] === 'esg') ? 'selected' : '' ?>>ESG</option>
                        </select>
                    </div>
                    <div class="form-group" style="flex: 0 0 50px;">
                        <label>Color</label>
                        <input type="color" name="tag_color"
                               value="<?= $edit_tag ? htmlspecialchars($edit_tag['color']) : '#f59e0b' ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label>Keywords</label>
                    <div class="hint">One per line. Article is tagged if ANY keyword appears in its text.</div>
                    <textarea name="tag_keywords" rows="3" placeholder="John Smith&#10;J. Smith&#10;Smith, John"
                    ><?= $edit_tag ? htmlspecialchars(implode("\n", $edit_tag['keywords'])) : '' ?></textarea>
                </div>
                <div style="display: flex; gap: 8px;">
                    <button type="submit" class="btn btn-sm btn-primary">
                        <?= $edit_tag ? 'Update Tag' : 'Add Tag' ?>
                    </button>
                    <?php if ($edit_tag): ?>
                        <a href="clients.php?edit=<?= $edit_index ?>" class="btn btn-sm btn-outline">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>

            <!-- Existing client tags list -->
            <?php if (!empty($edit_client_tags)): ?>
            <div style="margin-top: 16px;">
                <?php foreach ($edit_client_tags as $t): ?>
                <div class="tag-item <?= $t['enabled'] ? '' : 'disabled' ?>">
                    <div class="tag-item-info">
                        <span class="tag-item-badge" style="background:<?= htmlspecialchars($t['color']) ?>">
                            <?= htmlspecialchars($t['name']) ?>
                        </span>
                        <div class="tag-item-kws">
                            <?php foreach ($t['keywords'] as $kw): ?>
                                <span class="tag-item-kw"><?= htmlspecialchars($kw) ?></span>
                            <?php endforeach; ?>
                        </div>
                        <span class="tag-item-meta"><?= (int)$t['usage_count'] ?> articles</span>
                        <?php if (!$t['enabled']): ?>
                            <span class="tag-item-meta">(disabled)</span>
                        <?php endif; ?>
                    </div>
                    <div class="tag-item-actions">
                        <a href="clients.php?edit=<?= $edit_index ?>&edit_tag=<?= $t['id'] ?>" class="btn btn-sm btn-outline">Edit</a>
                        <form method="post" action="clients.php?edit=<?= $edit_index ?>" style="display:inline;">
                            <input type="hidden" name="tag_action" value="toggle_tag">
                            <input type="hidden" name="tag_id" value="<?= $t['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline"><?= $t['enabled'] ? 'Disable' : 'Enable' ?></button>
                        </form>
                        <form method="post" action="clients.php?edit=<?= $edit_index ?>" style="display:inline;"
                              onsubmit="return confirm('Delete tag: <?= htmlspecialchars(addslashes($t['name'])) ?>?')">
                            <input type="hidden" name="tag_action" value="delete_tag">
                            <input type="hidden" name="tag_id" value="<?= $t['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php elseif ($edit_data): ?>
                <p style="color: #6c757d; font-size: 13px; margin-top: 12px;">No client-specific tags yet. Add one above.</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>

    </div>
    </div><!-- /accordion -->

    <!-- Existing Clients List -->
    <?php if (!empty($clients)): ?>
    <h2 style="font-size: 18px; margin-bottom: 12px; color: #043546;">
        Clients (<?= count($clients) ?>)
    </h2>
    <div class="client-list">
        <?php foreach ($clients as $ci => $c): ?>
        <div class="client-card">
            <div class="client-header">
                <div class="client-name"><?= htmlspecialchars($c['name']) ?></div>
                <div class="client-actions">
                    <?php if (isset($client_db_ids[$c['name']])): ?>
                        <a href="client.php?id=<?= $client_db_ids[$c['name']] ?>" class="btn btn-sm btn-outline" style="background:#E58325;color:#fff;border-color:#E58325;">View</a>
                    <?php endif; ?>
                    <a href="clients.php?edit=<?= $ci ?>" class="btn btn-sm btn-outline">Edit</a>
                    <form method="post" style="display:inline;"
                          onsubmit="return confirm('Delete <?= htmlspecialchars(addslashes($c['name'])) ?>?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="index" value="<?= $ci ?>">
                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                    </form>
                </div>
            </div>

            <?php if (!empty($c['industries'])): ?>
            <div class="tag-list">
                <?php foreach ($c['industries'] as $ind): ?>
                    <span class="tag tag-blue"><?= htmlspecialchars($ind) ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if (!empty($c['competitors'])): ?>
            <div class="client-meta">
                Competitors: <?= htmlspecialchars(implode(', ', $c['competitors'])) ?>
            </div>
            <?php endif; ?>

            <?php
            $card_cid = $client_db_ids[$c['name']] ?? 0;
            $card_tags = $all_client_tags[$card_cid] ?? [];
            if (!empty($card_tags)):
            ?>
            <div class="tag-list" style="margin-top: 6px;">
                <?php foreach ($card_tags as $ct): ?>
                    <span class="tag" style="background:<?= htmlspecialchars($ct['color']) ?>;color:#fff;<?= $ct['enabled'] ? '' : 'opacity:0.4;' ?>">
                        <?= htmlspecialchars($ct['name']) ?>
                    </span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="source-list">
                <?php foreach ($c['sources'] ?? [] as $src): ?>
                <div class="source-item">
                    <span class="source-type-badge"><?= htmlspecialchars($src['type'] ?? 'rss') ?></span>
                    <span><?= htmlspecialchars($src['name'] ?? $src['url']) ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
        <p style="color: #6c757d; text-align: center; padding: 40px;">No clients configured yet. Click "Add New Client" above to get started.</p>
    <?php endif; ?>

</div><!-- /content-card -->
</div><!-- /page-bg -->

<footer>Sentiment Vision &middot; Client Manager</footer>

<script>
function toggleAccordion(id) {
    document.getElementById(id).classList.toggle('open');
}

function addSource() {
    const container = document.getElementById('sources-container');
    const row = document.createElement('div');
    row.className = 'source-row';
    row.innerHTML = `
        <input type="url" name="source_url[]" placeholder="https://example.com/rss/feed">
        <input type="text" name="source_name[]" placeholder="Label (optional)">
        <select name="source_type[]">
            <option value="rss">RSS</option>
            <option value="html">HTML</option>
            <option value="search">Search</option>
        </select>
        <button type="button" onclick="removeSource(this)" title="Remove">&times;</button>
    `;
    container.appendChild(row);
}

function removeSource(btn) {
    const container = document.getElementById('sources-container');
    if (container.children.length > 1) {
        btn.closest('.source-row').remove();
    }
}
</script>

</body>
</html>

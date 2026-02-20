<?php
/**
 * Sentiment Vision - Tag Manager
 * Manage global ESG tags and client-specific tags.
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

// Selected client for client-specific tags section
$selected_client_id = isset($_GET['client_id']) ? (int)$_GET['client_id'] : 0;

// ---------------------------------------------------------------------------
// Handle form submissions
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // Save global or client tag
    if ($action === 'save_global' || $action === 'save_client') {
        $name = trim($_POST['tag_name'] ?? '');
        $tag_type = $_POST['tag_type'] ?? 'esg';
        $keywords_raw = trim($_POST['keywords'] ?? '');
        $color = $_POST['color'] ?? '#6366f1';
        $edit_id = (int)($_POST['edit_id'] ?? 0);

        $keywords = array_values(array_filter(array_map('trim', explode("\n", $keywords_raw))));

        if ($name === '') {
            $message = 'Tag name is required.';
            $message_type = 'error';
        } elseif (empty($keywords)) {
            $message = 'At least one keyword is required.';
            $message_type = 'error';
        } else {
            $scope = ($action === 'save_global') ? 'global' : 'client';
            $client_id_val = ($action === 'save_client') ? (int)$_POST['client_id'] : null;
            $kw_json = json_encode($keywords);

            if ($edit_id > 0) {
                $stmt = $db->prepare("UPDATE tags SET name = ?, tag_type = ?, keywords = ?, color = ? WHERE id = ?");
                $stmt->bind_param('ssssi', $name, $tag_type, $kw_json, $color, $edit_id);
                if ($stmt->execute()) {
                    $message = "Updated tag: $name";
                    $message_type = 'success';
                } else {
                    $message = "Failed to update tag.";
                    $message_type = 'error';
                }
            } else {
                $stmt = $db->prepare("INSERT INTO tags (name, tag_type, scope, client_id, keywords, color) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->bind_param('sssiss', $name, $tag_type, $scope, $client_id_val, $kw_json, $color);
                if ($stmt->execute()) {
                    $message = "Added tag: $name";
                    $message_type = 'success';
                } else {
                    $message = "Failed to add tag. It may already exist.";
                    $message_type = 'error';
                }
            }
        }
    }

    // Delete tag
    if ($action === 'delete') {
        $del_id = (int)$_POST['tag_id'];
        $stmt = $db->prepare("DELETE FROM tags WHERE id = ?");
        $stmt->bind_param('i', $del_id);
        $stmt->execute();
        $message = "Tag deleted.";
        $message_type = 'info';
    }

    // Toggle enabled/disabled
    if ($action === 'toggle') {
        $toggle_id = (int)$_POST['tag_id'];
        $stmt = $db->prepare("UPDATE tags SET enabled = NOT enabled WHERE id = ?");
        $stmt->bind_param('i', $toggle_id);
        $stmt->execute();
        $message = "Tag toggled.";
        $message_type = 'info';
    }

    // Seed default ESG tags
    if ($action === 'seed_defaults') {
        $defaults = [
            // --- Environmental (3 tags) ---
            ['Climate & Emissions', 'esg', '#16a34a', [
                "climate change", "greenhouse gas", "GHG", "carbon emissions", "carbon footprint",
                "carbon neutral", "net zero", "global warming", "decarbonization", "decarbonisation",
                "paris agreement", "climate risk", "climate action", "air emissions", "air quality",
                "air pollution", "climate mitigation", "climate adaptation", "carbon offset",
                "carbon credit", "scope 1", "scope 2", "scope 3"
            ]],
            ['Energy & Resources', 'esg', '#15803d', [
                "renewable energy", "solar energy", "wind energy", "clean energy", "energy efficiency",
                "energy transition", "energy consumption", "alternative energy", "green energy",
                "natural resource", "resource consumption", "energy reduction", "energy management",
                "fossil fuel", "hydroelectric", "geothermal", "battery storage", "electrification",
                "power grid", "energy independence"
            ]],
            ['Environmental Mgmt', 'esg', '#059669', [
                "waste management", "recycling", "water management", "water scarcity", "land management",
                "chemical management", "hazardous waste", "pollution", "deforestation", "biodiversity",
                "sustainable building", "green building", "LEED", "product lifecycle", "circular economy",
                "ocean pollution", "plastic waste", "soil contamination", "environmental remediation",
                "materials management", "environmental impact"
            ]],
            // --- Social (3 tags) ---
            ['Workforce & Labor', 'esg', '#2563eb', [
                "employee wellbeing", "workplace safety", "occupational health", "labor rights",
                "human rights", "fair wage", "living wage", "worker safety", "employee benefits",
                "talent retention", "employee development", "workforce training", "labor dispute",
                "labor union", "collective bargaining", "child labor", "forced labor",
                "working conditions", "employee health", "compensation", "workplace injury"
            ]],
            ['Diversity & Inclusion', 'esg', '#3b82f6', [
                "diversity", "equity", "inclusion", "DEI", "gender equality", "pay gap", "gender pay",
                "racial equity", "equal opportunity", "accessibility", "disability inclusion",
                "LGBTQ", "women in leadership", "minority", "underrepresented", "inclusive workplace",
                "belonging", "cultural diversity", "neurodiversity", "anti-discrimination"
            ]],
            ['Social Impact', 'esg', '#0ea5e9', [
                "community engagement", "stakeholder engagement", "philanthropy", "social good",
                "corporate social responsibility", "CSR", "data privacy", "customer privacy",
                "information security", "cybersecurity", "data breach", "digital inclusion",
                "disaster relief", "risk management", "economic contribution", "social impact",
                "community investment", "human development", "public health", "food security"
            ]],
            // --- Governance (2 tags) ---
            ['Corporate Governance', 'esg', '#9333ea', [
                "board of directors", "board composition", "board diversity", "corporate governance",
                "ESG governance", "governance structure", "executive compensation", "CEO pay",
                "shareholder rights", "proxy vote", "annual meeting", "fiduciary duty",
                "independent director", "board oversight", "governance framework", "audit committee",
                "corporate leadership", "succession planning"
            ]],
            ['Ethics & Compliance', 'esg', '#7c3aed', [
                "business ethics", "anti-corruption", "bribery", "corporate culture", "compliance",
                "whistleblower", "grievance mechanism", "responsible tax", "tax transparency",
                "supply chain", "investor relations", "public policy", "lobbying", "government relations",
                "regulatory compliance", "sanctions", "money laundering", "fraud", "corporate misconduct",
                "code of conduct", "transparency", "accountability"
            ]],
            // --- Other / Cross-cutting (2 tags) ---
            ['Innovation & Digital', 'custom', '#f59e0b', [
                "digital transformation", "innovation", "research and development", "R&D",
                "artificial intelligence", "AI", "machine learning", "automation", "technology",
                "customer satisfaction", "reputation management", "brand management",
                "emerging technology", "disruptive innovation", "patent", "intellectual property"
            ]],
            ['Responsible Finance', 'custom', '#ef4444', [
                "impact investing", "ESG investing", "sustainable finance", "green bond",
                "responsible investment", "long-term value", "market access", "responsible lending",
                "responsible marketing", "responsible pricing", "solvency", "financial management",
                "shareholder activism", "divestment", "fiduciary", "sustainable development goals", "SDG"
            ]],
        ];
        $added = 0;
        $stmt = $db->prepare("INSERT IGNORE INTO tags (name, tag_type, scope, client_id, keywords, color) VALUES (?, ?, 'global', NULL, ?, ?)");
        foreach ($defaults as $d) {
            $kw_json = json_encode($d[3]);
            $stmt->bind_param('ssss', $d[0], $d[1], $kw_json, $d[2]);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                $added++;
            }
        }
        $message = $added > 0 ? "Added $added default ESG tag(s)." : "Default ESG tags already exist.";
        $message_type = $added > 0 ? 'success' : 'info';
    }
}

// ---------------------------------------------------------------------------
// Load tags for display
// ---------------------------------------------------------------------------

// Global tags with usage counts
$global_tags = [];
$res = $db->query("
    SELECT t.*, COUNT(at2.id) AS usage_count
    FROM tags t
    LEFT JOIN article_tags at2 ON t.id = at2.tag_id
    WHERE t.scope = 'global'
    GROUP BY t.id
    ORDER BY t.tag_type, t.name
");
if ($res) {
    while ($row = $res->fetch_assoc()) {
        $row['keywords'] = json_decode($row['keywords'], true) ?: [];
        $global_tags[] = $row;
    }
}

// Client-specific tags (if a client is selected)
$client_tags = [];
if ($selected_client_id > 0) {
    $stmt = $db->prepare("
        SELECT t.*, COUNT(at2.id) AS usage_count
        FROM tags t
        LEFT JOIN article_tags at2 ON t.id = at2.tag_id
        WHERE t.scope = 'client' AND t.client_id = ?
        GROUP BY t.id
        ORDER BY t.name
    ");
    $stmt->bind_param('i', $selected_client_id);
    $stmt->execute();
    $cres = $stmt->get_result();
    while ($row = $cres->fetch_assoc()) {
        $row['keywords'] = json_decode($row['keywords'], true) ?: [];
        $client_tags[] = $row;
    }
}

// Check if editing an existing tag
$edit_tag = null;
if (isset($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $db->prepare("SELECT * FROM tags WHERE id = ?");
    $stmt->bind_param('i', $edit_id);
    $stmt->execute();
    $edit_tag = $stmt->get_result()->fetch_assoc();
    if ($edit_tag) {
        $edit_tag['keywords'] = json_decode($edit_tag['keywords'], true) ?: [];
    }
}

$db->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Tag Manager - Sentiment Vision</title>
<link rel="stylesheet" href="css/style.css">
<style>
    .tag-grid { display: grid; gap: 12px; }
    .tag-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 8px; padding: 16px 20px; }
    .tag-card.disabled { opacity: 0.5; }
    .tag-card:hover { border-color: rgba(229, 131, 37, 0.4); }
    .tag-header { display: flex; justify-content: space-between; align-items: start; margin-bottom: 8px; }
    .tag-name { font-size: 16px; font-weight: 600; color: #043546; display: flex; align-items: center; gap: 8px; }
    .tag-badge { display: inline-block; padding: 2px 10px; border-radius: 20px; font-size: 11px; font-weight: 600; color: #fff; }
    .tag-type-label {
        font-size: 10px; font-weight: 600; text-transform: uppercase; padding: 2px 8px;
        border-radius: 4px; letter-spacing: 0.5px; font-family: 'Archivo', sans-serif;
    }
    .tag-type-esg { background: #D7E5EF; color: #043546; }
    .tag-type-custom { background: #F8E0C8; color: #92400e; }
    .tag-actions { display: flex; gap: 6px; }
    .tag-keywords { display: flex; flex-wrap: wrap; gap: 4px; margin-top: 6px; }
    .tag-kw { display: inline-block; padding: 2px 8px; border-radius: 20px; font-size: 11px; background: #f1f5f9; color: #374151; }
    .tag-meta { font-size: 12px; color: #6c757d; margin-top: 6px; }
</style>
</head>
<body>

<div class="sub-nav-wrap">
<nav class="sub-nav">
    <a href="index.php">Dashboard</a>
    <a href="clients.php">Clients</a>
    <a href="tags.php" class="active">Tags</a>
    <a href="sources.php">Sources</a>
</nav>
</div>

<div class="page-bg">
<div class="content-card">

    <?php if ($message): ?>
        <div class="alert alert-<?= $message_type ?>"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>

    <!-- ================================================================= -->
    <!-- GLOBAL ESG TAGS                                                    -->
    <!-- ================================================================= -->

    <!-- Add / Edit Tag accordion toggle -->
    <div style="margin-bottom: 16px;">
        <button type="button" class="btn btn-primary" onclick="toggleAccordion('tag-form-accordion')">
            <?= ($edit_tag && $edit_tag['scope'] === 'global') ? 'Edit Tag: ' . htmlspecialchars($edit_tag['name']) : '+ Tag' ?> &#9662;
        </button>
    </div>

    <div id="tag-form-accordion" class="accordion-body <?= ($edit_tag && $edit_tag['scope'] === 'global') ? 'open' : '' ?>">
    <div class="card" style="margin-bottom: 20px;">
        <h2><?= ($edit_tag && $edit_tag['scope'] === 'global') ? 'Edit Global Tag: ' . htmlspecialchars($edit_tag['name']) : 'Add Global Tag' ?></h2>
        <p class="hint">Global tags apply to articles across ALL clients. Use these for ESG categories.</p>

        <form method="post" action="tags.php<?= $selected_client_id ? '?client_id=' . $selected_client_id : '' ?>">
            <input type="hidden" name="action" value="save_global">
            <input type="hidden" name="edit_id" value="<?= ($edit_tag && $edit_tag['scope'] === 'global') ? $edit_tag['id'] : 0 ?>">

            <div class="form-row">
                <div class="form-group">
                    <label>Tag Name</label>
                    <input type="text" name="tag_name" required placeholder="e.g. Environment"
                           value="<?= ($edit_tag && $edit_tag['scope'] === 'global') ? htmlspecialchars($edit_tag['name']) : '' ?>">
                </div>
                <div class="form-group" style="flex: 0 0 140px;">
                    <label>Type</label>
                    <select name="tag_type">
                        <option value="esg" <?= ($edit_tag && $edit_tag['tag_type'] === 'esg') ? 'selected' : '' ?>>ESG</option>
                        <option value="custom" <?= ($edit_tag && $edit_tag['tag_type'] === 'custom') ? 'selected' : '' ?>>Custom</option>
                    </select>
                </div>
                <div class="form-group narrow">
                    <label>Color</label>
                    <input type="color" name="color"
                           value="<?= ($edit_tag && $edit_tag['scope'] === 'global') ? htmlspecialchars($edit_tag['color']) : '#6366f1' ?>">
                </div>
            </div>

            <div class="form-group">
                <label>Keywords</label>
                <div class="hint">One per line. An article is tagged if ANY keyword appears in its text.</div>
                <textarea name="keywords" rows="5" placeholder="climate change&#10;carbon emissions&#10;renewable energy&#10;sustainability"
                ><?= ($edit_tag && $edit_tag['scope'] === 'global') ? htmlspecialchars(implode("\n", $edit_tag['keywords'])) : '' ?></textarea>
            </div>

            <div style="display: flex; gap: 10px;">
                <button type="submit" class="btn btn-primary">
                    <?= ($edit_tag && $edit_tag['scope'] === 'global') ? 'Update Tag' : 'Add Global Tag' ?>
                </button>
                <?php if ($edit_tag && $edit_tag['scope'] === 'global'): ?>
                    <a href="tags.php<?= $selected_client_id ? '?client_id=' . $selected_client_id : '' ?>" class="btn btn-outline">Cancel</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
    </div><!-- /accordion -->

    <!-- Global Tags List -->
    <?php if (!empty($global_tags)): ?>
    <h2 style="font-size: 18px; margin-bottom: 12px; color: #043546;">
        Global Tags (<?= count($global_tags) ?>)
    </h2>
    <div class="tag-grid">
        <?php foreach ($global_tags as $t): ?>
        <div class="tag-card <?= $t['enabled'] ? '' : 'disabled' ?>">
            <div class="tag-header">
                <div class="tag-name">
                    <span class="tag-badge" style="background: <?= htmlspecialchars($t['color']) ?>;">
                        <?= htmlspecialchars($t['name']) ?>
                    </span>
                    <span class="tag-type-label tag-type-<?= $t['tag_type'] ?>">
                        <?= strtoupper($t['tag_type']) ?>
                    </span>
                    <?php if (!$t['enabled']): ?>
                        <span style="font-size: 11px; color: #6c757d;">(disabled)</span>
                    <?php endif; ?>
                </div>
                <div class="tag-actions">
                    <a href="tags.php?edit=<?= $t['id'] ?><?= $selected_client_id ? '&client_id=' . $selected_client_id : '' ?>"
                       class="btn btn-sm btn-outline">Edit</a>
                    <form method="post" style="display:inline;">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="tag_id" value="<?= $t['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-outline">
                            <?= $t['enabled'] ? 'Disable' : 'Enable' ?>
                        </button>
                    </form>
                    <form method="post" style="display:inline;"
                          onsubmit="return confirm('Delete tag: <?= htmlspecialchars(addslashes($t['name'])) ?>?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="tag_id" value="<?= $t['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                    </form>
                </div>
            </div>
            <div class="tag-keywords">
                <?php foreach ($t['keywords'] as $kw): ?>
                    <span class="tag-kw"><?= htmlspecialchars($kw) ?></span>
                <?php endforeach; ?>
            </div>
            <div class="tag-meta">
                <?= (int)$t['usage_count'] ?> article(s) tagged
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Quick Setup -->
    <?php if (empty($global_tags)): ?>
    <div class="card" style="text-align: center;">
        <h3>Quick Setup</h3>
        <p class="hint">Add the standard Environment, Social, and Governance tags with common keywords.</p>
        <form method="post">
            <input type="hidden" name="action" value="seed_defaults">
            <button type="submit" class="btn btn-primary">Add Default ESG Tags</button>
        </form>
    </div>
    <?php endif; ?>

    <hr class="section-divider">

    <!-- ================================================================= -->
    <!-- CLIENT-SPECIFIC TAGS                                               -->
    <!-- ================================================================= -->

    <div class="card">
        <h2>Client-Specific Tags</h2>
        <p class="hint">These tags apply only to the selected client. Use for team members, projects, etc.</p>

        <form method="get" action="tags.php">
            <div class="form-group">
                <label>Select Client</label>
                <select name="client_id" onchange="this.form.submit()">
                    <option value="">-- Choose a client --</option>
                    <?php foreach ($all_clients as $c): ?>
                        <option value="<?= $c['id'] ?>" <?= $selected_client_id == $c['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($c['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </form>

        <?php if ($selected_client_id > 0): ?>
        <hr style="border: none; border-top: 1px solid #e5e7eb; margin: 16px 0;">

        <!-- Add client tag form -->
        <h3><?= ($edit_tag && $edit_tag['scope'] === 'client') ? 'Edit Tag: ' . htmlspecialchars($edit_tag['name']) : 'Add Tag' ?></h3>
        <form method="post" action="tags.php?client_id=<?= $selected_client_id ?>" style="margin-top: 12px;">
            <input type="hidden" name="action" value="save_client">
            <input type="hidden" name="client_id" value="<?= $selected_client_id ?>">
            <input type="hidden" name="edit_id" value="<?= ($edit_tag && $edit_tag['scope'] === 'client') ? $edit_tag['id'] : 0 ?>">

            <div class="form-row">
                <div class="form-group">
                    <label>Tag Name</label>
                    <input type="text" name="tag_name" required placeholder="e.g. John Smith, Project Phoenix"
                           value="<?= ($edit_tag && $edit_tag['scope'] === 'client') ? htmlspecialchars($edit_tag['name']) : '' ?>">
                </div>
                <div class="form-group" style="flex: 0 0 140px;">
                    <label>Type</label>
                    <select name="tag_type">
                        <option value="custom" <?= (!$edit_tag || $edit_tag['tag_type'] === 'custom') ? 'selected' : '' ?>>Custom</option>
                        <option value="esg" <?= ($edit_tag && $edit_tag['tag_type'] === 'esg') ? 'selected' : '' ?>>ESG</option>
                    </select>
                </div>
                <div class="form-group narrow">
                    <label>Color</label>
                    <input type="color" name="color"
                           value="<?= ($edit_tag && $edit_tag['scope'] === 'client') ? htmlspecialchars($edit_tag['color']) : '#f59e0b' ?>">
                </div>
            </div>

            <div class="form-group">
                <label>Keywords</label>
                <div class="hint">One per line. An article is tagged if ANY keyword appears in its text.</div>
                <textarea name="keywords" rows="4" placeholder="John Smith&#10;J. Smith&#10;Smith, John"
                ><?= ($edit_tag && $edit_tag['scope'] === 'client') ? htmlspecialchars(implode("\n", $edit_tag['keywords'])) : '' ?></textarea>
            </div>

            <div style="display: flex; gap: 10px;">
                <button type="submit" class="btn btn-primary">
                    <?= ($edit_tag && $edit_tag['scope'] === 'client') ? 'Update Tag' : 'Add Client Tag' ?>
                </button>
                <?php if ($edit_tag && $edit_tag['scope'] === 'client'): ?>
                    <a href="tags.php?client_id=<?= $selected_client_id ?>" class="btn btn-outline">Cancel</a>
                <?php endif; ?>
            </div>
        </form>

        <!-- Client Tags List -->
        <?php if (!empty($client_tags)): ?>
        <h3 style="margin-top: 20px;">Tags for this client (<?= count($client_tags) ?>)</h3>
        <div class="tag-grid" style="margin-top: 12px;">
            <?php foreach ($client_tags as $t): ?>
            <div class="tag-card <?= $t['enabled'] ? '' : 'disabled' ?>">
                <div class="tag-header">
                    <div class="tag-name">
                        <span class="tag-badge" style="background: <?= htmlspecialchars($t['color']) ?>;">
                            <?= htmlspecialchars($t['name']) ?>
                        </span>
                        <span class="tag-type-label tag-type-<?= $t['tag_type'] ?>">
                            <?= strtoupper($t['tag_type']) ?>
                        </span>
                        <?php if (!$t['enabled']): ?>
                            <span style="font-size: 11px; color: #6c757d;">(disabled)</span>
                        <?php endif; ?>
                    </div>
                    <div class="tag-actions">
                        <a href="tags.php?edit=<?= $t['id'] ?>&client_id=<?= $selected_client_id ?>"
                           class="btn btn-sm btn-outline">Edit</a>
                        <form method="post" action="tags.php?client_id=<?= $selected_client_id ?>" style="display:inline;">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="tag_id" value="<?= $t['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-outline">
                                <?= $t['enabled'] ? 'Disable' : 'Enable' ?>
                            </button>
                        </form>
                        <form method="post" action="tags.php?client_id=<?= $selected_client_id ?>" style="display:inline;"
                              onsubmit="return confirm('Delete tag: <?= htmlspecialchars(addslashes($t['name'])) ?>?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="tag_id" value="<?= $t['id'] ?>">
                            <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                        </form>
                    </div>
                </div>
                <div class="tag-keywords">
                    <?php foreach ($t['keywords'] as $kw): ?>
                        <span class="tag-kw"><?= htmlspecialchars($kw) ?></span>
                    <?php endforeach; ?>
                </div>
                <div class="tag-meta">
                    <?= (int)$t['usage_count'] ?> article(s) tagged
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php elseif ($selected_client_id > 0): ?>
            <p style="color: #6c757d; text-align: center; padding: 20px; margin-top: 12px;">
                No client-specific tags yet. Add one above.
            </p>
        <?php endif; ?>

        <?php else: ?>
            <p style="color: #6c757d; text-align: center; padding: 20px;">
                Select a client above to manage their specific tags.
            </p>
        <?php endif; ?>
    </div>

</div><!-- /content-card -->
</div><!-- /page-bg -->

<footer>Sentiment Vision &middot; Tag Manager</footer>

<script>
function toggleAccordion(id) {
    document.getElementById(id).classList.toggle('open');
}
</script>

</body>
</html>

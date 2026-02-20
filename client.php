<?php
/**
 * Sentiment Vision - Client Detail Page
 * Shows sentiment gauges + article list for a single client.
 */

require_once __DIR__ . '/includes/db_connect.php';

// ---------------------------------------------------------------------------
// Load client
// ---------------------------------------------------------------------------
$client_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$client_id) {
    header('Location: index.php');
    exit;
}

$stmt = $db->prepare("SELECT * FROM clients WHERE id = ?");
$stmt->bind_param('i', $client_id);
$stmt->execute();
$client = $stmt->get_result()->fetch_assoc();
if (!$client) {
    header('Location: index.php');
    exit;
}

$industries = json_decode($client['industries'], true) ?: [];
$competitors = json_decode($client['competitors'], true) ?: [];

// ---------------------------------------------------------------------------
// Media tier toggle filter
// ---------------------------------------------------------------------------
$active_tiers = [1, 2, 3, 4]; // default: all
if (isset($_GET['tiers']) && $_GET['tiers'] !== '') {
    $raw_tiers = array_map('intval', explode(',', $_GET['tiers']));
    $raw_tiers = array_filter($raw_tiers, fn($t) => $t >= 1 && $t <= 4);
    if (!empty($raw_tiers)) {
        $active_tiers = array_values(array_unique($raw_tiers));
    }
}
$tier_in = implode(',', $active_tiers);
$tier_cond = " AND a.media_tier IN ($tier_in)";

// ---------------------------------------------------------------------------
// Sentiment data (weighted by media tier: T1=3x, T2=2x, T3=1x, T4=0.5x)
// ---------------------------------------------------------------------------
$tier_weight_sql = "CASE a.media_tier WHEN 1 THEN 3.0 WHEN 2 THEN 2.0 WHEN 3 THEN 1.0 WHEN 4 THEN 0.5 ELSE 1.0 END";

// 1) Company Sentiment — weighted avg of articles that mention the client name
$company_stmt = $db->prepare("
    SELECT SUM(sentiment_score * $tier_weight_sql) / SUM($tier_weight_sql) AS avg_score,
           COUNT(*) AS total,
           SUM(CASE WHEN sentiment_score IS NOT NULL THEN 1 ELSE 0 END) AS scored,
           SUM(CASE WHEN sentiment_label = 'positive' THEN 1 ELSE 0 END) AS positive,
           SUM(CASE WHEN sentiment_label = 'negative' THEN 1 ELSE 0 END) AS negative,
           SUM(CASE WHEN sentiment_label = 'neutral' THEN 1 ELSE 0 END) AS neutral
    FROM articles a
    WHERE a.client_id = ?
      AND a.sentiment_score IS NOT NULL
      AND (a.title LIKE ? OR a.content_text LIKE ?)
      AND a.media_tier IN ($tier_in)
");
$company_like = '%' . $client['name'] . '%';
$company_stmt->bind_param('iss', $client_id, $company_like, $company_like);
$company_stmt->execute();
$company_sent = $company_stmt->get_result()->fetch_assoc();

// 2) Industry Sentiment — weighted avg of articles matching any industry keyword
$industry_sent = ['avg_score' => null, 'total' => 0, 'scored' => 0, 'positive' => 0, 'negative' => 0, 'neutral' => 0];
if (!empty($industries)) {
    $industry_conditions = [];
    $industry_params = [$client_id];
    $industry_types = 'i';
    foreach ($industries as $ind) {
        $industry_conditions[] = "a.title LIKE ? OR a.content_text LIKE ?";
        $industry_params[] = '%' . $ind . '%';
        $industry_params[] = '%' . $ind . '%';
        $industry_types .= 'ss';
    }
    $industry_where = '(' . implode(' OR ', $industry_conditions) . ')';
    $industry_sql = "
        SELECT SUM(sentiment_score * $tier_weight_sql) / SUM($tier_weight_sql) AS avg_score,
               COUNT(*) AS total,
               SUM(CASE WHEN sentiment_score IS NOT NULL THEN 1 ELSE 0 END) AS scored,
               SUM(CASE WHEN sentiment_label = 'positive' THEN 1 ELSE 0 END) AS positive,
               SUM(CASE WHEN sentiment_label = 'negative' THEN 1 ELSE 0 END) AS negative,
               SUM(CASE WHEN sentiment_label = 'neutral' THEN 1 ELSE 0 END) AS neutral
        FROM articles a
        WHERE a.client_id = ? AND a.sentiment_score IS NOT NULL AND $industry_where
              AND a.media_tier IN ($tier_in)
    ";
    $ind_stmt = $db->prepare($industry_sql);
    $ind_stmt->bind_param($industry_types, ...$industry_params);
    $ind_stmt->execute();
    $industry_sent = $ind_stmt->get_result()->fetch_assoc();
}

// 3) Competitor Sentiment — weighted avg of articles mentioning any competitor
$competitor_sent = ['avg_score' => null, 'total' => 0, 'scored' => 0, 'positive' => 0, 'negative' => 0, 'neutral' => 0];
if (!empty($competitors)) {
    $comp_conditions = [];
    $comp_params = [$client_id];
    $comp_types = 'i';
    foreach ($competitors as $comp) {
        $comp_conditions[] = "a.title LIKE ? OR a.content_text LIKE ?";
        $comp_params[] = '%' . $comp . '%';
        $comp_params[] = '%' . $comp . '%';
        $comp_types .= 'ss';
    }
    $comp_where = '(' . implode(' OR ', $comp_conditions) . ')';
    $comp_sql = "
        SELECT SUM(sentiment_score * $tier_weight_sql) / SUM($tier_weight_sql) AS avg_score,
               COUNT(*) AS total,
               SUM(CASE WHEN sentiment_score IS NOT NULL THEN 1 ELSE 0 END) AS scored,
               SUM(CASE WHEN sentiment_label = 'positive' THEN 1 ELSE 0 END) AS positive,
               SUM(CASE WHEN sentiment_label = 'negative' THEN 1 ELSE 0 END) AS negative,
               SUM(CASE WHEN sentiment_label = 'neutral' THEN 1 ELSE 0 END) AS neutral
        FROM articles a
        WHERE a.client_id = ? AND a.sentiment_score IS NOT NULL AND $comp_where
              AND a.media_tier IN ($tier_in)
    ";
    $comp_stmt = $db->prepare($comp_sql);
    $comp_stmt->bind_param($comp_types, ...$comp_params);
    $comp_stmt->execute();
    $competitor_sent = $comp_stmt->get_result()->fetch_assoc();
}

// ---------------------------------------------------------------------------
// Filter + pagination parameters
// ---------------------------------------------------------------------------
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';
if (!in_array($filter, ['all', 'positive', 'neutral', 'negative'], true)) {
    $filter = 'all';
}

$per_page = 20;
$client_page = max(1, isset($_GET['cp']) ? (int)$_GET['cp'] : 1);
$client_offset = ($client_page - 1) * $per_page;
$industry_page = max(1, isset($_GET['ip']) ? (int)$_GET['ip'] : 1);
$industry_offset = ($industry_page - 1) * $per_page;

// Sentiment filter condition (shared by both groups)
$sentiment_cond = '';
$sentiment_param = '';
if ($filter !== 'all') {
    $sentiment_cond = ' AND a.sentiment_label = ?';
    $sentiment_param = $filter;
}

// --- Group 1: Client Articles (mention client name) ---
$g1_where = "a.client_id = ? AND (a.title LIKE ? OR COALESCE(a.content_text, '') LIKE ?)" . $tier_cond . $sentiment_cond;

$g1_count_sql = "SELECT COUNT(*) AS cnt FROM articles a WHERE $g1_where";
$g1_count_stmt = $db->prepare($g1_count_sql);
if ($filter !== 'all') {
    $g1_count_stmt->bind_param('isss', $client_id, $company_like, $company_like, $sentiment_param);
} else {
    $g1_count_stmt->bind_param('iss', $client_id, $company_like, $company_like);
}
$g1_count_stmt->execute();
$g1_total = $g1_count_stmt->get_result()->fetch_assoc()['cnt'];
$g1_total_pages = max(1, ceil($g1_total / $per_page));

$g1_sql = "SELECT a.*, s.name AS source_name FROM articles a LEFT JOIN sources s ON a.source_id = s.id WHERE $g1_where ORDER BY a.fetched_at DESC LIMIT ? OFFSET ?";
$g1_stmt = $db->prepare($g1_sql);
if ($filter !== 'all') {
    $g1_stmt->bind_param('isssii', $client_id, $company_like, $company_like, $sentiment_param, $per_page, $client_offset);
} else {
    $g1_stmt->bind_param('issii', $client_id, $company_like, $company_like, $per_page, $client_offset);
}
$g1_stmt->execute();
$g1_articles = $g1_stmt->get_result();

// --- Group 2: Industry/Competitor Articles (do NOT mention client name) ---
$g2_where = "a.client_id = ? AND NOT (a.title LIKE ? OR COALESCE(a.content_text, '') LIKE ?)" . $tier_cond . $sentiment_cond;

$g2_count_sql = "SELECT COUNT(*) AS cnt FROM articles a WHERE $g2_where";
$g2_count_stmt = $db->prepare($g2_count_sql);
if ($filter !== 'all') {
    $g2_count_stmt->bind_param('isss', $client_id, $company_like, $company_like, $sentiment_param);
} else {
    $g2_count_stmt->bind_param('iss', $client_id, $company_like, $company_like);
}
$g2_count_stmt->execute();
$g2_total = $g2_count_stmt->get_result()->fetch_assoc()['cnt'];
$g2_total_pages = max(1, ceil($g2_total / $per_page));

$g2_sql = "SELECT a.*, s.name AS source_name FROM articles a LEFT JOIN sources s ON a.source_id = s.id WHERE $g2_where ORDER BY a.fetched_at DESC LIMIT ? OFFSET ?";
$g2_stmt = $db->prepare($g2_sql);
if ($filter !== 'all') {
    $g2_stmt->bind_param('isssii', $client_id, $company_like, $company_like, $sentiment_param, $per_page, $industry_offset);
} else {
    $g2_stmt->bind_param('issii', $client_id, $company_like, $company_like, $per_page, $industry_offset);
}
$g2_stmt->execute();
$g2_articles = $g2_stmt->get_result();

// ---------------------------------------------------------------------------
// Helper: convert score (-1 to 1) to gauge percentage (0 to 100) and color
// ---------------------------------------------------------------------------
function gauge_data($score) {
    if ($score === null) {
        return ['pct' => 50, 'color' => '#94a3b8', 'label' => 'No data', 'rotation' => 0];
    }
    // score is -1 to 1, map to 0-100
    $pct = round(($score + 1) * 50, 1);
    $pct = max(0, min(100, $pct));
    // rotation: -90 (leftmost, score=-1) to 90 (rightmost, score=1)
    $rotation = round($score * 90, 1);

    if ($score >= 0.2) {
        $color = '#16a34a'; $label = 'Positive';
    } elseif ($score >= -0.2) {
        $color = '#eab308'; $label = 'Neutral';
    } else {
        $color = '#dc2626'; $label = 'Negative';
    }
    return ['pct' => $pct, 'color' => $color, 'label' => $label, 'rotation' => $rotation, 'score' => round($score, 2)];
}

function client_url($client_id, $overrides = []) {
    $params = ['id' => $client_id];
    foreach (['filter', 'cp', 'ip', 'tiers'] as $key) {
        if (isset($_GET[$key])) {
            $params[$key] = $_GET[$key];
        }
    }
    foreach ($overrides as $k => $v) {
        if ($v === null) {
            unset($params[$k]);
        } else {
            $params[$k] = $v;
        }
    }
    // Remove defaults to keep URLs clean
    if (isset($params['filter']) && $params['filter'] === 'all') unset($params['filter']);
    if (isset($params['cp']) && $params['cp'] <= 1) unset($params['cp']);
    if (isset($params['ip']) && $params['ip'] <= 1) unset($params['ip']);
    // Omit tiers param when all 4 are active (default state)
    if (isset($params['tiers'])) {
        $t = array_map('intval', explode(',', $params['tiers']));
        sort($t);
        if ($t === [1, 2, 3, 4]) unset($params['tiers']);
    }
    return '?' . http_build_query($params);
}

$g_company    = gauge_data($company_sent['avg_score']);
$g_industry   = gauge_data($industry_sent['avg_score']);
$g_competitor = gauge_data($competitor_sent['avg_score']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($client['name']) ?> - Sentiment Vision</title>
<link rel="stylesheet" href="css/style.css">
<style>
    .client-header-section { margin-bottom: 24px; }
    .client-header-section h2 { font-size: 24px; font-weight: 700; color: #043546; margin-bottom: 6px; }
    .client-meta { display: flex; gap: 16px; flex-wrap: wrap; align-items: center; }

    .gauges { display: flex; gap: 20px; flex-wrap: wrap; margin-bottom: 28px; }
    .gauge-card {
        background: #fff; border-radius: 8px; border-top: 3px solid #043546;
        padding: 24px 20px 16px; flex: 1; min-width: 260px; text-align: center;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }
    .gauge-card:nth-child(2) { border-top-color: #00434F; }
    .gauge-card:nth-child(3) { border-top-color: #E58325; }
    .gauge-card h3 {
        font-size: 10px; font-weight: 600; color: #6c757d; margin-bottom: 16px;
        text-transform: uppercase; letter-spacing: 1.5px; font-family: 'Archivo', sans-serif;
    }
    .gauge-wrap { width: 200px; height: 110px; margin: 0 auto 12px; position: relative; overflow: hidden; }
    .gauge-bg { fill: none; stroke: #e5e7eb; stroke-width: 18; stroke-linecap: round; }
    .gauge-fill { fill: none; stroke-width: 18; stroke-linecap: round; transition: stroke-dashoffset 1s ease, stroke 0.5s ease; }
    .gauge-needle { transition: transform 1s ease; transform-origin: 100px 100px; }
    .gauge-score { font-size: 28px; font-weight: 700; margin-top: -8px; font-family: 'Archivo', sans-serif; }
    .gauge-label { font-size: 13px; font-weight: 600; margin-top: 2px; }
    .gauge-detail { font-size: 12px; color: #6c757d; margin-top: 6px; }
    .gauge-breakdown { display: flex; justify-content: center; gap: 14px; margin-top: 10px; font-size: 12px; }
    .gauge-breakdown span { display: flex; align-items: center; gap: 4px; }
    .dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
    .dot-green { background: #16a34a; }
    .dot-yellow { background: #eab308; }
    .dot-red { background: #dc2626; }

    .table-wrap { background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); overflow-x: auto; margin-bottom: 20px; }
    table { width: 100%; border-collapse: collapse; font-size: 14px; }
    th {
        background: #f9fafb; text-align: left; padding: 10px 14px;
        font-weight: 600; color: #043546; border-bottom: 1px solid #e5e7eb;
        white-space: nowrap; font-family: 'Archivo', sans-serif; font-size: 12px;
        text-transform: uppercase; letter-spacing: 0.5px;
    }
    td { padding: 10px 14px; border-bottom: 1px solid #f1f5f9; vertical-align: top; }
    tr:hover td { background: #f9fafb; }
    .truncate { max-width: 400px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

    .pagination { display: flex; justify-content: center; gap: 8px; margin: 20px 0; }
    .pagination a, .pagination span {
        padding: 6px 12px; border-radius: 4px; border: 1px solid #e5e7eb; font-size: 14px;
    }
    .pagination a:hover { background: rgba(229, 131, 37, 0.08); border-color: #E58325; text-decoration: none; }
    .pagination span { background: #E58325; color: #fff; border-color: #E58325; }

    .filter-tabs { display: flex; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; }
    .filter-tabs a {
        padding: 7px 16px; border-radius: 4px; font-size: 13px; font-weight: 500;
        border: 1px solid #e5e7eb; background: #fff; color: #6c757d;
    }
    .filter-tabs a:hover { background: rgba(229, 131, 37, 0.05); color: #E58325; text-decoration: none; }
    .filter-tabs a.active { background: #E58325; color: #fff; border-color: #E58325; }
    .group-header { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 12px; margin-top: 28px; }
    .group-header:first-of-type { margin-top: 0; }
    .group-header h3 { font-size: 16px; font-weight: 600; color: #043546; }
    .group-header .count { font-size: 13px; color: #6c757d; font-weight: 400; }
    .section-title { font-size: 16px; font-weight: 600; color: #043546; margin-bottom: 12px; }

    .tier-toggles { display: flex; align-items: center; gap: 8px; margin-bottom: 20px; flex-wrap: wrap; }
    .tier-label { font-size: 13px; font-weight: 600; color: #6c757d; margin-right: 4px; }
    .tier-btn {
        padding: 6px 14px; border-radius: 4px; font-size: 12px; font-weight: 600;
        border: 2px solid; cursor: pointer; transition: all 0.15s ease;
        background: #fff; line-height: 1;
    }
    .tier-btn:hover { opacity: 0.85; }
    .tier-btn.tier-1 { border-color: #043546; color: #043546; }
    .tier-btn.tier-1.active { background: #043546; color: #fff; }
    .tier-btn.tier-2 { border-color: #00434F; color: #00434F; }
    .tier-btn.tier-2.active { background: #00434F; color: #fff; }
    .tier-btn.tier-3 { border-color: #415649; color: #415649; }
    .tier-btn.tier-3.active { background: #415649; color: #fff; }
    .tier-btn.tier-4 { border-color: #E58325; color: #E58325; }
    .tier-btn.tier-4.active { background: #E58325; color: #fff; }
</style>
</head>
<body>

<div class="sub-nav-wrap">
<nav class="sub-nav">
    <a href="index.php">Dashboard</a>
    <a href="clients.php">Clients</a>
    <a href="tags.php">Tags</a>
    <a href="sources.php">Sources</a>
</nav>
</div>

<div class="page-bg">
<div class="content-card wide">

    <!-- Client Info -->
    <div class="client-header-section">
        <h2><?= htmlspecialchars($client['name']) ?></h2>
        <div class="client-meta">
            <?php foreach ($industries as $ind): ?>
                <span class="tag tag-blue"><?= htmlspecialchars($ind) ?></span>
            <?php endforeach; ?>
            <?php foreach ($competitors as $comp): ?>
                <span class="tag tag-amber"><?= htmlspecialchars($comp) ?></span>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- 3 Sentiment Gauges -->
    <div class="gauges">
        <?php
        $gauge_configs = [
            ['title' => 'Company Sentiment',    'data' => $g_company,    'stats' => $company_sent],
            ['title' => 'Industry Sentiment',   'data' => $g_industry,   'stats' => $industry_sent],
            ['title' => 'Competitor Sentiment',  'data' => $g_competitor, 'stats' => $competitor_sent],
        ];
        foreach ($gauge_configs as $gi => $gc):
            $g = $gc['data'];
            $s = $gc['stats'];
            // Arc length for the half-circle: radius=82, half-circle = PI * 82 ≈ 257.6
            $arc_len = 257.6;
            $fill_len = ($g['pct'] / 100) * $arc_len;
        ?>
        <div class="gauge-card">
            <h3><?= $gc['title'] ?></h3>
            <div class="gauge-wrap">
                <svg viewBox="0 0 200 110" width="200" height="110">
                    <!-- Background arc -->
                    <path d="M 18 100 A 82 82 0 0 1 182 100"
                          class="gauge-bg" />
                    <!-- Colored fill arc -->
                    <path d="M 18 100 A 82 82 0 0 1 182 100"
                          class="gauge-fill"
                          stroke="<?= $g['color'] ?>"
                          stroke-dasharray="<?= $arc_len ?>"
                          stroke-dashoffset="<?= $arc_len - $fill_len ?>" />
                    <!-- Needle -->
                    <g class="gauge-needle" style="transform: rotate(<?= $g['rotation'] ?>deg)">
                        <line x1="100" y1="100" x2="100" y2="28" stroke="#043546" stroke-width="2.5" stroke-linecap="round" />
                        <circle cx="100" cy="100" r="5" fill="#043546" />
                    </g>
                    <!-- Scale labels -->
                    <text x="10" y="108" font-size="10" fill="#6c757d" text-anchor="start">-1</text>
                    <text x="100" y="16" font-size="10" fill="#6c757d" text-anchor="middle">0</text>
                    <text x="190" y="108" font-size="10" fill="#6c757d" text-anchor="end">+1</text>
                </svg>
            </div>
            <div class="gauge-score" style="color: <?= $g['color'] ?>">
                <?= isset($g['score']) ? $g['score'] : '—' ?>
            </div>
            <div class="gauge-label" style="color: <?= $g['color'] ?>">
                <?= $g['label'] ?>
            </div>
            <div class="gauge-detail">
                <?= (int)$s['scored'] ?> scored of <?= (int)$s['total'] ?> articles
            </div>
            <?php if ((int)$s['scored'] > 0): ?>
            <div class="gauge-breakdown">
                <span><span class="dot dot-green"></span> <?= (int)$s['positive'] ?></span>
                <span><span class="dot dot-yellow"></span> <?= (int)$s['neutral'] ?></span>
                <span><span class="dot dot-red"></span> <?= (int)$s['negative'] ?></span>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Media Tier Toggles -->
    <div class="tier-toggles">
        <span class="tier-label">Media Tier:</span>
        <button class="tier-btn tier-1<?= in_array(1, $active_tiers) ? ' active' : '' ?>" data-tier="1">T1 National</button>
        <button class="tier-btn tier-2<?= in_array(2, $active_tiers) ? ' active' : '' ?>" data-tier="2">T2 Business</button>
        <button class="tier-btn tier-3<?= in_array(3, $active_tiers) ? ' active' : '' ?>" data-tier="3">T3 Industry</button>
        <button class="tier-btn tier-4<?= in_array(4, $active_tiers) ? ' active' : '' ?>" data-tier="4">T4 Vendor</button>
    </div>

    <!-- Sentiment Filter Tabs -->
    <div class="filter-tabs">
        <a href="<?= client_url($client_id, ['filter' => null, 'cp' => null, 'ip' => null]) ?>" class="<?= $filter === 'all' ? 'active' : '' ?>">All Articles</a>
        <a href="<?= client_url($client_id, ['filter' => 'positive', 'cp' => null, 'ip' => null]) ?>" class="<?= $filter === 'positive' ? 'active' : '' ?>">Positive</a>
        <a href="<?= client_url($client_id, ['filter' => 'neutral', 'cp' => null, 'ip' => null]) ?>" class="<?= $filter === 'neutral' ? 'active' : '' ?>">Neutral</a>
        <a href="<?= client_url($client_id, ['filter' => 'negative', 'cp' => null, 'ip' => null]) ?>" class="<?= $filter === 'negative' ? 'active' : '' ?>">Negative</a>
    </div>

    <!-- Group 1: Client Articles -->
    <div class="group-header">
        <h3>Client Articles <span class="count">(<?= $g1_total ?>)</span></h3>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Source</th>
                    <th>Tags</th>
                    <th>Words</th>
                    <th>Sentiment</th>
                    <th>Published</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($g1_articles->num_rows === 0): ?>
                    <tr><td colspan="7" style="text-align:center; color:#6c757d; padding:30px;">
                        No client articles<?= $filter !== 'all' ? ' with ' . htmlspecialchars($filter) . ' sentiment' : '' ?>.
                    </td></tr>
                <?php else: ?>
                    <?php while ($a = $g1_articles->fetch_assoc()): ?>
                    <tr>
                        <td class="truncate">
                            <a href="index.php?view=<?= $a['id'] ?>"><?= htmlspecialchars($a['title'] ?: 'Untitled') ?></a>
                        </td>
                        <td style="font-size:12px; color:#64748b;"><?= htmlspecialchars($a['source_name'] ?? 'N/A') ?></td>
                        <td>
                            <?php
                                $esg = json_decode($a['esg_tags'] ?? '', true) ?: [];
                                $cust = json_decode($a['tags'] ?? '', true) ?: [];
                                foreach ($esg as $tag) {
                                    echo '<span class="badge" style="background:#dbeafe;color:#1e40af;font-size:10px;margin:1px;">' . htmlspecialchars($tag) . '</span>';
                                }
                                foreach ($cust as $tag) {
                                    echo '<span class="badge" style="background:#f3e8ff;color:#6b21a8;font-size:10px;margin:1px;">' . htmlspecialchars($tag) . '</span>';
                                }
                                if (empty($esg) && empty($cust)) {
                                    echo '<span style="color:#cbd5e1;">—</span>';
                                }
                            ?>
                        </td>
                        <td><?= (int)$a['word_count'] ?></td>
                        <td>
                            <?php if ($a['sentiment_score'] !== null): ?>
                                <?php
                                    $sc = (float)$a['sentiment_score'];
                                    if ($sc >= 0.2) { $bc = 'badge-green'; }
                                    elseif ($sc >= -0.2) { $bc = 'badge-yellow'; }
                                    else { $bc = 'badge-red'; }
                                ?>
                                <span class="badge <?= $bc ?>"><?= round($sc, 2) ?></span>
                            <?php else: ?>
                                <span style="color:#cbd5e1;">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12px; white-space:nowrap;"><?= htmlspecialchars($a['published_date'] ?? '-') ?></td>
                        <td>
                            <?php if ($a['content_text']): ?>
                                <span class="badge badge-green">Content</span>
                            <?php else: ?>
                                <span class="badge badge-gray">Meta only</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($g1_total_pages > 1): ?>
    <div class="pagination">
        <?php if ($client_page > 1): ?><a href="<?= client_url($client_id, ['cp' => $client_page - 1]) ?>">&laquo; Prev</a><?php endif; ?>
        <?php for ($p = max(1, $client_page - 3); $p <= min($g1_total_pages, $client_page + 3); $p++): ?>
            <?php if ($p == $client_page): ?>
                <span><?= $p ?></span>
            <?php else: ?>
                <a href="<?= client_url($client_id, ['cp' => $p]) ?>"><?= $p ?></a>
            <?php endif; ?>
        <?php endfor; ?>
        <?php if ($client_page < $g1_total_pages): ?><a href="<?= client_url($client_id, ['cp' => $client_page + 1]) ?>">Next &raquo;</a><?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Group 2: Industry / Competitor Articles -->
    <div class="group-header" style="margin-top: 36px;">
        <h3>Industry / Competitor Articles <span class="count">(<?= $g2_total ?>)</span></h3>
    </div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Title</th>
                    <th>Source</th>
                    <th>Tags</th>
                    <th>Words</th>
                    <th>Sentiment</th>
                    <th>Published</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($g2_articles->num_rows === 0): ?>
                    <tr><td colspan="7" style="text-align:center; color:#6c757d; padding:30px;">
                        No industry/competitor articles<?= $filter !== 'all' ? ' with ' . htmlspecialchars($filter) . ' sentiment' : '' ?>.
                    </td></tr>
                <?php else: ?>
                    <?php while ($a = $g2_articles->fetch_assoc()): ?>
                    <tr>
                        <td class="truncate">
                            <a href="index.php?view=<?= $a['id'] ?>"><?= htmlspecialchars($a['title'] ?: 'Untitled') ?></a>
                        </td>
                        <td style="font-size:12px; color:#64748b;"><?= htmlspecialchars($a['source_name'] ?? 'N/A') ?></td>
                        <td>
                            <?php
                                $esg = json_decode($a['esg_tags'] ?? '', true) ?: [];
                                $cust = json_decode($a['tags'] ?? '', true) ?: [];
                                foreach ($esg as $tag) {
                                    echo '<span class="badge" style="background:#dbeafe;color:#1e40af;font-size:10px;margin:1px;">' . htmlspecialchars($tag) . '</span>';
                                }
                                foreach ($cust as $tag) {
                                    echo '<span class="badge" style="background:#f3e8ff;color:#6b21a8;font-size:10px;margin:1px;">' . htmlspecialchars($tag) . '</span>';
                                }
                                if (empty($esg) && empty($cust)) {
                                    echo '<span style="color:#cbd5e1;">—</span>';
                                }
                            ?>
                        </td>
                        <td><?= (int)$a['word_count'] ?></td>
                        <td>
                            <?php if ($a['sentiment_score'] !== null): ?>
                                <?php
                                    $sc = (float)$a['sentiment_score'];
                                    if ($sc >= 0.2) { $bc = 'badge-green'; }
                                    elseif ($sc >= -0.2) { $bc = 'badge-yellow'; }
                                    else { $bc = 'badge-red'; }
                                ?>
                                <span class="badge <?= $bc ?>"><?= round($sc, 2) ?></span>
                            <?php else: ?>
                                <span style="color:#cbd5e1;">—</span>
                            <?php endif; ?>
                        </td>
                        <td style="font-size:12px; white-space:nowrap;"><?= htmlspecialchars($a['published_date'] ?? '-') ?></td>
                        <td>
                            <?php if ($a['content_text']): ?>
                                <span class="badge badge-green">Content</span>
                            <?php else: ?>
                                <span class="badge badge-gray">Meta only</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endwhile; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($g2_total_pages > 1): ?>
    <div class="pagination">
        <?php if ($industry_page > 1): ?><a href="<?= client_url($client_id, ['ip' => $industry_page - 1]) ?>">&laquo; Prev</a><?php endif; ?>
        <?php for ($p = max(1, $industry_page - 3); $p <= min($g2_total_pages, $industry_page + 3); $p++): ?>
            <?php if ($p == $industry_page): ?>
                <span><?= $p ?></span>
            <?php else: ?>
                <a href="<?= client_url($client_id, ['ip' => $p]) ?>"><?= $p ?></a>
            <?php endif; ?>
        <?php endfor; ?>
        <?php if ($industry_page < $g2_total_pages): ?><a href="<?= client_url($client_id, ['ip' => $industry_page + 1]) ?>">Next &raquo;</a><?php endif; ?>
    </div>
    <?php endif; ?>

</div><!-- /content-card -->
</div><!-- /page-bg -->

<footer>Sentiment Vision &middot; <?= htmlspecialchars($client['name']) ?></footer>

<script>
document.querySelectorAll('.tier-btn').forEach(function(btn) {
    btn.addEventListener('click', function() {
        var tier = parseInt(this.getAttribute('data-tier'));
        // Get current active tiers from buttons
        var active = [];
        document.querySelectorAll('.tier-btn.active').forEach(function(b) {
            active.push(parseInt(b.getAttribute('data-tier')));
        });

        var idx = active.indexOf(tier);
        if (idx !== -1) {
            // Trying to deselect — prevent if it's the last one
            if (active.length <= 1) return;
            active.splice(idx, 1);
        } else {
            active.push(tier);
        }

        active.sort();

        // Build new URL preserving all other params
        var params = new URLSearchParams(window.location.search);
        if (active.length === 4 && active.join(',') === '1,2,3,4') {
            params.delete('tiers');
        } else {
            params.set('tiers', active.join(','));
        }
        // Reset pagination when tiers change
        params.delete('cp');
        params.delete('ip');

        window.location.search = params.toString();
    });
});
</script>

</body>
</html>
<?php $db->close(); ?>

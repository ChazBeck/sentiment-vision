<?php
/**
 * Sentiment Vision - Dashboard
 * Client tiles overview with sentiment at a glance.
 */

require_once __DIR__ . '/includes/db_connect.php';

// ---------------------------------------------------------------------------
// Single article detail view (kept from previous dashboard)
// ---------------------------------------------------------------------------
$view_id = isset($_GET['view']) ? (int)$_GET['view'] : 0;
$article_detail = null;
if ($view_id) {
    $detail_stmt = $db->prepare("
        SELECT a.*, c.name AS client_name, s.name AS source_name
        FROM articles a
        JOIN clients c ON a.client_id = c.id
        LEFT JOIN sources s ON a.source_id = s.id
        WHERE a.id = ?
    ");
    $detail_stmt->bind_param('i', $view_id);
    $detail_stmt->execute();
    $article_detail = $detail_stmt->get_result()->fetch_assoc();
}

// ---------------------------------------------------------------------------
// Client tiles data
// ---------------------------------------------------------------------------
if (!$article_detail) {
    // Basic stats from all articles, weighted sentiment only from client-mention articles
    // Tier weights: T1=3x, T2=2x, T3=1x, T4=0.5x
    $tiles = $db->query("
        SELECT c.id, c.name, c.industries, c.competitors,
               (SELECT COUNT(*) FROM articles a WHERE a.client_id = c.id) AS article_count,
               (SELECT COUNT(*) FROM articles a WHERE a.client_id = c.id AND a.content_text IS NOT NULL) AS with_content,
               (SELECT MAX(a.fetched_at) FROM articles a WHERE a.client_id = c.id) AS last_fetch,
               (SELECT SUM(a.sentiment_score * CASE a.media_tier WHEN 1 THEN 3.0 WHEN 2 THEN 2.0 WHEN 3 THEN 1.0 WHEN 4 THEN 0.5 ELSE 1.0 END)
                     / SUM(CASE a.media_tier WHEN 1 THEN 3.0 WHEN 2 THEN 2.0 WHEN 3 THEN 1.0 WHEN 4 THEN 0.5 ELSE 1.0 END)
                FROM articles a WHERE a.client_id = c.id AND a.sentiment_score IS NOT NULL
                   AND (a.title LIKE CONCAT('%', c.name, '%') OR COALESCE(a.content_text, '') LIKE CONCAT('%', c.name, '%'))) AS avg_sentiment,
               (SELECT SUM(CASE WHEN a.sentiment_label = 'positive' THEN 1 ELSE 0 END) FROM articles a WHERE a.client_id = c.id
                   AND (a.title LIKE CONCAT('%', c.name, '%') OR COALESCE(a.content_text, '') LIKE CONCAT('%', c.name, '%'))) AS positive,
               (SELECT SUM(CASE WHEN a.sentiment_label = 'neutral' THEN 1 ELSE 0 END) FROM articles a WHERE a.client_id = c.id
                   AND (a.title LIKE CONCAT('%', c.name, '%') OR COALESCE(a.content_text, '') LIKE CONCAT('%', c.name, '%'))) AS neutral_count,
               (SELECT SUM(CASE WHEN a.sentiment_label = 'negative' THEN 1 ELSE 0 END) FROM articles a WHERE a.client_id = c.id
                   AND (a.title LIKE CONCAT('%', c.name, '%') OR COALESCE(a.content_text, '') LIKE CONCAT('%', c.name, '%'))) AS negative
        FROM clients c
        ORDER BY c.name
    ");
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sentiment Vision</title>
<link rel="stylesheet" href="css/style.css">
<style>
    .tile-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 20px; margin-bottom: 30px; }
    .tile {
        background: #fff; border-radius: 8px;
        padding: 24px; transition: all 0.2s ease;
        border: 1px solid #e5e7eb;
    }
    .tile:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.08); border-color: rgba(229, 131, 37, 0.4); }
    .tile-name { font-size: 20px; font-weight: 700; color: #043546; margin-bottom: 8px; font-family: 'Archivo', sans-serif; }
    .tile-name a { color: #043546; }
    .tile-name a:hover { color: #E58325; text-decoration: none; }
    .tile-tags { display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 16px; }
    .tile-sentiment { display: flex; align-items: baseline; gap: 10px; margin-bottom: 12px; }
    .tile-score { font-size: 32px; font-weight: 700; font-family: 'Archivo', sans-serif; }
    .tile-label { font-size: 14px; font-weight: 600; }
    .sentiment-bar { display: flex; height: 8px; border-radius: 4px; overflow: hidden; margin-bottom: 8px; background: #f1f5f9; }
    .sentiment-bar div { height: 100%; }
    .bar-legend { display: flex; gap: 14px; font-size: 12px; color: #6c757d; margin-bottom: 16px; }
    .bar-legend span { display: flex; align-items: center; gap: 4px; }
    .dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; }
    .dot-green { background: #16a34a; }
    .dot-yellow { background: #eab308; }
    .dot-red { background: #dc2626; }
    .tile-stats { display: flex; gap: 16px; font-size: 12px; color: #6c757d; padding-top: 14px; border-top: 1px solid #e5e7eb; flex-wrap: wrap; }
    .tile-stats strong { color: #043546; font-weight: 600; }
    .tile-link { display: inline-block; margin-top: 14px; font-size: 13px; font-weight: 500; color: #E58325; }
    .tile-link:hover { color: #d16a1d; }
    .article-detail { background: #fff; border-radius: 8px; padding: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); margin-bottom: 20px; }
    .article-detail h2 { font-size: 20px; margin-bottom: 12px; font-family: 'Archivo', sans-serif; color: #043546; }
    .article-detail .meta { color: #6c757d; font-size: 13px; margin-bottom: 16px; display: flex; gap: 16px; flex-wrap: wrap; }
    .article-detail .body { white-space: pre-wrap; font-size: 14px; line-height: 1.7; max-height: 500px; overflow-y: auto; padding: 16px; background: #f9fafb; border-radius: 4px; }
    .empty-state { text-align: center; padding: 60px 20px; color: #6c757d; }
    .empty-state h2 { font-size: 20px; color: #043546; margin-bottom: 8px; }
</style>
</head>
<body>

<div class="sub-nav-wrap">
<nav class="sub-nav">
    <a href="index.php" class="active">Dashboard</a>
    <a href="clients.php">Clients</a>
    <a href="tags.php">Tags</a>
    <a href="sources.php">Sources</a>
</nav>
</div>

<div class="page-bg">
<div class="content-card wide">

<?php if ($article_detail): ?>
    <!-- Single Article View -->
    <div style="margin: 20px 0;">
        <a href="index.php">&larr; Back to dashboard</a>
    </div>
    <div class="article-detail">
        <h2><?= htmlspecialchars($article_detail['title'] ?: 'Untitled') ?></h2>
        <div class="meta">
            <span>Client: <strong><?= htmlspecialchars($article_detail['client_name']) ?></strong></span>
            <span>Source: <?= htmlspecialchars($article_detail['source_name'] ?? 'N/A') ?></span>
            <?php if ($article_detail['author']): ?><span>By: <?= htmlspecialchars($article_detail['author']) ?></span><?php endif; ?>
            <?php if ($article_detail['published_date']): ?><span>Published: <?= htmlspecialchars($article_detail['published_date']) ?></span><?php endif; ?>
            <span>Fetched: <?= htmlspecialchars($article_detail['fetched_at']) ?></span>
            <span>Words: <?= (int)$article_detail['word_count'] ?></span>
            <?php if ($article_detail['language']): ?><span>Lang: <?= htmlspecialchars($article_detail['language']) ?></span><?php endif; ?>
        </div>
        <p style="margin-bottom: 12px;">
            <a href="<?= htmlspecialchars($article_detail['url']) ?>" target="_blank" rel="noopener">View original &rarr;</a>
        </p>
        <?php if ($article_detail['sentiment_score'] !== null): ?>
            <?php
                $sc = (float)$article_detail['sentiment_score'];
                if ($sc >= 0.2) { $bc = 'badge-green'; $sl = 'Positive'; }
                elseif ($sc >= -0.2) { $bc = 'badge-yellow'; $sl = 'Neutral'; }
                else { $bc = 'badge-red'; $sl = 'Negative'; }
            ?>
            <p style="margin-bottom: 12px;">
                Sentiment: <span class="badge <?= $bc ?>"><?= round($sc, 2) ?> &middot; <?= $sl ?></span>
            </p>
        <?php endif; ?>
        <?php
            $esg_tags = json_decode($article_detail['esg_tags'] ?? '', true) ?: [];
            $custom_tags = json_decode($article_detail['tags'] ?? '', true) ?: [];
        ?>
        <?php if (!empty($esg_tags) || !empty($custom_tags)): ?>
            <div style="margin-bottom: 12px; display: flex; flex-wrap: wrap; gap: 4px;">
                <?php foreach ($esg_tags as $tag): ?>
                    <span class="badge" style="background:#dbeafe;color:#1e40af;"><?= htmlspecialchars($tag) ?></span>
                <?php endforeach; ?>
                <?php foreach ($custom_tags as $tag): ?>
                    <span class="badge" style="background:#f3e8ff;color:#6b21a8;"><?= htmlspecialchars($tag) ?></span>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php if ($article_detail['content_text']): ?>
            <div class="body"><?= htmlspecialchars($article_detail['content_text']) ?></div>
        <?php else: ?>
            <p style="color: #94a3b8; font-style: italic;">No content extracted for this article.</p>
        <?php endif; ?>
    </div>

<?php elseif ($tiles && $tiles->num_rows > 0): ?>
    <!-- Client Tiles -->
    <div class="tile-grid">
        <?php while ($t = $tiles->fetch_assoc()):
            $industries = json_decode($t['industries'], true) ?: [];
            $competitors = json_decode($t['competitors'], true) ?: [];
            $avg = $t['avg_sentiment'];
            $pos = (int)$t['positive'];
            $neu = (int)$t['neutral_count'];
            $neg = (int)$t['negative'];
            $scored = $pos + $neu + $neg;

            // Sentiment color
            if ($avg === null) {
                $s_color = '#94a3b8'; $s_label = 'No data';
            } elseif ($avg >= 0.2) {
                $s_color = '#16a34a'; $s_label = 'Positive';
            } elseif ($avg >= -0.2) {
                $s_color = '#eab308'; $s_label = 'Neutral';
            } else {
                $s_color = '#dc2626'; $s_label = 'Negative';
            }

            // Bar widths
            $pos_pct = $scored > 0 ? round(($pos / $scored) * 100, 1) : 0;
            $neu_pct = $scored > 0 ? round(($neu / $scored) * 100, 1) : 0;
            $neg_pct = $scored > 0 ? max(0, 100 - $pos_pct - $neu_pct) : 0;
        ?>
        <div class="tile">
            <div class="tile-name">
                <a href="client.php?id=<?= $t['id'] ?>"><?= htmlspecialchars($t['name']) ?></a>
            </div>

            <div class="tile-tags">
                <?php foreach ($industries as $ind): ?>
                    <span class="tag tag-blue"><?= htmlspecialchars($ind) ?></span>
                <?php endforeach; ?>
                <?php foreach ($competitors as $comp): ?>
                    <span class="tag tag-amber"><?= htmlspecialchars($comp) ?></span>
                <?php endforeach; ?>
            </div>

            <div class="tile-sentiment">
                <span class="tile-score" style="color: <?= $s_color ?>">
                    <?= $avg !== null ? round($avg, 2) : '&mdash;' ?>
                </span>
                <span class="tile-label" style="color: <?= $s_color ?>"><?= $s_label ?></span>
            </div>

            <?php if ($scored > 0): ?>
            <div class="sentiment-bar">
                <?php if ($pos_pct > 0): ?><div style="width:<?= $pos_pct ?>%; background:#16a34a;"></div><?php endif; ?>
                <?php if ($neu_pct > 0): ?><div style="width:<?= $neu_pct ?>%; background:#eab308;"></div><?php endif; ?>
                <?php if ($neg_pct > 0): ?><div style="width:<?= $neg_pct ?>%; background:#dc2626;"></div><?php endif; ?>
            </div>
            <div class="bar-legend">
                <span><span class="dot dot-green"></span> <?= $pos ?> positive</span>
                <span><span class="dot dot-yellow"></span> <?= $neu ?> neutral</span>
                <span><span class="dot dot-red"></span> <?= $neg ?> negative</span>
            </div>
            <?php else: ?>
            <div class="sentiment-bar"></div>
            <div class="bar-legend" style="color:#cbd5e1;">No scored articles yet</div>
            <?php endif; ?>

            <div class="tile-stats">
                <span><strong><?= (int)$t['article_count'] ?></strong> articles</span>
                <span><strong><?= (int)$t['with_content'] ?></strong> with content</span>
                <span>Last fetch: <strong><?= $t['last_fetch'] ? date('M j, g:ia', strtotime($t['last_fetch'])) : 'Never' ?></strong></span>
            </div>

            <a href="client.php?id=<?= $t['id'] ?>" class="tile-link">View Details &rarr;</a>
        </div>
        <?php endwhile; ?>
    </div>

<?php else: ?>
    <div class="empty-state">
        <h2>No clients configured</h2>
        <p>Add your first client to get started.</p>
        <p style="margin-top: 12px;"><a href="clients.php">Go to Client Management &rarr;</a></p>
    </div>
<?php endif; ?>

</div><!-- /content-card -->
</div><!-- /page-bg -->

<footer>Sentiment Vision</footer>

</body>
</html>
<?php $db->close(); ?>

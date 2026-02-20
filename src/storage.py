import json
import logging

import mysql.connector

logger = logging.getLogger("sentiment_vision")

# ---------------------------------------------------------------------------
# Connection settings stored for reconnection
# ---------------------------------------------------------------------------
_db_config: dict = {}


def _ensure_connection(conn):
    """Ping the connection; if it dropped, reconnect transparently.

    GreenGeeks shared MySQL has aggressive idle timeouts (~60-120 s).
    Long RSS fetches + content extraction can easily exceed this, so we
    reconnect on the fly rather than holding a single long-lived connection.

    Returns the (possibly new) connection object.
    """
    try:
        conn.ping(reconnect=True, attempts=3, delay=2)
        return conn
    except mysql.connector.Error:
        # ping(reconnect=True) should handle it, but if it fails we
        # reconnect manually using the saved config.
        if _db_config:
            logger.warning("MySQL connection lost — reconnecting…")
            new_conn = mysql.connector.connect(**_db_config)
            new_conn.autocommit = False
            return new_conn
        raise


SCHEMA_SQL = [
    """
    CREATE TABLE IF NOT EXISTS clients (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        name            VARCHAR(255) NOT NULL UNIQUE,
        industries      JSON NOT NULL,
        competitors     JSON NOT NULL,
        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )
    """,
    """
    CREATE TABLE IF NOT EXISTS sources (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        client_id       INT,
        name            VARCHAR(255) NOT NULL,
        source_type     ENUM('rss', 'html', 'search') NOT NULL,
        url             TEXT NOT NULL,
        enabled         TINYINT NOT NULL DEFAULT 1,
        media_tier      TINYINT NOT NULL DEFAULT 3,
        is_global       TINYINT NOT NULL DEFAULT 0,
        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (client_id) REFERENCES clients(id),
        UNIQUE KEY uq_client_url (client_id, url(500))
    )
    """,
    """
    CREATE TABLE IF NOT EXISTS articles (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        client_id       INT NOT NULL,
        source_id       INT,
        url             VARCHAR(2048) NOT NULL,
        title           TEXT,
        author          VARCHAR(512),
        published_date  DATETIME,
        fetched_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        content_text    MEDIUMTEXT,
        summary         TEXT,
        image_url       TEXT,
        word_count      INT,
        language        VARCHAR(10),
        sentiment_score FLOAT,
        sentiment_label VARCHAR(20),
        score_method    ENUM('vader', 'ai') DEFAULT 'vader',
        media_tier      TINYINT NOT NULL DEFAULT 3,
        esg_tags        JSON,
        esg_score       FLOAT,
        analyzed_at     DATETIME,
        tags            JSON,
        FOREIGN KEY (client_id) REFERENCES clients(id),
        FOREIGN KEY (source_id) REFERENCES sources(id),
        UNIQUE KEY uq_client_url (client_id, url(500))
    )
    """,
    "CREATE INDEX IF NOT EXISTS idx_articles_client ON articles(client_id)",
    "CREATE INDEX IF NOT EXISTS idx_articles_published ON articles(published_date)",
    "CREATE INDEX IF NOT EXISTS idx_articles_fetched ON articles(fetched_at)",
    """
    CREATE TABLE IF NOT EXISTS fetch_log (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        source_id       INT NOT NULL,
        run_started_at  DATETIME NOT NULL,
        run_finished_at DATETIME,
        articles_found  INT DEFAULT 0,
        articles_new    INT DEFAULT 0,
        status          ENUM('success', 'error', 'skipped') NOT NULL,
        error_message   TEXT,
        FOREIGN KEY (source_id) REFERENCES sources(id)
    )
    """,
    """
    CREATE TABLE IF NOT EXISTS tags (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        name            VARCHAR(255) NOT NULL,
        tag_type        ENUM('esg', 'custom') NOT NULL DEFAULT 'custom',
        scope           ENUM('global', 'client') NOT NULL DEFAULT 'global',
        client_id       INT NULL,
        keywords        JSON NOT NULL,
        match_method    ENUM('keyword', 'ai') NOT NULL DEFAULT 'keyword',
        color           VARCHAR(7) DEFAULT '#6366f1',
        enabled         TINYINT NOT NULL DEFAULT 1,
        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE,
        UNIQUE KEY uq_tag_name_client (name, client_id)
    )
    """,
    """
    CREATE TABLE IF NOT EXISTS article_tags (
        id              INT AUTO_INCREMENT PRIMARY KEY,
        article_id      INT NOT NULL,
        tag_id          INT NOT NULL,
        confidence      FLOAT DEFAULT 1.0,
        matched_keyword VARCHAR(255) NULL,
        match_method    ENUM('keyword', 'ai') NOT NULL DEFAULT 'keyword',
        created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (article_id) REFERENCES articles(id) ON DELETE CASCADE,
        FOREIGN KEY (tag_id) REFERENCES tags(id) ON DELETE CASCADE,
        UNIQUE KEY uq_article_tag (article_id, tag_id)
    )
    """,
    "CREATE INDEX IF NOT EXISTS idx_article_tags_article ON article_tags(article_id)",
    "CREATE INDEX IF NOT EXISTS idx_article_tags_tag ON article_tags(tag_id)",
]

# Migrations for existing databases — each runs in try/except to skip if already applied
MIGRATION_SQL = [
    "ALTER TABLE sources MODIFY client_id INT NULL",
    "ALTER TABLE sources ADD COLUMN media_tier TINYINT NOT NULL DEFAULT 3",
    "ALTER TABLE sources ADD COLUMN is_global TINYINT NOT NULL DEFAULT 0",
    "ALTER TABLE articles ADD COLUMN media_tier TINYINT NOT NULL DEFAULT 3",
    "ALTER TABLE articles ADD COLUMN score_method ENUM('vader', 'ai') DEFAULT 'vader'",
]


def init_db(settings: dict):
    """Connect to MySQL, create schema if needed, return connection."""
    global _db_config
    db_cfg = settings.get("database", {})
    _db_config = dict(
        host=db_cfg.get("host", "localhost"),
        port=db_cfg.get("port", 3306),
        user=db_cfg.get("user"),
        password=db_cfg.get("password"),
        database=db_cfg.get("database"),
        charset="utf8mb4",
        collation="utf8mb4_unicode_ci",
        connection_timeout=30,
    )
    conn = mysql.connector.connect(**_db_config)
    cursor = conn.cursor()
    for statement in SCHEMA_SQL:
        try:
            cursor.execute(statement)
        except mysql.connector.Error as e:
            # Indexes may already exist; ignore duplicate key name errors
            if e.errno == 1061:  # Duplicate key name
                pass
            else:
                raise
    # Run migrations for existing databases (idempotent — skips if already applied)
    for migration in MIGRATION_SQL:
        try:
            cursor.execute(migration)
        except mysql.connector.Error as e:
            # 1060 = Duplicate column name (already added), 1061 = Duplicate key name
            if e.errno in (1060, 1061):
                pass
            else:
                raise
    conn.commit()
    cursor.close()
    logger.info("Database initialized")
    return conn


def sync_clients(conn, clients) -> dict:
    """Upsert clients from config into DB. Returns {name: id} mapping."""
    conn = _ensure_connection(conn)
    cursor = conn.cursor()
    client_map = {}
    for client in clients:
        cursor.execute(
            """INSERT INTO clients (name, industries, competitors)
               VALUES (%s, %s, %s)
               ON DUPLICATE KEY UPDATE
                   industries = VALUES(industries),
                   competitors = VALUES(competitors)""",
            (client.name, json.dumps(client.industries), json.dumps(client.competitors)),
        )
        cursor.execute("SELECT id FROM clients WHERE name = %s", (client.name,))
        client_map[client.name] = cursor.fetchone()[0]
    conn.commit()
    cursor.close()
    return client_map


def sync_sources(conn, client_id: int, sources) -> dict:
    """Upsert sources for a client. Returns {url: id} mapping."""
    conn = _ensure_connection(conn)
    cursor = conn.cursor()
    source_map = {}
    for source in sources:
        media_tier = getattr(source, "media_tier", 3)
        cursor.execute(
            """INSERT INTO sources (client_id, name, source_type, url, media_tier)
               VALUES (%s, %s, %s, %s, %s)
               ON DUPLICATE KEY UPDATE
                   name = VALUES(name),
                   source_type = VALUES(source_type),
                   media_tier = VALUES(media_tier)""",
            (client_id, source.name, source.source_type, source.url, media_tier),
        )
        cursor.execute(
            "SELECT id FROM sources WHERE client_id = %s AND url = %s",
            (client_id, source.url),
        )
        source_map[source.url] = cursor.fetchone()[0]
    conn.commit()
    cursor.close()
    return source_map


def sync_global_sources(conn, global_sources) -> dict:
    """Upsert global sources (client_id=NULL, is_global=1). Returns {url: id} mapping."""
    conn = _ensure_connection(conn)
    cursor = conn.cursor()
    source_map = {}
    for source in global_sources:
        # MySQL treats NULL != NULL in unique keys, so use SELECT-then-INSERT
        cursor.execute(
            "SELECT id FROM sources WHERE is_global = 1 AND url = %s",
            (source.url,),
        )
        row = cursor.fetchone()
        if row:
            # Update existing global source
            cursor.execute(
                """UPDATE sources SET name = %s, source_type = %s, media_tier = %s
                   WHERE id = %s""",
                (source.name, source.source_type, source.media_tier, row[0]),
            )
            source_map[source.url] = row[0]
        else:
            # Insert new global source
            cursor.execute(
                """INSERT INTO sources (client_id, name, source_type, url, media_tier, is_global)
                   VALUES (NULL, %s, %s, %s, %s, 1)""",
                (source.name, source.source_type, source.url, source.media_tier),
            )
            source_map[source.url] = cursor.lastrowid
    conn.commit()
    cursor.close()
    return source_map


def store_article(conn, article: dict, client_id: int, source_id: int, media_tier: int = 3):
    """Insert article with dedup via INSERT IGNORE. Returns article ID if new, None if duplicate."""
    conn = _ensure_connection(conn)
    cursor = conn.cursor()
    summary = article.get("summary") or ""
    if not summary and article.get("content_text"):
        summary = article["content_text"][:500]

    cursor.execute(
        """INSERT IGNORE INTO articles
           (client_id, source_id, url, title, author, published_date,
            content_text, summary, image_url, word_count, language, media_tier)
           VALUES (%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)""",
        (
            client_id,
            source_id,
            article["url"],
            article.get("title"),
            article.get("author"),
            article.get("published_date"),
            article.get("content_text"),
            summary,
            article.get("image_url"),
            article.get("word_count"),
            article.get("language"),
            media_tier,
        ),
    )
    article_id = cursor.lastrowid if cursor.rowcount > 0 else None
    conn.commit()
    cursor.close()
    return article_id


def log_fetch(
    conn,
    source_id: int,
    run_started: str,
    articles_found: int,
    articles_new: int,
    status: str,
    error_message: str = None,
):
    """Record a fetch attempt in the fetch_log table."""
    conn = _ensure_connection(conn)
    cursor = conn.cursor()
    cursor.execute(
        """INSERT INTO fetch_log
           (source_id, run_started_at, run_finished_at, articles_found, articles_new, status, error_message)
           VALUES (%s, %s, NOW(), %s, %s, %s, %s)""",
        (source_id, run_started, articles_found, articles_new, status, error_message),
    )
    conn.commit()
    cursor.close()


# ---------------------------------------------------------------------------
# Tag CRUD
# ---------------------------------------------------------------------------

def get_all_tags(conn, scope: str = None, client_id: int = None) -> list:
    """Retrieve tags with optional filtering. Returns list of dicts."""
    conn = _ensure_connection(conn)
    cursor = conn.cursor(dictionary=True)
    if scope == "global":
        cursor.execute(
            "SELECT * FROM tags WHERE scope = 'global' ORDER BY tag_type, name"
        )
    elif scope == "client" and client_id:
        cursor.execute(
            "SELECT * FROM tags WHERE scope = 'client' AND client_id = %s ORDER BY tag_type, name",
            (client_id,),
        )
    else:
        cursor.execute("SELECT * FROM tags ORDER BY scope, tag_type, name")
    rows = cursor.fetchall()
    cursor.close()
    for row in rows:
        if isinstance(row["keywords"], str):
            row["keywords"] = json.loads(row["keywords"])
    return rows


def get_tag_by_id(conn, tag_id: int) -> dict:
    """Retrieve a single tag by ID. Returns dict or None."""
    conn = _ensure_connection(conn)
    cursor = conn.cursor(dictionary=True)
    cursor.execute("SELECT * FROM tags WHERE id = %s", (tag_id,))
    row = cursor.fetchone()
    cursor.close()
    if row and isinstance(row["keywords"], str):
        row["keywords"] = json.loads(row["keywords"])
    return row


def create_tag(conn, name: str, tag_type: str, scope: str,
               keywords: list, client_id: int = None,
               match_method: str = "keyword", color: str = "#6366f1") -> int:
    """Insert a new tag. Returns the new tag ID."""
    conn = _ensure_connection(conn)
    cursor = conn.cursor()
    cursor.execute(
        """INSERT INTO tags (name, tag_type, scope, client_id, keywords, match_method, color)
           VALUES (%s, %s, %s, %s, %s, %s, %s)""",
        (name, tag_type, scope, client_id, json.dumps(keywords), match_method, color),
    )
    tag_id = cursor.lastrowid
    conn.commit()
    cursor.close()
    return tag_id


def update_tag(conn, tag_id: int, name: str = None, keywords: list = None,
               enabled: bool = None, color: str = None):
    """Update specific fields of a tag. Only non-None arguments are updated."""
    updates = []
    params = []
    if name is not None:
        updates.append("name = %s")
        params.append(name)
    if keywords is not None:
        updates.append("keywords = %s")
        params.append(json.dumps(keywords))
    if enabled is not None:
        updates.append("enabled = %s")
        params.append(1 if enabled else 0)
    if color is not None:
        updates.append("color = %s")
        params.append(color)
    if not updates:
        return
    params.append(tag_id)
    conn = _ensure_connection(conn)
    cursor = conn.cursor()
    cursor.execute(f"UPDATE tags SET {', '.join(updates)} WHERE id = %s", params)
    conn.commit()
    cursor.close()


def delete_tag(conn, tag_id: int):
    """Delete a tag (CASCADE removes article_tags entries)."""
    conn = _ensure_connection(conn)
    cursor = conn.cursor()
    cursor.execute("DELETE FROM tags WHERE id = %s", (tag_id,))
    conn.commit()
    cursor.close()

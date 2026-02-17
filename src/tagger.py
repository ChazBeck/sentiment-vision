"""
Tag matching module for Sentiment Vision.

Phase 1: Keyword-based matching against article content.
Phase 2 (future): AI/NLP classification dispatched via match_method field.
"""

import json
import logging
import re

logger = logging.getLogger("sentiment_vision")


def load_tags_for_client(conn, client_id: int) -> list:
    """
    Load all enabled tags applicable to a given client.
    Returns both global tags and client-specific tags for this client.
    """
    cursor = conn.cursor(dictionary=True)
    cursor.execute(
        """SELECT id, name, tag_type, scope, keywords, match_method, color
           FROM tags
           WHERE enabled = 1
             AND (scope = 'global' OR (scope = 'client' AND client_id = %s))
           ORDER BY tag_type, name""",
        (client_id,),
    )
    rows = cursor.fetchall()
    cursor.close()

    for row in rows:
        if isinstance(row["keywords"], str):
            row["keywords"] = json.loads(row["keywords"])

    return rows


def match_tags_keyword(text: str, tags: list) -> list:
    """
    Match article text against tag keywords using case-insensitive search.
    Uses word-boundary regex for short keywords (<=3 chars) to avoid false positives.
    """
    if not text:
        return []

    text_lower = text.lower()
    matches = []

    for tag in tags:
        if tag["match_method"] != "keyword":
            continue

        for keyword in tag["keywords"]:
            kw_lower = keyword.lower().strip()
            if not kw_lower:
                continue

            if len(kw_lower) <= 3:
                pattern = r"\b" + re.escape(kw_lower) + r"\b"
                if re.search(pattern, text_lower):
                    matches.append({
                        "tag_id": tag["id"],
                        "tag_name": tag["name"],
                        "tag_type": tag["tag_type"],
                        "matched_keyword": keyword,
                        "confidence": 1.0,
                        "match_method": "keyword",
                    })
                    break
            else:
                if kw_lower in text_lower:
                    matches.append({
                        "tag_id": tag["id"],
                        "tag_name": tag["name"],
                        "tag_type": tag["tag_type"],
                        "matched_keyword": keyword,
                        "confidence": 1.0,
                        "match_method": "keyword",
                    })
                    break

    return matches


def match_tags_ai(text: str, tags: list) -> list:
    """Phase 2 placeholder: AI/NLP-based tag classification."""
    return []


def tag_article(conn, article_id: int, client_id: int, text: str) -> list:
    """
    Full tagging pipeline for a single article.
    Loads applicable tags, runs keyword matching, writes results to
    article_tags junction table, and updates denormalized JSON columns.
    Returns list of matched tag names.
    """
    tags = load_tags_for_client(conn, client_id)
    if not tags:
        return []

    matches = match_tags_keyword(text, tags)
    ai_matches = match_tags_ai(text, [t for t in tags if t["match_method"] == "ai"])

    # Merge, preferring higher confidence when both methods match same tag
    seen = {}
    for m in matches + ai_matches:
        tid = m["tag_id"]
        if tid not in seen or m["confidence"] > seen[tid]["confidence"]:
            seen[tid] = m

    all_matches = list(seen.values())
    if not all_matches:
        return []

    cursor = conn.cursor()
    for m in all_matches:
        cursor.execute(
            """INSERT IGNORE INTO article_tags
               (article_id, tag_id, confidence, matched_keyword, match_method)
               VALUES (%s, %s, %s, %s, %s)""",
            (article_id, m["tag_id"], m["confidence"],
             m["matched_keyword"], m["match_method"]),
        )

    # Update denormalized JSON columns on the articles table
    esg_tags = [m["tag_name"] for m in all_matches if m["tag_type"] == "esg"]
    custom_tags = [m["tag_name"] for m in all_matches if m["tag_type"] == "custom"]

    cursor.execute(
        """UPDATE articles
           SET esg_tags = %s, tags = %s
           WHERE id = %s""",
        (json.dumps(esg_tags) if esg_tags else None,
         json.dumps(custom_tags) if custom_tags else None,
         article_id),
    )

    conn.commit()
    cursor.close()

    return [m["tag_name"] for m in all_matches]


def tag_untagged(conn, client_id: int, batch_size: int = 500) -> int:
    """
    Batch-tag articles that have no entries in article_tags yet.
    Returns count of articles that received at least one tag.
    """
    cursor = conn.cursor(dictionary=True)
    cursor.execute(
        """SELECT a.id, a.title, a.content_text
           FROM articles a
           LEFT JOIN article_tags at2 ON a.id = at2.article_id
           WHERE a.client_id = %s
             AND a.content_text IS NOT NULL
             AND at2.id IS NULL
           ORDER BY a.fetched_at DESC
           LIMIT %s""",
        (client_id, batch_size),
    )
    rows = cursor.fetchall()
    cursor.close()

    if not rows:
        return 0

    logger.info(f"Tagging {len(rows)} untagged articles for client {client_id}")
    tagged_count = 0

    for row in rows:
        text = ((row.get("title") or "") + " " + (row.get("content_text") or "")).strip()
        if not text:
            continue
        try:
            matched = tag_article(conn, row["id"], client_id, text)
            if matched:
                tagged_count += 1
                logger.debug(f"  Article {row['id']}: {matched}")
        except Exception as e:
            logger.error(f"Failed to tag article {row['id']}: {e}")
            continue

    logger.info(f"Tagging complete: {tagged_count}/{len(rows)} articles tagged")
    return tagged_count


def retag_all(conn, client_id: int, batch_size: int = 500) -> int:
    """
    Clear existing tags and re-tag all articles for a client.
    Returns count of articles that received at least one tag.
    """
    cursor = conn.cursor()
    # Delete existing article_tags for this client's articles
    cursor.execute(
        """DELETE at2 FROM article_tags at2
           INNER JOIN articles a ON at2.article_id = a.id
           WHERE a.client_id = %s""",
        (client_id,),
    )
    # Clear denormalized JSON columns
    cursor.execute(
        "UPDATE articles SET esg_tags = NULL, tags = NULL WHERE client_id = %s",
        (client_id,),
    )
    conn.commit()
    cursor.close()

    # Now tag everything
    cursor = conn.cursor(dictionary=True)
    cursor.execute(
        """SELECT id, title, content_text
           FROM articles
           WHERE client_id = %s AND content_text IS NOT NULL
           ORDER BY fetched_at DESC
           LIMIT %s""",
        (client_id, batch_size),
    )
    rows = cursor.fetchall()
    cursor.close()

    if not rows:
        return 0

    logger.info(f"Re-tagging {len(rows)} articles for client {client_id}")
    tagged_count = 0

    for row in rows:
        text = ((row.get("title") or "") + " " + (row.get("content_text") or "")).strip()
        if not text:
            continue
        try:
            matched = tag_article(conn, row["id"], client_id, text)
            if matched:
                tagged_count += 1
        except Exception as e:
            logger.error(f"Failed to re-tag article {row['id']}: {e}")
            continue

    logger.info(f"Re-tagging complete: {tagged_count}/{len(rows)} articles tagged")
    return tagged_count

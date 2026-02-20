"""
Sentiment analysis module — VADER + Claude AI hybrid.

Scores articles on a -1.0 (negative) to +1.0 (positive) scale.
VADER handles clear-positive and clear-negative articles for free.
Articles in the neutral zone are escalated to Claude Haiku for
context-aware scoring from the client's perspective.
"""

import json
import logging
import re
from datetime import datetime, timezone

from vaderSentiment.vaderSentiment import SentimentIntensityAnalyzer

from .storage import _ensure_connection

logger = logging.getLogger("sentiment_vision")

# Module-level singleton — VADER's __init__ loads the lexicon once (~5MB, ~50ms)
_analyzer = None

# Common abbreviations to avoid false sentence splits
_ABBREVS = frozenset([
    "mr", "mrs", "ms", "dr", "jr", "sr", "prof", "vs", "etc",
    "inc", "ltd", "corp", "co", "gen", "gov", "sgt", "col",
    "dept", "univ", "approx", "est", "vol", "no", "st", "ave",
])


def _get_analyzer():
    """Lazy-init singleton for the VADER analyzer."""
    global _analyzer
    if _analyzer is None:
        _analyzer = SentimentIntensityAnalyzer()
    return _analyzer


def _split_sentences(text: str) -> list:
    """
    Split text into sentences.
    Handles common abbreviations to avoid false splits.
    No NLTK dependency.
    """
    # Split on . ! ? followed by whitespace and uppercase letter
    raw_parts = re.split(r"(?<=[.!?])\s+(?=[A-Z])", text)

    # Rejoin fragments that were split on abbreviation periods
    sentences = []
    buffer = ""
    for part in raw_parts:
        if buffer:
            buffer = buffer + " " + part
        else:
            buffer = part

        # Check if the buffer ends with an abbreviation (e.g. "Dr." or "Inc.")
        # If so, keep buffering instead of emitting
        last_word = buffer.rstrip(".").rsplit(None, 1)[-1].lower() if buffer.rstrip(".") else ""
        if buffer.rstrip().endswith(".") and last_word in _ABBREVS:
            continue
        sentences.append(buffer.strip())
        buffer = ""

    if buffer:
        sentences.append(buffer.strip())

    return [s for s in sentences if s]


def score_text(text: str) -> float:
    """
    Score text using VADER with sentence-level aggregation for long content.

    Short text (≤280 chars): score directly.
    Long text: split into sentences, score each, return mean of
    significant scores (|compound| > 0.05) to avoid neutral dilution.

    Returns compound score in range -1.0 to +1.0.
    """
    analyzer = _get_analyzer()

    # Short text — score directly (headlines, titles)
    if len(text) <= 280:
        return analyzer.polarity_scores(text)["compound"]

    # Long text — sentence-level aggregation
    sentences = _split_sentences(text)
    if not sentences:
        return analyzer.polarity_scores(text)["compound"]

    scores = []
    for sentence in sentences:
        if len(sentence) < 10:
            continue
        compound = analyzer.polarity_scores(sentence)["compound"]
        scores.append(compound)

    if not scores:
        return 0.0

    # Filter out near-zero scores to avoid dilution by filler sentences
    significant = [s for s in scores if abs(s) > 0.05]
    if significant:
        return sum(significant) / len(significant)

    # All sentences are near-neutral
    return sum(scores) / len(scores)


def score_label(score: float, settings: dict) -> str:
    """Map a numeric score to a sentiment label using configurable thresholds."""
    thresholds = settings.get("sentiment", {})
    pos_threshold = thresholds.get("positive_threshold", 0.2)
    neg_threshold = thresholds.get("negative_threshold", -0.2)

    if score >= pos_threshold:
        return "positive"
    elif score < neg_threshold:
        return "negative"
    return "neutral"


def score_article(article_row: dict, settings: dict, client_context: dict = None) -> dict:
    """
    Score a single article using hybrid VADER + AI approach.

    1. Always runs VADER first (free, instant).
    2. If the VADER score falls in the neutral zone AND AI scoring is enabled,
       escalates to Claude Haiku for context-aware re-scoring.
    3. Falls back to VADER if AI scoring fails for any reason.

    Returns dict with sentiment_score, sentiment_label, score_method, analyzed_at.
    """
    text = article_row.get("content_text") or article_row.get("title") or ""
    if not text.strip():
        return {
            "sentiment_score": 0.0,
            "sentiment_label": "neutral",
            "score_method": "vader",
            "analyzed_at": datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S"),
        }

    # Step 1: VADER scoring (always runs)
    vader_score = score_text(text)
    vader_label = score_label(vader_score, settings)

    final_score = vader_score
    final_label = vader_label
    score_method = "vader"

    # Step 2: Check if AI escalation is warranted
    ai_cfg = settings.get("sentiment", {}).get("ai_scoring", {})
    pos_threshold = settings.get("sentiment", {}).get("positive_threshold", 0.2)
    neg_threshold = settings.get("sentiment", {}).get("negative_threshold", -0.2)
    in_neutral_zone = neg_threshold <= vader_score < pos_threshold

    if in_neutral_zone and ai_cfg.get("enabled") and client_context:
        try:
            from .ai_scorer import score_with_ai

            ai_result = score_with_ai(article_row, client_context, settings)
            if ai_result:
                final_score = ai_result["sentiment_score"]
                final_label = ai_result["sentiment_label"]
                score_method = "ai"
        except ImportError:
            pass  # anthropic not installed, stay with VADER

    return {
        "sentiment_score": round(final_score, 4),
        "sentiment_label": final_label,
        "score_method": score_method,
        "analyzed_at": datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S"),
    }


def analyze_unscored(conn, settings: dict) -> int:
    """
    Batch-score all articles where sentiment_score IS NULL.
    Uses hybrid VADER + AI scoring when AI is enabled.
    Updates each row in-place. Returns count of articles scored.
    """
    batch_size = settings.get("sentiment", {}).get("batch_size", 500)

    conn = _ensure_connection(conn)
    cursor = conn.cursor(dictionary=True)
    cursor.execute(
        """SELECT a.id, a.title, a.content_text, a.client_id,
                  c.name AS client_name, c.industries AS client_industries
           FROM articles a
           JOIN clients c ON a.client_id = c.id
           WHERE a.sentiment_score IS NULL
           ORDER BY a.fetched_at DESC
           LIMIT %s""",
        (batch_size,),
    )
    rows = cursor.fetchall()
    cursor.close()

    if not rows:
        logger.info("No unscored articles found")
        return 0

    ai_enabled = settings.get("sentiment", {}).get("ai_scoring", {}).get("enabled", False)
    logger.info(
        f"Scoring {len(rows)} unscored articles"
        f"{' (AI hybrid enabled)' if ai_enabled else ''}"
    )
    scored_count = 0
    ai_count = 0

    conn = _ensure_connection(conn)
    update_cursor = conn.cursor()
    for row in rows:
        try:
            # Build client context for AI scorer
            industries = row.get("client_industries", "[]")
            if isinstance(industries, str):
                industries = json.loads(industries)
            client_context = {
                "name": row.get("client_name", "Unknown"),
                "industries": industries,
            }

            result = score_article(row, settings, client_context=client_context)

            update_cursor.execute(
                """UPDATE articles
                   SET sentiment_score = %s,
                       sentiment_label = %s,
                       score_method = %s,
                       analyzed_at = %s
                   WHERE id = %s""",
                (
                    result["sentiment_score"],
                    result["sentiment_label"],
                    result["score_method"],
                    result["analyzed_at"],
                    row["id"],
                ),
            )
            scored_count += 1
            if result["score_method"] == "ai":
                ai_count += 1

            if scored_count % 50 == 0:
                conn.commit()
                logger.info(f"  Scored {scored_count}/{len(rows)} articles...")

        except Exception as e:
            logger.error(f"Failed to score article {row['id']}: {e}")
            continue

    conn.commit()
    update_cursor.close()

    # Log session summary
    if ai_count > 0:
        try:
            from .ai_scorer import get_session_stats
            stats = get_session_stats()
            logger.info(
                f"Sentiment analysis complete: {scored_count} articles scored "
                f"({ai_count} via AI, {scored_count - ai_count} via VADER, "
                f"est. cost: ${stats['estimated_cost_usd']:.4f})"
            )
        except ImportError:
            logger.info(f"Sentiment analysis complete: {scored_count} articles scored")
    else:
        logger.info(f"Sentiment analysis complete: {scored_count} articles scored (all VADER)")

    return scored_count

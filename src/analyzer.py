"""
Sentiment analysis module using VADER.

Scores articles on a -1.0 (negative) to +1.0 (positive) scale.
For long articles, uses sentence-level scoring with aggregation
to avoid dilution by neutral filler sentences.
"""

import logging
import re
from datetime import datetime, timezone

from vaderSentiment.vaderSentiment import SentimentIntensityAnalyzer

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


def score_article(article_row: dict, settings: dict) -> dict:
    """
    Score a single article. Uses content_text if available, falls back to title.
    Returns dict with sentiment_score, sentiment_label, analyzed_at.
    """
    text = article_row.get("content_text") or article_row.get("title") or ""
    if not text.strip():
        return {
            "sentiment_score": 0.0,
            "sentiment_label": "neutral",
            "analyzed_at": datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S"),
        }

    score = score_text(text)
    label = score_label(score, settings)
    return {
        "sentiment_score": round(score, 4),
        "sentiment_label": label,
        "analyzed_at": datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S"),
    }


def analyze_unscored(conn, settings: dict) -> int:
    """
    Batch-score all articles where sentiment_score IS NULL.
    Updates each row in-place. Returns count of articles scored.
    """
    batch_size = settings.get("sentiment", {}).get("batch_size", 500)

    cursor = conn.cursor(dictionary=True)
    cursor.execute(
        """SELECT id, title, content_text
           FROM articles
           WHERE sentiment_score IS NULL
           ORDER BY fetched_at DESC
           LIMIT %s""",
        (batch_size,),
    )
    rows = cursor.fetchall()
    cursor.close()

    if not rows:
        logger.info("No unscored articles found")
        return 0

    logger.info(f"Scoring {len(rows)} unscored articles")
    scored_count = 0

    update_cursor = conn.cursor()
    for row in rows:
        try:
            result = score_article(row, settings)
            update_cursor.execute(
                """UPDATE articles
                   SET sentiment_score = %s,
                       sentiment_label = %s,
                       analyzed_at = %s
                   WHERE id = %s""",
                (
                    result["sentiment_score"],
                    result["sentiment_label"],
                    result["analyzed_at"],
                    row["id"],
                ),
            )
            scored_count += 1

            if scored_count % 50 == 0:
                conn.commit()
                logger.info(f"  Scored {scored_count}/{len(rows)} articles...")

        except Exception as e:
            logger.error(f"Failed to score article {row['id']}: {e}")
            continue

    conn.commit()
    update_cursor.close()

    logger.info(f"Sentiment analysis complete: {scored_count} articles scored")
    return scored_count

import logging
import time
from email.utils import parsedate_to_datetime
from urllib.parse import urljoin, urlparse

import feedparser
from bs4 import BeautifulSoup

from .utils import polite_get

logger = logging.getLogger("sentiment_vision")


def fetch_source(source, settings: dict) -> list:
    """Dispatch to the appropriate fetcher based on source type. Returns list of article dicts."""
    handlers = {
        "rss": _fetch_rss,
        "html": _fetch_html,
        "search": _fetch_search,
    }
    handler = handlers.get(source.source_type)
    if not handler:
        logger.error(f"Unknown source type: {source.source_type}")
        return []
    return handler(source.url, settings)


def _fetch_rss(url: str, settings: dict) -> list:
    """Parse RSS/Atom feed and extract articles with full content."""
    fetch_cfg = settings.get("fetching", {})
    max_articles = fetch_cfg.get("max_articles_per_source", 50)

    feed = feedparser.parse(url)
    if feed.bozo:
        logger.warning(f"Feed parse issue for {url}: {feed.bozo_exception}")

    articles = []
    for entry in feed.entries[:max_articles]:
        link = entry.get("link", "").strip()
        if not link:
            continue

        title = entry.get("title", "")
        author = entry.get("author", "")
        summary = entry.get("summary", "")

        # Parse published date
        published_date = None
        if entry.get("published_parsed"):
            try:
                published_date = time.strftime("%Y-%m-%d %H:%M:%S", entry.published_parsed)
            except (TypeError, ValueError):
                pass
        if not published_date and entry.get("published"):
            try:
                dt = parsedate_to_datetime(entry.published)
                published_date = dt.strftime("%Y-%m-%d %H:%M:%S")
            except (TypeError, ValueError):
                pass

        # Try to get full content from the entry itself
        content_text = ""
        if entry.get("content"):
            raw_html = entry.content[0].get("value", "")
            content_text = _html_to_text(raw_html)

        # If no inline content, fetch the full article
        if not content_text or len(content_text) < 200:
            try:
                extracted = _extract_content(link, settings)
                if extracted.get("content_text"):
                    content_text = extracted["content_text"]
                    if not title:
                        title = extracted.get("title", "")
                    if not author:
                        author = extracted.get("author", "")
                    if not published_date and extracted.get("published_date"):
                        published_date = extracted["published_date"]
            except Exception as e:
                logger.warning(f"Content extraction failed for {link}: {e}")

        word_count = len(content_text.split()) if content_text else 0

        articles.append({
            "url": link,
            "title": title,
            "author": author,
            "published_date": published_date,
            "content_text": content_text or None,
            "summary": summary[:500] if summary else None,
            "image_url": _get_entry_image(entry),
            "word_count": word_count,
            "language": feed.feed.get("language", ""),
        })

    return articles


def _fetch_html(url: str, settings: dict) -> list:
    """Discover article links from an HTML page and extract each article."""
    fetch_cfg = settings.get("fetching", {})
    max_articles = fetch_cfg.get("max_articles_per_source", 50)

    response = polite_get(url, settings)
    soup = BeautifulSoup(response.text, "lxml")
    base_domain = urlparse(url).netloc

    # Find candidate article links
    links = set()
    # Look in <article> tags first
    for article_tag in soup.find_all("article"):
        for a in article_tag.find_all("a", href=True):
            links.add(a["href"])

    # Also look for links in common list containers
    for container in soup.find_all(["main", "section", "div"], class_=lambda c: c and any(
        kw in (c if isinstance(c, str) else " ".join(c)).lower()
        for kw in ("article", "story", "news", "post", "feed", "list")
    )):
        for a in container.find_all("a", href=True):
            links.add(a["href"])

    # If we found very few links from structured elements, broaden the search
    if len(links) < 3:
        for a in soup.find_all("a", href=True):
            href = a["href"]
            parsed = urlparse(urljoin(url, href))
            # Heuristic: same domain, path has 2+ segments, looks like an article
            if parsed.netloc == base_domain and parsed.path.count("/") >= 2:
                links.add(href)

    # Resolve and filter links
    resolved = set()
    for href in links:
        full_url = urljoin(url, href)
        parsed = urlparse(full_url)
        # Keep only HTTP(S) links on the same domain
        if parsed.scheme in ("http", "https") and parsed.netloc == base_domain:
            resolved.add(full_url)

    articles = []
    for article_url in list(resolved)[:max_articles]:
        try:
            extracted = _extract_content(article_url, settings)
            if extracted.get("content_text") and extracted["word_count"] > 50:
                extracted["url"] = article_url
                articles.append(extracted)
        except Exception as e:
            logger.warning(f"Failed to extract {article_url}: {e}")

    return articles


def _fetch_search(url: str, settings: dict) -> list:
    """Treat search URLs as RSS feeds (e.g., Google News RSS). Fall back to HTML."""
    try:
        articles = _fetch_rss(url, settings)
        if articles:
            return articles
    except Exception as e:
        logger.warning(f"RSS parse failed for search URL {url}, trying HTML: {e}")

    return _fetch_html(url, settings)


def _extract_content(url: str, settings: dict) -> dict:
    """Fetch a URL and extract article content. Trafilatura primary, readability-lxml fallback."""
    ext_cfg = settings.get("content_extraction", {})
    max_len = ext_cfg.get("max_content_length", 500000)

    response = polite_get(url, settings)
    raw_html = response.text[:max_len]

    # Primary: trafilatura (v2.0+ returns a Document object with attribute access)
    try:
        import trafilatura
        doc = trafilatura.bare_extraction(raw_html, url=url, include_comments=False)
        if doc and doc.text:
            text = doc.text
            pub_date = doc.date
            if pub_date and len(pub_date) == 10:
                pub_date = pub_date + " 00:00:00"
            return {
                "content_text": text,
                "title": doc.title or "",
                "author": doc.author or "",
                "published_date": pub_date,
                "image_url": doc.image or "",
                "word_count": len(text.split()),
                "language": doc.language or "",
            }
    except Exception as e:
        logger.debug(f"Trafilatura failed for {url}: {e}")

    # Fallback: readability-lxml
    try:
        from readability import Document
        doc = Document(raw_html)
        cleaned_html = doc.summary()
        title = doc.title()
        soup = BeautifulSoup(cleaned_html, "lxml")
        text = soup.get_text(separator="\n", strip=True)
        return {
            "content_text": text,
            "title": title,
            "author": "",
            "published_date": None,
            "image_url": "",
            "word_count": len(text.split()),
            "language": "",
        }
    except Exception as e:
        logger.debug(f"Readability fallback also failed for {url}: {e}")

    return {
        "content_text": None,
        "title": "",
        "author": "",
        "published_date": None,
        "image_url": "",
        "word_count": 0,
        "language": "",
    }


def _html_to_text(html: str) -> str:
    """Convert HTML snippet to plain text."""
    soup = BeautifulSoup(html, "lxml")
    return soup.get_text(separator="\n", strip=True)


def _get_entry_image(entry) -> str:
    """Extract lead image URL from a feedparser entry."""
    # Check media:thumbnail
    if hasattr(entry, "media_thumbnail") and entry.media_thumbnail:
        return entry.media_thumbnail[0].get("url", "")
    # Check media:content
    if hasattr(entry, "media_content") and entry.media_content:
        for media in entry.media_content:
            if media.get("medium") == "image" or media.get("type", "").startswith("image/"):
                return media.get("url", "")
    # Check enclosures
    if hasattr(entry, "enclosures") and entry.enclosures:
        for enc in entry.enclosures:
            if enc.get("type", "").startswith("image/"):
                return enc.get("href", "")
    return ""


def _resolve_google_news_url(url: str) -> str:
    """Decode Google News redirect URL to the actual article URL.
    Returns the original URL unchanged if it's not a Google News link."""
    if "news.google.com" not in url:
        return url
    try:
        from googlenewsdecoder import gnewsdecoder
        result = gnewsdecoder(url, interval=None)
        if result.get("status") and result.get("decoded_url"):
            logger.debug(f"  Resolved Google News URL → {result['decoded_url'][:80]}")
            return result["decoded_url"]
    except Exception as e:
        logger.debug(f"  Google News decode failed for {url[:60]}: {e}")
    return url


def refetch_empty(conn, settings: dict, batch_size: int = 500) -> int:
    """Re-download content for articles with empty/null content_text.
    Resolves Google News redirect URLs before extraction.
    Returns count of articles successfully updated."""
    cursor = conn.cursor(dictionary=True)
    cursor.execute(
        """SELECT id, url, title, author, published_date
           FROM articles
           WHERE content_text IS NULL OR word_count IS NULL OR word_count = 0
           ORDER BY fetched_at DESC
           LIMIT %s""",
        (batch_size,),
    )
    rows = cursor.fetchall()
    cursor.close()

    if not rows:
        logger.info("No empty articles to refetch.")
        return 0

    logger.info(f"Attempting to refetch content for {len(rows)} empty articles")
    updated = 0

    for row in rows:
        try:
            # Resolve Google News redirect URLs to actual article URLs
            fetch_url = _resolve_google_news_url(row["url"])

            extracted = _extract_content(fetch_url, settings)
            text = extracted.get("content_text")
            if not text:
                logger.debug(f"  Still no content for article {row['id']}: {fetch_url[:80]}")
                continue

            cursor = conn.cursor()
            # Fill in missing metadata too — preserve existing values if present
            title = extracted.get("title") or row["title"]
            author = extracted.get("author") or row["author"]
            pub_date = extracted.get("published_date") or row["published_date"]
            summary = text[:500]
            word_count = extracted.get("word_count", len(text.split()))

            cursor.execute(
                """UPDATE articles
                   SET content_text = %s, word_count = %s, summary = %s,
                       title = %s, author = %s, published_date = %s
                   WHERE id = %s""",
                (text, word_count, summary, title, author, pub_date, row["id"]),
            )
            conn.commit()
            cursor.close()
            updated += 1
            logger.debug(f"  Refetched article {row['id']}: {word_count} words")

        except Exception as e:
            logger.warning(f"  Failed to refetch article {row['id']} ({row['url'][:60]}): {e}")
            continue

    logger.info(f"Refetch complete: {updated}/{len(rows)} articles updated with content")
    return updated

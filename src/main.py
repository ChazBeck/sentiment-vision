"""
Sentiment Vision - Article Gathering & Analysis Tool

Usage:
    python -m src.main                        # Full run, all clients
    python -m src.main --client "Acme Corp"   # Single client
    python -m src.main --dry-run              # Fetch but don't store
    python -m src.main --verbose              # Debug logging
    python -m src.main --analyze              # Fetch + score unscored articles
    python -m src.main --analyze-only         # Score only, no fetching
    python -m src.main --tag-only             # Tag untagged articles only
    python -m src.main --retag                # Clear and re-tag all articles
    python -m src.main --refetch              # Re-download empty articles, then re-tag
"""
import argparse
import sys
from datetime import datetime, timezone
from pathlib import Path


def get_project_root() -> Path:
    return Path(__file__).resolve().parent.parent


# Only keep articles published in the current year
MIN_YEAR = datetime.now().year


def _is_recent(article: dict) -> bool:
    """Return True if article was published in the current year (or has no date)."""
    pub = article.get("published_date")
    if not pub:
        return True  # keep articles with unknown dates
    try:
        # published_date is a string like "2024-03-15 00:00:00"
        year = int(str(pub)[:4])
        return year >= MIN_YEAR
    except (ValueError, TypeError):
        return True


def main():
    parser = argparse.ArgumentParser(description="Sentiment Vision Article Gatherer")
    parser.add_argument("--client", type=str, help="Run for a single client only")
    parser.add_argument("--dry-run", action="store_true", help="Fetch but do not store")
    parser.add_argument("--verbose", action="store_true", help="Debug-level logging")
    parser.add_argument("--analyze", action="store_true", help="Run sentiment analysis after fetching")
    parser.add_argument("--analyze-only", action="store_true", help="Skip fetching, only run sentiment analysis")
    parser.add_argument("--tag-only", action="store_true", help="Skip fetching, only tag untagged articles")
    parser.add_argument("--retag", action="store_true", help="Clear and re-tag all articles")
    parser.add_argument("--refetch", action="store_true",
                        help="Re-download content for articles with empty content_text, then re-tag")
    args = parser.parse_args()

    root = get_project_root()

    # Load config
    from .config_loader import load_clients, load_global_sources, load_settings
    settings = load_settings(str(root / "config" / "settings.yaml"))
    clients = load_clients(str(root / "config" / "clients.yaml"))
    global_sources = load_global_sources(settings)

    if args.verbose:
        settings.setdefault("logging", {})["level"] = "DEBUG"

    # Setup logging
    from .utils import normalize_url, setup_logging
    logger = setup_logging(settings)
    logger.info("=== Sentiment Vision gather run started ===")

    # Init DB (unless dry run)
    from .storage import init_db, log_fetch, store_article, sync_clients, sync_global_sources, sync_sources
    conn = None
    client_map = {}
    if not args.dry_run:
        try:
            conn = init_db(settings)
            client_map = sync_clients(conn, clients)
        except Exception as e:
            logger.error(f"Database connection failed: {e}")
            sys.exit(1)
    else:
        logger.info("DRY RUN mode -- no database writes")

    # --analyze-only: skip fetching, jump to analysis
    if args.analyze_only:
        if not conn:
            logger.error("--analyze-only requires database connection (not compatible with --dry-run)")
            sys.exit(1)
        from .analyzer import analyze_unscored
        try:
            scored = analyze_unscored(conn, settings)
            logger.info(f"=== Analysis complete. {scored} articles scored ===")
        except Exception as e:
            logger.error(f"Sentiment analysis failed: {e}", exc_info=True)
        conn.close()
        return

    # --tag-only / --retag: skip fetching, run tagging on existing articles
    if args.tag_only or args.retag:
        if not conn:
            logger.error("--tag-only/--retag requires database connection (not compatible with --dry-run)")
            sys.exit(1)
        from .tagger import retag_all, tag_untagged
        tag_func = retag_all if args.retag else tag_untagged
        label = "Re-tagging" if args.retag else "Tagging"
        total_tagged = 0
        for client in clients:
            cid = client_map.get(client.name)
            if cid:
                logger.info(f"{label} articles for {client.name}")
                tagged = tag_func(conn, cid)
                total_tagged += tagged
        logger.info(f"=== {label} complete. {total_tagged} articles tagged ===")
        conn.close()
        return

    # --refetch: re-download content for empty articles, then re-tag
    if args.refetch:
        if not conn:
            logger.error("--refetch requires database connection (not compatible with --dry-run)")
            sys.exit(1)
        from .fetcher import refetch_empty
        from .tagger import retag_all
        updated = refetch_empty(conn, settings)
        if updated > 0:
            logger.info("Re-tagging all articles after refetch...")
            total_tagged = 0
            for client in clients:
                cid = client_map.get(client.name)
                if cid:
                    tagged = retag_all(conn, cid)
                    total_tagged += tagged
            logger.info(f"=== Refetch + retag complete. {updated} refetched, {total_tagged} tagged ===")
        else:
            logger.info("=== No articles refetched, skipping retag ===")
        conn.close()
        return

    # Filter to single client if specified
    if args.client:
        clients = [c for c in clients if c.name == args.client]
        if not clients:
            logger.error(f"Client '{args.client}' not found in config")
            sys.exit(1)

    # Main fetch loop
    from .fetcher import fetch_source
    from .tagger import tag_article
    total_new = 0

    # -----------------------------------------------------------------------
    # Phase 1: Fetch global mass-media sources, keyword-filter per client
    # -----------------------------------------------------------------------
    if global_sources and conn and not args.dry_run:
        logger.info(f"=== Processing {len(global_sources)} global media sources ===")
        global_source_map = sync_global_sources(conn, global_sources)
        global_new = 0

        for gsource in global_sources:
            source_id = global_source_map.get(gsource.url)
            run_start = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
            articles_found = 0
            articles_new = 0
            status = "success"
            error_msg = None

            try:
                logger.info(f"  Fetching global source: {gsource.name} (tier {gsource.media_tier})")
                articles = fetch_source(gsource, settings)
                articles_found = len(articles)
                logger.info(f"  Found {articles_found} articles from {gsource.name}")

                for article in articles:
                    if not _is_recent(article):
                        continue
                    article["url"] = normalize_url(article["url"])
                    text = ((article.get("title") or "") + " " + (article.get("content_text") or "")).lower()

                    # Check each client's keywords
                    for client in clients:
                        cid = client_map[client.name]
                        keywords = [client.name.lower()]
                        keywords += [ind.lower() for ind in client.industries]
                        keywords += [comp.lower() for comp in client.competitors]

                        if any(kw in text for kw in keywords):
                            article_id = store_article(
                                conn, article, cid, source_id,
                                media_tier=gsource.media_tier,
                            )
                            if article_id:
                                articles_new += 1
                                tag_article(conn, article_id, cid, text)
                                logger.debug(
                                    f"    Matched '{article.get('title', '')[:60]}' "
                                    f"→ client {client.name}"
                                )

                global_new += articles_new
                logger.info(
                    f"  Stored {articles_new} keyword-matched articles from {gsource.name}"
                )

            except Exception as e:
                status = "error"
                error_msg = str(e)
                logger.error(f"  ERROR fetching global source {gsource.name}: {e}", exc_info=True)
                continue
            finally:
                if source_id:
                    log_fetch(
                        conn, source_id, run_start,
                        articles_found, articles_new, status, error_msg,
                    )

        total_new += global_new
        logger.info(f"=== Global sources complete. {global_new} new articles ===")

    # -----------------------------------------------------------------------
    # Phase 2: Fetch per-client sources (existing behavior)
    # -----------------------------------------------------------------------
    for client in clients:
        logger.info(f"Processing client: {client.name}")
        client_id = client_map.get(client.name)

        source_map = {}
        if conn:
            source_map = sync_sources(conn, client_id, client.sources)

        for source in client.sources:
            source_id = source_map.get(source.url)
            run_start = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M:%S")
            articles_found = 0
            articles_new = 0
            status = "success"
            error_msg = None

            try:
                logger.info(f"  Fetching source: {source.name} ({source.source_type})")
                articles = fetch_source(source, settings)
                articles_found = len(articles)
                logger.info(f"  Found {articles_found} articles")

                if conn:
                    tier = getattr(source, "media_tier", 3)
                    for article in articles:
                        if not _is_recent(article):
                            continue
                        article["url"] = normalize_url(article["url"])
                        article_id = store_article(conn, article, client_id, source_id, media_tier=tier)
                        if article_id:
                            articles_new += 1
                            text = ((article.get("title") or "") + " "
                                    + (article.get("content_text") or ""))
                            tag_article(conn, article_id, client_id, text)
                    total_new += articles_new
                    logger.info(
                        f"  Stored {articles_new} new articles "
                        f"({articles_found - articles_new} duplicates skipped)"
                    )
                else:
                    for article in articles:
                        logger.debug(
                            f"    [DRY RUN] Would store: {article.get('title', 'Untitled')}"
                        )

            except Exception as e:
                status = "error"
                error_msg = str(e)
                logger.error(f"  ERROR fetching {source.name}: {e}", exc_info=True)
                continue
            finally:
                if conn and source_id:
                    log_fetch(
                        conn, source_id, run_start,
                        articles_found, articles_new, status, error_msg,
                    )

    logger.info(f"=== Fetch complete. Total new articles: {total_new} ===")

    # Sentiment analysis pass (if --analyze flag)
    if args.analyze and conn:
        from .analyzer import analyze_unscored
        try:
            scored = analyze_unscored(conn, settings)
            logger.info(f"=== Analysis complete. {scored} articles scored ===")
        except Exception as e:
            logger.error(f"Sentiment analysis failed: {e}", exc_info=True)

    if conn:
        conn.close()


if __name__ == "__main__":
    main()

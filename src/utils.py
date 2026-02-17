import logging
import time
from logging.handlers import RotatingFileHandler
from pathlib import Path
from urllib.parse import parse_qs, urlencode, urlparse, urlunparse

import requests


# Module-level per-domain delay tracker
_domain_last_request: dict = {}

TRACKING_PARAMS = {
    "utm_source", "utm_medium", "utm_campaign", "utm_term", "utm_content",
    "ref", "fbclid", "gclid", "mc_cid", "mc_eid",
}


def setup_logging(settings: dict) -> logging.Logger:
    """Configure and return the application logger with rotating file + console handlers."""
    log_cfg = settings.get("logging", {})
    logger = logging.getLogger("sentiment_vision")
    logger.setLevel(getattr(logging, log_cfg.get("level", "INFO")))

    # Avoid adding duplicate handlers on repeated calls
    if logger.handlers:
        return logger

    # Ensure log directory exists
    log_file = log_cfg.get("file", "logs/gather.log")
    Path(log_file).parent.mkdir(parents=True, exist_ok=True)

    fh = RotatingFileHandler(
        log_file,
        maxBytes=log_cfg.get("max_bytes", 5 * 1024 * 1024),
        backupCount=log_cfg.get("backup_count", 3),
    )
    fh.setFormatter(logging.Formatter("%(asctime)s [%(levelname)s] %(name)s: %(message)s"))
    logger.addHandler(fh)

    ch = logging.StreamHandler()
    ch.setFormatter(logging.Formatter("%(levelname)s: %(message)s"))
    logger.addHandler(ch)

    return logger


def polite_get(url: str, settings: dict, attempt: int = 0) -> requests.Response:
    """HTTP GET with per-domain rate limiting, retries, timeout, and User-Agent."""
    fetch_cfg = settings.get("fetching", {})
    domain = urlparse(url).netloc
    delay = fetch_cfg.get("per_domain_delay", 2.0)
    timeout = fetch_cfg.get("request_timeout", 15)
    max_retries = fetch_cfg.get("retry_attempts", 2)
    retry_delay = fetch_cfg.get("retry_delay", 5)
    user_agent = fetch_cfg.get("user_agent", "SentimentVisionBot/1.0")

    # Enforce per-domain delay
    last = _domain_last_request.get(domain, 0)
    elapsed = time.time() - last
    if elapsed < delay:
        time.sleep(delay - elapsed)

    try:
        response = requests.get(
            url,
            headers={"User-Agent": user_agent},
            timeout=timeout,
            allow_redirects=True,
        )
        _domain_last_request[domain] = time.time()
        response.raise_for_status()
        return response
    except requests.RequestException:
        if attempt < max_retries:
            time.sleep(retry_delay)
            return polite_get(url, settings, attempt + 1)
        raise


def normalize_url(url: str) -> str:
    """Normalize URL for dedup: lowercase scheme/host, strip tracking params, remove fragment."""
    parsed = urlparse(url)
    query = parse_qs(parsed.query)
    filtered = {k: v for k, v in query.items() if k.lower() not in TRACKING_PARAMS}
    sorted_query = urlencode(filtered, doseq=True)

    return urlunparse((
        parsed.scheme.lower(),
        parsed.netloc.lower(),
        parsed.path.rstrip("/") or "/",
        parsed.params,
        sorted_query,
        "",  # Remove fragment
    ))

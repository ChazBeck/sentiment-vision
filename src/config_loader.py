import os
from dataclasses import dataclass, field
from pathlib import Path
from urllib.parse import quote_plus

import yaml


VALID_SOURCE_TYPES = {"rss", "html", "search"}


@dataclass
class SourceConfig:
    name: str
    source_type: str
    url: str
    media_tier: int = 3  # Default Tier 3 (industry trades)


@dataclass
class GlobalSourceConfig:
    """A global RSS feed not tied to any single client."""
    name: str
    source_type: str  # always "rss"
    url: str
    media_tier: int


@dataclass
class ClientConfig:
    name: str
    industries: list = field(default_factory=list)
    competitors: list = field(default_factory=list)
    sources: list = field(default_factory=list)


def _load_dotenv(project_root: Path) -> None:
    """Read .env file from project root and inject into os.environ (if not already set)."""
    env_path = project_root / ".env"
    if not env_path.exists():
        return
    with open(env_path, "r") as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith("#"):
                continue
            if "=" not in line:
                continue
            key, _, val = line.partition("=")
            key = key.strip()
            val = val.strip()
            # Strip surrounding quotes
            if len(val) >= 2 and val[0] == val[-1] and val[0] in ('"', "'"):
                val = val[1:-1]
            # Don't overwrite vars already set in the real environment
            if key not in os.environ:
                os.environ[key] = val


def load_settings(config_path: str) -> dict:
    """Load global settings from YAML. DB credentials come from .env file."""
    path = Path(config_path)
    if not path.exists():
        raise FileNotFoundError(f"Settings config not found: {config_path}")

    # Load .env into os.environ (project root is one level up from config/)
    _load_dotenv(path.resolve().parent.parent)

    with open(path, "r") as f:
        settings = yaml.safe_load(f)

    # Override DB settings from environment variables (.env or real env)
    db = settings.setdefault("database", {})
    if os.environ.get("DB_HOST"):
        db["host"] = os.environ["DB_HOST"]
    if os.environ.get("DB_PORT"):
        db["port"] = int(os.environ["DB_PORT"])
    if os.environ.get("DB_USER"):
        db["user"] = os.environ["DB_USER"]
    if os.environ.get("DB_PASSWORD"):
        db["password"] = os.environ["DB_PASSWORD"]
    if os.environ.get("DB_NAME"):
        db["database"] = os.environ["DB_NAME"]

    return settings


def load_clients(config_path: str) -> list:
    """Load and validate client definitions from YAML. Returns list of ClientConfig."""
    path = Path(config_path)
    if not path.exists():
        raise FileNotFoundError(f"Client config not found: {config_path}")

    with open(path, "r") as f:
        data = yaml.safe_load(f)

    if not data or "clients" not in data:
        raise ValueError(f"Client config must have a top-level 'clients' key: {config_path}")

    clients = []
    for i, entry in enumerate(data["clients"]):
        name = entry.get("name", "").strip()
        if not name:
            raise ValueError(f"Client at index {i} is missing a 'name'")

        raw_sources = entry.get("sources", [])
        if not raw_sources:
            raise ValueError(f"Client '{name}' has no sources defined")

        sources = []
        for j, s in enumerate(raw_sources):
            stype = s.get("type", "").strip().lower()
            if stype not in VALID_SOURCE_TYPES:
                raise ValueError(
                    f"Client '{name}', source index {j}: "
                    f"invalid type '{stype}'. Must be one of {VALID_SOURCE_TYPES}"
                )
            surl = s.get("url", "").strip()
            if not surl:
                raise ValueError(f"Client '{name}', source index {j}: missing 'url'")
            sname = s.get("name", surl)

            sources.append(SourceConfig(name=sname, source_type=stype, url=surl))

        # Auto-generate Google News search sources for competitors & industries
        existing_urls = {s.url for s in sources}
        for competitor in entry.get("competitors", []):
            url = f"https://news.google.com/rss/search?q={quote_plus(competitor)}"
            if url not in existing_urls:
                sources.append(SourceConfig(
                    name=f"Google News - {competitor}",
                    source_type="search",
                    url=url,
                ))
                existing_urls.add(url)
        for industry in entry.get("industries", []):
            url = f"https://news.google.com/rss/search?q={quote_plus(industry)}"
            if url not in existing_urls:
                sources.append(SourceConfig(
                    name=f"Google News - {industry}",
                    source_type="search",
                    url=url,
                ))
                existing_urls.add(url)

        clients.append(
            ClientConfig(
                name=name,
                industries=entry.get("industries", []),
                competitors=entry.get("competitors", []),
                sources=sources,
            )
        )

    return clients


def load_global_sources(settings: dict) -> list:
    """Load global media sources from settings. Returns list of GlobalSourceConfig."""
    raw = settings.get("global_sources", [])
    if not raw:
        return []

    sources = []
    for i, entry in enumerate(raw):
        name = entry.get("name", "").strip()
        url = entry.get("url", "").strip()
        tier = entry.get("tier", 1)

        if not name or not url:
            raise ValueError(f"Global source at index {i}: missing 'name' or 'url'")
        if tier not in (1, 2, 3, 4):
            raise ValueError(f"Global source '{name}': tier must be 1-4, got {tier}")

        sources.append(GlobalSourceConfig(
            name=name,
            source_type="rss",
            url=url,
            media_tier=tier,
        ))

    return sources

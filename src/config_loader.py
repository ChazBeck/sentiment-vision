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


def load_settings(config_path: str) -> dict:
    """Load global settings from YAML. Supports SV_DB_PASSWORD env var override."""
    path = Path(config_path)
    if not path.exists():
        raise FileNotFoundError(f"Settings config not found: {config_path}")

    with open(path, "r") as f:
        settings = yaml.safe_load(f)

    # Allow env var override for DB password
    env_password = os.environ.get("SV_DB_PASSWORD")
    if env_password:
        settings.setdefault("database", {})["password"] = env_password

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

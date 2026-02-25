"""Reset neutral-scored articles so they can be re-scored by AI.

Usage (on server):
    source venv/bin/activate
    python3 reset_neutral.py
"""

import os
import sys
from pathlib import Path

# Load .env so DB creds are available
env_path = Path(__file__).resolve().parent / ".env"
if env_path.exists():
    for line in env_path.read_text().splitlines():
        line = line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, _, value = line.partition("=")
        key, value = key.strip(), value.strip().strip("'\"")
        if key and key not in os.environ:
            os.environ[key] = value

import mysql.connector

conn = mysql.connector.connect(
    host=os.environ.get("DB_HOST", "localhost"),
    port=int(os.environ.get("DB_PORT", 3306)),
    user=os.environ.get("DB_USER"),
    password=os.environ.get("DB_PASSWORD"),
    database=os.environ.get("DB_NAME"),
)
cursor = conn.cursor()
cursor.execute(
    "UPDATE articles SET sentiment_score = NULL, sentiment_label = NULL, "
    "score_method = NULL, analyzed_at = NULL WHERE sentiment_label = 'neutral'"
)
count = cursor.rowcount
conn.commit()
cursor.close()
conn.close()

print(f"Reset {count} neutral articles. Run 'python3 -m src.main --analyze-only --verbose' to re-score them.")

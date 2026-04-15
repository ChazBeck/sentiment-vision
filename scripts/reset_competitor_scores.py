"""
Null out sentiment_score on articles that mention a competitor name
(per-client), so the analyzer re-scores them under the new subject-aware prompt.

Usage:
    ./venv/bin/python -m scripts.reset_competitor_scores [--dry-run]
"""
import argparse
import json
import sys

from src.config_loader import load_settings
from src.storage import init_db


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--dry-run", action="store_true",
                        help="Report counts but do not modify rows")
    args = parser.parse_args()

    settings = load_settings("config/settings.yaml")
    conn = init_db(settings)
    cursor = conn.cursor(dictionary=True)

    cursor.execute("SELECT id, name, competitors FROM clients")
    clients = cursor.fetchall()

    total = 0
    for client in clients:
        comps = client["competitors"]
        if isinstance(comps, str):
            comps = json.loads(comps)
        if not comps:
            continue

        like_clauses = []
        params = [client["id"]]
        for comp in comps:
            like_clauses.append("(title LIKE %s OR content_text LIKE %s)")
            params.extend([f"%{comp}%", f"%{comp}%"])

        where = (
            "client_id = %s AND score_method = 'ai' AND sentiment_score IS NOT NULL "
            f"AND ({' OR '.join(like_clauses)})"
        )

        cursor.execute(f"SELECT COUNT(*) AS cnt FROM articles WHERE {where}", params)
        count = cursor.fetchone()["cnt"]
        print(f"  {client['name']}: {count} competitor-mentioning AI-scored articles")
        total += count

        if not args.dry_run and count:
            update_cursor = conn.cursor()
            update_cursor.execute(
                f"UPDATE articles SET sentiment_score = NULL, sentiment_label = NULL, "
                f"sentiment_rationale = NULL, sentiment_subject = NULL, analyzed_at = NULL "
                f"WHERE {where}",
                params,
            )
            update_cursor.close()

    if not args.dry_run:
        conn.commit()
        print(f"\nReset {total} articles. Run the analyzer to re-score.")
    else:
        print(f"\n[dry-run] Would reset {total} articles.")

    cursor.close()
    conn.close()


if __name__ == "__main__":
    sys.exit(main())

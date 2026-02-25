"""
AI-powered sentiment scoring using Claude API.

Provides hybrid scoring: called only for articles where VADER
scores in the neutral zone. Falls back to VADER on any failure.

Cost: ~$0.0002 per article using Claude Haiku with 500-word truncation.
"""

import json
import logging
import os
import re

logger = logging.getLogger("sentiment_vision")

# Module-level state
_client = None
_ai_disabled = False
_session_ai_calls = 0
_session_estimated_cost = 0.0

SYSTEM_PROMPT = """You are a media sentiment analyst for PR and reputation monitoring.

You will be given:
- A client company name and their industry
- A list of their competitors
- A news article

Your job:
1. Identify who or what the article is ACTUALLY about (it may be about the client, \
a competitor, the broader industry, or an unrelated entity).
2. Score the article's sentiment impact on the CLIENT COMPANY on a scale of -1.0 to 1.0.

IMPORTANT: Do NOT assume the article is about the client. Many articles will be about \
competitors or industry trends. Your rationale must accurately state who the article \
is about.

Respond with ONLY a JSON object: {"score": <float>, "rationale": "<one sentence>"}

Scoring guide:
- Positive (0.3 to 1.0): Directly benefits the client — favorable coverage, growth, \
wins, partnerships.
- Mild positive (0.1 to 0.3): Indirectly positive — industry tailwinds, competitor \
struggles that may benefit client.
- Neutral (-0.1 to 0.1): No clear impact on the client, or purely factual industry \
reporting.
- Mild negative (-0.3 to -0.1): Indirectly negative — industry headwinds, regulatory \
trends that could affect client.
- Negative (-1.0 to -0.3): Directly harms the client — negative coverage, regulatory \
threats, lawsuits, opposition, operational failures.

Rationale examples:
- "CyrusOne downsizing Yorkville project could reduce competition for Cologix."
- "New EPA water-usage rules pose compliance risk for Cologix."
- "Cologix partnership with AWS signals growth and market confidence.\""""


def _get_client():
    """Lazy-init singleton for the Anthropic client."""
    global _client
    if _client is None:
        import anthropic

        api_key = os.environ.get("ANTHROPIC_API_KEY")
        if not api_key:
            raise ValueError("ANTHROPIC_API_KEY not set in environment")
        _client = anthropic.Anthropic(api_key=api_key)
    return _client


def _truncate_for_api(text: str, max_words: int = 500) -> str:
    """Truncate text to first N words for API cost control."""
    words = text.split()
    if len(words) <= max_words:
        return text
    return " ".join(words[:max_words]) + "..."


def _parse_ai_response(response_text: str, settings: dict):
    """Parse Claude's JSON response into (score, label). Returns None on failure."""
    from .analyzer import score_label

    try:
        # Strip markdown code fences if present
        cleaned = response_text.strip()
        cleaned = re.sub(r"^```(?:json)?\s*", "", cleaned)
        cleaned = re.sub(r"\s*```$", "", cleaned)
        cleaned = cleaned.strip()

        data = json.loads(cleaned)
        score = float(data["score"])
        score = max(-1.0, min(1.0, score))  # Clamp to valid range
        label = score_label(score, settings)

        rationale = data.get("rationale", "")
        if rationale:
            logger.debug(f"  AI rationale: {rationale}")

        return (round(score, 4), label, rationale)
    except (json.JSONDecodeError, KeyError, ValueError, TypeError) as e:
        logger.warning(
            f"Failed to parse AI response: {e} | Raw: {response_text[:200]}"
        )
        return None


def score_with_ai(
    article_row: dict, client_context: dict, settings: dict
) -> dict | None:
    """
    Score an article using Claude API.

    Returns dict with sentiment_score, sentiment_label, score_method on success.
    Returns None on any failure (caller should fall back to VADER).
    """
    global _ai_disabled, _session_ai_calls, _session_estimated_cost

    if _ai_disabled:
        return None

    ai_cfg = settings.get("sentiment", {}).get("ai_scoring", {})

    # Budget check (daily = monthly / 30)
    monthly_budget = ai_cfg.get("monthly_budget_usd", 3.0)
    daily_budget = monthly_budget / 30
    if _session_estimated_cost >= daily_budget:
        logger.warning(
            f"AI scoring budget reached for this session "
            f"(${_session_estimated_cost:.4f} >= ${daily_budget:.4f} daily cap)"
        )
        return None

    text = article_row.get("content_text") or article_row.get("title") or ""
    if not text.strip():
        return None

    max_words = ai_cfg.get("max_words", 500)
    model = ai_cfg.get("model", "claude-haiku-4-5-20251001")
    max_tokens = ai_cfg.get("max_tokens", 100)
    temperature = ai_cfg.get("temperature", 0)

    truncated = _truncate_for_api(text, max_words)
    title = article_row.get("title", "Untitled")
    client_name = client_context.get("name", "Unknown")
    industries = client_context.get("industries", [])
    competitors = client_context.get("competitors", [])

    user_message = (
        f"Client company: {client_name}\n"
        f"Industry: {', '.join(industries)}\n"
        f"Competitors: {', '.join(competitors) if competitors else 'N/A'}\n\n"
        f"Article title: {title}\n\n"
        f"Article text (first {max_words} words):\n{truncated}\n\n"
        f"Score this article's sentiment impact on {client_name}."
    )

    try:
        import anthropic

        client = _get_client()
        response = client.messages.create(
            model=model,
            max_tokens=max_tokens,
            temperature=temperature,
            system=SYSTEM_PROMPT,
            messages=[{"role": "user", "content": user_message}],
        )

        # Track cost
        input_tokens = response.usage.input_tokens
        output_tokens = response.usage.output_tokens
        est_cost = (input_tokens * 0.00000025) + (output_tokens * 0.00000125)
        _session_ai_calls += 1
        _session_estimated_cost += est_cost

        response_text = response.content[0].text
        parsed = _parse_ai_response(response_text, settings)
        if parsed is None:
            return None

        score, label, rationale = parsed
        logger.debug(
            f"  AI scored article {article_row.get('id')}: {score} ({label}) "
            f"[{input_tokens}+{output_tokens} tokens, ${est_cost:.5f}]"
        )
        return {
            "sentiment_score": score,
            "sentiment_label": label,
            "sentiment_rationale": rationale,
            "score_method": "ai",
        }

    except ImportError:
        logger.warning("anthropic package not installed -- AI scoring disabled")
        _ai_disabled = True
        return None
    except Exception as e:
        # Check for specific anthropic exceptions if the module is loaded
        err_type = type(e).__name__
        if err_type == "AuthenticationError":
            logger.error(
                "Invalid ANTHROPIC_API_KEY -- AI scoring disabled for this run"
            )
            _ai_disabled = True
        elif err_type in ("BadRequestError", "NotFoundError"):
            # Billing errors, invalid model, etc. — disable to avoid log spam
            logger.error(
                f"Claude API error ({err_type}): {e} -- AI scoring disabled for this run"
            )
            _ai_disabled = True
        elif err_type == "RateLimitError":
            logger.warning("Claude API rate limit hit -- falling back to VADER")
        elif err_type == "APIConnectionError":
            logger.warning("Claude API unreachable -- falling back to VADER")
        else:
            logger.error(f"Unexpected AI scoring error: {e}", exc_info=True)
        return None


def get_session_stats() -> dict:
    """Return stats about AI scoring for the current session."""
    return {
        "ai_calls": _session_ai_calls,
        "estimated_cost_usd": round(_session_estimated_cost, 6),
    }

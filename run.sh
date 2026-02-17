#!/bin/bash
# Cron wrapper for Sentiment Vision article gatherer
# Activates virtualenv and runs the Python entry point
#
# Crontab example (daily at 6 AM):
#   0 6 * * * /home/charle22/public_html/tools.veerl.es/dev/sentiment_vision/run.sh >> /home/charle22/public_html/tools.veerl.es/dev/sentiment_vision/logs/cron.log 2>&1

# Absolute path to project root
PROJECT_DIR="/home/charle22/public_html/tools.veerl.es/dev/sentiment_vision"

source "$PROJECT_DIR/venv/bin/activate"
cd "$PROJECT_DIR"
python -m src.main --analyze "$@" 2>&1

#!/usr/bin/env bash
# Optional host-side WP-Cron tick (if you prefer crontab over compose service `wp-cron`).
# Example crontab: * * * * * /path/to/plugin-wordpress/docker/wp-cron-host.sh
set -euo pipefail
ROOT="$(cd "$(dirname "$0")" && pwd)"
PORT=8081
if [[ -f "$ROOT/.env" ]]; then
	# shellcheck disable=SC1091
	set -a
	# shellcheck source=/dev/null
	source "$ROOT/.env"
	set +a
	PORT="${WP_HTTP_PORT:-$PORT}"
fi
exec curl -fsS --max-time 120 "http://127.0.0.1:${PORT}/wp-cron.php" -o /dev/null

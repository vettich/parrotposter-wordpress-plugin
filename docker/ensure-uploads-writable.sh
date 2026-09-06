#!/usr/bin/env bash
# Official wordpress image only chowns on first copy. `docker compose exec` is
# root, so test files under wp-content/uploads often end up unwritable by Apache
# (www-data, uid 33). Re-apply ownership on every start.
set -euo pipefail
uploads=/var/www/html/wp-content/uploads
mkdir -p "$uploads"
if [ "$(id -u)" = 0 ]; then
	chown -R www-data:www-data "$uploads"
fi
if [ "$#" -eq 0 ]; then
	set -- apache2-foreground
fi
exec docker-entrypoint.sh "$@"

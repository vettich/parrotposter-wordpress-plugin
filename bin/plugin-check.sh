#!/usr/bin/env bash
# WordPress.org Plugin Check (PCP) against the release zip, via daily docker wpcli.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT/docker"

compose() {
	docker compose --profile cli "$@"
}

echo "=== ensuring daily WordPress is up ==="
docker compose up -d

echo "=== waiting for WordPress ==="
ready=
for _ in $(seq 1 60); do
	if compose run --rm -T wpcli core is-installed >/dev/null 2>&1; then
		ready=1
		break
	fi
	sleep 3
done
if [[ -z "$ready" ]]; then
	echo "WordPress is not installed in the daily docker stack" >&2
	docker compose logs wordpress || true
	exit 1
fi

echo "=== building parrotposter.zip ==="
( cd "$ROOT" && ./bin/make-zip.sh parrotposter.zip >/dev/null )

echo "=== ensuring plugin-check is installed ==="
if compose run --rm -T wpcli plugin is-installed plugin-check >/dev/null 2>&1; then
	compose run --rm -T wpcli plugin activate plugin-check
else
	compose run --rm -T wpcli plugin install plugin-check --activate
fi

# PCP 2.1 accepts an installed slug, a directory, or a zip URL — not a local .zip path.
unpack=$(mktemp -d)
trap 'rm -rf "$unpack"' EXIT
chmod 755 "$unpack"
unzip -q "$ROOT/parrotposter.zip" -d "$unpack"
chmod -R a+rX "$unpack"

echo "=== wp plugin check (mode=update, static+runtime) ==="
compose run --rm -T \
	-v "$unpack:/tmp/pcp-parrotposter:ro" \
	wpcli plugin check /tmp/pcp-parrotposter \
	--slug=parrotposter \
	--mode=update \
	--require=/var/www/html/wp-content/plugins/plugin-check/cli.php

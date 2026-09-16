#!/usr/bin/env bash
# Layer B: ephemeral WP cells. Tears down volumes after each cell.
set -euo pipefail

DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$DIR"

CELLS=(
	'6.9-php8.2-apache|cli-php8.2|pp-compat-69-82'
	'php8.3-apache|cli-php8.3|pp-compat-83'
	'php8.5-apache|cli-php8.5|pp-compat-85'
)

compose() {
	local project="$1"
	shift
	docker compose -p "$project" --project-directory "$DIR" "$@"
}

wait_and_install() {
	local project="$1"
	local i
	for i in $(seq 1 60); do
		if compose "$project" run --rm -T wpcli core is-installed >/dev/null 2>&1; then
			return 0
		fi
		if compose "$project" run --rm -T wpcli core install \
			--url=http://wordpress \
			--title='PP Compat' \
			--admin_user=admin \
			--admin_password=admin \
			--admin_email=compat@example.local \
			--skip-email >/dev/null 2>&1
		then
			compose "$project" run --rm -T wpcli rewrite structure '' --hard >/dev/null 2>&1 || true
			return 0
		fi
		sleep 3
	done
	echo "WordPress did not become ready (${project})" >&2
	compose "$project" logs wordpress || true
	return 1
}

run_cell() {
	local image_tag="$1"
	local cli_tag="$2"
	local project="$3"

	echo "======== cell ${project}  wordpress:${image_tag}  +  wordpress:${cli_tag} ========"
	export WORDPRESS_IMAGE_TAG="$image_tag"
	export WPCLI_IMAGE="$cli_tag"

	compose "$project" down -v --remove-orphans >/dev/null 2>&1 || true
	compose "$project" pull
	compose "$project" up -d

	wait_and_install "$project"
	compose "$project" run --rm -T wpcli core version
	"$DIR/assert.sh" "$project"

	compose "$project" down -v --remove-orphans
	echo "======== cell ${project} OK ========"
}

for spec in "${CELLS[@]}"; do
	IFS='|' read -r image_tag cli_tag project <<<"$spec"
	run_cell "$image_tag" "$cli_tag" "$project"
done

echo "=== compat-wp OK ==="

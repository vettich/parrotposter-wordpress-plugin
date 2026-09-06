#!/usr/bin/env bash
# Runtime checklist for one docker-compat cell. Arg: compose project name.
set -euo pipefail

PROJECT="${1:?compose project name}"
DIR="$(cd "$(dirname "$0")" && pwd)"
PLUGIN_PHP="/var/www/html/wp-content/plugins/parrotposter/docker-compat/php"
SECRET='compat-test-secret'

wp() {
	docker compose -p "$PROJECT" --project-directory "$DIR" run --rm -T \
		-e COMPAT_URL="${COMPAT_URL:-}" \
		-e COMPAT_EXPECT_CODE="${COMPAT_EXPECT_CODE:-}" \
		-e COMPAT_SECRET="${COMPAT_SECRET:-}" \
		-e COMPAT_JSON_KEY="${COMPAT_JSON_KEY:-}" \
		wpcli "$@"
}

fail() {
	echo "FAIL: $*" >&2
	exit 1
}

ok() {
	echo "OK: $*"
}

assert_no_fatals() {
	local step="$1"
	local log
	log="$(wp eval 'echo file_exists(WP_CONTENT_DIR . "/debug.log") ? file_get_contents(WP_CONTENT_DIR . "/debug.log") : "";')"
	if printf '%s' "$log" | grep -E 'PHP Fatal|Parse error|Uncaught' >/dev/null; then
		printf '%s\n' "$log" >&2
		fail "debug.log fatals after ${step}"
	fi
	ok "debug.log clean after ${step}"
}

http() {
	local url="$1"
	local expect="$2"
	local secret="${3:-}"
	local json_key="${4:-}"
	COMPAT_URL="$url" COMPAT_EXPECT_CODE="$expect" COMPAT_SECRET="$secret" COMPAT_JSON_KEY="$json_key" \
		wp eval-file "${PLUGIN_PHP}/http.php"
}

echo "--- activate ---"
wp plugin activate parrotposter
wp plugin is-active parrotposter >/dev/null || fail "plugin not active"
assert_no_fatals activate

echo "--- db version + tables ---"
ver="$(wp option get parrotposter_db_version)"
[[ -n "$ver" ]] || fail "parrotposter_db_version empty"
ok "parrotposter_db_version=${ver}"

tables="$(wp eval-file "${PLUGIN_PHP}/tables.php")"
for t in \
	wp_parrotposter_autoposting \
	wp_parrotposter_posts \
	wp_parrotposter_local_queue \
	wp_parrotposter_exclude_ids \
	wp_parrotposter_outbound_tasks
do
	printf '%s\n' "$tables" | grep -qx "$t" || fail "missing table ${t}"
done
ok "plugin tables present"

echo "--- cron ---"
hooks="$(wp cron event list --fields=hook --format=csv)"
for h in parrotposter_retry_local_queue parrotposter_refresh_domains parrotposter_outbound_lease_tick; do
	printf '%s\n' "$hooks" | grep -qx "$h" || fail "cron hook not scheduled: ${h}"
done
ok "cron hooks scheduled"

echo "--- REST unauth 401 ---"
http 'http://wordpress/?rest_route=/parrotposter/v1/info' 401
assert_no_fatals rest-401

echo "--- REST authed /info and /fields ---"
wp eval 'parrotposter\Settings::set_pp_to_site_secret("compat-test-secret");'
http 'http://wordpress/?rest_route=/parrotposter/v1/info' 200 "$SECRET" post_types
http 'http://wordpress/?rest_route=/parrotposter/v1/fields&post_type=post' 200 "$SECRET" fields
assert_no_fatals rest-200

echo "--- admin login view ---"
wp eval-file "${PLUGIN_PHP}/admin.php"
assert_no_fatals admin

echo "--- publish post ---"
wp post create --post_title='Compat smoke' --post_status=publish --post_content='compat' --porcelain >/dev/null
assert_no_fatals publish

echo "--- outbound cron tick ---"
wp cron event run parrotposter_outbound_lease_tick
assert_no_fatals outbound-cron

echo "--- deactivate / reactivate ---"
wp plugin deactivate parrotposter
wp plugin activate parrotposter
wp plugin is-active parrotposter >/dev/null || fail "plugin not active after reactivate"
assert_no_fatals reactivate

ok "assert.sh complete"

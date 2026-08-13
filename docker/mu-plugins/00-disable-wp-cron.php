<?php
/**
 * Disable WP's HTTP self-spawn cron for the docker stack.
 *
 * Spawn uses siteurl (https://wp-pp.l2.vettich.ru), which often does not resolve
 * inside the container. External tick: compose service `wp-cron` (or host curl
 * to http://127.0.0.1:${WP_HTTP_PORT}/wp-cron.php).
 */
if (!defined('DISABLE_WP_CRON')) {
	define('DISABLE_WP_CRON', true);
}

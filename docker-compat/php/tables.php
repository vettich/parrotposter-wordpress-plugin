<?php
global $wpdb;
$like = $wpdb->esc_like($wpdb->prefix . 'parrotposter_') . '%';
$tables = $wpdb->get_col($wpdb->prepare('SHOW TABLES LIKE %s', $like));
if (!is_array($tables)) {
	fwrite(STDERR, "compat-tables: SHOW TABLES failed\n");
	exit(1);
}
echo implode("\n", $tables) . "\n";

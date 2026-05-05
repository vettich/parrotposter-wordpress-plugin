<?php

namespace parrotposter;

defined('ABSPATH') || exit;

class Install
{
	const VERSION_OPTION = 'parrotposter_db_version';

	public static function init()
	{
		add_action('init', [__CLASS__, 'check_version'], 15);
	}

	/**
	 * DB migrations and cron bootstrap run only in wp-admin, WP-Cron, or WP-CLI — not on public frontend requests.
	 */
	private static function should_run_db_check(): bool
	{
		if (is_admin()) {
			return true;
		}
		if (defined('DOING_CRON') && DOING_CRON) {
			return true;
		}
		if (defined('WP_CLI') && WP_CLI) {
			return true;
		}

		return false;
	}

	public static function check_version()
	{
		if (!self::should_run_db_check()) {
			return;
		}

		$ver = get_option(self::VERSION_OPTION);
		$requires_update = version_compare($ver, PARROTPOSTER_DB_VERSION, '<');

		if (!$ver || $requires_update) {
			self::install();
			do_action('parrotposter_updated');
		}

		if (!$ver) {
			add_option(self::VERSION_OPTION, PARROTPOSTER_DB_VERSION);
		}

		if (!wp_next_scheduled('parrotposter_refresh_domains')) {
			wp_schedule_event(time() + 120, 'hourly', 'parrotposter_refresh_domains');
		}
	}

	public static function install()
	{
		if (get_transient('parrotposter_installing') === 'yes') {
			return;
		}

		set_transient('parrotposter_installing', 'yes', 10 * MINUTE_IN_SECONDS);

		self::create_tables();
		self::update_db_version();

		if (!wp_next_scheduled('parrotposter_retry_local_queue')) {
			wp_schedule_event(time() + 60, 'parrotposter_every_minute', 'parrotposter_retry_local_queue');
		}

		if (!wp_next_scheduled('parrotposter_refresh_domains')) {
			wp_schedule_event(time() + 120, 'hourly', 'parrotposter_refresh_domains');
		}

		delete_transient('parrotposter_installing');

		add_option('parrotposter_install_timestamp', time());
		do_action('parrotposter_installed');
	}

	/**
	 * Полное удаление данных плагина: только при удалении плагина из WordPress (см. uninstall.php).
	 */
	public static function uninstall()
	{
		self::drop_tables();
		delete_option(self::VERSION_OPTION);
		delete_option('parrotposter_install_timestamp');
		delete_option('parrotposter_autoposting_n');
		Options::delete_data();
		DomainCache::delete_persistent();
		delete_option('parrotposter_lq_wake_pending');
	}

	public static function create_tables()
	{
		require_once ABSPATH.'wp-admin/includes/upgrade.php';

		dbDelta(self::get_schema());
	}

	protected static function get_schema()
	{
		global $wpdb;
		$charset_collate = $wpdb->get_charset_collate();

		$tables = "
			CREATE TABLE {$wpdb->prefix}parrotposter_autoposting (
				id bigint(20) unsigned NOT NULL auto_increment,
				user_id bigint(20) unsigned NOT NULL default 0,
				name varchar(255) NOT NULL default '',
				enable boolean NOT NULL default 0,
				wp_post_type varchar(255) NOT NULL default '',
				conditions longtext,
				post_text longtext,
				post_link longtext,
				post_tags longtext,
				post_images longtext,
				utm_enable boolean NOT NULL default 0,
				utm_source longtext,
				utm_medium longtext,
				utm_campaign longtext,
				utm_term longtext,
				utm_content longtext,
				account_ids longtext,
				when_publish varchar(255) NOT NULL default 'immediately',
				publish_delay int NOT NULL default 1,
				exclude_duplicates boolean NOT NULL default 1,
				extra_vk_from_group boolean NOT NULL default 1,
				extra_vk_signed boolean NOT NULL default 0,
				PRIMARY KEY (id),
				KEY user_id (user_id)
			) $charset_collate;

			CREATE TABLE {$wpdb->prefix}parrotposter_posts (
				wp_post_id bigint(20) unsigned NOT NULL,
				post_id varchar(50) NOT NULL,
				autoposting_id bigint(20) unsigned NOT NULL default 0,
				PRIMARY KEY (wp_post_id, post_id)
			) $charset_collate;

			CREATE TABLE {$wpdb->prefix}parrotposter_local_queue (
				id bigint(20) unsigned NOT NULL auto_increment,
				wp_post_id bigint(20) unsigned NOT NULL,
				operation varchar(20) NOT NULL,
				payload longtext NOT NULL,
				status varchar(20) NOT NULL default 'pending',
				attempts int NOT NULL default 0,
				next_attempt_at datetime NOT NULL,
				created_at datetime NOT NULL,
				locked_until datetime NULL default NULL,
				PRIMARY KEY (id),
				KEY idx_lq_pending (status, next_attempt_at),
				KEY idx_lq_processing (status, locked_until),
				UNIQUE KEY idx_lq_dedup (wp_post_id, operation)
			) $charset_collate;
		";

		return $tables;
	}

	public static function get_tables()
	{
		global $wpdb;

		return [
			"{$wpdb->prefix}parrotposter_autoposting",
			"{$wpdb->prefix}parrotposter_posts",
			"{$wpdb->prefix}parrotposter_local_queue",
		];
	}

	protected static function update_db_version($ver = null)
	{
		delete_option(self::VERSION_OPTION);
		add_option(self::VERSION_OPTION, is_null($ver) ? PARROTPOSTER_DB_VERSION : $ver);
	}

	public static function drop_tables()
	{
		global $wpdb;

		$tables = self::get_tables();
		foreach ($tables as $table) {
			$wpdb->query("DROP TABLE IF EXISTS {$table}");
		}
	}
}

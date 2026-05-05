<?php

namespace parrotposter;

defined('ABSPATH') || exit;

class PP
{
	private static $_instance = null;
	public static function get_instance()
	{
		if (self::$_instance == null) {
			self::$_instance = new self;
		}
		return self::$_instance;
	}

	public function __construct() {}

	public static function asset($filename)
	{
		return plugins_url("/assets/$filename", PARROTPOSTER_PLUGIN_FILE);
	}

	public static function isset_asset($filename)
	{
		return file_exists(PARROTPOSTER_PLUGIN_DIR . "/assets/$filename");
	}

	public static function log($data)
	{
		if (!Env::log_enabled()) {
			return;
		}

		$log = [
			'at' => date(DATE_ATOM),
			'data' => $data,
		];
		$s = print_r($log, true);
		error_log($s, 3, PARROTPOSTER_PLUGIN_DIR . 'var.log');
	}

	public function register()
	{
		add_filter('cron_schedules', [__CLASS__, 'add_cron_schedules']);
		add_action('parrotposter_retry_local_queue', [LocalQueue::class, 'retry_via_cron']);
		add_action('parrotposter_refresh_domains', [DomainSelector::class, 'cron_refresh_domains']);

		add_action('plugins_loaded', [$this, 'load_textdomain']);
		add_action('admin_enqueue_scripts', [$this, 'register_scripts']);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_parrotposter_admin_bootstrap'], 5);
		add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_translates'], 100);
		add_action('admin_menu', [$this, 'redirect_to_authorization']);
		add_action('admin_menu', [$this, 'admin_menu']);
		add_action('add_meta_boxes', [$this, 'post_meta_box']);

		AdminAjaxPost::init(false);
		Install::init();
		Scheduler::init();

		register_activation_hook(PARROTPOSTER_PLUGIN_FILE, [$this, 'activation']);
		register_deactivation_hook(PARROTPOSTER_PLUGIN_FILE, [$this, 'deactivation']);
	}

	public static function activation()
	{
		add_filter('cron_schedules', [__CLASS__, 'add_cron_schedules']);
		Install::install();
		if (!wp_next_scheduled('parrotposter_retry_local_queue')) {
			wp_schedule_event(time() + 60, 'parrotposter_every_minute', 'parrotposter_retry_local_queue');
		}
		if (!wp_next_scheduled('parrotposter_refresh_domains')) {
			wp_schedule_event(time() + 120, 'hourly', 'parrotposter_refresh_domains');
		}
	}

	public static function deactivation()
	{
		wp_clear_scheduled_hook('parrotposter_retry_local_queue');
		wp_clear_scheduled_hook('parrotposter_refresh_domains');
	}

	/**
	 * @param array<string, array{interval: int, display: string}> $schedules
	 * @return array<string, array{interval: int, display: string}>
	 */
	public static function add_cron_schedules(array $schedules): array
	{
		if (!isset($schedules['parrotposter_every_minute'])) {
			$schedules['parrotposter_every_minute'] = [
				'interval' => 60,
				'display' => 'Every minute (ParrotPoster)',
			];
		}

		return $schedules;
	}

	public function load_textdomain()
	{
		load_plugin_textdomain('parrotposter', false, dirname(plugin_basename(PARROTPOSTER_PLUGIN_FILE)) . '/languages');
	}

	public function register_scripts()
	{
		// parrotposter css files
		wp_enqueue_style('parrotposter-admin-menu', self::asset('css/admin-menu.css'));

		// parrotposter js files
		wp_register_script('parrotposter-admin-bootstrap', self::asset('js/admin-bootstrap.js'), ['jquery'], PARROTPOSTER_VERSION, true);
		wp_localize_script(
			'parrotposter-admin-bootstrap',
			'ParrotPosterAdmin',
			[
				'ajaxNonce' => wp_create_nonce('parrotposter_ajax'),
			]
		);

		wp_register_script('parrotposter-post-meta-box', self::asset('js/post-meta-box.js'), ['jquery', 'parrotposter-admin-bootstrap'], PARROTPOSTER_VERSION, true);

		wp_register_script('parrotposter-iframe', self::asset('js/iframe.js'), [], PARROTPOSTER_VERSION, true);

		wp_register_style('parrotposter-view-embed', self::asset('css/view-embed.css'), [], PARROTPOSTER_VERSION);
		wp_register_script('parrotposter-view-embed', self::asset('js/view-embed.js'), ['parrotposter-iframe'], PARROTPOSTER_VERSION, true);

		wp_register_script('parrotposter-main-script', false, ['jquery', 'parrotposter-admin-bootstrap'], PARROTPOSTER_VERSION, true);

		AssetModules::register();
	}

	/**
	 * Экраны админки, где нужны nonce и скрипты ParrotPoster.
	 */
	public static function is_parrotposter_admin_context(): bool
	{
		if (!is_admin()) {
			return false;
		}
		global $pagenow;
		if ($pagenow === 'post.php' || $pagenow === 'post-new.php') {
			return true;
		}

		return isset($_GET['page']) && is_string($_GET['page']) && strpos(sanitize_text_field(wp_unslash($_GET['page'])), 'parrotposter') === 0;
	}

	public function enqueue_parrotposter_admin_bootstrap()
	{
		if (!self::is_parrotposter_admin_context()) {
			return;
		}
		wp_enqueue_script('parrotposter-admin-bootstrap');
	}

	public function enqueue_admin_translates()
	{
		AssetModules::enqueue_script_translates();
	}

	public function redirect_to_authorization()
	{
		global $pagenow;

		$pp_page_start = 'parrotposter';
		$is_pp_page = $pagenow === 'admin.php' && isset($_GET['page']) && substr(sanitize_text_field(wp_unslash($_GET['page'])), 0, strlen($pp_page_start)) === $pp_page_start;
		if (!$is_pp_page) {
			return;
		}

		$is_home_page = isset($_GET['page']) && sanitize_text_field(wp_unslash($_GET['page'])) === 'parrotposter';
		$is_authorized = !empty(Options::user_id());

		// OAuth: обмен code → token (GraphQL), только для администраторов сайта.
		if (!$is_authorized && $is_home_page && !empty($_GET['code']) && current_user_can('manage_options')) {
			$code = sanitize_text_field(wp_unslash($_GET['code']));
			if ($code !== '') {
				$ex = Api::exchange_auth_code($code);
				if (empty($ex['error']) && !empty($ex['token'])) {
					$prev_uid = Options::user_id();
					$prev_tok = Options::token();
					$token = $ex['token'];
					Options::set_user_data('', $token);
					$valid = Api::validate_token();
					if (!empty($valid['error'])) {
						Options::set_user_data($prev_uid, $prev_tok);
					} else {
						list($user, $err) = Api::me();
						if (!empty($err) || empty($user)) {
							Options::set_user_data($prev_uid, $prev_tok);
						} else {
							$uid = isset($user['id']) ? (string) $user['id'] : '';
							if ($uid === '') {
								Options::set_user_data($prev_uid, $prev_tok);
							} else {
								Options::set_user_data($uid, $token);
								$is_authorized = true;
								wp_safe_redirect(
									remove_query_arg(
										['code', 'state', 'error', 'error_description'],
										admin_url('admin.php?page=parrotposter_posts')
									)
								);
								exit;
							}
						}
					}
				}
				wp_safe_redirect(remove_query_arg(['code', 'state', 'error', 'error_description'], admin_url('admin.php?page=parrotposter')));
				exit;
			}
		}

		if ($is_authorized && $is_home_page) {
			wp_safe_redirect(admin_url('admin.php?page=parrotposter_posts'));
			exit;
		}
		if (!$is_authorized && !$is_home_page) {
			wp_safe_redirect(admin_url('admin.php?page=parrotposter'));
			exit;
		}
	}

	public function admin_menu()
	{
		if (empty(Options::user_id())) {
			add_menu_page(__('ParrotPoster settings page', 'parrotposter'), __('ParrotPoster', 'parrotposter'), 'manage_options', 'parrotposter', [$this, 'admin_page'], self::asset('images/icon.png'), 100);
			add_submenu_page('parrotposter', __('Authorization', 'parrotposter'), __('Authorization', 'parrotposter'), 'manage_options', 'parrotposter', [$this, 'admin_page']);
			return;
		}

		$main_item = Menu::get_main_item();
		if (!$main_item) {
			return;
		}
		add_menu_page($main_item['label'], 'ParrotPoster', 'manage_options', $main_item['id'], [$this, 'admin_page'], self::asset('images/icon.png'), 100);

		foreach (Menu::get_items() as $item) {
			add_submenu_page($main_item['id'], $item['label'], $item['label'], 'manage_options', $item['id'], [$this, 'admin_page']);
		}
	}

	public function admin_page()
	{
		$page = isset($_GET['page']) ? sanitize_text_field(wp_unslash($_GET['page'])) : 'parrotposter';
		$view = isset($_GET['view']) ? sanitize_text_field(wp_unslash($_GET['view'])) : 'index';
		$prefix = 'parrotposter_';
		if (substr($page, 0, strlen($prefix)) == $prefix) {
			$page = substr($page, strlen($prefix));
		}

		if (!self::is_safe_view_segment($page) || !self::is_safe_view_segment($view)) {
			$page = 'parrotposter';
			$view = 'index';
		}

		self::include_view("$page/$view");
	}

	/**
	 * Один сегмент пути к шаблону (без / и ..) для admin_page().
	 */
	private static function is_safe_view_segment($segment)
	{
		if ($segment === '' || strlen($segment) > 64) {
			return false;
		}
		return (bool) preg_match('/^[a-zA-Z0-9_-]+$/', $segment);
	}

	public static function include_view($name, $view_args = [])
	{
		wp_enqueue_script('parrotposter-main-script');

		$rel = (string) $name;
		if ($rel === '' || strpos($rel, "\0") !== false) {
			wp_die(esc_html__('Invalid view path.', 'parrotposter'), '', ['response' => 400]);
		}

		$views_dir = wp_normalize_path(PARROTPOSTER_PLUGIN_DIR . 'views');
		$base = realpath($views_dir);
		if ($base === false) {
			wp_die(esc_html__('ParrotPoster views directory is missing.', 'parrotposter'), '', ['response' => 500]);
		}
		$base = trailingslashit(wp_normalize_path($base));

		$candidate = wp_normalize_path($views_dir . '/' . $rel . '.php');
		$resolved = realpath($candidate);
		if ($resolved === false) {
			wp_die(esc_html__('View not found.', 'parrotposter'), '', ['response' => 404]);
		}
		$resolved = wp_normalize_path($resolved);
		if (strpos($resolved, $base) !== 0) {
			wp_die(esc_html__('Invalid view path.', 'parrotposter'), '', ['response' => 403]);
		}

		require_once $resolved;
	}

	public function post_meta_box()
	{
		$post_id = (isset($_GET['post']) && (int) $_GET['post'] > 0) ? (int) $_GET['post'] : 0;
		if (empty($post_id)) {
			return;
		}
		$post_types = WpPostHelpers::get_post_types();
		add_meta_box('parrotposter-post-meta-box', 'ParrotPoster', [$this, 'post_meta_box_view'], $post_types, 'side', 'high');
	}

	public function post_meta_box_view()
	{
		self::include_view('post-meta-box');
	}
}

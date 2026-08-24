<?php

namespace parrotposter;

defined('ABSPATH') || exit;

class AdminAjaxPost
{
	private static $instance = null;

	public static function get_instance()
	{
		if (self::$instance == null) {
			self::$instance = new self;
		}
		return self::$instance;
	}

	public static function init($textdomain = true)
	{
		self::get_instance();
		if ($textdomain) {
			PP::get_instance()->load_textdomain();
		}
	}

	public function __construct()
	{
		// auth
		add_action('admin_post_parrotposter_auth', [$this, 'auth']);
		add_action('admin_post_parrotposter_auth2', [$this, 'auth2']);
		add_action('admin_post_parrotposter_signup', [$this, 'signup']);
		add_action('admin_post_parrotposter_forgot_password', [$this, 'forgot_password']);
		add_action('admin_post_parrotposter_reset_password', [$this, 'reset_password']);
		add_action('admin_post_parrotposter_logout', [$this, 'logout']);
		add_action('admin_post_parrotposter_save_settings', [$this, 'save_settings']);

		// tariffs
		add_action('admin_post_parrotposter_set_tariff', [$this, 'set_tariff']);
		add_action('admin_post_parrotposter_create_transaction', [$this, 'create_transaction']);

		// posts
		add_action('admin_post_parrotposter_publish_post', [$this, 'publish_post']);
		add_action('wp_ajax_parrotposter_get_post_html', [$this, 'get_post_html']);

		// scheduler
		add_action('admin_post_parrotposter_autoposting_add', [$this, 'autoposting_add']);
		add_action('admin_post_parrotposter_autoposting_edit', [$this, 'autoposting_edit']);
		add_action('wp_ajax_parrotposter_autoposting_enable', [$this, 'autoposting_enable']);
		add_action('wp_ajax_parrotposter_publish_post_via_template', [$this, 'publish_post_via_template']);
		add_action('wp_ajax_parrotposter_has_post_duplicates', [$this, 'has_post_duplicates']);
		add_action('wp_ajax_parrotposter_local_queue_list', [$this, 'local_queue_list']);
		add_action('wp_ajax_parrotposter_process_local_queue_admin', [$this, 'process_local_queue_admin']);

		// api access
		add_action('wp_ajax_parrotposter_api_list_posts', [$this, 'api_list_posts']);
		add_action('wp_ajax_parrotposter_api_list_posts_by_wp_post', [$this, 'api_list_posts_by_wp_post']);
		add_action('wp_ajax_parrotposter_api_get_post', [$this, 'api_get_post']);
		add_action('wp_ajax_parrotposter_api_delete_post', [$this, 'api_delete_post']);
		add_action('wp_ajax_parrotposter_api_list_accounts', [$this, 'api_list_accounts']);
		add_action('wp_ajax_parrotposter_api_get_connect_url', [$this, 'api_get_connect_url']);
		add_action('wp_ajax_parrotposter_api_connect', [$this, 'api_connect']);
		add_action('wp_ajax_parrotposter_api_delete_account', [$this, 'api_delete_account']);
		add_action('wp_ajax_parrotposter_api_get_me', [$this, 'api_get_me']);
		add_action('wp_ajax_parrotposter_api_create_transaction', [$this, 'api_create_transaction']);

		// ParrotPoster server callback: process local queue (hook token, no WP user session).
		add_action('wp_ajax_nopriv_parrotposter_process_local_queue', [$this, 'process_local_queue']);
		add_action('wp_ajax_parrotposter_process_local_queue', [$this, 'process_local_queue']);

		// Session token refresh for the iframe (triggered via postMessage from the front-end).
		add_action('wp_ajax_parrotposter_refresh_session_token', [$this, 'refresh_session_token']);

		// Reconnect the site after a remote (PP-side) disable — clears stale local secrets and
		// re-binds (triggered via postMessage from the iframe pipelines-list banner).
		add_action('wp_ajax_parrotposter_reconnect_plugin', [$this, 'reconnect_plugin']);

		// Pipeline source bridge (WP-11): source-descriptor-root / -frame / field-schema
		// over the postMessage bridge, reusing WireProtocol's /info and /fields logic
		// directly (no HTTP loopback) — DEC-002-06 D3.
		add_action('wp_ajax_parrotposter_bridge_source_descriptor_root', [$this, 'bridge_source_descriptor_root']);
		add_action('wp_ajax_parrotposter_bridge_source_descriptor_frame', [$this, 'bridge_source_descriptor_frame']);
		add_action('wp_ajax_parrotposter_bridge_field_schema', [$this, 'bridge_field_schema']);
		add_action('wp_ajax_parrotposter_bridge_list_preview_items', [$this, 'bridge_list_preview_items']);

		// Pipeline migration (SPEC-002-17).
		add_action('wp_ajax_pp_migrate_to_pipeline', [$this, 'migrate_to_pipeline']);
		add_action('wp_ajax_pp_revert_migration', [$this, 'revert_migration']);
		add_action('wp_ajax_pp_dismiss_migration_banner', [$this, 'dismiss_migration_banner']);
	}

	/**
	 * Issues a fresh short-lived session token for the iframe.
	 * Called via AJAX from the parent WP admin page when the iframe signals token expiry.
	 */
	public function refresh_session_token(): void
	{
		self::ajax_guard();
		nocache_headers();
		header('Content-Type: application/json; charset=UTF-8');
		$res = Api::issue_session_key();
		echo wp_json_encode($res);
		exit;
	}

	/**
	 * Re-binds the site after a remote (PP-side) disable.
	 *
	 * `Settings::disconnect()` clears the stale local plugin_id/secrets first — otherwise
	 * `PluginConnect::silent_bind()` would short-circuit on `Settings::is_connected()` still
	 * being true (it only checks local state, not whether PP still honors those secrets) and
	 * never actually re-bind.
	 */
	public function reconnect_plugin(): void
	{
		self::ajax_guard();
		nocache_headers();
		header('Content-Type: application/json; charset=UTF-8');

		Settings::disconnect();
		$bind = PluginConnect::silent_bind();

		echo wp_json_encode([
			'success' => empty($bind['error']),
			'error' => $bind['error'] ?? null,
		]);
		exit;
	}

	/**
	 * Bridge: source-descriptor-root (SPEC-002-02 §3.3, WP-11) — same post_type set
	 * as REST `/info.post_types`, wrapped as a root `SourceStepDescriptor`. Called
	 * via postMessage `source_descriptor_root` from the embedded front-app iframe
	 * when PP backend can't reach the site directly (DEC-002-06 D3).
	 */
	public function bridge_source_descriptor_root(): void
	{
		self::ajax_guard();
		nocache_headers();
		header('Content-Type: application/json; charset=UTF-8');

		echo wp_json_encode([
			'key' => 'post_type',
			'label' => __('Post type', 'parrotposter'),
			'options_mode' => 'inline',
			'options' => WireProtocol::post_type_options(),
			'requires_child_selection' => false,
			'children' => null,
		]);
		exit;
	}

	/**
	 * Bridge: source-descriptor-frame (WP-11). WP's source tree is flat — the
	 * root's `post_type` step is always a leaf (SPEC-002-02 §3.3.3), unlike
	 * Bitrix's `iblock_type -> iblock` nesting (§3.3.4 example). The front-app
	 * should not call this per the §3.3.3 decision tree (root has no `children`
	 * and `requires_child_selection` is false), but the op is implemented as a
	 * stub for wire completeness: it always reports "no further step".
	 */
	public function bridge_source_descriptor_frame(): void
	{
		self::ajax_guard();
		nocache_headers();
		header('Content-Type: application/json; charset=UTF-8');

		echo wp_json_encode([
			'descriptor' => null,
		]);
		exit;
	}

	/**
	 * Bridge: field-schema (WP-11) — calls the same internal WireProtocol logic
	 * as REST `/fields` (WP-04) directly, no HTTP loopback onto this site's own
	 * REST route. Accepts either a full `source_path` (JSON-encoded
	 * `SourceSelectionStep[]`, matching the wire `field_schema` op payload) or a
	 * bare `post_type` for convenience. Optional `locale`/`lang` (PP UI language)
	 * matches `GET /fields?locale=` — labels only.
	 */
	public function bridge_field_schema(): void
	{
		self::ajax_guard();
		nocache_headers();
		header('Content-Type: application/json; charset=UTF-8');

		$post_type = '';
		if (isset($_POST['source_path'])) {
			$raw = wp_unslash($_POST['source_path']);
			$source_path = is_string($raw) ? json_decode($raw, true) : $raw;
			if (is_array($source_path)) {
				$post_type = WireProtocol::post_type_from_source_path($source_path);
			}
		}
		if ($post_type === '' && isset($_POST['post_type']) && is_string($_POST['post_type'])) {
			$post_type = sanitize_key(wp_unslash($_POST['post_type']));
		}

		$locale = null;
		if (isset($_POST['locale']) && is_string($_POST['locale'])) {
			$locale = wp_unslash($_POST['locale']);
		} elseif (isset($_POST['lang']) && is_string($_POST['lang'])) {
			$locale = wp_unslash($_POST['lang']);
		}

		$result = WireProtocol::field_schema_for_post_type($post_type, $locale);
		if (is_wp_error($result)) {
			$data = $result->get_error_data();
			status_header(is_array($data) && isset($data['status']) ? (int) $data['status'] : 400);
			echo wp_json_encode([
				'error' => [
					'code' => $result->get_error_code(),
					'message' => $result->get_error_message(),
				],
			]);
			exit;
		}

		echo wp_json_encode($result);
		exit;
	}

	/**
	 * Bridge: latest source items for template preview — same internals as REST
	 * `GET /items/latest` (WP-05), no HTTP loopback. Used when the embedded
	 * front-app iframe cannot reach the site through PP backend (DEC-002-06 D3).
	 *
	 * POST: `source_path` (JSON SourceSelectionStep[]), optional `post_type`,
	 * `limit`, `offset`, `filter` (JSON Expression AST — compact domain shape,
	 * same as REST `?filter=`), `required_fields` (JSON string array).
	 */
	public function bridge_list_preview_items(): void
	{
		self::ajax_guard();
		nocache_headers();
		header('Content-Type: application/json; charset=UTF-8');

		$post_type = '';
		if (isset($_POST['source_path'])) {
			$raw = wp_unslash($_POST['source_path']);
			$source_path = is_string($raw) ? json_decode($raw, true) : $raw;
			if (is_array($source_path)) {
				$post_type = WireProtocol::post_type_from_source_path($source_path);
			}
		}
		if ($post_type === '' && isset($_POST['post_type']) && is_string($_POST['post_type'])) {
			$post_type = sanitize_key(wp_unslash($_POST['post_type']));
		}

		$limit = isset($_POST['limit']) ? (int) $_POST['limit'] : 5;
		$offset = isset($_POST['offset']) ? (int) $_POST['offset'] : 0;

		$filter = null;
		if (isset($_POST['filter'])) {
			$raw_filter = wp_unslash($_POST['filter']);
			if (is_string($raw_filter) && $raw_filter !== '') {
				$decoded = json_decode($raw_filter, true);
				$filter = is_array($decoded) ? $decoded : null;
			} elseif (is_array($raw_filter)) {
				$filter = $raw_filter;
			}
		}

		$required_fields = [];
		if (isset($_POST['required_fields'])) {
			$raw_fields = wp_unslash($_POST['required_fields']);
			if (is_string($raw_fields) && $raw_fields !== '') {
				$decoded = json_decode($raw_fields, true);
				$required_fields = is_array($decoded) ? $decoded : [];
			} elseif (is_array($raw_fields)) {
				$required_fields = $raw_fields;
			}
		}

		$pipeline_id = null;
		if (isset($_POST['pipeline_id']) && is_string($_POST['pipeline_id'])) {
			$pipeline_id = sanitize_text_field(wp_unslash($_POST['pipeline_id']));
			if ($pipeline_id === '') {
				$pipeline_id = null;
			}
		}

		$result = WireProtocol::items_latest_for_post_type(
			$post_type,
			$limit,
			$offset,
			$filter,
			$required_fields,
			$pipeline_id
		);
		if (is_wp_error($result)) {
			$data = $result->get_error_data();
			status_header(is_array($data) && isset($data['status']) ? (int) $data['status'] : 400);
			echo wp_json_encode([
				'error' => [
					'code' => $result->get_error_code(),
					'message' => $result->get_error_message(),
				],
			]);
			exit;
		}

		echo wp_json_encode($result);
		exit;
	}

	/**
	 * Migrate legacy autoposting templates to PP pipelines (GraphQL migratePluginToPipeline).
	 */
	public function migrate_to_pipeline(): void
	{
		self::ajax_guard();
		nocache_headers();
		header('Content-Type: application/json; charset=UTF-8');

		if (Settings::plugin_id() === '') {
			$bind = PluginConnect::silent_bind();
			if (!empty($bind['error'])) {
				$msg = is_string($bind['error']) ? $bind['error'] : 'plugin bind failed';
				echo wp_json_encode(['success' => false, 'error' => $msg]);
				exit;
			}
		}

		$mode = isset($_POST['mode']) ? sanitize_text_field(wp_unslash((string) $_POST['mode'])) : 'import';
		$config_ids = null;
		if ($mode === 'fresh') {
			$config_ids = [];
		} elseif (isset($_POST['config_ids']) && is_array($_POST['config_ids'])) {
			$config_ids = [];
			foreach ($_POST['config_ids'] as $id) {
				$config_ids[] = sanitize_text_field(wp_unslash((string) $id));
			}
		}

		if ($mode === 'import' && is_array($config_ids) && $config_ids === []) {
			echo wp_json_encode([
				'success' => false,
				'error' => __('Select at least one template to migrate.', 'parrotposter'),
			]);
			exit;
		}

		$result = MigrationService::migrate_to_pipeline($config_ids);
		echo wp_json_encode($result);
		exit;
	}

	/**
	 * Revert pipeline migration (GraphQL revertPluginToLegacy).
	 */
	public function revert_migration(): void
	{
		self::ajax_guard();
		nocache_headers();
		header('Content-Type: application/json; charset=UTF-8');

		if (Settings::plugin_id() === '') {
			$bind = PluginConnect::silent_bind();
			if (!empty($bind['error'])) {
				$msg = is_string($bind['error']) ? $bind['error'] : 'plugin bind failed';
				echo wp_json_encode(['success' => false, 'error' => $msg]);
				exit;
			}
		}

		$result = MigrationService::revert_migration();
		echo wp_json_encode($result);
		exit;
	}

	/**
	 * Hide the pipeline-active migration banner for the current admin.
	 */
	public function dismiss_migration_banner(): void
	{
		self::ajax_guard();
		nocache_headers();
		header('Content-Type: application/json; charset=UTF-8');

		UserPreferences::set_show_migration_banner(false);
		echo wp_json_encode(['success' => true]);
		exit;
	}

	public function save_settings(): void
	{
		FormHelpers::must_be_post_nonce();
		if (!current_user_can('manage_options')) {
			FormHelpers::post_error('forbidden');
		}
		self::init();

		$show = isset($_POST['parrotposter_show_migration_banner'])
			&& sanitize_text_field(wp_unslash((string) $_POST['parrotposter_show_migration_banner'])) === '1';
		UserPreferences::set_show_migration_banner($show);

		FormHelpers::post_success('', 'admin.php?page=parrotposter_settings');
	}

	/**
	 * HTTP callback from ParrotPoster post-queue worker.
	 */
	public function process_local_queue(): void
	{
		nocache_headers();
		header('Content-Type: application/json; charset=UTF-8');

		if (!Api::check_hook_token()) {
			status_header(403);
			echo wp_json_encode(['error' => ['msg' => 'unauthorized', 'code' => 'REMOTE_AUTH']]);
			exit;
		}

		$result = LocalQueue::handle_http_process();
		echo wp_json_encode($result);
		exit;
	}

	/**
	 * Admin UI: run local queue on this site (no post-queue / scheduler wake).
	 */
	public function process_local_queue_admin(): void
	{
		self::ajax_guard();

		$queue_id = isset($_POST['queue_id']) ? (int) $_POST['queue_id'] : 0;
		if ($queue_id > 0) {
			$result = LocalQueue::process_queue_row($queue_id);
		} else {
			$result = LocalQueue::process_pending_admin();
		}

		if (empty($result['ok'])) {
			$code = isset($result['error']) ? (string) $result['error'] : 'error';
			FormHelpers::post_error($code);
		}

		FormHelpers::post_success($result);
	}

	/**
	 * Проверка прав и AJAX-nonce (parrotposter_ajax) или nonce формы (parrotposter_nonce в parrotposter[]).
	 */
	private static function ajax_guard(): void
	{
		if (!current_user_can('manage_options')) {
			status_header(403);
			echo wp_json_encode(['error' => 'forbidden']);
			exit;
		}
		$ajax_ok = isset($_REQUEST['nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_REQUEST['nonce'])), 'parrotposter_ajax');
		$form_ok = isset($_POST['parrotposter']['nonce']) && wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['parrotposter']['nonce'])), 'parrotposter_nonce');
		if (!$ajax_ok && !$form_ok) {
			status_header(403);
			echo wp_json_encode(['error' => 'bad_nonce']);
			exit;
		}
	}

	private static function api_error($error)
	{
		return json_encode(['error' => $error]);
		exit;
	}

	private static function api_response($resp, $list = [], $flags = 0)
	{
		// like php code:
		//     list($var1, $var2) = $resp;
		if (!empty($list)) {
			$result = [];
			foreach ($list as $i => $k) {
				if (isset($resp[$i])) {
					$result[$k] = $resp[$i];
				} else {
					$result[$k] = null;
				}
			}
			echo json_encode($result, $flags);
			exit;
		}

		echo json_encode($resp, $flags);
		exit;
	}

	public function auth()
	{
		FormHelpers::must_be_post_nonce();
		if (!current_user_can('manage_options')) {
			FormHelpers::post_error('forbidden');
		}
		self::init();

		$username = sanitize_text_field($_POST['parrotposter']['username']);
		$password = sanitize_text_field($_POST['parrotposter']['password']);

		if (empty($username)) {
			FormHelpers::post_error(__('Email is empty', 'parrotposter'));
		}

		if (empty($password)) {
			FormHelpers::post_error(__('Password is empty', 'parrotposter'));
		}

		$res = Api::login($username, $password);
		if (!empty($res['error'])) {
			FormHelpers::post_error($res['error']);
		}
		// Force a fresh bind so a site previously disabled on PP gets reactivated on relogin
		// (silent_bind() would otherwise short-circuit on stale local "connected" state).
		Settings::disconnect();
		PluginConnect::silent_bind();
		FormHelpers::post_success('logged');
	}

	public function auth2()
	{
		FormHelpers::must_be_post_nonce();
		if (!current_user_can('manage_options')) {
			echo 'forbidden';
			exit;
		}
		self::init(false);

		$user_id = sanitize_text_field(wp_unslash($_POST['parrotposter']['user_id'] ?? ''));
		$token = sanitize_text_field(wp_unslash($_POST['parrotposter']['token'] ?? ''));

		$prev_uid = Options::user_id();
		$prev_tok = Options::token();

		Options::set_user_data($user_id, $token);
		$valid = Api::validate_token();
		if (!empty($valid['error'])) {
			Options::set_user_data($prev_uid, $prev_tok);
			echo 'error';
			exit;
		}

		list($user, $err) = Api::me();
		if (!empty($err) || empty($user)) {
			Options::set_user_data($prev_uid, $prev_tok);
			echo 'error';
			exit;
		}

		$api_uid = isset($user['id']) ? (string) $user['id'] : '';
		if ($api_uid !== (string) $user_id) {
			Options::set_user_data($prev_uid, $prev_tok);
			echo 'error';
			exit;
		}

		// Force a fresh bind so a site previously disabled on PP gets reactivated on relogin
		// (silent_bind() would otherwise short-circuit on stale local "connected" state).
		Settings::disconnect();
		PluginConnect::silent_bind();

		echo 'ok';
		exit;
	}

	public function signup()
	{
		FormHelpers::must_be_post_nonce();
		if (!current_user_can('manage_options')) {
			FormHelpers::post_error('forbidden');
		}
		self::init();

		$name = sanitize_text_field($_POST['parrotposter']['name']);
		$username = sanitize_text_field($_POST['parrotposter']['username']);
		$password = sanitize_text_field($_POST['parrotposter']['password']);
		$confirm_password = sanitize_text_field($_POST['parrotposter']['confirm_password']);

		if (empty($username)) {
			FormHelpers::post_error(__('Email is empty', 'parrotposter'));
		}

		if (empty($password)) {
			FormHelpers::post_error(__('Password is empty', 'parrotposter'));
		}
		if ($password != $confirm_password) {
			FormHelpers::post_error(__('Passwords do not match', 'parrotposter'));
		}

	$res = Api::signup($name, $username, $password);
		if (!empty($res['error'])) {
			FormHelpers::post_error($res['error']);
		}
		PluginConnect::silent_bind();
		FormHelpers::post_success('logged');
	}

	public function forgot_password()
	{
		FormHelpers::must_be_post_nonce();
		if (!current_user_can('manage_options')) {
			FormHelpers::post_error('forbidden');
		}
		self::init();

		$username = sanitize_text_field($_POST['parrotposter']['username']);

		if (empty($username)) {
			FormHelpers::post_error(__('Email is empty', 'parrotposter'));
		}

		$callback_url = add_query_arg([
			'page' => 'parrotposter',
			'view' => 'reset_password',
			'parrotposter_token' => '{{token}}',
		], admin_url('admin.php'));

		$res = Api::forgot_password($username, $callback_url);
		if (!empty($res['error'])) {
			FormHelpers::post_error($res['error']);
		}
		FormHelpers::post_success(__('An email with a link to recover your password was sent to your email.', 'parrotposter'));
	}

	public function reset_password()
	{
		FormHelpers::must_be_post_nonce();
		if (!current_user_can('manage_options')) {
			FormHelpers::post_error('forbidden');
		}
		self::init();

		$token = sanitize_text_field($_POST['parrotposter']['token']);
		$password = sanitize_text_field($_POST['parrotposter']['password']);
		$confirm_password = sanitize_text_field($_POST['parrotposter']['confirm_password']);

		if (empty($token)) {
			FormHelpers::post_error(__('Token is empty', 'parrotposter'));
		}

		if (empty($password)) {
			FormHelpers::post_error(__('Password is empty', 'parrotposter'));
		}
		if ($password != $confirm_password) {
			FormHelpers::post_error(__('Passwords do not match', 'parrotposter'));
		}

		$res = Api::reset_password($token, $password);
		if (!empty($res['error'])) {
			FormHelpers::post_error($res['error']);
		}
		FormHelpers::post_success('true');
	}

	public function logout()
	{
		FormHelpers::must_be_post_nonce();
		if (!current_user_can('manage_options')) {
			FormHelpers::post_error('forbidden');
		}

		$plugin_id = Settings::plugin_id();
		if ($plugin_id !== '') {
			Api::disable_plugin($plugin_id);
		}

		Api::logout();
		Settings::disconnect();
		FormHelpers::post_success();
	}

	public function set_tariff()
	{
		FormHelpers::must_be_post_nonce();
		if (!current_user_can('manage_options')) {
			FormHelpers::post_error('forbidden');
		}

		$tariff_id = sanitize_text_field($_POST['parrotposter']['tariff_id']);

		$res = Api::set_user_tariff($tariff_id);
		if (!empty($res['error'])) {
			FormHelpers::post_error($res['error']);
		}
		FormHelpers::post_success();
	}

	public function create_transaction()
	{
		FormHelpers::must_be_post_nonce();
		if (!current_user_can('manage_options')) {
			FormHelpers::post_error('forbidden');
		}

		$tariff_id = sanitize_text_field($_POST['parrotposter']['tariff_id']);
		$period = sanitize_text_field($_POST['parrotposter']['period']);

		$success_url = add_query_arg([
			'page' => 'parrotposter',
			'subpage' => 'tariff_success_payed',
		], admin_url('admin.php'));

		$fail_url = add_query_arg([
			'page' => 'parrotposter',
			'subpage' => 'tariff_fail_payed',
		], admin_url('admin.php'));

		$res = Api::create_transaction($tariff_id, $period, $success_url, $fail_url);
		if (isset($res['response']['payment_url'])) {
			wp_redirect(esc_url_raw($res['response']['payment_url']));
			exit;
		}
		if (!empty($res['error'])) {
			FormHelpers::post_error($res['error']);
		}
		FormHelpers::post_success();
	}

	public function publish_post()
	{
		FormHelpers::must_be_post_nonce();
		if (!current_user_can('manage_options')) {
			FormHelpers::post_error('forbidden');
		}

		$post_id = sanitize_text_field($_POST['parrotposter']['post_id']);
		$text = sanitize_textarea_field($_POST['parrotposter']['post_text']);
		$link = esc_url_raw($_POST['parrotposter']['post_link']);

		$images = [];
		$images_ids = (isset($_POST['parrotposter']['images_ids']) && is_array($_POST['parrotposter']['images_ids']))
			? $_POST['parrotposter']['images_ids']
			: [];
		foreach ($images_ids as $id) {
			$id = sanitize_text_field($id);
			$attached_file = get_attached_file($id);
			if (empty($attached_file)) {
				continue;
			}
			$res = Api::upload_file($attached_file);
			PP::log($res);
			$file_id = ApiHelpers::retrieve_response($res, 'file_id');
			PP::log($file_id);
			if (empty($file_id)) {
				continue;
			}
			$images[] = $file_id;
		}

		$when_publish = sanitize_text_field($_POST['parrotposter']['when_publish']);
		$publish_at = null;
		$publish_delay_minutes = null;
		switch ($when_publish) {
			case 'now':
				$publish_at = ApiHelpers::formatCurrentDatetime();
				break;
			case 'post_date':
				$publish_at = ApiHelpers::formatISO8601Datetime(get_the_date('c', $post_id));
				break;
			case 'delay':
				$delay = intval($_POST['parrotposter']['publish_delay']);
				if ($delay < 1) {
					$delay = 1;
				} elseif ($delay > 10) {
					$delay = 10;
				}
				$publish_delay_minutes = $delay;
				break;
			case 'custom':
				$specific_time = sanitize_text_field($_POST['parrotposter']['specific_time']);
				$publish_at = ApiHelpers::formatISO8601Datetime($specific_time);
				break;
		}

		$account_ids = (isset($_POST['parrotposter']['account_ids']) && is_array($_POST['parrotposter']['account_ids']))
			? $_POST['parrotposter']['account_ids']
			: [];
		foreach ($account_ids as $k => $id) {
			$account_ids[$k] = sanitize_text_field($id);
		}

		$extra_vk_signed = intval($_POST['parrotposter']['extra_vk_signed']);
		$extra_vk_from_group = intval($_POST['parrotposter']['extra_vk_from_group']);

		$post = [
			'fields' => [
				'text' => $text,
				'link' => $link,
				'images' => $images,
				'extra' => [
					'wp_post_id' => intval($post_id),
					'wp_domain' => WpPostHelpers::get_site_domain(),
					'vk_signed' => !!$extra_vk_signed,
					'vk_from_group' => !!$extra_vk_from_group,
				]
			],
			'networks' => [
				'accounts' => $account_ids,
			]
		];
		if ($publish_delay_minutes !== null) {
			$post['publish_delay_minutes'] = $publish_delay_minutes;
		} else {
			$post['publish_at'] = $publish_at;
		}

		$res = Api::create_post($post);
		if (!empty($res['error'])) {
			FormHelpers::post_error($res['error']);
		}
		FormHelpers::post_success();
	}

	private function autoposting_data()
	{
		return FormHelpers::prepare_data_values([
			'name:text:limit=255' => '%s',
			'enable:number' => '%d',
			'wp_post_type' => '%s',
			'conditions:raw' => '%s',
			'post_text:textarea' => '%s',
			'post_link' => '%s',
			'post_tags' => '%s',
			'post_images:text_array:remove_empty' => '%s',
			'utm_enable:number' => '%d',
			'utm_source' => '%s',
			'utm_medium' => '%s',
			'utm_campaign' => '%s',
			'utm_term' => '%s',
			'utm_content' => '%s',
			'account_ids:raw' => '%s',
			'when_publish' => '%s',
			'publish_delay:number:min=1:max=10' => '%d',
			'exclude_duplicates:number' => '%d',
			'extra_vk_from_group:number' => '%d',
			'extra_vk_signed:number' => '%d',
		], $_POST['parrotposter']);
	}

	public function autoposting_add()
	{
		FormHelpers::must_be_post_nonce();
		if (!current_user_can('manage_options')) {
			FormHelpers::post_error('forbidden');
		}

		if (isset($_POST['parrotposter']['apply'])) {
			set_transient('parrotposter_autoposting_add_data', $_POST['parrotposter'], MINUTE_IN_SECONDS);
			FormHelpers::post_success('', $_POST['back_url']);
		}

		list($data, $format) = $this->autoposting_data();
		$err = DBAutopostingTable::insert($data, $format);
		if (!empty($err)) {
			set_transient('parrotposter_autoposting_add_data', $data, MINUTE_IN_SECONDS);
			FormHelpers::post_error($err);
		}

		$n = intval(sanitize_text_field($_POST['parrotposter']['increment']));
		if ($n > 0) {
			update_option('parrotposter_autoposting_n', $n, false);
		}

		FormHelpers::post_success();
	}

	public function autoposting_edit()
	{
		FormHelpers::must_be_post_nonce();
		if (!current_user_can('manage_options')) {
			FormHelpers::post_error('forbidden');
		}

		if (isset($_POST['parrotposter']['apply'])) {
			set_transient('parrotposter_autoposting_edit_data', $_POST['parrotposter'], MINUTE_IN_SECONDS);
			FormHelpers::post_success('', $_POST['back_url']);
		}

		$id = intval(sanitize_text_field($_POST['parrotposter']['id']));
		list($data, $format) = $this->autoposting_data();
		$err = DBAutopostingTable::update($id, $data, $format);
		if (!empty($err)) {
			set_transient('parrotposter_autoposting_edit_data', $data, MINUTE_IN_SECONDS);
			FormHelpers::post_error($err);
		}

		FormHelpers::post_success();
	}

	public function autoposting_enable()
	{
		self::ajax_guard();

		$err = DBAutopostingTable::switch_enable(
			$_POST['parrotposter']['id'],
			$_POST['parrotposter']['enable']
		);
		if (!empty($err)) {
			FormHelpers::post_error($err);
		}

		FormHelpers::post_success();
	}

	public function has_post_duplicates()
	{
		self::ajax_guard();

		$wp_post_id = isset($_POST['parrotposter']['wp_post_id']) ? absint($_POST['parrotposter']['wp_post_id']) : 0;
		$template_ids = isset($_POST['parrotposter']['template_ids']) && is_array($_POST['parrotposter']['template_ids'])
			? $_POST['parrotposter']['template_ids']
			: [];
		$results = Scheduler::last_publish_at_for_templates($wp_post_id, $template_ids);

		FormHelpers::post_success($results);
	}

	public function local_queue_list()
	{
		self::ajax_guard();

		FormHelpers::post_success([
			'items' => LocalQueue::list_for_admin(),
			'pending_count' => LocalQueue::get_pending_count(),
			'active_count' => LocalQueue::get_active_count(),
			'wake_pending' => LocalQueue::get_wake_pending_flag(),
		]);
	}

	public function publish_post_via_template()
	{
		self::ajax_guard();

		$wp_post_id = $_POST['parrotposter']['wp_post_id'];
		$template_id = $_POST['parrotposter']['template_id'];

		$wp_post = get_post($wp_post_id);
		$template = DBAutopostingTable::get_by_id($template_id);
		Scheduler::get_instance()->publish_post_by_template_without_check($wp_post, $template, false);

		FormHelpers::post_success('true');
	}

	public function get_post_html()
	{
		self::ajax_guard();
		status_header(501);
		echo wp_json_encode(['error' => 'not_implemented']);
		exit;
	}

	public function api_list_posts()
	{
		self::ajax_guard();
		if (isset($_POST['parrotposter']) && !is_array($_POST['parrotposter'])) {
			FormHelpers::post_error('wrong input data');
		}
		$filter = [];
		if (is_array($_POST['parrotposter']['filter'])) {
			foreach ($_POST['parrotposter']['filter'] as $k => $v) {
				$filter[$k] = sanitize_text_field($v);
			}
		}

		$sort = [];
		if (is_array($_POST['parrotposter']['sort'])) {
			foreach ($_POST['parrotposter']['sort'] as $k => $v) {
				$sort[$k] = sanitize_text_field($v);
			}
		}

		$paging = [];
		if (is_array($_POST['parrotposter']['paging'])) {
			foreach ($_POST['parrotposter']['paging'] as $k => $v) {
				$paging[$k] = sanitize_text_field($v);
			}
		}

		$res = Api::list_posts($filter, $sort, $paging);
		echo json_encode($res);
		exit;
	}

	public function api_list_posts_by_wp_post()
	{
		self::ajax_guard();
		if (isset($_POST['parrotposter']) && !is_array($_POST['parrotposter'])) {
			FormHelpers::post_error('wrong input data');
		}

		$filter = [
			'user_id' => Options::user_id(),
			'fields.extra.wp_post_id' => intval($_POST['parrotposter']['wp_post_id']),
		];

		$res = Api::list_posts($filter, [], [
			'page' => 1,
			'size' => 50,
			'skip_total' => true,
		]);
		if (!empty($res['response']['posts'])) {
			foreach ($res['response']['posts'] as $i => $post) {
				$res['response']['posts'][$i]['status_view'] = ApiHelpers::get_post_status_text($post['status']);
			}
		}
		echo json_encode($res);
		exit;
	}

	public function api_get_post()
	{
		self::ajax_guard();
		FormHelpers::must_be_right_input_data();
		$post_id = sanitize_text_field($_POST['parrotposter']['post_id']);
		list($post, $error) = Api::get_post($post_id);
		if (empty($error)) {
			$format = get_option('date_format') . ' ' . get_option('time_format');
			$post['publish_at_view'] = wp_date($format, ApiHelpers::getTimestamp($post['publish_at']));
		}
		echo json_encode([
			'post' => $post,
			'error' => $error,
		]);
		exit;
	}

	public function api_delete_post()
	{
		self::ajax_guard();
		FormHelpers::must_be_right_input_data();
		$post_id = sanitize_text_field($_POST['parrotposter']['post_id']);
		$res = Api::delete_post($post_id);
		echo json_encode($res);
		exit;
	}

	public function api_list_accounts()
	{
		self::ajax_guard();
		list($accounts, $error) = Api::list_accounts();
		$accounts = ApiHelpers::fix_accounts_photos($accounts);
		self::api_response([$accounts, $error], ['accounts', 'error']);
	}

	public function api_get_connect_url()
	{
		self::ajax_guard();
		$type = sanitize_text_field($_POST['parrotposter']['type']);
		$callback_url = esc_url_raw($_POST['parrotposter']['callback_url']);
		$res = Api::get_connect_url($type, $callback_url);
		echo json_encode($res);
		exit;
	}

	public function api_connect()
	{
		self::ajax_guard();
		$type = sanitize_text_field($_POST['parrotposter']['type']);
		$fields = [];
		if (isset($_POST['parrotposter']['username'])) {
			$fields['username'] = sanitize_text_field($_POST['parrotposter']['username']);
		}
		if (isset($_POST['parrotposter']['password'])) {
			$fields['password'] = sanitize_text_field($_POST['parrotposter']['password']);
		}
		if (isset($_POST['parrotposter']['proxy'])) {
			$fields['proxy'] = sanitize_text_field($_POST['parrotposter']['proxy']);
		}
		if (isset($_POST['parrotposter']['code'])) {
			$fields['code'] = sanitize_text_field($_POST['parrotposter']['code']);
		}
		if (isset($_POST['parrotposter']['bot_token'])) {
			$fields['bot_token'] = sanitize_text_field($_POST['parrotposter']['bot_token']);
		}
		$res = Api::connect($type, $fields);
		$res['need_challenge_txt'] = __('Need enter a code from SMS or email', 'parrotposter');
		echo json_encode($res);
		exit;
	}

	public function api_delete_account()
	{
		self::ajax_guard();
		FormHelpers::must_be_right_input_data();
		$id = sanitize_text_field($_POST['parrotposter']['account_id']);
		$res = Api::delete_account($id);
		echo json_encode($res, JSON_FORCE_OBJECT);
		exit;
	}

	public function api_get_me()
	{
		self::ajax_guard();
		list($user, $error) = Api::me();
		if (!empty($error)) {
			self::api_error($error);
		}

		$accounts_cur_cnt = $user['tariff_limits']['accounts_current_cnt'];
		$accounts_cnt = $user['tariff_limits']['accounts_cnt'];

		$connect_disabled = false;
		if ($accounts_cur_cnt >= $accounts_cnt) {
			$connect_disabled = true;
		}

		self::api_response([
			'user' => $user,
			'connect_btn_disabled' => $connect_disabled,
			'accounts_badge_txt' => sprintf(__('Added %1$d of %2$d.', 'parrotposter'), $accounts_cur_cnt, $accounts_cnt),
		]);
	}

	public function api_create_transaction()
	{
		self::ajax_guard();
		FormHelpers::must_be_right_input_data();
		$tariff_id = sanitize_text_field($_POST['parrotposter']['tariff_id']);
		$period = sanitize_text_field($_POST['parrotposter']['period']);
		$success_url = add_query_arg([
			'page' => 'parrotposter_tariffs',
			'view' => 'success',
		], admin_url('admin.php'));
		$fail_url = add_query_arg([
			'page' => 'parrotposter_tariffs',
			'view' => 'fail',
		], admin_url('admin.php'));

		$res = Api::create_transaction($tariff_id, $period, $success_url, $fail_url);
		echo json_encode($res);
		exit;
	}
}

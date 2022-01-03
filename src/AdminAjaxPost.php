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
		add_action('admin_post_parrotposter_signup', [$this, 'signup']);
		add_action('admin_post_parrotposter_forgot_password', [$this, 'forgot_password']);
		add_action('admin_post_parrotposter_reset_password', [$this, 'reset_password']);
		add_action('admin_post_parrotposter_logout', [$this, 'logout']);

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
		FormHelpers::post_success('logged');
	}

	public function signup()
	{
		FormHelpers::must_be_post_nonce();
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
		FormHelpers::post_success('logged');
	}

	public function forgot_password()
	{
		FormHelpers::must_be_post_nonce();
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
		Api::logout();
		FormHelpers::post_success();
	}

	public function set_tariff()
	{
		FormHelpers::must_be_post_nonce();

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

		$post_id = sanitize_text_field($_POST['parrotposter']['post_id']);
		$text = sanitize_textarea_field($_POST['parrotposter']['post_text']);
		$link = esc_url_raw($_POST['parrotposter']['post_link']);

		$images = [];
		$images_ids = $_POST['parrotposter']['images_ids']; // it is array, sanitizing below
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
		switch ($when_publish) {
		case 'now':
			$publish_at = ApiHelpers::datetimeFormat('now');
			break;
		case 'post_date':
			$publish_at = ApiHelpers::datetimeFormat(get_the_date('c', $post_id));
			break;
		case 'delay':
			$delay = intval($_POST['parrotposter']['publish_delay']);
			if ($delay < 1) {
				$delay = 1;
			} elseif ($delay > 10) {
				$delay = 10;
			}
			$publish_at = ApiHelpers::datetimeFormat("+{$delay} minutes");
			break;
		case 'custom':
			$specific_time = sanitize_text_field($_POST['parrotposter']['specific_time']);
			$publish_at = ApiHelpers::datetimeFormat($specific_time);
			break;
		}

		$account_ids = $_POST['parrotposter']['account_ids'];
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
			'publish_at' => $publish_at,
			'networks' => [
				'accounts' => $account_ids,
			]
		];

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
		FormHelpers::must_be_post_nonce();

		$err = DBAutopostingTable::switch_enable(
			$_POST['parrotposter']['id'],
			$_POST['parrotposter']['enable']
		);
		if (!empty($err)) {
			FormHelpers::post_error($err);
		}

		FormHelpers::post_success();
	}

	public function api_list_posts()
	{
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
		if (isset($_POST['parrotposter']) && !is_array($_POST['parrotposter'])) {
			FormHelpers::post_error('wrong input data');
		}

		$filter = [
			'user_id' => Options::user_id(),
			'fields.extra.wp_post_id' => intval($_POST['parrotposter']['wp_post_id']),
		];

		$res = Api::list_posts($filter);
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
		FormHelpers::must_be_right_input_data();
		$post_id = sanitize_text_field($_POST['parrotposter']['post_id']);
		list($post, $error) = Api::get_post($post_id);
		if (empty($error)) {
			$format = get_option('date_format').' '.get_option('time_format');
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
		FormHelpers::must_be_right_input_data();
		$post_id = sanitize_text_field($_POST['parrotposter']['post_id']);
		$res = Api::delete_post($post_id);
		echo json_encode($res);
		exit;
	}

	public function api_list_accounts()
	{
		list($accounts, $error) = Api::list_accounts();
		$accounts = ApiHelpers::fix_accounts_photos($accounts);
		self::api_response([$accounts, $error], ['accounts', 'error']);
	}

	public function api_get_connect_url()
	{
		$type = sanitize_text_field($_POST['parrotposter']['type']);
		$callback_url = esc_url_raw($_POST['parrotposter']['callback_url']);
		$res = Api::get_connect_url($type, $callback_url);
		echo json_encode($res);
		exit;
	}

	public function api_connect()
	{
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
		FormHelpers::must_be_right_input_data();
		$id = sanitize_text_field($_POST['parrotposter']['account_id']);
		$res = Api::delete_account($id);
		echo json_encode($res, JSON_FORCE_OBJECT);
		exit;
	}

	public function api_get_me()
	{
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

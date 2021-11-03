<?php

namespace parrotposter;

use ParrotPoster;

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

		// api access
		add_action('wp_ajax_parrotposter_api_list_posts', [$this, 'api_list_posts']);
		add_action('wp_ajax_parrotposter_api_get_post', [$this, 'api_get_post']);
		add_action('wp_ajax_parrotposter_api_delete_post', [$this, 'api_delete_post']);
		add_action('wp_ajax_parrotposter_api_list_accounts', [$this, 'api_list_accounts']);
		add_action('wp_ajax_parrotposter_api_get_connect_url', [$this, 'api_get_connect_url']);
		add_action('wp_ajax_parrotposter_api_connect', [$this, 'api_connect']);
		add_action('wp_ajax_parrotposter_api_delete_account', [$this, 'api_delete_account']);
		add_action('wp_ajax_parrotposter_api_get_me', [$this, 'api_get_me']);
		add_action('wp_ajax_parrotposter_api_create_transaction', [$this, 'api_create_transaction']);
	}

	public function init()
	{
		ParrotPoster::get_instance()->load_textdomain();
	}

	public function auth()
	{
		FormHelpers::must_be_post_nonce();
		self::init();

		$username = sanitize_text_field($_POST['parrotposter']['username']);
		$password = sanitize_text_field($_POST['parrotposter']['password']);

		if (empty($username)) {
			FormHelpers::post_error(parrotposter__('Email is empty'));
		}

		if (empty($password)) {
			FormHelpers::post_error(parrotposter__('Password is empty'));
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
			FormHelpers::post_error(parrotposter__('Email is empty'));
		}

		if (empty($password)) {
			FormHelpers::post_error(parrotposter__('Password is empty'));
		}
		if ($password != $confirm_password) {
			FormHelpers::post_error(parrotposter__('Passwords do not match'));
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
			FormHelpers::post_error(parrotposter__('Email is empty'));
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
		FormHelpers::post_success(parrotposter__('An email with a link to recover your password was sent to your email.'));
	}

	public function reset_password()
	{
		FormHelpers::must_be_post_nonce();
		self::init();

		$token = sanitize_text_field($_POST['parrotposter']['token']);
		$password = sanitize_text_field($_POST['parrotposter']['password']);
		$confirm_password = sanitize_text_field($_POST['parrotposter']['confirm_password']);

		if (empty($token)) {
			FormHelpers::post_error(parrotposter__('Token is empty'));
		}

		if (empty($password)) {
			FormHelpers::post_error(parrotposter__('Password is empty'));
		}
		if ($password != $confirm_password) {
			FormHelpers::post_error(parrotposter__('Passwords do not match'));
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
		$text = sanitize_textarea_field($_POST['parrotposter']['text']);
		$link = sanitize_url($_POST['parrotposter']['link']);
		$images_ids = $_POST['parrotposter']['images_ids']; // it is array, sanitizing below
		$publish_at = sanitize_text_field($_POST['parrotposter']['publish_at']);
		$publish_at_2 = sanitize_text_field($_POST['parrotposter']['publish_at_2']);
		$accounts = $_POST['parrotposter']['accounts']; // it is array, sanitizing below

		$images = [];
		foreach ($images_ids as $id) {
			$id = sanitize_text_field($id);
			$attached_file = get_attached_file($id);
			if (empty($attached_file)) {
				continue;
			}
			$res = Api::upload_file($attached_file);
			ParrotPoster::log($res);
			$file_id = ApiHelpers::retrieve_response($res, 'file_id');
			ParrotPoster::log($file_id);
			if (empty($file_id)) {
				continue;
			}
			$images[] = $file_id;
		}

		if (empty($publish_at)) {
			$publish_at = $publish_at_2;
		}
		$publish_at = ApiHelpers::datetimeFormat($publish_at);

		foreach ($accounts as $k => $id) {
			$accounts[$k] = sanitize_text_field($id);
		}

		$post = [
			'fields' => [
				'text' => $text,
				'link' => $link,
				'images' => $images,
				'extra' => [
					'wp_post_id' => $post_id,
				]
			],
			'publish_at' => $publish_at,
			'networks' => [
				'accounts' => $accounts,
			]
		];

		$res = Api::create_post($post);
		if (!empty($res['error'])) {
			FormHelpers::post_error($res['error']);
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

	public function api_get_post()
	{
		FormHelpers::must_be_right_input_data();
		$post_id = sanitize_text_field($_POST['parrotposter']['post_id']);
		$res = Api::get_post($post_id);
		echo json_encode($res);
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
		$res = Api::list_accounts();
		echo json_encode($res);
		exit;
	}

	public function api_get_connect_url()
	{
		$type = sanitize_text_field($_POST['parrotposter']['type']);
		$callback_url = sanitize_url($_POST['parrotposter']['callback_url']);
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
		$res['need_challenge_txt'] = parrotposter__('Need enter a code from SMS or email');
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
		$res = Api::me();
		$user = $res['response'] ?: [];
		if (isset($res['error'])) {
			echo json_encode($res);
			exit;
		}

		$accounts_cur_cnt = $user['tariff_limits']['accounts_current_cnt'];
		$accounts_cnt = $user['tariff_limits']['accounts_cnt'];

		$connect_disabled = false;
		if ($accounts_cur_cnt >= $accounts_cnt) {
			$connect_disabled = true;
		}

		echo json_encode([
			'user' => $user,
			'connect_btn_disabled' => $connect_disabled,
			'accounts_badge_txt' => parrotposter__('Added %s of %s.', $accounts_cur_cnt, $accounts_cnt),
		]);
		exit;
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

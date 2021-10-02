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
		add_action('wp_ajax_parrotposter_api_remove_post', [$this, 'api_remove_post']);
		add_action('wp_ajax_parrotposter_api_list_accounts', [$this, 'api_list_accounts']);
	}

	public function init()
	{
		ParrotPoster::load_textdomain();
	}

	public function auth()
	{
		self::init();
		if (!FormHelpers::check_post_nonce()) {
			FormHelpers::post_error('nonce');
		}

		$username = trim($_POST['parrotposter']['username']);
		$password = trim($_POST['parrotposter']['password']);

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
		self::init();
		if (!FormHelpers::check_post_nonce()) {
			FormHelpers::post_error('nonce');
		}

		$name = trim($_POST['parrotposter']['name']);
		$username = trim($_POST['parrotposter']['username']);
		$password = trim($_POST['parrotposter']['password']);
		$confirm_password = trim($_POST['parrotposter']['confirm_password']);

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
		self::init();
		if (!FormHelpers::check_post_nonce()) {
			FormHelpers::post_error('nonce');
		}

		$username = trim($_POST['parrotposter']['username']);

		if (empty($username)) {
			FormHelpers::post_error(parrotposter__('Email is empty'));
		}

		$callback_url = add_query_arg([
			'page' => 'parrotposter',
			'subpage' => 'reset_password',
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
		self::init();
		if (!FormHelpers::check_post_nonce()) {
			FormHelpers::post_error('nonce');
		}

		$token = trim($_POST['parrotposter']['token']);
		$password = trim($_POST['parrotposter']['password']);
		$confirm_password = trim($_POST['parrotposter']['confirm_password']);

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
		if (!FormHelpers::check_post_nonce()) {
			FormHelpers::post_error('nonce');
		}
		Api::logout();
		FormHelpers::post_success();
	}

	public function set_tariff()
	{
		if (!FormHelpers::check_post_nonce()) {
			FormHelpers::post_error('nonce');
		}

		$tariff_id = trim($_POST['parrotposter']['tariff_id']);

		$res = Api::set_user_tariff($tariff_id);
		if (!empty($res['error'])) {
			FormHelpers::post_error($res['error']);
		}
		FormHelpers::post_success();
	}

	public function create_transaction()
	{
		if (!FormHelpers::check_post_nonce()) {
			FormHelpers::post_error('nonce');
		}

		$tariff_id = trim($_POST['parrotposter']['tariff_id']);
		$period = trim($_POST['parrotposter']['period']);

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
		if (!FormHelpers::check_post_nonce()) {
			FormHelpers::post_error('nonce');
		}

		$post_id = trim($_POST['parrotposter']['post_id']);
		$text = trim($_POST['parrotposter']['text']);
		$link = trim($_POST['parrotposter']['link']);
		$images_ids = $_POST['parrotposter']['images_ids'];
		$publish_at = trim($_POST['parrotposter']['publish_at']);
		$publish_at_2 = trim($_POST['parrotposter']['publish_at_2']);
		$accounts = $_POST['parrotposter']['accounts'];

		$images = [];
		foreach ($images_ids as $id) {
			$attached_file = get_attached_file($id);
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
		$filter = $_POST['parrotposter']['filter'];
		$sort = $_POST['parrotposter']['sort'];
		$paging = $_POST['parrotposter']['paging'];
		$res = Api::list_posts($filter, $sort, $paging);
		echo json_encode($res);
		exit;
	}

	public function api_get_post()
	{
		$post_id = $_POST['parrotposter']['post_id'];
		$res = Api::get_post($post_id);
		echo json_encode($res);
		exit;
	}

	public function api_remove_post()
	{
		$post_id = $_POST['parrotposter']['post_id'];
		$res = Api::remove_post($post_id);
		echo json_encode($res);
		exit;
	}

	public function api_list_accounts()
	{
		$res = Api::list_accounts();
		echo json_encode($res);
		exit;
	}
}

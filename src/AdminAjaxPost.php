<?php

namespace parrotposter;

use ParrotPoster;

class AdminAjaxPost {

	static private $instance = null;

	public static function get_instance()
	{
		if (self::$instance == null) {
			self::$instance = new self;
		}
		return self::$instance;
	}

	public function __construct()
	{
		add_action('admin_post_parrotposter_auth', [$this, 'auth']);
		add_action('admin_post_parrotposter_signup', [$this, 'signup']);
		add_action('admin_post_parrotposter_forgot_password', [$this, 'forgot_password']);
		add_action('admin_post_parrotposter_reset_password', [$this, 'reset_password']);
		add_action('admin_post_parrotposter_logout', [$this, 'logout']);

		add_action('admin_post_parrotposter_set_tariff', [$this, 'set_tariff']);
		add_action('admin_post_parrotposter_create_transaction', [$this, 'create_transaction']);
	}

	public function auth()
	{
		if (!FormHelpers::check_post_nonce()) {
			FormHelpers::post_error('nonce');
		}

		$username = trim($_POST['parrotposter']['username']);
		$password = trim($_POST['parrotposter']['password']);

		if (empty($username)) {
			FormHelpers::post_error(parrotposter__('Username is empty'));
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
		if (!FormHelpers::check_post_nonce()) {
			FormHelpers::post_error('nonce');
		}

		$name = trim($_POST['parrotposter']['name']);
		$username = trim($_POST['parrotposter']['username']);
		$password = trim($_POST['parrotposter']['password']);
		$confirm_password = trim($_POST['parrotposter']['confirm_password']);

		if (empty($username)) {
			FormHelpers::post_error(parrotposter__('Username is empty'));
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
		if (!FormHelpers::check_post_nonce()) {
			FormHelpers::post_error('nonce');
		}

		$username = trim($_POST['parrotposter']['username']);

		if (empty($username)) {
			FormHelpers::post_error(parrotposter__('Username is empty'));
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
}

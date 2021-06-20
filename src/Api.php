<?php

namespace parrotposter;

class Api {
	const API_URL = 'https://parrotposter.com/api/v1/';
	const FROM = 'wordpress';
	const USER_AGENT = 'ParrotPoster WP Plugin';

	public static function ping()
	{
		return self::get('ping');
	}

	public static function login($username, $password)
	{
		$data = [
			'username' => $username,
			'password' => $password,
			'from' => self::FROM,
		];
		$res = self::post('tokens', $data);
		if (!empty($res['error'])) {
			return $res;
		}

		Options::set_user_data($res['response']['user_id'], $res['response']['token']);
		return [];
	}

	public static function logout()
	{
		Options::set_user_data('', '');
	}

	public static function validate_token()
	{
		$token = Options::token();
		if (empty($token)) {
			return ['error' => ['msg' => 'token is empty']];
		}
		$res = self::get("tokens/$token/valid");
		return $res;
	}

	public static function me()
	{
		$res = self::get('me');
		return $res;
	}

	private static function call($endpoint = '', $params = []) {
		if (empty($endpoint)) {
			return false;
		}
		$url = self::API_URL.$endpoint;
		$defaults = [
			'method' => 'GET',
			'timeout' => 30,
			'redirection' => '5',
			'user-agent' => self::USER_AGENT,
		];
		if (!empty(Options::token())) {
			$defaults['headers']['Token'] = Options::token();
		}
		$args = array_merge($defaults, $params);
		error_log(print_r([$defaults, $params, $args], true), 3, PARROTPOSTER_PLUGIN_DIR.'var.log');
		return json_decode(wp_remote_retrieve_body(wp_remote_request($url, $args)), true);
	}

	private static function get($endpoint = '', $query = [], $params = []) {
		$params['body'] = [
			'query' => urlencode(json_encode($query)),
		];
		return self::call($endpoint, $params);
	}

	private static function post($endpoint = '', $data = [], $params = []) {
		$params['method'] = 'POST';
		$params['headers']['Content-Type'] = 'application/json';
		$params['body'] = json_encode($data);
		return self::call($endpoint, $params);
	}

	private static function delete($endpoint = '', $query = [], $params = []) {
		$params['method'] = 'DELETE';
		$params['body'] = [
			'query' => urlencode(json_encode($query)),
		];
		return self::call($endpoint, $params);
	}
}
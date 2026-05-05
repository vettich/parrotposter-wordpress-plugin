<?php

namespace parrotposter;

defined('ABSPATH') || exit;

class Api
{
	const FROM = 'wordpress';
	const USER_AGENT = 'ParrotPoster WP Plugin';

	const SERVER_UNAVAILABLE = -11;

	/** Header with short-lived hook token when ParrotPoster calls the site callback. */
	public const PARROTPOSTER_HOOK_TOKEN_HEADER = 'X-ParrotPoster-HookToken';

	private const PP_DOWN_CIRCUIT_TTL_SEC = 30;

	private const CURL_NETWORK_STYLE_ERRORS = [
		'http_request_failed',
	];

	private static $_api_log_enabled = true;

	private static function is_pp_down_circuit_open(): bool
	{
		$until = (int) (DomainCache::load()['pp_down_until'] ?? 0);

		return $until > time();
	}

	private static function mark_pp_unavailable_for_circuit(): void
	{
		DomainCache::with_lock(static function (array $state) {
			$state['pp_down_until'] = time() + self::PP_DOWN_CIRCUIT_TTL_SEC;

			return $state;
		});
	}

	private static function clear_pp_down_circuit(): void
	{
		DomainCache::with_lock(static function (array $state) {
			$state['pp_down_until'] = 0;

			return $state;
		});
	}

	private static function is_network_wp_error(\WP_Error $err): bool
	{
		$codes = $err->get_error_codes();

		return (bool) array_intersect($codes, self::CURL_NETWORK_STYLE_ERRORS);
	}

	private static function decode_rest_result($body)
	{
		if (!is_string($body) || $body === '') {
			return [
				'error' => [
					'msg' => 'server is unavailable',
					'code' => self::SERVER_UNAVAILABLE,
				],
			];
		}
		$new_res = json_decode($body, true);
		if ($new_res !== null) {
			return $new_res;
		}

		return [
			'error' => [
				'msg' => 'server is unavailable',
				'code' => self::SERVER_UNAVAILABLE,
			],
		];
	}

	/**
	 * REST URL на домене (как раньше: lang в query; фильтры — body query= при GET).
	 */
	private static function build_rest_url(string $domain, string $endpoint): string
	{
		$api = trim(Env::api_uri(), '/');
		$base = rtrim($domain, '/') . '/' . $api . '/';

		return $base . $endpoint . '?lang=' . rawurlencode(self::get_locale());
	}

	/**
	 * @param array{method?: string, headers?: array, body?: array|string, timeout?: int, redirection?: int|string} $params
	 * @param array{
	 *     queries?: array,
	 *     plain_text_ok?: bool,
	 *     flat_query_params?: bool,
	 *     retry_other_domains_until_ok?: bool
	 * } $extra
	 */
	private static function do_request(string $method, string $endpoint, array $params = [], bool $need_auth = true, array $extra = [])
	{
		if (self::is_pp_down_circuit_open()) {
			return [
				'error' => [
					'msg' => 'server is unavailable',
					'code' => self::SERVER_UNAVAILABLE,
				],
			];
		}

		$queries = isset($extra['queries']) && is_array($extra['queries']) ? $extra['queries'] : [];
		$plain_text_ok = !empty($extra['plain_text_ok']);
		$flat_query_params = !empty($extra['flat_query_params']);
		$retry_other_domains_until_ok = !empty($extra['retry_other_domains_until_ok']);

		$passes = [
			['force_refresh' => false],
			['force_refresh' => true],
		];

		foreach ($passes as $pass) {
			$domains = DomainSelector::get_priority_domains($pass['force_refresh']);
			if (empty($domains)) {
				$best = DomainSelector::get_best_domain();
				if (!empty($best)) {
					$domains = [$best];
				}
			}

			foreach ($domains as $domain) {
				$url = self::build_rest_url($domain, $endpoint);
				if ($flat_query_params && !empty($queries)) {
					$url .= '&' . http_build_query($queries, '', '&', PHP_QUERY_RFC3986);
				}

				$defaults = [
					'method' => strtoupper($method),
					'timeout' => isset($params['timeout']) ? (int) $params['timeout'] : 30,
					'redirection' => isset($params['redirection']) ? $params['redirection'] : 5,
					'user-agent' => self::USER_AGENT,
					'sslverify' => true,
				];

				if ($need_auth && !empty(Options::token())) {
					$defaults['headers']['Token'] = Options::token();
				}

				$defaults['headers']['X-PP-WordPress-Version'] = defined('PARROTPOSTER_VERSION') ? (string) PARROTPOSTER_VERSION : '';

				if (!$flat_query_params && !empty($queries)) {
					$defaults['body'] = [
						'query' => urlencode(json_encode($queries)),
					];
				}

				$args = Tools::array_merge_recursive_distinct($defaults, $params);

				$time_start = microtime(true);
				$response = wp_remote_request($url, $args);
				$time_secs = microtime(true) - $time_start;

				if (is_wp_error($response)) {
					if (self::is_network_wp_error($response)) {
						DomainSelector::mark_domain_error($domain);
					}
					if (self::$_api_log_enabled) {
						PP::log([$endpoint, $time_secs, $args, $response->get_error_message(), $url]);
					}
					continue;
				}

				self::clear_pp_down_circuit();

				$code = (int) wp_remote_retrieve_response_code($response);
				$body = wp_remote_retrieve_body($response);

				if (self::$_api_log_enabled) {
					$ret = json_decode((string) $body, true);
					PP::log([$endpoint, $time_secs, $args, $ret, $url]);
				}

				if ($retry_other_domains_until_ok) {
					if ($code !== 200) {
						continue;
					}
					if ($plain_text_ok && strcasecmp(trim((string) $body), 'OK') === 0) {
						return ['response' => true];
					}
					$decoded = self::decode_rest_result(is_string($body) ? $body : '');
					if (is_array($decoded) && empty($decoded['error'])) {
						return $decoded;
					}
					continue;
				}

				if ($code >= 500) {
					return self::decode_rest_result(is_string($body) ? $body : '');
				}

				if ($plain_text_ok) {
					if ($code === 200 && strcasecmp(trim((string) $body), 'OK') === 0) {
						return ['response' => true];
					}
				}

				return self::decode_rest_result(is_string($body) ? $body : '');
			}
		}

		self::mark_pp_unavailable_for_circuit();

		return [
			'error' => [
				'msg' => 'server is unavailable',
				'code' => self::SERVER_UNAVAILABLE,
			],
		];
	}

	/**
	 * @return array{data: array}|array{error: array{msg: string, code?: int}}
	 */
	private static function do_graphql_request(string $query, array $variables = [], array $opts = []): array
	{
		if (self::is_pp_down_circuit_open()) {
			return [
				'error' => [
					'msg' => 'server is unavailable',
					'code' => self::SERVER_UNAVAILABLE,
				],
			];
		}

		$bearer_token = isset($opts['bearer_token']) ? $opts['bearer_token'] : null;
		$curl_timeout = isset($opts['curl_timeout']) ? (int) $opts['curl_timeout'] : 15;
		$curl_connect_timeout = isset($opts['curl_connect_timeout']) ? (int) $opts['curl_connect_timeout'] : 5;
		$log_label = isset($opts['log_label']) ? (string) $opts['log_label'] : 'graphql';

		$payload = ['query' => $query];
		if ($variables !== []) {
			$payload['variables'] = $variables;
		}
		$body_json = wp_json_encode($payload, JSON_UNESCAPED_UNICODE);
		if ($body_json === false) {
			return ['error' => ['msg' => 'json encode error']];
		}

		$passes = [
			['force_refresh' => false],
			['force_refresh' => true],
		];

		foreach ($passes as $pass) {
			$domains = DomainSelector::get_priority_domains($pass['force_refresh']);
			if (empty($domains)) {
				$best = DomainSelector::get_best_domain();
				if (!empty($best)) {
					$domains = [$best];
				}
			}

			foreach ($domains as $domain) {
				$gql_url = rtrim($domain, '/') . Env::graphql_api_uri();

				$headers = [
					'Content-Type' => 'application/json',
					'X-PP-WordPress-Version' => defined('PARROTPOSTER_VERSION') ? (string) PARROTPOSTER_VERSION : '',
				];
				if (is_string($bearer_token) && $bearer_token !== '') {
					$headers['Authorization'] = 'Bearer ' . $bearer_token;
				}

				$response = wp_remote_post($gql_url, [
					'timeout' => $curl_timeout,
					'connect_timeout' => $curl_connect_timeout,
					'redirection' => 3,
					'user-agent' => self::USER_AGENT,
					'sslverify' => true,
					'headers' => $headers,
					'body' => $body_json,
				]);

				if (is_wp_error($response)) {
					if (self::is_network_wp_error($response)) {
						DomainSelector::mark_domain_error($domain);
					}
					PP::log([$log_label . '_network', $domain, $response->get_error_message()]);
					continue;
				}

				self::clear_pp_down_circuit();

				$code = (int) wp_remote_retrieve_response_code($response);
				$body = wp_remote_retrieve_body($response);

				if ($code >= 500) {
					$decoded = json_decode((string) $body, true);
					if (is_array($decoded) && !empty($decoded['errors'])) {
						$msg = $decoded['errors'][0]['message'] ?? 'graphql error';

						return ['error' => ['msg' => $msg]];
					}

					return [
						'error' => [
							'msg' => 'server is unavailable',
							'code' => self::SERVER_UNAVAILABLE,
						],
					];
				}

				$decoded = json_decode((string) $body, true);
				if (!is_array($decoded)) {
					continue;
				}

				if (!empty($decoded['errors'])) {
					$msg = $decoded['errors'][0]['message'] ?? 'graphql error';

					return ['error' => ['msg' => $msg]];
				}

				return ['data' => isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : []];
			}
		}

		self::mark_pp_unavailable_for_circuit();

		return [
			'error' => [
				'msg' => 'server is unavailable',
				'code' => self::SERVER_UNAVAILABLE,
			],
		];
	}

	/**
	 * Короткоживущий токен для iframe (GraphQL).
	 *
	 * @return array{token: string}|array{error: array{msg: string, code?: int}}
	 */
	public static function issue_session_key(): array
	{
		$bearer = Options::token();
		if (empty($bearer)) {
			return ['error' => ['msg' => 'token is empty']];
		}

		$read_only = false;
		$q = 'mutation IssueSessionKey($readOnly: Boolean) { issueSessionKey(readOnly: $readOnly) { token } }';
		$res = self::do_graphql_request($q, ['readOnly' => $read_only], [
			'bearer_token' => $bearer,
			'log_label' => 'issueSessionKey',
			'curl_timeout' => 3,
			'curl_connect_timeout' => 2,
		]);

		if (!empty($res['error'])) {
			return $res;
		}

		$session_token = $res['data']['issueSessionKey']['token'] ?? null;
		if (empty($session_token)) {
			return ['error' => ['msg' => 'no session token in response']];
		}

		return ['token' => (string) $session_token];
	}

	/**
	 * OAuth: обмен code на token (без Bearer).
	 *
	 * @return array{token: string}|array{error: array{msg: string, code?: int}}
	 */
	public static function exchange_auth_code(string $code): array
	{
		$code = trim($code);
		if ($code === '') {
			return ['error' => ['msg' => 'code is empty']];
		}
		if (strlen($code) > 8192) {
			return ['error' => ['msg' => 'code is too long']];
		}

		$q = 'mutation ExchangeAuthCode($code: String!) { exchangeCode(code: $code) { token } }';
		$res = self::do_graphql_request($q, ['code' => $code], [
			'log_label' => 'exchangeAuthCode',
		]);

		if (!empty($res['error'])) {
			return $res;
		}

		$payload = $res['data']['exchangeCode'] ?? null;
		if (!is_array($payload)) {
			return ['error' => ['msg' => 'invalid exchange response']];
		}
		$token = $payload['token'] ?? null;
		if ($token === null || $token === '') {
			return ['error' => ['msg' => 'no token in response']];
		}

		return ['token' => (string) $token];
	}

	public static function ping()
	{
		return self::get('ping', [], false);
	}

	public static function login($username, $password)
	{
		$data = [
			'username' => $username,
			'password' => $password,
			'from' => self::FROM,
		];
		$res = self::post('tokens', $data, [], false);
		if (!empty($res['error'])) {
			return $res;
		}

		Options::set_user_data($res['response']['user_id'], $res['response']['token']);
		return [];
	}

	public static function signup($name, $username, $password)
	{
		$data = [
			'name' => $name,
			'username' => $username,
			'password' => $password,
			'from' => self::FROM,
		];
		$res = self::post('users', $data, [], false);
		if (!empty($res['error'])) {
			return $res;
		}

		Options::set_user_data($res['response']['user_id'], $res['response']['token']);
		return [];
	}

	public static function forgot_password($username, $callback_url)
	{
		$data = [
			'username' => $username,
			'callback_url' => $callback_url,
			'from' => self::FROM,
		];
		$res = self::post('passwords/forgot', $data, [], false);
		return $res;
	}

	public static function reset_password($token, $password)
	{
		$data = [
			'token' => $token,
			'password' => $password,
		];
		$res = self::post('passwords/new', $data, [], false);
		return $res;
	}

	public static function logout()
	{
		$token = Options::token();
		self::delete("tokens/$token", [], false);
		Options::set_user_data('', '');
	}

	public static function validate_token()
	{
		$token = Options::token();
		if (empty($token)) {
			return ['error' => ['msg' => 'token is empty']];
		}
		$res = self::get("tokens/$token/valid", [], false);
		return $res;
	}

	public static function me()
	{
		$res = self::get('me');
		return ApiHelpers::prepare_api_response($res, '', []);
	}

	public static function get_tariff($id)
	{
		if (empty($id)) {
			return [null, 'id is empty'];
		}

		$res = self::get("tariffs/$id");
		return ApiHelpers::prepare_api_response($res);
	}

	public static function list_tariffs()
	{
		$res = self::get('tariffs');
		return $res;
	}

	public static function set_user_tariff($tariff_id)
	{
		$data = ['tariff_id' => $tariff_id];
		$res = self::post('me/set-tariff', $data);
		return $res;
	}

	public static function create_transaction($tariff_id, $period, $success_url, $fail_url)
	{
		$data = [
			'tariff_id' => $tariff_id,
			'period' => intval($period),
			'success_url' => $success_url,
			'fail_url' => $fail_url,
		];
		$res = self::post('transactions', $data);
		return $res;
	}

	public static function list_accounts()
	{
		$res = self::get('accounts');
		return ApiHelpers::prepare_api_response($res, 'accounts', []);
	}

	public static function get_connect_url($account_type, $callback_url)
	{
		$data = [
			'type' => $account_type,
			'callback' => $callback_url,
		];
		$res = self::get('connect_url', $data);
		return $res;
	}

	public static function connect($account_type, $fields)
	{
		$data = [
			'type' => $account_type,
			'fields' => $fields,
		];
		$res = self::post('connect', $data);
		return $res;
	}

	public static function delete_account($account_id)
	{
		$res = self::delete("accounts/$account_id");
		return $res;
	}

	public static function list_posts($filter = [], $sort = [], $paging = [], $needCounts = false)
	{
		$filter['from'] = self::FROM;
		$data = [
			'filter' => $filter,
		];
		if (!empty($sort)) {
			$data['sort'] = $sort;
		}
		if (!empty($paging)) {
			$data['paging'] = $paging;
		}
		if ($needCounts) {
			$data['need_counts'] = true;
		}
		return self::get('posts', $data);
	}

	public static function get_post($post_id = '')
	{
		$res = self::get("posts/$post_id");
		return ApiHelpers::prepare_api_response($res, '', []);
	}

	public static function delete_post($post_id = '')
	{
		$res = self::delete("posts/$post_id");
		return $res;
	}

	public static function create_post($post = [])
	{
		$res = self::post('posts', $post);
		return $res;
	}

	/**
	 * Update an existing ParrotPoster post (REST POST /posts/{post_id}).
	 *
	 * @param string               $post_id PP post ID
	 * @param array<string, mixed> $data    Request body (fields, networks; omit publish_at to keep schedule)
	 */
	public static function update_post(string $post_id, array $data = [])
	{
		$post_id = trim($post_id);
		if ($post_id === '') {
			return ['error' => ['msg' => 'post id is empty']];
		}

		$res = self::post("posts/$post_id", $data);

		return $res;
	}

	public static function get_last_post_publish_at($post_ids = [])
	{
		$ids = implode(',', $post_ids);
		$res = self::get("posts/$ids/last-publish-at");
		return ApiHelpers::retrieve_response($res);
	}

	public static function get_exchange_rate_usd()
	{
		$res = self::get('exchange-rate/usd');
		return $res;
	}

	public static function upload_file($filepath)
	{
		$filename = basename($filepath);
		$content_type = mime_content_type($filepath);
		$boundary = wp_generate_password(24, false);

		$payload = "--$boundary\r\n";
		$payload .= "Content-Disposition: form-data; name=\"file\"; filename=\"$filename\"\r\n";
		$payload .= "Content-Type: $content_type\r\n";
		$payload .= "\r\n";
		$payload .= file_get_contents($filepath);
		$payload .= "\r\n--{$boundary}--\r\n";

		$params = [
			'method' => 'POST',
			'headers' => [
				'Content-Type' => "multipart/form-data; boundary=$boundary",
			],
			'body' => $payload,
		];
		self::$_api_log_enabled = false;
		$res = self::call('files', $params);
		self::$_api_log_enabled = true;
		if (!empty($res['error'])) {
			return $res;
		}
		$file_id = $res['response']['file_id'];

		$res = self::get("files/$file_id/status");
		if (!empty($res['response']) && $res['response']['status'] != 'uploaded') {
			return ['error' => ['msg' => 'failed file upload']];
		}

		return ['response' => ['file_id' => $file_id]];
	}

	private static function call($endpoint = '', $params = [])
	{
		if (empty($endpoint)) {
			return false;
		}

		$method = isset($params['method']) ? strtoupper((string) $params['method']) : 'GET';
		$need_auth = isset($params['__need_auth']) ? (bool) $params['__need_auth'] : true;
		unset($params['__need_auth'], $params['method']);

		$extra = [];
		if ($method === 'GET' && !empty($params['body']['query'])) {
			$q_raw = $params['body']['query'];
			$q_decoded = json_decode(urldecode($q_raw), true);
			if (is_array($q_decoded)) {
				$extra['queries'] = $q_decoded;
			}
			unset($params['body']);
		} elseif ($method === 'DELETE' && !empty($params['body']['query'])) {
			$q_raw = $params['body']['query'];
			$q_decoded = json_decode(urldecode($q_raw), true);
			if (is_array($q_decoded)) {
				$extra['queries'] = $q_decoded;
			}
			unset($params['body']);
		}

		return self::do_request($method, $endpoint, $params, $need_auth, $extra);
	}

	private static function get($endpoint = '', $query = [], $need_auth = true, $params = [])
	{
		if (!empty($query)) {
			$params['body'] = [
				'query' => urlencode(json_encode($query)),
			];
		}
		$params['method'] = 'GET';
		$params['__need_auth'] = $need_auth;

		return self::call($endpoint, $params);
	}

	private static function post($endpoint = '', $data = [], $params = [], $need_auth = true)
	{
		$params['method'] = 'POST';
		$params['headers']['Content-Type'] = 'application/json';
		$data = mb_convert_encoding($data, 'UTF-8', 'UTF-8');
		$params['body'] = json_encode($data);
		$params['__need_auth'] = $need_auth;

		return self::call($endpoint, $params);
	}

	private static function delete($endpoint = '', $query = [], $need_auth = true, $params = [])
	{
		$params['method'] = 'DELETE';
		if (!empty($query)) {
			$params['body'] = [
				'query' => urlencode(json_encode($query)),
			];
		}
		$params['__need_auth'] = $need_auth;

		return self::call($endpoint, $params);
	}

	public static function hook_token_from_incoming_request(): string
	{
		if (!empty($_SERVER['HTTP_X_PARROTPOSTER_HOOKTOKEN'])) {
			return trim((string) wp_unslash($_SERVER['HTTP_X_PARROTPOSTER_HOOKTOKEN']));
		}

		return '';
	}

	/**
	 * Validates hook token from PP (same contract as Bitrix vettich.sp3 Api::checkHookToken).
	 */
	public static function check_hook_token(?string $hook_token = null): bool
	{
		$hook_token = $hook_token ?? self::hook_token_from_incoming_request();
		if ($hook_token === '' || strlen($hook_token) > 4096) {
			return false;
		}
		if (empty(Options::token())) {
			return false;
		}

		$endpoint = 'check-hook-token/' . rawurlencode($hook_token);
		$res = self::do_request('GET', $endpoint, ['method' => 'GET'], false, ['plain_text_ok' => true]);
		if (!is_array($res) || !empty($res['error'])) {
			return false;
		}
		if (isset($res['response']) && $res['response'] === true) {
			return true;
		}
		if (isset($res['response']) && is_array($res['response']) && !empty($res['response']['valid'])) {
			return true;
		}

		return false;
	}

	/**
	 * Registers callback URL with PP post-queue (Bitrix Api::requestLocalQueueWake analogue).
	 */
	public static function request_local_queue_wake(): bool
	{
		if (empty(Options::token())) {
			return false;
		}

		$callback = admin_url('admin-ajax.php?action=parrotposter_process_local_queue');

		$res = self::do_request(
			'POST',
			'post-queue',
			[
				'method' => 'POST',
				'headers' => [
					'Content-Type' => 'application/json',
				],
				'body' => '{}',
			],
			true,
			[
				'queries' => ['url' => $callback],
				'flat_query_params' => true,
				'plain_text_ok' => true,
				'retry_other_domains_until_ok' => true,
			]
		);

		return is_array($res) && empty($res['error']);
	}

	private static function get_locale()
	{
		$locale = 'en';
		$locale_splitted = explode('_', get_locale());
		if (
			count($locale_splitted) > 0 &&
			!empty($locale_splitted[0]) &&
			strlen($locale_splitted[0]) == 2
		) {
			$locale = $locale_splitted[0];
		}
		return $locale;
	}
}

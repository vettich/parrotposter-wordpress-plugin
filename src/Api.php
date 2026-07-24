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
					$first = $decoded['errors'][0];
					$msg = is_array($first) && isset($first['message'])
						? (string) $first['message']
						: 'graphql error';
					$code = null;
					if (is_array($first) && isset($first['extensions']['code']) && is_string($first['extensions']['code'])) {
						$code = $first['extensions']['code'];
					}

					return ['error' => array_filter(['msg' => $msg, 'code' => $code])];
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
	 * GraphQL mutation with site_to_pp Bearer + HMAC (machine-path, SPEC-002-09 §4.2).
	 *
	 * @param array<string, mixed> $variables Mutation variables (input fields for pluginPipelineEventIngest)
	 * @param array<string, mixed> $opts      curl_timeout, curl_connect_timeout, _retried_contract
	 * @return array{data?: array, error?: array{msg: string, code?: int|string, extensions?: array}}
	 */
	public static function graphql_mutation(string $operation, array $variables = [], array $opts = []): array
	{
		$secret = Settings::site_to_pp_secret();
		if ($secret === '') {
			return ['error' => ['msg' => 'site_to_pp secret is empty']];
		}

		$query = self::build_graphql_mutation_query($operation);
		if ($query === '') {
			return ['error' => ['msg' => 'unknown graphql operation']];
		}

		$gql_variables = self::wrap_graphql_variables($operation, $variables);
		$res = self::do_graphql_site_request($query, $gql_variables, array_merge($opts, [
			'bearer_token' => $secret,
			'sign_with_site_to_pp' => true,
			'log_label' => $operation,
		]));

		if (!empty($res['error']) && self::is_contract_version_mismatch_error($res)) {
			self::apply_contract_version_mismatch($res);
			if (empty($opts['_retried_contract'])) {
				$variables = self::refresh_contract_version_in_variables($operation, $variables);
				$opts['_retried_contract'] = true;

				return self::graphql_mutation($operation, $variables, $opts);
			}
		}

		if (!empty($res['data'])) {
			return self::normalize_graphql_mutation_response($operation, $res);
		}

		return $res;
	}

	/**
	 * GraphQL mutation with user session Bearer (admin UI path, SPEC-002-17).
	 *
	 * @param array<string, mixed> $variables Mutation variables
	 * @param array<string, mixed> $opts      curl_timeout, curl_connect_timeout
	 * @return array{data?: array, error?: array{msg: string, code?: int|string, extensions?: array}}
	 */
	public static function graphql_user_mutation(string $operation, array $variables = [], array $opts = []): array
	{
		$bearer = Options::token();
		if ($bearer === '') {
			return ['error' => ['msg' => __('Требуется авторизация в ParrotPoster.', 'parrotposter')]];
		}

		$query = self::build_graphql_mutation_query($operation);
		if ($query === '') {
			return ['error' => ['msg' => 'unknown graphql operation']];
		}

		$gql_variables = self::wrap_graphql_variables($operation, $variables);
		$res = self::do_graphql_request($query, $gql_variables, array_merge($opts, [
			'bearer_token' => $bearer,
			'log_label' => $operation,
		]));

		return self::normalize_graphql_mutation_response($operation, $res);
	}

	/**
	 * @param array{data?: array, error?: array{msg: string, code?: int|string, extensions?: array}} $res
	 * @return array{data?: array, error?: array{msg: string, code?: int|string, extensions?: array}}
	 */
	private static function normalize_graphql_mutation_response(string $operation, array $res): array
	{
		if (empty($res['data'])) {
			return $res;
		}

		$payload = self::extract_mutation_payload($operation, $res['data']);
		if (is_array($payload) && array_key_exists('accepted', $payload) && empty($payload['accepted'])) {
			$msg = 'graphql mutation rejected';
			if (!empty($payload['errors']) && is_array($payload['errors'])) {
				$first = $payload['errors'][0] ?? null;
				if (is_array($first) && !empty($first['message'])) {
					$msg = (string) $first['message'];
				}
			}

			return ['error' => ['msg' => $msg, 'errors' => $payload['errors'] ?? []]];
		}
		if (is_array($payload) && !empty($payload['errors']) && is_array($payload['errors'])) {
			$first = $payload['errors'][0] ?? null;
			if (is_array($first) && !empty($first['message'])) {
				return ['error' => ['msg' => (string) $first['message'], 'errors' => $payload['errors']]];
			}
		}

		return $res;
	}

	private static function build_graphql_mutation_query(string $operation): string
	{
		switch ($operation) {
			case 'pluginPipelineEventIngest':
				return 'mutation PluginPipelineEventIngest($input: PluginPipelineEventInput!) { pluginPipelineEventIngest(input: $input) { accepted triggerRunIds errors { message code } } }';
			case 'migratePluginToPipeline':
				return 'mutation MigratePluginToPipeline($input: MigratePluginInput!) { migratePluginToPipeline(input: $input) { plugin { id migrationMode pipelineIdsFromMigration } pipelines { id name } warnings errors { message code } } }';
			case 'revertPluginToLegacy':
				return 'mutation RevertPluginToLegacy($pluginId: ID!) { revertPluginToLegacy(pluginId: $pluginId) { plugin { id migrationMode pipelineIdsFromMigration } errors { message code } } }';
			default:
				return '';
		}
	}

	/**
	 * @param array<string, mixed> $variables
	 * @return array<string, mixed>
	 */
	private static function wrap_graphql_variables(string $operation, array $variables): array
	{
		switch ($operation) {
			case 'pluginPipelineEventIngest':
				return ['input' => $variables];
			case 'migratePluginToPipeline':
				return ['input' => $variables];
			case 'revertPluginToLegacy':
				return $variables;
			default:
				return $variables;
		}
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>|null
	 */
	private static function extract_mutation_payload(string $operation, array $data): ?array
	{
		switch ($operation) {
			case 'pluginPipelineEventIngest':
				return isset($data['pluginPipelineEventIngest']) && is_array($data['pluginPipelineEventIngest'])
					? $data['pluginPipelineEventIngest']
					: null;
			case 'migratePluginToPipeline':
				return isset($data['migratePluginToPipeline']) && is_array($data['migratePluginToPipeline'])
					? $data['migratePluginToPipeline']
					: null;
			case 'revertPluginToLegacy':
				return isset($data['revertPluginToLegacy']) && is_array($data['revertPluginToLegacy'])
					? $data['revertPluginToLegacy']
					: null;
			default:
				return null;
		}
	}

	/**
	 * @param array{error: array{msg?: string, code?: string, extensions?: array}} $res
	 */
	private static function is_contract_version_mismatch_error(array $res): bool
	{
		$code = $res['error']['code'] ?? '';
		if ($code === 'contract_version_mismatch') {
			return true;
		}
		$msg = strtolower((string) ($res['error']['msg'] ?? ''));

		return strpos($msg, 'contract_version_mismatch') !== false;
	}

	/**
	 * @param array{error: array{extensions?: array}} $res
	 */
	private static function apply_contract_version_mismatch(array $res): void
	{
		$extensions = $res['error']['extensions'] ?? null;
		if (!is_array($extensions)) {
			return;
		}
		$contract = $extensions['pipelineContract'] ?? $extensions['pipeline_contract'] ?? null;
		if (!is_array($contract)) {
			return;
		}
		Settings::apply_pipeline_contract_snapshot($contract);
	}

	/**
	 * @param array<string, mixed> $variables
	 * @return array<string, mixed>
	 */
	private static function refresh_contract_version_in_variables(string $operation, array $variables): array
	{
		if ($operation !== 'pluginPipelineEventIngest') {
			return $variables;
		}
		$pipeline_id = isset($variables['pipelineId']) ? (string) $variables['pipelineId'] : '';
		if ($pipeline_id === '') {
			return $variables;
		}
		$contract = Settings::get_pipeline_contract($pipeline_id);
		if (!is_array($contract)) {
			return $variables;
		}
		$variables['contractVersion'] = (int) ($contract['contract_version'] ?? 0);

		return $variables;
	}

	/**
	 * @param array<string, mixed> $variables
	 * @param array<string, mixed> $opts
	 * @return array{data?: array, error?: array{msg: string, code?: int|string, extensions?: array}}
	 */
	private static function do_graphql_site_request(string $query, array $variables = [], array $opts = []): array
	{
		if (self::is_pp_down_circuit_open()) {
			return [
				'error' => [
					'msg' => 'server is unavailable',
					'code' => self::SERVER_UNAVAILABLE,
				],
			];
		}

		$bearer_token = isset($opts['bearer_token']) ? (string) $opts['bearer_token'] : '';
		$sign_with_site_to_pp = !empty($opts['sign_with_site_to_pp']);
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
				if ($bearer_token !== '') {
					$headers['Authorization'] = 'Bearer ' . $bearer_token;
				}
				if ($sign_with_site_to_pp && $bearer_token !== '') {
					$headers = array_merge($headers, self::build_site_to_pp_hmac_headers(
						'POST',
						Env::graphql_signing_path(),
						$body_json,
						$bearer_token
					));
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
						$parsed = self::parse_graphql_error($decoded['errors'][0] ?? null);

						return ['error' => $parsed];
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
					$parsed = self::parse_graphql_error($decoded['errors'][0] ?? null);

					return ['error' => $parsed];
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
	 * @param mixed $error
	 * @return array{msg: string, code?: string, extensions?: array}
	 */
	private static function parse_graphql_error($error): array
	{
		if (!is_array($error)) {
			return ['msg' => 'graphql error'];
		}
		$msg = isset($error['message']) ? (string) $error['message'] : 'graphql error';
		$extensions = isset($error['extensions']) && is_array($error['extensions']) ? $error['extensions'] : [];
		$code = '';
		if (isset($extensions['code']) && is_string($extensions['code'])) {
			$code = $extensions['code'];
		}

		return [
			'msg' => $msg,
			'code' => $code !== '' ? $code : null,
			'extensions' => $extensions,
		];
	}

	/**
	 * @return array<string, string>
	 */
	private static function build_site_to_pp_hmac_headers(
		string $method,
		string $path,
		string $raw_body,
		string $site_to_pp_secret
	): array {
		$timestamp = (string) time();
		$nonce = function_exists('wp_generate_uuid4') ? wp_generate_uuid4() : uniqid('pp_', true);
		$body_hash = hash('sha256', $raw_body);
		$signing_input = strtoupper($method) . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . $body_hash;
		$signature = self::base64url_encode(hash_hmac('sha256', $signing_input, $site_to_pp_secret, true));

		return [
			'X-PP-Timestamp' => $timestamp,
			'X-PP-Nonce' => $nonce,
			'X-PP-Signature' => $signature,
		];
	}

	private static function base64url_encode(string $data): string
	{
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
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

	/**
	 * Issue one-time auth code for plugin binding (user session Bearer).
	 *
	 * @return array{code: string, expires_at?: string}|array{error: array{msg: string, code?: int}}
	 */
	public static function create_plugin_auth_code(
		string $domain,
		string $callback_url,
		?string $plugin_version = null
	): array {
		$bearer = Options::token();
		if ($bearer === '') {
			return ['error' => ['msg' => __('Требуется авторизация в ParrotPoster.', 'parrotposter')]];
		}

		$domain = trim($domain);
		$callback_url = trim($callback_url);
		if ($domain === '' || $callback_url === '') {
			return ['error' => ['msg' => __('Некорректные параметры подключения.', 'parrotposter')]];
		}

		$variables = [
			'domain' => $domain,
			'platform' => 'WORDPRESS',
			'callbackUrl' => $callback_url,
		];
		if ($plugin_version !== null && trim($plugin_version) !== '') {
			$variables['pluginVersion'] = trim($plugin_version);
		}

		$q = 'mutation CreatePluginAuthCode($domain: String!, $platform: PluginPlatform!, $callbackUrl: String!, $pluginVersion: String) {
			createPluginAuthCode(domain: $domain, platform: $platform, callbackUrl: $callbackUrl, pluginVersion: $pluginVersion) {
				code expiresAt
			}
		}';
		$res = self::do_graphql_request($q, $variables, [
			'bearer_token' => $bearer,
			'log_label' => 'createPluginAuthCode',
		]);

		if (!empty($res['error'])) {
			return $res;
		}

		$payload = $res['data']['createPluginAuthCode'] ?? null;
		if (!is_array($payload)) {
			return ['error' => ['msg' => __('Некорректный ответ сервера.', 'parrotposter')]];
		}

		$code = isset($payload['code']) ? (string) $payload['code'] : '';
		if ($code === '') {
			return ['error' => ['msg' => __('Код подключения не получен.', 'parrotposter')]];
		}

		$result = ['code' => $code];
		if (!empty($payload['expiresAt'])) {
			$result['expires_at'] = (string) $payload['expiresAt'];
		}

		return $result;
	}

	/**
	 * Build SSO URL for opening the main web app in a new tab.
	 *
	 * @return array{url: string}|array{error: array{msg: string}}
	 */
	public static function build_sso_url(?string $return_to = null): array
	{
		$session = self::issue_session_key();
		if (!empty($session['error'])) {
			return $session;
		}

		$token = (string) $session['token'];
		$domains = Env::domains();
		$pp_base = !empty($domains) ? rtrim((string) $domains[0], '/') : 'https://parrotposter.com';
		$params = [
			'token' => $token,
			'lang' => substr(get_user_locale(), 0, 2),
		];
		if ($return_to !== null && $return_to !== '') {
			$params['returnTo'] = $return_to;
		}

		return [
			'url' => add_query_arg($params, $pp_base . '/auth/enter-with-token'),
		];
	}

	/**
	 * Plugin binding: exchange one-time auth code for machine secrets (no HMAC).
	 *
	 * @return array{plugin_id: string, site_to_pp_secret: string, pp_to_site_secret: string, migration_mode?: string}|array{error: array{msg: string, code?: string}}
	 */
	public static function complete_plugin_binding(
		string $code,
		string $domain,
		string $callback_url,
		?string $plugin_version = null
	): array {
		$code = trim($code);
		if ($code === '') {
			return ['error' => ['msg' => __('Код подключения не указан.', 'parrotposter')]];
		}
		if (strlen($code) > 8192) {
			return ['error' => ['msg' => __('Код подключения слишком длинный.', 'parrotposter')]];
		}

		$domain = trim($domain);
		$callback_url = trim($callback_url);
		if ($domain === '' || $callback_url === '') {
			return ['error' => ['msg' => __('Некорректные параметры подключения.', 'parrotposter')]];
		}

		$variables = [
			'code' => $code,
			'platform' => 'WORDPRESS',
			'domain' => $domain,
			'callbackUrl' => $callback_url,
		];
		if ($plugin_version !== null && trim($plugin_version) !== '') {
			$variables['pluginVersion'] = trim($plugin_version);
		}

		$q = 'mutation CompletePluginBinding($code: String!, $platform: PluginPlatform!, $domain: String!, $callbackUrl: String!, $pluginVersion: String) {
			completePluginBinding(code: $code, platform: $platform, domain: $domain, callbackUrl: $callbackUrl, pluginVersion: $pluginVersion) {
				pluginId siteToPpSecret ppToSiteSecret migrationMode
			}
		}';
		$res = self::do_graphql_request($q, $variables, [
			'log_label' => 'completePluginBinding',
		]);

		if (!empty($res['error'])) {
			$mapped = self::map_plugin_binding_error($res['error']);

			return ['error' => $mapped];
		}

		$payload = $res['data']['completePluginBinding'] ?? null;
		if (!is_array($payload)) {
			return ['error' => ['msg' => __('Некорректный ответ сервера.', 'parrotposter')]];
		}

		$plugin_id = isset($payload['pluginId']) ? (string) $payload['pluginId'] : '';
		$site_to_pp = isset($payload['siteToPpSecret']) ? (string) $payload['siteToPpSecret'] : '';
		$pp_to_site = isset($payload['ppToSiteSecret']) ? (string) $payload['ppToSiteSecret'] : '';
		if ($plugin_id === '' || $site_to_pp === '' || $pp_to_site === '') {
			return ['error' => ['msg' => __('Сервер не вернул данные подключения.', 'parrotposter')]];
		}

		$result = [
			'plugin_id' => $plugin_id,
			'site_to_pp_secret' => $site_to_pp,
			'pp_to_site_secret' => $pp_to_site,
		];
		if (isset($payload['migrationMode']) && is_string($payload['migrationMode'])) {
			$result['migration_mode'] = $payload['migrationMode'];
		}

		return $result;
	}

	/**
	 * Disable plugin binding on PP (user session; MustAuth on server).
	 *
	 * @return array{ok: true}|array{error: array{msg: string, code?: string}}
	 */
	public static function disable_plugin(string $plugin_id): array
	{
		$plugin_id = trim($plugin_id);
		if ($plugin_id === '') {
			return ['error' => ['msg' => 'plugin_id is empty']];
		}

		$bearer = Options::token();
		if ($bearer === '') {
			return ['error' => ['msg' => 'token is empty']];
		}

		$q = 'mutation DisablePlugin($id: ID!) { disablePlugin(id: $id) }';
		$res = self::do_graphql_request($q, ['id' => $plugin_id], [
			'bearer_token' => $bearer,
			'log_label' => 'disablePlugin',
		]);

		if (!empty($res['error'])) {
			return $res;
		}

		$ok = $res['data']['disablePlugin'] ?? null;
		if ($ok !== true) {
			return ['error' => ['msg' => 'disablePlugin failed']];
		}

		return ['ok' => true];
	}

	/**
	 * @param array{msg?: string, code?: string} $error
	 * @return array{msg: string, code?: string}
	 */
	private static function map_plugin_binding_error(array $error): array
	{
		$code = isset($error['code']) ? (string) $error['code'] : '';
		$messages = [
			'auth_code_invalid_or_expired' => __('Код подключения недействителен или истёк. Начните подключение заново.', 'parrotposter'),
			'binding_mismatch' => __('Данные подключения не совпадают. Проверьте домен и callback URL.', 'parrotposter'),
			'callback_url_must_be_https' => __('Callback URL должен использовать HTTPS.', 'parrotposter'),
			'callback_url_invalid' => __('Некорректный callback URL. Убедитесь, что сайт доступен по HTTPS и не использует локальный адрес.', 'parrotposter'),
		];
		if ($code !== '' && isset($messages[$code])) {
			return ['msg' => $messages[$code], 'code' => $code];
		}

		$msg = isset($error['msg']) ? (string) $error['msg'] : __('Не удалось завершить подключение.', 'parrotposter');

		return array_filter(['msg' => $msg, 'code' => $code !== '' ? $code : null]);
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

	/**
	 * @return array{response?: array, error?: array}
	 */
	public static function upload_file($filepath)
	{
		if (self::is_pp_down_circuit_open()) {
			return [
				'error' => [
					'msg' => 'server is unavailable',
					'code' => self::SERVER_UNAVAILABLE,
				],
			];
		}

		$max_attempts = 3;
		$last_res = ['error' => ['msg' => 'upload failed']];

		for ($attempt = 1; $attempt <= $max_attempts; ++$attempt) {
			if ($attempt > 1) {
				usleep(200000 * $attempt);
			}

			$last_res = self::upload_file_once($filepath);
			if (empty($last_res['error'])) {
				return $last_res;
			}

			if (!self::is_upload_error_retriable($last_res)) {
				break;
			}
		}

		PP::log([
			'Api::upload_file_failed',
			'filepath' => $filepath,
			'error' => $last_res['error'] ?? null,
		]);

		return $last_res;
	}

	/**
	 * @param array{response?: array, error?: array} $res
	 */
	private static function is_upload_error_retriable(array $res): bool
	{
		if (empty($res['error']) || !is_array($res['error'])) {
			return false;
		}
		$code = $res['error']['code'] ?? null;

		return $code === self::SERVER_UNAVAILABLE;
	}

	/**
	 * @return string MIME type for multipart upload
	 */
	private static function detect_upload_mime_type(string $filepath): string
	{
		if (function_exists('mime_content_type')) {
			$mime = \mime_content_type($filepath);
			if (is_string($mime) && $mime !== '') {
				return $mime;
			}
		}

		$checked = wp_check_filetype(basename($filepath));
		if (!empty($checked['type']) && is_string($checked['type'])) {
			return $checked['type'];
		}

		return 'application/octet-stream';
	}

	/**
	 * @return array{response?: array, error?: array}
	 */
	private static function upload_file_once($filepath)
	{
		$filename = basename($filepath);
		$content_type = self::detect_upload_mime_type($filepath);
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
		$file_id = $res['response']['file_id'] ?? null;
		if (empty($file_id)) {
			return ['error' => ['msg' => 'no file_id in response']];
		}

		$res = self::get("files/$file_id/status");
		if (!empty($res['response']) && ($res['response']['status'] ?? '') !== 'uploaded') {
			usleep(300000);
			$res = self::get("files/$file_id/status");
			if (!empty($res['response']) && ($res['response']['status'] ?? '') !== 'uploaded') {
				return ['error' => ['msg' => 'failed file upload']];
			}
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

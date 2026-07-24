<?php

namespace parrotposter;

defined('ABSPATH') || exit;

/**
 * Plugin-side cache for pipeline wire protocol (migration mode, secrets, contracts).
 */
class Settings
{
	public const MIGRATION_MODE_LEGACY = 'legacy';

	public const MIGRATION_MODE_PIPELINE = 'pipeline';

	private const MIGRATION_MODE_KEY = 'parrotposter_migration_mode';

	private const PLUGIN_ID_KEY = 'parrotposter_plugin_id';

	private const SITE_TO_PP_KEY = 'parrotposter_site_to_pp';

	private const PP_TO_SITE_HASH_KEY = 'parrotposter_pp_to_site_hash';

	private const PP_TO_SITE_PREV_HASH_KEY = 'parrotposter_pp_to_site_prev_hash';

	private const PP_TO_SITE_PEPPER_KEY = 'parrotposter_pp_to_site_pepper';

	private const PIPELINE_IDS_KEY = 'parrotposter_pipeline_ids';

	private const PIPELINE_CONTRACTS_KEY = 'parrotposter_pipeline_contracts';

	private const SECRET_CIPHER_PREFIX = 'PP1:';

	public static function get_migration_mode(): string
	{
		$mode = get_option(self::MIGRATION_MODE_KEY, self::MIGRATION_MODE_LEGACY);
		if (!is_string($mode) || $mode === '') {
			return self::MIGRATION_MODE_LEGACY;
		}

		return $mode === self::MIGRATION_MODE_PIPELINE
			? self::MIGRATION_MODE_PIPELINE
			: self::MIGRATION_MODE_LEGACY;
	}

	public static function set_migration_mode(string $mode): void
	{
		$normalized = $mode === self::MIGRATION_MODE_PIPELINE
			? self::MIGRATION_MODE_PIPELINE
			: self::MIGRATION_MODE_LEGACY;
		update_option(self::MIGRATION_MODE_KEY, $normalized);
	}

	public static function plugin_id(): string
	{
		$id = get_option(self::PLUGIN_ID_KEY, '');

		return is_string($id) ? $id : '';
	}

	public static function set_plugin_id(string $plugin_id): void
	{
		update_option(self::PLUGIN_ID_KEY, trim($plugin_id));
	}

	public static function is_connected(): bool
	{
		return self::plugin_id() !== '' && self::site_to_pp_secret() !== '';
	}

	/**
	 * Whether the site has enabled legacy autoposting templates (enable=1).
	 */
	public static function has_enabled_legacy_templates(): bool
	{
		$rows = DBAutopostingTable::get_all(true);

		return !empty($rows);
	}

	/**
	 * Clear plugin binding secrets and pipeline cache (local disconnect).
	 */
	public static function disconnect(): void
	{
		delete_option(self::PLUGIN_ID_KEY);
		delete_option(self::SITE_TO_PP_KEY);
		delete_option(self::PP_TO_SITE_HASH_KEY);
		delete_option(self::PP_TO_SITE_PREV_HASH_KEY);
		self::set_migration_mode(self::MIGRATION_MODE_LEGACY);
		self::set_pipeline_ids([]);
		delete_option(self::PIPELINE_CONTRACTS_KEY);
	}

	public static function site_to_pp_secret(): string
	{
		$raw = get_option(self::SITE_TO_PP_KEY, '');
		if (!is_string($raw) || $raw === '') {
			return '';
		}
		if (self::secret_stored_is_encrypted($raw)) {
			$plain = self::decrypt_stored_secret($raw);

			return $plain !== false ? $plain : '';
		}
		self::migrate_plain_secret_to_encrypted($raw);

		return $raw;
	}

	public static function set_site_to_pp_secret(string $secret): void
	{
		$secret = trim($secret);
		if ($secret === '') {
			delete_option(self::SITE_TO_PP_KEY);

			return;
		}
		$blob = self::encrypt_secret_for_storage($secret);
		update_option(self::SITE_TO_PP_KEY, $blob !== false ? $blob : $secret);
	}

	/**
	 * Store HMAC hash of pp_to_site secret (SPEC-002-01 §7.1). Plaintext is never persisted.
	 */
	public static function set_pp_to_site_secret(string $secret, bool $as_previous = false): void
	{
		$secret = trim($secret);
		$key = $as_previous ? self::PP_TO_SITE_PREV_HASH_KEY : self::PP_TO_SITE_HASH_KEY;
		if ($secret === '') {
			delete_option($key);

			return;
		}
		update_option($key, self::hash_pp_to_site_secret($secret));
	}

	public static function clear_pp_to_site_prev_secret(): void
	{
		delete_option(self::PP_TO_SITE_PREV_HASH_KEY);
	}

	/**
	 * Constant-time verify for inbound PP→site HTTP (Bearer or X-ParrotPoster-Secret).
	 */
	public static function verify_pp_to_site_secret(string $secret): bool
	{
		$secret = trim($secret);
		if ($secret === '') {
			return false;
		}
		$hash = self::hash_pp_to_site_secret($secret);
		$current = get_option(self::PP_TO_SITE_HASH_KEY, '');
		if (is_string($current) && $current !== '' && hash_equals($current, $hash)) {
			return true;
		}
		$prev = get_option(self::PP_TO_SITE_PREV_HASH_KEY, '');
		if (is_string($prev) && $prev !== '' && hash_equals($prev, $hash)) {
			return true;
		}

		return false;
	}

	public static function has_pp_to_site_secret(): bool
	{
		$current = get_option(self::PP_TO_SITE_HASH_KEY, '');

		return is_string($current) && $current !== '';
	}

	private static function hash_pp_to_site_secret(string $secret): string
	{
		return hash_hmac('sha256', $secret, self::pp_to_site_pepper());
	}

	private static function pp_to_site_pepper(): string
	{
		$pepper = get_option(self::PP_TO_SITE_PEPPER_KEY, '');
		if (!is_string($pepper) || strlen($pepper) < 32) {
			$pepper = wp_generate_password(64, true, true);
			update_option(self::PP_TO_SITE_PEPPER_KEY, $pepper, false);
		}

		return $pepper;
	}

	/**
	 * @return list<string>
	 */
	public static function get_pipeline_ids(): array
	{
		$raw = get_option(self::PIPELINE_IDS_KEY, []);
		if (!is_array($raw)) {
			return [];
		}

		$ids = [];
		foreach ($raw as $id) {
			if (!is_string($id) || trim($id) === '') {
				continue;
			}
			$ids[] = trim($id);
		}

		return array_values(array_unique($ids));
	}

	/**
	 * @param list<string> $pipeline_ids
	 */
	public static function set_pipeline_ids(array $pipeline_ids): void
	{
		$normalized = [];
		foreach ($pipeline_ids as $id) {
			if (!is_string($id) || trim($id) === '') {
				continue;
			}
			$normalized[] = trim($id);
		}
		update_option(self::PIPELINE_IDS_KEY, array_values(array_unique($normalized)));
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	public static function get_pipeline_contracts(): array
	{
		$raw = get_option(self::PIPELINE_CONTRACTS_KEY, []);
		if (!is_array($raw)) {
			return [];
		}

		$contracts = [];
		foreach ($raw as $pipeline_id => $contract) {
			if (!is_string($pipeline_id) || !is_array($contract)) {
				continue;
			}
			$contracts[$pipeline_id] = $contract;
		}

		return $contracts;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public static function get_pipeline_contract(string $pipeline_id): ?array
	{
		$pipeline_id = trim($pipeline_id);
		if ($pipeline_id === '') {
			return null;
		}
		$contracts = self::get_pipeline_contracts();

		return isset($contracts[$pipeline_id]) && is_array($contracts[$pipeline_id])
			? $contracts[$pipeline_id]
			: null;
	}

	/**
	 * @param array<string, mixed> $contract
	 */
	public static function set_pipeline_contract(string $pipeline_id, array $contract): void
	{
		$pipeline_id = trim($pipeline_id);
		if ($pipeline_id === '') {
			return;
		}
		$contracts = self::get_pipeline_contracts();
		$contracts[$pipeline_id] = $contract;
		update_option(self::PIPELINE_CONTRACTS_KEY, $contracts);
	}

	/**
	 * @param array<string, mixed> $snapshot
	 */
	public static function apply_pipeline_contract_snapshot(array $snapshot): void
	{
		$pipeline_id = isset($snapshot['pipelineId']) ? (string) $snapshot['pipelineId'] : '';
		if ($pipeline_id === '' && isset($snapshot['pipeline_id'])) {
			$pipeline_id = (string) $snapshot['pipeline_id'];
		}
		if ($pipeline_id === '') {
			return;
		}

		$contract = self::get_pipeline_contract($pipeline_id);
		if (!is_array($contract)) {
			$contract = [];
		}

		if (isset($snapshot['contractVersion'])) {
			$contract['contract_version'] = (int) $snapshot['contractVersion'];
		} elseif (isset($snapshot['contract_version'])) {
			$contract['contract_version'] = (int) $snapshot['contract_version'];
		}
		if (isset($snapshot['contractHash'])) {
			$contract['contract_hash'] = (string) $snapshot['contractHash'];
		} elseif (isset($snapshot['contract_hash'])) {
			$contract['contract_hash'] = (string) $snapshot['contract_hash'];
		}
		if (isset($snapshot['requiredFields']) && is_array($snapshot['requiredFields'])) {
			$contract['required_fields'] = array_values($snapshot['requiredFields']);
		} elseif (isset($snapshot['required_fields']) && is_array($snapshot['required_fields'])) {
			$contract['required_fields'] = array_values($snapshot['required_fields']);
		}
		if (isset($snapshot['sourcePath']) && is_array($snapshot['sourcePath'])) {
			$contract['source_path'] = $snapshot['sourcePath'];
		} elseif (isset($snapshot['source_path']) && is_array($snapshot['source_path'])) {
			$contract['source_path'] = $snapshot['source_path'];
		}
		if (isset($snapshot['sourceFilters']) && is_array($snapshot['sourceFilters'])) {
			$contract['source_filters'] = $snapshot['sourceFilters'];
		} elseif (isset($snapshot['source_filters']) && is_array($snapshot['source_filters'])) {
			$contract['source_filters'] = $snapshot['source_filters'];
		}
		// notify_contract (SPEC-002-09 §7.5) sends scope inside source_filters only.
		// Always re-sync top-level scope_filter from that nest so a later notify
		// cannot leave a stale top-level value from the previous snapshot.
		if (is_array($contract['source_filters'] ?? null)) {
			$source_filters = $contract['source_filters'];
			if (array_key_exists('scopeFilter', $source_filters)) {
				$contract['scope_filter'] = $source_filters['scopeFilter'];
			} elseif (array_key_exists('scope_filter', $source_filters)) {
				$contract['scope_filter'] = $source_filters['scope_filter'];
			}
		}
		// Explicit top-level scope wins when present (mismatch / alternate payloads).
		if (array_key_exists('scopeFilter', $snapshot)) {
			$contract['scope_filter'] = $snapshot['scopeFilter'];
		} elseif (array_key_exists('scope_filter', $snapshot)) {
			$contract['scope_filter'] = $snapshot['scope_filter'];
		}
		if (isset($snapshot['templateRequiredFields']) && is_array($snapshot['templateRequiredFields'])) {
			$contract['template_required_fields'] = array_values($snapshot['templateRequiredFields']);
		} elseif (isset($snapshot['template_required_fields']) && is_array($snapshot['template_required_fields'])) {
			$contract['template_required_fields'] = array_values($snapshot['template_required_fields']);
		}

		self::set_pipeline_contract($pipeline_id, $contract);
		self::add_pipeline_id($pipeline_id);
	}

	/**
	 * Append pipeline id to the local cache if not already present.
	 */
	public static function add_pipeline_id(string $pipeline_id): void
	{
		$pipeline_id = trim($pipeline_id);
		if ($pipeline_id === '') {
			return;
		}
		$ids = self::get_pipeline_ids();
		if (in_array($pipeline_id, $ids, true)) {
			return;
		}
		$ids[] = $pipeline_id;
		self::set_pipeline_ids($ids);
		self::set_migration_mode(self::MIGRATION_MODE_PIPELINE);
	}

	/**
	 * Update migration_mode from heartbeat / plugin info response (WP-02).
	 *
	 * @param array<string, mixed> $response
	 */
	public static function update_from_heartbeat(array $response): void
	{
		if (isset($response['migration_mode']) && is_string($response['migration_mode'])) {
			self::set_migration_mode($response['migration_mode']);
		} elseif (isset($response['migrationMode']) && is_string($response['migrationMode'])) {
			self::set_migration_mode(strtolower($response['migrationMode']));
		}

		if (isset($response['pipeline_ids_from_migration']) && is_array($response['pipeline_ids_from_migration'])) {
			self::set_pipeline_ids($response['pipeline_ids_from_migration']);
		} elseif (isset($response['pipelineIdsFromMigration']) && is_array($response['pipelineIdsFromMigration'])) {
			self::set_pipeline_ids($response['pipelineIdsFromMigration']);
		}
	}

	/**
	 * scope_filter AST from contract (top-level or nested in source_filters).
	 *
	 * @param array<string, mixed> $contract
	 * @return mixed|null
	 */
	public static function resolve_scope_filter(array $contract)
	{
		if (array_key_exists('scope_filter', $contract)) {
			return $contract['scope_filter'];
		}
		if (array_key_exists('scopeFilter', $contract)) {
			return $contract['scopeFilter'];
		}
		$source_filters = $contract['source_filters'] ?? $contract['sourceFilters'] ?? null;
		if (!is_array($source_filters)) {
			return null;
		}
		if (array_key_exists('scope_filter', $source_filters)) {
			return $source_filters['scope_filter'];
		}
		if (array_key_exists('scopeFilter', $source_filters)) {
			return $source_filters['scopeFilter'];
		}

		return null;
	}

	/**
	 * @param array<string, mixed> $contract
	 * @return list<string>
	 */
	public static function resolve_template_required_fields(array $contract): array
	{
		$fields = $contract['template_required_fields'] ?? $contract['templateRequiredFields'] ?? [];
		if (!is_array($fields)) {
			return [];
		}

		$normalized = [];
		foreach ($fields as $field) {
			if (!is_string($field) || trim($field) === '') {
				continue;
			}
			$normalized[] = trim($field);
		}

		return array_values(array_unique($normalized));
	}

	/**
	 * @param array<string, mixed> $contract
	 * @return list<string>
	 */
	public static function resolve_required_fields(array $contract): array
	{
		$fields = $contract['required_fields'] ?? $contract['requiredFields'] ?? [];
		if (!is_array($fields)) {
			return [];
		}

		$normalized = [];
		foreach ($fields as $field) {
			if (!is_string($field) || trim($field) === '') {
				continue;
			}
			$normalized[] = trim($field);
		}

		return array_values(array_unique($normalized));
	}

	/**
	 * Pipelines whose source_path matches the post type.
	 *
	 * @return list<array{pipeline_id: string, contract_version: int, contract: array<string, mixed>}>
	 */
	public static function get_pipelines_for_post(\WP_Post $post): array
	{
		$pipeline_ids = self::get_pipeline_ids();
		if (empty($pipeline_ids)) {
			return [];
		}

		$matches = [];
		foreach ($pipeline_ids as $pipeline_id) {
			$contract = self::get_pipeline_contract($pipeline_id);
			if (!is_array($contract)) {
				$contract = [];
			}
			if (!self::post_matches_source_path($post, $contract)) {
				continue;
			}
			$matches[] = [
				'pipeline_id' => $pipeline_id,
				'contract_version' => (int) ($contract['contract_version'] ?? 0),
				'contract' => $contract,
			];
		}

		return $matches;
	}

	/**
	 * @param array<string, mixed> $contract
	 */
	private static function post_matches_source_path(\WP_Post $post, array $contract): bool
	{
		$source_path = $contract['source_path'] ?? null;
		if (!is_array($source_path) || empty($source_path)) {
			return true;
		}

		$post_type = (string) $post->post_type;
		foreach ($source_path as $step) {
			if (!is_array($step)) {
				continue;
			}
			$key = '';
			if (isset($step['key']) && is_string($step['key'])) {
				$key = $step['key'];
			} elseif (isset($step['step']) && is_string($step['step'])) {
				$key = $step['step'];
			}
			$value = isset($step['value']) ? (string) $step['value'] : '';
			if ($key === 'post_type' || $key === 'wp_post_type') {
				return $value === '' || $value === $post_type;
			}
		}

		return true;
	}

	private static function secret_stored_is_encrypted(string $raw): bool
	{
		return strncmp($raw, self::SECRET_CIPHER_PREFIX, strlen(self::SECRET_CIPHER_PREFIX)) === 0;
	}

	/**
	 * @return string|false
	 */
	private static function encrypt_secret_for_storage(string $plaintext)
	{
		if (!function_exists('openssl_encrypt') || !function_exists('openssl_cipher_iv_length')) {
			return false;
		}
		$method = 'aes-256-cbc';
		if (!in_array($method, openssl_get_cipher_methods(), true)) {
			return false;
		}
		$key = self::get_secret_cipher_key();
		$ivlen = openssl_cipher_iv_length($method);
		$iv = openssl_random_pseudo_bytes($ivlen);
		if ($iv === false) {
			return false;
		}
		$cipher = openssl_encrypt($plaintext, $method, $key, OPENSSL_RAW_DATA, $iv);
		if ($cipher === false) {
			return false;
		}

		return self::SECRET_CIPHER_PREFIX . base64_encode($iv . $cipher);
	}

	/**
	 * @return string|false
	 */
	private static function decrypt_stored_secret(string $blob)
	{
		$payload = substr($blob, strlen(self::SECRET_CIPHER_PREFIX));
		$raw = base64_decode($payload, true);
		if ($raw === false || $raw === '') {
			return false;
		}
		if (!function_exists('openssl_decrypt') || !function_exists('openssl_cipher_iv_length')) {
			return false;
		}
		$method = 'aes-256-cbc';
		$key = self::get_secret_cipher_key();
		$ivlen = openssl_cipher_iv_length($method);
		if (strlen($raw) < $ivlen + 1) {
			return false;
		}
		$iv = substr($raw, 0, $ivlen);
		$ciphertext = substr($raw, $ivlen);
		$plain = openssl_decrypt($ciphertext, $method, $key, OPENSSL_RAW_DATA, $iv);
		if ($plain === false) {
			return false;
		}

		return $plain;
	}

	private static function migrate_plain_secret_to_encrypted(string $plaintext): void
	{
		$blob = self::encrypt_secret_for_storage($plaintext);
		if ($blob !== false) {
			update_option(self::SITE_TO_PP_KEY, $blob);
		}
	}

	private static function get_secret_cipher_key(): string
	{
		$material = 'parrotposter/wp-site-to-pp/v1';
		if (function_exists('wp_salt')) {
			$material .= wp_salt('auth') . wp_salt('secure_auth');
		}

		return hash('sha256', $material, true);
	}
}

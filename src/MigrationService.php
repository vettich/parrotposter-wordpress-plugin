<?php

namespace parrotposter;

defined('ABSPATH') || exit;

/**
 * GraphQL migration calls (SPEC-002-17).
 */
class MigrationService
{
	/**
	 * @param list<string>|null $config_ids Autoposting template ids; null = all rows.
	 * @return array{success: bool, pipelines_created?: int, error?: string, warnings?: list<string>}
	 */
	public static function migrate_to_pipeline(?array $config_ids = null): array
	{
		$plugin_id = Settings::plugin_id();
		if ($plugin_id === '') {
			return ['success' => false, 'error' => 'plugin_id is not configured'];
		}

		if ($config_ids === null) {
			$config_ids = self::all_autoposting_config_ids();
		}

		$normalized_ids = [];
		foreach ($config_ids as $id) {
			if (!is_string($id) && !is_numeric($id)) {
				continue;
			}
			$id = trim((string) $id);
			if ($id !== '') {
				$normalized_ids[] = $id;
			}
		}
		$normalized_ids = array_values(array_unique($normalized_ids));

		$res = Api::graphql_user_mutation('migratePluginToPipeline', [
			'pluginId' => $plugin_id,
			'configIds' => $normalized_ids,
		]);

		if (!empty($res['error'])) {
			$msg = isset($res['error']['msg']) ? (string) $res['error']['msg'] : 'migration failed';

			return ['success' => false, 'error' => $msg];
		}

		$payload = self::extract_migrate_payload($res['data'] ?? []);
		if ($payload === null) {
			return ['success' => false, 'error' => 'invalid migration response'];
		}

		$user_errors = isset($payload['errors']) && is_array($payload['errors']) ? $payload['errors'] : [];
		if (!empty($user_errors)) {
			$first = $user_errors[0];
			$msg = is_array($first) && !empty($first['message'])
				? (string) $first['message']
				: 'migration rejected';

			return ['success' => false, 'error' => $msg];
		}

		$pipelines = isset($payload['pipelines']) && is_array($payload['pipelines'])
			? $payload['pipelines']
			: [];
		$pipeline_ids = [];
		foreach ($pipelines as $pipeline) {
			if (!is_array($pipeline)) {
				continue;
			}
			$id = isset($pipeline['id']) ? (string) $pipeline['id'] : '';
			if ($id !== '') {
				$pipeline_ids[] = $id;
			}
		}
		if (!empty($pipeline_ids)) {
			Settings::set_pipeline_ids($pipeline_ids);
		}

		$plugin = isset($payload['plugin']) && is_array($payload['plugin']) ? $payload['plugin'] : [];
		if (!empty($plugin)) {
			Settings::update_from_heartbeat($plugin);
		} else {
			Settings::set_migration_mode(Settings::MIGRATION_MODE_PIPELINE);
		}

		$warnings = [];
		if (isset($payload['warnings']) && is_array($payload['warnings'])) {
			foreach ($payload['warnings'] as $warning) {
				if (is_string($warning) && $warning !== '') {
					$warnings[] = $warning;
				}
			}
		}

		$result = [
			'success' => true,
			'pipelines_created' => count($pipelines),
		];
		if (!empty($warnings)) {
			$result['warnings'] = $warnings;
		}

		return $result;
	}

	/**
	 * @return array{success: bool, error?: string}
	 */
	public static function revert_migration(): array
	{
		$plugin_id = Settings::plugin_id();
		if ($plugin_id === '') {
			return ['success' => false, 'error' => 'plugin_id is not configured'];
		}

		$res = Api::graphql_user_mutation('revertPluginToLegacy', [
			'pluginId' => $plugin_id,
		]);

		if (!empty($res['error'])) {
			$msg = isset($res['error']['msg']) ? (string) $res['error']['msg'] : 'revert failed';

			return ['success' => false, 'error' => $msg];
		}

		$payload = self::extract_revert_payload($res['data'] ?? []);
		if ($payload === null) {
			return ['success' => false, 'error' => 'invalid revert response'];
		}

		$user_errors = isset($payload['errors']) && is_array($payload['errors']) ? $payload['errors'] : [];
		if (!empty($user_errors)) {
			$first = $user_errors[0];
			$msg = is_array($first) && !empty($first['message'])
				? (string) $first['message']
				: 'revert rejected';

			return ['success' => false, 'error' => $msg];
		}

		$plugin = isset($payload['plugin']) && is_array($payload['plugin']) ? $payload['plugin'] : [];
		if (!empty($plugin)) {
			Settings::update_from_heartbeat($plugin);
		} else {
			Settings::set_migration_mode(Settings::MIGRATION_MODE_LEGACY);
		}

		return ['success' => true];
	}

	/**
	 * @return list<string>
	 */
	private static function all_autoposting_config_ids(): array
	{
		$rows = DBAutopostingTable::get_all(false);
		$ids = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$id = isset($row['id']) ? (string) $row['id'] : '';
			if ($id !== '') {
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>|null
	 */
	private static function extract_migrate_payload(array $data): ?array
	{
		if (!isset($data['migratePluginToPipeline']) || !is_array($data['migratePluginToPipeline'])) {
			return null;
		}

		return $data['migratePluginToPipeline'];
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>|null
	 */
	private static function extract_revert_payload(array $data): ?array
	{
		if (!isset($data['revertPluginToLegacy']) || !is_array($data['revertPluginToLegacy'])) {
			return null;
		}

		return $data['revertPluginToLegacy'];
	}
}

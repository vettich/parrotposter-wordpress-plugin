<?php

namespace parrotposter;

defined('ABSPATH') || exit;

use parrotposter\fields\Fields;
use parrotposter\fields\conditions\Taxonomies as ConditionsTaxonomies;
use WP_REST_Request;
use WP_REST_Response;

/**
 * CMS-side REST endpoints for PP→site wire protocol (SPEC-002-09 §7.4 subset).
 */
class WireProtocol
{
	private const RATE_LIMIT_MAX = 60;

	private const RATE_LIMIT_WINDOW_SEC = 60;

	/**
	 * Template-only / legacy macros excluded from pipeline FieldSchema.
	 * `content_first_paragraph` remains in CommonType for legacy scheduler;
	 * pipeline UI uses `content` + Liquid `first_paragraph` instead.
	 */
	private const FIELD_SCHEMA_SKIP_KEYS = ['br', 'content_first_paragraph'];

	/** Instant MVP field keys (SPEC-002-08). */
	private const INSTANT_MVP_FIELD_KEYS = [
		'title',
		'excerpt',
		'content',
		'link',
		'date',
		'featured_image',
		'images_in_content',
		'featured_video',
		'videos_in_content',
		'attached_videos',
		'gifs_in_content',
		'attached_audio',
		'attached_documents',
	];

	private const MEDIA_FIELD_KEYS = [
		'featured_image',
		'images_in_content',
		'product_image',
		'product_gallery',
		'featured_video',
		'videos_in_content',
		'attached_videos',
		'gifs_in_content',
		'attached_audio',
		'attached_documents',
	];

	/** Post media fields for the `media` schema section (Woo product_* stay in `woocommerce`). */
	private const SECTION_MEDIA_FIELD_KEYS = [
		'featured_image',
		'images_in_content',
		'featured_video',
		'videos_in_content',
		'attached_videos',
		'gifs_in_content',
		'attached_audio',
		'attached_documents',
	];

	private const FIELD_SECTION_BASIC = 'basic';

	private const FIELD_SECTION_MEDIA = 'media';

	private const FIELD_SECTION_TAXONOMIES = 'taxonomies';

	private const FIELD_SECTION_WOOCOMMERCE = 'woocommerce';

	public static function register_rest_routes(): void
	{
		$get_suffixes = [
			'info',
			'fields',
			'items/latest',
			'items/(?P<item_id>[^/]+)',
			'autopost-configs',
		];
		foreach ($get_suffixes as $suffix) {
			foreach ([$suffix, 'pp/v1/' . $suffix] as $route) {
				register_rest_route('parrotposter/v1', $route, [
					'methods' => \WP_REST_Server::READABLE,
					'callback' => [self::class, 'dispatch'],
					'permission_callback' => [self::class, 'authorize_request'],
					'args' => self::route_args($route),
				]);
			}
		}

		// BE-18 / WP-05: POST JSON only (no GET compat).
		foreach (['items/next', 'pp/v1/items/next'] as $route) {
			register_rest_route('parrotposter/v1', $route, [
				'methods' => \WP_REST_Server::CREATABLE,
				'callback' => [self::class, 'dispatch'],
				'permission_callback' => [self::class, 'authorize_request'],
			]);
		}

		// WP-06: full published exclude snapshot (PP pushes pages).
		foreach (['published_ids_sync', 'pp/v1/published_ids_sync'] as $route) {
			register_rest_route('parrotposter/v1', $route, [
				'methods' => \WP_REST_Server::CREATABLE,
				'callback' => [self::class, 'dispatch'],
				'permission_callback' => [self::class, 'authorize_request'],
			]);
		}

		foreach (['notify_contract', 'pp/v1/notify_contract'] as $route) {
			register_rest_route('parrotposter/v1', $route, [
				'methods' => \WP_REST_Server::CREATABLE,
				'callback' => [self::class, 'dispatch_notify_contract'],
				'permission_callback' => [self::class, 'authorize_request'],
			]);
		}
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function route_args(string $route): array
	{
		$args = [];

		// Routes are registered as `fields` and `pp/v1/fields` — do not require a leading `/`.
		$needs_post_type = (bool) preg_match('#(?:^|/)fields$#', $route)
			|| strpos($route, 'items/latest') !== false;
		if ($needs_post_type) {
			$args['post_type'] = [
				'type' => 'string',
				'required' => true,
				'sanitize_callback' => 'sanitize_key',
			];
		} elseif (strpos($route, 'items/(?P<item_id>') === false) {
			$args['post_type'] = [
				'type' => 'string',
				'default' => 'post',
				'sanitize_callback' => 'sanitize_key',
			];
		}

		if (strpos($route, 'items/latest') !== false) {
			$args['limit'] = [
				'type' => 'integer',
				'default' => 5,
				'minimum' => 1,
				'maximum' => 50,
			];
			$args['offset'] = [
				'type' => 'integer',
				'default' => 0,
				'minimum' => 0,
			];
			$args['filter'] = [
				'type' => 'string',
				'required' => false,
			];
			// JSON array of field keys for template preview (draft required_fields).
			$args['required_fields'] = [
				'type' => 'string',
				'required' => false,
			];
		}

		if (strpos($route, 'items/(?P<item_id>') !== false) {
			$args['item_id'] = [
				'type' => 'string',
				'required' => true,
				'sanitize_callback' => static function ($value) {
					return is_string($value) ? rawurldecode($value) : '';
				},
			];
		}

		if (
			strpos($route, 'items/(?P<item_id>') !== false
			|| strpos($route, 'items/latest') !== false
		) {
			$args['pipeline_id'] = [
				'type' => 'string',
				'required' => false,
				'sanitize_callback' => static function ($value) {
					return is_string($value) ? trim($value) : '';
				},
			];
		}

		return $args;
	}

	/**
	 * @param WP_REST_Request $request
	 * @return true|\WP_Error
	 */
	public static function authorize_request(WP_REST_Request $request)
	{
		if (!self::check_rate_limit()) {
			return new \WP_Error(
				'rate_limited',
				'Too many requests',
				['status' => 429]
			);
		}

		if (!Settings::has_pp_to_site_secret()) {
			return new \WP_Error(
				'unauthorized',
				'Plugin secret is not configured',
				['status' => 401]
			);
		}

		$secret = self::secret_from_request($request);
		if ($secret === '' || !Settings::verify_pp_to_site_secret($secret)) {
			return new \WP_Error(
				'unauthorized',
				'Invalid secret',
				['status' => 401]
			);
		}

		return true;
	}

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function dispatch(WP_REST_Request $request)
	{
		$route = (string) $request->get_route();
		if (strpos($route, '/items/next') !== false) {
			$result = self::handle_items_next($request);
			if (is_wp_error($result)) {
				return $result;
			}

			return self::respond($result);
		}
		if (strpos($route, '/published_ids_sync') !== false) {
			$result = self::handle_published_ids_sync($request);
			if (is_wp_error($result)) {
				return $result;
			}

			return self::respond($result);
		}
		if (strpos($route, '/items/latest') !== false) {
			$result = self::handle_items_latest($request);
			if (is_wp_error($result)) {
				return $result;
			}

			return self::respond($result);
		}
		$item_id = $request->get_param('item_id');
		if (is_string($item_id) && $item_id !== '') {
			return self::respond(self::handle_item_by_id($item_id, $request));
		}
		if (strpos($route, '/fields') !== false) {
			$result = self::handle_fields($request);
			if (is_wp_error($result)) {
				return $result;
			}

			return self::respond($result);
		}
		if (strpos($route, '/autopost-configs') !== false) {
			return self::respond(self::handle_autopost_configs());
		}

		return self::respond(self::handle_info());
	}

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response|\WP_Error
	 */
	public static function dispatch_notify_contract(WP_REST_Request $request)
	{
		$body = $request->get_json_params();
		if (!is_array($body)) {
			return new WP_REST_Response([
				'ok' => false,
				'error' => [
					'code' => 'invalid_payload',
					'message' => 'JSON body required',
				],
			], 400);
		}

		$pipeline_id = isset($body['pipeline_id']) ? (string) $body['pipeline_id'] : '';
		if ($pipeline_id === '' && isset($body['pipelineId'])) {
			$pipeline_id = (string) $body['pipelineId'];
		}
		if ($pipeline_id === '') {
			return new WP_REST_Response([
				'ok' => false,
				'error' => [
					'code' => 'invalid_payload',
					'message' => 'pipeline_id is required',
				],
			], 400);
		}

		Settings::apply_pipeline_contract_snapshot($body);

		return new WP_REST_Response(['cached' => true], 200);
	}

	/**
	 * @param array<string, mixed>|null $payload
	 */
	private static function respond(?array $payload, int $status = 200): WP_REST_Response
	{
		if ($payload === null) {
			return new WP_REST_Response([
				'ok' => false,
				'error' => [
					'code' => 'not_found',
					'message' => 'Item not found',
				],
			], 404);
		}

		return new WP_REST_Response($payload, $status);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function handle_info(): array
	{
		$capabilities = defined('PARROTPOSTER_CAPABILITIES') && is_array(PARROTPOSTER_CAPABILITIES)
			? array_values(PARROTPOSTER_CAPABILITIES)
			: ['push_events'];

		$post_types = [];
		foreach (WpPostHelpers::get_post_types('object') as $slug => $object) {
			$label = '';
			if (is_object($object) && isset($object->labels->name) && is_string($object->labels->name)) {
				$label = $object->labels->name;
			}
			$post_types[] = [
				'value' => (string) $slug,
				'label' => $label !== '' ? $label : (string) $slug,
			];
		}

		return [
			'migration_mode' => Settings::get_migration_mode(),
			'capabilities' => $capabilities,
			'plugin_version' => defined('PARROTPOSTER_VERSION') ? (string) PARROTPOSTER_VERSION : '',
			'plugin_id' => Settings::plugin_id(),
			'post_types' => $post_types,
			'filter_capabilities' => SelectionFilterQuery::filter_capabilities(),
		];
	}

	/**
	 * Legacy autoposting templates for migration wizard (SPEC-002-17 §3.2).
	 *
	 * @return array<string, mixed>
	 */
	private static function handle_autopost_configs(): array
	{
		$rows = DBAutopostingTable::get_all(false);
		$configs = [];
		foreach ($rows as $row) {
			if (!is_array($row)) {
				continue;
			}
			$id = isset($row['id']) ? (string) $row['id'] : '';
			if ($id === '') {
				continue;
			}
			$account_ids = [];
			if (isset($row['account_ids']) && is_array($row['account_ids'])) {
				foreach ($row['account_ids'] as $aid) {
					if (is_string($aid) || is_numeric($aid)) {
						$account_ids[] = (string) $aid;
					}
				}
			}
			$post_images = [];
			if (isset($row['post_images']) && is_array($row['post_images'])) {
				foreach ($row['post_images'] as $img_field) {
					if (!is_string($img_field) && !is_numeric($img_field)) {
						continue;
					}
					$key = self::normalize_field_key((string) $img_field);
					if ($key !== '') {
						$post_images[] = $key;
					}
				}
			}
			$conditions = isset($row['conditions']) && is_array($row['conditions'])
				? $row['conditions']
				: [];
			$configs[] = [
				'id' => $id,
				'name' => isset($row['name']) ? (string) $row['name'] : '',
				'post_type' => isset($row['wp_post_type']) ? (string) $row['wp_post_type'] : 'post',
				'template' => isset($row['post_text']) ? (string) $row['post_text'] : '',
				'post_link' => isset($row['post_link']) ? (string) $row['post_link'] : '',
				'post_images' => $post_images,
				'accounts' => $account_ids,
				'conditions' => $conditions,
				'enabled' => !empty($row['enable']),
			];
		}

		return ['configs' => $configs];
	}

	/**
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function handle_fields(WP_REST_Request $request)
	{
		$post_type = self::require_valid_post_type($request);
		if (is_wp_error($post_type)) {
			return $post_type;
		}

		$legacy_fields = Fields::get_fields($post_type, ['text', 'link', 'date', 'image']);
		$fields_by_key = [];
		$order = 10;
		foreach ($legacy_fields as $field) {
			if (!is_array($field)) {
				continue;
			}
			$key = self::normalize_field_key((string) ($field['key'] ?? ''));
			if ($key === '' || in_array($key, self::FIELD_SCHEMA_SKIP_KEYS, true)) {
				continue;
			}
			$fields_by_key[$key] = [
				'key' => $key,
				'type' => self::field_storage_type($key),
				'semantic' => self::field_semantic($key),
				'label' => (string) ($field['label'] ?? $key),
				'section_id' => self::field_section_id($key),
				'order' => $order,
				'sortable' => self::field_is_sortable($key),
				'filterable' => self::field_is_filterable($key),
			];
			$order += 10;
		}

		foreach (self::INSTANT_MVP_FIELD_KEYS as $mvp_key) {
			if (isset($fields_by_key[$mvp_key])) {
				continue;
			}
			$fields_by_key[$mvp_key] = [
				'key' => $mvp_key,
				'type' => self::field_storage_type($mvp_key),
				'semantic' => self::field_semantic($mvp_key),
				'label' => self::instant_mvp_field_label($mvp_key),
				'section_id' => self::field_section_id($mvp_key),
				'order' => $order,
				'sortable' => self::field_is_sortable($mvp_key),
				'filterable' => self::field_is_filterable($mvp_key),
			];
			$order += 10;
		}

		$taxonomy_keys = self::taxonomy_keys_for_post_type($post_type);
		foreach ($taxonomy_keys as $tax_key) {
			if (!isset($fields_by_key[$tax_key])) {
				$tax = get_taxonomy($tax_key);
				$fields_by_key[$tax_key] = [
					'key' => $tax_key,
					'type' => 'array',
					'semantic' => 'taxonomy',
					'label' => $tax instanceof \WP_Taxonomy ? (string) $tax->label : $tax_key,
					'section_id' => self::FIELD_SECTION_TAXONOMIES,
					'order' => $order,
					'sortable' => false,
					'filterable' => true,
				];
				$order += 10;
			} else {
				$fields_by_key[$tax_key]['type'] = 'array';
				$fields_by_key[$tax_key]['semantic'] = 'taxonomy';
				$fields_by_key[$tax_key]['section_id'] = self::FIELD_SECTION_TAXONOMIES;
			}
			$fields_by_key[$tax_key]['value_schema'] = [
				'kind' => 'options',
				'options_mode' => 'inline',
				'options' => self::taxonomy_value_options($tax_key),
			];
		}

		$has_woocommerce = false;
		foreach (array_keys($fields_by_key) as $field_key) {
			if (strpos((string) $field_key, 'product_') === 0) {
				$has_woocommerce = true;
				break;
			}
		}

		$sections = [
			['id' => self::FIELD_SECTION_BASIC, 'label' => __('Basic fields', 'parrotposter'), 'order' => 0],
			['id' => self::FIELD_SECTION_MEDIA, 'label' => __('Media', 'parrotposter'), 'order' => 1],
		];
		if ($taxonomy_keys !== []) {
			$sections[] = [
				'id' => self::FIELD_SECTION_TAXONOMIES,
				'label' => __('Taxonomies', 'parrotposter'),
				'order' => 2,
			];
		}
		if ($has_woocommerce) {
			$sections[] = [
				'id' => self::FIELD_SECTION_WOOCOMMERCE,
				'label' => __('WooCommerce', 'parrotposter'),
				'order' => 3,
			];
		}

		return [
			'fields' => array_values($fields_by_key),
			'sections' => $sections,
			'filter_capabilities' => SelectionFilterQuery::filter_capabilities(),
		];
	}

	/**
	 * Taxonomy names with show_ui for the post type (legacy scheduler parity).
	 *
	 * @return list<string>
	 */
	private static function taxonomy_keys_for_post_type(string $post_type): array
	{
		$taxonomies = get_object_taxonomies($post_type, 'objects');
		if (!is_array($taxonomies)) {
			return [];
		}

		$keys = [];
		foreach ($taxonomies as $tax) {
			if ($tax instanceof \WP_Taxonomy && $tax->show_ui) {
				$keys[] = (string) $tax->name;
			}
		}

		return $keys;
	}

	/**
	 * Inline options for taxonomy condition literals (term_id as string).
	 *
	 * @return list<array{value: string, label: string}>
	 */
	private static function taxonomy_value_options(string $taxonomy): array
	{
		$terms = ConditionsTaxonomies::get_terms($taxonomy);
		$options = [];
		foreach ($terms as $term) {
			if (!is_array($term)) {
				continue;
			}
			$value = isset($term['key']) ? (string) $term['key'] : '';
			if ($value === '') {
				continue;
			}
			$options[] = [
				'value' => $value,
				'label' => isset($term['label']) ? (string) $term['label'] : $value,
			];
		}

		return $options;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	private static function handle_item_by_id(string $source_item_id, WP_REST_Request $request): ?array
	{
		$parsed = self::parse_source_item_id($source_item_id);
		if ($parsed === null) {
			return null;
		}

		$post = get_post($parsed['post_id']);
		if (!$post instanceof \WP_Post || $post->post_type !== $parsed['post_type']) {
			return null;
		}
		if ($post->post_status !== 'publish') {
			return null;
		}

		return self::format_source_item($post, self::pipeline_id_from_request($request));
	}

	/**
	 * Sequential fetch_next (SPEC-002-09 §7.3.1 / BE-18): POST JSON body.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function handle_items_next(WP_REST_Request $request)
	{
		$body = $request->get_json_params();
		if (!is_array($body)) {
			return new \WP_Error(
				'invalid_payload',
				'JSON body required',
				['status' => 400]
			);
		}

		$pipeline_id = '';
		if (isset($body['pipeline_id']) && is_string($body['pipeline_id'])) {
			$pipeline_id = trim($body['pipeline_id']);
		} elseif (isset($body['pipelineId']) && is_string($body['pipelineId'])) {
			$pipeline_id = trim($body['pipelineId']);
		}
		if ($pipeline_id === '') {
			return new \WP_Error(
				'invalid_payload',
				'pipeline_id is required',
				['status' => 400]
			);
		}

		$contract_version = 0;
		if (isset($body['contract_version']) && is_numeric($body['contract_version'])) {
			$contract_version = (int) $body['contract_version'];
		}

		$source_path = isset($body['source_path']) && is_array($body['source_path'])
			? $body['source_path']
			: null;
		if ($source_path === null) {
			return new \WP_Error(
				'invalid_payload',
				'source_path is required',
				['status' => 400]
			);
		}

		$post_type = self::post_type_from_source_path($source_path);
		if ($post_type === '') {
			return new \WP_Error(
				'invalid_payload',
				'source_path must include post_type',
				['status' => 400]
			);
		}

		$allowed = WpPostHelpers::get_post_types('names');
		if (!in_array($post_type, $allowed, true)) {
			return new \WP_Error(
				'invalid_post_type',
				sprintf('Unknown post_type: %s', $post_type),
				['status' => 400]
			);
		}

		$selection_filter = $body['selection_filter'] ?? null;
		if ($selection_filter !== null && !is_array($selection_filter)) {
			return new \WP_Error(
				'invalid_payload',
				'selection_filter must be an object',
				['status' => 400]
			);
		}

		$sort = $body['sort'] ?? null;
		if ($sort !== null && !is_array($sort)) {
			return new \WP_Error(
				'invalid_payload',
				'sort must be an object',
				['status' => 400]
			);
		}

		// exclude_mode (post_source_ref | sequential_cycle) documents which list PP sent.
		// Inline: published_ids. Scale (>L): exclude_sync + local ExcludeCache.

		$exclude_ack = null;
		$published_ids = [];

		$exclude_sync = isset($body['exclude_sync']) && is_array($body['exclude_sync'])
			? $body['exclude_sync']
			: null;

		if ($exclude_sync !== null) {
			$sync_result = self::apply_exclude_sync($pipeline_id, $exclude_sync);
			if (is_wp_error($sync_result)) {
				return $sync_result;
			}
			$exclude_ack = $sync_result;
			$published_ids = ExcludeCache::list_ids($pipeline_id);
		} elseif (isset($body['published_ids']) && is_array($body['published_ids'])) {
			$published_ids = $body['published_ids'];
		}

		$run_exclude_ids = isset($body['run_exclude_ids']) && is_array($body['run_exclude_ids'])
			? $body['run_exclude_ids']
			: [];

		$exclude_post_ids = self::parse_exclude_post_ids(
			array_merge($published_ids, $run_exclude_ids)
		);

		$query_args = [
			'post_type' => $post_type,
			'post_status' => 'publish',
			'posts_per_page' => 1,
			'post__not_in' => $exclude_post_ids,
			'ignore_sticky_posts' => true,
			'no_found_rows' => true,
		];
		self::apply_sort_to_query($query_args, $sort);

		$filter_result = SelectionFilterQuery::apply($query_args, $selection_filter, $post_type);
		if (is_wp_error($filter_result)) {
			return $filter_result;
		}

		$cleanup = SelectionFilterQuery::install_where_hooks($query_args);
		try {
			$query = new \WP_Query($query_args);
		} finally {
			$cleanup();
		}

		$post = null;
		if (is_array($query->posts) && isset($query->posts[0]) && $query->posts[0] instanceof \WP_Post) {
			$post = $query->posts[0];
		}

		if ($post === null) {
			$result = [
				'item' => null,
				'contract_version' => $contract_version,
			];
			if ($exclude_ack !== null) {
				$result['exclude_ack'] = $exclude_ack;
			}
			return $result;
		}

		$extra_fields = self::collect_expression_fields($selection_filter);

		$result = [
			'item' => self::format_source_item($post, $pipeline_id, $extra_fields),
			'contract_version' => $contract_version,
		];
		if ($exclude_ack !== null) {
			$result['exclude_ack'] = $exclude_ack;
		}
		return $result;
	}

	/**
	 * Apply exclude_sync from fetch_next payload; return exclude_ack or WP_Error.
	 *
	 * @param array<string, mixed> $exclude_sync
	 * @return array{client_version: int}|\WP_Error
	 */
	private static function apply_exclude_sync(string $pipeline_id, array $exclude_sync)
	{
		$mode = isset($exclude_sync['mode']) ? (string) $exclude_sync['mode'] : '';
		$server_version = isset($exclude_sync['server_version'])
			? (int) $exclude_sync['server_version']
			: 0;

		if ($mode === 'full_snapshot_required') {
			return new \WP_Error(
				'full_snapshot_required',
				'Local exclude cache needs published_ids_sync before fetch_next',
				[
					'status' => 409,
					'server_version' => $server_version,
				]
			);
		}

		if ($mode !== 'delta') {
			return new \WP_Error(
				'invalid_payload',
				'exclude_sync.mode must be delta or full_snapshot_required',
				['status' => 400]
			);
		}

		$added = isset($exclude_sync['added']) && is_array($exclude_sync['added'])
			? $exclude_sync['added']
			: [];
		$removed = isset($exclude_sync['removed']) && is_array($exclude_sync['removed'])
			? $exclude_sync['removed']
			: [];

		ExcludeCache::apply_delta($pipeline_id, $server_version, $added, $removed);

		return ['client_version' => $server_version];
	}

	/**
	 * PP→site published_ids_sync — full snapshot pages (SPEC-002-09 §7.3.2).
	 *
	 * Payload includes source_item_ids (PP is source of truth).
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function handle_published_ids_sync(WP_REST_Request $request)
	{
		$body = $request->get_json_params();
		if (!is_array($body)) {
			return new \WP_Error(
				'invalid_payload',
				'JSON body required',
				['status' => 400]
			);
		}

		$pipeline_id = '';
		if (isset($body['pipeline_id']) && is_string($body['pipeline_id'])) {
			$pipeline_id = trim($body['pipeline_id']);
		}
		if ($pipeline_id === '') {
			return new \WP_Error(
				'invalid_payload',
				'pipeline_id is required',
				['status' => 400]
			);
		}

		$page = isset($body['page']) ? (int) $body['page'] : 1;
		if ($page < 1) {
			$page = 1;
		}

		$server_version = isset($body['server_version']) ? (int) $body['server_version'] : 0;
		$has_more = !empty($body['has_more']);
		$source_item_ids = isset($body['source_item_ids']) && is_array($body['source_item_ids'])
			? $body['source_item_ids']
			: [];

		ExcludeCache::apply_snapshot_page(
			$pipeline_id,
			$source_item_ids,
			$server_version,
			$page === 1
		);

		return [
			'source_item_ids' => array_values(array_map('strval', $source_item_ids)),
			'has_more' => $has_more,
			'server_version' => $server_version,
		];
	}

	/**
	 * @param list<mixed> $source_ids
	 * @return list<int>
	 */
	private static function parse_exclude_post_ids(array $source_ids): array
	{
		$exclude_post_ids = [];
		foreach ($source_ids as $source_item_id) {
			if (!is_string($source_item_id) && !is_numeric($source_item_id)) {
				continue;
			}
			$parsed = self::parse_source_item_id((string) $source_item_id);
			if ($parsed !== null) {
				$exclude_post_ids[] = $parsed['post_id'];
			}
		}

		return array_values(array_unique(array_map('intval', $exclude_post_ids)));
	}

	/**
	 * Extract post_type from source_path steps (`{ key: post_type, value: … }`).
	 *
	 * @param list<mixed> $source_path
	 */
	private static function post_type_from_source_path(array $source_path): string
	{
		foreach ($source_path as $step) {
			if (!is_array($step)) {
				continue;
			}
			$key = isset($step['key']) ? (string) $step['key'] : '';
			if ($key !== 'post_type' && $key !== 'wp_post_type') {
				continue;
			}
			$value = isset($step['value']) ? sanitize_key((string) $step['value']) : '';
			if ($value !== '') {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Latest published items for template preview (no pipeline required).
	 *
	 * Optional `filter` (Expression AST JSON) — scan newer→older until `limit`
	 * matches (or scan budget exhausted). Optional `offset` for PP-side paging.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function handle_items_latest(WP_REST_Request $request)
	{
		$post_type = self::require_valid_post_type($request);
		if (is_wp_error($post_type)) {
			return $post_type;
		}

		$limit = (int) $request->get_param('limit');
		if ($limit < 1) {
			$limit = 5;
		}
		if ($limit > 50) {
			$limit = 50;
		}

		$offset = (int) $request->get_param('offset');
		if ($offset < 0) {
			$offset = 0;
		}

		$filter = self::decode_json_param($request->get_param('filter'), null);
		$pipeline_id = self::pipeline_id_from_request($request);
		$extra_fields = array_values(
			array_unique(
				array_merge(
					self::collect_expression_fields($filter),
					self::parse_required_fields_param($request->get_param('required_fields'))
				)
			)
		);

		if (!is_array($filter)) {
			$query = new \WP_Query([
				'post_type' => $post_type,
				'post_status' => 'publish',
				'posts_per_page' => $limit,
				'offset' => $offset,
				'orderby' => 'date',
				'order' => 'DESC',
				'ignore_sticky_posts' => true,
				'no_found_rows' => true,
			]);

			$items = [];
			foreach ($query->posts as $post) {
				if (!$post instanceof \WP_Post) {
					continue;
				}
				$items[] = self::format_source_item($post, $pipeline_id, $extra_fields);
			}

			return [
				'items' => $items,
				'next_offset' => $offset + count($items),
			];
		}

		// Filtered: walk pages until we fill `limit` matches (bounded scan).
		$matched = [];
		$scan_offset = $offset;
		$batch_size = max($limit, 50);
		$max_scan = 200;
		$scanned = 0;

		while (count($matched) < $limit && $scanned < $max_scan) {
			$batch = min($batch_size, $max_scan - $scanned);
			$query = new \WP_Query([
				'post_type' => $post_type,
				'post_status' => 'publish',
				'posts_per_page' => $batch,
				'offset' => $scan_offset,
				'orderby' => 'date',
				'order' => 'DESC',
				'ignore_sticky_posts' => true,
				'no_found_rows' => true,
			]);

			$posts = $query->posts;
			if (!is_array($posts) || $posts === []) {
				break;
			}

			foreach ($posts as $post) {
				if (!$post instanceof \WP_Post) {
					continue;
				}
				$scanned++;
				$scan_offset++;
				$item = self::format_source_item($post, $pipeline_id, $extra_fields);
				$fields = isset($item['fields']) && is_array($item['fields']) ? $item['fields'] : [];
				if (!ExpressionEval::evaluate($filter, $fields)) {
					continue;
				}
				$matched[] = $item;
				if (count($matched) >= $limit) {
					break;
				}
			}

			if (count($posts) < $batch) {
				break;
			}
		}

		return [
			'items' => $matched,
			'next_offset' => $scan_offset,
		];
	}

	/**
	 * Parse `required_fields` query param (JSON string array) for preview payloads.
	 *
	 * @param mixed $raw
	 * @return list<string>
	 */
	private static function parse_required_fields_param($raw): array
	{
		$decoded = self::decode_json_param($raw, null);
		if (!is_array($decoded)) {
			return [];
		}
		$fields = [];
		foreach ($decoded as $entry) {
			if (!is_string($entry)) {
				continue;
			}
			$key = self::normalize_field_key($entry);
			if ($key !== '') {
				$fields[] = $key;
			}
		}

		return array_values(array_unique($fields));
	}

	/**
	 * Field keys referenced by an Expression AST (for payload completeness).
	 *
	 * @param mixed $expr
	 * @return list<string>
	 */
	private static function collect_expression_fields($expr): array
	{
		if (!is_array($expr)) {
			return [];
		}
		$fields = [];
		self::collect_expression_fields_into($expr, $fields);

		return array_values(array_unique($fields));
	}

	/**
	 * @param array<string, mixed> $expr
	 * @param list<string> $fields
	 */
	private static function collect_expression_fields_into(array $expr, array &$fields): void
	{
		$kind = isset($expr['kind']) ? (string) $expr['kind'] : '';
		if ($kind === 'compare') {
			$field = isset($expr['field']) ? (string) $expr['field'] : '';
			if ($field !== '') {
				$fields[] = $field;
			}

			return;
		}
		if (($kind === 'and' || $kind === 'or') && isset($expr['children']) && is_array($expr['children'])) {
			foreach ($expr['children'] as $child) {
				if (is_array($child)) {
					self::collect_expression_fields_into($child, $fields);
				}
			}
		}
	}

	/**
	 * @param list<string> $extra_required_fields
	 * @return array<string, mixed>
	 */
	private static function format_source_item(
		\WP_Post $post,
		?string $pipeline_id = null,
		array $extra_required_fields = []
	): array {
		$required_fields = [];
		if (is_string($pipeline_id) && $pipeline_id !== '') {
			$contract = Settings::get_pipeline_contract($pipeline_id);
			if (is_array($contract)) {
				$required_fields = Settings::resolve_required_fields($contract);
			}
		}
		if ($extra_required_fields !== []) {
			$required_fields = array_values(
				array_unique(array_merge($required_fields, $extra_required_fields))
			);
		}

		$fields = PushEventService::build_item_payload($post, $required_fields);
		$source_item_id = PushEventService::source_item_id($post->post_type, (int) $post->ID);

		return [
			'source_item_id' => $source_item_id,
			'id' => $source_item_id,
			'fields' => $fields,
		];
	}

	private static function pipeline_id_from_request(WP_REST_Request $request): ?string
	{
		$pipeline_id = $request->get_param('pipeline_id');
		if (!is_string($pipeline_id)) {
			return null;
		}
		$pipeline_id = trim($pipeline_id);
		if ($pipeline_id === '') {
			return null;
		}

		return $pipeline_id;
	}

	/**
	 * @return array{post_type: string, post_id: int}|null
	 */
	private static function parse_source_item_id(string $source_item_id): ?array
	{
		$source_item_id = trim(rawurldecode($source_item_id));
		if ($source_item_id === '') {
			return null;
		}
		if (strpos($source_item_id, ':') !== false) {
			[$post_type, $post_id] = explode(':', $source_item_id, 2);
			$post_id = (int) $post_id;
			if ($post_type === '' || $post_id <= 0) {
				return null;
			}

			return ['post_type' => $post_type, 'post_id' => $post_id];
		}
		$post_id = (int) $source_item_id;
		if ($post_id <= 0) {
			return null;
		}
		$post = get_post($post_id);

		return [
			'post_type' => $post instanceof \WP_Post ? (string) $post->post_type : 'post',
			'post_id' => $post_id,
		];
	}

	/**
	 * @param mixed $raw
	 * @return mixed
	 */
	private static function decode_json_param($raw, $default)
	{
		if ($raw === null || $raw === '') {
			return $default;
		}
		if (is_array($raw)) {
			return $raw;
		}
		if (!is_string($raw)) {
			return $default;
		}
		$decoded = json_decode($raw, true);
		if (json_last_error() !== JSON_ERROR_NONE) {
			return $default;
		}

		return $decoded;
	}

	/**
	 * @param array<string, mixed> $query_args
	 * @param mixed                $sort
	 */
	private static function apply_sort_to_query(array &$query_args, $sort): void
	{
		$field = 'date';
		$direction = 'DESC';

		if (is_array($sort)) {
			$first = null;
			// SPEC-002-07 §5: { kind: sort, rules: [{ field, direction }] }
			if (isset($sort['rules']) && is_array($sort['rules']) && isset($sort['rules'][0]) && is_array($sort['rules'][0])) {
				$first = $sort['rules'][0];
			} elseif (isset($sort[0]) && is_array($sort[0])) {
				$first = $sort[0];
			} elseif (isset($sort['field'])) {
				// BE wire shorthand: { kind: sort, field, direction }
				$first = $sort;
			}
			if (is_array($first)) {
				$field = isset($first['field']) ? (string) $first['field'] : $field;
				$direction = isset($first['direction']) && strtolower((string) $first['direction']) === 'asc'
					? 'ASC'
					: 'DESC';
			}
		}

		switch ($field) {
			case 'title':
				$query_args['orderby'] = 'title';
				break;
			case 'ID':
			case 'id':
				$query_args['orderby'] = 'ID';
				break;
			case 'published_at':
			case 'date':
			default:
				$query_args['orderby'] = 'date';
				break;
		}
		$query_args['order'] = $direction;
	}

	private static function field_is_sortable(string $key): bool
	{
		return in_array($key, ['date', 'title', 'published_at', 'ID', 'id'], true);
	}

	private static function field_is_filterable(string $key): bool
	{
		if (in_array($key, self::MEDIA_FIELD_KEYS, true)) {
			// MVP SQL: only featured_image supports is_set / is_empty.
			return $key === 'featured_image';
		}

		return true;
	}

	private static function field_section_id(string $key): string
	{
		if (strpos($key, 'product_') === 0) {
			return self::FIELD_SECTION_WOOCOMMERCE;
		}

		if (in_array($key, self::SECTION_MEDIA_FIELD_KEYS, true)) {
			return self::FIELD_SECTION_MEDIA;
		}

		return self::FIELD_SECTION_BASIC;
	}

	private static function instant_mvp_field_label(string $key): string
	{
		$labels = [
			'title' => _x('Title', 'wp_post_field', 'parrotposter'),
			'excerpt' => _x('Excerpt', 'wp_post_field', 'parrotposter'),
			'content' => _x('Content', 'wp_post_field', 'parrotposter'),
			'link' => _x('Link', 'wp_post_field', 'parrotposter'),
			'date' => _x('Date', 'wp_post_field', 'parrotposter'),
			'featured_image' => _x('Featured image', 'wp_post_field', 'parrotposter'),
			'images_in_content' => _x('Images in content', 'wp_post_field', 'parrotposter'),
			'featured_video' => _x('Featured video', 'wp_post_field', 'parrotposter'),
			'videos_in_content' => _x('Videos in content', 'wp_post_field', 'parrotposter'),
			'attached_videos' => _x('Attached videos', 'wp_post_field', 'parrotposter'),
			'gifs_in_content' => _x('GIFs in content', 'wp_post_field', 'parrotposter'),
			'attached_audio' => _x('Attached audio', 'wp_post_field', 'parrotposter'),
			'attached_documents' => _x('Attached documents', 'wp_post_field', 'parrotposter'),
		];

		return $labels[$key] ?? $key;
	}

	/**
	 * @return string|\WP_Error
	 */
	private static function require_valid_post_type(WP_REST_Request $request)
	{
		$post_type = sanitize_key((string) $request->get_param('post_type'));
		if ($post_type === '') {
			return new \WP_Error(
				'invalid_post_type',
				'post_type query parameter is required',
				['status' => 400]
			);
		}

		$allowed = WpPostHelpers::get_post_types('names');
		if (!in_array($post_type, $allowed, true)) {
			return new \WP_Error(
				'invalid_post_type',
				sprintf('Unknown post_type: %s', $post_type),
				['status' => 400]
			);
		}

		return $post_type;
	}

	private static function normalize_field_key(string $key): string
	{
		$key = trim($key);
		if ($key === '') {
			return '';
		}
		if ($key[0] === '{' && substr($key, -1) === '}') {
			$key = substr($key, 1, -1);
		}

		return $key;
	}

	private static function field_storage_type(string $key): string
	{
		if (in_array($key, self::MEDIA_FIELD_KEYS, true)) {
			return 'array';
		}

		return 'string';
	}

	private static function field_semantic(string $key): string
	{
		if (in_array($key, ['featured_image', 'images_in_content', 'product_image', 'product_gallery'], true)) {
			return 'image';
		}
		if (in_array($key, ['featured_video', 'videos_in_content', 'attached_videos'], true)) {
			return 'video';
		}
		if ($key === 'gifs_in_content') {
			return 'gif';
		}
		if ($key === 'attached_audio') {
			return 'audio';
		}
		if ($key === 'attached_documents') {
			return 'file';
		}
		if ($key === 'link' || $key === 'url' || $key === 'product_link') {
			return 'url';
		}
		if ($key === 'date') {
			return 'datetime';
		}

		return 'text';
	}

	private static function secret_from_request(WP_REST_Request $request): string
	{
		$header_secret = $request->get_header('x-parrotposter-secret');
		if (is_string($header_secret) && $header_secret !== '') {
			return trim($header_secret);
		}

		$auth = $request->get_header('authorization');
		if (!is_string($auth) || $auth === '') {
			return '';
		}
		if (stripos($auth, 'bearer ') === 0) {
			return trim(substr($auth, 7));
		}
		if (stripos($auth, 'pp-secret ') === 0) {
			return trim(substr($auth, 10));
		}

		return '';
	}

	private static function check_rate_limit(): bool
	{
		$ip = self::client_ip();
		if ($ip === '') {
			return true;
		}
		$key = 'pp_wire_rate_' . md5($ip);
		$bucket = get_transient($key);
		if (!is_array($bucket)) {
			$bucket = ['count' => 0, 'started_at' => time()];
		}
		$started_at = (int) ($bucket['started_at'] ?? time());
		$count = (int) ($bucket['count'] ?? 0);
		if (time() - $started_at >= self::RATE_LIMIT_WINDOW_SEC) {
			$started_at = time();
			$count = 0;
		}
		++$count;
		set_transient($key, ['count' => $count, 'started_at' => $started_at], self::RATE_LIMIT_WINDOW_SEC);
		if ($count > self::RATE_LIMIT_MAX) {
			return false;
		}

		return true;
	}

	private static function client_ip(): string
	{
		if (!empty($_SERVER['REMOTE_ADDR'])) {
			return sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR']));
		}

		return '';
	}
}

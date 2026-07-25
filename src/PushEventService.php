<?php

namespace parrotposter;

defined('ABSPATH') || exit;

use parrotposter\fields\CommonType;
use parrotposter\fields\Fields;
use parrotposter\fields\Taxonomies;

/**
 * Build SourceItem payloads and send pipeline push events to PP.
 */
class PushEventService
{
	public const PREV_FIELDS_META = '_pp_prev_fields';

	private const TRACKED_FIELDS = [
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
		'status',
		// WooCommerce product fields (WC_Product::save / woocommerce_update_product).
		'product_title',
		'product_short_description',
		'product_description',
		'product_regular_price',
		'product_sale_price',
		'product_currency',
		'product_link',
		'product_image',
		'product_gallery',
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

	private const MEDIA_FIELD_KIND = [
		'featured_image' => 'image',
		'images_in_content' => 'image',
		'product_image' => 'image',
		'product_gallery' => 'image',
		'featured_video' => 'video',
		'videos_in_content' => 'video',
		'attached_videos' => 'video',
		'gifs_in_content' => 'gif',
		'attached_audio' => 'audio',
		'attached_documents' => 'file',
	];

	public static function send_created_event(\WP_Post $post, string $pipeline_id, array $contract = []): void
	{
		$pipeline_id = trim($pipeline_id);
		if ($pipeline_id === '') {
			return;
		}
		if (!self::post_passes_scope_filter($post, $contract)) {
			return;
		}

		$payload = self::build_item_payload($post, Settings::resolve_required_fields($contract));
		$variables = self::build_mutation_variables(
			$pipeline_id,
			'CREATED',
			$post,
			$payload,
			null
		);

		self::enqueue_event($variables, $post->ID);
	}

	/**
	 * @param list<string>|null $changed_fields
	 */
	public static function send_updated_event(\WP_Post $post, string $pipeline_id, array $contract = [], ?array $changed_fields = null): void
	{
		$pipeline_id = trim($pipeline_id);
		if ($pipeline_id === '') {
			return;
		}
		if (!self::post_passes_scope_filter($post, $contract)) {
			return;
		}

		$payload = self::build_item_payload($post, Settings::resolve_required_fields($contract));
		if ($changed_fields === null) {
			$changed_fields = self::diff_changed_fields($post->ID, $payload);
		}
		$changed_fields = self::filter_changed_fields_by_template($changed_fields, $contract);
		if (empty($changed_fields)) {
			return;
		}

		$variables = self::build_mutation_variables(
			$pipeline_id,
			'UPDATED',
			$post,
			$payload,
			$changed_fields
		);

		self::enqueue_event($variables, $post->ID);
	}

	public static function send_deleted_event(int $post_id, string $pipeline_id, ?string $post_type = null): void
	{
		$pipeline_id = trim($pipeline_id);
		if ($pipeline_id === '' || $post_id <= 0) {
			return;
		}

		if ($post_type === null || $post_type === '') {
			$post = get_post($post_id);
			$post_type = $post instanceof \WP_Post ? $post->post_type : 'post';
		}

		$payload = [
			'id' => (string) $post_id,
			'post_type' => (string) $post_type,
		];

		$variables = [
			'pipelineId' => $pipeline_id,
			'eventType' => 'DELETED',
			'contractVersion' => self::contract_version_for_pipeline($pipeline_id),
			'sourceItemId' => self::source_item_id($post_type, $post_id),
			'payload' => $payload,
		];

		self::enqueue_event($variables, $post_id);
	}

	/**
	 * @param list<string> $required_fields
	 * @return array<string, mixed>
	 */
	public static function build_item_payload(\WP_Post $post, array $required_fields = []): array
	{
		$payload = [
			'id' => (string) $post->ID,
			'title' => get_the_title($post),
			'excerpt' => self::field_text('excerpt', $post),
			'content' => self::field_text('content', $post),
			'link' => self::field_text('link', $post),
			'url' => self::field_text('link', $post),
			'date' => self::field_text('date', $post),
			'post_type' => (string) $post->post_type,
			'status' => (string) $post->post_status,
			'featured_image' => self::field_media_items('featured_image', $post),
			'images_in_content' => self::field_media_items('images_in_content', $post),
			'featured_video' => self::field_media_items('featured_video', $post),
			'videos_in_content' => self::field_media_items('videos_in_content', $post),
			'attached_videos' => self::field_media_items('attached_videos', $post),
			'gifs_in_content' => self::field_media_items('gifs_in_content', $post),
			'attached_audio' => self::field_media_items('attached_audio', $post),
			'attached_documents' => self::field_media_items('attached_documents', $post),
		];

		if ($post->post_type === 'product') {
			foreach ([
				'product_title',
				'product_short_description',
				'product_description',
				'product_regular_price',
				'product_sale_price',
				'product_currency',
				'product_link',
				'product_image',
				'product_gallery',
			] as $product_field) {
				$payload[$product_field] = self::resolve_field_value($product_field, $post);
			}
		}

		foreach (Taxonomies::keys_for_post_type($post->post_type) as $tax_key) {
			if (array_key_exists($tax_key, $payload)) {
				continue;
			}
			$payload[$tax_key] = self::taxonomy_term_ids($tax_key, $post);
		}

		foreach ($required_fields as $field) {
			if (!is_string($field) || $field === '' || $field === 'source_item_id') {
				continue;
			}
			if (array_key_exists($field, $payload)) {
				continue;
			}
			$payload[$field] = self::resolve_field_value($field, $post);
		}

		return $payload;
	}

	/**
	 * @param array<string, mixed> $contract
	 */
	public static function post_passes_scope_filter(\WP_Post $post, array $contract): bool
	{
		$scope_filter = Settings::resolve_scope_filter($contract);
		if ($scope_filter === null) {
			return true;
		}

		$item = self::build_item_payload($post, Settings::resolve_required_fields($contract));

		return ExpressionEval::evaluate($scope_filter, $item);
	}

	/**
	 * @param list<string> $changed_fields
	 * @param array<string, mixed> $contract
	 * @return list<string>
	 */
	public static function filter_changed_fields_by_template(array $changed_fields, array $contract): array
	{
		$template_fields = Settings::resolve_template_required_fields($contract);
		if (empty($template_fields)) {
			return $changed_fields;
		}

		return array_values(array_intersect($changed_fields, $template_fields));
	}

	/**
	 * @param array<string, mixed> $payload
	 * @param list<string>|null    $changed_fields
	 * @return array<string, mixed>
	 */
	private static function build_mutation_variables(
		string $pipeline_id,
		string $event_type,
		\WP_Post $post,
		array $payload,
		?array $changed_fields
	): array {
		$variables = [
			'pipelineId' => $pipeline_id,
			'eventType' => $event_type,
			'contractVersion' => self::contract_version_for_pipeline($pipeline_id),
			'sourceItemId' => self::source_item_id($post->post_type, (int) $post->ID),
			'payload' => $payload,
		];
		if ($changed_fields !== null) {
			$variables['changedFields'] = array_values($changed_fields);
		}

		return $variables;
	}

	/**
	 * @param array<string, mixed> $variables
	 */
	private static function enqueue_event(array $variables, int $wp_post_id): void
	{
		LocalQueue::enqueue('pipeline_event', $variables, $wp_post_id);
	}

	/**
	 * Post-ingest side effects for LocalQueue flush (baseline meta, delete baseline).
	 *
	 * @param array<string, mixed>              $variables
	 * @param array{data?: array<string, mixed>} $res
	 */
	public static function apply_side_effects_after_ingest(int $wp_post_id, array $variables, array $res): void
	{
		$event_type = (string) ($variables['eventType'] ?? '');
		if ($event_type === 'DELETED') {
			if ($wp_post_id > 0) {
				delete_post_meta($wp_post_id, self::PREV_FIELDS_META);
			}

			return;
		}

		if (!self::should_save_baseline_after_ingest($event_type, $res)) {
			return;
		}

		$payload = isset($variables['payload']) && is_array($variables['payload'])
			? $variables['payload']
			: [];
		if ($wp_post_id > 0 && $payload !== []) {
			self::save_prev_fields($wp_post_id, $payload);
		}
	}

	/**
	 * @param array{data?: array<string, mixed>} $res
	 */
	private static function should_save_baseline_after_ingest(string $event_type, array $res): bool
	{
		$ingest = self::extract_ingest_payload($res);
		if ($ingest === null) {
			return false;
		}
		if (empty($ingest['accepted'])) {
			return false;
		}

		$trigger_run_ids = $ingest['triggerRunIds'] ?? $ingest['trigger_run_ids'] ?? [];
		if (!is_array($trigger_run_ids)) {
			$trigger_run_ids = [];
		}

		// UPDATED with immediate skipped runs (e.g. changed_fields_no_intersection):
		// keep the previous baseline so a later edit can still be pushed.
		if ($event_type === 'UPDATED' && !empty($trigger_run_ids)) {
			return false;
		}

		return true;
	}

	/**
	 * @param array{data?: array<string, mixed>} $res
	 * @return array<string, mixed>|null
	 */
	private static function extract_ingest_payload(array $res): ?array
	{
		$data = $res['data'] ?? null;
		if (!is_array($data)) {
			return null;
		}
		$ingest = $data['pluginPipelineEventIngest'] ?? null;

		return is_array($ingest) ? $ingest : null;
	}

	public static function source_item_id(string $post_type, int $post_id): string
	{
		return $post_type . ':' . $post_id;
	}

	private static function contract_version_for_pipeline(string $pipeline_id): int
	{
		$contract = Settings::get_pipeline_contract($pipeline_id);

		return is_array($contract) ? (int) ($contract['contract_version'] ?? 0) : 0;
	}

	private static function field_text(string $field, \WP_Post $post): string
	{
		$value = self::resolve_field_value($field, $post);
		if ($value === null) {
			return '';
		}
		if (is_string($value)) {
			return $value;
		}

		return (string) $value;
	}

	/**
	 * @return mixed
	 */
	private static function resolve_field_value(string $field, \WP_Post $post)
	{
		if (in_array($field, self::MEDIA_FIELD_KEYS, true)) {
			return self::field_media_items($field, $post);
		}

		// Wire/pipeline filters compare term_id[]; legacy TextProcessor still uses names.
		if (taxonomy_exists($field)) {
			return self::taxonomy_term_ids($field, $post);
		}

		$value = Fields::get_field_value($field, $post);
		if ($value === null) {
			$value = CommonType::get_field_value($field, $post);
		}
		if ($value === null) {
			return '';
		}

		return $value;
	}

	/**
	 * @return list<string>
	 */
	private static function taxonomy_term_ids(string $taxonomy, \WP_Post $post): array
	{
		$terms = wp_get_post_terms($post->ID, $taxonomy, ['fields' => 'ids']);
		if (is_wp_error($terms) || !is_array($terms)) {
			return [];
		}

		$ids = [];
		foreach ($terms as $term_id) {
			$ids[] = (string) (int) $term_id;
		}

		return $ids;
	}

	/**
	 * Media payload items: `{url, mime?, kind}` (renderer accepts objects or plain URL strings).
	 *
	 * @return list<array{url: string, mime?: string, kind: string}>
	 */
	private static function field_media_items(string $field, \WP_Post $post): array
	{
		$value = Fields::get_field_value($field, $post);
		if ($value === null) {
			$value = CommonType::get_field_value($field, $post);
		}
		if (!is_array($value)) {
			return [];
		}

		$kind = self::MEDIA_FIELD_KIND[$field] ?? 'image';
		$items = [];
		foreach ($value as $attachment_id) {
			if (is_string($attachment_id) && $attachment_id !== '' && !is_numeric($attachment_id)) {
				$absolute = self::absolutize_media_url($attachment_id);
				if ($absolute !== '') {
					$items[] = [
						'url' => $absolute,
						'kind' => $kind,
					];
				}
				continue;
			}
			$attachment_id = (int) $attachment_id;
			if ($attachment_id <= 0) {
				continue;
			}
			$url = wp_get_attachment_url($attachment_id);
			if (!is_string($url) || $url === '') {
				continue;
			}
			$item = [
				'url' => $url,
				'kind' => $kind,
			];
			$mime = get_post_mime_type($attachment_id);
			if (is_string($mime) && $mime !== '') {
				$item['mime'] = $mime;
			}
			$items[] = $item;
		}

		return $items;
	}

	/**
	 * Ensure media URLs in Event payload are absolute (SPEC-002-08 SourceItem).
	 */
	private static function absolutize_media_url(string $url): string
	{
		$url = trim($url);
		if ($url === '') {
			return '';
		}
		if (preg_match('#^https?://#i', $url)) {
			return $url;
		}
		if (strpos($url, '//') === 0) {
			$scheme = is_ssl() ? 'https:' : 'http:';

			return $scheme . $url;
		}
		if (function_exists('home_url')) {
			return home_url($url);
		}

		return $url;
	}

	/**
	 * @param array<string, mixed> $payload
	 * @return list<string>
	 */
	public static function diff_changed_fields(int $post_id, array $payload): array
	{
		$prev = get_post_meta($post_id, self::PREV_FIELDS_META, true);
		if (!is_array($prev)) {
			$prev = [];
		}

		$changed = [];
		foreach (self::TRACKED_FIELDS as $field) {
			$old = $prev[$field] ?? null;
			$new = $payload[$field] ?? null;
			if (self::values_differ($old, $new)) {
				$changed[] = $field;
			}
		}

		return $changed;
	}

	/**
	 * @param mixed $old
	 * @param mixed $new
	 */
	private static function values_differ($old, $new): bool
	{
		if (is_array($old) || is_array($new)) {
			return wp_json_encode($old) !== wp_json_encode($new);
		}

		return $old !== $new;
	}

	/**
	 * @param array<string, mixed> $payload
	 */
	private static function save_prev_fields(int $post_id, array $payload): void
	{
		$snapshot = [];
		foreach (self::TRACKED_FIELDS as $field) {
			if (array_key_exists($field, $payload)) {
				$snapshot[$field] = $payload[$field];
			}
		}
		update_post_meta($post_id, self::PREV_FIELDS_META, $snapshot);
	}
}

<?php

namespace parrotposter;

defined('ABSPATH') || exit;

/**
 * WordPress hooks for pipeline push events (v2, migration_mode=pipeline).
 */
class PipelineHooks
{
	private static $_instance;

	public static function init(): void
	{
		self::get_instance();
	}

	public static function get_instance(): self
	{
		if (!self::$_instance) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	private function __construct()
	{
		add_action('wp_after_insert_post', [$this, 'on_post_saved'], 20, 4);
		add_action('wp_trash_post', [$this, 'on_post_deleted'], 20, 1);
		add_action('before_delete_post', [$this, 'on_post_deleted'], 20, 1);
		// WC_Product::save / price meta often bypasses wp_after_insert_post.
		add_action('woocommerce_update_product', [$this, 'on_product_updated'], 20, 1);
	}

	private function is_supported_post_type(string $post_type): bool
	{
		if ($post_type === '') {
			return false;
		}
		$types = WpPostHelpers::get_post_types('names');

		return in_array($post_type, $types, true);
	}

	private function should_handle(): bool
	{
		if (Settings::get_migration_mode() !== Settings::MIGRATION_MODE_PIPELINE) {
			return false;
		}
		if (Settings::site_to_pp_secret() === '') {
			return false;
		}

		return true;
	}

	/**
	 * @param int          $post_id
	 * @param \WP_Post     $post
	 * @param bool         $updated
	 * @param \WP_Post|null $post_before
	 */
	public function on_post_saved($post_id, $post, $updated, $post_before): void
	{
		if (!$this->should_handle()) {
			return;
		}
		if (wp_is_post_revision($post_id)) {
			return;
		}
		if (!$post instanceof \WP_Post) {
			return;
		}
		if (!$this->is_supported_post_type($post->post_type)) {
			return;
		}

		$pipelines = Settings::get_pipelines_for_post($post);
		if (empty($pipelines)) {
			return;
		}

		$was_publish = $post_before instanceof \WP_Post && $post_before->post_status === 'publish';
		$is_publish = $post->post_status === 'publish';

		if ($is_publish && !$was_publish) {
			foreach ($pipelines as $pipeline) {
				PushEventService::send_created_event($post, $pipeline['pipeline_id'], $pipeline['contract']);
			}

			return;
		}

		// Leaving publishable → DELETED (draft/private/…). Trash uses on_post_deleted.
		if ($was_publish && !$is_publish) {
			if ($post->post_status === 'trash') {
				return;
			}
			foreach ($pipelines as $pipeline) {
				PushEventService::send_deleted_event(
					(int) $post_id,
					$pipeline['pipeline_id'],
					$post->post_type
				);
			}

			return;
		}

		if ($is_publish && $was_publish && $updated) {
			// No local baseline yet (e.g. post was published before migration_mode
			// switched to `pipeline`): diff against an empty baseline so the first
			// push establishes it, instead of silently skipping updates forever.
			foreach ($pipelines as $pipeline) {
				PushEventService::send_updated_event($post, $pipeline['pipeline_id'], $pipeline['contract']);
			}
		}
	}

	/**
	 * WooCommerce product object save (price/stock/etc. without wp_insert_post).
	 *
	 * @param int $product_id
	 */
	public function on_product_updated($product_id): void
	{
		if (!$this->should_handle()) {
			return;
		}

		$product_id = (int) $product_id;
		if ($product_id <= 0) {
			return;
		}

		$post = get_post($product_id);
		if (!$post instanceof \WP_Post || $post->post_type !== 'product') {
			return;
		}
		if ($post->post_status !== 'publish') {
			return;
		}
		if (!$this->is_supported_post_type($post->post_type)) {
			return;
		}

		$pipelines = Settings::get_pipelines_for_post($post);
		if (empty($pipelines)) {
			return;
		}

		foreach ($pipelines as $pipeline) {
			PushEventService::send_updated_event($post, $pipeline['pipeline_id'], $pipeline['contract']);
		}
	}

	/**
	 * @param int|\WP_Post $post_id
	 */
	public function on_post_deleted($post_id): void
	{
		if (!$this->should_handle()) {
			return;
		}

		$post_id = (int) $post_id;
		if ($post_id <= 0 || wp_is_post_revision($post_id)) {
			return;
		}

		$post = get_post($post_id);
		if (!$post instanceof \WP_Post) {
			return;
		}
		if (!$this->is_supported_post_type($post->post_type)) {
			return;
		}

		$pipelines = Settings::get_pipelines_for_post($post);
		if (empty($pipelines)) {
			return;
		}

		foreach ($pipelines as $pipeline) {
			PushEventService::send_deleted_event($post_id, $pipeline['pipeline_id'], $post->post_type);
		}
	}
}

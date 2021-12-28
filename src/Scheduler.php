<?php

namespace parrotposter;

defined('ABSPATH') || exit;

use parrotposter\fields\conditions\Conditions;

class Scheduler
{
	private static $_instance;

	public static function get_instance()
	{
		if (!self::$_instance) {
			self::$_instance = new self;
		}
		return self::$_instance;
	}

	public static function init()
	{
		self::get_instance();
	}

	private function __construct()
	{
		add_action('wp_after_insert_post', [$this, 'wp_after_insert_post_handler'], 10, 4);
	}

	public function wp_after_insert_post_handler($post_id, $post, $updated, $post_before)
	{
		if (wp_is_post_revision($post_id)) {
			return;
		}

		$isEqual = !empty($post_before) && $post->post_status == $post_before->post_status;
		if ($isEqual || $post->post_status != 'publish') {
			return;
		}

		PP::log(['post published', $post->post_status, $post_before->post_status, $post]);

		self::publish_post($post);
	}

	public function publish_post($wp_post)
	{
		global $wpdb;

		$autopostings = WpPostHelpers::list_autoposting_by_post($wp_post);
		if (empty($autopostings)) {
			return;
		}

		foreach ($autopostings as $item) {
			if (!$item['enable'] || empty($item['account_ids'])) {
				continue;
			}
			if (!Conditions::check($item['conditions'], $wp_post)) {
				continue;
			}

			if ($item['exclude_duplicates']) {
				$cnt = $wpdb->get_var($wpdb->prepare(
					"SELECT count(*)
					FROM {$wpdb->prefix}parrotposter_posts
					WHERE wp_post_id = %d AND autoposting_id = %d",
					$wp_post->ID,
					$item['id'],
				));

				if ($cnt > 0) {
					continue;
				}
			}

			$post_text = TextProcessor::replace_post_text($wp_post, $item['post_text']);
			$post_text = Tools::clear_text($post_text);

			$post_tags = TextProcessor::replace_post_text($wp_post, $item['post_tags']);
			$post_tags = Tools::clear_text($post_tags);

			$image_ids = self::upload_wp_images($wp_post, $item['post_images']);

			$post_fields = [
				'text' => $post_text,
				'tags' => $post_tags,
				'link' => TextProcessor::replace_post_text($wp_post, $item['post_link']),
				'images' => $image_ids,
				'extra' => [
					'wp_post_id' => $wp_post->ID,
					'wp_site_domain' => WpPostHelpers::get_site_domain(),
					'vk_signed' => !!$item['extra_vk_signed'],
					'vk_from_group' => !!$item['extra_vk_from_group'],
				],
			];

			if ($item['utm_enable']) {
				$post_fields['need_utm'] = true;
				$post_fields['utm_params'] = [
					'utm_source' => TextProcessor::replace_post_text($wp_post, $item['utm_source']),
					'utm_medium' => TextProcessor::replace_post_text($wp_post, $item['utm_medium']),
					'utm_content' => TextProcessor::replace_post_text($wp_post, $item['utm_content']),
					'utm_term' => TextProcessor::replace_post_text($wp_post, $item['utm_term']),
					'utm_campaign' => TextProcessor::replace_post_text($wp_post, $item['utm_campaign']),
				];
			}

			switch ($item['when_publish']) {
			case 'immediately':
				$publish_at = ApiHelpers::datetimeFormat('now');
				break;
			case 'delay':
				$publish_at = ApiHelpers::datetimeFormat("+{$item['publish_delay']} minutes");
				break;
			}

			$pp_post = [
				'fields' => $post_fields,
				'publish_at' => $publish_at,
				'networks' => [
					'accounts' => $item['account_ids'],
				]
			];

			$res = Api::create_post($pp_post);
			if (empty($res['error'])) {
				$wpdb->insert(
					$wpdb->prefix.'parrotposter_posts',
					[
						'wp_post_id' => $wp_post->ID,
						'post_id' => $res['response']['post_id'],
						'autoposting_id' => $item['id'],
					],
					['%d', '%s', '%d'],
				);
			}
		}
	}

	public static function upload_wp_images($wp_post, $images_txt_keys)
	{
		$images_txt = implode('', $images_txt_keys);
		$wp_images_ids = TextProcessor::replace_post_image_text_to_ids($wp_post, $images_txt);
		$image_ids = [];
		$wp_images_processed = [];
		foreach ($wp_images_ids as $id) {
			$id = sanitize_text_field($id);
			$attached_file = get_attached_file($id);
			if (empty($attached_file) || isset($wp_images_processed[$id])) {
				continue;
			}
			$wp_images_processed[$id] = true;

			$res = Api::upload_file($attached_file);
			$file_id = ApiHelpers::retrieve_response($res, 'file_id');
			if (empty($file_id)) {
				continue;
			}
			$image_ids[] = $file_id;
		}
		return $image_ids;
	}
}

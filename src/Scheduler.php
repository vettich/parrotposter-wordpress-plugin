<?php

namespace parrotposter;

defined('ABSPATH') || exit;

use parrotposter\fields\conditions\Conditions;

class Scheduler
{
	private static $_instance;
	private $posts = [];

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
		add_action('shutdown', [$this, 'shutdown_handler'], 300);
	}

	public function wp_after_insert_post_handler($post_id, $post, $updated, $post_before)
	{
		if (wp_is_post_revision($post_id)) {
			PP::log(['wp_after_insert_post_handler', $post_id, 'post is revision, skip']);
			return;
		}

		$isEqual = !empty($post_before) && $post->post_status == $post_before->post_status;
		if ($isEqual || $post->post_status != 'publish') {
			return;
		}

		PP::log(['post published', $post->post_status, $post_before->post_status, $post]);

		$this->posts[] = $post;
	}

	public function shutdown_handler()
	{
		if (empty($this->posts)) {
			return;
		}

		foreach ($this->posts as $post) {
			self::publish_post($post);
		}
		$this->posts = [];
	}

	public function publish_post($wp_post)
	{
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

			$this->publish_post_by_template_without_check($wp_post, $item, true);
		}
	}

	public function publish_post_by_template_without_check($wp_post, $template, $exclude_duplicates)
	{
		global $wpdb;

		if ($exclude_duplicates && $template['exclude_duplicates'] && self::has_duplicate($wp_post->ID, $template['id'])) {
			return;
		}

		$post_text = TextProcessor::replace_post_text($wp_post, $template['post_text']);
		$post_text = Tools::clear_text($post_text);

		$post_tags = TextProcessor::replace_post_text($wp_post, $template['post_tags']);
		$post_tags = Tools::clear_text($post_tags);

		list($image_ids, $image_urls) = self::upload_wp_images($wp_post, $template['post_images']);

		$post_fields = [
			'text' => $post_text,
			'tags' => $post_tags,
			'link' => TextProcessor::replace_post_text($wp_post, $template['post_link']),
			'images' => $image_ids,
			'image_urls' => $image_urls,
			'extra' => [
				'wp_post_id' => $wp_post->ID,
				'wp_site_domain' => WpPostHelpers::get_site_domain(),
				'vk_signed' => !!$template['extra_vk_signed'],
				'vk_from_group' => !!$template['extra_vk_from_group'],
			],
		];

		if ($template['utm_enable']) {
			$post_fields['need_utm'] = true;
			$post_fields['utm_params'] = [
				'utm_source' => TextProcessor::replace_post_text($wp_post, $template['utm_source']),
				'utm_medium' => TextProcessor::replace_post_text($wp_post, $template['utm_medium']),
				'utm_content' => TextProcessor::replace_post_text($wp_post, $template['utm_content']),
				'utm_term' => TextProcessor::replace_post_text($wp_post, $template['utm_term']),
				'utm_campaign' => TextProcessor::replace_post_text($wp_post, $template['utm_campaign']),
			];
		}

		switch ($template['when_publish']) {
			case 'immediately':
				$publish_at = ApiHelpers::datetimeFormat('now');
				break;
			case 'delay':
				$publish_at = ApiHelpers::datetimeFormat("+{$template['publish_delay']} minutes");
				break;
		}

		$pp_post = [
			'fields' => $post_fields,
			'publish_at' => $publish_at,
			'networks' => [
				'accounts' => $template['account_ids'],
			]
		];

		$res = Api::create_post($pp_post);
		if (empty($res['error'])) {
			$wpdb->insert(
				$wpdb->prefix . 'parrotposter_posts',
				[
					'wp_post_id' => $wp_post->ID,
					'post_id' => $res['response']['post_id'],
					'autoposting_id' => $template['id'],
				],
				['%d', '%s', '%d'],
			);
		}
	}

	public static function upload_wp_images($wp_post, $images_txt_keys)
	{
		$images_txt = implode('', $images_txt_keys);
		$wp_images_ids = TextProcessor::replace_post_image_text_to_ids($wp_post, $images_txt);
		$image_ids = [];
		$image_urls = [];
		$wp_images_processed = [];
		foreach ($wp_images_ids as $id) {
			$url = $id;
			$id = sanitize_text_field($id);
			$attached_file = get_attached_file($id);
			if (empty($attached_file) || isset($wp_images_processed[$id])) {
				if (str_starts_with($url, 'http')) {
					$image_urls[] = $url;
				}
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
		return [$image_ids, $image_urls];
	}

	public static function has_duplicate($wp_post_id, $template_id)
	{
		global $wpdb;

		$cnt = $wpdb->get_var($wpdb->prepare(
			"SELECT count(*)
					FROM {$wpdb->prefix}parrotposter_posts
					WHERE wp_post_id = %d AND autoposting_id = %d",
			$wp_post_id,
			$template_id,
		));

		return $cnt > 0;
	}

	public static function last_publish_at($wp_post_id, $template_id)
	{
		global $wpdb;

		$results = $wpdb->get_results($wpdb->prepare(
			"SELECT post_id
				FROM {$wpdb->prefix}parrotposter_posts
				WHERE wp_post_id = %d and autoposting_id = %d",
			$wp_post_id,
			$template_id,
		));

		$ids = [];
		foreach ($results as $res) {
			$ids[] = $res->post_id;
		}

		if (empty($ids)) {
			return false;
		}

		return Api::get_last_post_publish_at($ids);
	}
}

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
		add_action('wp_trash_post', [$this, 'on_post_trashed'], 10, 1);
		add_action('before_delete_post', [$this, 'on_before_delete_post'], 10, 2);
	}

	/**
	 * Whether the post type is eligible for autoposting (public types from WpPostHelpers).
	 */
	private function is_supported_post_type($post_type): bool
	{
		if (!is_string($post_type) || $post_type === '') {
			return false;
		}
		$types = WpPostHelpers::get_post_types('names');

		return in_array($post_type, $types, true);
	}

	public function wp_after_insert_post_handler($post_id, $post, $updated, $post_before)
	{
		if (wp_is_post_revision($post_id)) {
			PP::log(['wp_after_insert_post_handler', $post_id, 'post is revision, skip']);
			return;
		}

		if (!$post instanceof \WP_Post) {
			return;
		}

		if (!$this->is_supported_post_type($post->post_type)) {
			return;
		}

		$was_publish = !empty($post_before) && $post_before->post_status === 'publish';
		$is_publish = $post->post_status === 'publish';

		if ($is_publish && !$was_publish) {
			PP::log(['post transitioned to publish', $post->post_status, $post_before ? $post_before->post_status : null, $post_id]);
			LocalQueue::enqueue_create((int) $post_id);

			return;
		}

		if ($is_publish && $was_publish && $updated && self::wp_post_has_linked_pp_posts((int) $post_id)) {
			LocalQueue::enqueue_update((int) $post_id);
		}
	}

	public function on_post_trashed($post_id)
	{
		$post_id = (int) $post_id;
		if ($post_id <= 0 || wp_is_post_revision($post_id)) {
			return;
		}
		$post = get_post($post_id);
		if (!$post instanceof \WP_Post || !$this->is_supported_post_type($post->post_type)) {
			return;
		}

		LocalQueue::enqueue_delete($post_id);
	}

	/**
	 * @param int|\WP_Post $post_id
	 */
	public function on_before_delete_post($post_id, $post = null)
	{
		$post_id = (int) $post_id;
		if ($post_id <= 0 || wp_is_post_revision($post_id)) {
			return;
		}
		$p = $post instanceof \WP_Post ? $post : get_post($post_id);
		if (!$p instanceof \WP_Post || !$this->is_supported_post_type($p->post_type)) {
			return;
		}

		LocalQueue::enqueue_delete($post_id);
	}

	public function publish_post($wp_post)
	{
		$autopostings = WpPostHelpers::list_autoposting_by_post($wp_post);
		if (empty($autopostings)) {
			return;
		}

		$eligible = [];
		foreach ($autopostings as $item) {
			if (!$item['enable'] || empty($item['account_ids'])) {
				continue;
			}
			if (!Conditions::check($item['conditions'], $wp_post)) {
				continue;
			}
			$eligible[] = $item;
		}

		if (empty($eligible)) {
			return;
		}

		// Cache fills lazily per template; same attachment_id is uploaded once per run.
		$cache = new MediaUploadCache();

		foreach ($eligible as $item) {
			$this->publish_post_by_template_without_check($wp_post, $item, true, $cache);
		}
	}

	/**
	 * @param \WP_Post              $wp_post
	 * @param array<string, mixed>  $template
	 * @param bool                  $exclude_duplicates
	 * @param MediaUploadCache|null $cache
	 */
	public function publish_post_by_template_without_check($wp_post, $template, $exclude_duplicates, $cache = null)
	{
		global $wpdb;

		if ($exclude_duplicates && $template['exclude_duplicates'] && self::has_duplicate($wp_post->ID, $template['id'])) {
			return;
		}

		$post_text = TextProcessor::replace_post_text($wp_post, $template['post_text']);
		$post_text = Tools::clear_text($post_text);

		$post_tags = TextProcessor::replace_post_text($wp_post, $template['post_tags']);
		$post_tags = Tools::clear_text($post_tags);

		$post_images = $template['post_images'] ?? [];
		if (!is_array($post_images)) {
			$post_images = [];
		}
		list($image_ids, $image_urls) = self::upload_wp_images($wp_post, $post_images, $cache);

		$post_fields = [
			'text' => $post_text,
			'tags' => $post_tags,
			'link' => TextProcessor::replace_post_text($wp_post, $template['post_link']),
			'images' => $image_ids,
			'image_urls' => $image_urls,
			'extra' => [
				'wp_post_id' => $wp_post->ID,
				'wp_autoposting_id' => (int) $template['id'],
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

		$publish_at = null;
		$publish_delay_minutes = null;
		switch ($template['when_publish']) {
			case 'immediately':
				$publish_at = ApiHelpers::formatCurrentDatetime();
				break;
			case 'delay':
				$delay = intval($template['publish_delay']);
				if ($delay < 1) {
					$delay = 1;
				} elseif ($delay > 10) {
					$delay = 10;
				}
				$publish_delay_minutes = $delay;
				break;
		}

		$pp_post = [
			'fields' => $post_fields,
			'networks' => [
				'accounts' => $template['account_ids'],
			]
		];
		if ($publish_delay_minutes !== null) {
			$pp_post['publish_delay_minutes'] = $publish_delay_minutes;
		} else {
			$pp_post['publish_at'] = $publish_at;
		}

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
		} else {
			PP::log([
				'create_pp_post_failed',
				'wp_post_id' => $wp_post->ID,
				'autoposting_id' => (int) $template['id'],
				'images_count' => count($image_ids),
				'image_urls_count' => count($image_urls),
				'error' => $res['error'],
			]);
		}
	}

	/**
	 * Whether a remote ParrotPoster post may be updated from WordPress (matches api-server UpdatePost rules).
	 *
	 * @param array<string, mixed> $remote_post Response body from GET posts/{id}
	 */
	private static function is_pp_post_update_allowed(array $remote_post): bool
	{
		$status = isset($remote_post['status']) ? strtolower((string) $remote_post['status']) : '';
		if ($status !== 'success' && $status !== 'fail') {
			return $status === 'ready';
		}

		$publish_at = $remote_post['publish_at'] ?? '';
		if (!is_string($publish_at) || $publish_at === '') {
			return false;
		}

		try {
			$publish = new \DateTimeImmutable($publish_at);
		} catch (\Exception $e) {
			return false;
		}

		$cutoff = $publish->modify('+1 day');
		$now = new \DateTimeImmutable('now');

		return $cutoff >= $now;
	}

	/**
	 * Push WordPress post edits to already-created ParrotPoster posts (no delete/recreate).
	 * Omit publish_at so api-server keeps the original schedule for pending posts.
	 */
	public static function update_pp_posts_for_wp_post(\WP_Post $wp_post): void
	{
		global $wpdb;

		$table = $wpdb->prefix . 'parrotposter_posts';
		$rows = $wpdb->get_results(
			$wpdb->prepare("SELECT post_id, autoposting_id FROM {$table} WHERE wp_post_id = %d", $wp_post->ID),
			ARRAY_A
		);
		if (!is_array($rows) || empty($rows)) {
			return;
		}

		$autopostings = WpPostHelpers::list_autoposting_by_post($wp_post);
		$templates_by_id = [];
		foreach ($autopostings as $tpl) {
			$templates_by_id[(int) $tpl['id']] = $tpl;
		}

		// Cache fills lazily per linked PP post; no upfront upload for rows that may be skipped.
		$cache = new MediaUploadCache();

		foreach ($rows as $row) {
			$pp_post_id = isset($row['post_id']) ? (string) $row['post_id'] : '';
			$tpl_id = isset($row['autoposting_id']) ? (int) $row['autoposting_id'] : 0;
			if ($pp_post_id === '' || $tpl_id < 1) {
				continue;
			}
			$template = $templates_by_id[$tpl_id] ?? null;
			if (!$template || !$template['enable'] || empty($template['account_ids'])) {
				continue;
			}
			if (!Conditions::check($template['conditions'], $wp_post)) {
				continue;
			}

			list($remote, $get_err) = Api::get_post($pp_post_id);
			if (!is_array($remote) || !empty($get_err)) {
				PP::log(['update_pp_post', 'pp_post_id' => $pp_post_id, 'error' => $get_err ?? 'invalid remote']);
				continue;
			}
			if (!self::is_pp_post_update_allowed($remote)) {
				PP::log([
					'update_pp_post_skipped',
					'pp_post_id' => $pp_post_id,
					'status' => $remote['status'] ?? null,
					'publish_at' => $remote['publish_at'] ?? null,
				]);
				continue;
			}

			$post_text = TextProcessor::replace_post_text($wp_post, $template['post_text']);
			$post_text = Tools::clear_text($post_text);

			$post_tags = TextProcessor::replace_post_text($wp_post, $template['post_tags']);
			$post_tags = Tools::clear_text($post_tags);

			$post_images = $template['post_images'] ?? [];
			if (!is_array($post_images)) {
				$post_images = [];
			}
			list($image_ids, $image_urls) = self::upload_wp_images($wp_post, $post_images, $cache);

			$fields = [
				'text' => $post_text,
				'tags' => $post_tags,
				'link' => TextProcessor::replace_post_text($wp_post, $template['post_link']),
				'images' => $image_ids,
				'image_urls' => $image_urls,
				'extra' => [
					'wp_post_id' => $wp_post->ID,
					'wp_autoposting_id' => (int) $template['id'],
					'wp_site_domain' => WpPostHelpers::get_site_domain(),
					'vk_signed' => !!$template['extra_vk_signed'],
					'vk_from_group' => !!$template['extra_vk_from_group'],
				],
			];

			if ($template['utm_enable']) {
				$fields['need_utm'] = true;
				$fields['utm_params'] = [
					'utm_source' => TextProcessor::replace_post_text($wp_post, $template['utm_source']),
					'utm_medium' => TextProcessor::replace_post_text($wp_post, $template['utm_medium']),
					'utm_content' => TextProcessor::replace_post_text($wp_post, $template['utm_content']),
					'utm_term' => TextProcessor::replace_post_text($wp_post, $template['utm_term']),
					'utm_campaign' => TextProcessor::replace_post_text($wp_post, $template['utm_campaign']),
				];
			}

			$fields = Tools::filter_post_fields_for_api($fields, true);

			$update_data = [
				'fields' => $fields,
				'networks' => [
					'accounts' => $template['account_ids'],
				],
			];

			$res = Api::update_post($pp_post_id, $update_data);
			if (!empty($res['error'])) {
				PP::log(['update_pp_post', 'pp_post_id' => $pp_post_id, 'error' => $res['error']]);
			}
		}
	}

	/**
	 * @param \WP_Post                   $wp_post
	 * @param array<int, string>         $images_txt_keys
	 * @param MediaUploadCache|null      $cache
	 * @return array{0: string[], 1: string[]}
	 */
	public static function upload_wp_images($wp_post, $images_txt_keys, $cache = null)
	{
		$images_txt = implode('', $images_txt_keys);
		$wp_images_ids = TextProcessor::replace_post_image_text_to_ids($wp_post, $images_txt);
		$image_ids = [];
		$image_urls = [];
		$output_seen = [];

		foreach ($wp_images_ids as $raw) {
			$original = $raw;
			$id = sanitize_text_field($raw);

			if (Tools::str_starts_with((string) $original, 'http')) {
				$key = MediaUploadCache::keyForUrl((string) $original);
				if (isset($output_seen[$key])) {
					continue;
				}
				$resolved = self::resolve_wp_image_source($wp_post, $original, $id, $original, $cache);
				$output_seen[$key] = true;
				self::append_resolved_media($resolved, $image_ids, $image_urls);
				continue;
			}

			if ($id === '' || !preg_match('/^\d+$/', $id)) {
				continue;
			}

			$key = MediaUploadCache::keyForAttachmentId($id);
			if (isset($output_seen[$key])) {
				continue;
			}

			$att_url = wp_get_attachment_url((int) $id);
			$resolved = self::resolve_wp_image_source($wp_post, $original, $id, is_string($att_url) ? $att_url : '', $cache);
			$output_seen[$key] = true;
			if (!empty($att_url)) {
				$output_seen[MediaUploadCache::keyForUrl($att_url)] = true;
			}
			self::append_resolved_media($resolved, $image_ids, $image_urls);
		}

		return [$image_ids, $image_urls];
	}

	/**
	 * @param array{file_id: ?string, fallback_url: ?string} $resolved
	 * @param string[]                                      $image_ids
	 * @param string[]                                      $image_urls
	 */
	private static function append_resolved_media(array $resolved, array &$image_ids, array &$image_urls): void
	{
		if (!empty($resolved['file_id'])) {
			$image_ids[] = $resolved['file_id'];
			return;
		}
		if (!empty($resolved['fallback_url'])) {
			$image_urls[] = $resolved['fallback_url'];
		}
	}

	/**
	 * @param \WP_Post              $wp_post
	 * @param string                $original raw value from field resolver
	 * @param string                $id       sanitized attachment id or empty
	 * @param string                $url_hint attachment URL if known
	 * @param MediaUploadCache|null $cache
	 * @return array{file_id: ?string, fallback_url: ?string}
	 */
	private static function resolve_wp_image_source($wp_post, $original, $id, $url_hint, $cache)
	{
		if (Tools::str_starts_with((string) $original, 'http')) {
			$cache_key = MediaUploadCache::keyForUrl((string) $original);
			if ($cache instanceof MediaUploadCache && $cache->has($cache_key)) {
				$cached = $cache->get($cache_key);
				if (is_array($cached)) {
					return $cached;
				}
			}
			$result = [
				'file_id' => null,
				'fallback_url' => (string) $original,
			];
			if ($cache instanceof MediaUploadCache) {
				$cache->set($cache_key, null, $result['fallback_url']);
			}
			return $result;
		}

		$cache_key = MediaUploadCache::keyForAttachmentId($id);
		if ($cache instanceof MediaUploadCache && $cache->has($cache_key)) {
			$cached = $cache->get($cache_key);
			if (is_array($cached)) {
				return $cached;
			}
		}

		$attached_file = get_attached_file($id);
		$att_url = $url_hint !== '' ? $url_hint : wp_get_attachment_url((int) $id);
		if (!is_string($att_url)) {
			$att_url = '';
		}

		if (empty($attached_file)) {
			$fallback = (Tools::str_starts_with($att_url, 'http')) ? $att_url : null;
			$result = ['file_id' => null, 'fallback_url' => $fallback];
			if ($cache instanceof MediaUploadCache) {
				$cache->set($cache_key, null, $fallback);
			}
			if ($fallback === null && $att_url === '') {
				PP::log([
					'upload_wp_images_failed',
					'wp_post_id' => $wp_post->ID,
					'attachment_id' => $id,
					'reason' => 'no_local_file_and_no_url',
				]);
			}
			return $result;
		}

		$res = Api::upload_file($attached_file);
		$file_id = ApiHelpers::retrieve_response($res, 'file_id');
		if (!empty($file_id)) {
			$result = ['file_id' => (string) $file_id, 'fallback_url' => null];
			if ($cache instanceof MediaUploadCache) {
				$cache->set($cache_key, $result['file_id'], null);
				if (Tools::str_starts_with($att_url, 'http')) {
					$cache->set(MediaUploadCache::keyForUrl($att_url), $result['file_id'], null);
				}
			}
			return $result;
		}

		$fallback = (Tools::str_starts_with($att_url, 'http')) ? $att_url : null;
		if ($fallback !== null) {
			PP::log([
				'upload_wp_images_fallback_url',
				'wp_post_id' => $wp_post->ID,
				'attachment_id' => $id,
				'url' => $fallback,
				'upload_error' => $res['error'] ?? null,
			]);
		} else {
			PP::log([
				'upload_wp_images_failed',
				'wp_post_id' => $wp_post->ID,
				'attachment_id' => $id,
				'upload_error' => $res['error'] ?? null,
			]);
		}

		$result = ['file_id' => null, 'fallback_url' => $fallback];
		if ($cache instanceof MediaUploadCache) {
			$cache->set($cache_key, null, $fallback);
			if ($fallback !== null) {
				$cache->set(MediaUploadCache::keyForUrl($fallback), null, $fallback);
			}
		}

		return $result;
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

	/**
	 * Последнее время публикации по каждому шаблону для одного WP-поста (один список постов API вместо N запросов last-publish-at).
	 *
	 * @param int   $wp_post_id
	 * @param array $template_ids список id шаблонов (как приходит из запроса)
	 * @return array<string|int, string|false> ключ — id шаблона, значение — ISO datetime для UI или false
	 */
	public static function last_publish_at_for_templates($wp_post_id, $template_ids)
	{
		global $wpdb;

		$wp_post_id = (int) $wp_post_id;
		$out = [];

		if (!is_array($template_ids) || empty($template_ids)) {
			return $out;
		}

		foreach ($template_ids as $tid_raw) {
			$out[$tid_raw] = false;
		}

		if ($wp_post_id < 1) {
			return $out;
		}

		$template_ids_int = [];
		foreach ($template_ids as $tid) {
			$tid = (int) $tid;
			if ($tid > 0) {
				$template_ids_int[$tid] = true;
			}
		}
		$template_ids_int = array_keys($template_ids_int);
		if (empty($template_ids_int)) {
			return $out;
		}

		$table = $wpdb->prefix . 'parrotposter_posts';
		$in_placeholders = implode(',', array_fill(0, count($template_ids_int), '%d'));
		$sql = "SELECT post_id, autoposting_id FROM {$table} WHERE wp_post_id = %d AND autoposting_id IN ({$in_placeholders})";
		$sql = $wpdb->prepare($sql, array_merge([$wp_post_id], $template_ids_int));
		$rows = $wpdb->get_results($sql, ARRAY_A);
		if (!is_array($rows)) {
			$rows = [];
		}

		$by_template = [];
		$needed_post_ids = [];
		foreach ($rows as $row) {
			$apid = isset($row['post_id']) ? (string) $row['post_id'] : '';
			$aid = isset($row['autoposting_id']) ? (int) $row['autoposting_id'] : 0;
			if ($apid === '' || $aid < 1) {
				continue;
			}
			if (!isset($by_template[$aid])) {
				$by_template[$aid] = [];
			}
			$by_template[$aid][] = $apid;
			$needed_post_ids[$apid] = true;
		}

		if (empty($needed_post_ids)) {
			return $out;
		}

		$publish_by_id = self::fetch_publish_at_map_for_wp_post($wp_post_id, array_keys($needed_post_ids));

		foreach ($template_ids as $tid_raw) {
			$aid = (int) $tid_raw;
			if ($aid < 1 || empty($by_template[$aid])) {
				continue;
			}
			$ids = $by_template[$aid];
			$best = self::max_publish_at_among_post_ids($ids, $publish_by_id);
			if ($best !== null) {
				$out[$tid_raw] = $best;
				continue;
			}
			$out[$tid_raw] = Api::get_last_post_publish_at($ids);
		}

		return $out;
	}

	/**
	 * @param string[] $needed_post_ids
	 * @return array<string, string> post_id => publish_at (ISO)
	 */
	private static function fetch_publish_at_map_for_wp_post($wp_post_id, array $needed_post_ids)
	{
		$wp_post_id = (int) $wp_post_id;
		$needed = [];
		foreach ($needed_post_ids as $pid) {
			$pid = (string) $pid;
			if ($pid !== '') {
				$needed[$pid] = true;
			}
		}
		if ($wp_post_id < 1 || empty($needed)) {
			return [];
		}

		$publish_by_id = [];
		$page = 1;
		$page_size = 100;
		$max_pages = 50;

		$filter = [
			'user_id' => Options::user_id(),
			'fields.extra.wp_post_id' => $wp_post_id,
		];

		while ($page <= $max_pages) {
			$res = Api::list_posts($filter, [], [
				'page' => $page,
				'size' => $page_size,
				'skip_total' => true,
			]);
			if (!empty($res['error']) || empty($res['response']['posts']) || !is_array($res['response']['posts'])) {
				break;
			}
			$posts = $res['response']['posts'];
			foreach ($posts as $post) {
				if (!is_array($post)) {
					continue;
				}
				$pid = isset($post['id']) ? (string) $post['id'] : '';
				if ($pid === '' || !isset($needed[$pid])) {
					continue;
				}
				if (!empty($post['publish_at'])) {
					$publish_by_id[$pid] = (string) $post['publish_at'];
				}
			}
			if (count($posts) < $page_size) {
				break;
			}
			if (count($publish_by_id) >= count($needed)) {
				break;
			}
			$page++;
		}

		return $publish_by_id;
	}

	/**
	 * @param string[] $post_ids
	 * @param array<string, string> $publish_by_id
	 */
	private static function max_publish_at_among_post_ids(array $post_ids, array $publish_by_id)
	{
		$best_ts = null;
		$best_iso = null;
		foreach ($post_ids as $pid) {
			$pid = (string) $pid;
			if (!isset($publish_by_id[$pid])) {
				continue;
			}
			$iso = $publish_by_id[$pid];
			if ($iso === '') {
				continue;
			}
			$ts = strtotime($iso);
			if ($ts === false) {
				continue;
			}
			if ($best_ts === null || $ts > $best_ts) {
				$best_ts = $ts;
				$best_iso = $iso;
			}
		}

		return $best_iso;
	}

	public static function wp_post_has_linked_pp_posts(int $wp_post_id): bool
	{
		global $wpdb;

		if ($wp_post_id <= 0) {
			return false;
		}

		$table = $wpdb->prefix . 'parrotposter_posts';
		$cnt = (int) $wpdb->get_var($wpdb->prepare(
			"SELECT COUNT(*) FROM {$table} WHERE wp_post_id = %d",
			$wp_post_id
		));

		return $cnt > 0;
	}

	/**
	 * Deletes all ParrotPoster API posts linked to a WordPress post and clears local mapping rows.
	 */
	public static function delete_all_pp_posts_for_wp_post(int $wp_post_id): void
	{
		global $wpdb;

		if ($wp_post_id <= 0) {
			return;
		}

		$table = $wpdb->prefix . 'parrotposter_posts';
		$rows = $wpdb->get_results(
			$wpdb->prepare("SELECT post_id FROM {$table} WHERE wp_post_id = %d", $wp_post_id),
			ARRAY_A
		);
		if (!is_array($rows)) {
			$rows = [];
		}

		foreach ($rows as $row) {
			$pid = isset($row['post_id']) ? (string) $row['post_id'] : '';
			if ($pid === '') {
				continue;
			}
			Api::delete_post($pid);
		}

		$wpdb->delete($table, ['wp_post_id' => $wp_post_id], ['%d']);
	}
}

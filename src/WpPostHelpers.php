<?php

namespace parrotposter;

defined('ABSPATH') || exit;

class WpPostHelpers
{
	public static function get_images_from_content($content)
	{
		if (!is_array($content)) {
			$content = [$content];
		}
		$srcList = [];
		foreach ($content as $c) {
			preg_match_all('/<img.+?src=[\'"](?<src>[^\'"]+)[\'"].*?>/i', $c, $matches);
			$srcList = array_merge($srcList, $matches['src']);
		}
		return $srcList;
	}

	public static function get_image_ids_from_content($content)
	{
		$urls = self::get_images_from_content($content);
		$ids = [];
		$other_urls = [];
		foreach ($urls as $url) {
			$id = self::resolve_attachment_id_for_content_url($url);
			if (empty($id)) {
				$other_urls[] = $url;
				continue;
			}
			$ids[] = $id;
		}
		return array_merge(array_unique($ids), array_unique($other_urls));
	}

	/**
	 * Collect video source URLs from raw HTML (`<video>`, `<source>`).
	 *
	 * @param string|list<string> $content
	 * @return list<string>
	 */
	public static function get_videos_from_content($content)
	{
		if (!is_array($content)) {
			$content = [$content];
		}
		$src_list = [];
		foreach ($content as $c) {
			if (!is_string($c) || $c === '') {
				continue;
			}
			preg_match_all('/<video[^>]+src=[\'"](?<src>[^\'"]+)[\'"][^>]*>/i', $c, $video_matches);
			if (!empty($video_matches['src'])) {
				$src_list = array_merge($src_list, $video_matches['src']);
			}
			preg_match_all('/<source[^>]+src=[\'"](?<src>[^\'"]+)[\'"][^>]*>/i', $c, $source_matches);
			if (!empty($source_matches['src'])) {
				$src_list = array_merge($src_list, $source_matches['src']);
			}
			// Gutenberg core/video: <!-- wp:video -->…<!-- /wp:video -->
			if (preg_match_all('/<!--\s*wp:video\b[^>]*-->.*?<!--\s*\/wp:video\s*-->/is', $c, $blocks)) {
				foreach ($blocks[0] as $block) {
					preg_match_all('/(?:src|url)=[\'"](?<src>[^\'"]+)[\'"]/i', $block, $block_srcs);
					if (!empty($block_srcs['src'])) {
						$src_list = array_merge($src_list, $block_srcs['src']);
					}
				}
			}
		}

		return array_values(array_unique(array_filter($src_list)));
	}

	/**
	 * @param string|list<string> $content
	 * @return list<int|string>
	 */
	public static function get_video_ids_from_content($content)
	{
		return self::resolve_media_refs(self::get_videos_from_content($content));
	}

	/**
	 * GIF image URLs embedded in content (`image/gif` or `.gif` extension).
	 *
	 * @param string|list<string> $content
	 * @return list<int|string>
	 */
	public static function get_gif_ids_from_content($content)
	{
		$urls = self::get_images_from_content($content);
		$gifs = [];
		foreach ($urls as $url) {
			if (!is_string($url) || $url === '') {
				continue;
			}
			$path = (string) (parse_url($url, PHP_URL_PATH) ?: $url);
			if (!preg_match('/\.gif$/i', $path)) {
				$id = self::resolve_attachment_id_for_content_url($url);
				if (empty($id)) {
					continue;
				}
				$mime = (string) get_post_mime_type((int) $id);
				if ($mime !== 'image/gif') {
					continue;
				}
				$gifs[] = (int) $id;
				continue;
			}
			$id = self::resolve_attachment_id_for_content_url($url);
			$gifs[] = !empty($id) ? (int) $id : $url;
		}

		return array_values(array_unique($gifs, SORT_REGULAR));
	}

	/**
	 * @param string $mime_prefix e.g. "video", "audio"
	 * @return list<int>
	 */
	public static function get_attached_media_ids($post, string $mime_prefix): array
	{
		$post_id = $post instanceof \WP_Post ? (int) $post->ID : (int) $post;
		if ($post_id <= 0) {
			return [];
		}
		$attachments = get_attached_media($mime_prefix, $post_id);
		if (!is_array($attachments) || $attachments === []) {
			return [];
		}
		$ids = [];
		foreach ($attachments as $attachment) {
			if ($attachment instanceof \WP_Post) {
				$ids[] = (int) $attachment->ID;
			}
		}

		return array_values(array_unique(array_filter($ids)));
	}

	/**
	 * Non-image/video/audio attachments (documents).
	 *
	 * @return list<int>
	 */
	public static function get_attached_document_ids($post): array
	{
		$post_id = $post instanceof \WP_Post ? (int) $post->ID : (int) $post;
		if ($post_id <= 0) {
			return [];
		}
		$query = new \WP_Query([
			'post_type' => 'attachment',
			'post_parent' => $post_id,
			'post_status' => 'inherit',
			'posts_per_page' => 100,
			'fields' => 'ids',
			'orderby' => 'menu_order ID',
			'order' => 'ASC',
		]);
		$ids = [];
		foreach ((array) $query->posts as $id) {
			$id = (int) $id;
			$mime = (string) get_post_mime_type($id);
			if ($mime === '' || strpos($mime, 'image/') === 0 || strpos($mime, 'video/') === 0 || strpos($mime, 'audio/') === 0) {
				continue;
			}
			$ids[] = $id;
		}

		return array_values(array_unique($ids));
	}

	/**
	 * @param list<string> $urls
	 * @return list<int|string>
	 */
	private static function resolve_media_refs(array $urls): array
	{
		$ids = [];
		$other_urls = [];
		foreach ($urls as $url) {
			$id = self::resolve_attachment_id_for_content_url($url);
			if (empty($id)) {
				$other_urls[] = $url;
				continue;
			}
			$ids[] = (int) $id;
		}

		return array_merge(array_values(array_unique($ids)), array_values(array_unique($other_urls)));
	}

	/**
	 * Resolve content media URL to an attachment id without LIKE basename false-matches.
	 * Exact match first; LIKE image path only as a guarded fallback for resized image URLs.
	 *
	 * @param string $url
	 * @return int|false
	 */
	private static function resolve_attachment_id_for_content_url($url)
	{
		if (!is_string($url) || $url === '') {
			return false;
		}

		$exact = self::get_attachment_id_by_url($url);
		if ($exact) {
			return (int) $exact;
		}

		// Broken / unknown absolute URL: do not remap via LIKE basename.
		$dir = wp_upload_dir();
		if (self::is_external_url($url, $dir['baseurl'])) {
			return false;
		}

		return self::get_attachment_id($url);
	}

	public static function get_post_types($output = 'names')
	{
		$post_types = get_post_types(['public' => true], $output);
		$exclude = ['attachment', 'nav_menu_item', 'revision'];
		if ($output == 'names') {
			$post_types = array_diff($post_types, $exclude);
		}
		if ($output == 'object') {
			$post_types = array_diff_key($post_types, array_combine($exclude, $exclude));
		}
		return $post_types;
	}

	/**
	 * Возвращает ID вложения по URL файла на этом же сайте.
	 *
	 * Раньше использовался `guid RLIKE` по `wp_posts` без индекса (полный скан).
	 * Используется {@see attachment_url_to_postid()}: выборка по `postmeta._wp_attached_file`
	 * с точным совпадением пути (как в ядре с WP 4.0).
	 *
	 * @param string $url URL файла, например https://example.com/wp-content/uploads/2013/05/test-image.jpg
	 *
	 * @return int|null ID вложения или null, если не найдено
	 */
	public static function get_attachment_id_by_url($url)
	{
		if (!is_string($url) || $url === '') {
			return null;
		}

		$this_host = str_ireplace('www.', '', (string) parse_url(home_url(), PHP_URL_HOST));
		$file_host = str_ireplace('www.', '', (string) parse_url($url, PHP_URL_HOST));

		if ($this_host === '' || $file_host === '' || strcasecmp($this_host, $file_host) !== 0) {
			return null;
		}

		$content_path = parse_url(WP_CONTENT_URL, PHP_URL_PATH);
		if (is_string($content_path) && $content_path !== '') {
			$parsed_url = explode($content_path, $url, 2);
			if (!isset($parsed_url[1]) || $parsed_url[1] === '') {
				return null;
			}
		}

		$id = (int) attachment_url_to_postid($url);

		return $id > 0 ? $id : null;
	}

	/**
	 * Get the Attachment ID for a given image URL.
	 *
	 * @link   http://wordpress.stackexchange.com/a/7094
	 *
	 * @param  string $url
	 *
	 * @return boolean|integer
	 */
	public static function get_attachment_id($url)
	{
		if (!is_string($url) || $url === '') {
			return false;
		}

		$exact = self::get_attachment_id_by_url($url);
		if ($exact) {
			return (int) $exact;
		}

		$dir = wp_upload_dir();

		$file = $url;
		if (!static::is_external_url($url, $dir['baseurl'])) {
			$file = basename($url);
		}

		$query = [
			'post_type' => 'attachment',
			'fields' => 'ids',
			'meta_query' => [
				[
					'key' => '_wp_attached_file',
					'value' => $file,
					'compare' => 'LIKE',
				],
			]
		];

		// query attachments
		$ids = get_posts($query);

		if (!empty($ids)) {
			foreach ($ids as $id) {
				$attachment_url = wp_get_attachment_url($id);
				if (is_string($attachment_url) && $attachment_url !== '' && static::normalize_url($url) === static::normalize_url($attachment_url)) {
					return $id;
				}

				// first entry of returned array is the URL (images only)
				$arr = wp_get_attachment_image_src($id, 'full');
				if (!is_array($arr) || $arr === []) {
					continue;
				}
				$src = array_shift($arr);
				if (is_string($src) && $url === $src) {
					return $id;
				}
			}
		}

		$query['meta_query'][0]['key'] = '_wp_attachment_metadata';

		// query attachments again
		$ids = get_posts($query);

		if (empty($ids)) {
			return false;
		}

		foreach ($ids as $id) {
			$meta = wp_get_attachment_metadata($id);
			if (!is_array($meta) || empty($meta['sizes']) || !is_array($meta['sizes'])) {
				continue;
			}

			foreach ($meta['sizes'] as $size => $values) {
				if (!is_array($values) || !isset($values['file'])) {
					continue;
				}
				$img_src = wp_get_attachment_image_src($id, $size);
				if (!is_array($img_src) || $img_src === []) {
					continue;
				}
				$img_src_url = static::normalize_url(array_shift($img_src));
				if ($values['file'] === $file && static::normalize_url($url) === $img_src_url) {
					return $id;
				}
			}
		}

		return false;
	}

	public static function list_autoposting_by_post($post)
	{
		$result = [];
		$items = DBAutopostingTable::get_all();
		foreach ($items as $item) {
			if ($item['wp_post_type'] == $post->post_type) {
				$result[$item['id']] = $item;
			}
		}
		return $result;
	}

	public static function get_site_domain()
	{
		$wp_domain = site_url();
		$wp_domain = str_replace(['http://', 'https://'], '', $wp_domain);
		if (strpos($wp_domain, '/') !== false) {
			$wp_domain = strstr($wp_domain, '/', true);
		}
		return $wp_domain;
	}

	private static function is_external_url($url, $base_url)
	{
		$url = static::normalize_url($url);
		$base_url = static::normalize_url($base_url);
		return false === strpos($url, $base_url);
	}

	private static function normalize_url($url)
	{
		return str_replace('http://', 'https://', $url);
	}
}

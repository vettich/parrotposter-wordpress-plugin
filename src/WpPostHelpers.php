<?php

namespace parrotposter;

defined('ABSPATH') || exit;

class WpPostHelpers
{
	public static function get_images_from_content($content)
	{
		$output = preg_match_all('/<img.+?src=[\'"](?<src>[^\'"]+)[\'"].*?>/i', $content, $matches);
		return $matches['src'];
	}

	public static function get_image_ids_from_content($content)
	{
		$urls = self::get_images_from_content($content);
		$ids = [];
		foreach ($urls as $url) {
			$id = self::get_attachment_id($url);
			if (empty($id)) {
				continue;
			}
			$ids[] = $id;
		}
		return $ids;
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
	 * Return an ID of an attachment by searching the database with the file URL.
	 *
	 * First checks to see if the $url is pointing to a file that exists in
	 * the wp-content directory. If so, then we search the database for a
	 * partial match consisting of the remaining path AFTER the wp-content
	 * directory. Finally, if a match is found the attachment ID will be
	 * returned.
	 *
	 * https://frankiejarrett.com/2013/05/get-an-attachment-id-by-url-in-wordpress/
	 *
	 * @param string $url The URL of the image (ex: http://mysite.com/wp-content/uploads/2013/05/test-image.jpg)
	 *
	 * @return int|null $attachment Returns an attachment ID, or null if no attachment is found
	 */
	public static function get_attachment_id_by_url($url)
	{
		// Split the $url into two parts with the wp-content directory as the separator
		$parsed_url = explode(parse_url(WP_CONTENT_URL, PHP_URL_PATH), $url);

		// Get the host of the current site and the host of the $url, ignoring www
		$this_host = str_ireplace('www.', '', parse_url(home_url(), PHP_URL_HOST));
		$file_host = str_ireplace('www.', '', parse_url($url, PHP_URL_HOST));

		// Return nothing if there aren't any $url parts or if the current host and $url host do not match
		if (! isset($parsed_url[1]) || empty($parsed_url[1]) || ($this_host != $file_host)) {
			return;
		}

		// Now we're going to quickly search the DB for any attachment GUID with a partial path match
		// Example: /uploads/2013/05/test-image.jpg
		global $wpdb;

		$attachment = $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->prefix}posts WHERE guid RLIKE %s;", $parsed_url[1]));

		// Returns null if no attachment is found
		return $attachment[0];
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
		$dir = wp_upload_dir();

		// baseurl never has a trailing slash
		if (false === strpos($url, $dir['baseurl'] . '/')) {
			// URL points to a place outside of upload directory
			return false;
		}

		$file = basename($url);
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

		if (! empty($ids)) {
			foreach ($ids as $id) {

				// first entry of returned array is the URL
				$arr = wp_get_attachment_image_src($id, 'full');
				if ($url === array_shift($arr)) {
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

			foreach ($meta['sizes'] as $size => $values) {
				$img_src = wp_get_attachment_image_src($id, $size);
				if ($values['file'] === $file && $url === array_shift($img_src)) {
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
}

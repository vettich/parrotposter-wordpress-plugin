<?php

namespace parrotposter;

defined('ABSPATH') || exit;

/**
 * Local post-meta cache for the ParrotPoster column on edit.php (pipeline mode).
 */
class PublishColumnCache
{
	public const META_KEY = '_pp_publish_col';

	public const TTL_SEC = 300;

	/**
	 * @return array{has_posts: bool, socials: list<array{account_id: string, type: string, link: string, success: bool|null, error: string}>, cached_at: int}|null
	 */
	public static function get(int $wp_post_id)
	{
		if ($wp_post_id < 1) {
			return null;
		}

		$raw = get_post_meta($wp_post_id, self::META_KEY, true);
		if (is_array($raw)) {
			$data = $raw;
		} elseif (is_string($raw) && $raw !== '') {
			$decoded = json_decode($raw, true);
			$data = is_array($decoded) ? $decoded : null;
		} else {
			$data = null;
		}

		if (!is_array($data) || !self::is_valid_payload($data)) {
			return null;
		}

		$cached_at = isset($data['cached_at']) ? (int) $data['cached_at'] : 0;
		if ($cached_at < 1 || (time() - $cached_at) > self::TTL_SEC) {
			return null;
		}

		return $data;
	}

	/**
	 * @param array{has_posts?: bool, socials?: array, cached_at?: int} $data
	 */
	public static function set(int $wp_post_id, array $data): void
	{
		if ($wp_post_id < 1) {
			return;
		}
		$data['cached_at'] = time();
		// Store a PHP array. json_encode + update_post_meta slashes `\uXXXX`
		// escapes and turns "Опубликован" into the literal `u041e…` on read.
		update_post_meta($wp_post_id, self::META_KEY, $data);
	}

	public static function invalidate(int $wp_post_id): void
	{
		if ($wp_post_id < 1) {
			return;
		}
		delete_post_meta($wp_post_id, self::META_KEY);
	}

	/**
	 * Build cache payload from HMAC-mapped PP posts (union by account_id).
	 *
	 * @param list<array<string, mixed>> $posts
	 * @return array{has_posts: bool, socials: list<array{account_id: string, type: string, link: string, success: bool|null, error: string}>, cached_at: int}
	 */
	public static function from_posts(array $posts): array
	{
		if (empty($posts)) {
			return [
				'has_posts' => false,
				'socials' => [],
				'cached_at' => time(),
			];
		}

		usort($posts, static function ($a, $b) {
			$ta = isset($a['publish_at']) ? (string) $a['publish_at'] : '';
			$tb = isset($b['publish_at']) ? (string) $b['publish_at'] : '';

			return strcmp($tb, $ta);
		});

		$by_account = [];
		foreach ($posts as $post) {
			if (!is_array($post)) {
				continue;
			}
			$post_at = isset($post['publish_at']) ? (string) $post['publish_at'] : '';
			$results = isset($post['results']) && is_array($post['results']) ? $post['results'] : [];
			foreach ($results as $row) {
				if (!is_array($row)) {
					continue;
				}
				$account_id = isset($row['account_id']) ? (string) $row['account_id'] : '';
				$type = isset($row['social_type']) ? (string) $row['social_type'] : '';
				if ($account_id === '' || $type === '') {
					continue;
				}
				$link = isset($row['link']) ? (string) $row['link'] : '';
				$success = array_key_exists('success', $row) ? $row['success'] : null;
				$error = isset($row['error']) ? (string) $row['error'] : '';
				$at = isset($row['published_at']) && (string) $row['published_at'] !== ''
					? (string) $row['published_at']
					: $post_at;

				if (!isset($by_account[$account_id])) {
					$by_account[$account_id] = [
						'account_id' => $account_id,
						'type' => $type,
						'link' => $link,
						'success' => $success,
						'error' => $error,
						'attempt_at' => $at,
					];
					continue;
				}

				$cur = &$by_account[$account_id];
				if ($at > $cur['attempt_at']) {
					$cur['attempt_at'] = $at;
					$cur['success'] = $success;
					$cur['error'] = $error;
					$cur['type'] = $type;
					if ($link !== '') {
						$cur['link'] = $link;
					}
				} elseif ($cur['link'] === '' && $link !== '') {
					$cur['link'] = $link;
				}
				unset($cur);
			}
		}

		$socials = array_values($by_account);
		usort($socials, static function ($a, $b) {
			$type = strcmp($a['type'], $b['type']);
			if ($type !== 0) {
				return $type;
			}

			return strcmp($a['account_id'], $b['account_id']);
		});
		foreach ($socials as &$row) {
			unset($row['attempt_at']);
		}
		unset($row);

		return [
			'has_posts' => true,
			'socials' => $socials,
			'cached_at' => time(),
		];
	}

	public static function render_cell(int $wp_post_id, $data, string $publish_url): string
	{
		$publish_label = __('Publish to social networks', 'parrotposter');
		$open_label = __('Open publication', 'parrotposter');
		$title = esc_attr($publish_label);
		$id_attr = (int) $wp_post_id;
		$href = esc_url($publish_url);

		if (!is_array($data)) {
			return sprintf(
				'<div class="parrotposter-col-cell" data-wp-post-id="%d" data-pp-hydrate="1"><a class="parrotposter-publish" title="%s" href="%s" data-wp-post-id="%d"></a></div>',
				$id_attr,
				$title,
				$href,
				$id_attr
			);
		}

		if (empty($data['has_posts'])) {
			return sprintf(
				'<div class="parrotposter-col-cell" data-wp-post-id="%d"><a class="parrotposter-publish" title="%s" href="%s" data-wp-post-id="%d"></a></div>',
				$id_attr,
				$title,
				$href,
				$id_attr
			);
		}

		$icons = self::render_social_icons(
			isset($data['socials']) && is_array($data['socials']) ? $data['socials'] : [],
			true
		);

		return sprintf(
			'<div class="parrotposter-col-cell parrotposter-col-cell--status" data-wp-post-id="%d"><a class="parrotposter-publish parrotposter-col-hit" title="%s" href="%s" data-wp-post-id="%d"><span class="screen-reader-text">%s</span></a>%s<span class="parrotposter-col-more" aria-hidden="true"></span></div>',
			$id_attr,
			esc_attr($open_label),
			$href,
			$id_attr,
			esc_html($open_label),
			$icons
		);
	}

	/**
	 * @param list<array{account_id?: string, type?: string, link?: string, success?: mixed, error?: string}> $socials
	 * @param bool $interactive Column chips as buttons (popover). false = static (modal / picker).
	 */
	public static function render_social_icons(array $socials, $interactive = false)
	{
		if (empty($socials)) {
			return '';
		}

		$dir = $interactive ? ApiHelpers::accounts_directory() : [];

		$html = '<span class="parrotposter-col-socials">';
		foreach ($socials as $social) {
			if (!is_array($social)) {
				continue;
			}
			$type = isset($social['type']) ? (string) $social['type'] : '';
			$link = isset($social['link']) ? (string) $social['link'] : '';
			$slug = self::icon_slug($type);
			if ($slug === '') {
				continue;
			}
			$file = 'images/' . $slug . '.svg';
			if (!PP::isset_asset($file)) {
				continue;
			}
			$src = PP::asset($file);
			$account_id = isset($social['account_id']) ? (string) $social['account_id'] : '';
			$status_class = self::chip_status_class($social);
			$img = sprintf(
				'<img class="parrotposter-col-social" src="%s" alt="%s" width="16" height="16" />',
				esc_url($src),
				esc_attr($type)
			);

			if ($interactive) {
				$account = ($account_id !== '' && isset($dir[$account_id]) && is_array($dir[$account_id]))
					? $dir[$account_id]
					: [];
				$name = isset($account['name']) ? (string) $account['name'] : '';
				if ($name === '') {
					$name = $account_id !== ''
						? (string) ApiHelpers::get_social_network_name($account_id)
						: $type;
				}
				$photo = isset($account['photo']) ? (string) $account['photo'] : '';
				$error = isset($social['error']) ? (string) $social['error'] : '';
				$status_key = self::chip_status_key($social);
				$status_text = self::chip_status_text($social);
				$label = trim($name . ($status_text !== '' ? ' — ' . $status_text : ''));
				$html .= sprintf(
					'<button type="button" class="parrotposter-col-social-chip%s" data-account-id="%s" data-name="%s" data-photo="%s" data-link="%s" data-error="%s" data-status="%s" data-status-text="%s" aria-label="%s">%s</button>',
					$status_class,
					esc_attr($account_id),
					esc_attr($name),
					esc_url($photo),
					esc_url($link),
					esc_attr($error),
					esc_attr($status_key),
					esc_attr($status_text),
					esc_attr($label),
					$img
				);
				continue;
			}

			$chip_class = 'parrotposter-col-social-chip parrotposter-col-social-chip--static' . $status_class;
			if ($link !== '') {
				$html .= sprintf(
					'<a class="%s" href="%s" target="_blank" rel="noopener noreferrer">%s</a>',
					$chip_class,
					esc_url($link),
					$img
				);
			} else {
				$html .= '<span class="' . $chip_class . '">' . $img . '</span>';
			}
		}
		$html .= '</span>';

		return $html;
	}

	/**
	 * Account rows for the pipeline publish modal (avatar, name, link/error).
	 *
	 * @param list<array{account_id?: string, type?: string, link?: string, success?: mixed, error?: string}> $socials
	 * @return list<array{account_id: string, name: string, photo: string, type: string, success: mixed, link: string, error: string}>
	 */
	public static function accounts_for_modal(array $socials)
	{
		$dir = ApiHelpers::accounts_directory();
		$fallback = PP::asset('images/no-photo.svg');
		$rows = [];
		foreach ($socials as $social) {
			if (!is_array($social)) {
				continue;
			}
			$account_id = isset($social['account_id']) ? (string) $social['account_id'] : '';
			$raw_type = isset($social['type']) ? (string) $social['type'] : '';
			$slug = self::icon_slug($raw_type);
			if ($slug === 'max-white') {
				$slug = 'max';
			}
			$account = ($account_id !== '' && isset($dir[$account_id]) && is_array($dir[$account_id]))
				? $dir[$account_id]
				: [];
			$name = isset($account['name']) ? (string) $account['name'] : '';
			if ($name === '') {
				$name = $account_id !== ''
					? (string) ApiHelpers::get_social_network_name($account_id)
					: $raw_type;
			}
			$photo = isset($account['photo']) ? trim((string) $account['photo']) : '';
			if ($photo === '') {
				$photo = $fallback;
			}
			$rows[] = [
				'account_id' => $account_id,
				'name' => $name,
				'photo' => $photo,
				'type' => $slug,
				'success' => array_key_exists('success', $social) ? $social['success'] : null,
				'link' => isset($social['link']) ? (string) $social['link'] : '',
				'error' => isset($social['error']) ? (string) $social['error'] : '',
			];
		}

		return $rows;
	}

	public static function icon_slug(string $type): string
	{
		$type = strtolower($type);
		$map = [
			'ig' => 'insta',
			'instagram' => 'insta',
			'inst' => 'insta',
		];
		if (isset($map[$type])) {
			$type = $map[$type];
		}
		$allowed = [
			'vk' => true,
			'tg' => true,
			'ok' => true,
			'fb' => true,
			'insta' => true,
			'max' => true,
		];
		if (!isset($allowed[$type])) {
			return '';
		}
		if ($type === 'max' && !PP::isset_asset('images/max.svg') && PP::isset_asset('images/max-white.svg')) {
			return 'max-white';
		}

		return $type;
	}

	/**
	 * @param array<string, mixed> $social
	 */
	public static function chip_status_class(array $social): string
	{
		if (!array_key_exists('success', $social)) {
			return '';
		}
		if ($social['success'] === true) {
			return ' is-ok';
		}
		if ($social['success'] === false) {
			return ' is-fail';
		}

		return ' is-pending';
	}

	/**
	 * @param array<string, mixed> $social
	 */
	public static function chip_status_key(array $social): string
	{
		if (!array_key_exists('success', $social)) {
			return '';
		}
		if ($social['success'] === true) {
			return 'ok';
		}
		if ($social['success'] === false) {
			return 'fail';
		}

		return 'pending';
	}

	/**
	 * @param array<string, mixed> $social
	 */
	public static function chip_status_text(array $social): string
	{
		$key = self::chip_status_key($social);
		if ($key === 'ok') {
			return (string) ApiHelpers::get_post_status_text('success');
		}
		if ($key === 'fail') {
			return (string) ApiHelpers::get_post_status_text('fail');
		}
		if ($key === 'pending') {
			return (string) ApiHelpers::get_post_status_text('queue');
		}

		return '';
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private static function is_valid_payload(array $data): bool
	{
		if (!array_key_exists('has_posts', $data) || !array_key_exists('socials', $data) || !is_array($data['socials'])) {
			return false;
		}
		foreach ($data['socials'] as $social) {
			if (!is_array($social) || !array_key_exists('success', $social)) {
				return false;
			}
		}

		return true;
	}
}

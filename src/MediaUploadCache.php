<?php

namespace parrotposter;

defined('ABSPATH') || exit;

/**
 * In-memory cache of ParrotPoster file uploads for one publish/update run (per WP post).
 * Populated lazily in resolve_wp_image_source(); not pre-warmed for all templates upfront.
 */
class MediaUploadCache
{
	/** @var array<string, array{file_id: ?string, fallback_url: ?string}> */
	private $entries = [];

	public static function keyForAttachmentId(string $attachment_id): string
	{
		return 'id:' . $attachment_id;
	}

	public static function keyForUrl(string $url): string
	{
		return 'url:' . self::normalizeUrlKey($url);
	}

	public static function normalizeUrlKey(string $url): string
	{
		return str_replace('http://', 'https://', $url);
	}

	/**
	 * @return array{file_id: ?string, fallback_url: ?string}|null
	 */
	public function get(string $key): ?array
	{
		return $this->entries[$key] ?? null;
	}

	public function has(string $key): bool
	{
		return isset($this->entries[$key]);
	}

	/**
	 * @param string|null $file_id
	 * @param string|null $fallback_url
	 */
	public function set(string $key, $file_id, $fallback_url): void
	{
		$this->entries[$key] = [
			'file_id' => is_string($file_id) && $file_id !== '' ? $file_id : null,
			'fallback_url' => is_string($fallback_url) && $fallback_url !== '' ? $fallback_url : null,
		];
	}

}

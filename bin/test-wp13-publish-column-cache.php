#!/usr/bin/env php
<?php

/**
 * Smoke tests for PublishColumnCache: TTL, invalidate, from_posts mapping.
 * No live WPDB / HTTP.
 *
 * Usage: php bin/test-wp13-publish-column-cache.php
 */

namespace parrotposter {
	class PP
	{
		public static function isset_asset($file)
		{
			return true;
		}

		public static function asset($file)
		{
			return '/assets/' . $file;
		}
	}
}

namespace {

define('ABSPATH', true);

/** @var array<string, mixed> */
$GLOBALS['pp_test_meta'] = [];
	function get_post_meta($post_id, $key, $single = false)
	{
		$k = $post_id . '|' . $key;
		if (!array_key_exists($k, $GLOBALS['pp_test_meta'])) {
			return $single ? '' : [];
		}

		return $GLOBALS['pp_test_meta'][$k];
	}

if (!function_exists('update_post_meta')) {
	function update_post_meta($post_id, $key, $value)
	{
		$GLOBALS['pp_test_meta'][$post_id . '|' . $key] = $value;

		return true;
	}
}

if (!function_exists('delete_post_meta')) {
	function delete_post_meta($post_id, $key)
	{
		unset($GLOBALS['pp_test_meta'][$post_id . '|' . $key]);

		return true;
	}
}

if (!function_exists('wp_json_encode')) {
	function wp_json_encode($data, $options = 0, $depth = 512)
	{
		return json_encode($data, $options, $depth);
	}
}

if (!function_exists('__')) {
	function __($text, $domain = 'default')
	{
		return $text;
	}
}

if (!function_exists('esc_attr')) {
	function esc_attr($text)
	{
		return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
	}
}

if (!function_exists('esc_url')) {
	function esc_url($text)
	{
		return (string) $text;
	}
}

if (!function_exists('esc_html')) {
	function esc_html($text)
	{
		return htmlspecialchars((string) $text, ENT_QUOTES, 'UTF-8');
	}
}

if (!function_exists('get_transient')) {
	function get_transient($key)
	{
		return false;
	}
}

if (!function_exists('set_transient')) {
	function set_transient($key, $value, $ttl)
	{
		return true;
	}
}

	function assert_true(string $name, bool $condition): void
	{
		if (!$condition) {
			fwrite(STDERR, "FAIL: {$name}\n");
			exit(1);
		}
		echo "OK: {$name}\n";
	}

	function assert_eq(string $name, $expected, $actual): void
	{
		if ($expected !== $actual) {
			fwrite(STDERR, "FAIL: {$name}\n");
			fwrite(STDERR, '  expected: ' . var_export($expected, true) . "\n");
			fwrite(STDERR, '  actual:   ' . var_export($actual, true) . "\n");
			exit(1);
		}
		echo "OK: {$name}\n";
	}

	require_once dirname(__DIR__) . '/src/ApiHelpers.php';
	require_once dirname(__DIR__) . '/src/PublishColumnCache.php';

	use parrotposter\ApiHelpers;
	use parrotposter\PublishColumnCache;

	$empty = PublishColumnCache::from_posts([]);
	assert_eq('empty has_posts', false, $empty['has_posts']);
	assert_eq('empty socials', [], $empty['socials']);

	$from = PublishColumnCache::from_posts([
		[
			'id' => 'old',
			'publish_at' => '2026-09-01T00:00:00Z',
			'status' => 'fail',
			'results' => [
				[
					'account_id' => 'u:vk:1',
					'social_type' => 'vk',
					'success' => true,
					'link' => 'https://vk.com/old',
					'published_at' => '2026-09-01T00:01:00Z',
					'error' => '',
				],
			],
		],
		[
			'id' => 'new',
			'publish_at' => '2026-09-06T12:00:00Z',
			'status' => 'success',
			'results' => [
				[
					'account_id' => 'u:tg:1',
					'social_type' => 'tg',
					'success' => true,
					'link' => 'https://t.me/x',
					'published_at' => '2026-09-06T12:01:00Z',
					'error' => '',
				],
				[
					'account_id' => 'u:vk:1',
					'social_type' => 'vk',
					'success' => false,
					'link' => '',
					'published_at' => '2026-09-06T12:02:00Z',
					'error' => 'Access denied',
				],
			],
		],
	]);
	assert_eq('has_posts', true, $from['has_posts']);
	assert_eq('social count', 2, count($from['socials']));
	assert_eq('social types ordered', ['tg', 'vk'], array_column($from['socials'], 'type'));
	assert_eq('tg link', 'https://t.me/x', $from['socials'][0]['link']);
	assert_eq('tg success', true, $from['socials'][0]['success']);
	assert_eq('vk keeps older link after fail', 'https://vk.com/old', $from['socials'][1]['link']);
	assert_eq('vk latest success is fail', false, $from['socials'][1]['success']);
	assert_eq('vk latest error', 'Access denied', $from['socials'][1]['error']);
	assert_true('no post status in payload', !array_key_exists('status', $from));

	$two_vk = PublishColumnCache::from_posts([
		[
			'id' => 'p',
			'publish_at' => '2026-09-06T12:00:00Z',
			'results' => [
				['account_id' => 'u:vk:a', 'social_type' => 'vk', 'success' => true, 'link' => 'https://vk.com/a'],
				['account_id' => 'u:vk:b', 'social_type' => 'vk', 'success' => null, 'link' => ''],
			],
		],
	]);
	assert_eq('two vk accounts', ['u:vk:a', 'u:vk:b'], array_column($two_vk['socials'], 'account_id'));
	assert_eq('second vk pending', null, $two_vk['socials'][1]['success']);

	$pending_only = PublishColumnCache::from_posts([
		['id' => 'prep', 'publish_at' => '2026-09-06T12:00:00Z', 'results' => []],
	]);
	assert_eq('empty results still has_posts', true, $pending_only['has_posts']);
	assert_eq('empty results socials', [], $pending_only['socials']);

	assert_eq('icon ig', 'insta', PublishColumnCache::icon_slug('ig'));
	assert_eq('icon vk', 'vk', PublishColumnCache::icon_slug('vk'));
	assert_eq('icon unknown', '', PublishColumnCache::icon_slug('zzz'));

	PublishColumnCache::set(42, $from);
	$got = PublishColumnCache::get(42);
	assert_true('get after set', is_array($got));
	assert_eq('get vk success', false, $got['socials'][1]['success']);
	assert_true('set stores array not json string', is_array(get_post_meta(42, PublishColumnCache::META_KEY, true)));

	PublishColumnCache::invalidate(42);
	assert_eq('get after invalidate', null, PublishColumnCache::get(42));

	$legacy = $from;
	unset($legacy['socials'][0]['success']);
	$legacy['cached_at'] = time();
	update_post_meta(8, PublishColumnCache::META_KEY, $legacy);
	assert_eq('cache without success key is null', null, PublishColumnCache::get(8));

	$stale = $from;
	$stale['cached_at'] = time() - PublishColumnCache::TTL_SEC - 10;
	update_post_meta(7, PublishColumnCache::META_KEY, wp_json_encode($stale));
	assert_eq('expired ttl is null', null, PublishColumnCache::get(7));

	assert_eq('account type vk', 'vk', ApiHelpers::get_account_social_type('u:vk:g'));
	assert_eq('account type ig alias', 'insta', ApiHelpers::get_account_social_type('u:ig:1'));
	assert_eq('account type empty', '', ApiHelpers::get_account_social_type(''));
	assert_eq(
		'network names skip empty and alias ig',
		'VKontakte, Instagram, Telegram',
		ApiHelpers::list_social_network_names(['', 'u:vk:1', 'u:ig:2', 'u:vk:3', 'u:tg:4'])
	);
	assert_eq(
		'socials from account ids unique',
		[
			['type' => 'vk', 'link' => ''],
			['type' => 'insta', 'link' => ''],
		],
		ApiHelpers::socials_from_account_ids(['u:vk:1', 'u:ig:2', 'u:vk:3'])
	);

	assert_eq('chip fail class', ' is-fail', PublishColumnCache::chip_status_class(['success' => false]));
	assert_eq('chip pending key', 'pending', PublishColumnCache::chip_status_key(['success' => null]));
	assert_eq('chip picker no status', '', PublishColumnCache::chip_status_class(['type' => 'vk']));

	$html_status = PublishColumnCache::render_cell(11, [
		'has_posts' => true,
		'socials' => [],
	], 'admin.php?page=x&post_id=11');
	assert_true('status cell modifier', strpos($html_status, 'parrotposter-col-cell--status') !== false);
	assert_true('status hit overlay', strpos($html_status, 'parrotposter-col-hit') !== false);
	assert_true('no status dot', strpos($html_status, 'parrotposter-col-dot') === false);
	assert_true('status chevron', strpos($html_status, 'parrotposter-col-more') !== false);
	assert_true('open publication title', strpos($html_status, 'Open publication') !== false);

	$html_fail = PublishColumnCache::render_cell(12, [
		'has_posts' => true,
		'socials' => [
			[
				'account_id' => 'u:vk:1',
				'type' => 'vk',
				'link' => 'https://vk.com/wall1',
				'success' => false,
				'error' => 'Access denied',
			],
		],
	], '/x');
	assert_true('fail chip class', strpos($html_fail, 'parrotposter-col-social-chip is-fail') !== false);
	assert_true('fail chip is button', strpos($html_fail, '<button type="button"') !== false);
	assert_true('fail chip not anchor', strpos($html_fail, 'parrotposter-col-social-link') === false);
	assert_true('fail keeps link data', strpos($html_fail, 'data-link="https://vk.com/wall1"') !== false);
	assert_true('fail error data', strpos($html_fail, 'data-error="Access denied"') !== false);

	$html_static = PublishColumnCache::render_social_icons([
		['type' => 'vk', 'link' => 'https://vk.com/wall1', 'success' => true],
	], false);
	assert_true('static is anchor', strpos($html_static, '<a class="parrotposter-col-social-chip parrotposter-col-social-chip--static is-ok"') !== false);
	assert_true('static not button', strpos($html_static, '<button') === false);

	$html_empty = PublishColumnCache::render_cell(13, [
		'has_posts' => false,
		'socials' => [],
	], '/x');
	assert_true('empty has share link', strpos($html_empty, 'class="parrotposter-publish"') !== false);
	assert_true('empty no status layout', strpos($html_empty, 'parrotposter-col-cell--status') === false);
	assert_true('empty no hit overlay', strpos($html_empty, 'parrotposter-col-hit') === false);

	echo "ALL OK\n";
}

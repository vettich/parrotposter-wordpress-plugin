#!/usr/bin/env php
<?php

/**
 * Smoke tests for CMS source lookup helpers: HMAC GraphQL node mapping and
 * `source_item_id` format. No live WPDB / HTTP.
 *
 * Usage: php bin/test-wp12-cms-source-lookup.php
 */

define('ABSPATH', true);

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

require_once dirname(__DIR__) . '/src/PushEventService.php';
require_once dirname(__DIR__) . '/src/Api.php';

use parrotposter\Api;
use parrotposter\PushEventService;

assert_eq('source_item_id format', 'post:42', PushEventService::source_item_id('post', 42));
assert_eq('source_item_id page', 'page:7', PushEventService::source_item_id('page', 7));

$mapped = Api::plugin_cms_source_posts_to_wp_list([
	[
		'id' => 'aaa-bbb',
		'publishAt' => '2026-09-05T12:00:00Z',
		'status' => 'ready',
		'legacyAutopostingId' => 3,
	],
	[
		'id' => 'ccc-ddd',
		'publishAt' => '2026-09-05T13:00:00Z',
		'status' => 'queue',
		'legacyAutopostingId' => null,
	],
	['id' => ''],
	'not-an-array',
]);

assert_eq('mapped count', 2, count($mapped));
assert_eq('mapped id', 'aaa-bbb', $mapped[0]['id']);
assert_eq('mapped publish_at', '2026-09-05T12:00:00Z', $mapped[0]['publish_at']);
assert_eq('mapped status', 'ready', $mapped[0]['status']);
assert_eq('mapped autoposting', 3, $mapped[0]['fields']['extra']['wp_autoposting_id']);
assert_eq('mapped results empty', [], $mapped[0]['results']);
assert_true('v2 extra empty without autoposting', empty($mapped[1]['fields']['extra']));
assert_eq('v2 status queue', 'queue', $mapped[1]['status']);

$mapped_results = Api::plugin_cms_source_posts_to_wp_list([
	[
		'id' => 'post-1',
		'publishAt' => '2026-09-06T10:00:00Z',
		'status' => 'success',
		'legacyAutopostingId' => null,
		'results' => [
			[
				'accountId' => 'u:vk:g',
				'socialType' => 'vk',
				'success' => true,
				'linkToSocialPost' => 'https://vk.com/wall1',
				'publishedAt' => '2026-09-06T10:01:00Z',
				'errorMessage' => null,
			],
			[
				'accountId' => 'u:tg:1',
				'socialType' => 'tg',
				'success' => false,
				'linkToSocialPost' => '',
				'publishedAt' => '2026-09-06T10:02:00Z',
				'errorMessage' => 'Flood wait',
			],
			'bad',
		],
	],
]);
assert_eq('results count', 2, count($mapped_results[0]['results']));
assert_eq('results social', 'vk', $mapped_results[0]['results'][0]['social_type']);
assert_eq('results link', 'https://vk.com/wall1', $mapped_results[0]['results'][0]['link']);
assert_eq('results success', true, $mapped_results[0]['results'][0]['success']);
assert_eq('results error empty', '', $mapped_results[0]['results'][0]['error']);
assert_eq('results fail error', 'Flood wait', $mapped_results[0]['results'][1]['error']);
assert_eq('results fail success', false, $mapped_results[0]['results'][1]['success']);

$pipelines = Api::plugin_pipelines_for_publish_to_wp_list([
	['id' => 'p1', 'name' => 'News', 'accountIds' => ['u:vk:1', 'u:tg:2']],
	['id' => ''],
	'nope',
]);
assert_eq('pipelines count', 1, count($pipelines));
assert_eq('pipeline name', 'News', $pipelines[0]['name']);
assert_eq('pipeline accounts', ['u:vk:1', 'u:tg:2'], $pipelines[0]['account_ids']);

assert_eq(
	'source_path_for_post_type',
	[['key' => 'post_type', 'label' => '', 'value' => 'page']],
	PushEventService::source_path_for_post_type('page')
);

assert_eq('source_item_id numeric tail', '42', substr(PushEventService::source_item_id('post', 42), strlen('post:')));

echo "ALL OK\n";

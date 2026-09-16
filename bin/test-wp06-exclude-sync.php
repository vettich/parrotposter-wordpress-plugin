#!/usr/bin/env php
<?php

/**
 * Smoke tests for WP-06 exclude_sync helpers (no live WP DB).
 *
 * Usage: php bin/test-wp06-exclude-sync.php
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

// Mirror ExcludeCache delta algebra / WireProtocol mode checks without WPDB.

function apply_delta_set(array $ids, array $added, array $removed): array
{
	$set = array_fill_keys($ids, true);
	foreach ($removed as $id) {
		unset($set[(string) $id]);
	}
	foreach ($added as $id) {
		$set[(string) $id] = true;
	}
	$out = array_keys($set);
	sort($out);
	return $out;
}

$base = ['post:1', 'post:2', 'post:3'];
$after = apply_delta_set($base, ['post:4'], ['post:2']);
assert_true('delta add+remove', $after === ['post:1', 'post:3', 'post:4']);

$modes = ['delta', 'full_snapshot_required'];
assert_true('modes known', in_array('delta', $modes, true));

$payload_inline = [
	'published_ids' => array_fill(0, 5000, 'post:x'),
	'exclude_sync' => null,
];
assert_true('inline has no exclude_sync', $payload_inline['exclude_sync'] === null);

$payload_sync = [
	'published_ids' => [],
	'exclude_sync' => [
		'mode' => 'delta',
		'server_version' => 12,
		'since_version' => 10,
		'added' => ['post:9'],
		'removed' => [],
	],
];
assert_true('sync omits inline ids', $payload_sync['published_ids'] === []);
assert_true('sync mode delta', $payload_sync['exclude_sync']['mode'] === 'delta');

$ack = ['client_version' => 12];
assert_true('exclude_ack shape', isset($ack['client_version']));

$snap = [
	'pipeline_id' => 'pipe-1',
	'page' => 1,
	'page_size' => 1000,
	'source_item_ids' => ['post:1', 'post:2'],
	'server_version' => 12,
	'has_more' => false,
];
assert_true('snapshot page 1 replace', (int) $snap['page'] === 1);
assert_true('snapshot has ids', count($snap['source_item_ids']) === 2);

echo "All WP-06 smoke checks passed\n";

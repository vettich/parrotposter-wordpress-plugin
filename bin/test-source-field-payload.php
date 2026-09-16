<?php

declare(strict_types=1);

/**
 * Smoke test for PushEventService SourceField GraphQL encoding (INIT-002 W4).
 *
 * Usage: php bin/test-source-field-payload.php
 */

defined('ABSPATH') || define('ABSPATH', __DIR__ . '/../');

require_once __DIR__ . '/../src/PushEventService.php';

use parrotposter\PushEventService;

function assert_eq(string $label, $expected, $actual): void
{
	if ($expected !== $actual) {
		fwrite(STDERR, "FAIL: {$label}\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n");
		exit(1);
	}
	echo "OK: {$label}\n";
}

$payload = [
	'title' => 'Hello',
	'count' => 3,
	'active' => true,
	'tags' => ['a', 'b'],
	'meta' => ['k' => 'v'],
	'empty' => null,
];

$fields = PushEventService::payload_to_source_fields($payload);
assert_eq('encoded field count', 6, count($fields));

$round_trip = PushEventService::source_fields_to_payload($fields);
assert_eq('round-trip title', 'Hello', $round_trip['title'] ?? null);
assert_eq('round-trip count', 3.0, $round_trip['count'] ?? null);
assert_eq('round-trip tags', ['a', 'b'], $round_trip['tags'] ?? null);
assert_eq('round-trip meta', ['k' => 'v'], $round_trip['meta'] ?? null);
assert_eq('round-trip empty is null', true, array_key_exists('empty', $round_trip) && $round_trip['empty'] === null);

$title_field = null;
foreach ($fields as $field) {
	if (($field['key'] ?? '') === 'title') {
		$title_field = $field;
		break;
	}
}
assert_eq('title uses string variant', ['string' => 'Hello'], $title_field['value'] ?? null);

echo "\nAll SourceField payload smoke tests passed.\n";

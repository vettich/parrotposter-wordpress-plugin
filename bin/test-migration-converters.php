#!/usr/bin/env php
<?php

/**
 * Standalone tests for WP → pipeline-native GraphQL converters (no WordPress bootstrap).
 *
 * Usage: php bin/test-migration-converters.php
 */

defined('ABSPATH') || define('ABSPATH', true);
if (!defined('PARROTPOSTER_PLUGIN_DIR')) {
	define('PARROTPOSTER_PLUGIN_DIR', dirname(__DIR__) . '/');
}

require_once PARROTPOSTER_PLUGIN_DIR . 'src/autoloader.php';

use parrotposter\Migration\ConditionsConverter;
use parrotposter\Migration\MacroConverter;
use parrotposter\Migration\MediaFieldConverter;
use parrotposter\Migration\PayloadBuilder;
use parrotposter\Migration\TemplateClusterer;
use parrotposter\Migration\TriggerDelayConverter;
use parrotposter\Migration\UtmVkConverter;

function assert_true(string $name, $cond): void
{
	if (!$cond) {
		fwrite(STDERR, "FAIL: {$name}\n");
		exit(1);
	}
	echo "OK: {$name}\n";
}

function assert_eq(string $name, $expected, $actual): void
{
	if ($expected !== $actual) {
		fwrite(STDERR, "FAIL: {$name}\n");
		fwrite(STDERR, '  expected: [' . var_export($expected, true) . "]\n");
		fwrite(STDERR, '  actual:   [' . var_export($actual, true) . "]\n");
		exit(1);
	}
	echo "OK: {$name}\n";
}

$out = MacroConverter::convert("{{ title }}\n{{ url }}");
assert_true('macros double-brace title', strpos($out, '{{ item.title }}') !== false);
assert_true('macros double-brace link', strpos($out, '{{ item.link }}') !== false);

$out = MacroConverter::convert("{title}{br}\n{excerpt}");
assert_true('macros single-brace title', strpos($out, '{{ item.title }}') !== false);
assert_true('macros br becomes newline', strpos($out, "\n") !== false);
assert_true('macros excerpt', strpos($out, '{{ item.excerpt }}') !== false);
assert_true('macros leftover {title} gone', strpos($out, '{title}') === false);

assert_eq('macros social_code', MacroConverter::SOCIAL_CODE_PLACEHOLDER, MacroConverter::convert('{social_code}'));

$and = ConditionsConverter::convert([
	['key' => 'title', 'op' => 'include', 'value' => 'sale'],
	['key' => 'author', 'op' => 'equal', 'value' => ['1', '2']],
], 'News');
assert_eq('conditions AND warnings empty', [], $and['warnings']);
assert_eq('conditions AND kind', 'AND', $and['item']['kind']);
assert_eq('conditions AND children count', 2, count($and['item']['children']));
assert_eq('conditions include → CONTAINS', 'CONTAINS', $and['item']['children'][0]['compare']['op']);
assert_eq('conditions equal array → INCLUDES_ANY', 'INCLUDES_ANY', $and['item']['children'][1]['compare']['op']);

$eq = ConditionsConverter::convert([
	['key' => 'product_regular_price', 'op' => 'equal', 'value' => 100],
], 'Product');
assert_eq('conditions scalar equal op', 'EQ', $eq['item']['compare']['op']);
assert_eq('conditions scalar equal number', 100.0, $eq['item']['compare']['numberValue']);

$unknown = ConditionsConverter::convert([
	['key' => 'title', 'op' => 'include', 'value' => 'x'],
	['key' => 'foo', 'op' => 'unknown_op', 'value' => 'y'],
], 'Bad');
assert_true('unknown op keeps other leaf', $unknown['item'] !== null);
assert_eq('unknown op warning count', 1, count($unknown['warnings']));
assert_true('unknown op warning mentions foo', strpos($unknown['warnings'][0], 'foo') !== false);

$delay = TriggerDelayConverter::convert('delay', 5);
assert_eq('delay minutes → seconds', 300, $delay['debounceSeconds']);
$immediate = TriggerDelayConverter::convert('immediately', 5);
assert_eq('immediate debounce 0', 0, $immediate['debounceSeconds']);

$media = MediaFieldConverter::convert(['{content_images}', 'featured_image']);
assert_eq('media alias field', 'images_in_content', $media['sources'][0]['field']);
assert_eq('media alias mode MANY', 'MANY', $media['sources'][0]['mode']);
assert_eq('media featured ONE', 'ONE', $media['sources'][1]['mode']);
assert_eq('media alias warning count', 1, count($media['warnings']));

$utm = UtmVkConverter::convert(
	['utm_enable' => true, 'utm_source' => '{social_code}'],
	['u:vk:1', 'u:tg:2'],
	'{{ item.link }}'
);
assert_eq('utm needUtm', true, $utm['needUtm']);
assert_eq('utm default source empty placeholder', '', $utm['utmParams']['source']);
$override_types = [];
foreach ($utm['socialTypeOverrides'] as $entry) {
	$override_types[] = $entry['socialType'];
}
sort($override_types);
assert_eq('utm social overrides', ['TG', 'VK'], $override_types);

$payload = PayloadBuilder::from_row([
	'id' => '12',
	'name' => 'News',
	'wp_post_type' => 'post',
	'account_ids' => ['u:vk:1'],
	'post_text' => '{title}{br}{excerpt}',
	'post_link' => '{link}',
	'post_images' => ['featured_image'],
	'post_tags' => '{post_tag}',
	'conditions' => [
		['key' => 'title', 'op' => 'include', 'value' => 'news'],
	],
	'utm_enable' => 1,
	'utm_source' => 'vk',
	'when_publish' => 'delay',
	'publish_delay' => 5,
	'extra_vk_from_group' => 1,
	'extra_vk_signed' => 0,
]);
assert_eq('payload legacyId', '12', $payload['legacyId']);
assert_eq('payload postType', 'post', $payload['postType']);
assert_true('payload body liquid', strpos($payload['template']['default']['text']['bodyTemplate'], '{{ item.title }}') !== false);
assert_true('payload tags appended', strpos($payload['template']['default']['text']['bodyTemplate'], '{{ item.post_tag | hashtags: 20 }}') !== false);
assert_eq('payload compare op', 'CONTAINS', $payload['contentRules']['item']['compare']['op']);
assert_eq('payload debounce', 300, $payload['trigger']['debounceSeconds']);
assert_eq('payload vk fromGroup', true, $payload['template']['default']['platformData']['vk']['fromGroup']);
assert_eq('payload format PLAIN', 'PLAIN', $payload['template']['default']['text']['format']);

/**
 * @param array<string, mixed> $over
 * @return array<string, mixed>
 */
function cluster_row(array $over)
{
	return array_merge([
		'id' => '1',
		'name' => 'T',
		'wp_post_type' => 'post',
		'account_ids' => ['u:vk:1'],
		'post_text' => '{title}',
		'post_link' => '{link}',
		'post_images' => ['featured_image'],
		'post_tags' => '',
		'conditions' => [],
		'utm_enable' => 0,
		'when_publish' => 'immediately',
		'publish_delay' => 0,
		'extra_vk_from_group' => 1,
		'extra_vk_signed' => 0,
	], $over);
}

/**
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function cluster_payloads(array $rows)
{
	$out = [];
	foreach (TemplateClusterer::cluster($rows) as $cluster) {
		$out[] = PayloadBuilder::from_cluster($cluster);
	}

	return $out;
}

/**
 * @param array<string, mixed> $payload
 * @param string               $type
 * @return array<string, mixed>|null
 */
function social_override_block(array $payload, $type)
{
	if (!isset($payload['template']['socialTypeOverrides']) || !is_array($payload['template']['socialTypeOverrides'])) {
		return null;
	}
	foreach ($payload['template']['socialTypeOverrides'] as $entry) {
		if (is_array($entry) && isset($entry['socialType']) && $entry['socialType'] === $type) {
			return isset($entry['block']) && is_array($entry['block']) ? $entry['block'] : [];
		}
	}

	return null;
}

$same_except_accounts = cluster_payloads([
	cluster_row(['id' => '1', 'name' => 'VK News', 'account_ids' => ['u:vk:1']]),
	cluster_row(['id' => '2', 'name' => 'TG News', 'account_ids' => ['u:tg:2']]),
]);
assert_eq('stage1 cluster count', 1, count($same_except_accounts));
$p = $same_except_accounts[0];
$ids = $p['accountIds'];
sort($ids);
assert_eq('stage1 union accounts', ['u:tg:2', 'u:vk:1'], $ids);
assert_eq('stage1 name', 'VK News · TG News', $p['name']);
assert_eq('stage1 legacyId', '1+2', $p['legacyId']);
$has_text_override = false;
if (isset($p['template']['socialTypeOverrides'])) {
	foreach ($p['template']['socialTypeOverrides'] as $entry) {
		if (isset($entry['block']['text'])) {
			$has_text_override = true;
		}
	}
}
assert_eq('stage1 no text overrides', false, $has_text_override);
assert_true('stage1 merged warning', in_array('Merged 2 autoposting templates into one pipeline', $p['warnings'], true));

$vk_vs_tg_text = cluster_payloads([
	cluster_row(['id' => '1', 'name' => 'VK', 'account_ids' => ['u:vk:1'], 'post_text' => '{title}']),
	cluster_row([
		'id' => '2',
		'name' => 'TG',
		'account_ids' => ['u:tg:2'],
		'post_text' => "{title}{br}{excerpt}",
	]),
]);
assert_eq('stage2 cluster count', 1, count($vk_vs_tg_text));
$p = $vk_vs_tg_text[0];
assert_true('stage2 default is VK body', strpos($p['template']['default']['text']['bodyTemplate'], '{{ item.excerpt }}') === false);
$tg_block = social_override_block($p, 'TG');
assert_true('stage2 TG override present', $tg_block !== null);
assert_true('stage2 TG override text', isset($tg_block['text']['bodyTemplate']) && strpos($tg_block['text']['bodyTemplate'], '{{ item.excerpt }}') !== false);
$vk_block = social_override_block($p, 'VK');
assert_true('stage2 no VK text override', $vk_block === null || !isset($vk_block['text']));

$both_vk = cluster_payloads([
	cluster_row(['id' => '1', 'name' => 'VK A', 'account_ids' => ['u:vk:1'], 'post_text' => '{title}']),
	cluster_row(['id' => '2', 'name' => 'VK B', 'account_ids' => ['u:vk:2'], 'post_text' => '{excerpt}']),
]);
assert_eq('same network different text stays split', 2, count($both_vk));

$diff_cond = cluster_payloads([
	cluster_row(['id' => '1', 'conditions' => [['key' => 'title', 'op' => 'include', 'value' => 'a']]]),
	cluster_row([
		'id' => '2',
		'account_ids' => ['u:tg:2'],
		'conditions' => [['key' => 'title', 'op' => 'include', 'value' => 'b']],
	]),
]);
assert_eq('different conditions stay split', 2, count($diff_cond));

$blank_sparse = ConditionsConverter::convert(
	[1 => ['key' => '', 'value' => '']],
	'Blank'
);
assert_eq('sparse blank conditions convert to empty', null, $blank_sparse['item']);
assert_eq('sparse blank conditions no warning', [], $blank_sparse['warnings']);

$sparse_real = ConditionsConverter::convert(
	[1 => ['key' => 'title', 'op' => 'include', 'value' => 'news']],
	'Sparse'
);
assert_eq('sparse real condition op', 'CONTAINS', $sparse_real['item']['compare']['op']);
assert_eq('sparse real condition field', 'title', $sparse_real['item']['compare']['field']);

$blank_shape = cluster_payloads([
	cluster_row([
		'id' => '1',
		'name' => 'A',
		'account_ids' => ['u:vk:1', 'u:test:1'],
		'conditions' => [1 => ['key' => '', 'value' => '']],
	]),
	cluster_row([
		'id' => '2',
		'name' => 'B',
		'account_ids' => ['u:tg:2', 'u:ok:3'],
		'conditions' => [['key' => '', 'value' => '']],
	]),
]);
assert_eq('blank conditions object vs list merge', 1, count($blank_shape));
assert_eq('blank conditions union accounts', 4, count($blank_shape[0]['accountIds']));

$empty_vs_blank = cluster_payloads([
	cluster_row(['id' => '1', 'conditions' => []]),
	cluster_row([
		'id' => '2',
		'account_ids' => ['u:tg:2'],
		'conditions' => [['key' => '', 'op' => '', 'value' => '']],
	]),
]);
assert_eq('empty vs blank placeholder merge', 1, count($empty_vs_blank));

$diff_delay = cluster_payloads([
	cluster_row(['id' => '1', 'when_publish' => 'delay', 'publish_delay' => 5]),
	cluster_row(['id' => '2', 'account_ids' => ['u:tg:2'], 'when_publish' => 'immediately', 'publish_delay' => 0]),
]);
assert_eq('different delay stay split', 2, count($diff_delay));

$diff_images = cluster_payloads([
	cluster_row(['id' => '1', 'post_images' => ['featured_image']]),
	cluster_row(['id' => '2', 'account_ids' => ['u:tg:2'], 'post_images' => ['images_in_content']]),
]);
assert_eq('different images stay split', 2, count($diff_images));

$utm_union = cluster_payloads([
	cluster_row([
		'id' => '1',
		'name' => 'VK',
		'account_ids' => ['u:vk:1'],
		'utm_enable' => 1,
		'utm_source' => '{social_code}',
	]),
	cluster_row([
		'id' => '2',
		'name' => 'TG',
		'account_ids' => ['u:tg:2'],
		'utm_enable' => 1,
		'utm_source' => '{social_code}',
	]),
]);
assert_eq('utm cluster count', 1, count($utm_union));
$utm_types = [];
foreach ($utm_union[0]['template']['socialTypeOverrides'] as $entry) {
	$utm_types[] = $entry['socialType'];
}
sort($utm_types);
assert_eq('utm overrides both networks', ['TG', 'VK'], $utm_types);

$vk_extras = cluster_payloads([
	cluster_row([
		'id' => '1',
		'name' => 'TG',
		'account_ids' => ['u:tg:1', 'u:tg:2'],
		'extra_vk_from_group' => 1,
		'extra_vk_signed' => 0,
	]),
	cluster_row([
		'id' => '2',
		'name' => 'VK',
		'account_ids' => ['u:vk:9'],
		'extra_vk_from_group' => 1,
		'extra_vk_signed' => 1,
	]),
]);
assert_eq('vk extras cluster count', 1, count($vk_extras));
assert_eq(
	'vk extras taken from VK member',
	true,
	$vk_extras[0]['template']['default']['platformData']['vk']['signed']
);

$three = cluster_payloads([
	cluster_row(['id' => '1', 'name' => 'VK', 'account_ids' => ['u:vk:1']]),
	cluster_row(['id' => '2', 'name' => 'TG', 'account_ids' => ['u:tg:2']]),
	cluster_row([
		'id' => '3',
		'name' => 'Promo',
		'account_ids' => ['u:ok:3'],
		'conditions' => [['key' => 'category', 'op' => 'equal', 'value' => '9']],
	]),
]);
assert_eq('three rows → two pipelines', 2, count($three));
$merged = $three[0]['legacyId'] === '1+2' ? $three[0] : $three[1];
$solo = $three[0]['legacyId'] === '3' ? $three[0] : $three[1];
assert_eq('merged pair legacyId', '1+2', $merged['legacyId']);
assert_eq('solo leftover', '3', $solo['legacyId']);

echo "\nAll tests passed.\n";

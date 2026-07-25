#!/usr/bin/env php
<?php

/**
 * Smoke tests: taxonomy fields (e.g. `category`) must be included in
 * PushEventService::build_item_payload() output, matching what the schema
 * (WireProtocol::taxonomy_keys_for_post_type / fields\Taxonomies) already
 * declares as `filterable`.
 *
 * Usage: php bin/test-wp-taxonomy-payload.php
 */

define('ABSPATH', true);

if (!class_exists('WP_Post')) {
	class WP_Post
	{
		public $ID;
		public $post_title = '';
		public $post_excerpt = '';
		public $post_content = '';
		public $post_date = '';
		public $post_type = 'post';
		public $post_status = 'publish';

		public function __construct(array $fields = [])
		{
			foreach ($fields as $key => $value) {
				$this->$key = $value;
			}
		}
	}
}

if (!class_exists('WP_Taxonomy')) {
	class WP_Taxonomy
	{
		public $name;
		public $label;
		public $show_ui;

		public function __construct(string $name, bool $show_ui, string $label = '')
		{
			$this->name = $name;
			$this->show_ui = $show_ui;
			$this->label = $label !== '' ? $label : $name;
		}
	}
}

if (!class_exists('WP_Error')) {
	class WP_Error
	{
		public $code;
		public $message;

		public function __construct($code = '', $message = '')
		{
			$this->code = (string) $code;
			$this->message = (string) $message;
		}
	}
}

if (!class_exists('WP_Query')) {
	class WP_Query
	{
		public $posts = [];

		public function __construct($args = [])
		{
		}
	}
}

/** @var array<string, array<string, WP_Taxonomy>> */
$GLOBALS['pp_test_taxonomies'] = [
	'post' => [
		'category' => new WP_Taxonomy('category', true),
		'post_tag' => new WP_Taxonomy('post_tag', true),
		'hidden_tax' => new WP_Taxonomy('hidden_tax', false),
	],
	'product' => [
		'product_cat' => new WP_Taxonomy('product_cat', true),
	],
];

/** @var array<int, array<string, list<int>>> */
$GLOBALS['pp_test_post_terms'] = [];

if (!function_exists('is_wp_error')) {
	function is_wp_error($thing)
	{
		return $thing instanceof WP_Error;
	}
}

if (!function_exists('taxonomy_exists')) {
	function taxonomy_exists($taxonomy)
	{
		foreach ($GLOBALS['pp_test_taxonomies'] as $taxes) {
			if (array_key_exists($taxonomy, $taxes)) {
				return true;
			}
		}

		return false;
	}
}

if (!function_exists('get_object_taxonomies')) {
	function get_object_taxonomies($post_type, $output = 'names')
	{
		$taxes = $GLOBALS['pp_test_taxonomies'][$post_type] ?? [];

		return $output === 'objects' ? array_values($taxes) : array_keys($taxes);
	}
}

if (!function_exists('wp_get_post_terms')) {
	function wp_get_post_terms($post_id, $taxonomy, $args = [])
	{
		return $GLOBALS['pp_test_post_terms'][$post_id][$taxonomy] ?? [];
	}
}

if (!function_exists('get_the_title')) {
	function get_the_title($post)
	{
		return $post->post_title;
	}
}

if (!function_exists('get_permalink')) {
	function get_permalink($post)
	{
		return 'https://wp.loc/?p=' . $post->ID;
	}
}

if (!function_exists('get_post_thumbnail_id')) {
	function get_post_thumbnail_id($post)
	{
		return 0;
	}
}

if (!function_exists('get_attached_media')) {
	function get_attached_media($mime_prefix, $post_id)
	{
		return [];
	}
}

if (!function_exists('get_post_mime_type')) {
	function get_post_mime_type($id)
	{
		return '';
	}
}

if (!function_exists('_x')) {
	function _x($text, $context, $domain = 'default')
	{
		return $text;
	}
}

require_once dirname(__DIR__) . '/src/Tools.php';
require_once dirname(__DIR__) . '/src/WpPostHelpers.php';
require_once dirname(__DIR__) . '/src/fields/CommonType.php';
require_once dirname(__DIR__) . '/src/fields/Taxonomies.php';
require_once dirname(__DIR__) . '/src/fields/ProductType.php';
require_once dirname(__DIR__) . '/src/fields/Fields.php';
require_once dirname(__DIR__) . '/src/PushEventService.php';

use parrotposter\PushEventService;

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

// Case A: post_type='post', category (show_ui=true) with terms [3, 7].
$GLOBALS['pp_test_post_terms'][101] = ['category' => [3, 7]];
$post_a = new WP_Post(['ID' => 101, 'post_title' => 'Hello', 'post_type' => 'post']);
$payload_a = PushEventService::build_item_payload($post_a);
assert_true('category present in payload for post_type=post', array_key_exists('category', $payload_a));
assert_eq('category term ids match wp_get_post_terms result', ['3', '7'], $payload_a['category']);

// Case B: taxonomy with show_ui=false is excluded.
assert_true('hidden_tax (show_ui=false) absent from payload', !array_key_exists('hidden_tax', $payload_a));

// Case C: post_type='product' — product_cat (taxonomy) and product_regular_price
// (hardcoded WooCommerce field) both present, no collisions.
$GLOBALS['pp_test_post_terms'][202] = ['product_cat' => [12]];
$post_c = new WP_Post(['ID' => 202, 'post_title' => 'Widget', 'post_type' => 'product']);
$payload_c = PushEventService::build_item_payload($post_c);
assert_true('product_cat present in payload for post_type=product', array_key_exists('product_cat', $payload_c));
assert_eq('product_cat term ids match wp_get_post_terms result', ['12'], $payload_c['product_cat']);
assert_true(
	'product_regular_price (hardcoded WooCommerce field) still present',
	array_key_exists('product_regular_price', $payload_c)
);

// Case D: required_fields explicitly includes an already-added taxonomy — no
// duplication/crash from the array_key_exists guard in the required_fields loop.
$payload_d = PushEventService::build_item_payload($post_a, ['category']);
assert_eq('required_fields does not override/duplicate taxonomy already in payload', ['3', '7'], $payload_d['category']);

echo "\nAll taxonomy payload smoke tests passed.\n";

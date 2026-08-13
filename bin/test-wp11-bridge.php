#!/usr/bin/env php
<?php

/**
 * Smoke tests for WP-11: postMessage bridge helpers on WireProtocol —
 * `post_type_options()`, `post_type_from_source_path()`, `field_schema_for_post_type()`.
 *
 * These back the new `wp_ajax_parrotposter_bridge_*` handlers in AdminAjaxPost.php,
 * which call them directly (no HTTP loopback), same as REST `/info` and `/fields`.
 *
 * Usage: php bin/test-wp11-bridge.php
 */

define('ABSPATH', true);

if (!class_exists('WP_Error')) {
	class WP_Error
	{
		/** @var string */
		public $code;
		/** @var string */
		public $message;
		/** @var array<string, mixed> */
		public $data;

		public function __construct($code = '', $message = '', $data = [])
		{
			$this->code = (string) $code;
			$this->message = (string) $message;
			$this->data = is_array($data) ? $data : [];
		}

		public function get_error_code()
		{
			return $this->code;
		}

		public function get_error_message()
		{
			return $this->message;
		}

		public function get_error_data()
		{
			return $this->data;
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

if (!function_exists('is_wp_error')) {
	function is_wp_error($thing)
	{
		return $thing instanceof WP_Error;
	}
}

if (!function_exists('_x')) {
	function _x($text, $context, $domain = 'default')
	{
		return $text;
	}
}

if (!function_exists('__')) {
	function __($text, $domain = 'default')
	{
		return $text;
	}
}

if (!defined('PARROTPOSTER_PLUGIN_FILE')) {
	define('PARROTPOSTER_PLUGIN_FILE', dirname(__DIR__) . '/parrotposter.php');
}
if (!defined('PARROTPOSTER_PLUGIN_DIR')) {
	define('PARROTPOSTER_PLUGIN_DIR', dirname(__DIR__) . '/');
}

$GLOBALS['pp_test_loaded_textdomains'] = [];
$GLOBALS['pp_test_switch_to_locale_calls'] = [];
$GLOBALS['pp_test_switch_to_locale_result'] = false;

if (!function_exists('determine_locale')) {
	function determine_locale()
	{
		return 'en_US';
	}
}

if (!function_exists('switch_to_locale')) {
	function switch_to_locale($locale)
	{
		$GLOBALS['pp_test_switch_to_locale_calls'][] = $locale;
		return (bool) $GLOBALS['pp_test_switch_to_locale_result'];
	}
}

if (!function_exists('restore_previous_locale')) {
	function restore_previous_locale()
	{
		return true;
	}
}

if (!function_exists('unload_textdomain')) {
	function unload_textdomain($domain)
	{
		return true;
	}
}

if (!function_exists('load_textdomain')) {
	function load_textdomain($domain, $mofile)
	{
		$GLOBALS['pp_test_loaded_textdomains'][] = [
			'domain' => $domain,
			'mofile' => $mofile,
		];
		return is_readable($mofile);
	}
}

if (!function_exists('load_plugin_textdomain')) {
	function load_plugin_textdomain($domain, $deprecated = false, $plugin_rel_path = false)
	{
		return true;
	}
}

if (!function_exists('plugin_basename')) {
	function plugin_basename($file)
	{
		return 'parrotposter/parrotposter.php';
	}
}

if (!function_exists('sanitize_key')) {
	function sanitize_key($key)
	{
		return strtolower(preg_replace('/[^a-z0-9_\-]/', '', (string) $key));
	}
}

/** @var array<string, array<string, WP_Taxonomy>> */
$GLOBALS['pp_test_taxonomies'] = [
	'post' => [
		'category' => new WP_Taxonomy('category', true, 'Categories'),
		'post_tag' => new WP_Taxonomy('post_tag', true, 'Tags'),
		'hidden_tax' => new WP_Taxonomy('hidden_tax', false),
	],
	'page' => [],
];

if (!function_exists('get_object_taxonomies')) {
	function get_object_taxonomies($post_type, $output = 'names')
	{
		$taxes = $GLOBALS['pp_test_taxonomies'][$post_type] ?? [];

		return $output === 'objects' ? array_values($taxes) : array_keys($taxes);
	}
}

if (!function_exists('get_taxonomy')) {
	function get_taxonomy($taxonomy)
	{
		foreach ($GLOBALS['pp_test_taxonomies'] as $taxes) {
			if (array_key_exists($taxonomy, $taxes)) {
				return $taxes[$taxonomy];
			}
		}

		return false;
	}
}

if (!function_exists('get_terms')) {
	function get_terms($args = [])
	{
		// No terms needed for these smoke tests — taxonomy_value_options() just
		// needs an array back, not a WP_Error.
		return [];
	}
}

/** @var array<string, array<string, mixed>> */
$GLOBALS['pp_test_post_type_objects'] = [
	'post' => (object) ['labels' => (object) ['name' => 'Posts']],
	'page' => (object) ['labels' => (object) ['name' => 'Pages']],
];

if (!function_exists('get_post_types')) {
	function get_post_types($args = [], $output = 'names')
	{
		if ($output === 'names') {
			return array_keys($GLOBALS['pp_test_post_type_objects']);
		}

		return $GLOBALS['pp_test_post_type_objects'];
	}
}

require_once dirname(__DIR__) . '/src/WpPostHelpers.php';
require_once dirname(__DIR__) . '/src/SelectionFilterQuery.php';
require_once dirname(__DIR__) . '/src/fields/CommonType.php';
require_once dirname(__DIR__) . '/src/fields/Taxonomies.php';
require_once dirname(__DIR__) . '/src/fields/ProductType.php';
require_once dirname(__DIR__) . '/src/fields/Fields.php';
require_once dirname(__DIR__) . '/src/fields/conditions/Taxonomies.php';
require_once dirname(__DIR__) . '/src/WireProtocol.php';

use parrotposter\WireProtocol;

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

// --- post_type_options(): same shape/source as /info.post_types (bridge source-descriptor-root) ---

$post_type_options = WireProtocol::post_type_options();
assert_eq('post_type_options() count', 2, count($post_type_options));
assert_eq('post_type_options()[0] value', 'post', $post_type_options[0]['value']);
assert_eq('post_type_options()[0] label', 'Posts', $post_type_options[0]['label']);
assert_eq('post_type_options()[1] value', 'page', $post_type_options[1]['value']);

// --- post_type_from_source_path(): used by bridge_field_schema to resolve post_type ---

assert_eq(
	'post_type_from_source_path() reads key=post_type',
	'post',
	WireProtocol::post_type_from_source_path([['key' => 'post_type', 'value' => 'post']])
);
assert_eq(
	'post_type_from_source_path() accepts legacy key=wp_post_type',
	'page',
	WireProtocol::post_type_from_source_path([['key' => 'wp_post_type', 'value' => 'page']])
);
assert_eq(
	'post_type_from_source_path() empty when no matching step',
	'',
	WireProtocol::post_type_from_source_path([['key' => 'other', 'value' => 'x']])
);

// --- field_schema_for_post_type(): same builder as REST /fields (bridge field_schema) ---

$schema = WireProtocol::field_schema_for_post_type('post');
assert_true('field_schema_for_post_type("post") is not a WP_Error', !is_wp_error($schema));
assert_true('field_schema_for_post_type("post") has fields', is_array($schema['fields']) && count($schema['fields']) > 0);

$fields_by_key = [];
foreach ($schema['fields'] as $field) {
	$fields_by_key[$field['key']] = $field;
}
assert_true('title field present', isset($fields_by_key['title']));
assert_true('content field present', isset($fields_by_key['content']));
assert_true('featured_image field present (Instant MVP)', isset($fields_by_key['featured_image']));
assert_true('category taxonomy field present (show_ui=true)', isset($fields_by_key['category']));
assert_true('hidden_tax (show_ui=false) absent', !isset($fields_by_key['hidden_tax']));
assert_eq('category field semantic', 'taxonomy', $fields_by_key['category']['semantic']);

$section_ids = array_column($schema['sections'], 'id');
assert_true('basic section present', in_array('basic', $section_ids, true));
assert_true('media section present', in_array('media', $section_ids, true));
assert_true('taxonomies section present (post has taxonomies)', in_array('taxonomies', $section_ids, true));
assert_true('woocommerce section absent for post_type=post', !in_array('woocommerce', $section_ids, true));

assert_true('filter_capabilities present', isset($schema['filter_capabilities']['compare_ops']));

// --- normalize_ui_locale(): PP UI codes → WP locales ---
assert_eq('normalize_ui_locale(ru)', 'ru_RU', WireProtocol::normalize_ui_locale('ru'));
assert_eq('normalize_ui_locale(en)', 'en_US', WireProtocol::normalize_ui_locale('en'));
assert_eq('normalize_ui_locale(ru_RU)', 'ru_RU', WireProtocol::normalize_ui_locale('ru_RU'));
assert_eq('normalize_ui_locale(ru-RU)', 'ru_RU', WireProtocol::normalize_ui_locale('ru-RU'));
assert_true('normalize_ui_locale(empty) is null', WireProtocol::normalize_ui_locale('') === null);
assert_true('normalize_ui_locale(bogus) is null', WireProtocol::normalize_ui_locale('!!!') === null);

// locale=ru must load plugin .mo even when switch_to_locale fails (no WP core lang pack).
$GLOBALS['pp_test_loaded_textdomains'] = [];
$GLOBALS['pp_test_switch_to_locale_calls'] = [];
$GLOBALS['pp_test_switch_to_locale_result'] = false;
$schema_ru = WireProtocol::field_schema_for_post_type('post', 'ru');
assert_true('field_schema_for_post_type(post, ru) is not a WP_Error', !is_wp_error($schema_ru));
assert_true('switch_to_locale attempted for ru_RU', in_array('ru_RU', $GLOBALS['pp_test_switch_to_locale_calls'], true));
$mo_loads = array_values(array_filter(
	$GLOBALS['pp_test_loaded_textdomains'],
	static function ($entry) {
		return ($entry['domain'] ?? '') === 'parrotposter'
			&& substr((string) ($entry['mofile'] ?? ''), -strlen('parrotposter-ru_RU.mo')) === 'parrotposter-ru_RU.mo';
	}
));
assert_true('load_textdomain called with parrotposter-ru_RU.mo', count($mo_loads) > 0);
assert_true(
	'parrotposter-ru_RU.mo is readable',
	is_readable(PARROTPOSTER_PLUGIN_DIR . 'languages/parrotposter-ru_RU.mo')
);

// page has no taxonomies registered in this stub -> no taxonomies section.
$page_schema = WireProtocol::field_schema_for_post_type('page');
$page_section_ids = array_column($page_schema['sections'], 'id');
assert_true('taxonomies section absent for post_type=page (no taxonomies)', !in_array('taxonomies', $page_section_ids, true));

// Unknown post_type -> WP_Error (bridge_field_schema maps this to a JSON error + 400).
$err = WireProtocol::field_schema_for_post_type('no_such_type');
assert_true('field_schema_for_post_type() rejects unknown post_type', is_wp_error($err));
assert_eq('unknown post_type error code', 'invalid_post_type', $err->get_error_code());

// Empty post_type (e.g. malformed source_path from the bridge) -> WP_Error, not a fatal.
$empty_err = WireProtocol::field_schema_for_post_type('');
assert_true('field_schema_for_post_type("") rejects empty post_type', is_wp_error($empty_err));

echo "\nAll WP-11 bridge smoke tests passed.\n";

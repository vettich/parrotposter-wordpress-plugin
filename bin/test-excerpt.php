#!/usr/bin/env php
<?php

/**
 * Standalone tests for excerpt plain-text extraction (no WordPress bootstrap).
 *
 * Usage: php bin/test-excerpt.php
 */

define('ABSPATH', true);

require_once dirname(__DIR__) . '/src/Tools.php';

use parrotposter\Tools;

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

function excerpt_from_content(string $content): string
{
	return Tools::truncate_text(Tools::clear_text($content), 360, '...');
}

function excerpt_from_manual(string $excerpt): string
{
	return Tools::clear_text($excerpt);
}

$gutenberg_content = '<!-- wp:image {"id":13,"sizeSlug":"full","linkDestination":"none"} -->'
	. '<figure class="wp-block-image size-full"><img src="http://wp.loc/wp-content/uploads/2021/10/1f8c3b52c2c89d7bd672.jpg" alt="" class="wp-image-13"/></figure>'
	. '<!-- /wp:image -->'
	. "\n\n"
	. '<!-- wp:paragraph -->'
	. '<p>Описание этой новости</p>'
	. '<!-- /wp:paragraph -->';

assert_eq(
	'gutenberg content yields plain excerpt',
	'Описание этой новости',
	excerpt_from_content($gutenberg_content)
);

assert_eq(
	'manual excerpt strips tags',
	'Short summary',
	excerpt_from_manual('<p>Short <b>summary</b></p>')
);

assert_eq(
	'clear_text removes html comments',
	'Body text',
	Tools::clear_text('<!-- wp:paragraph --><p>Body text</p><!-- /wp:paragraph -->')
);

echo "\nAll tests passed.\n";

#!/usr/bin/env php
<?php

/**
 * Standalone tests for Tools::content_first_paragraph (no WordPress bootstrap).
 *
 * Usage: php bin/test-content-first-paragraph.php
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

// Client HTML: image-only paragraph, list, text paragraph.
$client_html = '<p><p><img class="aligncenter" src="https://example.com/flag.jpg" alt="flag" /></p></p>'
	. '<ul> <li><em>Представительница Центробанка Азербайджана Фидан Тофиди заявила о прогрессе в разработке законодательства.</em></li>'
	. ' <li><em>Второй пункт.</em></li> </ul>'
	. '<p>Центробанк Азербайджана уже подготовил финальную версию рамочного законопроекта о криптоактивах.</p>';

assert_eq(
	'client HTML returns first list item',
	'Представительница Центробанка Азербайджана Фидан Тофиди заявила о прогрессе в разработке законодательства.',
	Tools::content_first_paragraph($client_html)
);

assert_eq(
	'empty content',
	'',
	Tools::content_first_paragraph('')
);

assert_eq(
	'empty paragraph then real paragraph',
	'Real paragraph text.',
	Tools::content_first_paragraph('<p></p><p>Real paragraph text.</p>')
);

assert_eq(
	'separator paragraph skipped',
	'List item text',
	Tools::content_first_paragraph('<p>-----</p><ul><li>List item text</li></ul>')
);

assert_eq(
	'nested image-only paragraphs skipped',
	'Body text.',
	Tools::content_first_paragraph('<p><p><img src="x.jpg" /></p></p><p>Body text.</p>')
);

assert_eq(
	'plain text without tags',
	'Single line of plain text.',
	Tools::content_first_paragraph('Single line of plain text.')
);

assert_eq(
	'heading before paragraph',
	'Heading text',
	Tools::content_first_paragraph('<h2>Heading text</h2><p>Paragraph after heading.</p>')
);

assert_eq(
	'is_substantive rejects dashes',
	false,
	Tools::is_substantive_paragraph_text('-----')
);

assert_eq(
	'is_substantive accepts cyrillic',
	true,
	Tools::is_substantive_paragraph_text('Центробанк')
);

echo "\nAll tests passed.\n";

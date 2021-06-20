<?php
$config = new PhpCsFixer\Config();

return $config->setRules([
		'@PSR2' => true,
		'full_opening_tag' => true,
		'array_syntax' => ['syntax' => 'short'],
		'binary_operator_spaces' => [
			'default' => 'single_space'
		],
])
->setIndent("\t");

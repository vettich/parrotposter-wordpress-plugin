<?php

defined('ABSPATH') || die;

use parrotposter\PP;
use parrotposter\AssetModules;

AssetModules::enqueue(['h1', 'block', 'input', 'nav-tab']);

$data = get_transient('parrotposter_autoposting_add_data');
if ($data !== false) {
	delete_transient('parrotposter_autoposting_add_data');
} else {
	$name = __('Autoposting #1', 'parrotposter');
	$increment = get_option('parrotposter_autoposting_n', 0);
	$increment++;
	$name = str_replace('#1', "#$increment", $name);

	$data = [
		'name' => $name,
		'enable' => 1,
		'wp_post_type' => 'post',
		'post_text' => "{title}{br}\n{br}\n{excerpt}",
		'post_link' => '{link}',
		'post_tags' => '{post_tag}',
		'post_images' => ['{content_images}'],
		'utm_enable' => 0,
		'utm_source' => '{social_code}',
		'account_ids' => [],
		'when_publish' => '',
		'exclude_duplicates' => 1,
		'extra_vk_from_group' => 1,
		'extra_vk_signed' => 0,
	];
}
?>

<?php PP::include_view('header', [
	'title' => __('Creating autoposting', 'parrotposter'),
	'back_url' => 'admin.php?page=parrotposter_scheduler'
]) ?>
<?php PP::include_view('notice') ?>

<?php PP::include_view('scheduler/autoposting_form', [
	'action' => 'parrotposter_autoposting_add',
	'back_url' => 'admin.php?page=parrotposter_scheduler&view=autoposting_add',
	'success_url' => 'admin.php?page=parrotposter_scheduler',
	'increment' => isset($increment) ? $increment : 0,
	'data' => $data,
]) ?>

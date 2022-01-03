<?php

if (!defined('ABSPATH')) {
	die;
}

use parrotposter\PP;

$button_link = [
	'text' => __('+ Add autoposting', 'parrotposter'),
	'href' => '?page=parrotposter_scheduler&view=autoposting_add',
];
if ($view_args['tab'] == 'unload') {
	$button_link = [
		'text' => __('+ Add autounloading', 'parrotposter'),
		'href' => '?page=parrotposter_scheduler&view=unload_add',
	];
}

?>

<?php PP::include_view('header', [
	'title' => __('Scheduler', 'parrotposter'),
	'button_link' => $view_args['show_button'] ? $button_link : false,
]) ?>
<?php PP::include_view('notice') ?>

<nav class="parrotposter-nav-tab__wrapper">
	<a href="?page=parrotposter_scheduler"
		class="parrotposter-nav-tab__item <?php if ($view_args['tab'] == 'autoposting') {
	echo 'active';
} ?>">
		<?php _e('Autoposting', 'parrotposter') ?>
	</a>
	<a href="?page=parrotposter_scheduler&view=unload"
		class="parrotposter-nav-tab__item <?php if ($view_args['tab'] == 'unload') {
	echo 'active';
} ?>">
		<?php _e('Autounloading', 'parrotposter') ?>
	</a>
</nav>


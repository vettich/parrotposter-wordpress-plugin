<?php

if (!defined('ABSPATH')) {
	die;
}

use parrotposter\PP;

$button_link = [
	'text' => parrotposter__('+ Add autoposting'),
	'href' => '?page=parrotposter_scheduler&view=autoposting_add',
];
if ($view_args['tab'] == 'unload') {
	$button_link = [
		'text' => parrotposter__('+ Add autounloading'),
		'href' => '?page=parrotposter_scheduler&view=unload_add',
	];
}

?>

<?php PP::include_view('header', [
	'title' => parrotposter__('Scheduler'),
	'button_link' => $view_args['show_button'] ? $button_link : false,
]) ?>
<?php PP::include_view('notice') ?>

<nav class="parrotposter-nav-tab__wrapper">
	<a href="?page=parrotposter_scheduler"
		class="parrotposter-nav-tab__item <?php if ($view_args['tab'] == 'autoposting') {
	echo 'active';
} ?>">
		<?php parrotposter_e('Autoposting') ?>
	</a>
	<a href="?page=parrotposter_scheduler&view=unload"
		class="parrotposter-nav-tab__item <?php if ($view_args['tab'] == 'unload') {
	echo 'active';
} ?>">
		<?php parrotposter_e('Autounloading') ?>
	</a>
</nav>


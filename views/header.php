<?php

if (!defined('ABSPATH')) {
	die;
}

use parrotposter\PP;
use parrotposter\Menu;
use parrotposter\AssetModules;

AssetModules::enqueue(['header', 'h1']);

$menu = Menu::get_items();

?>

<div class="parrotposter-wrap">

	<div class="parrotposter-header">
		<div class="parrotposter-header__logo">
			<img src="<?php echo PP::asset('images/parrotposter-logo-wide-2.svg') ?>" alt="">
		</div>

		<ul class="parrotposter-header__menu">
		<?php foreach ($menu as $item): ?>
			<li class="<?php Menu::the_active_class($item) ?>">
				<a href="<?php Menu::the_link($item) ?>">
					<?php echo esc_attr($item['label']) ?>
				</a>
			</li>
		<?php endforeach ?>
		</ul>
	</div>

</div>

<hr class="wp-header-end">

<?php if (isset($view_args['title'])): ?>
<div class="parrotposter-h1">
	<?php if (isset($view_args['back_url'])): ?>
	<a class="parrotposter-h1__back" href="<?php echo esc_url($view_args['back_url']) ?>"></a>
	<?php endif ?>

	<h1><?php echo esc_html($view_args['title']) ?></h1>

	<?php if (!empty($view_args['button_link'])): ?>
	<?php
	$btn = $view_args['button_link'];
	$btn_href = isset($btn['href']) ? $btn['href'] : '';
	$btn_text = isset($btn['text']) ? $btn['text'] : '';
	$btn_class = isset($btn['class']) ? $btn['class'] : '';
	?>
	<?php if ($btn_href !== '' && $btn_text !== ''): ?>
	<a
		class="<?php echo esc_attr(trim('button ' . $btn_class . ' parrotposter-h1__button-link')) ?>"
		href="<?php echo esc_url($btn_href) ?>">
		<?php echo esc_html($btn_text) ?>
	</a>
	<?php endif ?>
	<?php endif ?>
</div>
<?php endif ?>

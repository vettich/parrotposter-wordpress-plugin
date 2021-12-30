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

	<h1><?php echo esc_attr($view_args['title']) ?></h1>

	<?php if (!empty($view_args['button_link'])): ?>
	<?php $btn_cls = isset($view_args['button_link']['class']) ? $view_args['button_link']['class'] : '' ?>
	<a
		class="button <?php echo $btn_cls ?> parrotposter-h1__button-link"
		href="<?php echo $view_args['button_link']['href'] ?>">
		<?php echo $view_args['button_link']['text'] ?>
	</a>
	<?php endif ?>
</div>
<?php endif ?>

<?php

if (!defined('ABSPATH')) {
	die;
}

use parrotposter\Menu;
use parrotposter\AssetModules;

AssetModules::enqueue(['header']);

$menu = Menu::get_items();

?>

<div class="parrotposter-wrap">

	<div class="parrotposter-header">
		<div class="parrotposter-header__logo">
			<img src="<?php echo ParrotPoster::asset('images/parrotposter-logo-wide.svg') ?>" alt="">
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

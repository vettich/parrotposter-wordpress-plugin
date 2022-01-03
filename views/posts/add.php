<?php

use parrotposter\PP;
use parrotposter\Api;
use parrotposter\AssetModules;

if (!defined('ABSPATH')) {
	die;
}

AssetModules::enqueue(['block', 'input', 'nav-tab']);

?>

<?php PP::include_view('header', [
	'title' => __('Creating post', 'parrotposter'),
	'back_url' => 'admin.php?page=parrotposter_posts'
]) ?>

	<div class="parrotposter-empty-block parrotposter-empty-block--in-development">
		<div class="parrotposter-empty-block__title">
			<?php _e('This section is in development', 'parrotposter') ?>
		</div>
	</div>


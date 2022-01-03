<?php
if (!defined('ABSPATH')) {
	die;
}

use parrotposter\PP;
use parrotposter\AssetModules;

AssetModules::enqueue(['block']);

?>

<?php PP::include_view('header') ?>

<div class="parrotposter-block parrotposter-block--success">
	<div class="parrotposter-block__value">
		<?php _e('You paid successfully. Thank you!', 'parrotposter') ?>
	</div>
	<div>
		<a class="button button-primary" href="admin.php?page=parrotposter_tariffs">
			<?php _e('Back to tariffs', 'parrotposter') ?>
		</a>
	</div>
</div>

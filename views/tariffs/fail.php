<?php
if (!defined('ABSPATH')) {
	die;
}

use parrotposter\PP;
use parrotposter\AssetModules;

AssetModules::enqueue(['block']);

?>

<?php PP::include_view('header') ?>

<div class="parrotposter-block parrotposter-block--fail">
	<div class="parrotposter-block__value">
		<?php parrotposter_e('There was an error making the payment, try again! Or report it to support@parrotposter.com') ?>
	</div>
	<div>
		<a class="button button-primary" href="admin.php?page=parrotposter_tariffs">
			<?php parrotposter_e('Back to tariffs') ?>
		</a>
	</div>
</div>

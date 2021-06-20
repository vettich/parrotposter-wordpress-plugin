<?php
use parrotposter\FormHelpers;
use parrotposter\Options;

$a = 1;
$bbbb = 2;

if (!current_user_can('manage_options')) {
	return;
}

if (empty(Options::user_id())) {
	ParrotPoster::include_view('auth');
	return;
}
?>

<div class="wrap">
	<h1><?php ParrotPoster::_e('ParrotPoster') ?></h1>

	<p>
		<form action="<?php echo esc_url(admin_url('admin-post.php')) ?>" method="post">
			<?php FormHelpers::the_nonce() ?>
			<input type="hidden" name="action" value="parrotposter_logout">
			<input class="button button-primary" type="submit" name="submit" value="Logout">
		</form>
	</p>

</div>

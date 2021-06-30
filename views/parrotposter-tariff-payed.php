<div class="wrap">
	<h1><?php parrotposter_e('Payment in ParrotPoster') ?></h1>

<?php if ($_GET['subpage'] == 'tariff_success_payed'): ?>
	<div class="notice notice-success">
		<p><?php parrotposter_e('You paid successfully. Thank you!') ?></p>
	</div>
<?php endif ?>

<?php if ($_GET['subpage'] == 'tariff_fail_payed'): ?>
	<div class="notice notice-error">
		<p><?php parrotposter_e('There was an error making the payment, try again! Or report it to tech support.') ?></p>
	</div>
<?php endif ?>

	<a href="admin.php?page=parrotposter" class="btn"><?php parrotposter_e('Go back to plugin') ?></a>
</div>


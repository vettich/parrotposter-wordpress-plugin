<?php

defined('ABSPATH') || die;

use parrotposter\AssetModules;
use parrotposter\FormHelpers;
use parrotposter\Api;

AssetModules::enqueue(['accounts']);

list($accounts) = Api::list_accounts();
$accounts = parrotposter\ApiHelpers::fix_accounts_photos($accounts);

list(
	'input_name' => $input_name,
	'account_ids' => $account_ids,
) = $view_args;
?>

<?php if (empty($accounts)): ?>

<div class="parrotposter-accounts__no-list">
	<div class="parrotposter-accounts__no-list-pic"></div>
	<div class="parrotposter-accounts__no-list-title">
		<?php _e('You have no accounts connected', 'parrotposter') ?>
	</div>
	<div class="parrotposter-accounts__no-list-subtitle">
		<?php sprintf(__('To connect, go to <a href="%s">Accounts</a>', 'parrotposter'), 'admin.php?page=parrotposter_accounts') ?>
	</div>
</div>

<?php else: ?>

<div class="parrotposter-accounts__list parrotposter-accounts__list--inputs">
	<?php foreach ($accounts as $item): ?>
		<label
			class="parrotposter-accounts__item parrotposter-accounts__item--input"
			data-id="<?php echo esc_attr($item['id']) ?>">
			
			<input
				type="checkbox"
				name="<?php echo esc_attr($input_name) ?>"
				value="<?php echo esc_attr($item['id']) ?>"
				<?php echo in_array($item['id'], (array)$account_ids) ? 'checked' : '' ?>>

			<div class="parrotposter-accounts__photo">
				<img src="<?php echo esc_url($item['photo']) ?>" alt="photo">
				<div class="parrotposter-accounts__type <?php echo esc_html($item['type']) ?>"></div>
			</div>
			<div class="parrotposter-accounts__name" title="<?php echo esc_html($item['name']) ?>">
				<?php echo esc_html($item['name']) ?>
			</div>
		</label>
	<?php endforeach ?>
</div>

<?php endif ?>

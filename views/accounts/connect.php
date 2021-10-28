<?php
if (!defined('ABSPATH')) {
	die;
}

use parrotposter\AssetModules;

AssetModules::enqueue(['accounts-connect', 'input', 'modal', 'notice']);

$disabled_class = '';
if (isset($view_args['connect_disabled']) && $view_args['connect_disabled']) {
	$disabled_class = 'disabled';
}
?>

<div class="parrotposter-accounts-connect">
	<div class="parrotposter-accounts-connect__btn fb <?php echo $disabled_class ?>"><?php parrotposter_e('Connect') ?></div>
	<div class="parrotposter-accounts-connect__btn insta <?php echo $disabled_class ?>"><?php parrotposter_e('Connect') ?></div>
	<div class="parrotposter-accounts-connect__btn ok <?php echo $disabled_class ?>"><?php parrotposter_e('Connect') ?></div>
	<div class="parrotposter-accounts-connect__btn tg <?php echo $disabled_class ?>"><?php parrotposter_e('Connect') ?></div>
	<div class="parrotposter-accounts-connect__btn vk <?php echo $disabled_class ?>"><?php parrotposter_e('Connect') ?></div>
</div>

<div id="parrotposter-connect-insta" class="parrotposter-modal">
	<div class="parrotposter-modal__container">
		<div class="parrotposter-modal__close"></div>
		<div class="parrotposter-modal__title"><?php parrotposter_e('Connect Instagram') ?></div>
		<div class="parrotposter-modal__content">
			<div class="parrotposter-notice parrotposter-notice__error" style="display: none"><p></p></div>
			<label class="parrotposter-input">
				<span><?php echo parrotposter_x('Login', 'connect-insta') ?></span>
				<input type="text" name="parrotposter[username]">
			</label>
			<label class="parrotposter-input">
				<span><?php parrotposter_e('Password') ?></span>
				<input type="password" name="parrotposter[password]">
			</label>
			<label class="parrotposter-input">
				<span><?php parrotposter_e('Proxy') ?></span>
				<input type="text" name="parrotposter[proxy]">
			</label>
			<label class="parrotposter-input" style="display: none">
				<span><?php parrotposter_e('Code') ?></span>
				<input type="text" name="parrotposter[code]">
			</label>
		</div>
		<button class="button button-primary"><?php parrotposter_e('Connect') ?></button>
	</div>
</div>

<div id="parrotposter-connect-tg" class="parrotposter-modal">
	<div class="parrotposter-modal__container">
		<div class="parrotposter-modal__close"></div>
		<div class="parrotposter-modal__title"><?php parrotposter_e('Connect Telegram') ?></div>
		<div class="parrotposter-modal__content">
			<div class="parrotposter-notice parrotposter-notice__error" style="display: none"><p></p></div>
			<label class="parrotposter-input">
				<span><?php parrotposter_e('Telegram bot token') ?></span>
				<input type="text" name="parrotposter[bot_token]">
			</label>
			<label class="parrotposter-input">
				<span><?php parrotposter_e('Channel or group link') ?></span>
				<input type="text" name="parrotposter[username]">
			</label>
		</div>
		<button class="button button-primary"><?php parrotposter_e('Connect') ?></button>
	</div>
</div>

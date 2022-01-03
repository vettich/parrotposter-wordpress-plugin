<?php
if (!defined('ABSPATH')) {
	die;
}

use parrotposter\AssetModules;

AssetModules::enqueue(['accounts-connect', 'input', 'modal', 'notice', 'copy', 'common']);

$disabled_class = '';
if (isset($view_args['connect_disabled']) && $view_args['connect_disabled']) {
	$disabled_class = 'disabled';
}

$connect_txt = _x('Connect', 'connect account button', 'parrotposter');

?>

<div class="parrotposter-accounts-connect">
	<div class="parrotposter-accounts-connect__btn fb <?php echo $disabled_class ?>"><?php echo $connect_txt ?></div>
	<div class="parrotposter-accounts-connect__btn insta <?php echo $disabled_class ?>"><?php echo $connect_txt ?></div>
	<div class="parrotposter-accounts-connect__btn ok <?php echo $disabled_class ?>"><?php echo $connect_txt ?></div>
	<div class="parrotposter-accounts-connect__btn tg <?php echo $disabled_class ?>"><?php echo $connect_txt ?></div>
	<div class="parrotposter-accounts-connect__btn vk <?php echo $disabled_class ?>"><?php echo $connect_txt ?></div>
</div>

<div id="parrotposter-connect-insta" class="parrotposter-modal">
	<div class="parrotposter-modal__container">
		<div class="parrotposter-modal__close"></div>
		<div class="parrotposter-modal__title"><?php _e('Connect Instagram', 'parrotposter') ?></div>
		<div class="parrotposter-notice parrotposter-notice__error" style="display: none"><p></p></div>
		<div class="parrotposter-input__group parrotposter-input--full">
			<label class="parrotposter-input">
				<span><?php _ex('Username', 'Connect Instagram', 'parrotposter') ?></span>
				<div class="parrotposter-input__help">
					<?php _e('Your Instagram username: @example or example', 'parrotposter') ?>
				</div>
				<input type="text" name="parrotposter[username]">
			</label>

			<label class="parrotposter-input">
				<span><?php _e('Password', 'parrotposter') ?></span>
				<div class="parrotposter-input__help">
					<?php _e('We do not store passwords anywhere', 'parrotposter') ?>
				</div>
				<input type="password" name="parrotposter[password]">
			</label>

			<label class="parrotposter-input">
				<span><?php _e('Proxy (optional)', 'parrotposter') ?></span>
				<div class="parrotposter-input__help">
					<?php _e('You can enter a proxy for Instagram connection. Proxy format: https://user:password@domain_or_ip:port', 'parrotposter') ?>
				</div>
				<input type="text" name="parrotposter[proxy]">
			</label>

			<label class="parrotposter-input" style="display: none">
				<span><?php _e('Code', 'parrotposter') ?></span>
				<input type="text" name="parrotposter[code]">
			</label>
		</div>
		<div class="parrotposter-input__note">
			<?php _e('Please note!', 'parrotposter') ?>
			<ul>
				<li><?php _e('For security reasons, Instagram may reset your password. Before connecting your account, please make sure you have access to the email or phone number on your Instagram profile', 'parrotposter') ?></li>
				<li><?php _e('If your account doesn\'t connect, try turning off/on two-factor authentication', 'parrotposter') ?></li>
			</ul>
		</div>
		<button class="button button-primary"><?php echo $connect_txt ?></button>
	</div>
</div>

<div id="parrotposter-connect-tg" class="parrotposter-modal">
	<div class="parrotposter-modal__container">
		<div class="parrotposter-modal__close"></div>
		<div class="parrotposter-modal__title"><?php _e('Connect Telegram', 'parrotposter') ?></div>
		<div class="parrotposter-notice parrotposter-notice__error" style="display: none"><p></p></div>
		<div class="parrotposter-input__group parrotposter-input--full">
			<label class="parrotposter-input">
				<span><?php _e('Telegram bot token', 'parrotposter') ?></span>
				<div class="parrotposter-input__help">
					<?php _ex('1. Create your telegram bot', 'telegram bot token instruction', 'parrotposter') ?> <br>
					<?php _ex('1.1. Start chat with <a href="https://t.me/BotFather" class="parrotposter-external-link" target="_blank">@BotFather</a>', 'telegram bot token instruction', 'parrotposter') ?> <br>
					<?php _ex('1.2. Send the command <span class="parrotposter-copy">/newbot</span> and follow @BotFather\'s instructions', 'telegram bot token instruction', 'parrotposter') ?> <br>
					<?php _ex('2. Send the command <span class="parrotposter-copy">/token</span> to bot @BotFather to generate a token', 'telegram bot token instruction', 'parrotposter') ?> <br>
					<?php _ex('3. Copy your bot\'s token here', 'telegram bot token instruction', 'parrotposter') ?>
				</div>
				<input type="text" name="parrotposter[bot_token]">
			</label>
			<label class="parrotposter-input">
				<span><?php _ex('Channel or group link', 'telegram', 'parrotposter') ?></span>
				<div class="parrotposter-input__help">
					<?php _ex('1. Copy to here your channel or group link. Example: https://t.me/your_channel', 'telegram channel/group link instruction', 'parrotposter') ?> <br>
					<?php _ex('2. Make sure you add the created bot to your channel or group', 'telegram channel/group link instruction', 'parrotposter') ?>
				</div>
				<input type="text" name="parrotposter[username]">
			</label>
		</div>
		<div class="parrotposter-input__note">
			<?php _e('Please note!', 'parrotposter') ?>
			<ul>
				<li><?php _ex('Only open groups or channels can be connected', 'telegram', 'parrotposter') ?></li>
				<li><?php _ex('In channels, posts are published on behalf of the channel, and in groups - on behalf of the created bot', 'telegram', 'parrotposter') ?></li>
			</ul>
		</div>
		<button class="button button-primary"><?php echo $connect_txt ?></button>
	</div>
</div>

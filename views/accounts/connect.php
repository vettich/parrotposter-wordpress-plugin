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

$tg_help_bot = parrotposter__('1. Create your telegram bot <br>
1.1. Start chat with <a href="https://t.me/BotFather" class="parrotposter-external-link" target="_blank">@BotFather</a> <br>
1.2. Send the command <span class="parrotposter-copy">/newbot</span> and follow @BotFather\'s instructions <br>
2. Send the command <span class="parrotposter-copy">/token</span> to bot @BotFather to generate a token <br>
3. Copy your bot\'s token here');

$tg_help_channel = parrotposter__('1. Copy to here your channel or group link. Example: https://t.me/your_channel <br>
2. Make sure you add the created bot to your channel or group');

$insta_note = parrotposter__('Please note!
<ul>
	<li>For security reasons, Instagram may reset your password. Before connecting your account, please make sure you have access to the email or phone number on your Instagram profile</li>
	<li>If your account doesn\'t connect, try turning off/on two-factor authentication</li>
</ul>');

$tg_note = parrotposter__('Please note!
<ul>
	<li>Only open groups or channels can be connected</li>
	<li>In channels, posts are published on behalf of the channel, and in groups - on behalf of the created bot</li>
</ul>');
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
		<div class="parrotposter-notice parrotposter-notice__error" style="display: none"><p></p></div>
		<div class="parrotposter-input__group">
			<label class="parrotposter-input">
				<span><?php echo parrotposter_x('Username', 'Connect Instagram') ?></span>
				<div class="parrotposter-input__help">
					<?php parrotposter_e('Your Instagram username: @example or example') ?>
				</div>
				<input type="text" name="parrotposter[username]">
			</label>
			<label class="parrotposter-input">
				<span><?php parrotposter_e('Password') ?></span>
				<div class="parrotposter-input__help">
					<?php parrotposter_e('We do not store passwords anywhere') ?>
				</div>
				<input type="password" name="parrotposter[password]">
			</label>
			<label class="parrotposter-input">
				<span><?php parrotposter_e('Proxy (optional)') ?></span>
				<div class="parrotposter-input__help">
					<?php parrotposter_e('You can enter a proxy for Instagram connection. Proxy format: https://user:password@domain_or_ip:port') ?>
				</div>
				<input type="text" name="parrotposter[proxy]">
			</label>
			<label class="parrotposter-input" style="display: none">
				<span><?php parrotposter_e('Code') ?></span>
				<input type="text" name="parrotposter[code]">
			</label>
		</div>
		<div class="parrotposter-input__note">
			<?php echo $insta_note ?>
		</div>
		<button class="button button-primary"><?php parrotposter_e('Connect') ?></button>
	</div>
</div>

<div id="parrotposter-connect-tg" class="parrotposter-modal">
	<div class="parrotposter-modal__container">
		<div class="parrotposter-modal__close"></div>
		<div class="parrotposter-modal__title"><?php parrotposter_e('Connect Telegram') ?></div>
		<div class="parrotposter-notice parrotposter-notice__error" style="display: none"><p></p></div>
		<div class="parrotposter-input__group">
			<label class="parrotposter-input">
				<span><?php parrotposter_e('Telegram bot token') ?></span>
				<div class="parrotposter-input__help">
					<?php echo $tg_help_bot ?>
				</div>
				<input type="text" name="parrotposter[bot_token]">
			</label>
			<label class="parrotposter-input">
				<span><?php parrotposter_e('Channel or group link') ?></span>
				<div class="parrotposter-input__help">
					<?php echo $tg_help_channel ?>
				</div>
				<input type="text" name="parrotposter[username]">
			</label>
		</div>
		<div class="parrotposter-input__note">
			<?php echo $tg_note ?>
		</div>
		<button class="button button-primary"><?php parrotposter_e('Connect') ?></button>
	</div>
</div>

<?php
if (!defined('ABSPATH')) {
	die;
}

use parrotposter\PP;
use parrotposter\Api;
use parrotposter\Tools;
use parrotposter\AssetModules;

AssetModules::enqueue(['help']);

?>

<?php PP::include_view('header', [
	'title' => _x('Help', 'help', 'parrotposter'),
]) ?>

<div class="parrotposter-help__block">

	<div class="parrotposter-help__item parrotposter-help__item--open">
		<div class="parrotposter-help__title">
			<?php _ex('About plugin', 'help', 'parrotposter') ?>
		</div>
		<div class="parrotposter-help__description">
			<?php _ex('ParrotPoster is a cloud-based service for publishing news and products from your site to social networks.', 'help', 'parrotposter') ?>
		</div>
	</div>

	<div class="parrotposter-help__item">
		<div class="parrotposter-help__title">
			<?php _ex('How many posts per day can I publish?', 'help', 'parrotposter') ?>
		</div>
		<div class="parrotposter-help__description">
			<?php _ex('No limits, but in the social network VKontakte you can publish no more than 50 posts per day, and in other social networks, we recommend publishing no more than 15-25 posts per day, so that the social network does not block the account.', 'help', 'parrotposter') ?>
		</div>
	</div>

	<div class="parrotposter-help__item">
		<div class="parrotposter-help__title">
			<?php _ex('How long is the trial version and can I change the tariff?', 'help', 'parrotposter') ?>
		</div>
		<div class="parrotposter-help__description">
			<?php _ex('When you register for the plugin, you get 14 days of free use with the option to add 3 accounts to test the functionality. If you need more accounts - you can change the tariff in the "Tariffs" section.', 'help', 'parrotposter') ?>
		</div>
	</div>

	<div class="parrotposter-help__item">
		<div class="parrotposter-help__title">
			<?php _ex('How many social network accounts can I add?', 'help', 'parrotposter') ?>
		</div>
		<div class="parrotposter-help__description">
			<?php _ex('It depends on the chosen tariff. The trial version has 3 accounts, on paid tariffs from 5 to 22 accounts. If you need to add more than 22 accounts, send us an email at support@parrotposter.com.', 'help', 'parrotposter') ?>
		</div>
	</div>

	<div class="parrotposter-help__item">
		<div class="parrotposter-help__title">
			<?php _ex('Why I cannot add a social account?', 'help', 'parrotposter') ?>
		</div>
		<div class="parrotposter-help__description">
			<ul>
				<li><?php _ex('You are not an administrator of the group/page or you were not granted any access rights', 'help', 'parrotposter') ?>
				<li><?php _ex('Your Instagram username and password are not correct', 'help', 'parrotposter') ?>
				<li><?php _ex('You can also try turning two-factor authentication on or off in Instagram, or changing your password', 'help', 'parrotposter') ?>
				<li><?php _ex('You did not specify the Telegram Bot Token for your Telegram group/channel correctly', 'help', 'parrotposter') ?>
				<li><?php _ex('You did not specify the Telegram link to your channel/group correctly', 'help', 'parrotposter') ?>
			</ul>
		</div>
	</div>

	<div class="parrotposter-help__item">
		<div class="parrotposter-help__title">
			<?php _ex('Are there any plans to add new social networks?', 'help', 'parrotposter') ?>
		</div>
		<div class="parrotposter-help__description">
			<?php _ex('Yes, we plan to do this in the near future.', 'help', 'parrotposter') ?>
		</div>
	</div>

	<div class="parrotposter-help__item">
		<div class="parrotposter-help__title">
			<?php _ex('How do I publish a post?', 'help', 'parrotposter') ?>
		</div>
		<div class="parrotposter-help__description">
			<ul>
				<li><?php _ex('Selectively, to do this, you can go to the already created news or product and click on "Publish to social networks," then on a separate page, fine-tune the news in social networks and publish the post', 'help', 'parrotposter') ?>
				<li><?php _ex('Using the autoposting template, for this you need to configure the autoposting template in the "Scheduler" section', 'help', 'parrotposter') ?>
			</ul>
		</div>
	</div>

	<div class="parrotposter-help__item">
		<div class="parrotposter-help__title">
			<?php _ex('Why aren\'t the posts published to social networks?', 'help', 'parrotposter') ?>
		</div>
		<div class="parrotposter-help__description">
			<ul>
				<li><?php _ex('The tariff has expired, renew it in the tab "Tariffs"', 'help', 'parrotposter') ?>
				<li><?php _ex('Token storage from social network has expired, try to reconnect your account in the "Accounts" section', 'help', 'parrotposter') ?>
				<li><?php _ex('Social network suspected of publishing news very often, try to reconnect your account or wait a while (from several hours to several days)', 'help', 'parrotposter') ?>
				<li><?php _ex('Did not add a bot to your Telegram channel/group', 'help', 'parrotposter') ?>
			</ul>
		</div>
	</div>

	<div class="parrotposter-help__item">
		<div class="parrotposter-help__title">
			<?php _ex('Where can I see the results of publications?', 'help', 'parrotposter') ?>
		</div>
		<div class="parrotposter-help__description">
			<?php _ex('In the "Posts" section, you can click on the View button to see the results of posts with statuses and links to the posts.', 'help', 'parrotposter') ?>
		</div>
	</div>

	<div class="parrotposter-help__item">
		<div class="parrotposter-help__title">
			<?php _ex('How do I set up auto-publication of news or products?', 'help', 'parrotposter') ?>
		</div>
		<div class="parrotposter-help__description">
			<?php _ex('You need to go to the Scheduler section and click on the Add Autoposting button, then set up the template.', 'help', 'parrotposter') ?>
			<p>
				<b><?php _ex('General:', 'help', 'parrotposter') ?></b>
				<ul>
					<li><?php _ex('Template name (for general understanding, e.g. News from "Latest Trends" category)', 'help', 'parrotposter') ?>
					<li><?php _ex('Enable/disable autoposting', 'help', 'parrotposter') ?>
				</ul>
			</p>
			<p>
				<b><?php _ex('Activation conditions:', 'help', 'parrotposter') ?></b>
				<br>
				<?php _ex('You need to set the conditions under which the post will automatically publish to social networks, for example:', 'help', 'parrotposter') ?>
				<ul>
					<li><?php _ex('Wordpress post type: Posts', 'help', 'parrotposter') ?>
					<li><?php _ex('Conditions: The category is equal to one of Music Trends, Clothing Trends, Cooking Trends.', 'help', 'parrotposter') ?>
				</ul>
				<?php _ex('With this condition, the post will be published if the created news will belong to one of the selected categories.', 'help', 'parrotposter') ?>
			</p>
			<p>
				<b><?php _ex('Post template:', 'help', 'parrotposter') ?></b>
				<br>
				<?php _ex('You need to configure the text content of the post, for example:', 'help', 'parrotposter') ?>
				<div class="code">
					{title}{br}<br>
					{br}<br>
					{excerpt}<br>
				</div>
				<?php _ex('In this case, the text will take the title of the news and excerpted text (it can be set in a separate field when creating the news).', 'help', 'parrotposter') ?>
			</p>
			<p>
				<b><?php _ex('Link:', 'help', 'parrotposter') ?></b>
				<br>
				<?php _ex('You can leave the <span class="code">{link}</span> macro to add a link to the news from the site to the post, if it is not required - just erase the macro and leave the field empty.', 'help', 'parrotposter') ?>
				<br>
				<?php _ex('You can also set UTM tags.', 'help', 'parrotposter') ?>
			</p>
			<p>
				<b><?php _ex('Tags:', 'help', 'parrotposter') ?></b>
				<br>
				<?php _ex('You can leave the <span class="code">{post_tag}</span> macro to have the tags from the news added to the post, if they are not required - just erase the macro and leave the field blank.', 'help', 'parrotposter') ?>
			</p>
			<p>
				<b><?php _ex('Images:', 'help', 'parrotposter') ?></b>
				<br>
				<?php _ex('You can choose which images are required to be added to the post.', 'help', 'parrotposter') ?>
			</p>
			<p>
				<b><?php _ex('Select Social Networks:', 'help', 'parrotposter') ?></b>
				<br>
				<?php _ex('Select the accounts to which you want to publish the news according to the given template.', 'help', 'parrotposter') ?>
			</p>
			<p>
				<b><?php _ex('When to publish:', 'help', 'parrotposter') ?></b>
				<br>
				<?php _ex('Choose when to publish, either immediately or with a time delay of 1 to 10 minutes after publishing the news/products on the site.', 'help', 'parrotposter') ?>
			</p>
			<p>
				<b><?php _ex('Additional settings:', 'help', 'parrotposter') ?></b>
				<br>
				<?php _ex('Select Exclude duplicates so that the same news is not published to a social network by a given template. You can also publish a VKontakte post on behalf of a group with the author\'s signature added.', 'help', 'parrotposter') ?>
			</p>
		</div>
	</div>

	<div class="parrotposter-help__item">
		<div class="parrotposter-help__title">
			<?php _ex('Is it possible to pay the tariff under the contract?', 'help', 'parrotposter') ?>
		</div>
		<div class="parrotposter-help__description">
			<?php _ex('Yes, but only for customers from the Russian Federation. Email us at support@parrotposter.com to sign a contract.', 'help', 'parrotposter') ?>
		</div>
	</div>

	<div class="parrotposter-help__item">
		<div class="parrotposter-help__title">
			<?php _ex('Is it possible to pay for the tariff using PayPal or other means of electronic payment?', 'help', 'parrotposter') ?>
		</div>
		<div class="parrotposter-help__description">
			<?php _ex('Yes, contact us at support@parrotposter.com.', 'help', 'parrotposter') ?>
		</div>
	</div>

	<div class="parrotposter-help__item">
		<div class="parrotposter-help__title">
			<?php _ex('Have not found an answer to your question or do you have a suggestion on how to improve the plugin?', 'help', 'parrotposter') ?>
		</div>
		<div class="parrotposter-help__description">
			<?php _ex('Email us at support@parrotposter.com', 'help', 'parrotposter') ?>
		</div>
	</div>

</div>

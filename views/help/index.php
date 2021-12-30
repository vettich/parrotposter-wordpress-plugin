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
	'title' => parrotposter__('Help'),
]) ?>

<div class="parrotposter-help__block">

	<div class="parrotposter-help__item parrotposter-help__item--open">
		<div class="parrotposter-help__title">
			<?php parrotposter_xe('About plugin', 'help') ?>
		</div>
		<div class="parrotposter-help__description">
			<?php parrotposter_xe('ParrotPoster is a cloud-based service for publishing news and products from your site to social networks.', 'help') ?>
		</div>
	</div>

	<div class="parrotposter-help__item">
		<div class="parrotposter-help__title">
			<?php parrotposter_xe('How many posts per day can I publish?', 'help') ?>
		</div>
		<div class="parrotposter-help__description">
			<?php parrotposter_xe('No limits, but in the social network VKontakte you can publish no more than 50 posts per day, and in other social networks, we recommend publishing no more than 15-25 posts per day, so that the social network does not block the account.', 'help') ?>
		</div>
	</div>

	<div class="parrotposter-help__item">
		<div class="parrotposter-help__title">
			<?php parrotposter_xe('How long is the trial version and can I change the tariff?', 'help') ?>
		</div>
		<div class="parrotposter-help__description">
			<?php parrotposter_xe('When you register for the plugin, you get 14 days of free use with the option to add 3 accounts to test the functionality. If you need more accounts - you can change the tariff in the "Tariffs" section.', 'help') ?>
		</div>
	</div>

	<div class="parrotposter-help__item">
		<div class="parrotposter-help__title">
			<?php parrotposter_xe('How many social network accounts can I add?', 'help') ?>
		</div>
		<div class="parrotposter-help__description">
			<?php parrotposter_xe('It depends on the chosen tariff. The trial version has 3 accounts, on paid tariffs from 5 to 22 accounts. If you need to add more than 22 accounts, send us an email at support@parrotposter.com.', 'help') ?>
		</div>
	</div>

	<div class="parrotposter-help__item">
		<div class="parrotposter-help__title">
			<?php parrotposter_xe('Why I cannot add a social account?', 'help') ?>
		</div>
		<div class="parrotposter-help__description">
			<ul>
				<li><?php parrotposter_xe('You are not an administrator of the group/page or you were not granted any access rights', 'help') ?>
				<li><?php parrotposter_xe('Your Instagram username and password are not correct', 'help') ?>
				<li><?php parrotposter_xe('You can also try turning two-factor authentication on or off in Instagram, or changing your password', 'help') ?>
				<li><?php parrotposter_xe('You did not specify the Telegram Bot Token for your Telegram group/channel correctly', 'help') ?>
				<li><?php parrotposter_xe('You did not specify the Telegram link to your channel/group correctly', 'help') ?>
			</ul>
		</div>
	</div>

	<div class="parrotposter-help__item">
		<div class="parrotposter-help__title">
			<?php parrotposter_xe('Are there any plans to add new social networks?', 'help') ?>
		</div>
		<div class="parrotposter-help__description">
			<?php parrotposter_xe('Yes, we plan to do this in the near future.', 'help') ?>
		</div>
	</div>

	<div class="parrotposter-help__item">
		<div class="parrotposter-help__title">
			<?php parrotposter_xe('How do I publish a post?', 'help') ?>
		</div>
		<div class="parrotposter-help__description">
			<ul>
				<li><?php parrotposter_xe('Selectively, to do this, you can go to the already created news or product and click on "Publish to social networks," then on a separate page, fine-tune the news in social networks and publish the post', 'help') ?>
				<li><?php parrotposter_xe('Using the autoposting template, for this you need to configure the autoposting template in the "Scheduler" section', 'help') ?>
			</ul>
		</div>
	</div>

	<div class="parrotposter-help__item">
		<div class="parrotposter-help__title">
			<?php parrotposter_xe('Why aren\'t the posts published to social networks?', 'help') ?>
		</div>
		<div class="parrotposter-help__description">
			<ul>
				<li><?php parrotposter_xe('The tariff has expired, renew it in the tab "Tariffs"', 'help') ?>
				<li><?php parrotposter_xe('Token storage from social network has expired, try to reconnect your account in the "Accounts" section', 'help') ?>
				<li><?php parrotposter_xe('Social network suspected of publishing news very often, try to reconnect your account or wait a while (from several hours to several days)', 'help') ?>
				<li><?php parrotposter_xe('Did not add a bot to your Telegram channel/group', 'help') ?>
			</ul>
		</div>
	</div>

	<div class="parrotposter-help__item">
		<div class="parrotposter-help__title">
			<?php parrotposter_xe('Where can I see the results of publications?', 'help') ?>
		</div>
		<div class="parrotposter-help__description">
			<?php parrotposter_xe('In the "Posts" section, you can click on the View button to see the results of posts with statuses and links to the posts.', 'help') ?>
		</div>
	</div>

	<div class="parrotposter-help__item">
		<div class="parrotposter-help__title">
			<?php parrotposter_xe('How do I set up auto-publication of news or products?', 'help') ?>
		</div>
		<div class="parrotposter-help__description">
			<?php parrotposter_xe('You need to go to the Scheduler section and click on the Add Autoposting button, then set up the template.', 'help') ?>
			<p>
				<b><?php parrotposter_xe('General:', 'help') ?></b>
				<ul>
					<li><?php parrotposter_xe('Template name (for general understanding, e.g. News from "Latest Trends" category)', 'help') ?>
					<li><?php parrotposter_xe('Enable/disable autoposting', 'help') ?>
				</ul>
			</p>
			<p>
				<b><?php parrotposter_xe('Activation conditions:', 'help') ?></b>
				<br>
				<?php parrotposter_xe('You need to set the conditions under which the post will automatically publish to social networks, for example:', 'help') ?>
				<ul>
					<li><?php parrotposter_xe('Wordpress post type: Posts', 'help') ?>
					<li><?php parrotposter_xe('Conditions: The category is equal to one of Music Trends, Clothing Trends, Cooking Trends.', 'help') ?>
				</ul>
				<?php parrotposter_xe('With this condition, the post will be published if the created news will belong to one of the selected categories.', 'help') ?>
			</p>
			<p>
				<b><?php parrotposter_xe('Post template:', 'help') ?></b>
				<br>
				<?php parrotposter_xe('You need to configure the text content of the post, for example:', 'help') ?>
				<div class="code">
					{title}{br}<br>
					{br}<br>
					{excerpt}<br>
				</div>
				<?php parrotposter_xe('In this case, the text will take the title of the news and excerpted text (it can be set in a separate field when creating the news).', 'help') ?>
			</p>
			<p>
				<b><?php parrotposter_xe('Link:', 'help') ?></b>
				<br>
				<?php parrotposter_xe('You can leave the <span class="code">{link}</span> macro to add a link to the news from the site to the post, if it is not required - just erase the macro and leave the field empty.', 'help') ?>
				<br>
				<?php parrotposter_xe('You can also set UTM tags.', 'help') ?>
			</p>
			<p>
				<b><?php parrotposter_xe('Tags:', 'help') ?></b>
				<br>
				<?php parrotposter_xe('You can leave the <span class="code">{post_tag}</span> macro to have the tags from the news added to the post, if they are not required - just erase the macro and leave the field blank.', 'help') ?>
			</p>
			<p>
				<b><?php parrotposter_xe('Images:', 'help') ?></b>
				<br>
				<?php parrotposter_xe('You can choose which images are required to be added to the post.', 'help') ?>
			</p>
			<p>
				<b><?php parrotposter_xe('Select Social Networks:', 'help') ?></b>
				<br>
				<?php parrotposter_xe('Select the accounts to which you want to publish the news according to the given template.', 'help') ?>
			</p>
			<p>
				<b><?php parrotposter_xe('When to publish:', 'help') ?></b>
				<br>
				<?php parrotposter_xe('Choose when to publish, either immediately or with a time delay of 1 to 10 minutes after publishing the news/products on the site.', 'help') ?>
			</p>
			<p>
				<b><?php parrotposter_xe('Additional settings:', 'help') ?></b>
				<br>
				<?php parrotposter_xe('Select Exclude duplicates so that the same news is not published to a social network by a given template. You can also publish a VKontakte post on behalf of a group with the author\'s signature added.', 'help') ?>
			</p>
		</div>
	</div>

	<div class="parrotposter-help__item">
		<div class="parrotposter-help__title">
			<?php parrotposter_xe('Is it possible to pay the tariff under the contract?', 'help') ?>
		</div>
		<div class="parrotposter-help__description">
			<?php parrotposter_xe('Yes, but only for customers from the Russian Federation. Email us at support@parrotposter.com to sign a contract.', 'help') ?>
		</div>
	</div>

	<div class="parrotposter-help__item">
		<div class="parrotposter-help__title">
			<?php parrotposter_xe('Is it possible to pay for the tariff using PayPal or other means of electronic payment?', 'help') ?>
		</div>
		<div class="parrotposter-help__description">
			<?php parrotposter_xe('Yes, contact us at support@parrotposter.com.', 'help') ?>
		</div>
	</div>

	<div class="parrotposter-help__item">
		<div class="parrotposter-help__title">
			<?php parrotposter_xe('Have not found an answer to your question or do you have a suggestion on how to improve the plugin?', 'help') ?>
		</div>
		<div class="parrotposter-help__description">
			<?php parrotposter_xe('Email us at support@parrotposter.com', 'help') ?>
		</div>
	</div>

</div>

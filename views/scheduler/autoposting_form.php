<?php

defined('ABSPATH') || die;

use parrotposter\PP;
use parrotposter\AssetModules;
use parrotposter\FormHelpers;
use parrotposter\WpPostHelpers;
use parrotposter\ArrayWrap;
use parrotposter\fields\Fields;
use parrotposter\fields\conditions\Conditions;

AssetModules::enqueue(['block', 'input', 'nav-tab', 'autoposting_form', 'ultraselect', 'pqselect']);

$data = new ArrayWrap($view_args['data']);

$post_types = WpPostHelpers::get_post_types('object');

$when_publish_data = [
	'immediately' => parrotposter__('Immediately upon publishing the post'),
	'delay' => parrotposter__('With a delay'),
];

$post_fields = Fields::get_fields($data->wp_post_type);

$post_images_fields = Fields::get_fields($data->wp_post_type, ['image']);
$post_images_fields_default = [
	[
		'key' => '',
		'label' => parrotposter_x('Select field', 'wp_post_type'),
	],
];
$images_data = [
	'available_items' => array_merge($post_images_fields_default, $post_images_fields),
	'selected_items' => $data->post_images ?: [],
];
$images_data_json = json_encode($images_data);

$post_conditions_fields = Conditions::get_fields($data->wp_post_type);
$post_conditions_fields_default = [
	'key' => '',
	'label' => parrotposter_x('Select field', 'wp_post_type'),
];
$conditions_data = [
	'available_items' => array_merge([$post_conditions_fields_default], $post_conditions_fields),
	'selected_items' => $data->conditions ?: [],
];
$conditions_json = json_encode($conditions_data);
?>

<form action="<?php echo esc_url(admin_url('admin-post.php')) ?>" method="post">
	<?php FormHelpers::the_nonce() ?>
	<input type="hidden" name="action" value="<?php echo $view_args['action'] ?>">
	<input type="hidden" name="back_url" value="<?php echo $view_args['back_url'] ?>">
	<input type="hidden" name="success_url" value="<?php echo $view_args['success_url'] ?>">

	<?php if (isset($view_args['increment'])): ?>
	<input type="hidden" name="parrotposter[increment]" value="<?php echo $view_args['increment'] ?>">
	<?php endif ?>

	<?php if ($data->isset('id')): ?>
	<input type="hidden" name="parrotposter[id]" value="<?php echo $data->id ?>">
	<?php endif ?>

	<div class="parrotposter-block parrotposter-block--scheduler-edit">
		<div class="parrotposter-block__title"><?php parrotposter_e('General') ?></div>

		<div class="parrotposter-input__group">
			<label class="parrotposter-input">
				<span><?php parrotposter_e('Name') ?></span>
				<input type="text" name="parrotposter[name]" value="<?php echo $data->name ?>">
				<div class="parrotposter-input__info">
					<?php parrotposter_e('Name of autoposting template') ?>
				</div>
			</label>

			<label class="parrotposter-input parrotposter-input--toggle">
				<?php FormHelpers::render_checkbox('parrotposter[enable]', $data->enable) ?>
				<span><?php parrotposter_e('Enable') ?></span>
			</label>
		</div>

		<div class="parrotposter-block__title"><?php parrotposter_e('Activation conditions') ?></div>

		<div class="parrotposter-input__group">
			<label class="parrotposter-input">
				<span><?php parrotposter_e('Wordpress post type') ?></span>
				<select name="parrotposter[wp_post_type]">
					<?php foreach ($post_types as $type => $obj): ?>
					<option
						value="<?php echo $type ?>"
						<?php echo $type == $data->wp_post_type ? 'selected' : '' ?>>
						<?php echo $obj->label ?>
					</option>
					<?php endforeach ?>
				</select>
				<div class="parrotposter-input__info">
					<?php parrotposter_e('Post type for autoposting. If you change the value, you should press the Reload button to display changes in the template fields') ?>
				</div>
			</label>

			<div class="parrotposter-input parrotposter-input--multi parrotposter--conditions">
				<span><?php parrotposter_e('Custom conditions') ?></span>
				<div class="parrotposter-input__items" data-items="<?php echo esc_attr($conditions_json) ?>">
				</div>
				<button class="button parrotposter-input__add-btn">
					<?php parrotposter_e('+ Add field') ?>
				</button>
				<div class="parrotposter-input__info">
					<?php parrotposter_e('The post will be published if all conditions are met') ?>
				</div>
			</div>

		</div>

		<div class="parrotposter-block__title"><?php parrotposter_e('Post template') ?></div>

		<div class="parrotposter-input__group">
			<label class="parrotposter-input parrotposter-input--wide parrotposter-autoposting-form--post-text">
				<span><?php parrotposter_e('Post text') ?></span>
				<div class="parrotposter-autoposting-form__input-row">
					<textarea name="parrotposter[post_text]"><?php echo $data->post_text ?></textarea>
					<div class="parrotposter-autoposting-form__post-fields-wrap">
						<div class="parrotposter-autoposting-form__post-fields-button"></div>
						<div class="parrotposter-autoposting-form__post-fields">
							<?php foreach ($post_fields as $field): ?>
							<div class="parrotposter-autoposting-form__post-field" data-key="<?php echo $field['key'] ?>">
								<span class="parrotposter-autoposting-form__post-field-key"><?php echo $field['key'] ?></span>
								<span class="parrotposter-autoposting-form__post-field-label"><?php echo $field['label'] ?></span>
							</div>
							<?php endforeach ?>
						</div>
					</div>
				</div>
				<div class="parrotposter-input__info">
					<?php parrotposter_e('The text of the post in the social networks. Customize the template the way you need, using substitution macros') ?>
				</div>
			</label>

			<label class="parrotposter-input">
				<span><?php parrotposter_e('Link') ?></span>
				<input type="text" name="parrotposter[post_link]" value="<?php echo $data->post_link ?>">
				<div class="parrotposter-input__info">
					<?php parrotposter_e('The link to the site with the news or product. You can delete the macros so that the link will not be published') ?>
				</div>
			</label>

			<div class="parrotposter-input">
				<label class="parrotposter-input parrotposter-input--checkbox">
					<?php FormHelpers::render_checkbox('parrotposter[utm_enable]', $data->utm_enable) ?>
					<span><?php parrotposter_e('UTM tags') ?></span>
				</label>
				<div class="parrotposter-input__info">
					<?php parrotposter_e('If checked, special UTM tags will be added to the end of the link (the field above) to track traffic in analytics') ?>
				</div>
			</div>

			<label class="parrotposter-input parrotposter--utm-param">
				<span><?php parrotposter_xe('Traffic source', 'utm') ?> [utm_source]</span>
				<input type="text" name="parrotposter[utm_source]" value="<?php echo $data->utm_source ?>">
				<div class="parrotposter-input__info">
					<?php parrotposter_e('You can use {social_code} macro, which will be replaced by a social network code (telegram, facebook, etc) when published') ?>
				</div>
			</label>

			<label class="parrotposter-input parrotposter--utm-param">
				<span><?php parrotposter_xe('Traffic type', 'utm') ?> [utm_medium]</span>
				<input type="text" name="parrotposter[utm_medium]" value="<?php echo $data->utm_medium ?>">
				<div class="parrotposter-input__info">
					<?php parrotposter_e('Identifies what type of link was used, such as cost per click or email. Example: cpc') ?>
				</div>
			</label>

			<label class="parrotposter-input parrotposter--utm-param">
				<span><?php parrotposter_xe('Campaign name', 'utm') ?> [utm_campaign]</span>
				<input type="text" name="parrotposter[utm_campaign]" value="<?php echo $data->utm_campaign ?>">
				<div class="parrotposter-input__info">
					<?php parrotposter_e('Identifies a specific product promotion or strategic campaign. Example: news') ?>
				</div>
			</label>

			<label class="parrotposter-input parrotposter--utm-param">
				<span><?php parrotposter_xe('Keyword', 'utm') ?> [utm_term]</span>
				<input type="text" name="parrotposter[utm_term]" value="<?php echo $data->utm_term ?>">
				<div class="parrotposter-input__info">
					<?php parrotposter_e('Identifies search terms') ?>
				</div>
			</label>

			<label class="parrotposter-input parrotposter--utm-param">
				<span><?php parrotposter_xe('Content type', 'utm') ?> [utm_content]</span>
				<input type="text" name="parrotposter[utm_content]" value="<?php echo $data->utm_content ?>">
				<div class="parrotposter-input__info">
					<?php parrotposter_e('Identifies what specifically was clicked to bring the user to the site, such as a banner ad or a text link. It is often used for A/B testing and content-targeted ads') ?>
				</div>
			</label>

			<label class="parrotposter-input">
				<span><?php parrotposter_e('Tags') ?></span>
				<input type="text" name="parrotposter[post_tags]" value="<?php echo $data->post_tags ?>">
				<div class="parrotposter-input__info">
					<?php parrotposter_e('Tags (hashtags) will be added to the end of the post text. Tags from this field will be converted to "correct" hashtags for social networks. For example, "red book, bestseller" will be converted to "#red #book #bestseller"') ?>
				</div>
			</label>

			<div class="parrotposter-input parrotposter-input--multi parrotposter--images">
				<span><?php parrotposter_e('Images') ?></span>
				<div class="parrotposter-input__items" data-items="<?php echo esc_attr($images_data_json) ?>">
				</div>
				<button class="button parrotposter-input__add-btn">
					<?php parrotposter_e('+ Add field') ?>
				</button>
				<div class="parrotposter-input__info">
					<?php parrotposter_e('Images for posting to social networks. You can specify several fields from which images from the news, products, etc. will be taken. For posting to Instagram the image is required') ?>
				</div>
			</div>
		</div>

		<div class="parrotposter-block__title"><?php parrotposter_e('Choice of social networks') ?></div>
		<?php PP::include_view('accounts/choice_list', [
			'account_ids' => $data->account_ids,
			'input_name' => 'parrotposter[account_ids][]',
		]) ?>

		<div class="parrotposter-input__note">
			<ul>
				<li><?php parrotposter_e('Images must be in jpg/png/wepb format') ?>
				<li><?php parrotposter_e('The weight of one image must not exceed 10MB') ?>
				<li><?php parrotposter_e('There can be no more than 10 images in a post') ?>
				<li><?php parrotposter_e('Only one image is uploaded to Facebook (the first image in the list)') ?>
			</ul>
		</div>

		<div class="parrotposter-block__title"><?php parrotposter_e('When to publish') ?></div>

		<div class="parrotposter-input__group">
			<label class="parrotposter-input">
				<select name="parrotposter[when_publish]">
					<?php foreach ($when_publish_data as $k => $v): ?>
					<option
						value="<?php echo $k ?>"
						<?php echo $k == $data->when_publish ? 'selected' : '' ?>>
						<?php echo $v ?>
					</option>
					<?php endforeach ?>
				</select>
				<div class="parrotposter-input__info">
					<?php parrotposter_e('You can specify the time when the post will be published. Immediately - the post will be published on social networks at the same time when the news, product, etc. is published. With a delay - the post will be published with a delay of the specified number of minutes') ?>
				</div>
			</label>

			<label class="parrotposter-input parrotposter--publish-delay">
				<span><?php parrotposter_e('Publish with a delay of 1 to 10 minutes') ?></span>
				<input type="number" name="parrotposter[publish_delay]" min="1" max="10" step="1"
					value="<?php echo $data->publish_delay ?: 1 ?>">
			</label>
		</div>

		<div class="parrotposter-block__title"><?php parrotposter_e('Additional settings') ?></div>

		<div class="parrotposter-input__group">
			<div class="parrotposter-input">
				<label class="parrotposter-input parrotposter-input--checkbox">
					<?php FormHelpers::render_checkbox('parrotposter[exclude_duplicates]', $data->exclude_duplicates) ?>
					<span><?php parrotposter_e('Exclude duplicates (within this template)') ?></span>
				</label>
				<div class="parrotposter-input__info">
					<?php parrotposter_e('If checked, the same news, product, etc. will not be republished within this autopublishing template. If not checked, the same post can be published several times') ?>
				</div>
			</div>
		</div>

		<div class="parrotposter-block__title"><?php parrotposter_e('Additional settings for VKontakte') ?></div>

		<div class="parrotposter-input__group">
			<label class="parrotposter-input parrotposter-input--checkbox">
				<?php FormHelpers::render_checkbox('parrotposter[extra_vk_from_group]', $data->extra_vk_from_group) ?>
				<span><?php parrotposter_e('Publish a post on behalf of the group') ?></span>
			</label>

			<label class="parrotposter-input parrotposter-input--checkbox">
				<?php FormHelpers::render_checkbox('parrotposter[extra_vk_signed]', $data->extra_vk_signed) ?>
				<span><?php parrotposter_e('Add a signature to the post') ?></span>
			</label>
		</div>

		<div class="parrotposter-input__group">
			<div class="parrotposter-input parrotposter-input--footer parrotposter-input--row">
				<?php if ($data->isset('id')): ?>
				<input class="button button-primary" type="submit" name="submit" value="<?php parrotposter_e('Update') ?>">
				<?php else: ?>
				<input class="button button-primary" type="submit" name="submit" value="<?php parrotposter_e('Create') ?>">
				<?php endif ?>

				<a class="button" href="admin.php?page=parrotposter_scheduler"><?php parrotposter_e('Cancel') ?></a>
			</div>
		</div>
	</div>

</form>

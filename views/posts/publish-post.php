<?php

use parrotposter\PP;
use parrotposter\WpPostHelpers;
use parrotposter\AssetModules;
use parrotposter\FormHelpers;
use parrotposter\Tools;
use parrotposter\fields\Fields;

wp_enqueue_script('jquery-ui-core');
wp_enqueue_script('jquery-ui-sortable');
AssetModules::enqueue(['block', 'input', 'flatpickr', 'publish-post']);

$post_id = (int) $_GET['post_id'];
if (empty($post_id)) {
	parrotposter_e('Error post_id');
	return;
}

$post = get_post($post_id);
$post_title = parrotposter\Tools::clear_text(apply_filters('the_title', $post->post_title));
$raw_content = apply_filters('the_content', $post->post_content);

$post_type = get_post_type($post_id);
$post_meta = get_post_meta($post_id);
$post_status = get_post_status($post_id);
$featuredImage = wp_get_attachment_url(get_post_thumbnail_id($post_id));
$tags = wp_get_post_tags($post_id);
$terms = wp_get_post_terms($post_id, $post_type . '_tag');

$post_link = urldecode(get_permalink($post_id));
$images = [];
if (!empty($featuredImage)) {
	$images[get_post_thumbnail_id($post_id)] = $featuredImage;
}
if (function_exists('wc_get_product')) {
	$p = wc_get_product($post_id);
	if (!empty($p)) {
		$img_url = wp_get_attachment_url($p->get_image_id());
		if (!empty($img_url)) {
			$images[$p->get_image_id()] = $img_url;
		}
		foreach ($p->get_gallery_image_ids() as $img_id) {
			$img_url = wp_get_attachment_url($img_id);
			if (!empty($img_id)) {
				$images[$img_id] = $img_url;
			}
		}

		$post_link = urldecode($p->get_permalink());
	}
}

$contentImages = parrotposter\WpPostHelpers::get_images_from_content($raw_content);
foreach ($contentImages as $img_url) {
	$attachment_id = parrotposter\WpPostHelpers::get_attachment_id($img_url);
	if (empty($attachment_id)) {
		continue;
	}

	$img_url = wp_get_attachment_url($attachment_id);
	if (!empty($img_url)) {
		$images[$attachment_id] = $img_url;
	}
}

$post_text = implode("\n\n", Fields::get_field_values(['title', 'excerpt'], $post));
$post_text = Tools::clear_text($post_text);

$back_url = "post.php?post=$post_id&action=edit";
if (isset($_GET['back_url'])) {
	$back_url = esc_url_raw($_GET['back_url']);
}
?>

<?php PP::include_view('header', [
	'title' => parrotposter__('Publish: %s', esc_attr($post_title)),
	'back_url' => $back_url,
]) ?>
<?php PP::include_view('notice') ?>

<form action="<?php echo esc_url(admin_url('admin-post.php')) ?>" method="post">
	<?php FormHelpers::the_nonce() ?>
	<input type="hidden" name="action" value="parrotposter_publish_post">
	<input type="hidden" name="back_url" value="<?php echo $back_url ?>">
	<input type="hidden" name="parrotposter[post_id]" value="<?php echo $post_id ?>">

	<div class="parrotposter-block parrotposter-block--scheduler-edit">

		<div class="parrotposter-block__title"><?php parrotposter_e('Post data') ?></div>

		<div class="parrotposter-input__group">
			<label class="parrotposter-input parrotposter-input--wide">
				<span><?php parrotposter_e('Post text') ?></span>
				<div class="parrotposter-input__row">
					<textarea name="parrotposter[post_text]"><?php echo $post_text ?></textarea>
				</div>
			</label>

			<label class="parrotposter-input">
				<span><?php parrotposter_e('Link') ?></span>
				<input type="text" name="parrotposter[post_link]" value="<?php echo $post_link ?>">
			</label>

			<?php if (!empty($images)): ?>
			<label class="parrotposter-input">
				<span><?php parrotposter_e('Select post images') ?></span>
				<div class="parrotposter-post-select-images-list">
				<?php foreach ($images as $attachment_id => $img_url): ?>
					<label class="parrotposter-post-select-images-item">
						<input type="checkbox" name="parrotposter[images_ids][]" value="<?php echo esc_attr($attachment_id) ?>" checked>
						<img src="<?php echo esc_url($img_url) ?>" alt="">
						<span class="parrotposter-post-select-images-label"></span>
					</label>
				<?php endforeach ?>
				</div>
				<div class="parrotposter-input__info">
					<?php parrotposter_e('Use drag\'n\'drop to move images. A maximum of 10 images will be loaded') ?>
				</div>
			</label>
			<?php endif ?>
		</div>

		<div class="parrotposter-block__title"><?php parrotposter_e('When to publish') ?></div>

		<div class="parrotposter-input__group">
			<label class="parrotposter-input">
				<select name="parrotposter[when_publish]">
					<option value="now"><?php parrotposter_e('Now') ?></option>
					<?php if (date('c') < get_the_date('c', $post_id)): ?>
					<option value="post_date"><?php parrotposter_e('Post date') ?></option>
					<?php endif ?>
					<option value="delay"><?php parrotposter_e('With a delay') ?></option>
					<option value="custom"><?php parrotposter_e('Enter a specific time') ?></option>
				</select>
			</label>

			<label class="parrotposter-input parrotposter--delay">
				<span><?php parrotposter_e('Publish with a delay of 1 to 10 minutes') ?></span>
				<input type="number" name="parrotposter[publish_delay]" min="1" max="10" step="1"
					value="3">
			</label>

			<label class="parrotposter-input parrotposter--specific-time">
				<span><?php parrotposter_e('Specific time') ?></span>
				<div class="parrotposter-post-pick-row">
					<input id="pick-publication-time" type="text" name="parrotposter[specific_time]">
					<input id="pick-publication-time-fmt" type="text" readonly>
				</div>
			</label>

		</div>

		<div class="parrotposter-block__title"><?php parrotposter_e('Choice of social networks') ?></div>
		<?php PP::include_view('accounts/choice_list', [
			'account_ids' => [],
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

		<div class="parrotposter-block__title"><?php parrotposter_e('Additional settings for VKontakte') ?></div>

		<div class="parrotposter-input__group">
			<label class="parrotposter-input parrotposter-input--checkbox">
				<?php FormHelpers::render_checkbox('parrotposter[extra_vk_from_group]', 1) ?>
				<span><?php parrotposter_e('Publish a post on behalf of the group') ?></span>
			</label>

			<label class="parrotposter-input parrotposter-input--checkbox">
				<?php FormHelpers::render_checkbox('parrotposter[extra_vk_signed]', 0) ?>
				<span><?php parrotposter_e('Add a signature to the post') ?></span>
			</label>
		</div>

		<br>
		<p id="parrotposter-publish-note">
			<?php parrotposter_e('1. You need to fill in the text or choose pictures.') ?> <br>
			<?php parrotposter_e('2. Select at least one social network account') ?>
		</p>

		<div class="parrotposter-input__group">
			<div class="parrotposter-input parrotposter-input--footer parrotposter-input--row">
				<input class="button button-primary" type="submit" name="submit" value="<?php parrotposter_e('Publish') ?>" disabled>
			</div>
		</div>
	</div>
</form>

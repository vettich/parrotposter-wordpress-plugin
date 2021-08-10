<?php

wp_enqueue_style('parrotposter-jquery-ui-css');
wp_enqueue_script('parrotposter-jquery-ui-js');

$post_id = (int) $_GET['post_id'];
if (empty($post_id)) {
	parrotposter_e('Error post_id');
	return;
}

$post = get_post($post_id);
$post_title = parrotposter\Tools::clear_text(apply_filters('the_title', $post->post_title));
$raw_content = apply_filters('the_content', $post->post_content);
$content = parrotposter\Tools::clear_text($raw_content);
$short_content = parrotposter\Tools::clear_text(apply_filters('the_content', $post->post_excerpt));

$post_type = get_post_type($post_id);
$post_meta = get_post_meta($post_id);
$post_status = get_post_status($post_id);
$featuredImage = wp_get_attachment_url(get_post_thumbnail_id($post_id));
$tags = wp_get_post_tags((int) $post_id);
$terms = wp_get_post_terms((int) $post_id, $post_type . '_tag');

$keys = [
	'title' => $post_title,
	'content' => $content,
	'short_content' => $short_content,
];

$images = [];

if (function_exists('wc_get_product')) {
	$p = wc_get_product($post_id);
	if (!empty($p)) {
		$dimension_unit = __(get_option('woocommerce_dimension_unit'), 'woocommerce');
		$weight_unit = __(get_option('woocommerce_weight_unit'), 'woocommerce');
		$keys['height'] = ($p->get_height() ?: 0).$dimension_unit;
		$keys['width'] = ($p->get_width() ?: 0).$dimension_unit;
		$keys['length'] = ($p->get_length() ?: 0).$dimension_unit;
		$keys['weight'] = ($p->get_weight() ?: 0).$weight_unit;
		$keys['price'] = parrotposter\Tools::clear_text(wc_price($p->get_price()));
		$keys['regular_price'] = parrotposter\Tools::clear_text(wc_price($p->get_regular_price()));
		$keys['sale_price'] = parrotposter\Tools::clear_text(wc_price($p->get_sale_price()));
		$keys['price'] = parrotposter\Tools::clear_text(wc_price($p->get_price()));
		$keys['sku'] = $p->get_sku();

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
	}
}

$availableKeys = [];
foreach ($keys as $key => $value) {
	if (!empty($key) && !empty($value)) {
		$availableKeys[$key] = $value;
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

$defaultPostText = [];
if (!empty($keys['title'])) {
	$defaultPostText[] = $keys['title'];
}
if (!empty($keys['short_content'])) {
	$defaultPostText[] = $keys['short_content'];
} elseif (!empty($keys['content'])) {
	$defaultPostText[] = $keys['content'];
}
$defaultPostText = implode("\n\n", $defaultPostText);

$post_link = urldecode(get_permalink($post_id));

$accounts_res = parrotposter\Api::list_accounts();
$accounts = parrotposter\ApiHelpers::retrieve_response($accounts_res, 'accounts');
$accounts = parrotposter\ApiHelpers::fix_accounts_photos($accounts);
?>

<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<div class="wrap parrotposter-wrap">
	<h1><?php parrotposter_e('Publish:') ?> <?php echo $post_title ?></h1>

	<?php if (isset($_GET['parrotposter_error_msg'])): ?>
		<div class="notice notice-error">
			<p><?php echo $_GET['parrotposter_error_msg'] ?></p>
		</div>
	<?php endif ?>

<!-- 	<div class="parrotposter-available-keys">
		<h2><?php parrotposter_e('Available keys') ?></h2>
		<ul>
		<?php foreach ($availableKeys as $key => $value): ?>
			<?php $title = strlen($value) > 200 ? esc_html($value) : '' ?>
			<li title="<?php echo $title ?>">
				<?php echo $key.': '.substr($value, 0, 200) ?>
			</li>
		<?php endforeach ?>
		</ul>
	</div> -->

	<form action="<?php echo esc_url(admin_url('admin-post.php')) ?>" method="post">
		<?php parrotposter\FormHelpers::the_nonce() ?>
		<input type="hidden" name="action" value="parrotposter_publish_post">
		<input type="hidden" name="back_url" value="<?php echo "post.php?post=$post_id&action=edit" ?>">
		<input type="hidden" name="parrotposter[post_id]" value="<?php echo $post_id ?>">

		<label>
			<h2><?php parrotposter_e('Post text') ?></h2>
			<textarea class="parrotposter-post-textarea" name="parrotposter[text]"><?php echo $defaultPostText ?></textarea>
		</label>

		<label>
			<h2><?php parrotposter_e('Post link') ?></h2>
			<input class="parrotposter-post-input" type="text" name="parrotposter[link]" value="<?php echo $post_link ?>">
		</label>

		<?php if (!empty($images)): ?>
		<h2><?php parrotposter_e('Select post images') ?></h2>
		<div class="parrotposter-post-select-images-list">
		<?php foreach ($images as $attachment_id => $img_url): ?>
			<label class="parrotposter-post-select-images-item">
				<input type="checkbox" name="parrotposter[images_ids][]" value="<?php echo $attachment_id ?>" checked>
				<img src="<?php echo $img_url ?>" alt="">
				<span class="parrotposter-post-select-images-label"></span>
			</label>
		<?php endforeach ?>
		</div>
		<?php endif ?>

		<h2><?php parrotposter_e('Select publication time') ?></h2>
		<label class="parrotposter-post-select-time">
			<input type="radio" name="parrotposter[publish_at]" value="<?php echo date('c') ?>" checked>
			<span><?php parrotposter_e('Now') ?></span>
		</label>

		<?php if (date('c') < get_the_date('c', $post_id)): ?>
		<label class="parrotposter-post-select-time">
			<input type="radio" name="parrotposter[publish_at]" value="<?php echo get_the_date('c', $post_id) ?>" checked>
			<span>
				<?php parrotposter_e('Post time') ?>:
				<?php echo get_the_date('d M, Y, H:i:s', $post_id) ?>
			</span>
		</label>
		<?php endif ?>

		<label class="parrotposter-post-select-time parrotposter-post-select-time-another">
			<input type="radio" name="parrotposter[publish_at]" value="">
			<span><?php parrotposter_e('Enter another time') ?></span>
			<label>
				<span><?php parrotposter_e('Enter time') ?></span>
				<input id="pick-publication-time" type="text" name="parrotposter[publish_at_2]">
			</label>
		</label>

		<h2><?php parrotposter_e('Select social networks') ?></h2>
		<div class="parrotposter-accounts-list">
		<?php foreach ($accounts as $account): ?>
			<label class="parrotposter-accounts-item">
				<input type="checkbox" name="parrotposter[accounts][]" value="<?php echo $account['id'] ?>">
				<div class="parrotposter-accounts-content">
					<div class="parrotposter-accounts-photo">
						<img src="<?php echo $account['photo'] ?>" alt="">
						<div class="parrotposter-accounts-type">
							<img src="<?php echo ParrotPoster::asset('images/'.$account['type'].'.png') ?>" alt="">
						</div>
					</div>
					<div class="parrotposter-accounts-name">
						<?php echo $account['name'] ?>
					</div>
				</div>
			</label>
		<?php endforeach ?>
		</div>

		<br>
		<p id="parrotposter-publish-note">
			<?php parrotposter_e('1. You need to fill in the text or choose pictures.') ?> <br>
			<?php parrotposter_e('2. Select at least one social network account') ?>
		</p>
		<input class="button button-primary" type="submit" name="submit" value="<?php parrotposter_e('Publish') ?>" disabled>
	</form>

</div>

<script>
	flatpickr('#pick-publication-time', {
		enableTime: true,
		time_24hr: true,
		minDate: new Date(),
	})

	jQuery(function($) {
		$('textarea.parrotposter-post-textarea').change(function () {
			checkPublishBtn()
		})
		$('textarea.parrotposter-post-textarea').keyup(function () {
			checkPublishBtn()
		})
		$('.parrotposter-post-select-images-list input[type=checkbox]').change(function () {
			checkPublishBtn()
		})
		$('.parrotposter-accounts-item input[type=checkbox]').change(function () {
			checkPublishBtn()
		})

		function checkPublishBtn() {
			const textExists = $('textarea.parrotposter-post-textarea').val().trim().length
			const imagesSelected = $('.parrotposter-post-select-images-list input[type=checkbox]:checked').length
			const accountsSelected = $('.parrotposter-accounts-item input[type=checkbox]:checked').length

			const disabled = (!textExists && !imagesSelected) || !accountsSelected
			$('input[name=submit]').prop('disabled', disabled)
			$('#parrotposter-publish-note').css('display', disabled ? 'block' : 'none')
		}

		checkPublishBtn()

		$('.parrotposter-post-select-images-list').sortable()
	})

</script>

<!--
<pre>
	<?php print_r([
		'$accounts' => $accounts,
	]) ?>
</pre>
 -->

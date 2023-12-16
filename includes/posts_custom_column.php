<?php

use parrotposter\AssetModules;
use parrotposter\PP;
use parrotposter\WpPostHelpers;

add_action('admin_init', function () {
	$post_types = WpPostHelpers::get_post_types();
	foreach ($post_types as $type) {
		add_filter("manage_{$type}_posts_columns", "parrotposter_manage_posts_columns");
		add_action("manage_{$type}_posts_custom_column", "parrotposter_manage_posts_custom_column");
	}
});

function parrotposter_manage_posts_columns($columns)
{
	$pp_cols = [
		'parrotposter_col' => '<span class="parrotposter-logo" title="ParrotPoster">ParrotPoster</span>',
	];
	return $columns + $pp_cols;
}

function parrotposter_manage_posts_custom_column($col_name)
{
	if ($col_name !== 'parrotposter_col') {
		return;
	}

	$link = sprintf(
		'admin.php?page=parrotposter_posts&view=publish-post&post_id=%s&back_url=%s',
		get_the_ID(),
		$_SERVER['REQUEST_URI'],
	);

	printf(
		'<a class="parrotposter-publish" title="%s" href="%s" data-wp-post-id="%s"></a>',
		__('Publish to social networks', 'parrotposter'),
		$link,
		get_the_ID(),
	);
}

add_action('admin_print_footer_scripts-edit.php', 'parrotposter_print_custom_columns_styles');
function parrotposter_print_custom_columns_styles()
{
?>
	<style>
		.column-parrotposter_col {
			width: 10%
		}

		.parrotposter-logo {
			display: block;
			height: 20px;
			width: 20px;
			margin: 0 auto;
			font-size: 0;
		}

		.parrotposter-logo:before {
			content: "";
			display: block;
			background-image: url(<?php echo PP::asset('images/icon.png') ?>);
			background-size: cover;
			background-position: center;
			background-repeat: no-repeat;
			width: 20px;
			height: 20px;
		}

		.parrotposter-publish {
			display: block;
			height: 20px;
			width: 20px;
			margin: 0 auto;
			font-size: 0;
			cursor: pointer;
		}

		.parrotposter-publish:before {
			content: "";
			display: block;
			background-image: url(<?php echo PP::asset('images/share.svg') ?>);
			background-size: contain;
			background-position: center;
			background-repeat: no-repeat;
			width: 20px;
			height: 20px;
		}
	</style>
<?php
}

add_action('admin_footer-edit.php', 'parrotposter_show_view_publish_via_template');
function parrotposter_show_view_publish_via_template()
{
	PP::include_view('posts/publish-via-template');
}

add_action('admin_enqueue_scripts', 'parrotposter_assets_for_view_publish_via_template', 100);
function parrotposter_assets_for_view_publish_via_template()
{
	AssetModules::enqueue(['modal', 'common', 'loading', 'publish-via-template']);
}

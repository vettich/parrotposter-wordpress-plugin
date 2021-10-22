<?php

use parrotposter\FormHelpers;
use parrotposter\Options;

if (!current_user_can('manage_options')) {
	return;
}

$subpage = '';
if (isset($_GET['subpage'])) {
	$subpage = sanitize_text_field($_GET['subpage']);
}

switch ($subpage) {
case 'reset_password':
	ParrotPoster::include_view('parrotposter-reset-password-part');
	return;

case 'tariff_success_payed':
case 'tariff_fail_payed':
	ParrotPoster::include_view('parrotposter-tariff-payed');
	return;

case 'publish_post':
	ParrotPoster::include_view('parrotposter-publish-post-part');
	return;
}

if (empty(Options::user_id())) {
	ParrotPoster::include_view('parrotposter-auth-parts');
	return;
}

ParrotPoster::include_view('parrotposter-user-parts');

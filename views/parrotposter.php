<?php

use parrotposter\FormHelpers;
use parrotposter\Options;

if (!current_user_can('manage_options')) {
	return;
}

if ($_GET['subpage'] == 'reset_password') {
	ParrotPoster::include_view('parrotposter-reset-password-part');
	return;
}

if ($_GET['subpage'] == 'tariff_success_payed' || $_GET['subpage'] == 'tariff_fail_payed') {
	ParrotPoster::include_view('parrotposter-tariff-payed');
	return;
}

if (empty(Options::user_id())) {
	ParrotPoster::include_view('parrotposter-auth-parts');
	return;
}

ParrotPoster::include_view('parrotposter-user-parts');


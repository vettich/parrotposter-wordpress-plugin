<?php

defined('ABSPATH') || exit;

use parrotposter\AssetModules;
use parrotposter\PP;

if (!current_user_can('manage_options')) {
	return;
}

AssetModules::enqueue(['modal', 'loading', 'local-queue-admin']);
PP::include_view('local-queue/notice');
PP::include_view('local-queue/modal');

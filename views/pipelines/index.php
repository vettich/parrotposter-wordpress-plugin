<?php

defined('ABSPATH') || die;

use parrotposter\PP;
use parrotposter\View;
use parrotposter\AssetModules;

AssetModules::enqueue(['block']);

PP::include_view('header', ['title' => __('Pipelines', 'parrotposter')]);
PP::include_view('notice');
PP::include_view('partials/migration-banner-pipeline-active');

View::embed_front('pipelines');

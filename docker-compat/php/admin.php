<?php
require_once ABSPATH . 'wp-admin/includes/admin.php';
$_GET['page'] = 'parrotposter';
ob_start();
parrotposter\PP::get_instance()->admin_page();
$html = ob_get_clean();
if (!is_string($html) || trim($html) === '') {
	fwrite(STDERR, "compat-admin: empty HTML\n");
	exit(1);
}
echo "admin_ok\n";

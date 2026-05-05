<?php

namespace parrotposter;

defined('ABSPATH') || exit;

class View
{
	/**
	 * Origin'ы фронта PP для postMessage.
	 *
	 * @return string[]
	 */
	private static function allowed_iframe_origins(): array
	{
		$seen = [];
		foreach (Env::domains() as $d) {
			if (!is_string($d) || $d === '') {
				continue;
			}
			$p = @parse_url(rtrim($d, '/'));
			if (empty($p['scheme']) || empty($p['host'])) {
				continue;
			}
			$origin = $p['scheme'] . '://' . $p['host'];
			if (!empty($p['port'])) {
				$origin .= ':' . $p['port'];
			}
			$seen[$origin] = true;
		}

		return array_keys($seen);
	}

	/**
	 * JSON-конфиг для ParrotPoster.initIframe().
	 */
	private static function iframe_config(string $endpoint): string
	{
		$session = Api::issue_session_key();
		$token = '';
		if (empty($session['error']) && !empty($session['token'])) {
			$token = $session['token'];
		}
		$pp_unavailable = !empty($session['error']['code'])
			&& (int) $session['error']['code'] === Api::SERVER_UNAVAILABLE;

		$path = rtrim(Env::front_base_uri(), '/') . '/' . ltrim($endpoint, '/');
		$lang = substr(get_user_locale(), 0, 2);
		$site_page = home_url(isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '');

		return wp_json_encode([
			'container' => '.pp-iframe-container',
			'endpoints' => Env::domains(),
			'path' => '/' . ltrim($path, '/'),
			'pingPath' => Env::available_check_uri(),
			'token' => $token,
			'lang' => $lang,
			'sitePage' => $site_page,
			'moduleReadOnly' => 0,
			'pp_unavailable' => $pp_unavailable,
			'debug' => Env::iframe_debug(),
			'messages' => [
				'loading' => __('Loading…', 'parrotposter'),
				'reload' => __('Reload', 'parrotposter'),
				'unavailable' => wp_kses_post(
					'<div class="parrotposter-iframe-load-error"><p><strong>'
					. esc_html__('ParrotPoster is temporarily unavailable.', 'parrotposter')
					. '</strong></p><p>'
					. esc_html__('Please try again later.', 'parrotposter')
					. '</p></div>'
				),
				'loadError' => '',
				'loadErrorCsp' => '',
				'reconnecting' => '',
			],
		], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
	}

	public static function embed_front($endpoint)
	{
		wp_enqueue_style('parrotposter-view-embed');
		wp_enqueue_script('parrotposter-iframe');
		wp_enqueue_script('parrotposter-view-embed');

		$iframe_init = json_decode(self::iframe_config($endpoint), true);
		if (!is_array($iframe_init)) {
			$iframe_init = [];
		}

		wp_localize_script(
			'parrotposter-view-embed',
			'ParrotPosterViewEmbed',
			[
				'allowedOrigins' => self::allowed_iframe_origins(),
				'authNonce' => wp_create_nonce('parrotposter_nonce'),
				'menuItems' => Menu::get_items(),
				'adminPostUrl' => admin_url('admin-post.php'),
				'profilePageUrl' => admin_url('admin.php?page=parrotposter_profile'),
				'iframeInit' => $iframe_init,
			]
		);
?>
		<div class="pp-iframe-container"></div>
<?php
	}
}

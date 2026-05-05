/**
 * Заглушка: объект ParrotPosterAdmin задаётся через wp_localize_script в PP::register_scripts.
 * Скрипт нужен как handle для локализации и подключается на экранах плагина до модульных JS.
 */
(function () {
	if (typeof window.ParrotPosterAdmin === 'undefined') {
		window.ParrotPosterAdmin = { ajaxNonce: '' };
	}
})();

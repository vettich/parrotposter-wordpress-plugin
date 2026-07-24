(function ($) {
	'use strict';

	function ajaxPost(action, data) {
		data = data || {};
		data.action = action;
		data.nonce = (window.ParrotPosterAdmin && ParrotPosterAdmin.ajaxNonce) || '';
		return $.post(ajaxurl, data);
	}

	function showNotice(message, isError) {
		var cls = isError ? 'notice-error' : 'notice-success';
		var $notice = $('<div class="notice ' + cls + ' is-dismissible"><p></p></div>');
		$notice.find('p').text(message);
		$('.pp-migration-banner').first().before($notice);
	}

	$(document).on('click', '.pp-migration-start', function (e) {
		e.preventDefault();
		var mode = $(this).data('mode');
		var $banner = $(this).closest('.pp-migration-banner');
		var $buttons = $banner.find('button, .button');
		$buttons.prop('disabled', true);

		var payload = { mode: mode || 'import' };
		if (mode === 'import') {
			var ids = [];
			$banner.find('input[name="pp_migration_config[]"]:checked').each(function () {
				ids.push($(this).val());
			});
			payload.config_ids = ids;
		}

		ajaxPost('pp_migrate_to_pipeline', payload)
			.done(function (res) {
				if (res && res.success) {
					window.location.href = 'admin.php?page=parrotposter_pipelines';
					return;
				}
				var msg = (res && res.error) ? res.error : 'Migration failed';
				showNotice(msg, true);
				$buttons.prop('disabled', false);
			})
			.fail(function () {
				showNotice('Migration request failed', true);
				$buttons.prop('disabled', false);
			});
	});

	$(document).on('click', '.pp-migration-revert', function (e) {
		e.preventDefault();
		if (!window.confirm($(this).data('confirm') || 'Revert to legacy scheduler?')) {
			return;
		}
		var $btn = $(this);
		$btn.prop('disabled', true);
		ajaxPost('pp_revert_migration', {})
			.done(function (res) {
				if (res && res.success) {
					window.location.href = 'admin.php?page=parrotposter_scheduler';
					return;
				}
				var msg = (res && res.error) ? res.error : 'Revert failed';
				showNotice(msg, true);
				$btn.prop('disabled', false);
			})
			.fail(function () {
				showNotice('Revert request failed', true);
				$btn.prop('disabled', false);
			});
	});

	$(document).on('click', '.pp-migration-dismiss', function (e) {
		e.preventDefault();
		if (!window.confirm($(this).data('confirm') || 'You can restore this notice in Settings → Pipelines.')) {
			return;
		}
		var $btn = $(this);
		var $banner = $btn.closest('.pp-migration-banner');
		$btn.prop('disabled', true);
		ajaxPost('pp_dismiss_migration_banner', {})
			.done(function (res) {
				if (res && res.success) {
					$banner.remove();
					return;
				}
				var msg = (res && res.error) ? res.error : 'Request failed';
				showNotice(msg, true);
				$btn.prop('disabled', false);
			})
			.fail(function () {
				showNotice('Request failed', true);
				$btn.prop('disabled', false);
			});
	});
})(jQuery);

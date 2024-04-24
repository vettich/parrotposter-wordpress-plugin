jQuery(function($) {
	let wp_post_id = 0;

	$('a.parrotposter-publish, a.parrotposter-meta-box__publish-btn').click(function(e) {
		e.preventDefault()

		$('#parrotposter-publish-manually-link').attr('href', e.currentTarget.href)
		$('input[name=parrotposter_template_id]').prop('checked', false);
		$('.parrotposter-modal__template-item').removeClass('parrotposter-modal__template-item--selected');
		$('.parrotposter-modal__template-post-already-exist').css('display', 'none');
		$('#parrotposter-wait-loading').css('display', 'inline-block');
		publishBtn.prop('disabled', true);
		publishBtn.removeClass('parrotposter-loading');

		wp_post_id = $(e.currentTarget).data('wp-post-id');

		parrotposter_modal_open('#parrotposter-publish-via-template')

		const template_ids = [];
		$('input[name=parrotposter_template_id]').each(function() {
			template_ids.push($(this).val());
		});

		const nonce = $('input[name="parrotposter[nonce]"]').val()
		$.post(ajaxurl, {
			'action': 'parrotposter_has_post_duplicates',
			'ajaxrequest': true,
			'parrotposter': { nonce, template_ids, wp_post_id }
		}, function(resp) {
			resp = JSON.parse(resp);
			if (resp.error) {
				return
			}

			Object.keys(resp.data).forEach(function(template_id) {
				if (!resp.data[template_id]) {
					return;
				}

				const existLabel = $(`label[for=pp-template-${template_id}] .parrotposter-modal__template-post-already-exist`);
				existLabel.css('display', 'block');

				let text = existLabel.data('orig');
				if (!text || !text.length) {
					text = existLabel.text();
					existLabel.data('orig', text);
				}
				existLabel.text(text.replace(':time:', new Date(resp.data[template_id]).toLocaleString()));
			})
		})
			.always(function() {
				$('#parrotposter-wait-loading').css('display', 'none');
			})
	})

	$('input[name=parrotposter_template_id]').change(function(e) {
		$('.parrotposter-modal__template-item').removeClass('parrotposter-modal__template-item--selected');
		$(e.currentTarget).closest('.parrotposter-modal__template-item').addClass('parrotposter-modal__template-item--selected');
	})

	$('.parrotposter-modal__template-item').click(function() {
		publishBtn.prop('disabled', false);
	})

	const publishBtn = $('#parrotposter-publish-via-template-btn');
	publishBtn.click(function(e) {
		e.preventDefault()
		publishBtn.addClass('parrotposter-loading');

		const template_id = $('input[name=parrotposter_template_id]:checked').val();

		const nonce = $('input[name="parrotposter[nonce]"]').val()
		$.post(ajaxurl, {
			'action': 'parrotposter_publish_post_via_template',
			'ajaxrequest': true,
			'parrotposter': { nonce, template_id, wp_post_id }
		}, function(data) {
			data = JSON.parse(data);
			if (data.error) {
				return
			}
			parrotposter_modal_close_all()
			parrotposter_modal_open('#parrotposter-publish-via-template-success')
		})
			.fail(function() {
				parrotposter_modal_close_all()
				parrotposter_modal_open('#parrotposter-publish-via-template-fail')
			})
			.always(function() {
				publishBtn.removeClass('parrotposter-loading');
			})
	})
})

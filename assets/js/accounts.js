jQuery(function($) {
	function origin_title() {
		let title = $('#parrotposter-confirm .parrotposter-modal__title').data('title')
		if (!title) {
			title = $('#parrotposter-confirm .parrotposter-modal__title').text()
			$('#parrotposter-confirm .parrotposter-modal__title').data('title', title)
		}
		return title
	}

	$('.parrotposter-accounts__delete').click(function(event) {
		const elem = event.target
		const rootItem = $(elem).closest('.parrotposter-accounts__item')
		const id = rootItem.data('id')
		const name = rootItem.find('.parrotposter-accounts__name').text()

		let title = origin_title()
		title = title.replace('#account_name#', name.trim())
		$('#parrotposter-confirm .parrotposter-modal__title').text(title)
		$('#parrotposter-confirm').data('id', id)
		parrotposter_modal_open('#parrotposter-confirm')
	})

	$('#parrotposter-confirm .button-primary').click(function(event) {
		event.preventDefault()
		parrotposter_modal_close('#parrotposter-confirm')
		const elem = $(event.target)
		const id = $('#parrotposter-confirm').data('id')
		const rootItem = $('.parrotposter-accounts__item[data-id="' + id + '"]')
		rootItem.addClass('parrotposter-loading');
		$.post(ajaxurl, {
			'action': 'parrotposter_api_delete_account',
			'parrotposter': {
				'account_id': id,
			}
		}, function (data) {
			data = JSON.parse(data)
			if (!data.error) {
				if ($('.parrotposter-accounts__item').length <= 1) {
					location.reload()
				} else {
					rootItem.removeClass('parrotposter-loading');
					rootItem.remove()
					update()
				}
			}
		})
	})

	function update() {
		$.post(ajaxurl, {
			'action': 'parrotposter_api_get_me',
		}, function (data) {
			data = JSON.parse(data)
			if (data.error) {
				return
			}

			$('.parrotposter-accounts__badge-txt').text(data.accounts_badge_txt)
			if (data.connect_btn_disabled) {
				$('.parrotposter-accounts-connect__btn').addClass('disabled')
				$('.parrotposter-accounts__badge').addClass('over')
			} else {
				$('.parrotposter-accounts-connect__btn').removeClass('disabled')
				$('.parrotposter-accounts__badge').removeClass('over')
			}
		})
	}

	$('.parrotposter-accounts__item--input input[type=checkbox]').change(check_inputs)
	function check_inputs() {
		$('.parrotposter-accounts__item--input input[type=checkbox]:not(:checked)')
			.closest('.parrotposter-accounts__item--input')
			.removeClass('parrotposter-accounts__item--selected')
		$('.parrotposter-accounts__item--input input[type=checkbox]:checked')
			.closest('.parrotposter-accounts__item--input')
			.addClass('parrotposter-accounts__item--selected')
	}
	check_inputs()
})

jQuery(function($) {
	$('.parrotposter-accounts__delete').click(function(event) {
		const elem = event.target
		const rootItem = $(elem).closest('.parrotposter-accounts__item')
		const id = rootItem.data('id')
		rootItem.addClass('loading');
		$.post(ajaxurl, {
			'action': 'parrotposter_api_delete_account',
			'parrotposter': {
				'account_id': id,
			}
		}, function (data) {
			rootItem.removeClass('loading');
			data = JSON.parse(data)
			if (!data.error) {
				rootItem.remove()
				update()
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
})

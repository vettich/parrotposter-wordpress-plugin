jQuery(function($) {
	function connect_by_url(type, elem) {
		if ($(elem).hasClass('disabled')) {
			return
		}
		$(elem).addClass('loading')
		$.post(ajaxurl, {
			'action': 'parrotposter_api_get_connect_url',
			'parrotposter': {
				'type': type,
				'callback_url': location.href,
			}
		}, function (data) {
			data = JSON.parse(data)
			if (!data.response || !data.response.url) {
				$(elem).removeClass('loading')
				return
			}

			location = data.response.url
		})
	}

	$('.parrotposter-accounts-connect__btn.vk').click(function(event) {
		connect_by_url('vk', event.target)
	})
	$('.parrotposter-accounts-connect__btn.fb').click(function(event) {
		connect_by_url('fb', event.target)
	})
	$('.parrotposter-accounts-connect__btn.ok').click(function(event) {
		connect_by_url('ok', event.target)
	})
	$('.parrotposter-accounts-connect__btn.insta').click(function(event) {
		$('#parrotposter-connect-insta .parrotposter-notice__error').hide()
		$('#parrotposter-connect-insta .parrotposter-modal__container').removeClass('parrotposter-loading')
		parrotposter_modal_open('#parrotposter-connect-insta')
	})
	$('.parrotposter-accounts-connect__btn.tg').click(function(event) {
		$('#parrotposter-connect-tg .parrotposter-notice__error').hide()
		$('#parrotposter-connect-tg .parrotposter-modal__container').removeClass('parrotposter-loading')
		parrotposter_modal_open('#parrotposter-connect-tg')
	})

	$('#parrotposter-connect-insta .button').click(function(event) {
		event.preventDefault()
		const container = $(event.target).closest('.parrotposter-modal__container')
		container.addClass('parrotposter-loading')
		$('#parrotposter-connect-insta .parrotposter-notice__error').hide()
		const fields = {
			'type': 'insta',
			'username': $('#parrotposter-connect-insta input[name="parrotposter[username]"]').val(),
			'password': $('#parrotposter-connect-insta input[name="parrotposter[password]"]').val(),
			'proxy': $('#parrotposter-connect-insta input[name="parrotposter[proxy]"]').val(),
			'code': $('#parrotposter-connect-insta input[name="parrotposter[code]"]').val(),
		}
		$.post(ajaxurl, {
			'action': 'parrotposter_api_connect',
			'parrotposter': fields,
		}, function (data) {
			data = JSON.parse(data)
			if (data.error) {
				container.removeClass('parrotposter-loading')
				$('#parrotposter-connect-insta .parrotposter-notice__error').show()
				$('#parrotposter-connect-insta .parrotposter-notice__error p').text(data.error.msg || data.error)
				return
			}

			if (!data.response) {
				container.removeClass('parrotposter-loading')
				return
			}

			if (data.response.need_challenge) {
				container.removeClass('parrotposter-loading')
				$('#parrotposter-connect-insta input[name="parrotposter[code]"]').closest('parrotposter-input').show()
				$('#parrotposter-connect-insta .parrotposter-notice__error').show()
				$('#parrotposter-connect-insta .parrotposter-notice__error p').text(data.need_challenge_txt)
				return
			}

			location.reload()
		})
	})

	$('#parrotposter-connect-tg .button').click(function(event) {
		event.preventDefault()
		const container = $(event.target).closest('.parrotposter-modal__container')
		container.addClass('parrotposter-loading')
		$('#parrotposter-connect-tg .parrotposter-notice__error').hide()
		const fields = {
			'type': 'tg',
			'username': $('#parrotposter-connect-tg input[name="parrotposter[username]"]').val(),
			'bot_token': $('#parrotposter-connect-tg input[name="parrotposter[bot_token]"]').val(),
		}
		$.post(ajaxurl, {
			'action': 'parrotposter_api_connect',
			'parrotposter': fields,
		}, function (data) {
			data = JSON.parse(data)
			if (data.error) {
				container.removeClass('parrotposter-loading')
				$('#parrotposter-connect-tg .parrotposter-notice__error').show()
				$('#parrotposter-connect-tg .parrotposter-notice__error p').text(data.error.msg || data.error)
				return
			}

			if (!data.response) {
				container.removeClass('parrotposter-loading')
				return
			}

			if (data.response.need_challenge) {
				container.removeClass('parrotposter-loading')
				$('#parrotposter-connect-tg input[name="parrotposter[code]"]').closest('parrotposter-input').show()
				$('#parrotposter-connect-tg .parrotposter-notice__error').show()
				$('#parrotposter-connect-tg .parrotposter-notice__error p').text(data.need_challenge_txt)
				return
			}

			location.reload()
		})
	})
})


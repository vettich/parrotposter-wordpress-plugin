function parrotposter_autoposting_delete(e, id) {
	e.preventDefault()
	const $ = jQuery
	const name = $($(e.target).closest('td').find('a').get(0)).text()
	const modal = $('#parrotposter-autoposting-delete-confirm')
	let title = modal.find('.parrotposter-modal__title').data('title')
	title = title.replace('{autoposting_name}', name)
	modal.find('.parrotposter-modal__title').text(title)
	modal.data('id', id)
	parrotposter_modal_open(modal)
}

function parrotposter_autoposting_delete_confirm(e) {
	e.preventDefault()
	const $ = jQuery
	const modal = $(e.target).closest('.parrotposter-modal')
	const id = modal.data('id')

	$(e.target).addClass('parrotposter-loading');

	let hash = location.hash || '#'
	let url = location.href.replace(hash, '') + '&action=delete&id=' + id
	console.log(url)
	location = url
}

function parrotposter_autoposting_enable(e, id) {
	const $ = jQuery
	const elem = $(e.target)
	const nonce = $('input[name="parrotposter[nonce]"]').val()
	$.post(ajaxurl, {
		'action': 'parrotposter_autoposting_enable',
		'ajaxrequest': true,
		'parrotposter': {
			'nonce': nonce,
			'id': id,
			'enable': elem.is(':checked') ? 1 : 0,
		}
	}, function (data) {
		console.log(data)
	})
}

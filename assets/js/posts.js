jQuery(function($) {
	$('.parrotposter-post-delete').click(function(e) {
		e.preventDefault()
		const elem = $(e.target)
		const id = elem.data('id')

		$('#parrotposter-post-delete-confirm').data('id', id)
		parrotposter_modal_open('#parrotposter-post-delete-confirm')
	})

	$('#parrotposter-post-delete-confirm .button-primary').click(function(e) {
		e.preventDefault()
		parrotposter_modal_close('#parrotposter-post-delete-confirm')
		const elem = $(e.target)
		const id = $('#parrotposter-post-delete-confirm').data('id')
		const rootItem = $('.parrotposter-post-delete[data-id="' + id + '"]').closest('td')
		rootItem.addClass('parrotposter-loading');

		let hash = location.hash || '#'
		let url = location.href.replace(hash, '') + '&action=delete&id=' + id
		console.log(url)
		location = url
	})

})

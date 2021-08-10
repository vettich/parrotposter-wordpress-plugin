jQuery(function($) {
	// bindings to close modal
	$('.parrotposter-modal .parrotposter-js-close').click(function(event) {
		event.preventDefault()
		$(event.target).closest('.parrotposter-modal').hide()
	})
	$('.parrotposter-modal').click(function(event) {
		const el = $(event.target)
		if (el.is('.parrotposter-modal')) {
			el.hide()
		}
	})
})

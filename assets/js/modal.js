jQuery(function($) {
	// bindings to close modal
	//
	// close when click to close buttons
	$('.parrotposter-modal .parrotposter-js-close, .parrotposter-modal__close').click(function(event) {
		event.preventDefault()
		$(event.target).closest('.parrotposter-modal').hide()
	})

	// close when press escape key (code: 27)
	$(document).keyup(function(event) {
		if (event.keyCode === 27) {
			$('.parrotposter-modal').hide();
		}
	})

	// close when click outside modal container
	$('.parrotposter-modal').click(function(event) {
		const el = $(event.target)
		if (el.is('.parrotposter-modal')) {
			el.hide()
		}
	})
})

function parrotposter_modal_open(selector) {
	jQuery(selector).show()
}

function parrotposter_modal_close(selector) {
	jQuery(selector).hide()
}


jQuery(function($) {
	// bindings to close modal
	//
	// close when click to close buttons
	$('.parrotposter-modal .parrotposter-js-close, .parrotposter-modal__close').click(function(event) {
		event.preventDefault()
		parrotposter_modal_close($(event.target).closest('.parrotposter-modal'))
	})

	// close when press escape key (code: 27)
	$(document).keyup(function(event) {
		if (event.keyCode === 27) {
			parrotposter_modal_close('.parrotposter-modal')
		}
	})

	// close when click outside modal container
	$('.parrotposter-modal').click(function(event) {
		const el = $(event.target)
		if (el.is('.parrotposter-modal')) {
			parrotposter_modal_close(el)
		}
	})
})

function parrotposter_modal_open(selector) {
	jQuery(selector).addClass('parrotposter-modal--open')
	jQuery(document.body).addClass('parrotposter-modal__body-overflow')
}

function parrotposter_modal_close(selector) {
	jQuery(selector).removeClass('parrotposter-modal--open')
	jQuery(document.body).removeClass('parrotposter-modal__body-overflow')
}


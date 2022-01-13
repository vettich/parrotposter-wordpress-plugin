jQuery(function($) {
	// bindings to close modal
	parrotposter_modal_on_close('.parrotposter-modal')

	// close when press escape key (code: 27)
	$(document).keyup(function(event) {
		if (event.keyCode === 27) {
			parrotposter_modal_close('.parrotposter-modal')
		}
	})

	$('.parrotposter-modal').appendTo('body')
})

const parrotposter_modal_stack = [];

function parrotposter_modal_open(selector) {
	parrotposter_modal_push_opens_and_close()
	jQuery(selector).addClass('parrotposter-modal--open')
	jQuery(document.body).addClass('parrotposter-modal__body-overflow')
}

function parrotposter_modal_close(selector) {
	jQuery(selector).removeClass('parrotposter-modal--open')
	const prev = parrotposter_modal_stack.pop()
	if (prev) {
		prev.addClass('parrotposter-modal--open')
	} else {
		jQuery(document.body).removeClass('parrotposter-modal__body-overflow')
	}
}

function parrotposter_modal_close_all() {
	jQuery('.parrotposter-modal').removeClass('parrotposter-modal--open')
	jQuery(document.body).removeClass('parrotposter-modal__body-overflow')
}

function parrotposter_modal_push_opens_and_close() {
	const modals = jQuery('.parrotposter-modal--open')
	if (modals.length) {
		parrotposter_modal_stack.push(modals)
		modals.removeClass('parrotposter-modal--open')
		jQuery(document.body).removeClass('parrotposter-modal__body-overflow')
	}
}

function parrotposter_modal_on_close(selector) {
	const elem = jQuery(selector)

	// close when click outside modal container
	elem.click(function(event) {
		const el = jQuery(event.target)
		if (el.is('.parrotposter-modal')) {
			parrotposter_modal_close(el)
		}
	})

	// close when click to close buttons
	elem.find('.parrotposter-js-close, .parrotposter-modal__close').click(function(event) {
		event.preventDefault()
		parrotposter_modal_close(jQuery(event.target).closest('.parrotposter-modal'))
	})
}

jQuery(function($) {
	$('.parrotposter-help__title').click(function (e) {
		e.preventDefault()
		const item = $(e.target).closest('.parrotposter-help__item')
		item.toggleClass('parrotposter-help__item--open')
	})
})

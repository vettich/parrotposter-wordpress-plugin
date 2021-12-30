function parrotposterCopyToClipboard(text) {
	let temp = document.createElement('textarea')
	temp.value = text
	document.body.appendChild(temp)
	temp.select()
	document.execCommand('copy')
	document.body.removeChild(temp)
}

jQuery(function($) {
	$('.parrotposter-copy').click(function(event) {
		event.preventDefault()
		const elem = $(event.target)

		parrotposterCopyToClipboard(elem.text())

		let tooltip = $(
			'<div class="parrotposter-copy__tooltip">' +
				wp.i18n.__('Copied!', 'parrotposter') +
			'</div>')
		elem.append(tooltip)
		setTimeout(function() {
			tooltip.remove()
		}, 2000)
	})
})

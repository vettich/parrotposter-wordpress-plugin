jQuery(function($) {
	const fp = flatpickr('#pick-publication-time', {
		enableTime: true,
		time_24hr: true,
		minDate: new Date(),
		dateFormat: 'Z',
		position: 'above center',
		onChange: function(selectedDates) {
			console.log(selectedDates);
			$('#pick-publication-time-fmt').val(selectedDates[0].toLocaleString())
		},
	})

	$('#pick-publication-time-fmt').click(function(e) {
		fp.toggle()
	})

	function checkPublishTime() {
		const value = $('select[name="parrotposter[when_publish]"]').val()
		if (value == 'custom') {
			$('.parrotposter--specific-time').show()
		} else {
			$('.parrotposter--specific-time').hide()
		}

		if (value == 'delay') {
			$('.parrotposter--delay').show()
		} else {
			$('.parrotposter--delay').hide()
		}
	}

	checkPublishTime()
	$('select[name="parrotposter[when_publish]"]').change(checkPublishTime)

	function checkPublishBtn() {
		const textExists = $('textarea[name="parrotposter[post_text]"]').val().trim().length
		const imagesSelected = $('input[name="parrotposter[images_ids][]"]:checked').length
		const accountsSelected = $('input[name="parrotposter[account_ids][]"]:checked').length

		const disabled = (!textExists && !imagesSelected) || !accountsSelected
		$('input[name=submit]').prop('disabled', disabled)
		$('#parrotposter-publish-note').css('display', disabled ? 'block' : 'none')
	}

	checkPublishBtn()

	$('textarea[name="parrotposter[post_text]"]').change(checkPublishBtn)
	$('textarea[name="parrotposter[post_text]"]').keyup(checkPublishBtn)
	$('input[name="parrotposter[images_ids][]"]').change(checkPublishBtn)
	$('input[name="parrotposter[account_ids][]"]').change(checkPublishBtn)

	$('.parrotposter-post-select-images-list').sortable()

	// textarea auto resize
	$("textarea").each(function () {
		this.setAttribute("style", "height:" + (this.scrollHeight) + "px;overflow-y:hidden;");
	}).on("input", function () {
		this.style.height = "auto";
		this.style.height = (this.scrollHeight) + "px";
	});
})


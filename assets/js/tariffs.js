jQuery(function($) {
	function getPeriod(tariff, periodValue) {
		if (!tariff || !tariff.periods) return {}
		for (let i = 0; i < tariff.periods.length; i++) {
			if (tariff.periods[i].period == periodValue) {
				return tariff.periods[i]
			}
		}
	}

	function updateTariffs(tariffs, periodValue) {
		for (let i = 0; i < tariffs.length; i++) {
			const tariff = tariffs[i]
			const period = getPeriod(tariff, periodValue)
			const root = '.parrotposter-tariffs__item[data-id="' + tariff.id + '"] '
			$(root + '.parrotposter-tariffs__price-value').text(period.price/100)
			$(root + '.parrotposter-tariffs__price-usd-value').text(period.price_usd)
		}
	}

	updateTariffs(parrotposter_tariffs, $('.parrotposter-tariffs-period__wrap input:radio:checked').val())

	// handler on change period
	$('.parrotposter-tariffs-period__wrap input:radio').change(function(event) {
		const elem = $(event.target)
		const period = elem.val()
		updateTariffs(parrotposter_tariffs, period)
	})

	// select and pay tariff
	$('.parrotposter-tariffs__item .button').click(function(event) {
		event.preventDefault()
		const elem = $(event.target)
		const root = elem.closest('.parrotposter-tariffs__item')
		const id = root.data('id')
		const period = $('.parrotposter-tariffs-period__wrap input:radio:checked').val()
		elem.addClass('parrotposter-loading')
		console.log(id, period)
		$.post(ajaxurl, {
			'action': 'parrotposter_api_create_transaction',
			'parrotposter': {
				'tariff_id': id,
				'period': period,
			}
		}, function (data) {
			data = JSON.parse(data)
			if (!data.response) {
				elem.removeClass('parrotposter-loading')
				return
			}

			location = data.response.payment_url
		})
	})
})

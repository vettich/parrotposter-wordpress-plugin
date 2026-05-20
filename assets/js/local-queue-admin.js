jQuery(function ($) {
	const modalSel = '#parrotposter-local-queue-modal'

	function parrotposter_local_queue_get_modal() {
		return $(modalSel)
	}

	$(document).on('click', '.parrotposter-local-queue-view-btn', function (e) {
		e.preventDefault()
		parrotposter_local_queue_open_modal()
	})

	function parrotposter_local_queue_open_modal() {
		if (typeof parrotposter_modal_open !== 'function') {
			return
		}
		parrotposter_modal_open(modalSel)
		parrotposter_local_queue_load()
	}

	function parrotposter_local_queue_load() {
		const $modal = parrotposter_local_queue_get_modal()
		if (!$modal.length) {
			return
		}
		const $loading = $modal.find('.parrotposter-local-queue-modal__loading')
		const $empty = $modal.find('.parrotposter-local-queue-modal__empty')
		const $wrap = $modal.find('.parrotposter-local-queue-modal__table-wrap')
		const $wake = $modal.find('.parrotposter-local-queue-modal__wake-hint')
		const $tbody = $modal.find('.parrotposter-local-queue-table tbody')

		$loading.show()
		$empty.hide()
		$wrap.hide()
		$wake.hide()
		$tbody.empty()

		$.post(ajaxurl, {
			nonce: window.ParrotPosterAdmin && window.ParrotPosterAdmin.ajaxNonce,
			action: 'parrotposter_local_queue_list',
			ajaxrequest: 'true',
		})
			.done(function (raw) {
				let data
				try {
					data = typeof raw === 'string' ? JSON.parse(raw) : raw
				} catch (err) {
					return
				}
				if (data.error) {
					return
				}
				const payload = data.data || {}
				const items = payload.items || []
				parrotposter_local_queue_render(items, payload)
			})
			.always(function () {
				$loading.hide()
			})
	}

	function parrotposter_local_queue_render(items, payload) {
		const $modal = parrotposter_local_queue_get_modal()
		const $empty = $modal.find('.parrotposter-local-queue-modal__empty')
		const $wrap = $modal.find('.parrotposter-local-queue-modal__table-wrap')
		const $wake = $modal.find('.parrotposter-local-queue-modal__wake-hint')
		const $tbody = $modal.find('.parrotposter-local-queue-table tbody')

		if (!items.length) {
			$empty.show()
			$wrap.hide()
		} else {
			$empty.hide()
			$wrap.show()
			items.forEach(function (item) {
				$tbody.append(parrotposter_local_queue_row(item))
			})
		}

		if (payload.wake_pending) {
			const msg =
				typeof wp !== 'undefined' && wp.i18n
					? wp.i18n.__(
							'Background sync wake is pending: the site could not reach ParrotPoster to continue the queue. It will retry automatically.',
							'parrotposter'
					  )
					: 'Background sync wake is pending.'
			$wake.text(msg).show()
		} else {
			$wake.hide()
		}
	}

	function parrotposter_local_queue_row(item) {
		const wpCell = parrotposter_local_queue_wp_cell(item)
		const details = item.payload ? $('<code/>').text(item.payload) : '—'
		const $tr = $('<tr/>')
		$tr.append($('<td/>').text(item.id))
		$tr.append($('<td/>').html(wpCell))
		$tr.append($('<td/>').text(item.operation_label || item.operation))
		$tr.append($('<td/>').text(item.status_label || item.status))
		$tr.append($('<td/>').text(String(item.attempts)))
		$tr.append($('<td/>').text(item.next_attempt_at || '—'))
		$tr.append($('<td/>').text(item.created_at || '—'))
		const $detailsTd = $('<td class="parrotposter-local-queue-table__details"/>')
		if (item.payload) {
			$detailsTd.append(details)
		} else {
			$detailsTd.text('—')
		}
		$tr.append($detailsTd)
		return $tr
	}

	function parrotposter_local_queue_wp_cell(item) {
		const id = item.wp_post_id
		if (item.post_missing) {
			return (
				'[' +
				id +
				'] ' +
				(typeof wp !== 'undefined' && wp.i18n
					? wp.i18n.__('(post not found)', 'parrotposter')
					: '(post not found)')
			)
		}
		const title = item.post_title || ''
		const label = '[' + id + '] ' + title
		if (item.edit_link) {
			return $('<a/>', { href: item.edit_link, target: '_blank', rel: 'noopener' }).text(label)[0]
				.outerHTML
		}
		return $('<span/>').text(label)[0].outerHTML
	}
})

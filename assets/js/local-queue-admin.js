jQuery(function ($) {
	const modalSel = '#parrotposter-local-queue-modal'

	function parrotposter_lq__(text, fallback) {
		if (typeof wp !== 'undefined' && wp.i18n && typeof wp.i18n.__ === 'function') {
			return wp.i18n.__(text, 'parrotposter')
		}
		return fallback || text
	}

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

	function parrotposter_local_queue_set_busy(busy) {
		const $modal = parrotposter_local_queue_get_modal()
		$modal.find('.parrotposter-local-queue-process-all-btn').prop('disabled', busy)
		$modal.find('.parrotposter-local-queue-process-row-btn').prop('disabled', busy)
		if (busy) {
			$modal.find('.parrotposter-local-queue-modal__loading').show()
		}
	}

	function parrotposter_local_queue_show_feedback(message, isError) {
		const $modal = parrotposter_local_queue_get_modal()
		const $fb = $modal.find('.parrotposter-local-queue-modal__feedback')
		$fb.text(message)
		$fb.toggleClass('parrotposter-local-queue-modal__feedback--error', !!isError)
		$fb.show()
	}

	function parrotposter_local_queue_error_message(code) {
		switch (code) {
			case 'busy':
				return parrotposter_lq__(
					'This queue item is busy or not ready yet. Try again in a moment.',
					'This queue item is busy or not ready yet. Try again in a moment.'
				)
			case 'not_found':
				return parrotposter_lq__('Queue item not found.', 'Queue item not found.')
			case 'not_processable':
				return parrotposter_lq__('This queue item cannot be processed.', 'This queue item cannot be processed.')
			case 'invalid_id':
				return parrotposter_lq__('Invalid queue item.', 'Invalid queue item.')
			default:
				return parrotposter_lq__('Could not process the queue item.', 'Could not process the queue item.')
		}
	}

	function parrotposter_process_local_queue_admin(queueId) {
		const $modal = parrotposter_local_queue_get_modal()
		if (!$modal.length) {
			return
		}

		const payload = {
			nonce: window.ParrotPosterAdmin && window.ParrotPosterAdmin.ajaxNonce,
			action: 'parrotposter_process_local_queue_admin',
			ajaxrequest: 'true',
		}
		if (queueId) {
			payload.queue_id = queueId
		}

		parrotposter_local_queue_set_busy(true)

		$.post(ajaxurl, payload)
			.done(function (raw) {
				let data
				try {
					data = typeof raw === 'string' ? JSON.parse(raw) : raw
				} catch (err) {
					parrotposter_local_queue_show_feedback(
						parrotposter_lq__('Invalid server response.', 'Invalid server response.'),
						true
					)
					return
				}
				if (data.error) {
					parrotposter_local_queue_show_feedback(
						parrotposter_local_queue_error_message(data.error),
						true
					)
					return
				}
				const result = data.data || {}
				const processed = parseInt(result.processed, 10) || 0
				let msg
				if (typeof wp !== 'undefined' && wp.i18n && typeof wp.i18n.sprintf === 'function') {
					msg = wp.i18n.sprintf(
						wp.i18n.__('Processed %d item(s).', 'parrotposter'),
						processed
					)
				} else {
					msg = 'Processed ' + processed + ' item(s).'
				}
				parrotposter_local_queue_show_feedback(msg, false)
				parrotposter_local_queue_load()
			})
			.fail(function () {
				parrotposter_local_queue_show_feedback(
					parrotposter_lq__('Request failed.', 'Request failed.'),
					true
				)
			})
			.always(function () {
				parrotposter_local_queue_set_busy(false)
				const $modal = parrotposter_local_queue_get_modal()
				$modal.find('.parrotposter-local-queue-modal__loading').hide()
			})
	}

	$(document).on('click', '.parrotposter-local-queue-process-all-btn', function (e) {
		e.preventDefault()
		parrotposter_process_local_queue_admin(null)
	})

	$(document).on('click', '.parrotposter-local-queue-process-row-btn', function (e) {
		e.preventDefault()
		const id = $(this).data('queue-id')
		if (id) {
			parrotposter_process_local_queue_admin(id)
		}
	})

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
		const $processAll = $modal.find('.parrotposter-local-queue-process-all-btn')

		if (!items.length) {
			$empty.show()
			$wrap.hide()
			$processAll.prop('disabled', true)
		} else {
			$empty.hide()
			$wrap.show()
			$processAll.prop('disabled', false)
			items.forEach(function (item) {
				$tbody.append(parrotposter_local_queue_row(item))
			})
		}

		if (payload.wake_pending) {
			const msg = parrotposter_lq__(
				'Background sync wake is pending: the site could not reach ParrotPoster to continue the queue. It will retry automatically.',
				'Background sync wake is pending: the site could not reach ParrotPoster to continue the queue. It will retry automatically.'
			)
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

		const $actionsTd = $('<td class="parrotposter-local-queue-table__actions"/>')
		if (item.status === 'pending' || item.status === 'failed') {
			const label = parrotposter_lq__('Process', 'Process')
			$actionsTd.append(
				$('<button type="button" class="button button-small parrotposter-local-queue-process-row-btn"/>')
					.attr('data-queue-id', item.id)
					.text(label)
			)
		} else {
			$actionsTd.text('—')
		}
		$tr.append($actionsTd)

		return $tr
	}

	function parrotposter_local_queue_wp_cell(item) {
		const id = item.wp_post_id
		if (item.post_missing) {
			return (
				'[' +
				id +
				'] ' +
				parrotposter_lq__('(post not found)', '(post not found)')
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

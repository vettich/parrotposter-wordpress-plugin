jQuery(function($) {
	const popoverId = 'parrotposter-col-popover'

	function ajaxNonce() {
		return window.ParrotPosterAdmin && window.ParrotPosterAdmin.ajaxNonce
	}

	function formNonce() {
		return $('input[name="parrotposter[nonce]"]').val()
	}

	function parseResp(resp) {
		if (typeof resp === 'string') {
			try {
				return JSON.parse(resp)
			} catch (e) {
				return { error: 'bad_json' }
			}
		}
		return resp || {}
	}

	function i18n(text) {
		if (window.wp && wp.i18n && typeof wp.i18n.__ === 'function') {
			return wp.i18n.__(text, 'parrotposter')
		}
		return text
	}

	function popoverEl() {
		let $el = $('#' + popoverId)
		if ($el.length) {
			return $el
		}
		$el = $('<div id="' + popoverId + '" class="parrotposter-col-popover" hidden></div>')
		$(document.body).append($el)
		return $el
	}

	function closePopover() {
		const $el = $('#' + popoverId)
		if ($el.length) {
			$el.attr('hidden', true).empty().removeData('anchor')
		}
	}

	function positionPopover($el, anchor) {
		const rect = anchor.getBoundingClientRect()
		const pad = 8
		const width = $el.outerWidth() || 240
		const height = $el.outerHeight() || 0
		let left = rect.left
		if (left + width > window.innerWidth - pad) {
			left = Math.max(pad, window.innerWidth - width - pad)
		}
		let top = rect.bottom + 6
		if (top + height > window.innerHeight - pad && rect.top - height - 6 > pad) {
			top = rect.top - height - 6
		}
		$el.css({ left: left + 'px', top: top + 'px' })
	}

	function openPopover(anchor) {
		const $btn = $(anchor)
		const $el = popoverEl()
		if ($el.data('anchor') === anchor && !$el.attr('hidden')) {
			closePopover()
			return
		}

		const name = $btn.attr('data-name') || ''
		const photo = $btn.attr('data-photo') || ''
		const link = $btn.attr('data-link') || ''
		const error = $btn.attr('data-error') || ''
		const status = $btn.attr('data-status') || ''
		const statusText = $btn.attr('data-status-text') || ''

		const $head = $('<div class="parrotposter-col-popover__head"></div>')
		if (photo) {
			$head.append($('<img class="parrotposter-col-popover__photo" alt="" width="32" height="32" />').attr('src', photo))
		}
		$head.append($('<div class="parrotposter-col-popover__name"></div>').text(name))

		$el.empty().append($head)
		if (statusText) {
			$el.append(
				$('<p class="parrotposter-col-popover__status"></p>')
					.addClass(status === 'fail' ? 'is-fail' : '')
					.text(statusText)
			)
		}
		if (error) {
			$el.append($('<p class="parrotposter-col-popover__error"></p>').text(error))
		}
		if (link) {
			$el.append(
				$('<a class="parrotposter-col-popover__link" target="_blank" rel="noopener noreferrer"></a>')
					.attr('href', link)
					.text(i18n('Open post'))
			)
		}

		$el.removeAttr('hidden').data('anchor', anchor)
		positionPopover($el, anchor)
	}

	function hydrate(ids) {
		if (!ids || !ids.length) {
			return
		}
		$.post(ajaxurl, {
			nonce: ajaxNonce(),
			action: 'parrotposter_pipeline_column_batch',
			ajaxrequest: true,
			parrotposter: { nonce: formNonce(), wp_post_ids: ids }
		}, function(resp) {
			resp = parseResp(resp)
			if (resp.error || !resp.data || !resp.data.cells) {
				return
			}
			closePopover()
			Object.keys(resp.data.cells).forEach(function(id) {
				const cell = resp.data.cells[id]
				const $el = $('.parrotposter-col-cell[data-wp-post-id="' + id + '"]')
				if ($el.length && cell.html) {
					$el.replaceWith(cell.html)
				}
			})
		})
	}

	function collectHydrateIds() {
		const ids = []
		$('[data-pp-hydrate]').each(function() {
			const id = $(this).data('wp-post-id')
			if (id) {
				ids.push(id)
			}
		})
		return ids
	}

	$(document).on('click', '.parrotposter-col-cell .parrotposter-col-social-chip', function(e) {
		e.preventDefault()
		e.stopPropagation()
		openPopover(this)
	})

	$(document).on('click', function(e) {
		if ($(e.target).closest('#' + popoverId + ', .parrotposter-col-cell .parrotposter-col-social-chip').length) {
			return
		}
		closePopover()
	})

	$(document).on('keydown', function(e) {
		if (e.key === 'Escape') {
			closePopover()
		}
	})

	$(document).on('scroll', '.wp-list-table', closePopover)
	$(window).on('scroll resize', closePopover)

	hydrate(collectHydrateIds())

	$(document).on('parrotposter:column-refresh', function(_e, ids) {
		hydrate(ids || collectHydrateIds())
	})
})

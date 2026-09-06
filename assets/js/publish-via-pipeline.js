jQuery(function($) {
	let wp_post_id = 0
	let useTabs = false
	let canPublish = false
	const modalSel = '#parrotposter-publish-via-pipeline'
	const modal = $(modalSel)
	const tabsEl = $('#parrotposter-pipeline-tabs')
	const listEl = $('#parrotposter-pipeline-list')
	const listWrap = $('#parrotposter-pipeline-list-wrap')
	const listTitle = $('#parrotposter-pipeline-list-title')
	const emptyEl = $('#parrotposter-pipeline-empty')
	const errorEl = $('#parrotposter-pipeline-error')
	const existingWrap = $('#parrotposter-pipeline-existing')
	const existingList = $('#parrotposter-pipeline-existing-list')
	const publishPanel = $('#parrotposter-pipeline-publish-panel')
	const loadingEl = $('#parrotposter-pipeline-loading')
	const hintEl = $('#parrotposter-pipeline-hint')
	const publishBtn = $('#parrotposter-publish-via-pipeline-btn')

	function formNonce() {
		return modal.find('input[name="parrotposter[nonce]"]').val()
	}

	function ajaxNonce() {
		return window.ParrotPosterAdmin && window.ParrotPosterAdmin.ajaxNonce
	}

	function modalAttr(name) {
		return modal.attr('data-' + name) || ''
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

	function statusClass(status) {
		if (status === 'success') {
			return 'is-success'
		}
		if (status === 'fail') {
			return 'is-fail'
		}
		return 'is-pending'
	}

	function renderAccountRow(acc) {
		const type = acc.type || ''
		const name = acc.name || ''
		const row = $('<div class="parrotposter-pipeline-modal__account"></div>')
		const photoWrap = $('<div class="parrotposter-pipeline-modal__account-photo"></div>')
		const img = $('<img alt="" width="32" height="32" />')
		if (acc.photo) {
			img.attr('src', acc.photo)
		}
		photoWrap.append(img)
		if (type) {
			photoWrap.append(
				$('<span class="parrotposter-pipeline-modal__account-type" aria-hidden="true"></span>').addClass(type)
			)
		}
		const body = $('<div class="parrotposter-pipeline-modal__account-body"></div>')
		body.append(
			$('<div class="parrotposter-pipeline-modal__account-name"></div>').text(name).attr('title', name)
		)
		const link = acc.link || ''
		if (acc.success === true && link) {
			body.append(
				$('<a class="parrotposter-pipeline-modal__account-link" target="_blank" rel="noopener noreferrer"></a>')
					.attr('href', link)
					.text(i18n('View post'))
			)
		} else if (acc.success === false) {
			row.addClass('is-fail')
			body.append(
				$('<div class="parrotposter-pipeline-modal__account-error"></div>').text(acc.error || '')
			)
		} else {
			body.append(
				$('<div class="parrotposter-pipeline-modal__account-hint"></div>').text(i18n('No result yet'))
			)
		}
		row.append(photoWrap).append(body)
		return row
	}

	function renderAccountList(accounts) {
		if (!accounts || !accounts.length) {
			return null
		}
		const list = $('<div class="parrotposter-pipeline-modal__accounts"></div>')
		accounts.forEach(function(acc) {
			if (!acc || typeof acc !== 'object') {
				return
			}
			list.append(renderAccountRow(acc))
		})
		return list.children().length ? list : null
	}

	function hideTabs() {
		useTabs = false
		tabsEl.attr('hidden', true)
		modal.removeClass('parrotposter-modal--pipeline-tabs')
		tabsEl.find('.parrotposter-pipeline-modal__tab')
			.removeClass('is-active')
			.attr('aria-selected', 'false')
	}

	function setPublishBtnVisible(visible) {
		if (visible && canPublish) {
			publishBtn.removeAttr('hidden')
			return
		}
		publishBtn.attr('hidden', true)
	}

	function setTab(name) {
		tabsEl.find('.parrotposter-pipeline-modal__tab').each(function() {
			const on = $(this).attr('data-tab') === name
			$(this).toggleClass('is-active', on).attr('aria-selected', on ? 'true' : 'false')
		})
		modal.find('[data-panel]').each(function() {
			if ($(this).attr('data-panel') === name) {
				$(this).removeAttr('hidden')
			} else {
				$(this).attr('hidden', true)
			}
		})
		setPublishBtnVisible(name === 'publish')
	}

	function applyLayout(hasExisting) {
		useTabs = !!hasExisting
		if (useTabs) {
			tabsEl.removeAttr('hidden')
			modal.addClass('parrotposter-modal--pipeline-tabs')
			setTab('results')
			return
		}
		hideTabs()
		existingWrap.attr('hidden', true)
		publishPanel.removeAttr('hidden')
		setPublishBtnVisible(true)
	}

	function resetView() {
		listEl.empty()
		existingList.empty()
		canPublish = false
		hideTabs()
		existingWrap.attr('hidden', true)
		publishPanel.attr('hidden', true)
		emptyEl.attr('hidden', true)
		errorEl.attr('hidden', true)
		listWrap.attr('hidden', true)
		hintEl.attr('hidden', true)
		publishBtn.prop('disabled', true).attr('hidden', true)
		loadingEl.removeAttr('hidden')
	}

	function showError() {
		loadingEl.attr('hidden', true)
		hideTabs()
		existingWrap.attr('hidden', true)
		publishPanel.attr('hidden', true)
		listWrap.attr('hidden', true)
		hintEl.attr('hidden', true)
		emptyEl.attr('hidden', true)
		publishBtn.attr('hidden', true)
		errorEl.find('p').text(modalAttr('load-error'))
		errorEl.removeAttr('hidden')
	}

	function renderExistingCards(posts) {
		existingList.empty()
		posts.forEach(function(post) {
			const when = post.publish_at ? new Date(post.publish_at).toLocaleString() : ''
			const label = post.status_text || post.status || ''
			const card = $('<div class="parrotposter-pipeline-modal__card"></div>')
			card.addClass(statusClass(post.status))
			const head = $('<div class="parrotposter-pipeline-modal__card-head"></div>')
			head.append($('<span class="parrotposter-pipeline-modal__dot" aria-hidden="true"></span>'))
			head.append($('<span class="parrotposter-pipeline-modal__status"></span>').text(label))
			if (when) {
				head.append($('<span class="parrotposter-pipeline-modal__when"></span>').text(when))
			}
			card.append(head)
			const accounts = renderAccountList(post.accounts)
			if (accounts) {
				card.append(accounts)
			}
			existingList.append(card)
		})
	}

	function renderExisting(posts) {
		existingList.empty()
		if (!posts || !posts.length) {
			return false
		}
		const sorted = posts.slice().sort(function(a, b) {
			return String(b.publish_at || '').localeCompare(String(a.publish_at || ''))
		})
		renderExistingCards(sorted)
		return true
	}

	function renderPipelines(pipelines, hasExisting) {
		listEl.empty()
		publishBtn.prop('disabled', true)
		if (!pipelines || !pipelines.length) {
			canPublish = false
			listWrap.attr('hidden', true)
			hintEl.attr('hidden', true)
			emptyEl.removeAttr('hidden')
			return
		}
		canPublish = true
		emptyEl.attr('hidden', true)
		listWrap.removeAttr('hidden')
		listTitle.text(hasExisting ? modalAttr('title-again') : modalAttr('title-choose'))
		if (hasExisting) {
			hintEl.removeAttr('hidden')
		} else {
			hintEl.attr('hidden', true)
		}
		pipelines.forEach(function(p) {
			const id = p.id
			const row = $('<label class="parrotposter-pipeline-modal__row"></label>')
			row.attr('for', 'pp-pipeline-' + id)
			const radio = $('<input type="radio" name="parrotposter_pipeline_id">')
			radio.attr('id', 'pp-pipeline-' + id).val(id)
			const body = $('<div class="parrotposter-pipeline-modal__row-body"></div>')
			body.append($('<div class="parrotposter-pipeline-modal__row-name"></div>').text(p.name || id))
			if (p.socials_html) {
				body.append($(p.socials_html))
			} else if (p.networks) {
				body.append($('<div class="parrotposter-pipeline-modal__row-networks"></div>').text(p.networks))
			}
			row.append(radio).append(body)
			listEl.append(row)
		})
		if (pipelines.length === 1) {
			const only = listEl.find('input[name=parrotposter_pipeline_id]').first()
			only.prop('checked', true)
			only.closest('.parrotposter-pipeline-modal__row').addClass('is-selected')
			publishBtn.prop('disabled', false)
		}
	}

	$(document).on('click', 'a.parrotposter-publish, a.parrotposter-meta-box__publish-btn', function(e) {
		e.preventDefault()
		wp_post_id = $(e.currentTarget).data('wp-post-id')
		resetView()
		parrotposter_modal_open(modalSel)

		$.post(ajaxurl, {
			nonce: ajaxNonce(),
			action: 'parrotposter_pipeline_publish_modal_data',
			ajaxrequest: true,
			parrotposter: { nonce: formNonce(), wp_post_id: wp_post_id }
		}, function(resp) {
			resp = parseResp(resp)
			loadingEl.attr('hidden', true)
			if (resp.error) {
				showError()
				return
			}
			const data = resp.data || {}
			const hasExisting = renderExisting(data.existing_posts || [])
			renderPipelines(data.pipelines || [], hasExisting)
			applyLayout(hasExisting)
		}).fail(function() {
			showError()
		})
	})

	tabsEl.on('click', '.parrotposter-pipeline-modal__tab', function() {
		if (!useTabs) {
			return
		}
		setTab($(this).attr('data-tab'))
	})

	$(document).on('change', '#parrotposter-pipeline-list input[name=parrotposter_pipeline_id]', function() {
		$('#parrotposter-pipeline-list .parrotposter-pipeline-modal__row').removeClass('is-selected')
		$(this).closest('.parrotposter-pipeline-modal__row').addClass('is-selected')
		publishBtn.prop('disabled', false)
	})

	publishBtn.click(function(e) {
		e.preventDefault()
		const pipeline_id = modal.find('input[name=parrotposter_pipeline_id]:checked').val()
		if (!pipeline_id) {
			return
		}
		publishBtn.addClass('parrotposter-loading')
		$.post(ajaxurl, {
			nonce: ajaxNonce(),
			action: 'parrotposter_pipeline_publish',
			ajaxrequest: true,
			parrotposter: { nonce: formNonce(), pipeline_id: pipeline_id, wp_post_id: wp_post_id }
		}, function(data) {
			data = parseResp(data)
			if (data.error) {
				parrotposter_modal_close_all()
				parrotposter_modal_open('#parrotposter-publish-via-template-fail')
				return
			}
			parrotposter_modal_close_all()
			parrotposter_modal_open('#parrotposter-publish-via-template-success')
			const cell = $('.parrotposter-col-cell[data-wp-post-id="' + wp_post_id + '"]')
			if (cell.length) {
				cell.attr('data-pp-hydrate', '1')
				$(document).trigger('parrotposter:column-refresh', [[wp_post_id]])
			}
		})
			.fail(function() {
				parrotposter_modal_close_all()
				parrotposter_modal_open('#parrotposter-publish-via-template-fail')
			})
			.always(function() {
				publishBtn.removeClass('parrotposter-loading')
			})
	})
})

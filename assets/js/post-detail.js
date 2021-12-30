const parrotposterModalId = '#parrotposter-view-post'
jQuery(function($) {
	const modalId = parrotposterModalId

	$('.parrotposter-post-view').click(function(e) {
		e.preventDefault()
		const elem = $(e.target)
		const id = elem.data('id')

		function deleteHandler(id) {
			location = location.href + '&action=delete&id=' + id
		}

		parrotposterLoadPost(id, elem, deleteHandler)
	})

	$(modalId + ' .parrotposter-button--delete').click(function(e) {
		e.preventDefault()
		const elem = $(e.target)
		const id = elem.closest('.parrotposter-modal').data('id')

		$('#parrotposter-view-post-delete-confirm').data('id', id)
		parrotposter_modal_open('#parrotposter-view-post-delete-confirm')
	})

	$('#parrotposter-view-post-delete-confirm .button-primary').click(function(event) {
		event.preventDefault()
		parrotposter_modal_close('#parrotposter-view-post-delete-confirm')
		const elem = $(event.target)
		const id = $('#parrotposter-view-post-delete-confirm').data('id')
		const rootItem = $(modalId + ' .parrotposter-button--delete')
		rootItem.addClass('parrotposter-loading');

		const deleteFn = $(modalId).data('delete-fn')
		console.log(deleteFn)
		deleteFn(id)
	})

})

function parrotposterLoadPost(postId, loadingSelector, deleteFn) {
	const $ = jQuery

	$(loadingSelector).addClass('parrotposter-loading')

	console.log(postId)
	$.post(ajaxurl, {
		'action': 'parrotposter_api_get_post',
		'parrotposter': {
			'post_id': postId,
		}
	}, function (data) {
		$(loadingSelector).removeClass('parrotposter-loading')

		data = JSON.parse(data)
		console.log(data)
		if (!data.post) {
			return
		}

		parrotposterOpenPostModal(data.post, deleteFn)
	})
}

function parrotposterClosePostModal() {
	parrotposter_modal_close(parrotposterModalId)

	const rootItem = $(parrotposterModalId + ' .parrotposter-button--delete')
	rootItem.removeClass('parrotposter-loading');
}

function parrotposterOpenPostModal(post, deleteFn) {
	const $ = jQuery
	const $modal = $(parrotposterModalId)
	const $images = $modal.find('.parrotposter-modal__post-images')
	const $text = $modal.find('.parrotposter-modal__post-text')
	const $tags = $modal.find('.parrotposter--tags .parrotposter-modal__post-info-value')
	const $link = $modal.find('.parrotposter--link .parrotposter-modal__post-info-value')
	const $publishAt = $modal.find('.parrotposter--publish_at .parrotposter-modal__post-info-value')
	const $accounts = $modal.find('.parrotposter-accounts__list')

	// clear
	$images.empty()
	$text.empty()
	$tags.empty()
	$text.empty()
	$publishAt.empty()
	$accounts.empty()

	if (post.fields.images_sizes && !post.fields.images_sizes.length) {
		$images.hide()
	} else if (post.fields.images_sizes) {
		$images.show()
		post.fields.images_sizes.forEach(function(img) {
			$images.append($(`<img src="${img.thumbnail}" alt="img">`))
		})
	}

	$text.text(post.fields.text)

	$tags.text(post.fields.tags)
	if (post.fields.tags.length > 0) {
		$tags.parent().parent().show()
	} else {
		$tags.parent().parent().hide()
	}

	$link.text(post.fields.link)
	if (post.fields.link.length > 0) {
		$link.parent().parent().show()
	} else {
		$link.parent().parent().hide()
	}

	// $publishAt.text(new Date(post.publish_at).toLocaleString())
	$publishAt.text(post.publish_at_view)

	parrotposterLoadAccounts(function() {
		const results = []
		const tpl = `<div class="parrotposter-accounts__item">
			<div class="parrotposter-accounts__photo">
				<img src="{photo_url}" alt="photo">
				<div class="parrotposter-accounts__type {type}"></div>
			</div>
			<div>
				<div class="parrotposter-accounts__name" title="{name}">{name}</div>
				<div class="parrotposter-accounts__result">{result}</div>
			</div>
		</div>`
		const successTpl = `<a href="{link}" target="_blank">{text}</a>`
		const errorTpl = `<span>{error}</span>`
		const emptyTpl = `<span>{text}</span>`

		function findAccount(id) {
			if (!parrotposter_accounts) {
				return null
			}
			return parrotposter_accounts.find(function(elem) {
				return elem.id == id
			})
		}

		const hasAccounts = Object.prototype.toString.call(post.networks.accounts) === '[object Array]'
		hasAccounts && post.networks.accounts.forEach(function (id) {
			const acc = findAccount(id)
			if (!acc) {
				return
			}

			const result = post.results[id]
			let resultHtml = ''
			if (!result) {
				resultHtml = emptyTpl.split('{text}').join(wp.i18n.__('No result yet', 'parrotposter'))
			} else if (result.success) {
				resultHtml = successTpl.split('{link}').join(result.link)
					.split('{text}').join(wp.i18n.__('View post', 'parrotposter'))
			} else {
				let errorTxt = wp.i18n.__('Error:', 'parrotposter')
				errorTxt += ' ' + result.error_msg
				resultHtml = emptyTpl.split('{text}').join(errorTxt)
			}

			let html = tpl.split('{photo_url}').join(acc.photo)
				.split('{type}').join(acc.type)
				.split('{name}').join(acc.name)
				.split('{result}').join(resultHtml)

			results.push(html)
		})

		if (results.length) {
			$accounts.append($(results.join('')))
		}
	})

	$modal.data('id', post.id)
	$modal.data('delete-fn', deleteFn)
	parrotposter_modal_open(parrotposterModalId)
}

let parrotposter_accounts = null
function parrotposterLoadAccounts(successCb) {
	if (!!parrotposter_accounts) {
		successCb()
		return
	}

	jQuery.post(ajaxurl, {
		'action': 'parrotposter_api_list_accounts',
	}, function (data) {
		data = JSON.parse(data)
		if (data.accounts) {
			parrotposter_accounts = data.accounts
		}
		successCb()
	})
}


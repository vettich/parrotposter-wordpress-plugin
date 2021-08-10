jQuery(function ($) {
	let accounts = null;

	function __ (msg) {
		return wp.i18n.__(msg, 'parrotposter')
	}

	// get exists posts by wp_post_id
	if (parrotposter_user_id) $.post(ajaxurl, {
		'action': 'parrotposter_api_list_posts',
		'parrotposter': {
			'filter': {
				'user_id': parrotposter_user_id,
				'fields.extra.wp_post_id': parrotposter_post_id,
			}
		}
	}, function (data) {
		$('.parrotposter-meta-box-post-items').removeClass('parrotposter-loading-spinner')

		data = JSON.parse(data);
		if (!data.response || !data.response.posts) {
			return
		}

		if (!data.response.posts.length) {
			$('.parrotposter-meta-box-post-items').append('<p>'+__('Posts have not yet been created')+'</p>')
			return
		}

		$('.parrotposter-meta-box-post-items').empty();
		data.response.posts.forEach(function(elem) {
			const template = `<p class="parrotposter-meta-box-post-item" data-post-id="{post_id}">
				<b>`+__('Post published at')+`:</b> {publish_at}<br>
				<b>`+__('Status')+`:<b> {status}<br>
				<a class="parrotposter-meta-box-post-view-detail-link" href="#" data-post-id="{post_id}">
					`+__('View details')+`
				</a>
			</p>`
			const publish_at = (new Date(elem['publish_at'])).toLocaleString()
			const html = template
				.split('{publish_at}').join(publish_at)
				.split('{status}').join(elem['status'])
				.split('{post_id}').join(elem['id'])
			$('.parrotposter-meta-box-post-items').append(html)
		})
		$('.parrotposter-meta-box-post-view-detail-link').click(loadPostDetailHandler)
	});

	// load and display post by id
	function loadPostDetailHandler(event) {
		event.preventDefault()
		const elem = $(event.target)
		const post_id = elem.data('post-id')

		$('#parrotposter-post-details').show()
		$('#parrotposter-post-details .parrotposter-modal-body').empty()
		$('#parrotposter-post-details .parrotposter-modal-body').append(`<p class="parrotposter-loading-spinner"></p>`)

		loadAccounts(function() {
			loadPostDetail(post_id)
		})
	}

	function loadPostDetail(post_id) {
		$.post(ajaxurl, {
			'action': 'parrotposter_api_get_post',
			'parrotposter': {'post_id': post_id}
		}, function (data) {
			data = JSON.parse(data);
			if (!data.response) {
				return
			}
			const post = data.response

			const images = []
			const imageTemplate = `<img src="{src}" alt="">`
			if (post.fields.images_sizes) {
				post.fields.images_sizes.forEach(function (img) {
					images.push(imageTemplate.split('{src}').join(img.thumbnail))
				})
			}

			const results = []
			const resultTemplate = `<p>[{type}] {name}: <a href="{link}" target="_blank">`+__('View post')+`</a></p>`
			const resultErrorTemplate = `<p>[{type}] {name}: {error}</p>`
			if (!!post.results) {
				Object.keys(post.results).forEach(function (id) {
					const result = post.results[id]
					const acc = findAccount(id)
					if (!acc) {
						return
					}
					if (result.success) {
						results.push(resultTemplate
							.split('{type}').join(acc.type)
							.split('{name}').join(acc.name)
							.split('{link}').join(result.link)
						)
					} else {
						results.push(resultErrorTemplate
							.split('{type}').join(acc.type)
							.split('{name}').join(acc.name)
							.split('{error}').join(result.error_msg)
						)
					}
				})
			}

			const template = `<div>
				<p class="parrotposter-modal-post-text">{text}</p>
				<p class="parrotposter-modal-post-images">{images}</p>
				<p class="parrotposter-modal-post-results">{results}</p>
				<p class="parrotposter-modal-post-actions">
					<a class="parrotposter-link-remove" href="#" data-post-id="{post_id}">`+__('Remove post')+`</a>
				</p>
			</div>`
			const body = template
				.split('{text}').join(post.fields.text)
				.split('{images}').join(images.join(''))
				.split('{results}').join(results.join(''))
				.split('{post_id}').join(post.id)
			$('#parrotposter-post-details .parrotposter-modal-body').empty()
			$('#parrotposter-post-details .parrotposter-modal-body').append(body)
			$('#parrotposter-post-details .parrotposter-link-remove').click(removePostHandler)
		})
	}

	function loadAccounts(cb) {
		if (!!accounts) {
			cb()
			return
		}
		$.post(ajaxurl, {
			'action': 'parrotposter_api_list_accounts',
		}, function (data) {
			data = JSON.parse(data);
			if (data.response) {
				accounts = data.response.accounts
			}
			cb()
		})
	}

	function findAccount(id) {
		if (!accounts) {
			return null
		}
		return accounts.find(function(elem) {
			return elem.id == id
		})
	}

	function removePostHandler(event) {
		event.preventDefault()
		if (!window.confirm(__('Remove post from ParrotPoster and social networks?'))) {
			return
		}

		const el = $(event.target)
		const post_id = el.data('post-id')
		el.append(`<div class="parrotposter-loading-spinner parrotposter-loading-spinner-block"></div>`)
		$.post(ajaxurl, {
			'action': 'parrotposter_api_remove_post',
			'parrotposter': {'post_id': post_id}
		}, function (data) {
			data = JSON.parse(data);
			if (data.error) {
				el.empty()
				el.append(data.error.msg)
				return
			}
			el.replaceWith(__('Removed success'))
			$('.parrotposter-meta-box-post-items .parrotposter-meta-box-post-item[data-post-id='+post_id+']').remove()
		})
	}
})


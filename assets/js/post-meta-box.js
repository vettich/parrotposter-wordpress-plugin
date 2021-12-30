jQuery(function($) {

	$('.parrotposter-meta-box__show-posts-btn').click(function(e) {
		e.preventDefault()
		const elem = $(e.target).closest('a')
		elem.toggleClass('parrotposter-meta-box__show-posts-btn--open')
		const isOpen = elem.hasClass('parrotposter-meta-box__show-posts-btn--open')
		if (!isOpen) {
			$('.parrotposter-meta-box-post-items').hide()
		} else {
			const items = $('.parrotposter-meta-box-post-items')
			items.show()
			if (!items.children().length) {
				loadPostsList()
			}
		}
	})

	function loading(elem, show) {
		if (show) {
			$(elem).append($(`<span class="parrotposter-loading parrotposter-loading-row">`))
		} else {
			$(elem).find('.parrotposter-loading').remove()
		}
	}

	function noPosts() {
		$('.parrotposter-meta-box-post-items')
			.append('<p>'+wp.i18n.__('Posts have not yet been created', 'parrotposter')+'</p>')
	}

	function loadPostsList() {
		// get exists posts by wp_post_id
		if (parrotposter_user_id) {

			loading('.parrotposter-meta-box-post-items', true)

			$.post(ajaxurl, {
				'action': 'parrotposter_api_list_posts_by_wp_post',
				'parrotposter': {
					'wp_post_id': parrotposter_post_id,
				}
			}, function (data) {
				loading('.parrotposter-meta-box-post-items', false)

				data = JSON.parse(data);
				console.log(data)
				if (!data.response || !data.response.posts) {
					return
				}

				if (!data.response.posts.length) {
					noPosts()
					return
				}

				$('.parrotposter-meta-box-post-items').empty();
				const html_list = []
				data.response.posts.forEach(function(post_item) {

					const template = `
					<hr data-post-id="{post_id}"/>
					<div class="parrotposter-meta-box-post-item" data-post-id="{post_id}">
						<div>
							<b>`+wp.i18n.__('Post published at', 'parrotposter')+`:</b>
							{publish_at}
						</div>
						<div>
							<b>`+wp.i18n.__('Status', 'parrotposter')+`:</b>
							{status}
						</div>
						<a class="parrotposter-meta-box-post-view-detail-link" href="#" data-post-id="{post_id}">
							<b>`+wp.i18n.__('View details', 'parrotposter')+`</b>
						</a>
					</div>
					`

					const publish_at = (new Date(post_item['publish_at'])).toLocaleString()
					const html = template
						.split('{publish_at}').join(publish_at)
						.split('{status}').join(post_item['status_view'])
						.split('{post_id}').join(post_item['id'])
					html_list.push(html)
				})
				$('.parrotposter-meta-box-post-items').append(html_list.join(''))
				$('.parrotposter-meta-box-post-view-detail-link').click(loadPostDetailHandler)
			})

		} else {
			noPosts()
		}
	}

	// load and display post by id
	function loadPostDetailHandler(event) {
		event.preventDefault()
		const elem = $(event.target).closest('a')
		const post_id = elem.data('post-id')

		function deleteFn (id) {
			$.post(ajaxurl, {
				'action': 'parrotposter_api_delete_post',
				'parrotposter': {'post_id': post_id}
			}, function (data) {
				data = JSON.parse(data);
				if (data.error) {
					return
				}
				$('.parrotposter-meta-box-post-items [data-post-id='+post_id+']').remove()
				parrotposterClosePostModal()
			})

		}

		parrotposterLoadPost(post_id, elem, deleteFn)

		// $('#parrotposter-post-details').show()
		// $('#parrotposter-post-details .parrotposter-modal-body').empty()
		// $('#parrotposter-post-details .parrotposter-modal-body').append(`<p class="parrotposter-loading-spinner"></p>`)
    //
		// loadAccounts(function() {
		// 	loadPostDetail(post_id)
		// })
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
			const resultTemplate = `<p>[{type}] {name}: <a href="{link}" target="_blank">`+wp.i18n.__('View post', 'parrotposter')+`</a></p>`
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
					<a class="parrotposter-link-remove" href="#" data-post-id="{post_id}">`+wp.i18n.__('Remove post', 'parrotposter')+`</a>
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
		if (!window.confirm(wp.i18n.__('Remove post from ParrotPoster and social networks?', 'parrotposter'))) {
			return
		}

		const el = $(event.target)
		const post_id = el.data('post-id')
		el.append(`<div class="parrotposter-loading-spinner parrotposter-loading-spinner-block"></div>`)
		$.post(ajaxurl, {
			'action': 'parrotposter_api_delete_post',
			'parrotposter': {'post_id': post_id}
		}, function (data) {
			data = JSON.parse(data);
			if (data.error) {
				el.empty()
				el.append(data.error.msg)
				return
			}
			el.replaceWith(wp.i18n.__('Removed success', 'parrotposter'))
			$('.parrotposter-meta-box-post-items .parrotposter-meta-box-post-item[data-post-id='+post_id+']').remove()
		})
	}
})

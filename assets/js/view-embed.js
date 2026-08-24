/**
 * Parent-page helpers for ParrotPoster WP admin iframe (postMessage, backdrop, viewport).
 */
(function () {
	'use strict';

	var cfg = window.ParrotPosterViewEmbed || {};
	var PP_ALLOWED_ORIGINS = cfg.allowedOrigins || [];
	var PP_AUTH2_NONCE = cfg.authNonce || '';

	function ppOriginAllowed(origin) {
		return PP_ALLOWED_ORIGINS.indexOf(origin) !== -1;
	}

	var ppParentBackdropActive = false;

	function pp_update_parent_backdrop_segments() {
		var root = document.getElementById('pp-parent-modal-backdrop');
		if (!root || !ppParentBackdropActive) {
			return;
		}
		var iframe = document.getElementById('pp-iframe');
		var top = root.querySelector('[data-pp-segment="top"]');
		var bottom = root.querySelector('[data-pp-segment="bottom"]');
		var left = root.querySelector('[data-pp-segment="left"]');
		var right = root.querySelector('[data-pp-segment="right"]');
		if (!iframe || !top || !bottom || !left || !right) {
			return;
		}
		var rect = iframe.getBoundingClientRect();
		var vw = window.innerWidth;
		var vh = window.innerHeight;
		var x1 = Math.min(Math.max(0, rect.left), vw);
		var y1 = Math.min(Math.max(0, rect.top), vh);
		var x2 = Math.min(Math.max(0, rect.right), vw);
		var y2 = Math.min(Math.max(0, rect.bottom), vh);

		top.style.top = '0px';
		top.style.left = '0px';
		top.style.right = '0px';
		top.style.width = '100%';
		top.style.height = y1 + 'px';
		top.style.bottom = 'auto';

		bottom.style.top = y2 + 'px';
		bottom.style.left = '0px';
		bottom.style.right = '0px';
		bottom.style.bottom = '0px';
		bottom.style.width = '100%';
		bottom.style.height = 'auto';

		var midH = Math.max(0, y2 - y1);
		left.style.top = y1 + 'px';
		left.style.left = '0px';
		left.style.width = x1 + 'px';
		left.style.height = midH + 'px';
		left.style.right = 'auto';
		left.style.bottom = 'auto';

		right.style.top = y1 + 'px';
		right.style.left = x2 + 'px';
		right.style.right = '0px';
		right.style.height = midH + 'px';
		right.style.bottom = 'auto';
		right.style.width = 'auto';
	}

	function pp_show_parent_backdrop() {
		var root = document.getElementById('pp-parent-modal-backdrop');
		if (!root) {
			root = document.createElement('div');
			root.id = 'pp-parent-modal-backdrop';
			root.className = 'pp-parent-modal-backdrop';
			root.setAttribute('aria-hidden', 'true');
			root.addEventListener('click', function (e) {
				var seg = e.target.closest('.pp-parent-modal-backdrop__segment');
				if (!seg) {
					return;
				}
				e.preventDefault();
				e.stopPropagation();
				pp_send_message('parent_backdrop_click', {});
			});
			['top', 'bottom', 'left', 'right'].forEach(function (name) {
				var seg = document.createElement('div');
				seg.className =
					'pp-parent-modal-backdrop__segment pp-parent-modal-backdrop__segment--' + name;
				seg.setAttribute('data-pp-segment', name);
				root.appendChild(seg);
			});
			document.body.appendChild(root);
		}
		ppParentBackdropActive = true;
		pp_update_parent_backdrop_segments();
		var container = document.querySelector('.pp-iframe-container');
		if (container) {
			container.classList.add('pp-iframe-container--modal-open');
		}
	}

	function pp_hide_parent_backdrop() {
		ppParentBackdropActive = false;
		var el = document.getElementById('pp-parent-modal-backdrop');
		if (el) {
			el.remove();
		}
		var container = document.querySelector('.pp-iframe-container');
		if (container) {
			container.classList.remove('pp-iframe-container--modal-open');
		}
	}

	window.addEventListener('message', function (event) {
		if (!ppOriginAllowed(event.origin)) {
			return;
		}
		if (!event.data || typeof event.data.type !== 'string') {
			return;
		}
		var fn = pp_message_commands[event.data.type];
		if (fn) {
			fn(event.data);
		}
	});

	var ppViewportBroadcastOn = false;
	var ppViewportTimer = null;
	var ppViewportRaf = 0;

	function pp_collect_viewport_for_iframe() {
		var iframe = document.getElementById('pp-iframe');
		if (!iframe) {
			return null;
		}
		var rect = iframe.getBoundingClientRect();
		var iframeAbsTop = rect.top + window.scrollY;
		var adminBar = document.getElementById('wpadminbar');
		var adminBarHeight = adminBar ? adminBar.offsetHeight : 0;
		return {
			scrollY: window.scrollY,
			viewportHeight: window.innerHeight,
			viewportWidth: window.innerWidth,
			iframeAbsTop: iframeAbsTop,
			iframeLeft: rect.left,
			adminBarHeight: adminBarHeight,
		};
	}

	function pp_send_viewport_to_iframe() {
		var payload = pp_collect_viewport_for_iframe();
		if (!payload) {
			return;
		}
		pp_send_message('viewport_update', payload);
		if (ppParentBackdropActive) {
			pp_update_parent_backdrop_segments();
		}
	}

	function pp_send_viewport_to_iframe_scheduled() {
		if (ppViewportRaf) {
			return;
		}
		ppViewportRaf = requestAnimationFrame(function () {
			ppViewportRaf = 0;
			pp_send_viewport_to_iframe();
		});
	}

	function pp_start_viewport_broadcast() {
		if (ppViewportBroadcastOn) {
			return;
		}
		ppViewportBroadcastOn = true;
		pp_send_viewport_to_iframe();
		ppViewportTimer = window.setInterval(pp_send_viewport_to_iframe, 500);
		window.addEventListener('scroll', pp_send_viewport_to_iframe_scheduled, { passive: true });
		window.addEventListener('resize', pp_send_viewport_to_iframe_scheduled, { passive: true });
	}

	function pp_send_message(type, data) {
		var msg = Object.assign({ type: type }, data || {});
		var iframe = document.getElementById('pp-iframe');
		if (!iframe || !iframe.contentWindow || !iframe.src) {
			return;
		}
		var targetOrigin;
		try {
			targetOrigin = new URL(iframe.src, window.location.href).origin;
		} catch (e) {
			return;
		}
		if (!ppOriginAllowed(targetOrigin)) {
			return;
		}
		iframe.contentWindow.postMessage(msg, targetOrigin);
	}

	var pp_message_commands = {
		request_token_refresh: function () {
			var ajaxUrl = typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php';
			var nonce = PP_AUTH2_NONCE || '';
			fetch(ajaxUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: new URLSearchParams([
					['action', 'parrotposter_refresh_session_token'],
					['parrotposter[nonce]', nonce],
				]).toString(),
				credentials: 'same-origin',
			})
				.then(function (r) {
					return r.json();
				})
				.then(function (res) {
					pp_send_message('token_refresh_result', {
						token: res && res.token ? String(res.token) : '',
						error: res && res.error ? res.error : null,
					});
				})
				.catch(function () {
					pp_send_message('token_refresh_result', {
						token: '',
						error: 'network',
					});
				});
		},
		reconnect_plugin: function () {
			var ajaxUrl = typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php';
			var nonce = PP_AUTH2_NONCE || '';
			fetch(ajaxUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: new URLSearchParams([
					['action', 'parrotposter_reconnect_plugin'],
					['parrotposter[nonce]', nonce],
				]).toString(),
				credentials: 'same-origin',
			})
				.then(function (r) {
					return r.json();
				})
				.then(function (res) {
					pp_send_message('reconnect_plugin_result', {
						ok: !!(res && res.success),
						error: res && res.error ? res.error : null,
					});
				})
				.catch(function () {
					pp_send_message('reconnect_plugin_result', {
						ok: false,
						error: 'network',
					});
				});
		},
		// WP-11: source-descriptor-root / -frame / field-schema over this bridge —
		// PP backend is unreachable from the site, so the embedded front-app iframe
		// asks the parent admin page to fetch them locally instead (DEC-002-06 D3).
		// Same request/response pattern as request_token_refresh above.
		source_descriptor_root: function () {
			var ajaxUrl = typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php';
			var nonce = PP_AUTH2_NONCE || '';
			fetch(ajaxUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: new URLSearchParams([
					['action', 'parrotposter_bridge_source_descriptor_root'],
					['parrotposter[nonce]', nonce],
				]).toString(),
				credentials: 'same-origin',
			})
				.then(function (r) {
					return r.json();
				})
				.then(function (res) {
					pp_send_message('source_descriptor_root_result', {
						descriptor: res && !res.error ? res : null,
						error: res && res.error ? res.error : null,
					});
				})
				.catch(function () {
					pp_send_message('source_descriptor_root_result', {
						descriptor: null,
						error: 'network',
					});
				});
		},
		source_descriptor_frame: function (data) {
			var ajaxUrl = typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php';
			var nonce = PP_AUTH2_NONCE || '';
			fetch(ajaxUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: new URLSearchParams([
					['action', 'parrotposter_bridge_source_descriptor_frame'],
					['parrotposter[nonce]', nonce],
					['selections_prefix', JSON.stringify((data && data.selections_prefix) || [])],
				]).toString(),
				credentials: 'same-origin',
			})
				.then(function (r) {
					return r.json();
				})
				.then(function (res) {
					pp_send_message('source_descriptor_frame_result', {
						descriptor: res && res.descriptor ? res.descriptor : null,
						error: res && res.error ? res.error : null,
					});
				})
				.catch(function () {
					pp_send_message('source_descriptor_frame_result', {
						descriptor: null,
						error: 'network',
					});
				});
		},
		field_schema: function (data) {
			var ajaxUrl = typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php';
			var nonce = PP_AUTH2_NONCE || '';
			var params = [
				['action', 'parrotposter_bridge_field_schema'],
				['parrotposter[nonce]', nonce],
				['source_path', JSON.stringify((data && data.source_path) || [])],
			];
			// Optional PP UI locale — labels only (same as GET /fields?locale=).
			var locale = (data && (data.locale || data.lang)) || '';
			if (locale) {
				params.push(['locale', String(locale)]);
			}
			fetch(ajaxUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: new URLSearchParams(params).toString(),
				credentials: 'same-origin',
			})
				.then(function (r) {
					return r.json();
				})
				.then(function (res) {
					pp_send_message('field_schema_result', {
						fields: res && res.fields ? res.fields : [],
						sections: res && res.sections ? res.sections : [],
						filter_capabilities: res && res.filter_capabilities ? res.filter_capabilities : null,
						error: res && res.error ? res.error : null,
					});
				})
				.catch(function () {
					pp_send_message('field_schema_result', {
						fields: [],
						sections: [],
						filter_capabilities: null,
						error: 'network',
					});
				});
		},
		// Latest published items for the template-preview picker. Same reason as
		// field_schema: PP backend often cannot reach callback_url, so the iframe
		// asks the parent admin page to run WP_Query locally (DEC-002-06 D3).
		list_preview_items: function (data) {
			var ajaxUrl = typeof ajaxurl !== 'undefined' ? ajaxurl : '/wp-admin/admin-ajax.php';
			var nonce = PP_AUTH2_NONCE || '';
			var params = [
				['action', 'parrotposter_bridge_list_preview_items'],
				['parrotposter[nonce]', nonce],
				['source_path', JSON.stringify((data && data.source_path) || [])],
			];
			if (data && data.limit != null) {
				params.push(['limit', String(data.limit)]);
			}
			if (data && data.offset != null) {
				params.push(['offset', String(data.offset)]);
			}
			if (data && data.filter) {
				params.push([
					'filter',
					typeof data.filter === 'string' ? data.filter : JSON.stringify(data.filter),
				]);
			}
			if (data && data.required_fields) {
				params.push([
					'required_fields',
					typeof data.required_fields === 'string'
						? data.required_fields
						: JSON.stringify(data.required_fields),
				]);
			}
			if (data && data.pipeline_id) {
				params.push(['pipeline_id', String(data.pipeline_id)]);
			}
			fetch(ajaxUrl, {
				method: 'POST',
				headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
				body: new URLSearchParams(params).toString(),
				credentials: 'same-origin',
			})
				.then(function (r) {
					return r.json();
				})
				.then(function (res) {
					pp_send_message('list_preview_items_result', {
						items: res && res.items ? res.items : [],
						next_offset: res && res.next_offset != null ? res.next_offset : null,
						error: res && res.error ? res.error : null,
					});
				})
				.catch(function () {
					pp_send_message('list_preview_items_result', {
						items: [],
						next_offset: null,
						error: 'network',
					});
				});
		},
		resize: function (data) {
			var iframe = document.getElementById('pp-iframe');
			if (iframe) {
				iframe.style.height = data.height + 'px';
			}
			pp_send_message('resize_result', {});
			pp_start_viewport_broadcast();
		},
		modal_open: function () {
			pp_show_parent_backdrop();
			pp_start_viewport_broadcast();
			document.documentElement.style.overflow = 'hidden';
			var payload = pp_collect_viewport_for_iframe();
			if (!payload) {
				pp_send_message('modal_open_result', {
					scrollY: window.scrollY,
					viewportHeight: window.innerHeight,
					viewportWidth: window.innerWidth,
					iframeAbsTop: window.scrollY,
					iframeLeft: 0,
					adminBarHeight: 0,
				});
				return;
			}
			pp_send_message('modal_open_result', payload);
		},
		modal_close: function () {
			pp_hide_parent_backdrop();
			document.documentElement.style.overflow = '';
			pp_send_message('modal_close_result', {});
		},
		prepare_callback: function () {
			pp_send_message('prepare_callback_result', {
				url: location.href,
			});
		},
		goto: function (data) {
			var items = cfg.menuItems || [];
			var menu_id = data.url;
			var item = items.find(function (v) {
				return v.id === menu_id;
			});
			location.href = item ? 'admin.php?page=' + item.id : data.url;
		},
		login: function (data) {
			var params = new URLSearchParams([
				['action', 'parrotposter_auth2'],
				['parrotposter[nonce]', PP_AUTH2_NONCE],
				['parrotposter[token]', data.token],
				['parrotposter[user_id]', data.userId],
			]);
			fetch(cfg.adminPostUrl || '', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/x-www-form-urlencoded',
				},
				body: params,
				credentials: 'same-origin',
			})
				.then(function (resp) {
					return resp.text().then(function (txt) {
						if (txt === 'ok') {
							location.href = cfg.accountsPageUrl || '';
						} else {
							pp_send_message('login_error');
						}
					});
				})
				.catch(function () {
					pp_send_message('login_error');
				});
		},
	};

	window.addEventListener('load', function () {
		var init = cfg.iframeInit;
		if (typeof ParrotPoster !== 'undefined' && ParrotPoster.initIframe && init) {
			ParrotPoster.initIframe(init);
		}
	});
})();

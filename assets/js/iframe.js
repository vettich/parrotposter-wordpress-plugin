/**
 * ParrotPoster: загрузка iframe с выбором endpoint, ping и handshake pp:ready.
 * Адаптировано из Bitrix VettichSP3.initIframe.
 */
(function (window) {
	'use strict';

	var ParrotPoster = window.ParrotPoster || (window.ParrotPoster = {});

	var STORAGE_KEY = 'parrotposter_endpoint';

	function sleep(ms) {
		return new Promise(function (resolve) {
			setTimeout(resolve, ms);
		});
	}

	ParrotPoster.initIframe = function (config) {
		var container = config.container;
		var endpoints = config.endpoints || [];
		var path = config.path || '';
		var token = config.token || '';
		var lang = config.lang || 'en';
		var moduleReadOnly = config.moduleReadOnly ? 1 : 0;
		var sitePage = config.sitePage || '';
		var timeout = config.timeout != null ? config.timeout : 5000;
		var pingPath = config.pingPath || '';
		var pingTimeout = config.pingTimeout != null ? config.pingTimeout : 2500;
		var extendedHandshakeTimeout =
			config.extendedHandshakeTimeout != null ? config.extendedHandshakeTimeout : 60000;
		var debug = !!config.debug;
		var loadingLabelDelayMs =
			config.loadingLabelDelayMs != null ? config.loadingLabelDelayMs : 450;
		var ppUnavailable = !!config.pp_unavailable;
		var iframeAutoRetries =
			config.iframeAutoRetries != null ? Math.max(0, Math.floor(Number(config.iframeAutoRetries))) : 2;
		var iframeAutoRetryDelayMs =
			config.iframeAutoRetryDelayMs != null ? Math.max(0, Math.floor(Number(config.iframeAutoRetryDelayMs))) : 3000;
		var msg = config.messages || {};

		var el =
			typeof container === 'string' ? document.querySelector(container) : container;
		if (!el) {
			console.error('ParrotPoster: container not found');
			return;
		}

		ParrotPoster._lastIframeEmbedConfig = config;
		ParrotPoster._lastIframeEmbedRoot = el;

		function m(key, fallback) {
			var v = msg[key];
			return v != null && String(v).trim() !== '' ? String(v) : fallback;
		}

		function showIframeLoadError(messageHtml) {
			var wrap = document.createElement('div');
			wrap.className = 'parrotposter-iframe-error-state';
			var body = document.createElement('div');
			body.className = 'parrotposter-iframe-error-body';
			body.innerHTML = messageHtml;
			wrap.appendChild(body);
			var actions = document.createElement('div');
			actions.className = 'parrotposter-iframe-reload-row';
			var btn = document.createElement('button');
			btn.type = 'button';
			btn.className = 'button button-primary';
			btn.textContent = m('reload', 'Reload');
			btn.addEventListener('click', function (ev) {
				if (ev) {
					ev.preventDefault();
					ev.stopPropagation();
				}
				var cfg = ParrotPoster._lastIframeEmbedConfig;
				var root = ParrotPoster._lastIframeEmbedRoot;
				if (!cfg) {
					window.location.reload();
					return;
				}
				/* Token and pp_unavailable come from PHP at page load; client-side initIframe
				 * cannot refresh them. If PP was down or session was not issued, initIframe
				 * exits early (no network) until the admin page is re-rendered. */
				var token = cfg.token;
				var hasToken = token != null && String(token).length > 0;
				if (cfg.pp_unavailable || !hasToken) {
					window.location.reload();
					return;
				}
				var nextCfg = {};
				for (var k in cfg) {
					if (Object.prototype.hasOwnProperty.call(cfg, k)) {
						nextCfg[k] = cfg[k];
					}
				}
				if (root && root.isConnected) {
					nextCfg.container = root;
				}
				ParrotPoster.initIframe(nextCfg);
			});
			actions.appendChild(btn);
			wrap.appendChild(actions);
			el.replaceChildren(wrap);
		}

		function showReconnectingState(attemptNumber, totalAttempts) {
			var wrap = document.createElement('div');
			wrap.className = 'pp-iframe-loading-indicator parrotposter-iframe-reconnecting';
			var tpl = m('reconnecting', '');
			var text;
			if (tpl) {
				text = tpl.replace(/#CURRENT#/g, String(attemptNumber)).replace(/#TOTAL#/g, String(totalAttempts));
			} else {
				text =
					'Retrying connection… (' + String(attemptNumber) + ' / ' + String(totalAttempts) + ')';
			}
			wrap.textContent = text;
			el.replaceChildren(wrap);
		}

		if (ppUnavailable) {
			showIframeLoadError(
				m(
					'unavailable',
					'<div class="parrotposter-iframe-load-error"><p><strong>ParrotPoster is temporarily unavailable.</strong></p><p>Please try again later.</p></div>'
				)
			);
			return;
		}

		var DBG = '[ParrotPoster iframe]';
		function dbg() {
			if (!debug) return;
			console.log.apply(console, [DBG].concat(Array.prototype.slice.call(arguments)));
		}
		function dbgWarn() {
			if (!debug) return;
			console.warn.apply(console, [DBG].concat(Array.prototype.slice.call(arguments)));
		}
		function dbgErr() {
			if (!debug) return;
			console.error.apply(console, [DBG].concat(Array.prototype.slice.call(arguments)));
		}

		var ourOriginSet = Object.create(null);
		for (var i = 0; i < endpoints.length; i++) {
			try {
				ourOriginSet[new URL(endpoints[i]).origin] = true;
			} catch (e) {
				/* skip */
			}
		}

		var cspBlockedOurService = false;

		function cspDirectiveBase(ev) {
			var raw = (ev.effectiveDirective || ev.violatedDirective || '').trim();
			if (!raw) return '';
			return raw.split(/\s+/)[0].toLowerCase();
		}

		function cspBlockedUriMatchesOurService(blockedURI) {
			if (!blockedURI || blockedURI === 'inline' || blockedURI === 'eval' || blockedURI === 'wasm-eval') {
				return false;
			}
			try {
				return !!ourOriginSet[new URL(blockedURI).origin];
			} catch (e) {
				return false;
			}
		}

		function onCspViolation(ev) {
			var dir = cspDirectiveBase(ev);
			var isFrame = dir === 'frame-src' || dir === 'child-src';
			var isConnect = dir === 'connect-src';
			if (!isFrame && !isConnect) return;
			if (cspBlockedUriMatchesOurService(ev.blockedURI)) {
				cspBlockedOurService = true;
				dbgWarn('CSP violation (our origin)', { directive: dir, blockedURI: ev.blockedURI });
			}
		}

		document.addEventListener('securitypolicyviolation', onCspViolation);

		function loadCache() {
			try {
				return localStorage.getItem(STORAGE_KEY);
			} catch (e) {
				return null;
			}
		}

		function saveCache(endpoint) {
			try {
				localStorage.setItem(STORAGE_KEY, endpoint);
			} catch (e) {
				/* ignore */
			}
		}

		function clearCache() {
			try {
				localStorage.removeItem(STORAGE_KEY);
			} catch (e) {
				/* ignore */
			}
		}

		function pingOnce(endpoint, ms) {
			var url = new URL(pingPath, endpoint).href;
			var t0 = Date.now();
			var controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
			var timer = null;
			if (controller && ms > 0) {
				timer = setTimeout(function () {
					controller.abort();
				}, ms);
			}
			return fetch(url, {
				method: 'GET',
				signal: controller ? controller.signal : undefined,
				cache: 'no-store',
				credentials: 'omit',
				mode: 'cors',
			})
				.then(function (res) {
					if (timer) clearTimeout(timer);
					var dt = Date.now() - t0;
					var ok = res.ok;
					dbg('ping', { endpoint: endpoint, ok: ok, ms: dt, status: res.status });
					return ok;
				})
				.catch(function (err) {
					if (timer) clearTimeout(timer);
					var dt = Date.now() - t0;
					var name = err && err.name;
					if (name === 'AbortError' || name === 'TypeError' || name === 'NetworkError') {
						dbg('ping', { endpoint: endpoint, ok: false, ms: dt, reason: name || 'network' });
						return false;
					}
					dbg('ping', { endpoint: endpoint, ok: null, ms: dt, reason: name || String(err) });
					return null;
				});
		}

		function startBackgroundPings() {
			if (!pingPath) {
				dbg('background pings skipped (no pingPath)');
				return null;
			}
			dbg('background pings started', { count: endpoints.length, pingTimeout: pingTimeout });
			var map = Object.create(null);
			for (var j = 0; j < endpoints.length; j++) {
				var ep = endpoints[j];
				if (!map[ep]) {
					map[ep] = pingOnce(ep, pingTimeout);
				}
			}
			return map;
		}

		function createIframe(src) {
			var iframe = document.createElement('iframe');
			iframe.id = 'pp-iframe';
			iframe.style.width = '100%';
			iframe.style.border = '0';
			iframe.setAttribute('frameborder', '0');
			iframe.setAttribute('allowtransparency', 'true');
			iframe.src = src;
			return iframe;
		}

		function waitForHandshake(origin, initialTimeoutMs) {
			var done = false;
			var timer = null;
			var handler = null;
			var rejectFn = null;
			var deadline = Date.now() + initialTimeoutMs;
			var hsStarted = Date.now();

			function cleanup() {
				if (done) return;
				done = true;
				if (handler) window.removeEventListener('message', handler);
				if (timer) clearTimeout(timer);
			}

			function scheduleTimer() {
				if (done) return;
				if (timer) clearTimeout(timer);
				var ms = Math.max(0, deadline - Date.now());
				timer = setTimeout(function () {
					if (done) return;
					dbg('handshake timeout', { origin: origin, sinceHandshakeMs: Date.now() - hsStarted });
					cleanup();
					rejectFn(new Error('Handshake timeout'));
				}, ms);
			}

			function ensureMinWait(msFromNow) {
				if (done) return;
				var minDeadline = Date.now() + msFromNow;
				if (minDeadline > deadline) {
					deadline = minDeadline;
					scheduleTimer();
				}
			}

			var promise = new Promise(function (resolve, reject) {
				rejectFn = reject;
				handler = function (e) {
					if (e.origin !== origin) return;
					if (e.data === 'pp:ready') {
						dbg('pp:ready', { origin: origin });
						cleanup();
						resolve();
					}
				};
				window.addEventListener('message', handler);
				scheduleTimer();
			});

			return {
				promise: promise,
				ensureMinWait: ensureMinWait,
				cancel: function () {
					if (done) return;
					cleanup();
					rejectFn(new Error('Handshake aborted'));
				},
			};
		}

		function buildIframeSrc(endpoint) {
			var base = String(endpoint).replace(/\/$/, '');
			var p = path.charAt(0) === '/' ? path : '/' + path;
			var q =
				'token=' +
				encodeURIComponent(token) +
				'&lang=' +
				encodeURIComponent(lang) +
				'&read_only=' +
				moduleReadOnly +
				'&site_page=' +
				encodeURIComponent(sitePage);
			return base + p + '?' + q;
		}

		function tryEndpoint(endpoint, pingPromise) {
			var url = buildIframeSrc(endpoint);
			var origin = new URL(url).origin;
			var iframe = createIframe(url);
			var loadingHtml = m('loading', '');

			var loadingWrap = loadingHtml
				? (function () {
						var w = document.createElement('div');
						w.className = 'pp-iframe-loading-indicator';
						w.textContent = loadingHtml;
						return w;
				  })()
				: null;

			var endpointFinished = false;
			var loadingLabelTimer = null;
			if (loadingWrap) {
				if (loadingLabelDelayMs > 0) {
					loadingWrap.style.display = 'none';
				}
				el.replaceChildren(loadingWrap, iframe);
				if (loadingLabelDelayMs > 0) {
					loadingLabelTimer = setTimeout(function () {
						loadingLabelTimer = null;
						if (!endpointFinished && loadingWrap.isConnected) {
							loadingWrap.style.display = '';
						}
					}, loadingLabelDelayMs);
				}
			} else {
				el.replaceChildren(iframe);
			}

			var initialHandshakeMs = pingPromise ? Math.max(timeout, pingTimeout + 2000) : timeout;
			var hs = waitForHandshake(origin, initialHandshakeMs);
			var hsPromise = hs.promise;
			var cancelHandshake = hs.cancel;
			var ensureMinWait = hs.ensureMinWait;

			if (pingPromise) {
				pingPromise.then(function (p) {
					if (endpointFinished || p !== true) return;
					ensureMinWait(extendedHandshakeTimeout);
				});
			}

			return new Promise(function (resolve, reject) {
				function fail(e) {
					endpointFinished = true;
					if (loadingLabelTimer) {
						clearTimeout(loadingLabelTimer);
						loadingLabelTimer = null;
					}
					cancelHandshake();
					hsPromise.catch(function () {});
					iframe.remove();
					if (loadingWrap) loadingWrap.remove();
					reject(e);
				}

				function ok() {
					endpointFinished = true;
					if (loadingLabelTimer) {
						clearTimeout(loadingLabelTimer);
						loadingLabelTimer = null;
					}
					if (loadingWrap) loadingWrap.remove();
					resolve(endpoint);
				}

				var racePing = pingPromise
					? Promise.race([
							hsPromise,
							pingPromise.then(function (p) {
								if (p === false) throw new Error('Ping failed');
								return new Promise(function () {});
							}),
					  ])
					: hsPromise;

				racePing.then(ok).catch(fail);
			});
		}

		function resolveEndpointSeq() {
			var cached = loadCache();
			var pingByEndpoint = startBackgroundPings();
			var ordered = cached
				? [cached].concat(
						endpoints.filter(function (e) {
							return e !== cached;
						})
				  )
				: endpoints.slice();

			function attempt(i) {
				if (i >= ordered.length) {
					return Promise.reject(new Error('All endpoints failed'));
				}
				var endpoint = ordered[i];
				var pingPromise = pingByEndpoint ? pingByEndpoint[endpoint] : null;
				return tryEndpoint(endpoint, pingPromise).then(
					function (okEp) {
						saveCache(okEp);
						return okEp;
					},
					function () {
						if (endpoint === cached) {
							clearCache();
						}
						return attempt(i + 1);
					}
				);
			}

			return attempt(0);
		}

		var autoRetryDelay = iframeAutoRetryDelayMs;
		var totalLoadAttempts = 1 + iframeAutoRetries;

		(function runEmbedWithAutoRetries() {
			function finalFail(err) {
				dbgErr('resolveEndpoint fatal', err);
				var errHtml = cspBlockedOurService ? m('loadErrorCsp', '') : m('loadError', '');
				if (!errHtml) {
					errHtml =
						'<div class="parrotposter-iframe-load-error"><p><strong>Failed to load ParrotPoster.</strong></p><p>Check your network or try again.</p></div>';
				}
				showIframeLoadError(errHtml);
				document.removeEventListener('securitypolicyviolation', onCspViolation);
			}

			function attemptLoad(i) {
				resolveEndpointSeq()
					.then(function () {
						document.removeEventListener('securitypolicyviolation', onCspViolation);
					})
					.catch(function (err) {
						dbgErr('resolveEndpoint failed', err, {
							attempt: i + 1,
							totalLoadAttempts: totalLoadAttempts,
						});
						if (i + 1 < totalLoadAttempts) {
							showReconnectingState(i + 2, totalLoadAttempts);
							sleep(autoRetryDelay).then(function () {
								attemptLoad(i + 1);
							});
						} else {
							finalFail(err);
						}
					});
			}

			attemptLoad(0);
		})();
	};
})(window);

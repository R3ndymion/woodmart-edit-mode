(function() {
	'use strict';

	var config = window.wdemLive;
	var settingsFrame = document.querySelector('.wdem-live-settings');
	var previewFrame = document.querySelector('.wdem-live-preview');
	var stage = document.querySelector('.wdem-live-stage');
	var state = document.querySelector('.wdem-live-state');
	var saveButton = document.querySelector('.wdem-live-save');
	var discardButton = document.querySelector('.wdem-live-discard');

	var unsaved = false;
	var saving = false;
	var pending = 0;
	var restoreScroll = null;

	function setState(key) {
		state.textContent = state.getAttribute('data-' + key);
		state.classList.toggle('wdem-unsaved', 'unsaved' === key);
	}

	function refreshState() {
		if (saving) {
			setState('saving');
		} else if (pending) {
			setState('updating');
		} else {
			setState(unsaved ? 'unsaved' : 'saved');
		}

		saveButton.disabled = !unsaved || saving;
		discardButton.disabled = !unsaved || saving;
	}

	function busy(delta) {
		pending = Math.max(0, pending + delta);
		document.body.classList.toggle('wdem-busy', pending > 0);
		refreshState();
	}

	// The page the preview is on right now, which is not always where it started: links inside it
	// keep the preview parameter, so the visitor can walk the site and keep editing.
	function currentPreviewUrl() {
		try {
			var url = new URL(previewFrame.contentWindow.location.href);

			if (url.searchParams.get(config.previewVar)) {
				return url;
			}
		} catch (e) {
			// Not reachable, start over from the original page.
		}

		return new URL(config.previewUrl);
	}

	function reloadPreview() {
		var win = previewFrame.contentWindow;

		try {
			restoreScroll = win ? win.scrollY : null;
		} catch (e) {
			restoreScroll = null;
		}

		busy(1);
		previewFrame.src = currentPreviewUrl().toString();
	}

	// Only the stylesheet changed, so only the stylesheet is replaced. No reload, no lost scroll.
	function swapCss() {
		var url = currentPreviewUrl();
		var doc;

		try {
			doc = previewFrame.contentDocument;
		} catch (e) {
			doc = null;
		}

		var style = doc ? doc.getElementById('wdem-live-css') : null;

		if (!style) {
			reloadPreview();
			return;
		}

		url.searchParams.set(config.cssVar, '1');
		url.searchParams.set('_', Date.now());

		busy(1);

		fetch(url.toString(), { credentials: 'same-origin', cache: 'no-store' })
			.then(function(response) {
				if (!response.ok) {
					throw new Error(response.status);
				}

				return response.text();
			})
			.then(function(css) {
				style.textContent = css;
				busy(-1);
			})
			.catch(function() {
				busy(-1);
				reloadPreview();
			});
	}

	previewFrame.addEventListener('load', function() {
		if (pending) {
			busy(-1);
		}

		if (null !== restoreScroll) {
			try {
				previewFrame.contentWindow.scrollTo(0, restoreScroll);
			} catch (e) {
				// Nothing to restore into.
			}

			restoreScroll = null;
		}
	});

	window.addEventListener('message', function(event) {
		if (event.origin !== window.location.origin || !event.data || 'object' !== typeof event.data || !event.data.wdemLive) {
			return;
		}

		var data = event.data;

		switch (data.wdemLive) {
			case 'sending':
				busy(1);
				break;

			case 'update':
				busy(-1);
				unsaved = !!data.unsaved;
				refreshState();

				if (data.changed) {
					if (data.cssOnly) {
						swapCss();
					} else {
						reloadPreview();
					}
				}
				break;

			case 'failed':
				busy(-1);
				break;

			// The settings page came back from its own submit: the values are in the database now,
			// and the theme has rebuilt its stylesheet on the way.
			case 'saved':
				saving = false;
				unsaved = false;
				refreshState();
				reloadPreview();
				break;

			// The settings page loaded without a save behind it.
			case 'ready':
				saving = false;
				refreshState();
				break;
		}
	});

	saveButton.addEventListener('click', function() {
		if (!unsaved || saving) {
			return;
		}

		saving = true;
		refreshState();
		settingsFrame.contentWindow.postMessage({ wdemLive: 'save' }, window.location.origin);
	});

	discardButton.addEventListener('click', function() {
		if (!unsaved || saving || !window.confirm(config.labels.discard)) {
			return;
		}

		var body = new FormData();
		body.append('action', 'wdem_live_discard');
		body.append('_ajax_nonce', config.nonce);

		busy(1);

		fetch(config.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function() {
				busy(-1);
				unsaved = false;
				refreshState();

				// The settings page goes back to the saved values by loading them again.
				settingsFrame.contentWindow.postMessage({ wdemLive: 'discard' }, window.location.origin);
				reloadPreview();
			})
			.catch(function() {
				busy(-1);
			});
	});

	document.querySelectorAll('.wdem-live-device').forEach(function(button) {
		button.addEventListener('click', function() {
			document.querySelectorAll('.wdem-live-device').forEach(function(other) {
				other.classList.toggle('wdem-active', other === button);
			});

			stage.setAttribute('data-device', button.getAttribute('data-device'));
		});
	});

	window.addEventListener('beforeunload', function(event) {
		if (unsaved && !saving) {
			event.preventDefault();
			event.returnValue = config.labels.leave;

			return config.labels.leave;
		}
	});

	// The panel width is a preference of this browser, nothing more.
	(function() {
		var resizer = document.querySelector('.wdem-live-resizer');
		var root = document.documentElement;
		var KEY = 'wdem_live_panel_width';

		try {
			var stored = parseInt(window.localStorage.getItem(KEY), 10);

			if (stored) {
				root.style.setProperty('--wdem-panel-w', stored + 'px');
			}
		} catch (e) {
			// Default width.
		}

		resizer.addEventListener('pointerdown', function(event) {
			event.preventDefault();
			document.body.classList.add('wdem-resizing');

			var isRtl = 'rtl' === document.documentElement.dir;

			function move(moveEvent) {
				var width = isRtl ? window.innerWidth - moveEvent.clientX : moveEvent.clientX;

				width = Math.max(360, Math.min(width, window.innerWidth * 0.7));
				root.style.setProperty('--wdem-panel-w', Math.round(width) + 'px');
			}

			function stop() {
				document.body.classList.remove('wdem-resizing');
				window.removeEventListener('pointermove', move);
				window.removeEventListener('pointerup', stop);

				try {
					window.localStorage.setItem(KEY, parseInt(root.style.getPropertyValue('--wdem-panel-w'), 10));
				} catch (e) {
					// Not remembered.
				}
			}

			window.addEventListener('pointermove', move);
			window.addEventListener('pointerup', stop);
		});
	})();

	busy(1);
	refreshState();
})();

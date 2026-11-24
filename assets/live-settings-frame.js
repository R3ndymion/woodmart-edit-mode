(function() {
	'use strict';

	var settings = window.wdemLiveFrame;
	var parentWindow = window.parent;

	// Loaded on its own rather than inside the editor: nothing to bridge to.
	if (!settings || parentWindow === window) {
		return;
	}

	var PREFIX = settings.option + '[';
	var SAVING_KEY = 'wdem_live_saving';
	var POLL = 600;
	var DEBOUNCE = 250;

	var form = null;
	var initial = null;
	var lastSent = null;
	var inFlight = false;
	var queued = false;
	var timer = null;
	var held = false;

	function post(message) {
		message.wdemLive = message.wdemLive || 'update';
		parentWindow.postMessage(message, window.location.origin);
	}

	// Walked rather than queried: a bracket inside an attribute selector is not read the same way by
	// every selector engine.
	function findForm() {
		var inputs = document.querySelectorAll('input[name], select[name], textarea[name]');

		for (var i = 0; i < inputs.length; i++) {
			if (0 === inputs[i].name.indexOf(PREFIX) && inputs[i].form) {
				return inputs[i].form;
			}
		}

		return null;
	}

	// Every input of the options form, grouped by the option it belongs to. Controls that keep their
	// value in a hidden input — colour pickers, uploads, ranges — are read like any other.
	function readFields() {
		if (window.tinymce && window.tinymce.triggerSave) {
			window.tinymce.triggerSave();
		}

		var fields = {};
		var data = new FormData(form);

		data.forEach(function(value, name) {
			if (0 !== name.indexOf(PREFIX)) {
				return;
			}

			var id = name.slice(PREFIX.length, name.indexOf(']'));

			if (!id || -1 !== settings.reserved.indexOf(id)) {
				return;
			}

			if (!fields[id]) {
				fields[id] = [];
			}

			fields[id].push([ name, 'string' === typeof value ? value : '' ]);
		});

		return fields;
	}

	// The options that differ from what the page loaded with, which is what is saved.
	function diff(current) {
		var changed = {};
		var ids = Object.keys(initial).concat(Object.keys(current));

		ids.forEach(function(id) {
			var before = JSON.stringify(initial[id] || []);
			var after = JSON.stringify(current[id] || []);

			if (before !== after) {
				changed[id] = current[id] || [];
			}
		});

		return changed;
	}

	function check() {
		timer = null;

		// A colour being dragged across the picker, a slider being pulled: the value settles when
		// the button is let go, and only that value is worth a trip to the preview.
		if (!form || !initial || held) {
			return;
		}

		var changed = diff(readFields());
		var signature = JSON.stringify(changed);

		if (signature === lastSent) {
			return;
		}

		if (inFlight) {
			queued = true;
			return;
		}

		send(changed, signature);
	}

	// The button is down on a control of the form. The window hears the release even when the
	// drag wandered off the control; a release outside the panel never reaches it at all, so the
	// first move with no button down ends the hold too.
	function holdWhilePressed() {
		function release() {
			if (!held) {
				return;
			}

			held = false;
			schedule();
		}

		form.addEventListener('pointerdown', function(event) {
			if (0 === event.button) {
				held = true;
			}
		}, true);

		window.addEventListener('pointerup', release, true);
		window.addEventListener('pointercancel', release, true);
		window.addEventListener('blur', release);
		document.addEventListener('pointermove', function(event) {
			if (held && 0 === event.buttons) {
				release();
			}
		}, true);
	}

	function schedule() {
		if (null !== timer) {
			window.clearTimeout(timer);
		}

		timer = window.setTimeout(check, DEBOUNCE);
	}

	function send(changed, signature) {
		var body = new FormData();

		body.append('action', 'wdem_live_update');
		body.append('_ajax_nonce', settings.nonce);

		Object.keys(changed).forEach(function(id) {
			body.append('changed[]', id);

			changed[id].forEach(function(pair) {
				body.append(pair[0], pair[1]);
			});
		});

		inFlight = true;
		post({ wdemLive: 'sending' });

		fetch(settings.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body })
			.then(function(response) {
				return response.json();
			})
			.then(function(response) {
				if (!response || !response.success) {
					throw new Error('rejected');
				}

				lastSent = signature;

				post({
					wdemLive: 'update',
					changed: response.data.changed.length,
					cssOnly: response.data.cssOnly,
					unsaved: response.data.unsaved
				});
			})
			.catch(function() {
				post({ wdemLive: 'failed' });
			})
			.then(function() {
				inFlight = false;

				if (queued) {
					queued = false;
					check();
				}
			});
	}

	// The submit goes through options.php and comes back through the theme, which may move on to a
	// clean address before this script runs again. A mark that survives the round trip is the only
	// reliable way to know the next page is the result of a save.
	function markSaving(on) {
		try {
			if (on) {
				window.sessionStorage.setItem(SAVING_KEY, '1');
			} else {
				window.sessionStorage.removeItem(SAVING_KEY);
			}
		} catch (e) {
			// Falls back to the settings-updated flag.
		}
	}

	function wasSaving() {
		try {
			return '1' === window.sessionStorage.getItem(SAVING_KEY);
		} catch (e) {
			return false;
		}
	}

	function save() {
		var button = form.querySelector('.xts-options-actions .xts-save-options-btn');

		if (window.tinymce && window.tinymce.triggerSave) {
			window.tinymce.triggerSave();
		}

		markSaving(true);

		// The theme's own button, so whatever the theme does on submit still happens.
		if (button) {
			button.click();
		} else if (form.requestSubmit) {
			form.requestSubmit();
		} else {
			form.submit();
		}
	}

	// The saved values are the new baseline: nothing is unsaved until the next edit.
	function onSaved() {
		markSaving(false);

		if (form) {
			initial = readFields();
			lastSent = JSON.stringify({});
		}

		post({ wdemLive: 'saved' });
	}

	// Discarding reloads the page to get the saved values back. The theme warns before leaving a
	// form with changes; here leaving is the point.
	function discard() {
		if (window.jQuery) {
			window.jQuery(window).off('beforeunload');
		}

		window.onbeforeunload = null;
		window.location.reload();
	}

	window.addEventListener('message', function(event) {
		if (event.origin !== window.location.origin || !event.data || !form) {
			return;
		}

		if ('save' === event.data.wdemLive) {
			save();
		} else if ('discard' === event.data.wdemLive) {
			discard();
		}
	});

	// Links out of the settings page — "edit the header", a docs link — would load whole screens into
	// the narrow panel and drop the unsaved values with it. They open in a tab of their own instead.
	// Links back into the settings page are its own navigation and stay put.
	document.addEventListener('click', function(event) {
		var link = event.target instanceof Element ? event.target.closest('a[href]') : null;

		if (!link || link.target) {
			return;
		}

		var href = link.getAttribute('href');

		if (!href || '#' === href.charAt(0) || 0 === href.indexOf('javascript:') || -1 !== href.indexOf('page=xts_theme_settings')) {
			return;
		}

		link.target = '_blank';
	}, true);

	function start() {
		var saved = wasSaving() || !!settings.saved;

		markSaving(false);
		form = findForm();

		if (!form) {
			post({ wdemLive: saved ? 'saved' : 'ready' });
			return;
		}

		setupViews();

		// WoodMart 8.7 saves the form over AJAX and announces it; older versions submit the page,
		// which the mark above covers.
		if (window.jQuery) {
			window.jQuery(document).on('woodmart_options_saved', onSaved);
		}

		// The theme initialises its controls on load; read the baseline after it has settled, or
		// its own normalisation of a value would look like an edit.
		window.setTimeout(function() {
			// The editor guards unsaved changes for the whole page; a second prompt from inside the
			// panel would only ask the same question twice.
			if (window.jQuery) {
				window.jQuery(window).off('beforeunload');
			}

			initial = readFields();
			lastSent = JSON.stringify({});

			holdWhilePressed();

			form.addEventListener('input', schedule, true);
			form.addEventListener('change', schedule, true);
			form.addEventListener('click', schedule, true);
			window.setInterval(check, POLL);

			post({ wdemLive: saved ? 'saved' : 'ready' });
		}, 300);
	}

	// The panel works like the customizer: one screen lists the sections, the other shows the fields
	// of one section under a bar that leads back. The theme's own navigation drives both — a click on
	// a section is the theme's click, this only decides which screen is showing. The first screen is
	// chosen on the server (body class), so nothing jumps while the page loads.
	var crumbs = null;

	function navLink(id) {
		var links = document.querySelectorAll('.xts-options-nav-col .xts-nav-vertical a[data-id]');

		for (var i = 0; i < links.length; i++) {
			if (links[i].getAttribute('data-id') === id) {
				return links[i];
			}
		}

		return null;
	}

	function linkText(link) {
		return link ? link.textContent.replace(/\s+/g, ' ').trim() : '';
	}

	function activeSectionId() {
		var section = document.querySelector('.xts-section.xts-active-section');

		return section ? section.getAttribute('data-id') : '';
	}

	function buildCrumbs() {
		var column = document.querySelector('.xts-sections');

		if (!column || crumbs) {
			return;
		}

		crumbs = document.createElement('div');
		crumbs.className = 'wdem-live-crumbs';
		crumbs.innerHTML = '<button type="button" class="wdem-live-back"></button><div class="wdem-live-crumbs-text"><span class="wdem-live-crumbs-group"></span><strong class="wdem-live-crumbs-title"></strong></div>';
		crumbs.querySelector('.wdem-live-back').setAttribute('aria-label', settings.back || 'Back');
		crumbs.querySelector('.wdem-live-back').title = settings.back || 'Back';

		crumbs.querySelector('.wdem-live-back').addEventListener('click', function() {
			setView('nav');
		});

		column.parentNode.insertBefore(crumbs, column);
	}

	function updateCrumbs() {
		if (!crumbs) {
			return;
		}

		var link = navLink(activeSectionId());
		var item = link ? link.parentNode : null;
		var group = item && item.classList.contains('xts-sub-menu-item') ? item.parentNode.closest('li') : null;

		crumbs.querySelector('.wdem-live-crumbs-group').textContent = group ? linkText(group.querySelector('a[data-id]')) : '';
		crumbs.querySelector('.wdem-live-crumbs-title').textContent = linkText(link);
	}

	function setView(view) {
		var body = document.body;

		body.classList.toggle('wdem-view-section', 'section' === view);
		body.classList.toggle('wdem-view-nav', 'section' !== view);

		if ('section' === view) {
			updateCrumbs();
			window.scrollTo(0, 0);

			return;
		}

		// Back on the list, the section just left stays in sight.
		var link = navLink(activeSectionId());

		window.scrollTo(0, 0);

		if (link && link.getBoundingClientRect().bottom > window.innerHeight) {
			link.scrollIntoView({ block: 'center' });
		}
	}

	function setupViews() {
		buildCrumbs();

		if (document.body.classList.contains('wdem-view-section')) {
			updateCrumbs();
		}

		// A group opens in place; a section opens its fields. The theme announces every switch with
		// xts_section_changed — a click in the list and a pick in its search alike, the search
		// clicking the link through jQuery, which fires no real click. A real click on a group is
		// remembered here first, so its switch to the group's first section keeps the list showing.
		var groupClicked = false;

		document.addEventListener('click', function(event) {
			var link = event.target instanceof Element ? event.target.closest('.xts-options-nav-col .xts-nav-vertical a[data-id]') : null;

			if (link) {
				groupClicked = link.parentNode.classList.contains('xts-has-child');
			}
		}, true);

		function onSectionChanged() {
			if (groupClicked) {
				groupClicked = false;
				return;
			}

			setView('section');

			// A search result highlights its field; on the new screen it has to be brought into view.
			window.setTimeout(function() {
				var field = document.querySelector('.xts-active-section .xts-highlight-field');

				if (field) {
					field.scrollIntoView({ block: 'center' });
				}
			}, 50);
		}

		if (window.jQuery) {
			window.jQuery(document).on('xts_section_changed', onSectionChanged);
		}
	}

	// After the save the frame can land back on this very document, restored from the back-forward
	// cache with no script run again. Its form holds the values just submitted, which are now the
	// saved ones, so they become the new baseline.
	window.addEventListener('pageshow', function(event) {
		if (!event.persisted || !form) {
			return;
		}

		var saved = wasSaving();

		markSaving(false);
		initial = readFields();
		lastSent = JSON.stringify({});

		post({ wdemLive: saved ? 'saved' : 'ready' });
	});

	if ('complete' === document.readyState) {
		start();
	} else {
		window.addEventListener('load', start);
	}
})();

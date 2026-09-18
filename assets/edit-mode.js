(function() {
	'use strict';

	var STORAGE_KEY = 'wdem_edit_mode';
	var MARKER_REGEXP = /^wd-em-(start|end):([\w-]+)$/;

	var settings = window.wdemEditMode;
	var groups = [];
	var overlay = null;
	var frame = null;
	var buttons = null;
	var activeGroup = null;
	var activeHolder = null;
	var activeElements = [];
	var rafId = null;
	var hideTimer = null;
	var openedDropdowns = [];

	function isEnabled() {
		try {
			return '1' === window.localStorage.getItem(STORAGE_KEY);
		} catch (e) {
			return false;
		}
	}

	function storeState(enabled) {
		try {
			window.localStorage.setItem(STORAGE_KEY, enabled ? '1' : '0');
		} catch (e) {
			// The state is not persisted when the storage is not available.
		}
	}

	// Collects the marker comments left by the PHP side and tags the elements between each pair.
	function scan() {
		var walker = document.createTreeWalker(document.body, NodeFilter.SHOW_COMMENT, null);
		var markers = [];
		var opened = [];
		var node;

		while ((node = walker.nextNode())) {
			var match = MARKER_REGEXP.exec(node.nodeValue.trim());

			if (match) {
				markers.push({ node: node, type: match[1], id: match[2] });
			}
		}

		markers.forEach(function(marker) {
			if ('start' === marker.type) {
				opened.push(marker);
				return;
			}

			for (var i = opened.length - 1; i >= 0; i--) {
				if (opened[i].id === marker.id) {
					addGroup(opened[i].id, opened[i].node, marker.node);
					opened.splice(i, 1);
					return;
				}
			}
		});

		scanSelectors();
		scanSliders();
	}

	// Sliders already carry their editor links in the markup, both for Elementor and WPBakery.
	// A slide offers its own link plus one for the slider it belongs to.
	function scanSliders() {
		var slides = document.querySelectorAll('.wd-slide[data-slide]');
		var i;

		for (i = 0; i < slides.length; i++) {
			if (slides[i].hasAttribute('data-wd-em')) {
				continue;
			}

			var carousel = slides[i].closest('[data-slider]');
			var actions = [
				readAction(slides[i], 'data-slide', settings.labels.slide),
				carousel ? readAction(carousel, 'data-slider', settings.labels.slider) : null
			];

			addTaggedGroup([ slides[i] ], actions.filter(Boolean));
		}

		// The slider chrome around the slides: arrows, pagination, the gaps between the items.
		var sliders = document.querySelectorAll('.wd-slider');

		for (i = 0; i < sliders.length; i++) {
			if (sliders[i].hasAttribute('data-wd-em')) {
				continue;
			}

			var holder = sliders[i].querySelector('[data-slider]');
			var action = holder ? readAction(holder, 'data-slider', settings.labels.slider) : null;

			addTaggedGroup([ sliders[i] ], action ? [ action ] : []);
		}
	}

	function readAction(element, attribute, type) {
		var raw = element.getAttribute(attribute);

		if (!raw) {
			return null;
		}

		try {
			var data = JSON.parse(raw);

			return data && data.url ? { title: data.title, type: type, edit_url: data.url } : null;
		} catch (e) {
			return null;
		}
	}

	// Elements that are always rendered in a known place, the header among them, need no markers.
	function scanSelectors() {
		if (!settings.selectors || !settings.selectors.length) {
			return;
		}

		settings.selectors.forEach(function(entry) {
			var elements = document.querySelectorAll(entry.selector);

			for (var i = 0; i < elements.length; i++) {
				// An earlier group already claimed this element, it is the more precise match.
				if (elements[i].hasAttribute('data-wd-em')) {
					mergeIntoGroup(elements[i], entry.actions || []);
					continue;
				}

				addTaggedGroup([ elements[i] ], entry.actions || []);
			}
		});
	}

	// An HTML block whose whole content is a contact form leaves its markers around that form and
	// nothing else, so the block and the selector describe the same element rather than one inside
	// the other. Neither editor is the wrong answer there, so both are offered, the narrower first.
	function mergeIntoGroup(element, actions) {
		var group = groups[element.getAttribute('data-wd-em')];

		// Only when the group is this element on its own: its actions are shared by everything in
		// it, and the elements beside this one are not what the selector matched.
		if (!group || !actions.length || 1 !== group.elements.length || group.elements[0] !== element) {
			return;
		}

		group.actions = actions.concat(group.actions);
	}

	function addGroup(id, startNode, endNode) {
		var block = settings.blocks && settings.blocks[id];

		if (!block) {
			return;
		}

		var elements = [];

		if (startNode.parentNode === endNode.parentNode) {
			var node = startNode.nextSibling;

			while (node && node !== endNode) {
				if (1 === node.nodeType) {
					elements.push(node);
				}

				node = node.nextSibling;
			}
		}

		// The builder may have moved the content out of the markers, fall back to their container.
		if (!elements.length && startNode.parentNode && startNode.parentNode !== document.body) {
			elements.push(startNode.parentNode);
		}

		if (!elements.length) {
			return;
		}

		addTaggedGroup(elements, [ block ]);
	}

	function addTaggedGroup(elements, actions) {
		if (!elements.length || !actions.length) {
			return;
		}

		var index = groups.length;

		groups.push({ elements: elements, actions: actions });

		elements.forEach(function(element) {
			element.setAttribute('data-wd-em', index);
		});
	}

	function buildOverlay() {
		overlay = document.createElement('div');
		overlay.className = 'wd-em-overlay';

		frame = document.createElement('div');
		frame.className = 'wd-em-frame';

		buttons = document.createElement('div');
		buttons.className = 'wd-em-buttons';

		overlay.appendChild(frame);
		overlay.appendChild(buttons);
		document.body.appendChild(overlay);
	}

	function getRect() {
		var rect = null;

		activeElements.forEach(function(element) {
			var elementRect = element.getBoundingClientRect();

			if (!elementRect.width || !elementRect.height) {
				return;
			}

			if (!rect) {
				rect = {
					top: elementRect.top,
					left: elementRect.left,
					right: elementRect.right,
					bottom: elementRect.bottom
				};

				return;
			}

			rect.top = Math.min(rect.top, elementRect.top);
			rect.left = Math.min(rect.left, elementRect.left);
			rect.right = Math.max(rect.right, elementRect.right);
			rect.bottom = Math.max(rect.bottom, elementRect.bottom);
		});

		return rect;
	}

	function position() {
		rafId = null;

		if (!activeGroup) {
			return;
		}

		var rect = getRect();

		if (!rect) {
			hide();
			return;
		}

		frame.style.top = rect.top + 'px';
		frame.style.left = rect.left + 'px';
		frame.style.width = (rect.right - rect.left) + 'px';
		frame.style.height = (rect.bottom - rect.top) + 'px';

		// The buttons overlap the frame border so the pointer never leaves the block on its way there.
		var buttonsHeight = buttons.offsetHeight;
		var minTop = getMinTop();
		var buttonsTop = rect.top - buttonsHeight + 2;

		// Blocks that start at the very top of the page leave no room above, put the buttons inside them.
		if (buttonsTop < minTop) {
			buttonsTop = Math.min(Math.max(rect.top, minTop), window.innerHeight - buttonsHeight);
		}

		buttons.style.top = Math.max(buttonsTop, minTop) + 'px';
		buttons.style.left = Math.max(Math.min(rect.left, window.innerWidth - buttons.offsetWidth), 0) + 'px';
	}

	// The admin bar is fixed on top of everything, the button has to stay below it.
	function getMinTop() {
		var adminBar = document.getElementById('wpadminbar');

		if (!adminBar) {
			return 0;
		}

		return Math.max(adminBar.getBoundingClientRect().bottom, 0);
	}

	function schedulePosition() {
		if (null === rafId && activeGroup) {
			rafId = window.requestAnimationFrame(position);
		}
	}

	function show(index, holder) {
		var group = groups[index];

		if (!group || (group === activeGroup && holder === activeHolder)) {
			return;
		}

		cancelHide();

		activeGroup = group;
		activeHolder = holder;

		// Carousels clone their slides after the scan, so a clone carries the attribute of the
		// original. Frame what the pointer is actually over whenever it is not part of the group.
		activeElements = group.elements.indexOf(holder) === -1 ? [ holder ] : group.elements;

		openDropdowns();

		buttons.innerHTML = '';

		group.actions.forEach(function(action, index) {
			buttons.appendChild(createButton(action, index > 0));
		});

		overlay.style.display = 'block';
		position();
	}

	function createButton(action, isSecondary) {
		var button = document.createElement('a');

		button.className = isSecondary ? 'wd-em-button wd-secondary' : 'wd-em-button';
		button.href = action.edit_url;
		button.target = '_blank';
		button.rel = 'noopener';

		var title = document.createElement('span');
		title.appendChild(document.createTextNode(settings.labels.edit + ': ' + action.title));
		button.appendChild(title);

		var type = document.createElement('b');
		type.appendChild(document.createTextNode(action.type));
		button.appendChild(type);

		return button;
	}

	// A block inside a dropdown would vanish as soon as the pointer leaves the menu item on its way
	// to the button, so the dropdowns around it are held open with the theme's own state class.
	function openDropdowns() {
		closeDropdowns();

		var dropdown = activeElements[0].closest('.wd-dropdown');

		while (dropdown) {
			if (!dropdown.classList.contains('wd-opened')) {
				dropdown.classList.add('wd-opened');
				openedDropdowns.push(dropdown);
			}

			dropdown = dropdown.parentElement ? dropdown.parentElement.closest('.wd-dropdown') : null;
		}
	}

	function closeDropdowns() {
		openedDropdowns.forEach(function(dropdown) {
			dropdown.classList.remove('wd-opened');
		});

		openedDropdowns = [];
	}

	function hide() {
		cancelHide();
		closeDropdowns();

		activeGroup = null;
		activeHolder = null;
		activeElements = [];

		if (overlay) {
			overlay.style.display = 'none';
		}
	}

	function cancelHide() {
		if (null !== hideTimer) {
			window.clearTimeout(hideTimer);
			hideTimer = null;
		}
	}

	// Gives the pointer time to travel from the block to the edit button.
	function scheduleHide() {
		if (!activeGroup || null !== hideTimer) {
			return;
		}

		hideTimer = window.setTimeout(hide, 250);
	}

	function setState(enabled) {
		document.documentElement.classList.toggle('wd-edit-mode-on', enabled);
		storeState(enabled);

		var item = document.getElementById('wp-admin-bar-wdem-edit-mode');

		if (item) {
			item.classList.toggle('wd-active', enabled);

			var status = item.querySelector('.wd-em-status');

			if (status) {
				status.textContent = enabled ? settings.labels.on : settings.labels.off;
			}
		}

		if (!enabled) {
			hide();
		}
	}

	function onMouseOver(event) {
		if (!isEnabled() || !(event.target instanceof Element)) {
			return;
		}

		if (event.target.closest('#wpadminbar') || event.target.closest('.wd-em-overlay')) {
			cancelHide();
			return;
		}

		var holder = event.target.closest('[data-wd-em]');

		if (!holder) {
			scheduleHide();
			return;
		}

		cancelHide();
		show(holder.getAttribute('data-wd-em'), holder);
	}

	function init() {
		if (!settings) {
			return;
		}

		var toggle = document.querySelector('#wp-admin-bar-wdem-edit-mode > .ab-item');

		if (toggle) {
			toggle.addEventListener('click', function(event) {
				event.preventDefault();
				setState(!isEnabled());
			});
		}

		document.addEventListener('keydown', function(event) {
			if ('Escape' === event.key && isEnabled()) {
				setState(false);
			}
		});

		scan();

		if (groups.length) {
			buildOverlay();
			hide();

			document.addEventListener('mouseover', onMouseOver);
			document.addEventListener('mouseleave', hide);
			window.addEventListener('scroll', schedulePosition, true);
			window.addEventListener('resize', schedulePosition);
		}

		setState(isEnabled());
	}

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();

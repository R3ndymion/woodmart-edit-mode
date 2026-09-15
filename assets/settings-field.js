(function() {
	'use strict';

	var PARAM = 'wdem-field';

	function getField() {
		var value = new URLSearchParams(window.location.search).get(PARAM);

		// Option ids are words and dashes. Anything else never matches a field and has no place
		// in a selector.
		return value && /^[\w-]+$/.test(value) ? value : null;
	}

	// The settings page already highlights a field this way when you pick one from its own search,
	// so a link from the front end arrives looking like something the page does on its own.
	function highlight(field) {
		var element = document.querySelector('.xts-' + field + '-field');

		if (!element) {
			return;
		}

		var previous = document.querySelector('.xts-highlight-field');

		if (previous) {
			previous.classList.remove('xts-highlight-field');
		}

		element.classList.add('xts-highlight-field');
		element.scrollIntoView({ block: 'center' });
	}

	// Leaving the parameter behind would drag the jump along to every section clicked afterwards,
	// because the theme keeps the rest of the query string when it rewrites "tab".
	function forget() {
		if (!window.history || !window.history.replaceState) {
			return;
		}

		var url = new URL(window.location.href);

		url.searchParams.delete(PARAM);
		window.history.replaceState({}, '', url);
	}

	function init() {
		var field = getField();

		if (!field) {
			return;
		}

		highlight(field);
		forget();
	}

	if ('loading' === document.readyState) {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();

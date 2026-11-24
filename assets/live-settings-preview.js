(function() {
	'use strict';

	var settings = window.wdemLivePreview;

	if (!settings) {
		return;
	}

	// Whether a URL is a front-end page of this site, the only kind a preview can show.
	function isPreviewable(url) {
		return url.origin === window.location.origin && 0 !== url.href.indexOf(settings.admin) && /^https?:$/.test(url.protocol);
	}

	// Walking the site inside the preview keeps it a preview: the parameter rides along on every
	// link followed and every search submitted.
	document.addEventListener('click', function(event) {
		var link = event.target instanceof Element ? event.target.closest('a[href]') : null;

		if (!link || event.defaultPrevented) {
			return;
		}

		var url;

		try {
			url = new URL(link.href, window.location.href);
		} catch (e) {
			return;
		}

		if (!isPreviewable(url)) {
			// Somewhere the preview cannot follow: a new tab, rather than losing the editor.
			if (url.origin !== window.location.origin || 0 === url.href.indexOf(settings.admin)) {
				link.target = '_blank';
			}

			return;
		}

		// Same page, different anchor: nothing to carry.
		if (url.pathname === window.location.pathname && url.search === window.location.search && url.hash) {
			return;
		}

		url.searchParams.set(settings.param, settings.value);
		link.href = url.toString();

		if ('_blank' === link.target) {
			link.removeAttribute('target');
		}
	}, true);

	document.addEventListener('submit', function(event) {
		var form = event.target;

		if (!(form instanceof HTMLFormElement) || 'get' !== (form.getAttribute('method') || 'get').toLowerCase()) {
			return;
		}

		var url;

		try {
			url = new URL(form.action || window.location.href, window.location.href);
		} catch (e) {
			return;
		}

		if (!isPreviewable(url) || form.querySelector('input[name="' + settings.param + '"]')) {
			return;
		}

		var input = document.createElement('input');
		input.type = 'hidden';
		input.name = settings.param;
		input.value = settings.value;
		form.appendChild(input);
	}, true);
})();

'use strict';

const { JSDOM } = require('jsdom');
const { check, done, asset } = require('./helpers');

const script = asset('settings-field.js');

const html = `<!doctype html><html><body>
<div class="xts-options">
	<div class="xts-section xts-active-section" data-id="copyrights_section">
		<div class="xts-field xts-settings-field xts-textarea-control xts-copyrights-field" id="field-copyrights">Copyrights text</div>
		<div class="xts-field xts-settings-field xts-textarea-control xts-copyrights2-field" id="field-copyrights2">Text next to copyrights</div>
	</div>
	<div class="xts-section xts-hidden" data-id="cookie_section">
		<div class="xts-field xts-settings-field xts-cookies_text-field xts-highlight-field" id="field-cookies">Popup text</div>
	</div>
</div>
</body></html>`;

const SETTINGS = 'http://example.test/wp-admin/admin.php?page=xts_theme_settings';

// Runs the script against a fresh document at the given URL and reports what it did. The script
// waits for DOMContentLoaded, so the document has to finish parsing before it is evaluated.
function run(query) {
	const dom = new JSDOM(html, { runScripts: 'outside-only', url: SETTINGS + query });
	const { window } = dom;
	const scrolled = [];

	// jsdom has no layout and no scrolling, so the call is recorded rather than performed.
	window.Element.prototype.scrollIntoView = function(options) {
		scrolled.push({ id: this.id, block: options && options.block });
	};

	return new Promise(function(resolve) {
		window.addEventListener('load', function() {
			window.eval(script);

			resolve({
				highlighted: Array.from(window.document.querySelectorAll('.xts-highlight-field')).map(el => el.id),
				scrolled: scrolled,
				search: window.location.search
			});
		});
	});
}

async function main() {
	// The link the front-end button builds.

	const field = await run('&tab=copyrights_section&wdem-field=copyrights');

	check('the linked option is highlighted', field.highlighted.join(), 'field-copyrights');
	check('and only that one, the stale highlight is cleared', field.highlighted.length, 1);
	check('the page scrolls to it', field.scrolled.map(s => s.id).join(), 'field-copyrights');
	check('centred, so the admin bar cannot cover it', field.scrolled[0].block, 'center');
	check('the parameter is dropped so later sections are not dragged along', field.search, '?page=xts_theme_settings&tab=copyrights_section');

	// A field living in a section the link did not open is still found: every section is in the DOM.
	const hidden = await run('&tab=cookie_section&wdem-field=copyrights2');
	check('a field in another section is still reachable', hidden.highlighted.join(), 'field-copyrights2');

	// Nothing asked for, nothing touched.
	const plain = await run('&tab=copyrights_section');
	check('without the parameter the stale highlight is left alone', plain.highlighted.join(), 'field-cookies');
	check('and nothing scrolls', plain.scrolled.length, 0);
	check('and the URL is untouched', plain.search, '?page=xts_theme_settings&tab=copyrights_section');

	// The value reaches a selector, so anything that is not an option id is refused outright.
	const junk = await run('&wdem-field=' + encodeURIComponent('copyrights, .xts-field'));
	check('a value that is not an option id is ignored', junk.highlighted.join(), 'field-cookies');
	check('and it does not scroll anywhere', junk.scrolled.length, 0);

	// An option this theme version does not render must not throw.
	const missing = await run('&wdem-field=no_such_option');
	check('an unknown option leaves the page alone', missing.highlighted.join(), 'field-cookies');
	check('unknown option still clears the parameter', missing.search, '?page=xts_theme_settings');
}

main().then(function() {
	done('settings field');
});

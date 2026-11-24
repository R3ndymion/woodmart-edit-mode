'use strict';

const { JSDOM, VirtualConsole } = require('jsdom');
const { check, done, asset } = require('./helpers');

const sleep = ms => new Promise(r => setTimeout(r, ms));

// The preview: every walk through the site keeps the preview parameter.
async function previewSuite() {
	const dom = new JSDOM(`<!doctype html><html><body>
		<a id="page" href="/shop/">Shop</a>
		<a id="query" href="/?product_cat=chairs">Chairs</a>
		<a id="anchor" href="#reviews">Reviews</a>
		<a id="admin" href="http://example.test/wp-admin/post.php?post=1&action=edit">Edit</a>
		<a id="external" href="https://example.org/">Elsewhere</a>
		<form id="search" action="/" method="get"><input name="s"></form>
		<form id="post" action="/cart/" method="post"></form>
	</body></html>`, { runScripts: 'outside-only', url: 'http://example.test/?wdem-live=abc' });

	const { window } = dom;
	const doc = window.document;

	window.wdemLivePreview = { param: 'wdem-live', value: 'abc', admin: 'http://example.test/wp-admin/' };
	window.eval(asset('live-settings-preview.js'));

	// Clicks are cancelled after the script has run, so jsdom does not try to navigate.
	doc.addEventListener('click', e => e.preventDefault());
	doc.addEventListener('submit', e => e.preventDefault());

	const click = id => doc.getElementById(id).dispatchEvent(new window.MouseEvent('click', { bubbles: true, cancelable: true }));

	click('page');
	check('a page link keeps the preview', doc.getElementById('page').href, 'http://example.test/shop/?wdem-live=abc');

	click('query');
	check('a link with a query keeps both', doc.getElementById('query').href, 'http://example.test/?product_cat=chairs&wdem-live=abc');

	click('anchor');
	check('an anchor on the same page is left alone', doc.getElementById('anchor').getAttribute('href'), '#reviews');

	click('admin');
	check('an admin link is not previewed', doc.getElementById('admin').href.indexOf('wdem-live'), -1);
	check('and opens in a tab of its own', doc.getElementById('admin').target, '_blank');

	click('external');
	check('another site opens in a tab of its own', doc.getElementById('external').target, '_blank');

	doc.getElementById('search').dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
	check('a search keeps the preview', doc.querySelector('#search input[name="wdem-live"]').value, 'abc');

	doc.getElementById('search').dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
	check('and only once', doc.querySelectorAll('#search input[name="wdem-live"]').length, 1);

	doc.getElementById('post').dispatchEvent(new window.Event('submit', { bubbles: true, cancelable: true }));
	check('a POST form is left alone', doc.querySelectorAll('#post input').length, 0);
}

// The settings page inside the editor: what it sends, and what it tells the editor.
async function frameSuite() {
	const dom = new JSDOM(`<!doctype html><html><body>
		<form action="options.php" method="post">
			<input type="hidden" name="option_page" value="xts-options-group">
			<input type="hidden" name="action" value="update">
			<input type="text" name="xts-woodmart-options[primary-color][idle]" value="#83b735">
			<select name="xts-woodmart-options[site_width]"><option value="boxed" selected>Boxed</option><option value="full-width">Full</option></select>
			<input type="checkbox" name="xts-woodmart-options[sticky_header]" value="1" checked>
			<input type="hidden" name="xts-woodmart-options[last_tab]" value="colors_section">
			<div class="xts-options-actions"><button class="xts-save-options-btn">Save options</button></div>
		</form>
	</body></html>`, { runScripts: 'outside-only', url: 'http://example.test/wp-admin/admin.php?page=xts_theme_settings&wdem-live-frame=1', virtualConsole: new VirtualConsole().sendTo(console) });

	const { window } = dom;
	const doc = window.document;
	const messages = [];
	const requests = [];

	// The frame talks to its parent; here the parent is a stand-in that records.
	const parent = { postMessage: message => messages.push(message) };
	Object.defineProperty(window, 'parent', { value: parent });

	window.fetch = function(url, options) {
		const sent = {};

		options.body.forEach((value, name) => {
			(sent[name] = sent[name] || []).push(value);
		});

		requests.push(sent);

		return Promise.resolve({
			json: () => Promise.resolve({ success: true, data: { changed: sent['changed[]'] || [], cssOnly: !!(sent['changed[]'] || []).every(id => 'primary-color' === id), unsaved: !!sent['changed[]'] } })
		});
	};

	let submitted = 0;
	doc.querySelector('form').addEventListener('submit', e => {
		e.preventDefault();
		submitted++;
	});

	window.wdemLiveFrame = {
		ajaxUrl: 'http://example.test/wp-admin/admin-ajax.php',
		nonce: 'n',
		option: 'xts-woodmart-options',
		reserved: [ 'last_tab', 'last_message', 'import_export', 'import-btn', 'reset-defaults', 'preset', 'fields_to_save' ],
		saved: ''
	};

	window.eval(asset('live-settings-frame.js'));

	if ('complete' !== doc.readyState) {
		await new Promise(r => window.addEventListener('load', r));
	}

	await sleep(400);

	check('the frame says it is ready', messages.map(m => m.wdemLive).join(','), 'ready');
	check('nothing is sent before an edit', requests.length, 0);

	const color = doc.querySelector('[name="xts-woodmart-options[primary-color][idle]"]');
	color.value = '#e0245e';
	color.dispatchEvent(new window.Event('change', { bubbles: true }));
	await sleep(400);

	check('an edit is sent', requests.length, 1);
	check('with the changed option only', JSON.stringify(requests[0]['changed[]']), '["primary-color"]');
	check('carrying its inputs', JSON.stringify(requests[0]['xts-woodmart-options[primary-color][idle]']), '["#e0245e"]');
	check('and none of the form plumbing', requests[0].option_page, undefined);
	check('as the live settings action', JSON.stringify(requests[0].action), '["wdem_live_update"]');
	check('the editor is told a colour is CSS only', messages[messages.length - 1].cssOnly, true);

	await sleep(700);
	check('polling does not resend the same state', requests.length, 1);

	// A checkbox cleared submits nothing at all, and still has to count as a change.
	doc.querySelector('[name="xts-woodmart-options[sticky_header]"]').checked = false;
	doc.querySelector('[name="xts-woodmart-options[sticky_header]"]').dispatchEvent(new window.Event('change', { bubbles: true }));
	await sleep(400);

	check('a cleared checkbox is sent as a change', JSON.stringify(requests[1]['changed[]']), '["primary-color","sticky_header"]');
	check('with no value of its own', requests[1]['xts-woodmart-options[sticky_header]'], undefined);
	check('and the editor reloads for it', messages[messages.length - 1].cssOnly, false);

	// Saving is the theme's own button.
	window.dispatchEvent(new window.MessageEvent('message', { data: { wdemLive: 'save' }, origin: 'http://example.test' }));

	// A message from anywhere else is ignored.
	window.dispatchEvent(new window.MessageEvent('message', { data: { wdemLive: 'save' }, origin: 'https://evil.test' }));
	await sleep(50);
	check('save presses the theme button', submitted, 1);

	// WoodMart 8.7 saves over AJAX and says so.
	window.jQuery = undefined;
	const before = requests.length;
	messages.length = 0;
	doc.dispatchEvent(new window.Event('woodmart_options_saved'));
	window.dispatchEvent(Object.assign(new window.Event('pageshow'), { persisted: true }));
	await sleep(50);
	check('a restored page after a save reports the save', messages.map(m => m.wdemLive).join(','), 'saved');

	await sleep(700);
	check('the saved values are the new baseline', requests.length, before);

	// The bridge polls the form forever; the window has to go for the process to end.
	window.close();
}

(async function() {
	await previewSuite();
	await frameSuite();
	done('live settings');
})();

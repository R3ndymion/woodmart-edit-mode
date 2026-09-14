'use strict';

const fs = require('fs');
const { JSDOM } = require('jsdom');
const { check, done, asset, findAdminBarCss } = require('./helpers');

const adminBarPath = findAdminBarCss();

if (!adminBarPath) {
	console.log('SKIP  admin bar CSS suite: wp-includes/css/admin-bar.css not found. Set WP_ROOT to point at a WordPress install.');
	process.exit(0);
}

const adminBarCss = fs.readFileSync(adminBarPath, 'utf8');
const ourCss = asset('edit-mode.css');

// WP prints admin-bar.css first, our inline style after it.
const html = `<!doctype html><html><head>
<style>${adminBarCss}</style>
<style>${ourCss}</style>
</head><body>
<div id="wpadminbar" class="nojq nojs">
	<div id="wp-toolbar"><ul class="ab-top-menu">
		<li id="wp-admin-bar-wdem-edit-mode"><a class="ab-item" href="#"><span class="ab-icon"></span><span class="ab-label">Edit mode</span></a></li>
		<li id="wp-admin-bar-other"><a class="ab-item" href="#"><span class="ab-label">Other</span></a></li>
	</ul></div>
</div>
</body></html>`;

const { window } = new JSDOM(html);
const doc = window.document;
const item = doc.querySelector('#wp-admin-bar-wdem-edit-mode');
const link = item.querySelector('.ab-item');
const label = item.querySelector('.ab-label');
const li = doc.getElementById('wp-admin-bar-wdem-edit-mode');

const css = (el, prop) => window.getComputedStyle(el).getPropertyValue(prop);
const ACTIVE = 'rgb(115, 103, 240)';
const ACTIVE_HOVER = 'rgb(91, 79, 224)';
const WP_HOVER_BG = 'rgb(44, 51, 56)';
const WP_HOVER_TEXT = 'rgb(114, 174, 230)';

// Inactive: WP styling untouched.
li.classList.add('hover');
check('inactive + hover keeps the WP hover background', css(link, 'background-color'), WP_HOVER_BG);
check('inactive + hover keeps the WP hover text', css(link, 'color'), WP_HOVER_TEXT);
li.classList.remove('hover');

// Active.
li.classList.add('wd-active');
check('active background', css(link, 'background-color'), ACTIVE);
check('active text', css(link, 'color'), 'rgb(255, 255, 255)');
check('active label text', css(label, 'color'), 'rgb(255, 255, 255)');

// Active + hover: our colour must win over the WP hover colour.
li.classList.add('hover');
check('active + hover keeps our background', css(link, 'background-color'), ACTIVE_HOVER);
check('active + hover keeps white text', css(link, 'color'), 'rgb(255, 255, 255)');
check('active + hover keeps white label', css(label, 'color'), 'rgb(255, 255, 255)');

// A neighbouring item must not be affected.
const other = doc.querySelector('#wp-admin-bar-other .ab-item');
// jsdom returns an empty string when no rule sets the property, which is the point here.
check('other admin bar items untouched', css(other, 'background-color'), '');

done('admin bar CSS');

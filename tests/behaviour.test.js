'use strict';

const { JSDOM } = require('jsdom');
const { check, done, asset } = require('./helpers');

const script = asset('edit-mode.js');

const html = `<!doctype html><html><body>
<div id="outside">nothing editable here</div>
<div id="wpadminbar"><ul><li id="wp-admin-bar-wdem-edit-mode"><a class="ab-item" href="#"><span class="ab-icon"></span><span class="ab-label">Edit mode</span><span class="wd-em-status"></span></a></li></ul></div>
<header id="site-header" class="whb-header">
	<div id="header-logo">logo</div>
	<!--wd-em-start:40--><div id="header-block">header html block</div><!--wd-em-end:40-->
	<!--wd-em-start:menu-7--><ul id="main-menu" class="wd-nav">
		<li id="menu-item-plain"><a href="#">Shop</a></li>
		<li class="wd-event-hover"><a href="#">Mega</a>
			<div id="mega-dropdown" class="wd-dropdown-menu wd-dropdown">
				<!--wd-em-start:80--><div id="mega-block">mega html block</div><!--wd-em-end:80-->
			</div>
		</li>
	</ul><!--wd-em-end:menu-7-->
</header>
<div id="mobile-panel">
	<!--wd-em-start:menu-7--><ul id="mobile-menu" class="wd-nav-mobile"><li id="mobile-item">Shop</li></ul><!--wd-em-end:menu-7-->
</div>
<main id="main-content"><div class="page">
	<!--wd-em-start:50-->
	<div id="layout-top">layout content</div>
	<section id="outer">
		<!--wd-em-start:10-->
		<div id="a">A</div>
		<div id="b">B<!--wd-em-start:20--><span id="inner">inner</span><!--wd-em-end:20--></div>
		<!--wd-em-end:10-->
	</section>
	<!--wd-em-end:50-->
	<p id="orphan"><!--wd-em-start:30-->text only<!--wd-em-end:30--></p>
	<div id="grid" class="products wd-products wd-loop-builder-on wd-loop-item-wrap-99">
		<div id="product-card" class="wd-product"><div id="product-title">A product</div></div>
	</div>
	<div id="below-grid">pagination</div>
</div></main>
<div id="slider" class="wd-slider wd-carousel-container" data-id="7">
	<div class="wd-carousel-inner">
		<div class="wd-carousel" data-slider='{"title":"Home slider","url":"http://example.test/edit-slider"}'>
			<div class="wd-carousel-wrap">
				<div id="slide-1" class="wd-slide wd-carousel-item" data-slide='{"title":"First slide","url":"http://example.test/edit-slide-1"}'>one</div>
				<div id="slide-2" class="wd-slide wd-carousel-item" data-slide='{"title":"Second slide","url":"http://example.test/edit-slide-2"}'>two</div>
			</div>
		</div>
	</div>
	<div id="slider-arrows" class="wd-nav-arrows wd-slider-arrows">arrows</div>
</div>
<ul class="wd-nav">
	<li class="wd-event-hover">
		<a href="#">Menu</a>
		<div id="dropdown" class="wd-dropdown-menu wd-dropdown">
			<div id="sub-dropdown" class="sub-sub-menu wd-dropdown">
				<!--wd-em-start:60--><div id="menu-block">menu html block</div><!--wd-em-end:60-->
			</div>
		</div>
	</li>
	<li class="wd-event-click wd-opened">
		<div id="clicked-dropdown" class="wd-dropdown wd-opened">
			<!--wd-em-start:70--><div id="clicked-block">clicked html block</div><!--wd-em-end:70-->
		</div>
	</li>
</ul>
<div id="wd-fb-12" class="wd-fb-holder wd-deferred wd-scroll">
	<div id="fb-wrap" class="wd-fb-wrap">
		<div class="wd-fb"><div class="wd-fb-inner"><div id="fb-content">floating block content</div></div></div>
	</div>
</div>
<aside id="sidebar" class="wd-sidebar sidebar-container">
	<div id="sidebar-heading" class="wd-heading">close</div>
	<div id="widget-area" class="widget-area">
		<!--wd-em-start:widgets-sidebar-shop-->
		<link rel="stylesheet" href="widget.css">
		<div id="widget-price" class="wd-widget widget sidebar-widget">Filter by price</div>
		<div id="widget-stock" class="wd-widget widget sidebar-widget"><span id="stock-label">In stock</span></div>
		<!--wd-em-end:widgets-sidebar-shop-->
	</div>
</aside>
<footer class="wd-footer">
	<div id="copyrights" class="wd-copyrights copyrights-wrapper wd-layout-two-columns">
		<div class="container wd-grid-g">
			<div id="copyrights-left" class="wd-col-start reset-last-child"><p>&copy; 2026 Shop</p></div>
			<div id="copyrights-right" class="wd-col-end reset-last-child"><span id="payments">payments</span></div>
		</div>
	</div>
</footer>
<div id="cookies" class="wd-cookies-popup">
	<div class="wd-cookies-inner">
		<div id="cookies-text" class="cookies-info-text">We use cookies.</div>
		<div id="cookies-buttons" class="cookies-buttons"><a href="#" class="cookies-accept-btn">Accept</a></div>
	</div>
</div>
<div id="popup-34" class="wd-popup-builder wd-popup wd-deferred wd-scroll-content">
	<div class="wd-popup-inner">
		<div id="popup-text">promo text</div>
		<!--wd-em-start:90--><div id="popup-block">html block in the popup</div><!--wd-em-end:90-->
	</div>
</div>
</body></html>`;

const dom = new JSDOM(html, { runScripts: 'outside-only', pretendToBeVisual: true, url: 'http://example.test/shop/' });
const { window } = dom;

// jsdom does not lay out, so every rect is 0x0 and the frame would hide itself on show().
const rect = (top, left, height, width) => ({
	top: top,
	left: left,
	right: left + width,
	bottom: top + height,
	width: width,
	height: height,
	x: left,
	y: top
});

const RECTS = {
	wpadminbar: rect(0, 0, 32, 1024),
	'site-header': rect(0, 0, 80, 1024),
	'main-menu': rect(20, 300, 40, 400),
	'mobile-menu': rect(20, 0, 300, 320),
	'mega-dropdown': rect(60, 300, 200, 400),
	'layout-top': rect(300, 50, 200, 900),
	'slide-1': rect(500, 0, 400, 1024),
	'slide-2': rect(500, 0, 400, 1024),
	'slide-clone': rect(500, 1024, 400, 1024),
	'slider-arrows': rect(700, 0, 40, 1024),
	'slider': rect(500, 0, 440, 1024),
	'main-content': rect(250, 0, 800, 1024),
	'grid': rect(900, 0, 300, 1024),
	'product-card': rect(900, 0, 300, 240),
	'below-grid': rect(1210, 0, 40, 1024),
	'wd-fb-12': rect(0, 0, 768, 1024),
	'fb-wrap': rect(600, 700, 150, 300),
	'popup-34': rect(150, 200, 400, 600),
	'copyrights': rect(1300, 0, 60, 1024),
	'copyrights-left': rect(1310, 20, 40, 500),
	'copyrights-right': rect(1310, 600, 40, 300),
	'cookies': rect(650, 0, 90, 1024),
	'cookies-text': rect(660, 20, 40, 700),
	'cookies-buttons': rect(660, 800, 40, 200),
	'sidebar': rect(240, 0, 700, 300),
	'sidebar-heading': rect(240, 0, 40, 300),
	'widget-price': rect(300, 10, 120, 280),
	'widget-stock': rect(440, 10, 200, 280)
};

window.Element.prototype.getBoundingClientRect = function() {
	return RECTS[this.id] || rect(400, 50, 80, 200);
};

window.wdemEditMode = {
	blocks: {
		10: { title: 'Outer block', type: 'HTML Block', edit_url: 'http://example.test/edit-10' },
		20: { title: 'Inner block', type: 'HTML Block', edit_url: 'http://example.test/edit-20' },
		30: { title: 'Text block', type: 'HTML Block', edit_url: 'http://example.test/edit-30' },
		40: { title: 'Header block', type: 'HTML Block', edit_url: 'http://example.test/edit-40' },
		80: { title: 'Mega block', type: 'HTML Block', edit_url: 'http://example.test/edit-80' },
		'menu-7': { title: 'Main Menu', type: 'Menu', edit_url: 'http://example.test/nav-menus.php?action=edit&menu=7' },
		50: { title: 'Shop layout', type: 'Layout', edit_url: 'http://example.test/edit-50' },
		60: { title: 'Menu block', type: 'HTML Block', edit_url: 'http://example.test/edit-60' },
		70: { title: 'Clicked block', type: 'HTML Block', edit_url: 'http://example.test/edit-70' },
		90: { title: 'Popup block', type: 'HTML Block', edit_url: 'http://example.test/edit-90' },
		'widgets-sidebar-shop': { title: 'Shop page Widget Area', type: 'Widget Area', edit_url: 'http://example.test/wp-admin/customize.php?autofocus%5Bsection%5D=sidebar-widgets-sidebar-shop' }
	},
	selectors: [
		{
			selector: 'header.whb-header',
			actions: [ { title: 'Main header', type: 'Header', edit_url: 'http://example.test/shop/?whb-header-frontend=1' } ]
		},
		{
			selector: '.wd-loop-item-wrap-99',
			actions: [ { title: 'Card design', type: 'Product loop item', edit_url: 'http://example.test/edit-loop-99' } ]
		},
		{
			selector: '#wd-fb-12 > .wd-fb-wrap',
			actions: [ { title: 'Cookie bar', type: 'Floating block', edit_url: 'http://example.test/edit-fb-12' } ]
		},
		{
			selector: '.wd-copyrights .wd-col-start',
			actions: [ { title: 'Copyrights text', type: 'Theme Settings', edit_url: 'http://example.test/wp-admin/admin.php?page=xts_theme_settings&tab=copyrights_section&wdem-field=copyrights' } ]
		},
		{
			selector: '.wd-copyrights .wd-col-end',
			actions: [ { title: 'Text next to copyrights', type: 'Theme Settings', edit_url: 'http://example.test/wp-admin/admin.php?page=xts_theme_settings&tab=copyrights_section&wdem-field=copyrights2' } ]
		},
		{
			selector: '.wd-cookies-popup .cookies-info-text',
			actions: [ { title: 'Popup text', type: 'Theme Settings', edit_url: 'http://example.test/wp-admin/admin.php?page=xts_theme_settings&tab=cookie_section&wdem-field=cookies_text' } ]
		},
		{
			selector: '#popup-34.wd-popup-builder',
			actions: [ { title: 'Newsletter', type: 'Popup', edit_url: 'http://example.test/edit-popup-34' } ]
		},
		{
			selector: 'main#main-content',
			actions: [
				{ title: 'Checkout form', type: 'Layout', edit_url: 'http://example.test/edit-checkout-form' },
				{ title: 'Checkout content', type: 'Layout', edit_url: 'http://example.test/edit-checkout-content' }
			]
		}
	],
	labels: { edit: 'Edit', slide: 'Slide', slider: 'Slider', on: 'ON', off: 'OFF' }
};

window.eval(script);

async function main() {
	const doc = window.document;
	const sleep = ms => new Promise(r => window.setTimeout(r, ms));
	const groupOf = id => doc.getElementById(id).getAttribute('data-wd-em');

	// Grouping.
	check('div#a tagged', null !== groupOf('a'), true);
	check('div#a and div#b share one group', groupOf('a'), groupOf('b'));
	check('span#inner is its own group', groupOf('inner') !== groupOf('a'), true);
	check('text-only block falls back to parent', null !== groupOf('orphan'), true);
	check('layout group is separate from its blocks', groupOf('layout-top') !== groupOf('a'), true);
	check('header tagged by selector', doc.querySelector('header.whb-header').hasAttribute('data-wd-em'), true);
	check('overlay built', !!doc.querySelector('.wd-em-overlay'), true);

	// Toggle.
	const toggle = doc.querySelector('#wp-admin-bar-wdem-edit-mode > .ab-item');
	const status = () => doc.querySelector('#wp-admin-bar-wdem-edit-mode .wd-em-status').textContent;

	check('mode off initially', doc.documentElement.classList.contains('wd-edit-mode-on'), false);
	check('status reads OFF', status(), 'OFF');
	toggle.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
	check('mode on after click', doc.documentElement.classList.contains('wd-edit-mode-on'), true);
	check('status reads ON', status(), 'ON');
	check('admin bar item marked active', doc.getElementById('wp-admin-bar-wdem-edit-mode').classList.contains('wd-active'), true);
	check('state persisted', window.localStorage.getItem('wdem_edit_mode'), '1');

	const hover = id => doc.getElementById(id).dispatchEvent(new window.MouseEvent('mouseover', { bubbles: true }));
	const overlay = doc.querySelector('.wd-em-overlay');
	const allButtons = () => Array.from(doc.querySelectorAll('.wd-em-button'));
	const buttonsRow = doc.querySelector('.wd-em-buttons');
	const href = i => (allButtons()[i] || {}).href;
	const label = () => allButtons()[0].textContent;

	// Hover always resolves to the innermost editable thing.
	hover('inner');
	check('nested block wins over its parent block', href(0), 'http://example.test/edit-20');
	check('button shows the entity type', label(), 'Edit: Inner blockHTML Block');

	hover('a');
	check('block wins over the layout around it', href(0), 'http://example.test/edit-10');

	hover('layout-top');
	check('layout content picks the layout', href(0), 'http://example.test/edit-50');
	check('layout label', label(), 'Edit: Shop layoutLayout');

	hover('header-block');
	check('block inside the header wins over the header', href(0), 'http://example.test/edit-40');

	hover('header-logo');
	check('header chrome picks the header', href(0), 'http://example.test/shop/?whb-header-frontend=1');
	check('header label', label(), 'Edit: Main headerHeader');

	// Button placement.
	hover('layout-top');
	check('buttons sit above a block with room over it', buttonsRow.style.top, '302px');

	hover('header-logo');
	check('buttons at the top of the page clear the admin bar', buttonsRow.style.top, '32px');

	// Blocks inside dropdowns.
	const opened = id => doc.getElementById(id).classList.contains('wd-opened');

	hover('menu-block');
	check('the dropdown around the block is held open', opened('dropdown'), true);
	check('the nested dropdown is held open too', opened('sub-dropdown'), true);

	allButtons()[0].dispatchEvent(new window.MouseEvent('mouseover', { bubbles: true }));
	await sleep(400);
	check('dropdown survives hovering the button', opened('dropdown'), true);
	check('button points at the block in the dropdown', href(0), 'http://example.test/edit-60');

	hover('a');
	check('leaving the dropdown for another block closes it', opened('dropdown'), false);
	check('nested dropdown released as well', opened('sub-dropdown'), false);

	// A dropdown the theme opened on its own must stay untouched.
	hover('clicked-block');
	check('already opened dropdown stays opened', opened('clicked-dropdown'), true);
	hover('a');
	check('already opened dropdown is not closed by us', opened('clicked-dropdown'), true);

	// One anchor can carry several actions, the way the checkout page has two layouts.
	doc.getElementById('main-content').dispatchEvent(new window.MouseEvent('mouseover', { bubbles: true }));
	check('both layouts on one anchor', allButtons().length, 2);
	check('first layout button', href(0), 'http://example.test/edit-checkout-form');
	check('second layout button', href(1), 'http://example.test/edit-checkout-content');

	// Navigation menus.
	hover('menu-item-plain');
	check('hovering a menu item picks the menu', href(0), 'http://example.test/nav-menus.php?action=edit&menu=7');
	check('menu label', label(), 'Edit: Main MenuMenu');
	check('the frame is the menu, not the header', doc.querySelector('.wd-em-frame').style.width, '400px');

	hover('mega-block');
	check('a block inside the menu still wins', href(0), 'http://example.test/edit-80');
	check('and the mega dropdown is held open', doc.getElementById('mega-dropdown').classList.contains('wd-opened'), true);

	hover('header-logo');
	check('header chrome outside the menu still picks the header', href(0), 'http://example.test/shop/?whb-header-frontend=1');

	// The same menu rendered twice must become two independent groups.
	hover('mobile-item');
	check('the second rendering points at the same menu', href(0), 'http://example.test/nav-menus.php?action=edit&menu=7');
	check('but frames its own markup', doc.querySelector('.wd-em-frame').style.width, '320px');

	// A product loop item layout frames the grid only, never the page around it.
	hover('product-title');
	check('hovering a product picks the loop item layout', href(0), 'http://example.test/edit-loop-99');
	check('only the grid button is offered', allButtons().length, 1);
	check('loop item label', label(), 'Edit: Card designProduct loop item');
	check('the frame is the grid, not the page', doc.querySelector('.wd-em-frame').style.height, '300px');

	hover('below-grid');
	check('outside the grid falls back to the page layouts', href(0), 'http://example.test/edit-checkout-form');
	check('and the page anchor keeps both layouts', allButtons().length, 2);

	// Sliders.
	hover('slide-1');
	check('slide offers two buttons', allButtons().length, 2);
	check('first button edits the slide', href(0), 'http://example.test/edit-slide-1');
	check('slide button label', label(), 'Edit: First slideSlide');
	check('second button edits the slider', href(1), 'http://example.test/edit-slider');
	check('slider button is secondary', allButtons()[1].className, 'wd-em-button wd-secondary');

	hover('slide-2');
	check('the other slide points at itself', href(0), 'http://example.test/edit-slide-2');
	check('and still at the same slider', href(1), 'http://example.test/edit-slider');

	// Swiper clones slides after the scan, the clone inherits the attribute of the original.
	const clone = doc.getElementById('slide-1').cloneNode(true);
	clone.id = 'slide-clone';
	doc.querySelector('.wd-carousel-wrap').appendChild(clone);

	hover('slide-clone');
	check('a cloned slide keeps the original links', href(0), 'http://example.test/edit-slide-1');
	check('the frame follows the clone, not the original', doc.querySelector('.wd-em-frame').style.left, '1024px');

	hover('slide-1');
	check('back on the original the frame returns to it', doc.querySelector('.wd-em-frame').style.left, '0px');

	hover('slider-arrows');
	check('slider chrome offers one button', allButtons().length, 1);
	check('slider chrome edits the slider', href(0), 'http://example.test/edit-slider');

	hover('a');
	check('a plain block is back to one button', allButtons().length, 1);

	// Widget areas. Core brackets every dynamic_sidebar() call, so one pair of markers covers the
	// whole area and every widget inside it resolves to the same button.
	hover('widget-price');
	check('hovering a widget picks the area it sits in', href(0), 'http://example.test/wp-admin/customize.php?autofocus%5Bsection%5D=sidebar-widgets-sidebar-shop');
	check('widget area label', label(), 'Edit: Shop page Widget AreaWidget Area');
	check('the frame spans the widgets, not one of them', doc.querySelector('.wd-em-frame').style.height, '340px');

	hover('stock-label');
	check('deep inside a widget still picks the area', href(0), 'http://example.test/wp-admin/customize.php?autofocus%5Bsection%5D=sidebar-widgets-sidebar-shop');

	// The theme prints stylesheet links into the area on the same hook. They are elements between
	// the markers, so they join the group, and a zero-sized one must not drag the frame to 0,0.
	check('the frame ignores the stylesheet links beside the widgets', doc.querySelector('.wd-em-frame').style.top, '300px');

	check('the sidebar chrome outside the area is not editable', doc.getElementById('sidebar-heading').hasAttribute('data-wd-em'), false);

	// Areas whose content lives in the theme settings rather than in a post.
	hover('copyrights-left');
	check('the copyrights text points at its own option', href(0), 'http://example.test/wp-admin/admin.php?page=xts_theme_settings&tab=copyrights_section&wdem-field=copyrights');
	check('settings label', label(), 'Edit: Copyrights textTheme Settings');
	check('the frame is the column, not the whole strip', doc.querySelector('.wd-em-frame').style.width, '500px');

	hover('payments');
	check('the second column points at the second option', href(0), 'http://example.test/wp-admin/admin.php?page=xts_theme_settings&tab=copyrights_section&wdem-field=copyrights2');

	// Only what a single control actually owns is framed. The band around the columns is layout,
	// nobody edits it, and outlining it would promise an editor that does not exist.
	check('the strip around the columns is not editable', doc.getElementById('copyrights').hasAttribute('data-wd-em'), false);

	hover('cookies-text');
	check('the cookie notice text points at its option', href(0), 'http://example.test/wp-admin/admin.php?page=xts_theme_settings&tab=cookie_section&wdem-field=cookies_text');

	check('the notice around the text is not editable', doc.getElementById('cookies').hasAttribute('data-wd-em'), false);
	check('and neither are its buttons', doc.getElementById('cookies-buttons').hasAttribute('data-wd-em'), false);

	// Floating blocks. The holder spans the viewport, only the wrap inside it is the block.
	hover('fb-content');
	check('hovering a floating block picks it', href(0), 'http://example.test/edit-fb-12');
	check('floating block label', label(), 'Edit: Cookie barFloating block');
	check('the frame is the wrap, not the holder', doc.querySelector('.wd-em-frame').style.width, '300px');

	// Popups. Hidden at scan time, still tagged, and hoverable once the theme opens them.
	hover('popup-text');
	check('hovering a popup picks it', href(0), 'http://example.test/edit-popup-34');
	check('popup label', label(), 'Edit: NewsletterPopup');

	hover('popup-block');
	check('a block inside the popup still wins', href(0), 'http://example.test/edit-90');

	// Magnific moves the popup into its own wrapper rather than copying it, so the tag travels.
	const mfpWrap = doc.createElement('div');
	mfpWrap.className = 'mfp-wrap';
	doc.body.appendChild(mfpWrap);
	mfpWrap.appendChild(doc.getElementById('popup-34'));

	hover('popup-text');
	check('an opened popup is still editable', href(0), 'http://example.test/edit-popup-34');
	check('and the frame still follows it', doc.querySelector('.wd-em-frame').style.height, '400px');

	// Leaving the block keeps the frame alive long enough to reach the button.
	hover('a');
	doc.getElementById('outside').dispatchEvent(new window.MouseEvent('mouseover', { bubbles: true }));
	check('frame survives leaving the block', overlay.style.display, 'block');

	allButtons()[0].dispatchEvent(new window.MouseEvent('mouseover', { bubbles: true }));
	await sleep(400);
	check('hovering the button cancels the pending hide', overlay.style.display, 'block');
	check('button still points at the block', href(0), 'http://example.test/edit-10');

	doc.getElementById('outside').dispatchEvent(new window.MouseEvent('mouseover', { bubbles: true }));
	await sleep(400);
	check('frame hides after the grace period', overlay.style.display, 'none');

	// Toggle off.
	toggle.dispatchEvent(new window.MouseEvent('click', { bubbles: true }));
	check('mode off after second click', doc.documentElement.classList.contains('wd-edit-mode-on'), false);
	check('status back to OFF', status(), 'OFF');
	check('overlay hidden', overlay.style.display, 'none');
}

function run() {
	main().then(function() {
		done('behaviour');
	});
}

if ('complete' === window.document.readyState) {
	run();
} else {
	window.addEventListener('load', run);
}

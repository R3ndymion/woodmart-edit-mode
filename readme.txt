=== WoodMart Edit Mode ===
Contributors: r3ndymion
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.0
License: GPLv2 or later

Jump from the front-end straight into the editor of whatever you are looking at.

== Description ==

Adds a "Live edit" switch to the admin bar. Turn it on and the front-end starts
answering the question "where does this thing actually live?" — hover anything and
a frame appears around it with a button that opens its editor in a new tab.

It recognises:

* HTML blocks, wherever they are rendered — footer, header elements, tabs, widgets, mega menu
* Layouts, on the pages where WoodMart renders one
* Product loop item layouts, framing just the product grid they govern
* Popups built with the popup builder, while the popup is open
* Floating blocks built with the floating block builder
* Navigation menus, wherever they are rendered — header, mobile panel, sticky nav, footer, widgets
* Widget areas, wherever they are rendered, opening that area in the customizer over the page you came from
* Content that comes from the theme settings — the copyrights columns, the cookie notice text — opening the exact control behind it
* Contact Form 7 forms, opening that form in Contact → Contact Forms
* Mailchimp for WordPress forms, opening that form in Mailchimp for WP → Forms
* The current header, opening WoodMart's front-end header builder
* Slides, which also offer a second button for the slider they belong to
* Footers and page titles built with the site builder, opening their editor

"Live settings", in the menu of the "Live edit" switch, opens the theme settings beside a live preview of the
current page, like the customizer: changes show in the preview straight away, and
nothing reaches visitors until you press Save. Colours and sizes update without a reload,
the preview can be switched to tablet and mobile width, and links inside it stay in
preview. Opening a section about a kind of page — Single product, Product archive, Blog
archive, Single post — takes the preview to such a page.

Hovering always resolves to the innermost thing. An HTML block inside a layout
offers the block, not the layout. Dropdowns are held open while highlighted, so a
block inside a mega menu can be reached without the menu collapsing.

The switch is remembered between pages. Escape turns it off.

Only users who can edit posts ever see any of this, and nothing is added to the
markup for anyone else.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install the zip through Plugins → Add New → Upload.
2. Activate it.
3. Open the front-end and click "Live edit" in the admin bar.

== Frequently Asked Questions ==

= Nothing happens and there is a notice about the theme =

Newer WoodMart versions ship this feature themselves. Running both would draw two
overlays over each other, so the plugin steps aside. To run it anyway:

`add_filter( 'wdem_defer_to_theme', '__return_false' );`

= The widget area button opens a broken customizer =

A WoodMart bug, not this plugin's: the theme registers its block editor script without the
dependencies listed in its own `index.asset.php`, and the customizer's widgets panel is the one
block editor screen that does not load `wp-plugins` by itself. Opening Appearance → Customize →
Widgets directly shows the same error with this plugin switched off. It affects sites using the
block widgets editor, and the fix belongs in the theme.

= A block on my page has no button =

Blocks loaded over AJAX are not covered — a mega menu item with "Load dropdown
content via AJAX" enabled is the usual case. The markup the plugin needs is only
present in the initial page response.

== Hooks ==

* `wdem_is_available` (bool) — force the edit mode on or off for a request.
* `wdem_selectors` (array) — add your own anchors: `array( 'selector' => '...', 'actions' => array( array( 'title', 'type', 'edit_url' ) ) )`.
* `wdem_settings_anchors` (array) — map more markup to theme settings: `array( 'selector' => '...', 'field' => 'option_id' )`, or `'section' => 'section_id'` for a whole section.
* `wdem_defer_to_theme` (bool) — return false to run alongside the theme's own edit mode.

== Changelog ==

= 1.1.0 =
* Live settings: edit the theme settings next to a live preview of the page, the way the customizer works.
* Live settings: colours, sizes, fonts and custom CSS apply without reloading the preview; other options reload it without losing the scroll position.
* Live settings: opening a section such as Single product or Blog archive takes the preview to that kind of page.
* Live settings: the theme's controls are laid out for the narrow panel, without screenshot tooltips.
* The admin bar switch is now called "Live edit", with Live settings in its menu, and takes the accent colour of the admin colour scheme.
* WoodMart 8.7 support: the Gutenberg header, site builder footers and page titles now link to their editor.
* Sliders and their slides are recognised again on WoodMart 8.7, which no longer prints their edit links in the markup.
* The header button is offered only to users who can open the header builder.

= 1.0.4 =
* Contact Form 7 forms are now recognised and link to their form in Contact → Contact Forms.
* Mailchimp for WordPress forms are now recognised and link to their form in Mailchimp for WP → Forms.
* An HTML block whose whole content is one of those forms now offers both buttons, the form first.

= 1.0.3 =
* The admin bar switch now shows its state: an ON/OFF label and an open or crossed-out eye.
* Popups and floating blocks are now recognised and link to their builders.
* Widget areas are now recognised and open in the customizer, previewing the page you came from.
* Content coming from the theme settings, the copyrights columns and the cookie notice text, links to the control behind it.

= 1.0.2 =
* Navigation menus are now recognised and link to their screen in Appearance → Menus.

= 1.0.1 =
* Product loop item layouts now highlight only the product grid, instead of the whole page.

= 1.0.0 =
* First release.

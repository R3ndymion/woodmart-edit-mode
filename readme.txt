=== WoodMart Edit Mode ===
Contributors: r3ndymion
Requires at least: 6.0
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.0.2
License: GPLv2 or later

Jump from the front-end straight into the editor of whatever you are looking at.

== Description ==

Adds an "Edit mode" switch to the admin bar. Turn it on and the front-end starts
answering the question "where does this thing actually live?" — hover anything and
a frame appears around it with a button that opens its editor in a new tab.

It recognises:

* HTML blocks, wherever they are rendered — footer, header elements, tabs, widgets, mega menu
* Layouts, on the pages where WoodMart renders one
* Product loop item layouts, framing just the product grid they govern
* Navigation menus, wherever they are rendered — header, mobile panel, sticky nav, footer, widgets
* The current header, opening WoodMart's front-end header builder
* Slides, which also offer a second button for the slider they belong to

Hovering always resolves to the innermost thing. An HTML block inside a layout
offers the block, not the layout. Dropdowns are held open while highlighted, so a
block inside a mega menu can be reached without the menu collapsing.

The switch is remembered between pages. Escape turns it off.

Only users who can edit posts ever see any of this, and nothing is added to the
markup for anyone else.

== Installation ==

1. Upload the plugin folder to `/wp-content/plugins/`, or install the zip through Plugins → Add New → Upload.
2. Activate it.
3. Open the front-end and click "Edit mode" in the admin bar.

== Frequently Asked Questions ==

= Nothing happens and there is a notice about the theme =

Newer WoodMart versions ship this feature themselves. Running both would draw two
overlays over each other, so the plugin steps aside. To run it anyway:

`add_filter( 'wdem_defer_to_theme', '__return_false' );`

= A block on my page has no button =

Blocks loaded over AJAX are not covered — a mega menu item with "Load dropdown
content via AJAX" enabled is the usual case. The markup the plugin needs is only
present in the initial page response.

== Hooks ==

* `wdem_is_available` (bool) — force the edit mode on or off for a request.
* `wdem_selectors` (array) — add your own anchors: `array( 'selector' => '...', 'actions' => array( array( 'title', 'type', 'edit_url' ) ) )`.
* `wdem_defer_to_theme` (bool) — return false to run alongside the theme's own edit mode.

== Changelog ==

= 1.0.2 =
* Navigation menus are now recognised and link to their screen in Appearance → Menus.

= 1.0.1 =
* Product loop item layouts now highlight only the product grid, instead of the whole page.

= 1.0.0 =
* First release.

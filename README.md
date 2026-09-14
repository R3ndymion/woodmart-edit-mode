# WoodMart Edit Mode

Jump from the front-end straight into the editor of whatever you are looking at.

Adds an **Edit mode** switch to the WordPress admin bar. Turn it on and hovering the
front-end draws a frame around the nearest editable thing and offers a button that
opens its editor in a new tab.

Works with Elementor, WPBakery and Gutenberg-built content. Requires the
[WoodMart](https://woodmart.xtemos.com/) theme.

## What it recognises

| Entity | Opens |
| --- | --- |
| HTML blocks (`cms_block`) | the block's editor, wherever it is rendered — footer, header elements, tabs, widgets, mega menu |
| Layouts (`woodmart_layout`) | the layout's editor |
| Product loop item layouts | the card layout, framing just the product grid it governs |
| Popups (`wd_popup`) | the popup's editor, while the popup is open |
| Floating blocks (`wd_floating_block`) | the floating block's editor |
| Navigation menus | that menu in **Appearance → Menus** |
| The current header | WoodMart's front-end header builder |
| Slides | the slide, plus a second button for its slider |

Hovering always resolves to the **innermost** thing. A block inside a layout offers
the block. A block inside a mega menu offers the block, not the menu — and the
dropdown is held open while it is highlighted, so you can actually reach the button.

The switch is remembered between pages. <kbd>Escape</kbd> turns it off.

## Install

Download the zip from [Releases](https://github.com/R3ndymion/woodmart-edit-mode/releases)
and install it through **Plugins → Add New → Upload Plugin**.

To work on it instead, clone straight into `wp-content/plugins/`:

```bash
git clone https://github.com/R3ndymion/woodmart-edit-mode.git
```

## How it stays out of the theme's way

The plugin needs to know *where* on the page each entity ended up, which PHP alone
cannot tell it. It bridges that four different ways, none of which require editing
the theme:

- **HTML blocks** — WoodMart guards `woodmart_get_html_block()` with
  `function_exists()`, and plugins load before themes, so this plugin's copy of that
  six-line function wins and wraps the output in HTML comment markers. Comments
  rather than a wrapper element, because a `<div>` would break flex and grid children
  and `:first-child` selectors.
- **Layouts** — no hook exists around layout output, so the plugin reads the
  `$woodmart_editable_posts_bar_data` global WoodMart already fills for its own admin
  bar menu, and anchors page-level layouts to `main#main-content`. Product loop item
  layouts get the grid wrapper instead, which WoodMart stamps with
  `wd-loop-item-wrap-{ID}`.
- **Menus** — core runs every `wp_nav_menu()` call through the `wp_nav_menu` filter.
- **Header and sliders** — already identifiable in the markup, via
  `header.whb-header` and the `data-slide` / `data-slider` attributes WoodMart prints
  for logged-in users.
- **Popups and floating blocks** — identifiable too, as `#popup-{ID}` and
  `#wd-fb-{ID}`, but the markup does not say which of them this page chose to print.
  WoodMart decides that from display conditions it keeps in a transient, so the plugin
  asks it the same question again; anything that did not print matches nothing in the
  DOM. A popup is `display: none` until it opens, and Magnific Popup *moves* the
  element into its wrapper rather than copying it, so the tag left on it at load
  survives and the open popup can be hovered.

## Who sees it

Only users who can `edit_posts`. Nothing is added to the markup for anyone else, and
the mode never activates inside the Elementor editor, an Elementor preview, the
front-end header builder, or a PJAX request.

## Known gap

Blocks loaded over AJAX have no buttons. A mega menu item with *Load dropdown content
via AJAX* enabled is the usual case: the initial response holds only a placeholder,
which is then replaced wholesale, and the content itself arrives through
`admin-ajax.php` where `is_admin()` is `true` so no markers are emitted.

## Filters

| Filter | Purpose |
| --- | --- |
| `wdem_is_available` (bool) | force the edit mode on or off for a request |
| `wdem_selectors` (array) | add your own anchors — `array( 'selector' => '…', 'actions' => array( array( 'title', 'type', 'edit_url' ) ) )` |
| `wdem_defer_to_theme` (bool) | return `false` to run alongside a WoodMart version that ships its own edit mode |

## Tests

The behaviour is covered by a jsdom harness rather than by clicking — 82 assertions
across hover resolution, nesting precedence, dropdown handling, button placement and
the admin bar cascade.

```bash
npm install
npm test
php tests/load.php
```

`tests/admin-bar-css.test.js` loads the real `wp-includes/css/admin-bar.css` and reads
`getComputedStyle`, because the active state has to beat WordPress's own hover colour
on specificity and that is not something to reason about on paper. It finds the
WordPress install by walking up from the plugin folder; set `WP_ROOT` if you run it
somewhere else.

One trap if you extend the suite: **jsdom does no layout**, so every
`getBoundingClientRect()` returns zeros and the overlay hides itself the instant it
appears. The fixture stubs the prototype with a per-element rect map before the script
is evaluated.

## Releasing

Tag and push. The workflow in `.github/workflows/release.yml` builds the installable
zip and attaches it to a GitHub release.

```bash
git tag v1.0.2
git push origin v1.0.2
```

## License

GPL-2.0-or-later. See [LICENSE](LICENSE).

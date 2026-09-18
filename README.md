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
| Widget areas | that area in the customizer, previewing the page you came from |
| Content set in the theme settings — the copyrights columns, the cookie notice text | that single control in **Theme Settings**, scrolled to and highlighted |
| Contact Form 7 forms | that form in **Contact → Contact Forms** |
| Mailchimp for WordPress forms | that form in **Mailchimp for WP → Forms** |
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
cannot tell it. It bridges that a different way per entity, none of which require
editing the theme or the plugin the entity belongs to:

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
- **Widget areas** — core brackets every `dynamic_sidebar()` call with
  `dynamic_sidebar_before` / `dynamic_sidebar_after`, which covers the sidebar template,
  the footer columns, the shop filters, the mobile panel and any widget area a page builder
  drops into a page. One pair of markers per area, not per widget: the customizer is the
  only editor that can be pointed at a single area, and a single widget cannot be focused
  at all once a site uses the block widgets screen.
- **Header and sliders** — already identifiable in the markup, via
  `header.whb-header` and the `data-slide` / `data-slider` attributes WoodMart prints
  for logged-in users.
- **Theme settings areas** — the markup names them by class, and WoodMart registers its whole
  options tree on `init` without an `is_admin()` guard, so the front end can read it. For any
  option id the registry hands back the section to open, the translated label and where it sits
  in the tree; only the anchor itself is written down. The link uses WoodMart's own `tab=` deep
  link, plus a `wdem-field=` of ours that a small script on the settings page turns into the
  theme's own `.xts-highlight-field` treatment — the same one its options search uses.
  Only what one control actually owns is anchored: the copyrights columns and the notice text,
  never the band or the popup around them. Framing a whole area would promise an editor for
  padding and layout that no option covers.
- **Popups and floating blocks** — identifiable too, as `#popup-{ID}` and
  `#wd-fb-{ID}`, but the markup does not say which of them this page chose to print.
  WoodMart decides that from display conditions it keeps in a transient, so the plugin
  asks it the same question again; anything that did not print matches nothing in the
  DOM. A popup is `display: none` until it opens, and Magnific Popup *moves* the
  element into its wrapper rather than copying it, so the tag left on it at load
  survives and the open popup can be hovered.
- **Contact Form 7 and Mailchimp for WordPress forms** — the easiest of the lot, because both
  plugins already stamp the form's post ID into the markup: `data-wpcf7-id` on the CF7 wrapper,
  a `mc4wp-form-{ID}` class on the Mailchimp `<form>`. All that is missing is which forms the
  page printed, and each plugin has exactly one hook that fires once per rendered form —
  `wpcf7_shortcode_callback` and `mc4wp_output_form`. One hook each covers the shortcode, the
  block, the widget and WoodMart's own Mailchimp and Contact Form 7 elements, which do nothing
  but call those shortcodes. Neither plugin needs to be installed; without it the hook never
  fires.

  A form usually sits inside something that is already editable — an HTML block, a widget area —
  and being the narrower match it wins. When an HTML block holds *nothing but* the form the two
  land on the same element, and both buttons are offered, the form first.

## Who sees it

Only users who can `edit_posts`. Nothing is added to the markup for anyone else, and
the mode never activates inside the Elementor editor, an Elementor preview, the
front-end header builder, or a PJAX request.

Getting *into* edit mode is not enough to get every button. Each destination asks for its
own capability and is checked separately, so a button never lands on "insufficient
permissions": Theme Settings wants `manage_options`, widget areas `edit_theme_options`,
Mailchimp forms whatever `mc4wp_admin_required_capability` returns, Contact Form 7 forms the
`wpcf7_edit_contact_form` meta capability, and HTML blocks, layouts and popups the
capabilities of their post type.

## Known gaps

**Blocks loaded over AJAX have no buttons.** A mega menu item with *Load dropdown content
via AJAX* enabled is the usual case: the initial response holds only a placeholder,
which is then replaced wholesale, and the content itself arrives through
`admin-ajax.php` where `is_admin()` is `true` so no markers are emitted.

**The widget area button can land on a broken customizer**, on WoodMart 8.6.0 and any other
version carrying the same bug — nothing this plugin can fix from its own side. The theme
registers its block editor bundle with no dependencies at all:

```php
// inc/integrations/gutenberg/class-gutenberg.php:376
wp_register_script( 'xts-blocks', …/build/index.js, array(), WOODMART_VERSION, true );
```

while its own `build/index.asset.php` beside it lists twenty-one, `wp-plugins` among them. The
handle is enqueued on every block editor screen, because `build/blocks/row/block.json` names it
as `editorScript`. In the post editor and on `widgets.php` it survives by luck, since
`wp-edit-post` and `wp-edit-widgets` both depend on `wp-plugins` — but `wp-customize-widgets`
does not, so in the customizer the bundle dies on `wp.plugins.registerPlugin` the moment
`class-wp-customize-widgets.php` fires `enqueue_block_editor_assets`.

It only bites when the site uses the **block** widgets editor, and it is reproducible without
this plugin by opening **Appearance → Customize → Widgets** directly. The fix belongs in the
theme — hand `wp_register_script()` the dependencies from `index.asset.php`.

## Filters

| Filter | Purpose |
| --- | --- |
| `wdem_is_available` (bool) | force the edit mode on or off for a request |
| `wdem_selectors` (array) | add your own anchors — `array( 'selector' => '…', 'actions' => array( array( 'title', 'type', 'edit_url' ) ) )` |
| `wdem_settings_anchors` (array) | map more markup to theme settings — `array( 'selector' => '…', 'field' => 'option_id' )` or `'section' => 'section_id'` |
| `wdem_defer_to_theme` (bool) | return `false` to run alongside a WoodMart version that ships its own edit mode |

## Tests

The behaviour is covered by a jsdom harness rather than by clicking — 129 assertions
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

<?php
/**
 * Plugin bootstrap.
 *
 * @package woodmart-edit-mode
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direct access not allowed.
}

/**
 * Highlights the editable parts of a WoodMart front-end and links them to their editors.
 */
class WDEM_Plugin {
	/**
	 * Singleton instance.
	 *
	 * @var WDEM_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Data of the HTML blocks rendered during the current request. Key is a post ID.
	 *
	 * @var array
	 */
	private $rendered_blocks = array();

	/**
	 * Selector entries for the third-party forms rendered during the current request, keyed to
	 * keep a form that appears twice on a page from being offered twice.
	 *
	 * @var array
	 */
	private $rendered_forms = array();

	/**
	 * Whether the edit mode can be used on the current request.
	 *
	 * @var bool|null
	 */
	private $is_available = null;

	/**
	 * Theme settings fields and sections, keyed by id. Filled on first use.
	 *
	 * @var array|null
	 */
	private $settings_registry = null;

	/**
	 * Reason the plugin refuses to run, shown as an admin notice.
	 *
	 * @var string
	 */
	private $blocked_reason = '';

	/**
	 * Get the singleton instance.
	 *
	 * @return WDEM_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Start the plugin once the theme is known.
	 *
	 * @return void
	 */
	public static function boot() {
		self::instance()->init();
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function init() {
		if ( ! $this->check_requirements() ) {
			add_action( 'admin_notices', array( $this, 'requirements_notice' ) );

			return;
		}

		add_filter( 'wp_nav_menu', array( $this, 'wrap_nav_menu' ), 10, 2 );

		// Widget areas get their markers from core, which fires these around every dynamic_sidebar()
		// call — the sidebar template, the footer columns, the shop filters, the mobile panel and
		// any widget area a page builder drops into a page.
		add_action( 'dynamic_sidebar_before', array( $this, 'mark_widget_area_start' ), 5, 2 );
		add_action( 'dynamic_sidebar_after', array( $this, 'mark_widget_area_end' ), 15, 2 );

		// Contact Form 7 and Mailchimp for WordPress both stamp the form's post ID into the
		// markup, so neither needs markers — only a signal that the form rendered at all. Each of
		// these fires once per rendered form, on the one path all of that plugin's output takes.
		// Without the plugin the hook simply never fires.
		add_action( 'wpcf7_shortcode_callback', array( $this, 'record_contact_form_7' ), 10, 1 );
		add_action( 'mc4wp_output_form', array( $this, 'record_mailchimp_form' ), 10, 1 );

		add_action( 'admin_bar_menu', array( $this, 'add_toggle_to_admin_bar' ), 100 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 40 );
		add_action( 'wp_print_footer_scripts', array( $this, 'localize_data' ), 2 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_settings_field_highlight' ) );
	}

	/**
	 * Make sure the plugin has something to work with and nothing to collide with.
	 *
	 * @return bool
	 */
	private function check_requirements() {
		if ( ! defined( 'WOODMART_THEMEROOT' ) || ! function_exists( 'woodmart_get_post_content' ) ) {
			$this->blocked_reason = __( 'WoodMart Edit Mode needs the WoodMart theme to be active.', 'woodmart-edit-mode' );

			return false;
		}

		// Newer WoodMart versions ship this feature themselves; running both would draw two overlays.
		if ( class_exists( '\XTS\Modules\Edit_Mode\Edit_Mode' ) && apply_filters( 'wdem_defer_to_theme', true ) ) {
			$this->blocked_reason = __( 'The active WoodMart theme already provides an edit mode, so WoodMart Edit Mode stays out of the way. Return false from the "wdem_defer_to_theme" filter to run it anyway.', 'woodmart-edit-mode' );

			return false;
		}

		return true;
	}

	/**
	 * Tell the user why nothing is happening.
	 *
	 * @return void
	 */
	public function requirements_notice() {
		if ( ! $this->blocked_reason || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		printf( '<div class="notice notice-warning"><p>%s</p></div>', esc_html( $this->blocked_reason ) );
	}

	/**
	 * Check if the edit mode can be used on the current request.
	 *
	 * @return bool
	 */
	private function is_available() {
		if ( null !== $this->is_available ) {
			return $this->is_available;
		}

		$is_available = ! is_admin() && is_user_logged_in() && is_admin_bar_showing() && current_user_can( 'edit_posts' );

		if ( $is_available && function_exists( 'woodmart_is_pjax' ) && woodmart_is_pjax() ) {
			$is_available = false;
		}

		if ( $is_available && function_exists( 'woodmart_is_elementor_installed' ) && woodmart_is_elementor_installed() && ( woodmart_elementor_is_edit_mode() || woodmart_elementor_is_preview_mode() || woodmart_elementor_is_preview_page() ) ) {
			$is_available = false;
		}

		if ( $is_available && function_exists( 'woodmart_is_header_frontend_editor' ) && woodmart_is_header_frontend_editor() ) {
			$is_available = false;
		}

		$this->is_available = (bool) apply_filters( 'wdem_is_available', $is_available );

		return $this->is_available;
	}

	/**
	 * Wrap a rendered HTML block with the markers used to find it in the DOM.
	 *
	 * Called from the woodmart_get_html_block() override in the main plugin file.
	 *
	 * @param string $content Rendered content.
	 * @param int    $post_id Post ID.
	 *
	 * @return string
	 */
	public function wrap_block( $content, $post_id ) {
		if ( ! $this->blocked_reason && $this->is_available() && '' !== trim( $content ) ) {
			$this->rendered_blocks[ $post_id ] = array(
				'title'    => get_the_title( $post_id ),
				'type'     => __( 'HTML Block', 'woodmart-edit-mode' ),
				'edit_url' => $this->get_edit_url( $post_id ),
			);

			return '<!--wd-em-start:' . $post_id . '-->' . $content . '<!--wd-em-end:' . $post_id . '-->';
		}

		return $content;
	}

	/**
	 * Wrap a rendered navigation menu with the markers used to find it in the DOM.
	 *
	 * Every wp_nav_menu() call passes its output through this core filter, so one hook covers the
	 * header, the mobile panel, the sticky navigation, the footer and any widget.
	 *
	 * @param string   $nav_menu Rendered menu.
	 * @param stdClass $args     Menu arguments.
	 *
	 * @return string
	 */
	public function wrap_nav_menu( $nav_menu, $args ) {
		if ( $this->blocked_reason || ! $this->is_available() || '' === trim( (string) $nav_menu ) ) {
			return $nav_menu;
		}

		$menu = $this->resolve_nav_menu( $args );

		if ( ! $menu ) {
			return $nav_menu;
		}

		$key = 'menu-' . $menu->term_id;

		$this->rendered_blocks[ $key ] = array(
			'title'    => $menu->name,
			'type'     => __( 'Menu', 'woodmart-edit-mode' ),
			'edit_url' => admin_url( 'nav-menus.php?action=edit&menu=' . $menu->term_id ),
		);

		return '<!--wd-em-start:' . $key . '-->' . $nav_menu . '<!--wd-em-end:' . $key . '-->';
	}

	/**
	 * Open the markers around a widget area.
	 *
	 * @param int|string $index       Sidebar id, already normalised by dynamic_sidebar().
	 * @param bool       $has_widgets Whether anything is going to be rendered.
	 *
	 * @return void
	 */
	public function mark_widget_area_start( $index, $has_widgets ) {
		$key = $this->register_widget_area( $index, $has_widgets );

		if ( $key ) {
			echo '<!--wd-em-start:' . esc_html( $key ) . '-->';
		}
	}

	/**
	 * Close the markers around a widget area.
	 *
	 * @param int|string $index       Sidebar id, already normalised by dynamic_sidebar().
	 * @param bool       $has_widgets Whether anything was rendered.
	 *
	 * @return void
	 */
	public function mark_widget_area_end( $index, $has_widgets ) {
		$key = $this->register_widget_area( $index, $has_widgets );

		if ( $key ) {
			echo '<!--wd-em-end:' . esc_html( $key ) . '-->';
		}
	}

	/**
	 * Decide whether a widget area gets markers, and remember it for the script.
	 *
	 * Both ends of the pair ask this the same question about the same sidebar, so they always
	 * agree and a marker is never left unclosed.
	 *
	 * @param int|string $index       Sidebar id.
	 * @param bool       $has_widgets Whether the sidebar is populated.
	 *
	 * @return string Marker id, or an empty string when the area is not to be marked.
	 */
	private function register_widget_area( $index, $has_widgets ) {
		global $wp_registered_sidebars;

		// Core fires these for empty sidebars too. Markers with nothing between them would make
		// the scan fall back to their container, which is the whole template around the area.
		if ( ! $has_widgets || $this->blocked_reason || ! $this->is_available() ) {
			return '';
		}

		// Editing widgets is an edit_theme_options job; edit_posts got the user this far.
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			return '';
		}

		$index = (string) $index;

		// A sidebar id is free-form. Anything the marker syntax cannot carry is left alone rather
		// than written out as a comment the scan would not read back.
		if ( ! preg_match( '/^[\w-]+$/', $index ) || empty( $wp_registered_sidebars[ $index ]['name'] ) ) {
			return '';
		}

		$key = 'widgets-' . $index;

		$this->rendered_blocks[ $key ] = array(
			'title'    => $wp_registered_sidebars[ $index ]['name'],
			'type'     => __( 'Widget Area', 'woodmart-edit-mode' ),
			'edit_url' => $this->get_widget_area_url( $index ),
		);

		return $key;
	}

	/**
	 * The customizer URL that opens one widget area, previewing the page it was linked from.
	 *
	 * The customizer is the only editor that can be pointed at a single area: it registers a
	 * section per sidebar whether the site uses the block widgets screen or the classic one, while
	 * widgets.php reads nothing from its URL at all.
	 *
	 * @param string $sidebar_id Sidebar id.
	 *
	 * @return string
	 */
	private function get_widget_area_url( $sidebar_id ) {
		global $wp;

		$current = home_url( add_query_arg( array(), $wp->request ) );

		// Built by hand because add_query_arg() does not encode, and both the nested key and the
		// URLs need it.
		$query = http_build_query(
			array(
				'autofocus' => array( 'section' => 'sidebar-widgets-' . $sidebar_id ),
				'url'       => $current,
				'return'    => $current,
			)
		);

		return admin_url( 'customize.php?' . $query );
	}

	/**
	 * Work out which menu was rendered, the same way wp_nav_menu() does.
	 *
	 * @param stdClass $args Menu arguments.
	 *
	 * @return WP_Term|false
	 */
	private function resolve_nav_menu( $args ) {
		$menu = isset( $args->menu ) && $args->menu ? wp_get_nav_menu_object( $args->menu ) : false;

		if ( $menu ) {
			return $menu;
		}

		if ( empty( $args->theme_location ) ) {
			return false;
		}

		$locations = get_nav_menu_locations();

		if ( empty( $locations[ $args->theme_location ] ) ) {
			return false;
		}

		return wp_get_nav_menu_object( $locations[ $args->theme_location ] );
	}

	/**
	 * Remember a Contact Form 7 form that has just been rendered.
	 *
	 * The wrapper carries data-wpcf7-id, so the form needs no markers; this hook only answers
	 * which forms the page decided to print. It runs on the shortcode callback, which is every
	 * path CF7 has — its block saves the shortcode into the content rather than rendering itself.
	 *
	 * @param WPCF7_ContactForm $contact_form Form that was rendered.
	 *
	 * @return void
	 */
	public function record_contact_form_7( $contact_form ) {
		if ( $this->blocked_reason || ! $this->is_available() || ! is_object( $contact_form ) || ! method_exists( $contact_form, 'id' ) ) {
			return;
		}

		$id = (int) $contact_form->id();

		// Editing a form is its own meta capability, mapped to publish_pages by default. Far above
		// the edit_posts that got the user into edit mode.
		if ( ! $id || ! current_user_can( 'wpcf7_edit_contact_form', $id ) ) {
			return;
		}

		$this->rendered_forms[ 'wpcf7-' . $id ] = array(
			// Both the attribute and the id spell the form out, but only the attribute is exactly
			// the ID: the id also carries the page and an occurrence counter.
			'selector' => '.wpcf7[data-wpcf7-id="' . $id . '"]',
			'actions'  => array(
				array(
					'title'    => $contact_form->title(),
					'type'     => __( 'Contact form', 'woodmart-edit-mode' ),
					'edit_url' => admin_url(
						'admin.php?' . http_build_query(
							array(
								'page'   => 'wpcf7',
								'post'   => $id,
								'action' => 'edit',
							)
						)
					),
				),
			),
		);
	}

	/**
	 * Remember a Mailchimp for WordPress form that is about to be rendered.
	 *
	 * Fires from the one method every MC4WP render path goes through — the shortcode, the block,
	 * the widget and mc4wp_show_form() alike.
	 *
	 * @param MC4WP_Form $form Form that is being rendered.
	 *
	 * @return void
	 */
	public function record_mailchimp_form( $form ) {
		if ( $this->blocked_reason || ! $this->is_available() || ! is_object( $form ) || empty( $form->ID ) ) {
			return;
		}

		// MC4WP guards its whole admin behind one capability and lets the site filter it.
		if ( ! current_user_can( (string) apply_filters( 'mc4wp_admin_required_capability', 'manage_options' ) ) ) {
			return;
		}

		$id = (int) $form->ID;

		$this->rendered_forms[ 'mc4wp-' . $id ] = array(
			// The form element carries its post ID as a class. The id attribute is a per-page
			// counter, so it says nothing about which form this is.
			'selector' => 'form.mc4wp-form-' . $id,
			'actions'  => array(
				array(
					'title'    => $form->name,
					'type'     => __( 'Mailchimp form', 'woodmart-edit-mode' ),
					// Built by hand: mc4wp_get_edit_form_url() lives in an admin-only file.
					'edit_url' => admin_url(
						'admin.php?' . http_build_query(
							array(
								'page'    => 'mailchimp-for-wp-forms',
								'view'    => 'edit-form',
								'form_id' => $id,
							)
						)
					),
				),
			),
		);
	}

	/**
	 * Get the elements that are found by a CSS selector instead of the markers.
	 *
	 * @return array
	 */
	private function get_selectors_data() {
		$data = array();

		$page_actions = array();

		foreach ( $this->get_rendered_layouts() as $id => $layout ) {
			// A product loop item layout governs the product grid and nothing else. WoodMart puts
			// its ID on that grid's wrapper in woocommerce/loop/loop-start.php, which makes a far
			// more precise anchor than the whole page. Several grids can share one layout.
			if ( 'product_loop_item' === $layout['layout_type'] ) {
				$data[] = array(
					'selector' => '.wd-loop-item-wrap-' . (int) $id,
					'actions'  => array(
						array(
							'title'    => $layout['title'],
							'type'     => __( 'Product loop item', 'woodmart-edit-mode' ),
							'edit_url' => $layout['edit_url'],
						),
					),
				);

				continue;
			}

			$page_actions[] = array(
				'title'    => $layout['title'],
				'type'     => __( 'Layout', 'woodmart-edit-mode' ),
				'edit_url' => $layout['edit_url'],
			);
		}

		// Some pages render more than one page-level layout, the checkout among them. They share
		// one anchor, so they are offered side by side as separate buttons.
		if ( $page_actions ) {
			$data[] = array(
				'selector' => 'main#main-content',
				'actions'  => $page_actions,
			);
		}

		if ( function_exists( 'whb_get_header' ) ) {
			$header = whb_get_header();

			if ( $header ) {
				global $wp;

				$data[] = array(
					'selector' => 'header.whb-header',
					'actions'  => array(
						array(
							'title'    => $header->get_name(),
							'type'     => __( 'Header', 'woodmart-edit-mode' ),
							'edit_url' => home_url( add_query_arg( array(), $wp->request ) ) . '?whb-header-frontend=' . $header->get_id(),
						),
					),
				);
			}
		}

		$data = array_merge( $data, array_values( $this->rendered_forms ), $this->get_floating_blocks_data(), $this->get_theme_settings_data() );

		return apply_filters( 'wdem_selectors', $data );
	}

	/**
	 * Get the popups and floating blocks that this page printed.
	 *
	 * Both already carry their post ID in the markup, so no marker is needed. What the markup does
	 * not say is which of them the page decided to print, and there is no hook around either
	 * render. WoodMart works the list out from the display conditions it keeps in a transient, so
	 * asking it the same question again is cheap — and a block that was not printed simply matches
	 * nothing in the DOM.
	 *
	 * @return array
	 */
	private function get_floating_blocks_data() {
		if ( ! class_exists( '\XTS\Modules\Floating_Blocks\Manager' ) ) {
			return array();
		}

		// The theme prints neither while a builder post is being previewed on its own.
		if ( in_array( get_post_type(), array( 'woodmart_slide', 'cms_block', 'wd_product_tabs', 'wd_floating_block', 'wd_popup', 'woodmart_layout' ), true ) ) {
			return array();
		}

		$types = array(
			// A popup is display:none until Magnific moves it into its own wrapper. It moves the
			// element rather than copying it, so the tag left on it by the scan travels along and
			// the popup can be hovered once it is open.
			'wd_popup'          => array(
				'label'    => __( 'Popup', 'woodmart-edit-mode' ),
				'selector' => '#popup-%d.wd-popup-builder',
			),
			// The holder spans the viewport with pointer-events: none, so it can neither be hovered
			// nor framed. The wrap inside it is the block as the visitor sees it.
			'wd_floating_block' => array(
				'label'    => __( 'Floating block', 'woodmart-edit-mode' ),
				'selector' => '#wd-fb-%d > .wd-fb-wrap',
			),
		);

		$manager = \XTS\Modules\Floating_Blocks\Manager::get_instance();
		$data    = array();

		foreach ( $types as $post_type => $type ) {
			foreach ( $manager->get_current_ids( $post_type ) as $id ) {
				// The promo popup of the theme settings is listed as "legacy" and has no post.
				if ( ! is_numeric( $id ) ) {
					continue;
				}

				// Both post types take the capabilities of a page, which an author does not have
				// even though edit_posts got them this far. No editor, no button.
				$edit_url = $this->get_edit_url( $id );

				if ( ! $edit_url ) {
					continue;
				}

				$data[] = array(
					'selector' => sprintf( $type['selector'], (int) $id ),
					'actions'  => array(
						array(
							'title'    => get_the_title( $id ),
							'type'     => $type['label'],
							'edit_url' => $edit_url,
						),
					),
				);
			}
		}

		return $data;
	}

	/**
	 * Get the areas whose content comes from the theme settings rather than from a post.
	 *
	 * The markup gives these away by class alone, and WoodMart registers its whole options tree on
	 * "init" without an is_admin() guard, so the front end can read it: for any option id the
	 * registry hands back the section to open, the translated label and where it sits in the tree.
	 * Only the anchor itself has to be written down here.
	 *
	 * @return array
	 */
	private function get_theme_settings_data() {
		if ( ! class_exists( '\XTS\Admin\Modules\Options' ) ) {
			return array();
		}

		// The settings page asks for manage_options, which edit_posts got nowhere near.
		if ( ! current_user_can( apply_filters( 'woodmart_capability_menu_page', 'manage_options', 'xts_theme_settings' ) ) ) {
			return array();
		}

		$anchors = apply_filters(
			'wdem_settings_anchors',
			array(
				// The two columns of the copyrights strip under the footer, each its own option.
				// The strip itself is deliberately left alone: framing the whole band would say
				// "this is editable" about padding and layout that no single control owns.
				array(
					'selector' => '.wd-copyrights .wd-col-start',
					'field'    => 'copyrights',
				),
				array(
					'selector' => '.wd-copyrights .wd-col-end',
					'field'    => 'copyrights2',
				),

				// The text of the cookie law notice. Its buttons are not an option, so nothing
				// around the text is anchored either.
				array(
					'selector' => '.wd-cookies-popup .cookies-info-text',
					'field'    => 'cookies_text',
				),
			)
		);

		$data = array();

		foreach ( $anchors as $anchor ) {
			if ( empty( $anchor['selector'] ) ) {
				continue;
			}

			$action = isset( $anchor['field'] )
				? $this->get_settings_field_action( $anchor['field'] )
				: $this->get_settings_section_action( isset( $anchor['section'] ) ? $anchor['section'] : '' );

			// An option this theme version no longer registers gets no button rather than a link
			// into a section that is not there any more.
			if ( ! $action ) {
				continue;
			}

			$data[] = array(
				'selector' => $anchor['selector'],
				'actions'  => array( $action ),
			);
		}

		return $data;
	}

	/**
	 * Build the action that opens a single theme settings option.
	 *
	 * @param string $field_id Option id.
	 *
	 * @return array|null
	 */
	private function get_settings_field_action( $field_id ) {
		$registry = $this->get_settings_registry();

		if ( empty( $registry['fields'][ $field_id ]['section'] ) || empty( $registry['fields'][ $field_id ]['name'] ) ) {
			return null;
		}

		$field = $registry['fields'][ $field_id ];

		return array(
			'title'    => $field['name'],
			'type'     => __( 'Theme Settings', 'woodmart-edit-mode' ),
			'edit_url' => $this->get_settings_url( $field['section'], $field_id ),
		);
	}

	/**
	 * Build the action that opens a whole theme settings section.
	 *
	 * @param string $section_id Section id.
	 *
	 * @return array|null
	 */
	private function get_settings_section_action( $section_id ) {
		$registry = $this->get_settings_registry();

		if ( empty( $registry['sections'][ $section_id ]['name'] ) ) {
			return null;
		}

		$section = $registry['sections'][ $section_id ];
		$parent  = ! empty( $section['parent'] ) && ! empty( $registry['sections'][ $section['parent'] ]['name'] )
			? $registry['sections'][ $section['parent'] ]['name'] . ' / '
			: '';

		return array(
			'title'    => $parent . $section['name'],
			'type'     => __( 'Theme Settings', 'woodmart-edit-mode' ),
			'edit_url' => $this->get_settings_url( $section_id ),
		);
	}

	/**
	 * The theme settings page URL, opened on a section and optionally pointed at one option.
	 *
	 * "tab" is WoodMart's own deep link — the page reads it while rendering and its navigation
	 * writes it back on every click. "wdem-field" is ours, read by assets/settings-field.js.
	 *
	 * @param string $section_id Section id.
	 * @param string $field_id   Option id, when the link should land on a single control.
	 *
	 * @return string
	 */
	private function get_settings_url( $section_id, $field_id = '' ) {
		$args = array(
			'page' => 'xts_theme_settings',
			'tab'  => $section_id,
		);

		if ( $field_id ) {
			$args['wdem-field'] = $field_id;
		}

		return admin_url( 'admin.php?' . http_build_query( $args ) );
	}

	/**
	 * Read the theme settings tree, keyed by id.
	 *
	 * @return array
	 */
	private function get_settings_registry() {
		if ( null !== $this->settings_registry ) {
			return $this->settings_registry;
		}

		$registry = array(
			'fields'   => array(),
			'sections' => array(),
		);

		foreach ( \XTS\Admin\Modules\Options::get_fields() as $field ) {
			if ( ! empty( $field->args['id'] ) ) {
				$registry['fields'][ $field->args['id'] ] = $field->args;
			}
		}

		foreach ( \XTS\Admin\Modules\Options::get_sections() as $section ) {
			if ( ! empty( $section['id'] ) ) {
				$registry['sections'][ $section['id'] ] = $section;
			}
		}

		$this->settings_registry = $registry;

		return $this->settings_registry;
	}

	/**
	 * Get the layouts that actually rendered on this page.
	 *
	 * WoodMart collects them itself while rendering, for its own admin bar menu. There is no hook
	 * around the layout output, so this global is the only honest source of what is on the page.
	 *
	 * @return array
	 */
	private function get_rendered_layouts() {
		global $woodmart_editable_posts_bar_data;

		if ( ! is_array( $woodmart_editable_posts_bar_data ) ) {
			return array();
		}

		$layouts = array();

		foreach ( $woodmart_editable_posts_bar_data as $item ) {
			if ( isset( $item['type'] ) && 'woodmart_layout' === $item['type'] ) {
				$layouts[ $item['id'] ] = array(
					'title'       => $item['title'],
					'edit_url'    => html_entity_decode( (string) $item['edit_url'] ),
					'layout_type' => (string) get_post_meta( $item['id'], 'wd_layout_type', true ),
				);
			}
		}

		return $layouts;
	}

	/**
	 * Get the editor URL for the given post.
	 *
	 * @param int $post_id Post ID.
	 *
	 * @return string
	 */
	private function get_edit_url( $post_id ) {
		if ( did_action( 'elementor/loaded' ) && class_exists( '\Elementor\Plugin' ) ) {
			$document = \Elementor\Plugin::$instance->documents->get( $post_id );

			if ( $document && $document->is_built_with_elementor() ) {
				return $document->get_edit_url();
			}
		}

		return (string) get_edit_post_link( $post_id, 'url' );
	}

	/**
	 * Add the edit mode toggle to the admin bar.
	 *
	 * @param WP_Admin_Bar $admin_bar Admin bar instance.
	 *
	 * @return void
	 */
	public function add_toggle_to_admin_bar( $admin_bar ) {
		if ( ! $this->is_available() ) {
			return;
		}

		$admin_bar->add_node(
			array(
				'id'    => 'wdem-edit-mode',
				'title' => '<span class="ab-icon"></span><span class="ab-label">' . esc_html__( 'Edit mode', 'woodmart-edit-mode' ) . '</span><span class="wd-em-status"></span>',
				'href'  => '#',
				'meta'  => array(
					'title' => esc_attr__( 'Highlight the editable parts of this page', 'woodmart-edit-mode' ),
				),
			)
		);
	}

	/**
	 * Enqueue the edit mode script and styles.
	 *
	 * @return void
	 */
	public function enqueue_assets() {
		if ( ! $this->is_available() ) {
			return;
		}

		wp_enqueue_style( 'wdem-edit-mode', WDEM_URL . 'assets/edit-mode.css', array(), WDEM_VERSION );
		wp_enqueue_script( 'wdem-edit-mode', WDEM_URL . 'assets/edit-mode.js', array(), WDEM_VERSION, true );
	}

	/**
	 * Enqueue the script that walks the theme settings page to the option that was linked to.
	 *
	 * The only thing this plugin loads in wp-admin, and only on that one page when a link actually
	 * asked for an option. Anyone reaching the page already holds the capability the menu asks for.
	 *
	 * @return void
	 */
	public function enqueue_settings_field_highlight() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading a link, not acting on it.
		if ( empty( $_GET['page'] ) || 'xts_theme_settings' !== $_GET['page'] || empty( $_GET['wdem-field'] ) ) {
			return;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		wp_enqueue_script( 'wdem-settings-field', WDEM_URL . 'assets/settings-field.js', array(), WDEM_VERSION, true );
	}

	/**
	 * Pass the collected data to the script.
	 *
	 * Runs after WoodMart fills its own admin bar data at priority 1, and before the footer scripts
	 * are printed at priority 20.
	 *
	 * @return void
	 */
	public function localize_data() {
		if ( ! $this->is_available() ) {
			return;
		}

		wp_localize_script(
			'wdem-edit-mode',
			'wdemEditMode',
			array(
				'blocks'    => (object) $this->rendered_blocks,
				'selectors' => $this->get_selectors_data(),
				'labels'    => array(
					'edit'   => esc_html__( 'Edit', 'woodmart-edit-mode' ),
					'on'     => esc_html_x( 'ON', 'edit mode state', 'woodmart-edit-mode' ),
					'off'    => esc_html_x( 'OFF', 'edit mode state', 'woodmart-edit-mode' ),
					'slide'  => esc_html__( 'Slide', 'woodmart-edit-mode' ),
					'slider' => esc_html__( 'Slider', 'woodmart-edit-mode' ),
				),
			)
		);
	}
}

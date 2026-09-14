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
	 * Whether the edit mode can be used on the current request.
	 *
	 * @var bool|null
	 */
	private $is_available = null;

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

		add_action( 'admin_bar_menu', array( $this, 'add_toggle_to_admin_bar' ), 100 );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ), 40 );
		add_action( 'wp_print_footer_scripts', array( $this, 'localize_data' ), 2 );
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

		$data = array_merge( $data, $this->get_floating_blocks_data() );

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
				'title' => '<span class="ab-icon"></span><span class="ab-label">' . esc_html__( 'Edit mode', 'woodmart-edit-mode' ) . '</span>',
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
					'slide'  => esc_html__( 'Slide', 'woodmart-edit-mode' ),
					'slider' => esc_html__( 'Slider', 'woodmart-edit-mode' ),
				),
			)
		);
	}
}

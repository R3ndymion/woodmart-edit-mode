<?php
/**
 * Live settings: the theme settings next to a live preview of the site, the way the customizer works.
 *
 * @package woodmart-edit-mode
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direct access not allowed.
}

/**
 * Edits the WoodMart theme settings beside a live preview.
 *
 * Nothing here draws a control. The left pane is the theme's own settings page, loaded in a frame and
 * stripped of the admin chrome, so every control, dependency and sanitizer is the theme's. What this
 * class adds is the bridge: unsaved values travel to a per-user transient, and a preview request
 * carrying a nonce reads the options through that transient instead of the database. Saving is the
 * theme's own form submit.
 */
class WDEM_Live_Settings {
	/**
	 * Admin page slug of the editor.
	 */
	const PAGE = 'wdem-live-settings';

	/**
	 * Query var of a preview request: a nonce bound to the user.
	 */
	const PREVIEW_VAR = 'wdem-live';

	/**
	 * Query var that turns a preview request into the theme settings CSS of that page.
	 */
	const CSS_VAR = 'wdem-live-css';

	/**
	 * Query var of the settings page loaded inside the editor.
	 */
	const FRAME_VAR = 'wdem-live-frame';

	/**
	 * Option the theme keeps its settings in.
	 */
	const OPTION = 'xts-woodmart-options';

	/**
	 * Settings that are form plumbing rather than options.
	 *
	 * @var array
	 */
	private static $reserved = array( 'last_tab', 'last_message', 'import_export', 'import-btn', 'reset-defaults', 'preset', 'fields_to_save' );

	/**
	 * Singleton instance.
	 *
	 * @var WDEM_Live_Settings|null
	 */
	private static $instance = null;

	/**
	 * Whether the current request is a verified preview. Null until it can be known.
	 *
	 * @var bool|null
	 */
	private $is_preview = null;

	/**
	 * Get the singleton instance.
	 *
	 * @return WDEM_Live_Settings
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Hooks that have to be in place before the theme loads its options.
	 *
	 * WoodMart reads its option the moment its options class is constructed, while functions.php is
	 * being included, so the filter has to exist before the theme does.
	 *
	 * @return void
	 */
	public static function early() {
		$instance = self::instance();

		add_filter( 'option_' . self::OPTION, array( $instance, 'filter_options' ), 99 );
		add_filter( 'pre_update_option', array( $instance, 'block_writes' ), 1, 3 );
	}

	/**
	 * Register the rest once the theme is known to be WoodMart.
	 *
	 * @return void
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_bar_menu', array( $this, 'add_admin_bar_link' ), 101 );
		add_action( 'wp_ajax_wdem_live_update', array( $this, 'ajax_update' ) );
		add_action( 'wp_ajax_wdem_live_discard', array( $this, 'ajax_discard' ) );
		add_action( 'update_option_' . self::OPTION, array( $this, 'forget_preview' ) );

		// The settings page inside the editor.
		add_filter( 'admin_body_class', array( $this, 'frame_body_class' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_frame_assets' ) );

		// The preview.
		add_filter( 'show_admin_bar', array( $this, 'hide_admin_bar_in_preview' ), 1000 );
		add_filter( 'wdem_is_available', array( $this, 'disable_edit_mode_in_preview' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_preview_assets' ), 11001 );
		add_action( 'wp_head', array( $this, 'print_preview_css' ), 10001 );
		add_action( 'template_redirect', array( $this, 'output_preview_css' ), 0 );
		add_filter( 'wp_redirect', array( $this, 'keep_preview_on_redirect' ), 1000 );
	}

	/**
	 * Version of an asset: the plugin version plus the file's modification time, so an edited file is
	 * never served from a cache keyed on the version alone.
	 *
	 * @param string $file Path relative to the plugin.
	 *
	 * @return string
	 */
	public static function asset_version( $file ) {
		$mtime = @filemtime( WDEM_PATH . $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return WDEM_VERSION . ( $mtime ? '.' . $mtime : '' );
	}

	/**
	 * Capability of the theme settings page, which is what editing them takes.
	 *
	 * @return string
	 */
	public static function get_capability() {
		return (string) apply_filters( 'woodmart_capability_menu_page', 'manage_options', 'xts_theme_settings' );
	}

	/**
	 * Whether the user may open the editor.
	 *
	 * @return bool
	 */
	public static function can_edit() {
		return class_exists( '\XTS\Admin\Modules\Options' ) && current_user_can( self::get_capability() );
	}

	/**
	 * The editor URL, previewing the given page.
	 *
	 * @param string $url        Page to preview.
	 * @param string $section_id Theme settings section to open.
	 * @param string $field_id   Option to scroll to.
	 *
	 * @return string
	 */
	public static function get_editor_url( $url = '', $section_id = '', $field_id = '' ) {
		$args = array( 'page' => self::PAGE );

		if ( $url ) {
			$args['url'] = $url;
		}

		if ( $section_id ) {
			$args['tab'] = $section_id;
		}

		if ( $field_id ) {
			$args['wdem-field'] = $field_id;
		}

		return admin_url( 'admin.php?' . http_build_query( $args ) );
	}

	/**
	 * Whether the current request is a preview of the user who opened the editor.
	 *
	 * The answer is cached only once it can be given: an option read before pluggable.php is loaded
	 * cannot know the user yet.
	 *
	 * @return bool
	 */
	public function is_preview() {
		if ( null !== $this->is_preview ) {
			return $this->is_preview;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The value is the nonce.
		if ( empty( $_GET[ self::PREVIEW_VAR ] ) || is_admin() ) {
			$this->is_preview = false;

			return false;
		}

		if ( ! function_exists( 'wp_verify_nonce' ) || ! function_exists( 'wp_get_current_user' ) ) {
			return false;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$nonce = sanitize_text_field( wp_unslash( $_GET[ self::PREVIEW_VAR ] ) );

		$this->is_preview = is_user_logged_in() && wp_verify_nonce( $nonce, 'wdem_live_preview' ) && current_user_can( self::get_capability() );

		return $this->is_preview;
	}

	/**
	 * Key of the user's unsaved values.
	 *
	 * @return string
	 */
	private function get_transient_key() {
		return 'wdem_live_' . get_current_user_id();
	}

	/**
	 * Hand the preview the unsaved values instead of the saved ones.
	 *
	 * @param mixed $value Saved options.
	 *
	 * @return mixed
	 */
	public function filter_options( $value ) {
		if ( ! $this->is_preview() ) {
			return $value;
		}

		$preview = get_transient( $this->get_transient_key() );

		return is_array( $preview ) ? $preview : $value;
	}

	/**
	 * Keep a preview from writing anything derived from unsaved values.
	 *
	 * The theme caches its settings CSS in options, and regenerates that cache on the front end when it
	 * is missing. In a preview that would publish the unsaved values to every visitor.
	 *
	 * @param mixed  $value     New value.
	 * @param string $option    Option name.
	 * @param mixed  $old_value Current value.
	 *
	 * @return mixed
	 */
	public function block_writes( $value, $option, $old_value ) {
		if ( ( self::OPTION === $option || 0 === strpos( $option, 'xts-theme_settings_' ) ) && $this->is_preview() ) {
			return $old_value;
		}

		return $value;
	}

	/**
	 * A save went through: there is nothing unsaved any more.
	 *
	 * @return void
	 */
	public function forget_preview() {
		if ( get_current_user_id() ) {
			delete_transient( $this->get_transient_key() );
		}
	}

	/**
	 * Register the editor as a page without a menu item.
	 *
	 * @return void
	 */
	public function register_page() {
		if ( ! self::can_edit() ) {
			return;
		}

		// Pages under options.php are reachable but never listed.
		$hook = add_submenu_page( 'options.php', __( 'Live settings', 'woodmart-edit-mode' ), __( 'Live settings', 'woodmart-edit-mode' ), self::get_capability(), self::PAGE, '__return_null' );

		if ( $hook ) {
			add_action( 'load-' . $hook, array( $this, 'render_editor' ) );
		}
	}

	/**
	 * Link to the editor from the front end, previewing the page it was clicked on.
	 *
	 * @param WP_Admin_Bar $admin_bar Admin bar instance.
	 *
	 * @return void
	 */
	public function add_admin_bar_link( $admin_bar ) {
		if ( is_admin() || $this->is_preview() || ! self::can_edit() ) {
			return;
		}

		// The full address, query string included: with plain permalinks the query string is the page.
		$host    = isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$uri     = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		$current = $host ? set_url_scheme( 'http://' . $host . $uri ) : home_url( '/' );

		$admin_bar->add_node(
			array(
				'id'    => 'wdem-live-settings',
				'title' => '<span class="ab-icon"></span><span class="ab-label">' . esc_html__( 'Live settings', 'woodmart-edit-mode' ) . '</span>',
				'href'  => self::get_editor_url( remove_query_arg( self::PREVIEW_VAR, $current ) ),
				'meta'  => array(
					'title' => esc_attr__( 'Edit the theme settings with a live preview of this page', 'woodmart-edit-mode' ),
				),
			)
		);
	}

	/**
	 * Print the editor: a document of its own, like customize.php, with no admin chrome around it.
	 *
	 * @return void
	 */
	public function render_editor() {
		if ( ! self::can_edit() ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to edit the theme settings.', 'woodmart-edit-mode' ), 403 );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Reading a link, not acting on it.
		$url     = isset( $_GET['url'] ) ? wp_validate_redirect( esc_url_raw( wp_unslash( $_GET['url'] ) ), home_url( '/' ) ) : home_url( '/' );
		$section = isset( $_GET['tab'] ) ? preg_replace( '/[^\w-]/', '', wp_unslash( $_GET['tab'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$field   = isset( $_GET['wdem-field'] ) ? preg_replace( '/[^\w-]/', '', wp_unslash( $_GET['wdem-field'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		// Every session starts from what is saved. The settings frame shows the saved values, so left
		// over unsaved ones would make the preview disagree with the controls.
		delete_transient( $this->get_transient_key() );

		$settings_args = array(
			'page'          => 'xts_theme_settings',
			self::FRAME_VAR => 1,
		);

		if ( $section ) {
			$settings_args['tab'] = $section;
		}

		if ( $field ) {
			$settings_args['wdem-field'] = $field;
		}

		$config = array(
			'settingsUrl' => admin_url( 'admin.php?' . http_build_query( $settings_args ) ),
			'previewUrl'  => add_query_arg( self::PREVIEW_VAR, wp_create_nonce( 'wdem_live_preview' ), $url ),
			'previewVar'  => self::PREVIEW_VAR,
			'cssVar'      => self::CSS_VAR,
			'closeUrl'    => $url,
			'ajaxUrl'     => admin_url( 'admin-ajax.php' ),
			'nonce'       => wp_create_nonce( 'wdem_live_update' ),
			'labels'      => array(
				'leave'   => __( 'The changes you made will be lost if you leave this page.', 'woodmart-edit-mode' ),
				'discard' => __( 'Discard all unsaved changes?', 'woodmart-edit-mode' ),
			),
		);

		nocache_headers();
		header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );

		include WDEM_PATH . 'includes/views/live-settings.php';

		exit;
	}

	/**
	 * Whether the current admin request is the settings page inside the editor.
	 *
	 * @return bool
	 */
	private function is_frame() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Reading a link, not acting on it.
		return is_admin() && ! empty( $_GET[ self::FRAME_VAR ] ) && isset( $_GET['page'] ) && 'xts_theme_settings' === $_GET['page'] && empty( $_GET['preset'] ) && self::can_edit();
	}

	/**
	 * Mark the framed settings page, so its stylesheet can strip the admin chrome.
	 *
	 * @param string $classes Body classes.
	 *
	 * @return string
	 */
	public function frame_body_class( $classes ) {
		if ( ! $this->is_frame() ) {
			return $classes;
		}

		return $classes . ' wdem-live-frame ' . ( $this->opens_on_section() ? 'wdem-view-section' : 'wdem-view-nav' );
	}

	/**
	 * Whether the panel opens on the fields of a section rather than on the list of sections.
	 *
	 * It does when the link names a section that has fields of its own. A group only collects
	 * sections, so it opens on the list, as does a panel opened without a section.
	 *
	 * @return bool
	 */
	private function opens_on_section() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$tab = isset( $_GET['tab'] ) ? preg_replace( '/[^\w-]/', '', wp_unslash( $_GET['tab'] ) ) : '';

		if ( ! $tab ) {
			return false;
		}

		foreach ( \XTS\Admin\Modules\Options::get_sections() as $section ) {
			if ( isset( $section['parent'] ) && $tab === $section['parent'] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Load the bridge into the framed settings page.
	 *
	 * @return void
	 */
	public function enqueue_frame_assets() {
		if ( ! $this->is_frame() ) {
			return;
		}

		wp_enqueue_style( 'wdem-live-frame', WDEM_URL . 'assets/live-settings-frame.css', array(), self::asset_version( 'assets/live-settings-frame.css' ) );
		wp_enqueue_script( 'wdem-live-frame', WDEM_URL . 'assets/live-settings-frame.js', array(), self::asset_version( 'assets/live-settings-frame.js' ), true );

		wp_localize_script(
			'wdem-live-frame',
			'wdemLiveFrame',
			array(
				'ajaxUrl'  => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'wdem_live_update' ),
				'option'   => self::OPTION,
				'reserved' => self::$reserved,
				'back'     => __( 'Back to all sections', 'woodmart-edit-mode' ),
				// The theme strips the parameter from the address bar as soon as its script runs.
				'saved'    => ! empty( $_GET['settings-updated'] ), // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			)
		);
	}

	/**
	 * Store the unsaved values of the fields that differ from what is saved.
	 *
	 * The frame sends only the fields that changed, each with every input it owns, plus their ids so
	 * that a field which submits nothing at all — every checkbox cleared — still counts. The rest
	 * comes from the database, and the whole set goes through the theme's own sanitizer.
	 *
	 * @return void
	 */
	public function ajax_update() {
		check_ajax_referer( 'wdem_live_update' );

		if ( ! self::can_edit() ) {
			wp_send_json_error( null, 403 );
		}

		$fields = $this->get_fields_registry();

		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized by the theme below.
		$changed = isset( $_POST['changed'] ) ? array_filter( array_map( 'strval', (array) wp_unslash( $_POST['changed'] ) ) ) : array();
		$posted  = isset( $_POST[ self::OPTION ] ) && is_array( $_POST[ self::OPTION ] ) ? wp_unslash( $_POST[ self::OPTION ] ) : array();
		// phpcs:enable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		$saved   = get_option( self::OPTION, array() );
		$saved   = is_array( $saved ) ? $saved : array();
		$options = $saved;
		$unsaved = false;

		foreach ( $changed as $id ) {
			if ( ! isset( $fields[ $id ] ) || in_array( $id, self::$reserved, true ) ) {
				continue;
			}

			$options[ $id ] = isset( $posted[ $id ] ) ? $posted[ $id ] : '';
			$unsaved        = true;
		}

		$previous = get_transient( $this->get_transient_key() );
		$previous = is_array( $previous ) ? $previous : $saved;

		if ( $unsaved ) {
			$options = \XTS\Admin\Modules\Options::get_instance()->sanitize_before_save( $options );

			set_transient( $this->get_transient_key(), $options, DAY_IN_SECONDS );
		} else {
			$options = $saved;

			delete_transient( $this->get_transient_key() );
		}

		$differs = array();

		foreach ( array_keys( $fields ) as $id ) {
			$before = isset( $previous[ $id ] ) ? $previous[ $id ] : null;
			$after  = isset( $options[ $id ] ) ? $options[ $id ] : null;

			if ( wp_json_encode( $before ) !== wp_json_encode( $after ) ) {
				$differs[] = $id;
			}
		}

		$css_only = (bool) $differs;

		foreach ( $differs as $id ) {
			if ( ! $fields[ $id ] ) {
				$css_only = false;
				break;
			}
		}

		wp_send_json_success(
			array(
				'changed' => $differs,
				'cssOnly' => $css_only,
				'unsaved' => $unsaved,
			)
		);
	}

	/**
	 * Throw the unsaved values away.
	 *
	 * @return void
	 */
	public function ajax_discard() {
		check_ajax_referer( 'wdem_live_update' );

		if ( ! self::can_edit() ) {
			wp_send_json_error( null, 403 );
		}

		delete_transient( $this->get_transient_key() );

		wp_send_json_success();
	}

	/**
	 * Every option id the theme registers, with whether a change to it only changes the CSS.
	 *
	 * A field with selectors writes its value into the theme settings stylesheet and nothing else, so
	 * the preview can swap the stylesheet instead of reloading. Typography is the exception: a new
	 * family needs a font link the stylesheet does not carry.
	 *
	 * @return array Option id => bool.
	 */
	private function get_fields_registry() {
		$registry = array();

		foreach ( \XTS\Admin\Modules\Options::get_fields() as $field ) {
			$list = array( $field );

			if ( ! empty( $field->inner_fields ) ) {
				$list = array_merge( $list, $field->inner_fields );
			}

			foreach ( $list as $item ) {
				if ( empty( $item->args['id'] ) ) {
					continue;
				}

				$registry[ $item->args['id'] ] = ! empty( $item->args['selectors'] ) && 'typography' !== $item->args['type'] && 'group' !== $item->args['type'];
			}
		}

		return $registry;
	}

	/**
	 * Carry the preview through the redirects of the front end — canonical URLs, the shop page,
	 * a login wall — or the next page would quietly show the saved settings instead.
	 *
	 * @param string $location Redirect target.
	 *
	 * @return string
	 */
	public function keep_preview_on_redirect( $location ) {
		if ( ! $location || ! $this->is_preview() ) {
			return $location;
		}

		$target = wp_parse_url( $location );
		$home   = wp_parse_url( home_url() );

		// Relative targets stay on this site; absolute ones only count when they do.
		if ( ! empty( $target['host'] ) && ( empty( $home['host'] ) || strtolower( $target['host'] ) !== strtolower( $home['host'] ) ) ) {
			return $location;
		}

		if ( 0 === strpos( $location, admin_url() ) || false !== strpos( $location, '/wp-admin/' ) || false !== strpos( $location, 'wp-login.php' ) ) {
			return $location;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return add_query_arg( self::PREVIEW_VAR, rawurlencode( sanitize_text_field( wp_unslash( $_GET[ self::PREVIEW_VAR ] ) ) ), $location );
	}

	/**
	 * Keep the preview a picture of the page: no admin bar, no edit mode.
	 *
	 * @param bool $show Whether to show the admin bar.
	 *
	 * @return bool
	 */
	public function hide_admin_bar_in_preview( $show ) {
		return $this->is_preview() ? false : $show;
	}

	/**
	 * Turn the edit mode off inside the preview.
	 *
	 * @param bool $available Whether the edit mode is available.
	 *
	 * @return bool
	 */
	public function disable_edit_mode_in_preview( $available ) {
		return $this->is_preview() ? false : $available;
	}

	/**
	 * Replace the cached settings stylesheet with one built from the unsaved values, and keep the
	 * preview parameter on every link.
	 *
	 * @return void
	 */
	public function enqueue_preview_assets() {
		if ( ! $this->is_preview() ) {
			return;
		}

		foreach ( wp_styles()->queue as $handle ) {
			if ( 0 === strpos( $handle, 'xts-style-theme_settings_' ) ) {
				wp_dequeue_style( $handle );
			}
		}

		wp_enqueue_script( 'wdem-live-preview', WDEM_URL . 'assets/live-settings-preview.js', array(), self::asset_version( 'assets/live-settings-preview.js' ), true );
		wp_localize_script(
			'wdem-live-preview',
			'wdemLivePreview',
			array(
				'param' => self::PREVIEW_VAR,
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
				'value' => sanitize_text_field( wp_unslash( $_GET[ self::PREVIEW_VAR ] ) ),
				'admin' => admin_url(),
			)
		);
	}

	/**
	 * Print the settings stylesheet of the preview, after the theme's own.
	 *
	 * @return void
	 */
	public function print_preview_css() {
		if ( ! $this->is_preview() ) {
			return;
		}

		echo '<style id="wdem-live-css">' . $this->get_css() . '</style>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The theme's own CSS.
	}

	/**
	 * Answer a stylesheet request of the preview: the settings CSS of this page, nothing else.
	 *
	 * Built on the front end, for the page being previewed, so per-page overrides apply exactly as
	 * they do when the page renders.
	 *
	 * @return void
	 */
	public function output_preview_css() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The preview nonce is verified.
		if ( empty( $_GET[ self::CSS_VAR ] ) || ! $this->is_preview() ) {
			return;
		}

		nocache_headers();
		header( 'Content-Type: text/css; charset=' . get_option( 'blog_charset' ) );

		echo $this->get_css(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- The theme's own CSS.

		exit;
	}

	/**
	 * Build the theme settings CSS the way the theme does when its cache is empty.
	 *
	 * The theme keeps that builder private, and it reloads the options and resets the globals as it
	 * goes. Both are put back afterwards, so the page renders with the state it had.
	 *
	 * @return string
	 */
	private function get_css() {
		if ( ! class_exists( '\XTS\Registry' ) || ! class_exists( '\XTS\Admin\Modules\Options\Presets' ) ) {
			return '';
		}

		$builder = \XTS\Registry::get_instance()->themesettingscss;

		if ( ! is_object( $builder ) || ! method_exists( $builder, 'get_all_css' ) ) {
			return '';
		}

		$options = \XTS\Admin\Modules\Options::get_instance();
		$state   = $this->snapshot( $builder, $options );

		$presets = \XTS\Admin\Modules\Options\Presets::get_active_presets();
		array_unshift( $presets, 'default' );

		$css    = '';
		$method = new ReflectionMethod( $builder, 'get_all_css' );
		$method->setAccessible( true );

		foreach ( $presets as $preset ) {
			$css .= (string) $method->invoke( $builder, $preset );
		}

		$this->restore( $state );

		return $css;
	}

	/**
	 * Remember what building the CSS is about to overwrite.
	 *
	 * @param object $builder Theme settings CSS builder.
	 * @param object $options Options instance.
	 *
	 * @return array
	 */
	private function snapshot( $builder, $options ) {
		$state = array(
			'globals' => array(),
			'props'   => array(),
		);

		foreach ( array( 'xts_woodmart_options', 'woodmart_options' ) as $name ) {
			$state['globals'][ $name ] = isset( $GLOBALS[ $name ] ) ? $GLOBALS[ $name ] : null;
		}

		$props = array(
			array( $options, '_options' ),
			array( $options, '_presets' ),
			array( $builder, 'is_preset_active' ),
		);

		foreach ( $props as $prop ) {
			if ( ! property_exists( $prop[0], $prop[1] ) ) {
				continue;
			}

			$reflection = new ReflectionProperty( $prop[0], $prop[1] );
			$reflection->setAccessible( true );

			$state['props'][] = array( $reflection, $reflection->isStatic() ? null : $prop[0], $reflection->getValue( $reflection->isStatic() ? null : $prop[0] ) );
		}

		return $state;
	}

	/**
	 * Put back what building the CSS overwrote.
	 *
	 * @param array $state Snapshot.
	 *
	 * @return void
	 */
	private function restore( $state ) {
		foreach ( $state['globals'] as $name => $value ) {
			$GLOBALS[ $name ] = $value; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		}

		foreach ( $state['props'] as $prop ) {
			if ( null === $prop[1] ) {
				$prop[0]->setValue( null, $prop[2] );
			} else {
				$prop[0]->setValue( $prop[1], $prop[2] );
			}
		}
	}
}

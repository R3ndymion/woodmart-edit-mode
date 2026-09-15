<?php
/**
 * Plugin Name: WoodMart Edit Mode
 * Plugin URI:  https://github.com/R3ndymion/woodmart-edit-mode
 * Description: Adds an "Edit mode" switch to the admin bar. With it on, hovering the front-end highlights the HTML blocks, layouts, menus, header and slides behind what you see and offers a button straight to their editor.
 * Version:     1.0.3
 * Author:      R3ndymion
 * Author URI:  https://github.com/R3ndymion
 * License:     GPL-2.0-or-later
 * Text Domain: woodmart-edit-mode
 * Requires PHP: 7.4
 *
 * @package woodmart-edit-mode
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direct access not allowed.
}

define( 'WDEM_VERSION', '1.0.3' );
define( 'WDEM_FILE', __FILE__ );
define( 'WDEM_PATH', plugin_dir_path( __FILE__ ) );
define( 'WDEM_URL', plugin_dir_url( __FILE__ ) );

require_once WDEM_PATH . 'includes/class-plugin.php';

add_action( 'after_setup_theme', array( 'WDEM_Plugin', 'boot' ), 20 );

/*
 * WoodMart guards this function with function_exists(), and plugins load before the theme, so
 * defining it here replaces the theme's copy. It is the only seam that reaches every HTML block
 * without touching the theme itself. Keep the body in sync with the theme's original: the only
 * addition is the wrap_block() call.
 */
if ( ! function_exists( 'woodmart_get_html_block' ) ) {
	/**
	 * Get HTML block content.
	 *
	 * @param int     $id         Block ID.
	 * @param boolean $inline_css Inline CSS.
	 *
	 * @return string
	 */
	function woodmart_get_html_block( $id, $inline_css = false ) {
		$id   = apply_filters( 'wpml_object_id', $id, 'cms_block', true );
		$post = get_post( $id );

		if ( ! $post || 'cms_block' !== $post->post_type || ! $id || ! function_exists( 'woodmart_get_post_content' ) ) {
			return '';
		}

		return WDEM_Plugin::instance()->wrap_block( woodmart_get_post_content( $id, $inline_css ), $id );
	}
}

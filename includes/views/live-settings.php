<?php
/**
 * The live settings editor document.
 *
 * @package woodmart-edit-mode
 *
 * @var array $config Script configuration, see WDEM_Live_Settings::render_editor().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Direct access not allowed.
}
?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php esc_html_e( 'Live settings', 'woodmart-edit-mode' ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( includes_url( 'css/dashicons.min.css' ) ); ?>">
	<link rel="stylesheet" href="<?php echo esc_url( WDEM_URL . 'assets/live-settings.css?ver=' . WDEM_Live_Settings::asset_version( 'assets/live-settings.css' ) ); ?>">
</head>
<body class="wdem-live">
	<div class="wdem-live-bar">
		<a class="wdem-live-close" href="<?php echo esc_url( $config['closeUrl'] ); ?>" title="<?php esc_attr_e( 'Close', 'woodmart-edit-mode' ); ?>" aria-label="<?php esc_attr_e( 'Close', 'woodmart-edit-mode' ); ?>"><span class="dashicons dashicons-no-alt"></span></a>

		<div class="wdem-live-title">
			<?php esc_html_e( 'Theme settings', 'woodmart-edit-mode' ); ?>
			<span class="wdem-live-state" data-saved="<?php esc_attr_e( 'Saved', 'woodmart-edit-mode' ); ?>" data-unsaved="<?php esc_attr_e( 'Unsaved changes', 'woodmart-edit-mode' ); ?>" data-saving="<?php esc_attr_e( 'Saving…', 'woodmart-edit-mode' ); ?>" data-updating="<?php esc_attr_e( 'Updating preview…', 'woodmart-edit-mode' ); ?>"><?php esc_html_e( 'Saved', 'woodmart-edit-mode' ); ?></span>
		</div>

		<div class="wdem-live-devices" role="group" aria-label="<?php esc_attr_e( 'Preview size', 'woodmart-edit-mode' ); ?>">
			<button type="button" class="wdem-live-device wdem-active" data-device="desktop" title="<?php esc_attr_e( 'Desktop', 'woodmart-edit-mode' ); ?>"><span class="dashicons dashicons-desktop"></span></button>
			<button type="button" class="wdem-live-device" data-device="tablet" title="<?php esc_attr_e( 'Tablet', 'woodmart-edit-mode' ); ?>"><span class="dashicons dashicons-tablet"></span></button>
			<button type="button" class="wdem-live-device" data-device="mobile" title="<?php esc_attr_e( 'Mobile', 'woodmart-edit-mode' ); ?>"><span class="dashicons dashicons-smartphone"></span></button>
		</div>

		<div class="wdem-live-actions">
			<button type="button" class="wdem-live-btn wdem-live-discard" disabled><?php esc_html_e( 'Discard', 'woodmart-edit-mode' ); ?></button>
			<button type="button" class="wdem-live-btn wdem-live-save wdem-primary" disabled><?php esc_html_e( 'Save', 'woodmart-edit-mode' ); ?></button>
		</div>
	</div>

	<div class="wdem-live-body">
		<div class="wdem-live-panel">
			<iframe class="wdem-live-settings" title="<?php esc_attr_e( 'Theme settings', 'woodmart-edit-mode' ); ?>" src="<?php echo esc_url( $config['settingsUrl'] ); ?>"></iframe>
		</div>
		<div class="wdem-live-resizer" role="separator" aria-orientation="vertical"></div>
		<div class="wdem-live-stage" data-device="desktop">
			<iframe class="wdem-live-preview" title="<?php esc_attr_e( 'Preview', 'woodmart-edit-mode' ); ?>" src="<?php echo esc_url( $config['previewUrl'] ); ?>"></iframe>
			<div class="wdem-live-loading"></div>
		</div>
	</div>

	<script>window.wdemLive = <?php echo wp_json_encode( $config ); ?>;</script>
	<script src="<?php echo esc_url( WDEM_URL . 'assets/live-settings.js?ver=' . WDEM_Live_Settings::asset_version( 'assets/live-settings.js' ) ); ?>"></script>
</body>
</html>

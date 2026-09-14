<?php
// Loads the plugin outside WordPress with minimal stubs, to catch fatals before shipping.
// Run with: php tests/load.php
define( 'ABSPATH', __DIR__ );
function plugin_dir_path( $file ) { return dirname( $file ) . '/'; }
function plugin_dir_url( $file ) { return 'http://example.test/plugin/'; }
function add_action( ...$args ) {}
function apply_filters( $hook, $value ) { return $value; }

require dirname( __DIR__ ) . '/woodmart-edit-mode.php';

$checks = array(
	'main file defines the block override' => function_exists( 'woodmart_get_html_block' ),
	'plugin class loads'                   => class_exists( 'WDEM_Plugin' ),
	'singleton works'                      => WDEM_Plugin::instance() instanceof WDEM_Plugin,
	'wrap_block is callable'               => is_callable( array( WDEM_Plugin::instance(), 'wrap_block' ) ),
	'boot is callable'                     => is_callable( array( 'WDEM_Plugin', 'boot' ) ),
);

$failed = false;

foreach ( $checks as $name => $ok ) {
	echo ( $ok ? 'PASS  ' : 'FAIL  ' ) . $name . PHP_EOL;
	$failed = $failed || ! $ok;
}

exit( $failed ? 1 : 0 );

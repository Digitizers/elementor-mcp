<?php
// Read-only: every registered widget's control names, types, option keys and defaults.
$out = array( 'elementor' => defined( 'ELEMENTOR_VERSION' ) ? ELEMENTOR_VERSION : null, 'pro' => defined( 'ELEMENTOR_PRO_VERSION' ) ? ELEMENTOR_PRO_VERSION : null, 'widgets' => array() );
if ( ! class_exists( '\Elementor\Plugin' ) ) { echo json_encode( array( 'error' => 'no elementor' ) ); return; }
if ( class_exists( '\Elementor\Core\Frontend\Performance' ) && method_exists( '\Elementor\Core\Frontend\Performance', 'set_use_style_controls' ) ) { \Elementor\Core\Frontend\Performance::set_use_style_controls( true ); }
foreach ( \Elementor\Plugin::$instance->widgets_manager->get_widget_types() as $type => $widget ) {
	try {
		$controls = $widget->get_controls();
	} catch ( \Throwable $e ) {
		$out['widgets'][ $type ] = array( 'error' => get_class( $e ) );
		continue;
	}
	$map = array();
	foreach ( (array) $controls as $name => $c ) {
		$row = array( 't' => isset( $c['type'] ) ? $c['type'] : null );
		if ( isset( $c['options'] ) && is_array( $c['options'] ) ) { $row['o'] = array_map( 'strval', array_keys( $c['options'] ) ); }
		if ( array_key_exists( 'default', $c ) && is_scalar( $c['default'] ) ) { $row['d'] = $c['default']; }
		if ( isset( $c['return_value'] ) && is_scalar( $c['return_value'] ) ) { $row['r'] = $c['return_value']; }
		$map[ $name ] = $row;
	}
	$out['widgets'][ $type ] = $map;
}
echo json_encode( $out );

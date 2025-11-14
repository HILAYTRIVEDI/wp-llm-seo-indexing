<?php
/**
 * Chart component.
 *
 * Renders Chart.js canvas elements with proper accessibility.
 *
 * @package WPLLMSEO
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render a chart canvas.
 *
 * @param array $args {
 *     Chart arguments.
 *
 *     @type string $id     Canvas ID (required).
 *     @type string $title  Chart title for accessibility (required).
 *     @type string $type   Chart type (line, bar, pie, doughnut).
 *     @type int    $height Canvas height in pixels.
 *     @type array  $data   Chart data to be passed to JavaScript.
 * }
 */
function wpllmseo_render_chart( $args = array() ) {
	$defaults = array(
		'id'     => 'wpllmseo-chart-' . wp_rand( 1000, 9999 ),
		'title'  => __( 'Chart', 'wpllmseo' ),
		'type'   => 'line',
		'height' => 300,
		'data'   => array(),
	);

	$args = wp_parse_args( $args, $defaults );

	if ( empty( $args['id'] ) || empty( $args['title'] ) ) {
		return;
	}

	// Encode data for JavaScript.
	$chart_data = ! empty( $args['data'] ) ? wp_json_encode( $args['data'] ) : '{}';
	?>
	<div class="wpllmseo-chart-container">
		<canvas id="<?php echo esc_attr( $args['id'] ); ?>" 
		        data-type="<?php echo esc_attr( $args['type'] ); ?>"
		        data-chart="<?php echo esc_attr( $chart_data ); ?>"
		        height="<?php echo esc_attr( $args['height'] ); ?>"
		        role="img"
		        aria-label="<?php echo esc_attr( $args['title'] ); ?>">
			<p><?php echo esc_html( sprintf( __( 'Your browser does not support canvas. Chart data for %s is available below.', 'wpllmseo' ), $args['title'] ) ); ?></p>
		</canvas>
	</div>
	<?php
}

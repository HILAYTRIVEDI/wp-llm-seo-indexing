<?php
/**
 * Card component - Material UI Design.
 *
 * Renders stat cards using Material Design principles.
 *
 * @package WPLLMSEO
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render a stat card using Material UI design.
 *
 * @param array $args {
 *     Card arguments.
 *
 *     @type string $title       Card title (required).
 *     @type mixed  $value       Main value to display (required).
 *     @type string $icon        Dashicon name (optional).
 *     @type string $color       Color variant: primary, success, warning, error, info, secondary.
 *     @type string $trend       Trend text (e.g., "+12% from last week").
 *     @type string $chart_id    Canvas ID for sparkline chart (optional).
 * }
 */
function wpllmseo_render_card( $args = array() ) {
	$defaults = array(
		'title'    => '',
		'value'    => 0,
		'icon'     => '',
		'color'    => 'primary',
		'trend'    => '',
		'chart_id' => '',
	);

	$args = wp_parse_args( $args, $defaults );

	if ( empty( $args['title'] ) ) {
		return;
	}
	
	$card_class = 'wpllmseo-card';
	if ( ! empty( $args['color'] ) && $args['color'] !== 'default' ) {
		$card_class .= ' wpllmseo-card-' . esc_attr( $args['color'] );
	}
	?>
	<div class="<?php echo esc_attr( $card_class ); ?>">
		<div class="wpllmseo-stat-content">
			<?php if ( ! empty( $args['icon'] ) ) : ?>
				<div class="wpllmseo-stat-icon">
					<span class="dashicons dashicons-<?php echo esc_attr( $args['icon'] ); ?>"></span>
				</div>
			<?php endif; ?>
			<div class="wpllmseo-stat-details">
				<div class="wpllmseo-stat-label"><?php echo esc_html( $args['title'] ); ?></div>
				<div class="wpllmseo-stat-value"><?php echo esc_html( $args['value'] ); ?></div>
				<?php if ( ! empty( $args['trend'] ) ) : ?>
					<div class="wpllmseo-stat-trend"><?php echo wp_kses_post( $args['trend'] ); ?></div>
				<?php endif; ?>
			</div>
		</div>
		<?php if ( ! empty( $args['chart_id'] ) ) : ?>
			<div class="wpllmseo-card-body">
				<div class="wpllmseo-chart-container">
					<canvas id="<?php echo esc_attr( $args['chart_id'] ); ?>" 
					        aria-label="<?php echo esc_attr( sprintf( __( 'Chart for %s', 'wpllmseo' ), $args['title'] ) ); ?>"
					        role="img"></canvas>
				</div>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Render a content card using WordPress postbox markup.
 *
 * @param array $args {
 *     Card arguments.
 *
 *     @type string $title   Card title (required).
 *     @type string $content Card content HTML (required).
 *     @type array  $footer  Optional footer with buttons.
 * }
 */
function wpllmseo_render_content_card( $args = array() ) {
	$defaults = array(
		'title'   => '',
		'content' => '',
		'footer'  => array(),
	);

	$args = wp_parse_args( $args, $defaults );

	if ( empty( $args['title'] ) ) {
		return;
	}
	?>
	<div class="postbox">
		<div class="postbox-header">
			<h2 class="hndle"><?php echo esc_html( $args['title'] ); ?></h2>
		</div>
		<div class="inside">
			<?php echo wp_kses_post( $args['content'] ); ?>
		</div>
		<?php if ( ! empty( $args['footer'] ) ) : ?>
			<div class="postbox-footer">
				<?php foreach ( $args['footer'] as $button ) : ?>
					<a href="<?php echo esc_url( $button['url'] ?? '#' ); ?>" 
					   class="button <?php echo esc_attr( $button['class'] ?? 'button-secondary' ); ?>">
						<?php echo esc_html( $button['label'] ); ?>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
	<?php
}

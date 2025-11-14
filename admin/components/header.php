<?php
/**
 * Header component.
 *
 * Renders page headers using native WordPress .wrap structure.
 *
 * @package WPLLMSEO
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render page header using WordPress native markup.
 *
 * @param array $args {
 *     Header arguments.
 *
 *     @type string $title       Page title (required).
 *     @type string $description Optional page description.
 *     @type array  $actions     Optional array of action buttons.
 * }
 */
function wpllmseo_render_header( $args = array() ) {
	$defaults = array(
		'title'       => '',
		'description' => '',
		'actions'     => array(),
	);

	$args = wp_parse_args( $args, $defaults );

	if ( empty( $args['title'] ) ) {
		return;
	}
	?>
	<h1><?php echo esc_html( $args['title'] ); ?>
		<?php if ( ! empty( $args['actions'] ) ) : ?>
			<?php foreach ( $args['actions'] as $action ) : ?>
				<a href="<?php echo esc_url( $action['url'] ); ?>" 
				   class="<?php echo esc_attr( $action['class'] ?? 'page-title-action' ); ?>"
				   <?php echo isset( $action['target'] ) ? 'target="' . esc_attr( $action['target'] ) . '"' : ''; ?>>
					<?php if ( isset( $action['icon'] ) ) : ?>
						<span class="dashicons dashicons-<?php echo esc_attr( $action['icon'] ); ?>"></span>
					<?php endif; ?>
					<?php echo esc_html( $action['label'] ); ?>
				</a>
			<?php endforeach; ?>
		<?php endif; ?>
	</h1>
	<?php if ( ! empty( $args['description'] ) ) : ?>
		<p><?php echo esc_html( $args['description'] ); ?></p>
	<?php endif; ?>
	<hr class="wp-header-end">
	<?php
}

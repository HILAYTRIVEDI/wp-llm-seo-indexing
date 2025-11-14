<?php
/**
 * Table component.
 *
 * Renders data tables with sorting and pagination support.
 *
 * @package WPLLMSEO
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Render a data table.
 *
 * @param array $args {
 *     Table arguments.
 *
 *     @type array  $columns  Column definitions (required). Each column: ['key' => string, 'label' => string, 'sortable' => bool].
 *     @type array  $rows     Table data rows (required).
 *     @type string $id       Table ID for JavaScript reference.
 *     @type bool   $sortable Enable sorting (default true).
 *     @type array  $actions  Row action callbacks.
 * }
 */
function wpllmseo_render_table( $args = array() ) {
	$defaults = array(
		'columns'  => array(),
		'rows'     => array(),
		'id'       => 'wpllmseo-table-' . wp_rand( 1000, 9999 ),
		'sortable' => true,
		'actions'  => array(),
	);

	$args = wp_parse_args( $args, $defaults );

	if ( empty( $args['columns'] ) ) {
		return;
	}
	?>
	<div class="wpllmseo-table-wrapper">
		<table class="wpllmseo-table widefat striped" id="<?php echo esc_attr( $args['id'] ); ?>">
			<thead>
				<tr>
					<?php foreach ( $args['columns'] as $column ) : ?>
						<th class="<?php echo ! empty( $column['sortable'] ) ? 'sortable' : ''; ?>" 
						    data-column="<?php echo esc_attr( $column['key'] ); ?>"
						    scope="col">
							<?php echo esc_html( $column['label'] ); ?>
							<?php if ( ! empty( $column['sortable'] ) ) : ?>
								<span class="sorting-indicator"></span>
							<?php endif; ?>
						</th>
					<?php endforeach; ?>
					<?php if ( ! empty( $args['actions'] ) ) : ?>
						<th scope="col"><?php esc_html_e( 'Actions', 'wpllmseo' ); ?></th>
					<?php endif; ?>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $args['rows'] ) ) : ?>
					<tr>
						<td colspan="<?php echo esc_attr( count( $args['columns'] ) + ( ! empty( $args['actions'] ) ? 1 : 0 ) ); ?>" class="no-items">
							<?php esc_html_e( 'No items found.', 'wpllmseo' ); ?>
						</td>
					</tr>
				<?php else : ?>
					<?php foreach ( $args['rows'] as $row ) : ?>
						<tr>
							<?php foreach ( $args['columns'] as $column ) : ?>
								<td data-column="<?php echo esc_attr( $column['key'] ); ?>">
									<?php
									$value = isset( $row[ $column['key'] ] ) ? $row[ $column['key'] ] : '';
									
									// Check if column has a custom formatter.
									if ( isset( $column['format'] ) && is_callable( $column['format'] ) ) {
										echo wp_kses_post( call_user_func( $column['format'], $value, $row ) );
									} else {
										echo esc_html( $value );
									}
									?>
								</td>
							<?php endforeach; ?>
							<?php if ( ! empty( $args['actions'] ) ) : ?>
								<td class="row-actions">
									<?php
									foreach ( $args['actions'] as $action_key => $action ) :
										$action_url = is_callable( $action['url'] ) 
											? call_user_func( $action['url'], $row ) 
											: $action['url'];
										?>
										<a href="<?php echo esc_url( $action_url ); ?>" 
										   class="<?php echo esc_attr( $action['class'] ?? '' ); ?>"
										   <?php echo isset( $action['data'] ) ? 'data-action="' . esc_attr( $action_key ) . '"' : ''; ?>>
											<?php echo esc_html( $action['label'] ); ?>
										</a>
										<?php
										if ( $action_key !== array_key_last( $args['actions'] ) ) {
											echo ' | ';
										}
										?>
									<?php endforeach; ?>
								</td>
							<?php endif; ?>
						</tr>
					<?php endforeach; ?>
				<?php endif; ?>
			</tbody>
		</table>
	</div>
	<?php
}

<?php
/**
 * MCP Audit Logs Tab
 *
 * @package WPLLMSEO
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Get filter parameters.
$filter_ability = isset( $_GET['filter_ability'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_ability'] ) ) : '';
$filter_status  = isset( $_GET['filter_status'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_status'] ) ) : '';
$filter_user    = isset( $_GET['filter_user'] ) ? absint( $_GET['filter_user'] ) : 0;
$page           = isset( $_GET['paged'] ) ? absint( $_GET['paged'] ) : 1;
$per_page       = 20;

// Build filter args.
$filter_args = array();
if ( $filter_ability ) {
	$filter_args['ability_name'] = $filter_ability;
}
if ( $filter_status ) {
	$filter_args['status'] = $filter_status;
}
if ( $filter_user ) {
	$filter_args['user_id'] = $filter_user;
}

// Get logs.
$logs       = WPLLMSEO_MCP_Audit::get_logs( $filter_args, $per_page, ( $page - 1 ) * $per_page );
$total_logs = count( WPLLMSEO_MCP_Audit::get_logs( $filter_args ) );
$total_pages = ceil( $total_logs / $per_page );

// Get stats for different periods.
$stats_hour  = WPLLMSEO_MCP_Audit::get_stats( 'hour' );
$stats_day   = WPLLMSEO_MCP_Audit::get_stats( 'day' );
$stats_week  = WPLLMSEO_MCP_Audit::get_stats( 'week' );
$stats_month = WPLLMSEO_MCP_Audit::get_stats( 'month' );

// Get all abilities for filter dropdown.
global $wpdb;
$table       = $wpdb->prefix . 'wpllmseo_mcp_logs';
$abilities   = $wpdb->get_col( "SELECT DISTINCT ability_name FROM $table ORDER BY ability_name" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

// Get all users who have made MCP requests.
$user_ids = $wpdb->get_col( "SELECT DISTINCT user_id FROM $table WHERE user_id > 0 ORDER BY user_id" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$users    = array();
foreach ( $user_ids as $user_id ) {
	$user = get_userdata( $user_id );
	if ( $user ) {
		$users[ $user_id ] = $user;
	}
}
?>

<div class="wpllmseo-mcp-audit">
	<h2><?php esc_html_e( 'Audit Logs', 'wpllmseo' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'View and monitor all MCP ability invocations for security and debugging.', 'wpllmseo' ); ?>
	</p>

	<!-- Stats Grid -->
	<div class="wpllmseo-card">
		<h3><?php esc_html_e( 'Activity Statistics', 'wpllmseo' ); ?></h3>
		
		<table class="wpllmseo-stats-grid" style="margin-bottom: 20px;">
			<tr>
				<td colspan="4"><strong><?php esc_html_e( 'Last Hour', 'wpllmseo' ); ?></strong></td>
			</tr>
			<tr>
				<td>
					<div class="wpllmseo-stat-card">
						<div class="wpllmseo-stat-label"><?php esc_html_e( 'Total Requests', 'wpllmseo' ); ?></div>
						<div class="wpllmseo-stat-value"><?php echo esc_html( $stats_hour['total_requests'] ); ?></div>
					</div>
				</td>
				<td>
					<div class="wpllmseo-stat-card">
						<div class="wpllmseo-stat-label"><?php esc_html_e( 'Success', 'wpllmseo' ); ?></div>
						<div class="wpllmseo-stat-value"><?php echo esc_html( $stats_hour['success_count'] ); ?></div>
					</div>
				</td>
				<td>
					<div class="wpllmseo-stat-card">
						<div class="wpllmseo-stat-label"><?php esc_html_e( 'Errors', 'wpllmseo' ); ?></div>
						<div class="wpllmseo-stat-value"><?php echo esc_html( $stats_hour['error_count'] ); ?></div>
					</div>
				</td>
				<td>
					<div class="wpllmseo-stat-card">
						<div class="wpllmseo-stat-label"><?php esc_html_e( 'Avg Time', 'wpllmseo' ); ?></div>
						<div class="wpllmseo-stat-value"><?php echo esc_html( number_format( $stats_hour['avg_execution_time'], 2 ) ); ?>s</div>
					</div>
				</td>
			</tr>
		</table>

		<table class="wpllmseo-stats-grid">
			<tr>
				<td colspan="4"><strong><?php esc_html_e( 'Last 24 Hours', 'wpllmseo' ); ?></strong></td>
			</tr>
			<tr>
				<td>
					<div class="wpllmseo-stat-card">
						<div class="wpllmseo-stat-label"><?php esc_html_e( 'Total Requests', 'wpllmseo' ); ?></div>
						<div class="wpllmseo-stat-value"><?php echo esc_html( $stats_day['total_requests'] ); ?></div>
					</div>
				</td>
				<td>
					<div class="wpllmseo-stat-card">
						<div class="wpllmseo-stat-label"><?php esc_html_e( 'Unique Callers', 'wpllmseo' ); ?></div>
						<div class="wpllmseo-stat-value"><?php echo esc_html( $stats_day['unique_callers'] ); ?></div>
					</div>
				</td>
				<td>
					<div class="wpllmseo-stat-card">
						<div class="wpllmseo-stat-label"><?php esc_html_e( 'Success Rate', 'wpllmseo' ); ?></div>
						<div class="wpllmseo-stat-value">
							<?php
							$success_rate = $stats_day['total_requests'] > 0 
								? ( $stats_day['success_count'] / $stats_day['total_requests'] ) * 100 
								: 0;
							echo esc_html( number_format( $success_rate, 1 ) . '%' );
							?>
						</div>
					</div>
				</td>
				<td>
					<div class="wpllmseo-stat-card">
						<div class="wpllmseo-stat-label"><?php esc_html_e( 'Most Used', 'wpllmseo' ); ?></div>
						<div class="wpllmseo-stat-value">
							<?php
							if ( ! empty( $stats_day['by_ability'] ) ) {
								$most_used = array_keys( $stats_day['by_ability'] )[0];
								echo '<code>' . esc_html( $most_used ) . '</code>';
							} else {
								echo '<em>' . esc_html__( 'N/A', 'wpllmseo' ) . '</em>';
							}
							?>
						</div>
					</div>
				</td>
			</tr>
		</table>
	</div>

	<!-- Filters -->
	<div class="wpllmseo-card" style="margin-top: 20px;">
		<h3><?php esc_html_e( 'Filter Logs', 'wpllmseo' ); ?></h3>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
			<input type="hidden" name="page" value="wpllmseo_mcp" />
			<input type="hidden" name="tab" value="audit" />
			
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row">
							<label for="filter_ability"><?php esc_html_e( 'Ability', 'wpllmseo' ); ?></label>
						</th>
						<td>
							<select name="filter_ability" id="filter_ability">
								<option value=""><?php esc_html_e( 'All Abilities', 'wpllmseo' ); ?></option>
								<?php foreach ( $abilities as $ability ) : ?>
									<option value="<?php echo esc_attr( $ability ); ?>" <?php selected( $filter_ability, $ability ); ?>>
										<?php echo esc_html( $ability ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
						<th scope="row">
							<label for="filter_status"><?php esc_html_e( 'Status', 'wpllmseo' ); ?></label>
						</th>
						<td>
							<select name="filter_status" id="filter_status">
								<option value=""><?php esc_html_e( 'All Statuses', 'wpllmseo' ); ?></option>
								<option value="success" <?php selected( $filter_status, 'success' ); ?>><?php esc_html_e( 'Success', 'wpllmseo' ); ?></option>
								<option value="error" <?php selected( $filter_status, 'error' ); ?>><?php esc_html_e( 'Error', 'wpllmseo' ); ?></option>
							</select>
						</td>
						<th scope="row">
							<label for="filter_user"><?php esc_html_e( 'User', 'wpllmseo' ); ?></label>
						</th>
						<td>
							<select name="filter_user" id="filter_user">
								<option value="0"><?php esc_html_e( 'All Users', 'wpllmseo' ); ?></option>
								<?php foreach ( $users as $user_id => $user ) : ?>
									<option value="<?php echo esc_attr( $user_id ); ?>" <?php selected( $filter_user, $user_id ); ?>>
										<?php echo esc_html( $user->display_name . ' (' . $user->user_login . ')' ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
				</tbody>
			</table>
			
			<p class="submit">
				<input type="submit" class="button" value="<?php esc_attr_e( 'Apply Filters', 'wpllmseo' ); ?>" />
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wpllmseo_mcp&tab=audit' ) ); ?>" class="button">
					<?php esc_html_e( 'Clear Filters', 'wpllmseo' ); ?>
				</a>
			</p>
		</form>
	</div>

	<!-- Logs Table -->
	<div class="wpllmseo-card" style="margin-top: 20px;">
		<h3><?php esc_html_e( 'Recent Activity', 'wpllmseo' ); ?></h3>
		
		<?php if ( empty( $logs ) ) : ?>
			<p class="description">
				<?php esc_html_e( 'No audit logs found matching your filters.', 'wpllmseo' ); ?>
			</p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th style="width: 15%;"><?php esc_html_e( 'Timestamp', 'wpllmseo' ); ?></th>
						<th style="width: 20%;"><?php esc_html_e( 'Ability', 'wpllmseo' ); ?></th>
						<th style="width: 15%;"><?php esc_html_e( 'User', 'wpllmseo' ); ?></th>
						<th style="width: 10%;"><?php esc_html_e( 'Status', 'wpllmseo' ); ?></th>
						<th style="width: 10%;"><?php esc_html_e( 'Exec Time', 'wpllmseo' ); ?></th>
						<th style="width: 15%;"><?php esc_html_e( 'IP Address', 'wpllmseo' ); ?></th>
						<th><?php esc_html_e( 'Error', 'wpllmseo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $logs as $log ) : ?>
						<?php
						$user = get_userdata( $log->user_id );
						?>
						<tr>
							<td>
								<abbr title="<?php echo esc_attr( $log->timestamp ); ?>">
									<?php echo esc_html( human_time_diff( strtotime( $log->timestamp ), time() ) . ' ago' ); ?>
								</abbr>
							</td>
							<td><code><?php echo esc_html( $log->ability_name ); ?></code></td>
							<td>
								<?php if ( $user ) : ?>
									<?php echo esc_html( $user->display_name ); ?>
									<br />
									<small><?php echo esc_html( $user->user_login ); ?></small>
								<?php else : ?>
									<em><?php esc_html_e( 'Unknown', 'wpllmseo' ); ?></em>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( 'success' === $log->status ) : ?>
									<span class="wpllmseo-badge wpllmseo-badge-success"><?php esc_html_e( 'Success', 'wpllmseo' ); ?></span>
								<?php else : ?>
									<span class="wpllmseo-badge wpllmseo-badge-error"><?php esc_html_e( 'Error', 'wpllmseo' ); ?></span>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( number_format( $log->execution_time, 3 ) ); ?>s</td>
							<td><code><?php echo esc_html( $log->ip_address ); ?></code></td>
							<td>
								<?php if ( $log->error_message ) : ?>
									<details>
										<summary><?php esc_html_e( 'View Error', 'wpllmseo' ); ?></summary>
										<pre style="margin-top: 10px; background: #f5f5f5; padding: 10px; overflow-x: auto;"><?php echo esc_html( $log->error_message ); ?></pre>
									</details>
								<?php else : ?>
									<em><?php esc_html_e( 'None', 'wpllmseo' ); ?></em>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<!-- Pagination -->
			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav" style="margin-top: 20px;">
					<div class="tablenav-pages">
						<span class="displaying-num">
							<?php
							printf(
								/* translators: %d: total number of logs */
								esc_html( _n( '%d log', '%d logs', $total_logs, 'wpllmseo' ) ),
								esc_html( number_format_i18n( $total_logs ) )
							);
							?>
						</span>
						<?php
						$base_url = add_query_arg(
							array(
								'page'           => 'wpllmseo_mcp',
								'tab'            => 'audit',
								'filter_ability' => $filter_ability,
								'filter_status'  => $filter_status,
								'filter_user'    => $filter_user,
							),
							admin_url( 'admin.php' )
						);

						echo wp_kses_post(
							paginate_links(
								array(
									'base'    => add_query_arg( 'paged', '%#%', $base_url ),
									'format'  => '',
									'current' => $page,
									'total'   => $total_pages,
								)
							)
						);
						?>
					</div>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</div>

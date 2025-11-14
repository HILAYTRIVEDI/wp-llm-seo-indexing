<?php
/**
 * MCP Tokens Management Tab
 *
 * @package WPLLMSEO
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Get current user's tokens.
$current_user = wp_get_current_user();
$user_tokens  = WPLLMSEO_MCP_Auth::get_user_tokens( $current_user->ID );
?>

<div class="wpllmseo-mcp-tokens">
	<h2><?php esc_html_e( 'MCP Access Tokens', 'wpllmseo' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Generate and manage API tokens for MCP authentication. Tokens inherit your WordPress user capabilities.', 'wpllmseo' ); ?>
	</p>

	<!-- Generate Token Form -->
	<div class="wpllmseo-card">
		<h3><?php esc_html_e( 'Generate New Token', 'wpllmseo' ); ?></h3>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=wpllmseo_mcp&tab=tokens' ) ); ?>">
			<?php wp_nonce_field( 'wpllmseo_mcp_action', 'wpllmseo_mcp_nonce' ); ?>
			<input type="hidden" name="action" value="generate_token" />
			
			<table class="form-table">
				<tbody>
					<tr>
						<th scope="row">
							<label for="token_name"><?php esc_html_e( 'Token Name', 'wpllmseo' ); ?></label>
						</th>
						<td>
							<input type="text" 
							       name="token_name" 
							       id="token_name" 
							       class="regular-text" 
							       required 
							       placeholder="<?php esc_attr_e( 'e.g., GitHub Actions, Zapier', 'wpllmseo' ); ?>" />
							<p class="description">
								<?php esc_html_e( 'A descriptive name to identify where this token is used', 'wpllmseo' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="token_capabilities"><?php esc_html_e( 'Capabilities', 'wpllmseo' ); ?></label>
						</th>
						<td>
							<label>
								<input type="checkbox" name="token_capabilities[]" value="read" checked disabled />
								<?php esc_html_e( 'Read (required)', 'wpllmseo' ); ?>
							</label><br />
							<label>
								<input type="checkbox" name="token_capabilities[]" value="write" checked />
								<?php esc_html_e( 'Write', 'wpllmseo' ); ?>
							</label><br />
							<label>
								<input type="checkbox" name="token_capabilities[]" value="bulk_operations" checked />
								<?php esc_html_e( 'Bulk Operations', 'wpllmseo' ); ?>
							</label>
							<p class="description">
								<?php esc_html_e( 'Select which MCP abilities this token can access', 'wpllmseo' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="token_expires"><?php esc_html_e( 'Expiration', 'wpllmseo' ); ?></label>
						</th>
						<td>
							<select name="token_expires" id="token_expires">
								<option value="0"><?php esc_html_e( 'Never', 'wpllmseo' ); ?></option>
								<option value="7"><?php esc_html_e( '7 days', 'wpllmseo' ); ?></option>
								<option value="30" selected><?php esc_html_e( '30 days', 'wpllmseo' ); ?></option>
								<option value="90"><?php esc_html_e( '90 days', 'wpllmseo' ); ?></option>
								<option value="365"><?php esc_html_e( '1 year', 'wpllmseo' ); ?></option>
							</select>
							<p class="description">
								<?php esc_html_e( 'When this token should automatically expire', 'wpllmseo' ); ?>
							</p>
						</td>
					</tr>
				</tbody>
			</table>
			
			<p class="submit">
				<input type="submit" 
				       name="submit" 
				       class="button button-primary" 
				       value="<?php esc_attr_e( 'Generate Token', 'wpllmseo' ); ?>" />
			</p>
		</form>
	</div>

	<!-- Active Tokens List -->
	<div class="wpllmseo-card" style="margin-top: 20px;">
		<h3><?php esc_html_e( 'Your Active Tokens', 'wpllmseo' ); ?></h3>
		
		<?php if ( empty( $user_tokens ) ) : ?>
			<p class="description">
				<?php esc_html_e( 'You haven\'t generated any tokens yet.', 'wpllmseo' ); ?>
			</p>
		<?php else : ?>
			<table class="wp-list-table widefat fixed striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Name', 'wpllmseo' ); ?></th>
						<th><?php esc_html_e( 'Token', 'wpllmseo' ); ?></th>
						<th><?php esc_html_e( 'Capabilities', 'wpllmseo' ); ?></th>
						<th><?php esc_html_e( 'Created', 'wpllmseo' ); ?></th>
						<th><?php esc_html_e( 'Last Used', 'wpllmseo' ); ?></th>
						<th><?php esc_html_e( 'Expires', 'wpllmseo' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wpllmseo' ); ?></th>
						<th><?php esc_html_e( 'Actions', 'wpllmseo' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $user_tokens as $token ) : ?>
						<?php
						$capabilities = json_decode( $token->capabilities, true );
						$is_expired   = ! empty( $token->expires_at ) && strtotime( $token->expires_at ) < time();
						$is_revoked   = (bool) $token->revoked;
						?>
						<tr>
							<td><strong><?php echo esc_html( $token->name ); ?></strong></td>
							<td>
								<code class="wpllmseo-masked-token">
									<?php
									echo esc_html( substr( $token->token, 0, 8 ) );
									echo '...';
									echo esc_html( substr( $token->token, -8 ) );
									?>
								</code>
							</td>
							<td>
								<?php
								if ( ! empty( $capabilities ) ) {
									echo esc_html( implode( ', ', $capabilities ) );
								} else {
									echo '<em>' . esc_html__( 'None', 'wpllmseo' ) . '</em>';
								}
								?>
							</td>
							<td><?php echo esc_html( human_time_diff( strtotime( $token->created_at ), time() ) . ' ago' ); ?></td>
							<td>
								<?php
								if ( $token->last_used ) {
									echo esc_html( human_time_diff( strtotime( $token->last_used ), time() ) . ' ago' );
								} else {
									echo '<em>' . esc_html__( 'Never', 'wpllmseo' ) . '</em>';
								}
								?>
							</td>
							<td>
								<?php
								if ( $token->expires_at ) {
									echo esc_html( human_time_diff( strtotime( $token->expires_at ), time() ) );
								} else {
									echo '<em>' . esc_html__( 'Never', 'wpllmseo' ) . '</em>';
								}
								?>
							</td>
							<td>
								<?php if ( $is_revoked ) : ?>
									<span class="wpllmseo-badge wpllmseo-badge-error"><?php esc_html_e( 'Revoked', 'wpllmseo' ); ?></span>
								<?php elseif ( $is_expired ) : ?>
									<span class="wpllmseo-badge wpllmseo-badge-warning"><?php esc_html_e( 'Expired', 'wpllmseo' ); ?></span>
								<?php else : ?>
									<span class="wpllmseo-badge wpllmseo-badge-success"><?php esc_html_e( 'Active', 'wpllmseo' ); ?></span>
								<?php endif; ?>
							</td>
							<td>
								<?php if ( ! $is_revoked ) : ?>
									<form method="post" style="display: inline;" onsubmit="return confirm('<?php esc_attr_e( 'Are you sure you want to revoke this token? This action cannot be undone.', 'wpllmseo' ); ?>');">
										<?php wp_nonce_field( 'wpllmseo_mcp_action', 'wpllmseo_mcp_nonce' ); ?>
										<input type="hidden" name="action" value="revoke_token" />
										<input type="hidden" name="token_id" value="<?php echo esc_attr( $token->id ); ?>" />
										<button type="submit" class="button button-small button-link-delete">
											<?php esc_html_e( 'Revoke', 'wpllmseo' ); ?>
										</button>
									</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</div>
</div>

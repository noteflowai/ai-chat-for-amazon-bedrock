<?php
/*
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 *
 * This view is included from inside a class method, so the variables below are method
 * scope rather than globals. The sniff analyses the file on its own and cannot see the
 * include site.
 */
/** MCP settings view. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	return;
}
$mcp_client = class_exists( 'AI_Chat_Bedrock_MCP_Client' ) ? new AI_Chat_Bedrock_MCP_Client() : null;
?>
<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
	<h2 class="nav-tab-wrapper aicfab-mcp-nav" role="tablist">
		<a href="#aicfab-mcp-servers" class="nav-tab nav-tab-active" data-aicfab-section="aicfab-mcp-servers"><?php esc_html_e( 'Servers', 'ai-chat-for-amazon-bedrock' ); ?></a>
		<a href="#aicfab-mcp-clients" class="nav-tab" data-aicfab-section="aicfab-mcp-clients"><?php esc_html_e( 'AI clients', 'ai-chat-for-amazon-bedrock' ); ?></a>
		<a href="#aicfab-mcp-policy" class="nav-tab" data-aicfab-section="aicfab-mcp-policy"><?php esc_html_e( 'Tool policy', 'ai-chat-for-amazon-bedrock' ); ?></a>
		<a href="#aicfab-mcp-log" class="nav-tab" data-aicfab-section="aicfab-mcp-log"><?php esc_html_e( 'Activity', 'ai-chat-for-amazon-bedrock' ); ?></a>
	</h2>
	<div class="ai-chat-bedrock-mcp-settings">
		<div class="aicfab-mcp-section" id="aicfab-mcp-servers">
		<h2><?php esc_html_e( 'Model Context Protocol (MCP)', 'ai-chat-for-amazon-bedrock' ); ?></h2>
		<p class="description"><?php esc_html_e( 'External tools can expose data and perform remote actions. Register only servers you operate or trust. Outbound servers must use a public HTTPS URL.', 'ai-chat-for-amazon-bedrock' ); ?></p>
		<table class="form-table" role="presentation">
			<tr>
				<th scope="row"><label for="ai_chat_bedrock_enable_mcp"><?php esc_html_e( 'Enable MCP tools', 'ai-chat-for-amazon-bedrock' ); ?></label></th>
				<td><input type="checkbox" id="ai_chat_bedrock_enable_mcp" value="1" <?php checked( get_option( 'ai_chat_bedrock_enable_mcp', false ) ); ?>></td>
			</tr>
			<tr>
				<th scope="row"><label for="ai_chat_bedrock_mcp_public_access"><?php esc_html_e( 'Public WordPress MCP REST access', 'ai-chat-for-amazon-bedrock' ); ?></label></th>
				<td>
					<input type="checkbox" id="ai_chat_bedrock_mcp_public_access" value="1" <?php checked( get_option( 'ai_chat_bedrock_mcp_public_access', false ) ); ?>>
					<p class="description"><?php esc_html_e( 'Disabled by default. When disabled, WordPress authentication is required. Public access exposes read-only published content and is rate limited.', 'ai-chat-for-amazon-bedrock' ); ?></p>
				</td>
			</tr>
		</table>

		<div id="ai-chat-bedrock-mcp-servers-section" class="<?php echo esc_attr( get_option( 'ai_chat_bedrock_enable_mcp', false ) ? '' : 'hidden' ); ?>">
			<h3><?php esc_html_e( 'External MCP servers', 'ai-chat-for-amazon-bedrock' ); ?></h3>
			<div class="ai-chat-bedrock-mcp-add-server">
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="ai_chat_bedrock_mcp_server_name"><?php esc_html_e( 'Server name', 'ai-chat-for-amazon-bedrock' ); ?></label></th><td><input type="text" id="ai_chat_bedrock_mcp_server_name" class="regular-text" maxlength="64" placeholder="my-server"></td></tr>
					<tr><th scope="row"><label for="ai_chat_bedrock_mcp_server_url"><?php esc_html_e( 'HTTPS base URL', 'ai-chat-for-amazon-bedrock' ); ?></label></th><td><input type="url" id="ai_chat_bedrock_mcp_server_url" class="regular-text" placeholder="https://mcp.example.com/mcp" pattern="https://.*"></td></tr>
					<tr>
						<th scope="row"><label for="ai_chat_bedrock_mcp_auth_type"><?php esc_html_e( 'Authentication', 'ai-chat-for-amazon-bedrock' ); ?></label></th>
						<td>
							<select id="ai_chat_bedrock_mcp_auth_type">
								<option value="none"><?php esc_html_e( 'None (public endpoint)', 'ai-chat-for-amazon-bedrock' ); ?></option>
								<option value="bearer"><?php esc_html_e( 'Bearer token', 'ai-chat-for-amazon-bedrock' ); ?></option>
								<option value="sigv4"><?php esc_html_e( 'AWS SigV4 (Bedrock AgentCore Gateway)', 'ai-chat-for-amazon-bedrock' ); ?></option>
							</select>
							<p class="description"><?php esc_html_e( 'SigV4 signs requests with the same AWS credentials used for Bedrock, so an AgentCore Gateway endpoint needs no separate secret.', 'ai-chat-for-amazon-bedrock' ); ?></p>
						</td>
					</tr>
					<tr class="aicfab-auth-bearer" style="display:none">
						<th scope="row"><label for="ai_chat_bedrock_mcp_auth_token"><?php esc_html_e( 'Bearer token', 'ai-chat-for-amazon-bedrock' ); ?></label></th>
						<td><input type="password" id="ai_chat_bedrock_mcp_auth_token" class="regular-text" autocomplete="new-password"><p class="description"><?php esc_html_e( 'Stored encrypted and never displayed again.', 'ai-chat-for-amazon-bedrock' ); ?></p></td>
					</tr>
					<tr class="aicfab-auth-sigv4" style="display:none">
						<th scope="row"><label for="ai_chat_bedrock_mcp_auth_service"><?php esc_html_e( 'Signing service and region', 'ai-chat-for-amazon-bedrock' ); ?></label></th>
						<td>
							<input type="text" id="ai_chat_bedrock_mcp_auth_service" class="regular-text" value="bedrock-agentcore" maxlength="60">
							<input type="text" id="ai_chat_bedrock_mcp_auth_region" aria-label="<?php esc_attr_e( 'SigV4 signing region', 'ai-chat-for-amazon-bedrock' ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'Region (optional)', 'ai-chat-for-amazon-bedrock' ); ?>" maxlength="30">
							<p class="description"><?php esc_html_e( 'Leave the region empty to use the region configured for Bedrock.', 'ai-chat-for-amazon-bedrock' ); ?></p>
						</td>
					</tr>
				</table>
				<p><button type="button" id="ai_chat_bedrock_add_mcp_server" class="button button-primary"><?php esc_html_e( 'Add server', 'ai-chat-for-amazon-bedrock' ); ?></button></p>
			</div>
			<table class="widefat" id="ai-chat-bedrock-mcp-servers-table">
				<thead><tr><th><?php esc_html_e( 'Name', 'ai-chat-for-amazon-bedrock' ); ?></th><th><?php esc_html_e( 'URL', 'ai-chat-for-amazon-bedrock' ); ?></th><th><?php esc_html_e( 'Status', 'ai-chat-for-amazon-bedrock' ); ?></th><th><?php esc_html_e( 'Tools', 'ai-chat-for-amazon-bedrock' ); ?></th><th><?php esc_html_e( 'Actions', 'ai-chat-for-amazon-bedrock' ); ?></th></tr></thead>
				<tbody><tr class="no-items"><td colspan="5"><?php esc_html_e( 'No MCP servers registered.', 'ai-chat-for-amazon-bedrock' ); ?></td></tr></tbody>
			</table>
		</div>

		<div id="ai-chat-bedrock-mcp-tools-modal" class="ai-chat-bedrock-modal" role="dialog" aria-modal="true" aria-labelledby="aicfab-mcp-tools-title" style="display:none">
			<div class="ai-chat-bedrock-modal-content"><button type="button" class="ai-chat-bedrock-modal-close" aria-label="<?php esc_attr_e( 'Close', 'ai-chat-for-amazon-bedrock' ); ?>">&times;</button><h3 id="aicfab-mcp-tools-title"><?php esc_html_e( 'MCP tools', 'ai-chat-for-amazon-bedrock' ); ?></h3><div id="ai-chat-bedrock-mcp-tools-list"></div></div>
		</div>

		</div>

		<div class="aicfab-mcp-section" id="aicfab-mcp-clients">
		<h2><?php esc_html_e( 'Connect AI clients (OAuth)', 'ai-chat-for-amazon-bedrock' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Let clients such as Claude Desktop connect by pasting the MCP URL, signing in to WordPress and approving. No WordPress password is shared with the client, and access can be revoked here at any time.', 'ai-chat-for-amazon-bedrock' ); ?></p>
		<?php $oauth = class_exists( 'AI_Chat_Bedrock_OAuth' ) ? new AI_Chat_Bedrock_OAuth() : null; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ai_chat_bedrock_oauth_settings' ); ?>
			<input type="hidden" name="action" value="ai_chat_bedrock_save_oauth">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="aicfab_oauth_enabled"><?php esc_html_e( 'OAuth connections', 'ai-chat-for-amazon-bedrock' ); ?></label></th>
					<td>
						<label><input type="checkbox" id="aicfab_oauth_enabled" name="oauth_enabled" value="1" <?php checked( AI_Chat_Bedrock_OAuth::enabled() ); ?>> <?php esc_html_e( 'Allow AI clients to connect with OAuth', 'ai-chat-for-amazon-bedrock' ); ?></label>
						<p class="description"><?php esc_html_e( 'Requires HTTPS in practice: clients only accept HTTPS redirect targets, and WordPress requires HTTPS for application passwords.', 'ai-chat-for-amazon-bedrock' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'MCP endpoint', 'ai-chat-for-amazon-bedrock' ); ?></th>
					<td><code><?php echo esc_html( rest_url( AI_Chat_Bedrock_WP_MCP_Server::NAMESPACE_V1 . '/mcp' ) ); ?></code></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Discovery', 'ai-chat-for-amazon-bedrock' ); ?></th>
					<td>
						<code><?php echo esc_html( home_url( '/.well-known/oauth-authorization-server' ) ); ?></code><br>
						<code><?php echo esc_html( home_url( '/.well-known/oauth-protected-resource' ) ); ?></code>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save connection settings', 'ai-chat-for-amazon-bedrock' ) ); ?>
		</form>

		<?php $grants = $oauth ? $oauth->grant_summaries() : array(); ?>
		<h3><?php esc_html_e( 'Connected AI clients', 'ai-chat-for-amazon-bedrock' ); ?></h3>
		<?php if ( empty( $grants ) ) : ?>
			<p class="aicfab-tool-log-empty"><?php esc_html_e( 'No AI clients are connected.', 'ai-chat-for-amazon-bedrock' ); ?></p>
		<?php else : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ai_chat_bedrock_oauth_revoke' ); ?>
				<input type="hidden" name="action" value="ai_chat_bedrock_revoke_oauth">
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Client', 'ai-chat-for-amazon-bedrock' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Acts as', 'ai-chat-for-amazon-bedrock' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Connected', 'ai-chat-for-amazon-bedrock' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Access token', 'ai-chat-for-amazon-bedrock' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Revoke', 'ai-chat-for-amazon-bedrock' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $grants as $grant ) : ?>
							<tr>
								<td><?php echo esc_html( $grant['client'] ); ?></td>
								<td><?php echo esc_html( $grant['user'] ); ?></td>
								<td><?php echo esc_html( $grant['created'] ? wp_date( 'Y-m-d H:i', $grant['created'] ) : '—' ); ?></td>
								<td class="aicfab-status aicfab-status-<?php echo $grant['active'] ? 'pass' : 'warn'; ?>">
									<?php echo esc_html( $grant['active'] ? __( 'Active', 'ai-chat-for-amazon-bedrock' ) : __( 'Expired, refreshable', 'ai-chat-for-amazon-bedrock' ) ); ?>
								</td>
								<td><label><input type="checkbox" name="revoke[]" value="<?php echo esc_attr( $grant['id'] ); ?>"> <?php esc_html_e( 'Revoke', 'ai-chat-for-amazon-bedrock' ); ?></label></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p>
					<?php submit_button( __( 'Revoke selected', 'ai-chat-for-amazon-bedrock' ), 'secondary', 'submit', false ); ?>
					<button type="submit" name="revoke_all" value="1" class="button"><?php esc_html_e( 'Revoke all connections', 'ai-chat-for-amazon-bedrock' ); ?></button>
				</p>
			</form>
		<?php endif; ?>

		</div>

		<div class="aicfab-mcp-section" id="aicfab-mcp-policy">
		<h2><?php esc_html_e( 'Tool policy', 'ai-chat-for-amazon-bedrock' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Tools that appear to change data are blocked until you allow them here. Read-only tools are allowed by default.', 'ai-chat-for-amazon-bedrock' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ai_chat_bedrock_tool_policy' ); ?>
			<input type="hidden" name="action" value="ai_chat_bedrock_save_tool_policy">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="aicfab_mcp_capability"><?php esc_html_e( 'Capability required', 'ai-chat-for-amazon-bedrock' ); ?></label></th>
					<td>
						<?php $capability = AI_Chat_Bedrock_Tool_Policy::required_capability(); ?>
						<select id="aicfab_mcp_capability" name="mcp_capability">
							<?php
							$capabilities = array(
								'read'              => __( 'Any signed-in user', 'ai-chat-for-amazon-bedrock' ),
								'edit_posts'        => __( 'Contributors and above (edit_posts)', 'ai-chat-for-amazon-bedrock' ),
								'edit_others_posts' => __( 'Editors and above (edit_others_posts)', 'ai-chat-for-amazon-bedrock' ),
								'manage_options'    => __( 'Administrators only (manage_options)', 'ai-chat-for-amazon-bedrock' ),
							);
							foreach ( $capabilities as $value => $label ) {
								echo '<option value="' . esc_attr( $value ) . '" ' . selected( $capability, $value, false ) . '>' . esc_html( $label ) . '</option>';
							}
							?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aicfab_mcp_max_rounds"><?php esc_html_e( 'Maximum tool rounds', 'ai-chat-for-amazon-bedrock' ); ?></label></th>
					<td>
						<input type="number" id="aicfab_mcp_max_rounds" name="mcp_max_rounds" min="1" max="<?php echo esc_attr( AI_Chat_Bedrock_Tool_Policy::MAX_ROUNDS_LIMIT ); ?>" value="<?php echo esc_attr( AI_Chat_Bedrock_Tool_Policy::max_rounds() ); ?>">
						<p class="description"><?php esc_html_e( 'How many times the model may call tools and continue reasoning for one visitor message.', 'ai-chat-for-amazon-bedrock' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aicfab_mcp_log_enabled"><?php esc_html_e( 'Audit log', 'ai-chat-for-amazon-bedrock' ); ?></label></th>
					<td>
						<label><input type="checkbox" id="aicfab_mcp_log_enabled" name="mcp_log_enabled" value="1" <?php checked( AI_Chat_Bedrock_Tool_Log::enabled() ); ?>> <?php esc_html_e( 'Record tool calls (metadata only)', 'ai-chat-for-amazon-bedrock' ); ?></label>
					</td>
				</tr>
			</table>

			<?php
			$discovered = $mcp_client instanceof AI_Chat_Bedrock_MCP_Client ? $mcp_client->get_all_tools() : array();
			$policy     = AI_Chat_Bedrock_Tool_Policy::policy();
			?>
			<?php if ( ! empty( $discovered ) ) : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Tool', 'ai-chat-for-amazon-bedrock' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Type', 'ai-chat-for-amazon-bedrock' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Allowed', 'ai-chat-for-amazon-bedrock' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $discovered as $tool ) : ?>
							<?php
							$name        = isset( $tool['name'] ) ? (string) $tool['name'] : '';
							$description = isset( $tool['description'] ) ? (string) $tool['description'] : '';
							if ( '' === $name ) {
								continue;
							}
							$mutating = AI_Chat_Bedrock_Tool_Policy::is_mutating( $name, $description );
							$allowed  = isset( $policy[ $name ] ) ? 'allow' === $policy[ $name ] : ! $mutating;
							?>
							<tr>
								<td><code><?php echo esc_html( $name ); ?></code><br><span class="description"><?php echo esc_html( wp_trim_words( $description, 18 ) ); ?></span></td>
								<td><?php echo esc_html( $mutating ? __( 'Changes data', 'ai-chat-for-amazon-bedrock' ) : __( 'Read only', 'ai-chat-for-amazon-bedrock' ) ); ?></td>
								<td><label><input type="checkbox" name="tool_allow[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( $allowed ); ?>> <?php esc_html_e( 'Allow', 'ai-chat-for-amazon-bedrock' ); ?></label></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p class="aicfab-tool-log-empty"><?php esc_html_e( 'No tools discovered yet. Register an MCP server and refresh its tools.', 'ai-chat-for-amazon-bedrock' ); ?></p>
			<?php endif; ?>

			<?php submit_button( __( 'Save tool policy', 'ai-chat-for-amazon-bedrock' ) ); ?>
		</form>

		</div>

		<div class="aicfab-mcp-section" id="aicfab-mcp-log">
		<h2><?php esc_html_e( 'Recent tool calls', 'ai-chat-for-amazon-bedrock' ); ?></h2>
		<?php $log = AI_Chat_Bedrock_Tool_Log::recent( 25 ); ?>
		<?php if ( empty( $log ) ) : ?>
			<p class="aicfab-tool-log-empty"><?php esc_html_e( 'No tool calls recorded yet.', 'ai-chat-for-amazon-bedrock' ); ?></p>
		<?php else : ?>
			<table class="widefat striped">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Time', 'ai-chat-for-amazon-bedrock' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Tool', 'ai-chat-for-amazon-bedrock' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Round', 'ai-chat-for-amazon-bedrock' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Status', 'ai-chat-for-amazon-bedrock' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Duration', 'ai-chat-for-amazon-bedrock' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Parameter keys', 'ai-chat-for-amazon-bedrock' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $log as $entry ) : ?>
						<tr>
							<td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', (int) $entry['time'] ) ); ?></td>
							<td><code><?php echo esc_html( $entry['tool'] ); ?></code></td>
							<td><?php echo esc_html( (string) $entry['round'] ); ?></td>
							<td class="aicfab-status aicfab-status-<?php echo 'ok' === $entry['status'] ? 'pass' : 'fail'; ?>">
								<?php echo esc_html( 'ok' === $entry['status'] ? __( 'Success', 'ai-chat-for-amazon-bedrock' ) : ( '' !== $entry['error'] ? $entry['error'] : __( 'Error', 'ai-chat-for-amazon-bedrock' ) ) ); ?>
							</td>
							<td><?php echo esc_html( $entry['duration'] > 0 ? $entry['duration'] . ' ms' : '—' ); ?></td>
							<td><?php echo esc_html( ! empty( $entry['keys'] ) ? implode( ', ', $entry['keys'] ) : '—' ); ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description"><?php esc_html_e( 'Parameter values, tool output and chat content are never stored.', 'ai-chat-for-amazon-bedrock' ); ?></p>
		<?php endif; ?>
		</div>
	</div>
</div>

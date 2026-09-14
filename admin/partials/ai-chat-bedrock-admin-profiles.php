<?php
/*
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 *
 * This view is included from inside a class method, so the variables below are method
 * scope rather than globals. The sniff analyses the file on its own and cannot see the
 * include site.
 */
/** Chat profiles view. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

$profiles = AI_Chat_Bedrock_Profiles::all();
$editing  = isset( $_GET['edit'] ) ? AI_Chat_Bedrock_Profiles::sanitize_key( wp_unslash( $_GET['edit'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitize_key() validates the value.
$current  = $editing && isset( $profiles[ $editing ] ) ? $profiles[ $editing ] : array();
$notice   = isset( $_GET['aicfab-profile'] ) ? sanitize_key( wp_unslash( $_GET['aicfab-profile'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$models   = AI_Chat_Bedrock_Models::options();

$value = function ( $key, $fallback = '' ) use ( $current ) {
	return isset( $current[ $key ] ) && '' !== $current[ $key ] ? $current[ $key ] : $fallback;
};
?>
<div class="wrap aicfab-dashboard">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
	<p class="aicfab-lede"><?php esc_html_e( 'Serve several chats from one plugin. Each profile can use its own model, prompt, presentation, guest access and grounding, and anything left empty falls back to the main settings.', 'ai-chat-for-amazon-bedrock' ); ?></p>

	<?php if ( 'saved' === $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Chat profile saved.', 'ai-chat-for-amazon-bedrock' ); ?></p></div>
	<?php elseif ( 'deleted' === $notice ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Chat profile deleted.', 'ai-chat-for-amazon-bedrock' ); ?></p></div>
	<?php elseif ( 'aicfab_invalid_profile_key' === $notice ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'A profile needs a name using letters, numbers or dashes.', 'ai-chat-for-amazon-bedrock' ); ?></p></div>
	<?php elseif ( 'aicfab_too_many_profiles' === $notice ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'The maximum number of chat profiles has been reached.', 'ai-chat-for-amazon-bedrock' ); ?></p></div>
	<?php endif; ?>

	<?php if ( ! empty( $profiles ) ) : ?>
		<table class="widefat striped" style="margin-bottom: 24px;">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Profile', 'ai-chat-for-amazon-bedrock' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Model', 'ai-chat-for-amazon-bedrock' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Guest access', 'ai-chat-for-amazon-bedrock' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Grounding', 'ai-chat-for-amazon-bedrock' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Shortcode', 'ai-chat-for-amazon-bedrock' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'ai-chat-for-amazon-bedrock' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $profiles as $key => $profile ) : ?>
					<tr>
						<td><strong><?php echo esc_html( $profile['label'] ); ?></strong><br><code><?php echo esc_html( $key ); ?></code></td>
						<td><?php echo esc_html( '' !== $profile['model_id'] ? $profile['model_id'] : __( 'Site default', 'ai-chat-for-amazon-bedrock' ) ); ?></td>
						<td><?php echo esc_html( 'inherit' === $profile['allow_public_chat'] ? __( 'Inherit', 'ai-chat-for-amazon-bedrock' ) : ( 'on' === $profile['allow_public_chat'] ? __( 'Guests allowed', 'ai-chat-for-amazon-bedrock' ) : __( 'Signed in only', 'ai-chat-for-amazon-bedrock' ) ) ); ?></td>
						<td><?php echo esc_html( 'inherit' === $profile['enable_site_context'] ? __( 'Inherit', 'ai-chat-for-amazon-bedrock' ) : ( 'on' === $profile['enable_site_context'] ? __( 'On', 'ai-chat-for-amazon-bedrock' ) : __( 'Off', 'ai-chat-for-amazon-bedrock' ) ) ); ?></td>
						<td><code>[ai_chat_bedrock profile="<?php echo esc_html( $key ); ?>"]</code></td>
						<td>
							<a class="button button-small" href="<?php echo esc_url( add_query_arg( 'edit', $key, admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock-profiles' ) ) ); ?>"><?php esc_html_e( 'Edit', 'ai-chat-for-amazon-bedrock' ); ?></a>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
								<?php wp_nonce_field( 'ai_chat_bedrock_delete_profile' ); ?>
								<input type="hidden" name="action" value="ai_chat_bedrock_delete_profile">
								<input type="hidden" name="key" value="<?php echo esc_attr( $key ); ?>">
								<button type="submit" class="button button-small"><?php esc_html_e( 'Delete', 'ai-chat-for-amazon-bedrock' ); ?></button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<div class="aicfab-panel" style="max-width: 820px;">
		<h2><?php echo esc_html( $current ? __( 'Edit profile', 'ai-chat-for-amazon-bedrock' ) : __( 'Add profile', 'ai-chat-for-amazon-bedrock' ) ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ai_chat_bedrock_save_profile' ); ?>
			<input type="hidden" name="action" value="ai_chat_bedrock_save_profile">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="aicfab_profile_key"><?php esc_html_e( 'Profile key', 'ai-chat-for-amazon-bedrock' ); ?></label></th>
					<td>
						<input type="text" id="aicfab_profile_key" name="key" class="regular-text" maxlength="32" required
							value="<?php echo esc_attr( $value( 'key' ) ); ?>" <?php echo $current ? 'readonly' : ''; ?>>
						<p class="description"><?php esc_html_e( 'Lowercase letters, numbers and dashes. Used in the shortcode and block.', 'ai-chat-for-amazon-bedrock' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aicfab_profile_label"><?php esc_html_e( 'Name', 'ai-chat-for-amazon-bedrock' ); ?></label></th>
					<td><input type="text" id="aicfab_profile_label" name="label" class="regular-text" maxlength="80" value="<?php echo esc_attr( $value( 'label' ) ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="aicfab_profile_model"><?php esc_html_e( 'Model', 'ai-chat-for-amazon-bedrock' ); ?></label></th>
					<td>
						<select id="aicfab_profile_model" name="model_id">
							<option value=""><?php esc_html_e( 'Site default', 'ai-chat-for-amazon-bedrock' ); ?></option>
							<?php foreach ( $models as $model_id => $label ) : ?>
								<option value="<?php echo esc_attr( $model_id ); ?>" <?php selected( $value( 'model_id' ), $model_id ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aicfab_profile_prompt"><?php esc_html_e( 'System prompt', 'ai-chat-for-amazon-bedrock' ); ?></label></th>
					<td><textarea id="aicfab_profile_prompt" name="system_prompt" rows="5" class="large-text" maxlength="8000"><?php echo esc_textarea( $value( 'system_prompt' ) ); ?></textarea></td>
				</tr>
				<tr>
					<th scope="row"><label for="aicfab_profile_title"><?php esc_html_e( 'Chat title', 'ai-chat-for-amazon-bedrock' ); ?></label></th>
					<td><input type="text" id="aicfab_profile_title" name="chat_title" class="regular-text" maxlength="120" value="<?php echo esc_attr( $value( 'chat_title' ) ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="aicfab_profile_welcome"><?php esc_html_e( 'Welcome message', 'ai-chat-for-amazon-bedrock' ); ?></label></th>
					<td><input type="text" id="aicfab_profile_welcome" name="welcome_message" class="regular-text" maxlength="500" value="<?php echo esc_attr( $value( 'welcome_message' ) ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="aicfab_profile_suggestions"><?php esc_html_e( 'Suggested questions', 'ai-chat-for-amazon-bedrock' ); ?></label></th>
					<td>
						<textarea id="aicfab_profile_suggestions" name="suggested_questions" rows="3" class="large-text code"><?php echo esc_textarea( $value( 'suggested_questions' ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One per line, up to four. Leave empty to inherit the main settings.', 'ai-chat-for-amazon-bedrock' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Limits', 'ai-chat-for-amazon-bedrock' ); ?></th>
					<td>
						<label><?php esc_html_e( 'Max tokens', 'ai-chat-for-amazon-bedrock' ); ?>
							<input type="number" name="max_tokens" min="0" max="4000" step="50" style="width:100px" value="<?php echo esc_attr( $value( 'max_tokens', 0 ) ); ?>">
						</label>
						<label style="margin-left:14px"><?php esc_html_e( 'Temperature', 'ai-chat-for-amazon-bedrock' ); ?>
							<input type="number" name="temperature" min="0" max="1" step="0.1" style="width:90px" value="<?php echo esc_attr( $value( 'temperature' ) ); ?>">
						</label>
						<label style="margin-left:14px"><?php esc_html_e( 'Requests per minute', 'ai-chat-for-amazon-bedrock' ); ?>
							<input type="number" name="rate_limit_per_minute" min="0" max="60" style="width:90px" value="<?php echo esc_attr( $value( 'rate_limit_per_minute', 0 ) ); ?>">
						</label>
						<label style="margin-left:14px"><?php esc_html_e( 'Passages', 'ai-chat-for-amazon-bedrock' ); ?>
							<input type="number" name="context_results" min="0" max="8" style="width:80px" value="<?php echo esc_attr( $value( 'context_results', 0 ) ); ?>">
						</label>
						<p class="description"><?php esc_html_e( 'Zero or empty means inherit the main settings.', 'ai-chat-for-amazon-bedrock' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aicfab_profile_guests"><?php esc_html_e( 'Guest access', 'ai-chat-for-amazon-bedrock' ); ?></label></th>
					<td>
						<select id="aicfab_profile_guests" name="allow_public_chat">
							<option value="inherit" <?php selected( $value( 'allow_public_chat', 'inherit' ), 'inherit' ); ?>><?php esc_html_e( 'Inherit main settings', 'ai-chat-for-amazon-bedrock' ); ?></option>
							<option value="off" <?php selected( $value( 'allow_public_chat', 'inherit' ), 'off' ); ?>><?php esc_html_e( 'Signed-in users only', 'ai-chat-for-amazon-bedrock' ); ?></option>
							<option value="on" <?php selected( $value( 'allow_public_chat', 'inherit' ), 'on' ); ?>><?php esc_html_e( 'Allow guests (incurs AWS charges)', 'ai-chat-for-amazon-bedrock' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aicfab_profile_grounding"><?php esc_html_e( 'Site content grounding', 'ai-chat-for-amazon-bedrock' ); ?></label></th>
					<td>
						<select id="aicfab_profile_grounding" name="enable_site_context">
							<option value="inherit" <?php selected( $value( 'enable_site_context', 'inherit' ), 'inherit' ); ?>><?php esc_html_e( 'Inherit main settings', 'ai-chat-for-amazon-bedrock' ); ?></option>
							<option value="off" <?php selected( $value( 'enable_site_context', 'inherit' ), 'off' ); ?>><?php esc_html_e( 'Off', 'ai-chat-for-amazon-bedrock' ); ?></option>
							<option value="on" <?php selected( $value( 'enable_site_context', 'inherit' ), 'on' ); ?>><?php esc_html_e( 'On', 'ai-chat-for-amazon-bedrock' ); ?></option>
						</select>
					</td>
				</tr>
			</table>
			<?php submit_button( $current ? __( 'Update profile', 'ai-chat-for-amazon-bedrock' ) : __( 'Add profile', 'ai-chat-for-amazon-bedrock' ) ); ?>
		</form>
	</div>
</div>

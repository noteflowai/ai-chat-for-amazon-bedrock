<?php
/*
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 *
 * This view is included from inside a class method, so the variables below are method
 * scope rather than globals. The sniff analyses the file on its own and cannot see the
 * include site.
 */
/** Settings view with tabs. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

$aicfab_tabs = AI_Chat_Bedrock_Admin::tabs();
$current     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'aws'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$current     = isset( $aicfab_tabs[ $current ] ) ? $current : 'aws';
$aicfab_page = $aicfab_tabs[ $current ]['page'];
$base        = admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock-settings' );
?>
<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
	<?php settings_errors( 'ai_chat_bedrock_settings' ); ?>
	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice state.
	$aicfab_transfer = isset( $_GET['aicfab-transfer'] ) ? sanitize_key( wp_unslash( $_GET['aicfab-transfer'] ) ) : '';
	if ( 'imported' === $aicfab_transfer ) :
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$aicfab_applied = isset( $_GET['aicfab-applied'] ) ? absint( $_GET['aicfab-applied'] ) : 0;
		?>
		<div class="notice notice-success is-dismissible"><p>
			<?php
			printf(
				/* translators: %s: number of settings groups applied. */
				esc_html__( 'Configuration imported. %s settings groups were applied. Credentials were not in the file and are unchanged.', 'ai-chat-for-amazon-bedrock' ),
				esc_html( number_format_i18n( $aicfab_applied ) )
			);
			?>
		</p></div>
	<?php elseif ( 'error' === $aicfab_transfer ) : ?>
		<div class="notice notice-error is-dismissible"><p>
			<?php
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo esc_html( isset( $_GET['aicfab-message'] ) ? rawurldecode( sanitize_text_field( wp_unslash( $_GET['aicfab-message'] ) ) ) : __( 'The configuration could not be imported.', 'ai-chat-for-amazon-bedrock' ) );
			?>
		</p></div>
	<?php endif; ?>

	<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Settings sections', 'ai-chat-for-amazon-bedrock' ); ?>">
		<?php foreach ( $aicfab_tabs as $key => $aicfab_tab ) : ?>
			<a class="nav-tab <?php echo $key === $current ? 'nav-tab-active' : ''; ?>"
				href="<?php echo esc_url( add_query_arg( 'tab', $key, $base ) ); ?>"><?php echo esc_html( $aicfab_tab['label'] ); ?></a>
		<?php endforeach; ?>
	</nav>

	<form method="post" action="options.php">
		<?php
		settings_fields( 'ai_chat_bedrock_settings' );
		foreach ( AI_Chat_Bedrock_Admin::fields_for_page( $aicfab_page ) as $field ) {
			printf( '<input type="hidden" name="ai_chat_bedrock_settings[_aicfab_fields][]" value="%s">', esc_attr( $field ) );
		}
		do_settings_sections( $aicfab_page );
		submit_button();
		?>
	</form>

	<?php if ( 'aws' === $current ) : ?>
		<div class="ai-chat-bedrock-settings-info">
			<h2><?php esc_html_e( 'Production guidance', 'ai-chat-for-amazon-bedrock' ); ?></h2>
			<ul>
				<li><?php esc_html_e( 'Use a dedicated least-privilege IAM identity with only bedrock:InvokeModel for the selected models.', 'ai-chat-for-amazon-bedrock' ); ?></li>
				<li><?php esc_html_e( 'Prefer credentials defined in wp-config.php constants so secrets are not stored in the database.', 'ai-chat-for-amazon-bedrock' ); ?></li>
				<li><?php esc_html_e( 'Guest chat is disabled by default because every request can incur AWS charges.', 'ai-chat-for-amazon-bedrock' ); ?></li>
				<li><?php esc_html_e( 'Rotate AWS credentials immediately if database or WordPress administrator access is compromised.', 'ai-chat-for-amazon-bedrock' ); ?></li>
			</ul>
		</div>
	<?php endif; ?>

	<hr>

	<h2><?php esc_html_e( 'Move this configuration to another site', 'ai-chat-for-amazon-bedrock' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Everything except credentials travels: models, prompts, profiles, limits, grounding, MCP servers and the tool policy. AWS keys and MCP tokens are never written to the file, because they are encrypted for this site and a configuration file is not a safe place for them.', 'ai-chat-for-amazon-bedrock' ); ?>
	</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'ai_chat_bedrock_export_settings' ); ?>
		<input type="hidden" name="action" value="ai_chat_bedrock_export_settings">
		<p><button type="submit" class="button"><?php esc_html_e( 'Download configuration', 'ai-chat-for-amazon-bedrock' ); ?></button></p>
	</form>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
		<?php wp_nonce_field( 'ai_chat_bedrock_import_settings' ); ?>
		<input type="hidden" name="action" value="ai_chat_bedrock_import_settings">
		<p>
			<label for="aicfab_import_file"><?php esc_html_e( 'Configuration file', 'ai-chat-for-amazon-bedrock' ); ?></label><br>
			<input type="file" id="aicfab_import_file" name="aicfab_import_file" accept="application/json,.json">
		</p>
		<p>
			<label for="aicfab_import_json"><?php esc_html_e( 'Or paste the file contents', 'ai-chat-for-amazon-bedrock' ); ?></label><br>
			<textarea id="aicfab_import_json" name="aicfab_import_json" class="large-text code" rows="4"></textarea>
		</p>
		<p>
			<button type="submit" class="button"><?php esc_html_e( 'Apply configuration', 'ai-chat-for-amazon-bedrock' ); ?></button>
			<span class="description"><?php esc_html_e( 'Existing values are overwritten. Credentials are left alone.', 'ai-chat-for-amazon-bedrock' ); ?></span>
		</p>
	</form>
</div>

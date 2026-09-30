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
// Import and export is a tab of its own, with no settings form: moving a configuration is a
// one-off task, not something to scroll past under every group of options.
$aicfab_nav  = $aicfab_tabs + array( 'transfer' => array( 'label' => __( 'Import and export', 'ai-chat-for-amazon-bedrock' ) ) );
$current     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'aws'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$current     = isset( $aicfab_nav[ $current ] ) ? $current : 'aws';
$aicfab_page = isset( $aicfab_tabs[ $current ] ) ? $aicfab_tabs[ $current ]['page'] : '';
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
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$aicfab_skipped = isset( $_GET['aicfab-skipped'] ) ? absint( $_GET['aicfab-skipped'] ) : 0;
		?>
		<div class="notice <?php echo esc_attr( $aicfab_skipped > 0 ? 'notice-warning' : 'notice-success' ); ?> is-dismissible"><p>
			<?php
			printf(
				/* translators: %s: number of settings groups applied. */
				esc_html__( 'Configuration imported. %s settings groups were applied. Credentials were not in the file and are unchanged.', 'ai-chat-for-amazon-bedrock' ),
				esc_html( number_format_i18n( $aicfab_applied ) )
			);
			if ( $aicfab_skipped > 0 ) {
				echo ' ';
				printf(
					/* translators: %s: number of settings groups skipped. */
					esc_html( _n( '%s group in the file was skipped, because this version does not recognize it or its value was not valid.', '%s groups in the file were skipped, because this version does not recognize them or their values were not valid.', $aicfab_skipped, 'ai-chat-for-amazon-bedrock' ) ),
					esc_html( number_format_i18n( $aicfab_skipped ) )
				);
			}
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
	<?php
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only notice state.
	if ( isset( $_GET['aicfab-cleared'] ) ) :
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$aicfab_cleared = absint( $_GET['aicfab-cleared'] );
		?>
		<div class="notice notice-success is-dismissible"><p>
			<?php
			echo esc_html(
				sprintf(
					/* translators: %s: number of items whose stored vectors were deleted. */
					_n( 'Index deleted: the vectors of %s item were removed.', 'Index deleted: the vectors of %s items were removed.', $aicfab_cleared, 'ai-chat-for-amazon-bedrock' ),
					number_format_i18n( $aicfab_cleared )
				)
			);
			?>
		</p></div>
	<?php endif; ?>

	<nav class="nav-tab-wrapper" aria-label="<?php esc_attr_e( 'Settings sections', 'ai-chat-for-amazon-bedrock' ); ?>">
		<?php foreach ( $aicfab_nav as $key => $aicfab_tab ) : ?>
			<a class="nav-tab <?php echo $key === $current ? 'nav-tab-active' : ''; ?>"
				href="<?php echo esc_url( add_query_arg( 'tab', $key, $base ) ); ?>"<?php echo $key === $current ? ' aria-current="page"' : ''; ?>><?php echo esc_html( $aicfab_tab['label'] ); ?></a>
		<?php endforeach; ?>
	</nav>

	<?php if ( '' !== $aicfab_page ) : ?>
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
	<?php endif; ?>

	<?php if ( 'knowledge' === $current && AI_Chat_Bedrock_Embeddings::enabled() ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="aicfab-clear-embeddings" data-aicfab-confirm="<?php esc_attr_e( 'Delete the index? Answers use keyword search until the content is indexed again.', 'ai-chat-for-amazon-bedrock' ); ?>">
			<?php wp_nonce_field( 'ai_chat_bedrock_clear_embeddings' ); ?>
			<input type="hidden" name="action" value="ai_chat_bedrock_clear_embeddings">
		</form>
	<?php endif; ?>

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

	<?php if ( 'transfer' === $current ) : ?>
		<div class="aicfab-transfer">
			<h2><?php esc_html_e( 'Move this configuration to another site', 'ai-chat-for-amazon-bedrock' ); ?></h2>
			<p>
				<?php esc_html_e( 'Everything except credentials travels: models, prompts, profiles, limits, grounding, MCP servers and the tool policy. AWS keys and MCP tokens are never written to the file, because they are encrypted for this site and a configuration file is not a safe place for them.', 'ai-chat-for-amazon-bedrock' ); ?>
			</p>

			<div class="aicfab-panel">
				<h3><?php esc_html_e( 'Export', 'ai-chat-for-amazon-bedrock' ); ?></h3>
				<p class="description"><?php esc_html_e( 'Downloads a JSON file with the current configuration of this site.', 'ai-chat-for-amazon-bedrock' ); ?></p>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'ai_chat_bedrock_export_settings' ); ?>
					<input type="hidden" name="action" value="ai_chat_bedrock_export_settings">
					<p><button type="submit" class="button button-secondary"><?php esc_html_e( 'Download configuration', 'ai-chat-for-amazon-bedrock' ); ?></button></p>
				</form>
			</div>

			<div class="aicfab-panel">
				<h3><?php esc_html_e( 'Import', 'ai-chat-for-amazon-bedrock' ); ?></h3>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
					<?php wp_nonce_field( 'ai_chat_bedrock_import_settings' ); ?>
					<input type="hidden" name="action" value="ai_chat_bedrock_import_settings">
					<table class="form-table" role="presentation">
						<tr>
							<th scope="row"><label for="aicfab_import_file"><?php esc_html_e( 'Configuration file', 'ai-chat-for-amazon-bedrock' ); ?></label></th>
							<td><input type="file" id="aicfab_import_file" name="aicfab_import_file" accept="application/json,.json"></td>
						</tr>
						<tr>
							<th scope="row"><label for="aicfab_import_json"><?php esc_html_e( 'Or paste the file contents', 'ai-chat-for-amazon-bedrock' ); ?></label></th>
							<td><textarea id="aicfab_import_json" name="aicfab_import_json" class="large-text code" rows="6"></textarea></td>
						</tr>
					</table>
					<p class="description" id="aicfab-import-note"><?php esc_html_e( 'Existing values are overwritten. Credentials are left alone.', 'ai-chat-for-amazon-bedrock' ); ?></p>
					<p class="submit"><button type="submit" class="button button-primary" aria-describedby="aicfab-import-note"><?php esc_html_e( 'Apply configuration', 'ai-chat-for-amazon-bedrock' ); ?></button></p>
				</form>
			</div>
		</div>
	<?php endif; ?>
</div>

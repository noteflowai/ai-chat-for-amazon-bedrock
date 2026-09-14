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
</div>

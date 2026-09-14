<?php
/*
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 *
 * This view is included from inside a class method, so the variables below are method
 * scope rather than globals. The sniff analyses the file on its own and cannot see the
 * include site.
 */
/** Diagnostics view. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

$diagnostics     = new AI_Chat_Bedrock_Diagnostics();
$checks          = $diagnostics->run( false );
$aicfab_aws      = new AI_Chat_Bedrock_AWS();
$aicfab_identity = $aicfab_aws->caller_identity();
$aicfab_account  = is_wp_error( $aicfab_identity ) ? '' : $aicfab_identity['account'];
$aicfab_arn      = is_wp_error( $aicfab_identity ) ? '' : $aicfab_identity['arn'];
$aicfab_policy   = AI_Chat_Bedrock_Iam_Policy::for_site( get_option( 'ai_chat_bedrock_settings', array() ), $aicfab_account );
$labels          = array(
	'pass' => __( 'Pass', 'ai-chat-for-amazon-bedrock' ),
	'warn' => __( 'Review', 'ai-chat-for-amazon-bedrock' ),
	'fail' => __( 'Action required', 'ai-chat-for-amazon-bedrock' ),
);
?>
<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
	<p><?php esc_html_e( 'These checks verify credentials, region, model selection, streaming support, and chat security defaults. The connectivity test sends one short paid request to Amazon Bedrock.', 'ai-chat-for-amazon-bedrock' ); ?></p>

	<p>
		<button type="button" class="button button-primary" id="aicfab-run-diagnostics"><?php esc_html_e( 'Run connectivity test', 'ai-chat-for-amazon-bedrock' ); ?></button>
		<span id="aicfab-diagnostics-status" role="status"></span>
	</p>

	<table class="widefat striped" id="aicfab-diagnostics-table">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Check', 'ai-chat-for-amazon-bedrock' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Status', 'ai-chat-for-amazon-bedrock' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Details', 'ai-chat-for-amazon-bedrock' ); ?></th>
			</tr>
		</thead>
		<tbody>
			<?php foreach ( $checks as $check ) : ?>
				<tr data-check="<?php echo esc_attr( $check['id'] ); ?>">
					<td><?php echo esc_html( $check['label'] ); ?></td>
					<td class="aicfab-status aicfab-status-<?php echo esc_attr( $check['status'] ); ?>"><?php echo esc_html( isset( $labels[ $check['status'] ] ) ? $labels[ $check['status'] ] : $check['status'] ); ?></td>
					<td><?php echo esc_html( $check['message'] ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Required AWS permissions', 'ai-chat-for-amazon-bedrock' ); ?></h2>
	<p><?php esc_html_e( 'This policy covers exactly what this site is configured to call, and nothing else. Attach it to the IAM user or role the plugin uses instead of a broad managed policy. It changes when you change the model, region or optional features, so copy it again after editing settings.', 'ai-chat-for-amazon-bedrock' ); ?></p>

	<?php if ( '' !== $aicfab_arn ) : ?>
		<p>
			<strong><?php esc_html_e( 'Credentials in use:', 'ai-chat-for-amazon-bedrock' ); ?></strong>
			<code><?php echo esc_html( $aicfab_arn ); ?></code>
		</p>
	<?php else : ?>
		<p class="notice notice-warning inline" style="padding:8px 12px">
			<?php esc_html_e( 'The AWS identity could not be read, so the account ID below is a wildcard. Replace it with your account ID, or grant sts:GetCallerIdentity to have it filled in automatically.', 'ai-chat-for-amazon-bedrock' ); ?>
		</p>
	<?php endif; ?>

	<p>
		<label class="screen-reader-text" for="aicfab-iam-policy"><?php esc_html_e( 'IAM policy for this site', 'ai-chat-for-amazon-bedrock' ); ?></label>
		<textarea id="aicfab-iam-policy" class="large-text code" rows="18" readonly><?php echo esc_textarea( AI_Chat_Bedrock_Iam_Policy::to_json( $aicfab_policy ) ); ?></textarea>
	</p>
	<p>
		<button type="button" class="button" id="aicfab-copy-iam-policy" data-copied="<?php echo esc_attr__( 'Copied to the clipboard.', 'ai-chat-for-amazon-bedrock' ); ?>" data-manual="<?php echo esc_attr__( 'The policy is selected. Copy it with your keyboard.', 'ai-chat-for-amazon-bedrock' ); ?>"><?php esc_html_e( 'Copy policy', 'ai-chat-for-amazon-bedrock' ); ?></button>
		<span id="aicfab-copy-iam-status" role="status"></span>
	</p>
	<p class="description">
		<?php esc_html_e( 'Model access must also be requested in the Amazon Bedrock console for the region above. Permissions alone are not enough: an account without model access is refused even with a correct policy.', 'ai-chat-for-amazon-bedrock' ); ?>
	</p>

	<h2><?php esc_html_e( 'Common fixes', 'ai-chat-for-amazon-bedrock' ); ?></h2>
	<ul>
		<li><?php esc_html_e( 'AccessDeniedException: grant bedrock:InvokeModel for the exact model or inference profile ARN.', 'ai-chat-for-amazon-bedrock' ); ?></li>
		<li><?php esc_html_e( 'ValidationException or model not found: confirm model access in the region and whether a cross-region inference profile ID is required.', 'ai-chat-for-amazon-bedrock' ); ?></li>
		<li><?php esc_html_e( 'Chat works but streaming fails: bedrock:InvokeModelWithResponseStream is a separate action from bedrock:InvokeModel and has to be granted as well.', 'ai-chat-for-amazon-bedrock' ); ?></li>
		<li><?php esc_html_e( 'Streaming unavailable: install the PHP cURL extension, or keep buffered responses.', 'ai-chat-for-amazon-bedrock' ); ?></li>
		<li><?php esc_html_e( 'Using a cross-region inference profile: the underlying foundation model must be allowed in every region the profile routes to, not only the profile itself.', 'ai-chat-for-amazon-bedrock' ); ?></li>
		<li><?php esc_html_e( 'Guardrail configured: bedrock:ApplyGuardrail is required on the guardrail in addition to the model permissions.', 'ai-chat-for-amazon-bedrock' ); ?></li>
		<li><?php esc_html_e( 'Credentials not found: define wp-config.php constants or allow the server IAM role.', 'ai-chat-for-amazon-bedrock' ); ?></li>
	</ul>
</div>

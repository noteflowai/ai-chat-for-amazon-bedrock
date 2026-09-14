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

$diagnostics = new AI_Chat_Bedrock_Diagnostics();
$checks      = $diagnostics->run( false );
$labels      = array(
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

	<h2><?php esc_html_e( 'Common fixes', 'ai-chat-for-amazon-bedrock' ); ?></h2>
	<ul>
		<li><?php esc_html_e( 'AccessDeniedException: grant bedrock:InvokeModel for the exact model or inference profile ARN.', 'ai-chat-for-amazon-bedrock' ); ?></li>
		<li><?php esc_html_e( 'ValidationException or model not found: confirm model access in the region and whether a cross-region inference profile ID is required.', 'ai-chat-for-amazon-bedrock' ); ?></li>
		<li><?php esc_html_e( 'Streaming unavailable: install the PHP cURL extension, or keep buffered responses.', 'ai-chat-for-amazon-bedrock' ); ?></li>
		<li><?php esc_html_e( 'Credentials not found: define wp-config.php constants or allow the server IAM role.', 'ai-chat-for-amazon-bedrock' ); ?></li>
	</ul>
</div>

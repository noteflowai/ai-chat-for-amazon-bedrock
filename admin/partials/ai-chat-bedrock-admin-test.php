<?php
/*
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 *
 * This view is included from inside a class method, so the variables below are method
 * scope rather than globals. The sniff analyses the file on its own and cannot see the
 * include site.
 */
/** Admin chat test view. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$options     = get_option( 'ai_chat_bedrock_settings', array() );
$chat_title  = isset( $options['chat_title'] ) ? $options['chat_title'] : __( 'Chat with AI', 'ai-chat-for-amazon-bedrock' );
$model_name  = isset( $options['model_id'] ) ? $options['model_id'] : '';
$max_tokens  = isset( $options['max_tokens'] ) ? absint( $options['max_tokens'] ) : 1000;
$temperature = isset( $options['temperature'] ) ? (float) $options['temperature'] : 0.7;
?>
<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
	<p><?php esc_html_e( 'This page uses the same secured endpoint as the public shortcode.', 'ai-chat-for-amazon-bedrock' ); ?></p>
	<div id="ai-chat-bedrock-test-interface">
		<?php echo do_shortcode( '[ai_chat_bedrock width="600px" height="500px"]' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
	</div>
	<h2><?php esc_html_e( 'Current model settings', 'ai-chat-for-amazon-bedrock' ); ?></h2>
	<table class="widefat striped"><tbody>
		<tr><th><?php esc_html_e( 'Model', 'ai-chat-for-amazon-bedrock' ); ?></th><td><?php echo esc_html( $model_name ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Maximum tokens', 'ai-chat-for-amazon-bedrock' ); ?></th><td><?php echo esc_html( $max_tokens ); ?></td></tr>
		<tr><th><?php esc_html_e( 'Temperature', 'ai-chat-for-amazon-bedrock' ); ?></th><td><?php echo esc_html( $temperature ); ?></td></tr>
	</tbody></table>
</div>

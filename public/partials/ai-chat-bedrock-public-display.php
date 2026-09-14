<?php
/*
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 *
 * This view is included from inside a class method, so the variables below are method
 * scope rather than globals. The sniff analyses the file on its own and cannot see the
 * include site.
 */
/** Public chat view. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$profile         = isset( $atts['profile'] ) ? AI_Chat_Bedrock_Profiles::sanitize_key( $atts['profile'] ) : '';
$options         = AI_Chat_Bedrock_Profiles::resolve( $profile );
$welcome_message = isset( $options['welcome_message'] ) ? $options['welcome_message'] : __( 'Hello! How can I help you today?', 'ai-chat-for-amazon-bedrock' );
$suggestions     = AI_Chat_Bedrock_Chat_Request::suggestions( $options );
$message_id      = wp_unique_id( 'aicfab-message-' );
$aicfab_mode     = isset( $atts['mode'] ) && 'popup' === $atts['mode'] ? 'popup' : 'inline';
$panel_id        = wp_unique_id( 'aicfab-panel-' );
$launcher        = isset( $atts['launcher'] ) ? $atts['launcher'] : __( 'Chat', 'ai-chat-for-amazon-bedrock' );
?>
<?php if ( 'popup' === $aicfab_mode ) : ?>
<div class="ai-chat-bedrock-popup" data-state="closed">
	<button type="button" class="ai-chat-bedrock-launcher" aria-expanded="false" aria-controls="<?php echo esc_attr( $panel_id ); ?>">
		<span class="ai-chat-bedrock-launcher-icon" aria-hidden="true"></span>
		<span class="ai-chat-bedrock-launcher-label"><?php echo esc_html( $launcher ); ?></span>
	</button>
	<div class="ai-chat-bedrock-popup-panel" id="<?php echo esc_attr( $panel_id ); ?>" hidden>
<?php endif; ?>
<div class="ai-chat-bedrock-container<?php echo 'popup' === $aicfab_mode ? ' is-popup' : ''; ?>" data-profile="<?php echo esc_attr( $profile ); ?>" style="width: <?php echo esc_attr( $atts['width'] ); ?>;">
	<div class="ai-chat-bedrock-header"><h3><?php echo esc_html( $atts['title'] ); ?></h3></div>
	<div class="ai-chat-bedrock-messages" style="height: <?php echo esc_attr( $atts['height'] ); ?>;" aria-live="polite" aria-atomic="false">
		<div class="ai-chat-bedrock-welcome-message">
			<div class="ai-chat-bedrock-message ai-message">
				<div class="ai-chat-bedrock-avatar" aria-hidden="true">AI</div>
				<div class="ai-chat-bedrock-message-content"><?php echo esc_html( $welcome_message ); ?></div>
			</div>
		</div>
	</div>
	<?php if ( ! empty( $suggestions ) ) : ?>
		<div class="ai-chat-bedrock-suggestions" aria-label="<?php esc_attr_e( 'Suggested questions', 'ai-chat-for-amazon-bedrock' ); ?>">
			<?php foreach ( $suggestions as $suggestion ) : ?>
				<button type="button" class="ai-chat-bedrock-suggestion"><?php echo esc_html( $suggestion ); ?></button>
			<?php endforeach; ?>
		</div>
	<?php endif; ?>
	<div class="ai-chat-bedrock-input">
		<form class="ai-chat-bedrock-form">
			<label class="screen-reader-text" for="<?php echo esc_attr( $message_id ); ?>"><?php esc_html_e( 'Chat message', 'ai-chat-for-amazon-bedrock' ); ?></label>
			<textarea id="<?php echo esc_attr( $message_id ); ?>" class="ai-chat-bedrock-textarea" placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>" rows="2" maxlength="4000"></textarea>
			<div class="ai-chat-bedrock-buttons">
				<button type="button" class="ai-chat-bedrock-clear button button-secondary"><?php echo esc_html( $atts['clear_text'] ); ?></button>
				<button type="submit" class="ai-chat-bedrock-submit button button-primary"><?php echo esc_html( $atts['button_text'] ); ?></button>
			</div>
		</form>
	</div>
	<div class="ai-chat-bedrock-footer">
		<small><?php esc_html_e( 'Powered by Amazon Bedrock', 'ai-chat-for-amazon-bedrock' ); ?></small>
		<small class="ai-chat-bedrock-usage" aria-live="polite"></small>
	</div>
</div>
<?php if ( 'popup' === $aicfab_mode ) : ?>
	</div>
</div>
<?php endif; ?>

<?php
/*
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 *
 * This view is included from inside a class method, so the variables below are method
 * scope rather than globals. The sniff analyses the file on its own and cannot see the
 * include site.
 */
/** Content generator view. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'edit_posts' ) ) {
	return;
}

$state          = isset( $_GET['aicfab-generated'] ) ? sanitize_key( wp_unslash( $_GET['aicfab-generated'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$aicfab_post_id = isset( $_GET['post_id'] ) ? absint( wp_unslash( $_GET['post_id'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$draft          = $aicfab_post_id ? get_post( $aicfab_post_id ) : null;
$tones          = AI_Chat_Bedrock_Content_Generator::tones();
$lengths        = AI_Chat_Bedrock_Content_Generator::lengths();
?>
<div class="wrap aicfab-dashboard">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
	<p class="aicfab-lede"><?php esc_html_e( 'Turn a topic into a draft post with your configured Amazon Bedrock model. Output is always saved as a draft for you to review, edit and publish yourself.', 'ai-chat-for-amazon-bedrock' ); ?></p>

	<?php if ( 'draft' === $state && $draft instanceof WP_Post ) : ?>
		<div class="notice notice-success">
			<p>
				<?php
				printf(
					/* translators: %s: draft title. */
					esc_html__( 'Draft created: %s', 'ai-chat-for-amazon-bedrock' ),
					'<strong>' . esc_html( get_the_title( $draft ) ) . '</strong>'
				);
				?>
				<a class="button button-primary" style="margin-left:10px" href="<?php echo esc_url( get_edit_post_link( $draft->ID ) ); ?>"><?php esc_html_e( 'Open in editor', 'ai-chat-for-amazon-bedrock' ); ?></a>
			</p>
		</div>
	<?php elseif ( '' !== $state && 'draft' !== $state ) : ?>
		<div class="notice notice-error">
			<p>
				<?php
				$messages = array(
					'aicfab_missing_topic' => __( 'Enter a topic first.', 'ai-chat-for-amazon-bedrock' ),
					'aicfab_rate_limited'  => __( 'Too many generation requests. Please wait a few minutes.', 'ai-chat-for-amazon-bedrock' ),
					'aicfab_forbidden'     => __( 'You are not allowed to create drafts.', 'ai-chat-for-amazon-bedrock' ),
					'aicfab_daily_limit'   => __( 'The daily Amazon Bedrock request limit for this site has been reached.', 'ai-chat-for-amazon-bedrock' ),
				);
				echo esc_html( isset( $messages[ $state ] ) ? $messages[ $state ] : __( 'The draft could not be generated. Check the diagnostics screen.', 'ai-chat-for-amazon-bedrock' ) );
				?>
			</p>
		</div>
	<?php endif; ?>

	<div class="aicfab-panel" style="max-width: 820px;">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php wp_nonce_field( 'ai_chat_bedrock_generate_content' ); ?>
			<input type="hidden" name="action" value="ai_chat_bedrock_generate_content">
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="aicfab_topic"><?php esc_html_e( 'Topic', 'ai-chat-for-amazon-bedrock' ); ?></label></th>
					<td>
						<input type="text" id="aicfab_topic" name="topic" class="large-text" maxlength="300" required placeholder="<?php esc_attr_e( 'How to choose a refund policy for a small shop', 'ai-chat-for-amazon-bedrock' ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aicfab_tone"><?php esc_html_e( 'Tone', 'ai-chat-for-amazon-bedrock' ); ?></label></th>
					<td>
						<select id="aicfab_tone" name="tone">
							<?php foreach ( $tones as $key => $label ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<label style="margin-left:14px" for="aicfab_length"><?php esc_html_e( 'Length', 'ai-chat-for-amazon-bedrock' ); ?></label>
						<select id="aicfab_length" name="length">
							<?php foreach ( $lengths as $key => $length ) : ?>
								<option value="<?php echo esc_attr( $key ); ?>" <?php selected( 'medium', $key ); ?>><?php echo esc_html( $length['label'] ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aicfab_language"><?php esc_html_e( 'Language', 'ai-chat-for-amazon-bedrock' ); ?></label></th>
					<td>
						<input type="text" id="aicfab_language" name="language" class="regular-text" maxlength="60" placeholder="<?php esc_attr_e( 'Leave empty to match the topic language', 'ai-chat-for-amazon-bedrock' ); ?>">
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="aicfab_notes"><?php esc_html_e( 'Source notes', 'ai-chat-for-amazon-bedrock' ); ?></label></th>
					<td>
						<textarea id="aicfab_notes" name="notes" rows="6" class="large-text" maxlength="2000" placeholder="<?php esc_attr_e( 'Optional facts the draft should rely on. Treated as data, never as instructions.', 'ai-chat-for-amazon-bedrock' ); ?>"></textarea>
						<p class="description"><?php esc_html_e( 'The model is instructed not to invent statistics, quotes, prices, dates or named sources. Review every draft before publishing.', 'ai-chat-for-amazon-bedrock' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Generate draft', 'ai-chat-for-amazon-bedrock' ) ); ?>
		</form>

		<div id="aicfab-generator-stream" class="aicfab-generator-stream" hidden>
			<h2><?php esc_html_e( 'Generated draft', 'ai-chat-for-amazon-bedrock' ); ?></h2>
			<p class="aicfab-generator-status" role="status" aria-live="polite"></p>
			<pre class="aicfab-generator-output" aria-live="off"></pre>
			<p class="aicfab-generator-result"></p>
		</div>
	</div>
</div>

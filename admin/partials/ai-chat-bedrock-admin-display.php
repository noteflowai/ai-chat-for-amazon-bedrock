<?php
/*
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 *
 * This view is included from inside a class method, so the variables below are method
 * scope rather than globals. The sniff analyses the file on its own and cannot see the
 * include site.
 */
/** Dashboard view. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

$options     = get_option( 'ai_chat_bedrock_settings', array() );
$options     = is_array( $options ) ? $options : array();
$credentials = AI_Chat_Bedrock_AWS_Credentials::describe( $options );
$regions     = AI_Chat_Bedrock_Models::regions();
$region      = isset( $options['aws_region'] ) ? (string) $options['aws_region'] : '';
$model       = isset( $options['model_id'] ) ? (string) $options['model_id'] : '';
$streaming   = ( ! isset( $options['enable_streaming'] ) || 'off' !== $options['enable_streaming'] ) && AI_Chat_Bedrock_AWS::streaming_supported();
$mcp_enabled = (bool) get_option( 'ai_chat_bedrock_enable_mcp', false );
$today       = AI_Chat_Bedrock_Usage::today_totals();
$week        = AI_Chat_Bedrock_Usage::totals( 7 );
$daily_limit = AI_Chat_Bedrock_Usage::daily_limit( $options );
$series      = AI_Chat_Bedrock_Usage::daily_series( 7 );
$by_model    = AI_Chat_Bedrock_Usage::by_model( 7 );
$peak        = max( 1, (int) max( wp_list_pluck( $series, 'requests' ) ) );

$settings_url    = admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock-settings' );
$test_url        = admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock-test' );
$mcp_url         = admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock-mcp' );
$diagnostics_url = admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock-diagnostics' );

$aicfab_state = array(
	'options'     => $options,
	'credentials' => $credentials,
	'model'       => $model,
	'region_name' => isset( $regions[ $region ] ) ? $regions[ $region ] : '',
	'requests'    => (int) $today['requests'] + (int) $week['requests'],
);

$steps           = AI_Chat_Bedrock_Setup_Steps::essential( $aicfab_state );
$aicfab_progress = AI_Chat_Bedrock_Setup_Steps::progress( $steps );
$aicfab_next     = AI_Chat_Bedrock_Setup_Steps::next( $aicfab_state );
?>
<div class="wrap aicfab-dashboard">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
	<p class="aicfab-lede"><?php esc_html_e( 'Connect WordPress to Amazon Bedrock with your own AWS account. Streaming answers, least-privilege credentials, and optional MCP tools.', 'ai-chat-for-amazon-bedrock' ); ?></p>

	<div class="aicfab-cards">
		<div class="aicfab-card">
			<h2><?php esc_html_e( 'Credentials', 'ai-chat-for-amazon-bedrock' ); ?></h2>
			<p class="aicfab-pill <?php echo $credentials['configured'] ? 'is-good' : 'is-bad'; ?>">
				<?php echo esc_html( $credentials['configured'] ? __( 'Connected', 'ai-chat-for-amazon-bedrock' ) : __( 'Not configured', 'ai-chat-for-amazon-bedrock' ) ); ?>
			</p>
			<p class="aicfab-card-detail"><?php echo esc_html( $credentials['message'] ); ?></p>
		</div>

		<div class="aicfab-card">
			<h2><?php esc_html_e( 'Model', 'ai-chat-for-amazon-bedrock' ); ?></h2>
			<p class="aicfab-pill <?php echo '' !== $model ? 'is-good' : 'is-warn'; ?>">
				<?php echo esc_html( '' !== $model ? __( 'Selected', 'ai-chat-for-amazon-bedrock' ) : __( 'Not selected', 'ai-chat-for-amazon-bedrock' ) ); ?>
			</p>
			<p class="aicfab-card-detail"><?php echo esc_html( '' !== $model ? $model : __( 'Choose a Bedrock model in the settings.', 'ai-chat-for-amazon-bedrock' ) ); ?></p>
			<p class="aicfab-card-detail"><?php echo esc_html( isset( $regions[ $region ] ) ? $regions[ $region ] : __( 'No region selected', 'ai-chat-for-amazon-bedrock' ) ); ?></p>
		</div>

		<div class="aicfab-card">
			<h2><?php esc_html_e( 'Streaming', 'ai-chat-for-amazon-bedrock' ); ?></h2>
			<p class="aicfab-pill <?php echo $streaming ? 'is-good' : 'is-warn'; ?>">
				<?php echo esc_html( $streaming ? __( 'Enabled', 'ai-chat-for-amazon-bedrock' ) : __( 'Buffered', 'ai-chat-for-amazon-bedrock' ) ); ?>
			</p>
			<p class="aicfab-card-detail">
				<?php echo esc_html( $streaming ? __( 'Answers appear as they are generated.', 'ai-chat-for-amazon-bedrock' ) : __( 'Answers appear when generation finishes.', 'ai-chat-for-amazon-bedrock' ) ); ?>
			</p>
		</div>

		<div class="aicfab-card">
			<h2><?php esc_html_e( 'MCP tools', 'ai-chat-for-amazon-bedrock' ); ?></h2>
			<p class="aicfab-pill <?php echo $mcp_enabled ? 'is-good' : 'is-neutral'; ?>">
				<?php echo esc_html( $mcp_enabled ? __( 'Enabled', 'ai-chat-for-amazon-bedrock' ) : __( 'Disabled', 'ai-chat-for-amazon-bedrock' ) ); ?>
			</p>
			<p class="aicfab-card-detail"><?php esc_html_e( 'Server-side tool execution with per-tool policy and an audit log.', 'ai-chat-for-amazon-bedrock' ); ?></p>
		</div>

		<div class="aicfab-card aicfab-card-usage">
			<h2><?php esc_html_e( 'Usage today', 'ai-chat-for-amazon-bedrock' ); ?></h2>
			<div class="aicfab-metrics">
				<div><span class="aicfab-metric"><?php echo esc_html( number_format_i18n( $today['requests'] ) ); ?></span><span class="aicfab-metric-label"><?php esc_html_e( 'requests', 'ai-chat-for-amazon-bedrock' ); ?></span></div>
				<div><span class="aicfab-metric"><?php echo esc_html( number_format_i18n( $today['input_tokens'] ) ); ?></span><span class="aicfab-metric-label"><?php esc_html_e( 'input tokens', 'ai-chat-for-amazon-bedrock' ); ?></span></div>
				<div><span class="aicfab-metric"><?php echo esc_html( number_format_i18n( $today['output_tokens'] ) ); ?></span><span class="aicfab-metric-label"><?php esc_html_e( 'output tokens', 'ai-chat-for-amazon-bedrock' ); ?></span></div>
			</div>
			<p class="aicfab-card-detail">
				<?php
				if ( $daily_limit > 0 ) {
					printf(
						/* translators: 1: requests today, 2: daily request limit, 3: requests in the last seven days. */
						esc_html__( 'Daily limit: %1$d of %2$d used. Last 7 days: %3$d requests.', 'ai-chat-for-amazon-bedrock' ),
						(int) $today['requests'],
						(int) $daily_limit,
						(int) $week['requests']
					);
				} else {
					printf(
						/* translators: %d: requests in the last seven days. */
						esc_html__( 'No plugin-side daily limit. Last 7 days: %d requests.', 'ai-chat-for-amazon-bedrock' ),
						(int) $week['requests']
					);
				}
				?>
			</p>
		</div>
	</div>

	<div class="aicfab-panel aicfab-usage-panel">
		<h2><?php esc_html_e( 'Usage over the last seven days', 'ai-chat-for-amazon-bedrock' ); ?></h2>

		<?php if ( $week['requests'] < 1 ) : ?>
			<p class="aicfab-card-detail"><?php esc_html_e( 'No Bedrock requests recorded yet.', 'ai-chat-for-amazon-bedrock' ); ?></p>
		<?php else : ?>
			<ul class="aicfab-usage-series">
				<?php foreach ( $series as $row ) : ?>
					<li>
						<span class="aicfab-usage-day"><?php echo esc_html( wp_date( 'M j', strtotime( $row['day'] . ' 00:00:00 UTC' ) ) ); ?></span>
						<?php
						// A day with no requests must read as empty. A minimum width made every
						// idle day look like it had traffic.
						$aicfab_bar = 0 === (int) $row['requests'] ? 0 : max( 2, (int) round( ( $row['requests'] / $peak ) * 100 ) );
						?>
						<span class="aicfab-usage-bar" aria-hidden="true"><span style="width: <?php echo esc_attr( (string) $aicfab_bar ); ?>%"></span></span>
						<span class="aicfab-usage-count">
							<?php
							printf(
								/* translators: 1: request count, 2: input tokens, 3: output tokens. */
								esc_html__( '%1$s requests · %2$s in / %3$s out', 'ai-chat-for-amazon-bedrock' ),
								esc_html( number_format_i18n( $row['requests'] ) ),
								esc_html( number_format_i18n( $row['input_tokens'] ) ),
								esc_html( number_format_i18n( $row['output_tokens'] ) )
							);
							?>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>

			<?php if ( ! empty( $by_model ) ) : ?>
				<table class="widefat striped aicfab-usage-models">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Model', 'ai-chat-for-amazon-bedrock' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Requests', 'ai-chat-for-amazon-bedrock' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Input tokens', 'ai-chat-for-amazon-bedrock' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Output tokens', 'ai-chat-for-amazon-bedrock' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $by_model as $row ) : ?>
							<tr>
								<td><code><?php echo esc_html( $row['model'] ); ?></code></td>
								<td><?php echo esc_html( number_format_i18n( $row['requests'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $row['input_tokens'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $row['output_tokens'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<p class="aicfab-card-detail">
				<?php esc_html_e( 'Counters only, kept for 30 days. Token counts are what Amazon Bedrock reported and are not a price estimate; check AWS Cost Explorer for billing.', 'ai-chat-for-amazon-bedrock' ); ?>
			</p>
		<?php endif; ?>
	</div>

	<div class="aicfab-columns">
		<div class="aicfab-panel">
			<h2><?php esc_html_e( 'Quick start', 'ai-chat-for-amazon-bedrock' ); ?></h2>
			<p class="aicfab-progress<?php echo $aicfab_progress['complete'] ? ' is-complete' : ''; ?>">
				<?php if ( $aicfab_progress['complete'] ) : ?>
					<?php esc_html_e( 'Setup is complete. The chat is live on this site.', 'ai-chat-for-amazon-bedrock' ); ?>
				<?php else : ?>
					<?php
					printf(
						/* translators: 1: steps completed, 2: steps in total. */
						esc_html__( 'Step %1$s of %2$s done.', 'ai-chat-for-amazon-bedrock' ),
						esc_html( number_format_i18n( $aicfab_progress['done'] ) ),
						esc_html( number_format_i18n( $aicfab_progress['total'] ) )
					);
					?>
				<?php endif; ?>
			</p>
			<ol class="aicfab-steps">
				<?php foreach ( $steps as $index => $step ) : ?>
					<li class="<?php echo $step['done'] ? 'is-done' : ''; ?>">
						<span class="aicfab-step-index" aria-hidden="true"><?php echo esc_html( $step['done'] ? '✓' : (string) ( $index + 1 ) ); ?></span>
						<span class="aicfab-step-body">
							<a href="<?php echo esc_url( $step['url'] ); ?>"><?php echo esc_html( $step['label'] ); ?></a>
							<span class="aicfab-step-help"><?php echo esc_html( $step['help'] ); ?></span>
						</span>
					</li>
				<?php endforeach; ?>
			</ol>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $settings_url ); ?>"><?php esc_html_e( 'Open settings', 'ai-chat-for-amazon-bedrock' ); ?></a>
				<a class="button" href="<?php echo esc_url( $diagnostics_url ); ?>"><?php esc_html_e( 'Run diagnostics', 'ai-chat-for-amazon-bedrock' ); ?></a>
				<a class="button" href="<?php echo esc_url( $test_url ); ?>"><?php esc_html_e( 'Test chat', 'ai-chat-for-amazon-bedrock' ); ?></a>
			</p>

			<?php if ( ! empty( $aicfab_next ) ) : ?>
				<h3 class="aicfab-next-heading"><?php esc_html_e( 'Worth doing next', 'ai-chat-for-amazon-bedrock' ); ?></h3>
				<ul class="aicfab-next">
					<?php foreach ( $aicfab_next as $aicfab_suggestion ) : ?>
						<li>
							<a href="<?php echo esc_url( $aicfab_suggestion['url'] ); ?>"><?php echo esc_html( $aicfab_suggestion['label'] ); ?></a>
							<span class="aicfab-step-help"><?php echo esc_html( $aicfab_suggestion['help'] ); ?></span>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php else : ?>
				<p class="aicfab-card-detail"><?php esc_html_e( 'Grounding, logging, a fallback model and request limits are all configured.', 'ai-chat-for-amazon-bedrock' ); ?></p>
			<?php endif; ?>
		</div>

		<div class="aicfab-panel">
			<h2><?php esc_html_e( 'Add the chat to a page', 'ai-chat-for-amazon-bedrock' ); ?></h2>
			<p><?php esc_html_e( 'Insert the Amazon Bedrock Chat block in the editor, or paste this shortcode:', 'ai-chat-for-amazon-bedrock' ); ?></p>
			<p><code>[ai_chat_bedrock]</code></p>
			<p><?php esc_html_e( 'Optional attributes: title, placeholder, width, height.', 'ai-chat-for-amazon-bedrock' ); ?></p>
			<h2><?php esc_html_e( 'Security defaults', 'ai-chat-for-amazon-bedrock' ); ?></h2>
			<ul class="aicfab-list">
				<li><?php esc_html_e( 'Guest chat stays disabled until you enable it.', 'ai-chat-for-amazon-bedrock' ); ?></li>
				<li><?php esc_html_e( 'Requests are rate limited per visitor.', 'ai-chat-for-amazon-bedrock' ); ?></li>
				<li><?php esc_html_e( 'MCP tools run on the server; browsers cannot forge results.', 'ai-chat-for-amazon-bedrock' ); ?></li>
				<li><?php esc_html_e( 'Tools that change data need explicit approval.', 'ai-chat-for-amazon-bedrock' ); ?></li>
			</ul>
			<p><a class="button" href="<?php echo esc_url( $mcp_url ); ?>"><?php esc_html_e( 'Manage MCP tools', 'ai-chat-for-amazon-bedrock' ); ?></a></p>
		</div>
	</div>
</div>

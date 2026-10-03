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
$failed_day  = AI_Chat_Bedrock_Usage::failure_totals( 1 );
$failed_week = AI_Chat_Bedrock_Usage::failure_totals( 7 );
$daily_limit = AI_Chat_Bedrock_Usage::daily_limit( $options );
$series      = AI_Chat_Bedrock_Usage::daily_series( 7 );
$by_model    = AI_Chat_Bedrock_Usage::by_model( 7 );
$peak        = max( 1, (int) max( wp_list_pluck( $series, 'requests' ) ) );

$settings_url    = admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock-settings' );
$test_url        = admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock-test' );
$mcp_url         = admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock-mcp' );
$diagnostics_url = admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock-diagnostics' );

$aicfab_state = array(
	'options'         => $options,
	'credentials'     => $credentials,
	'model'           => $model,
	'region_name'     => isset( $regions[ $region ] ) ? $regions[ $region ] : '',
	'requests'        => (int) $today['requests'] + (int) $week['requests'],
	'ability_sources' => AI_Chat_Bedrock_Abilities::available() ? ( new AI_Chat_Bedrock_Abilities() )->source_labels() : array(),
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
				<div><span class="aicfab-metric"><?php echo esc_html( number_format_i18n( (int) $failed_day['total'] ) ); ?></span><span class="aicfab-metric-label"><?php esc_html_e( 'failed requests', 'ai-chat-for-amazon-bedrock' ); ?></span></div>
			</div>
			<p class="aicfab-card-detail">
				<?php
				$limit_sentence = $daily_limit > 0
					? sprintf(
						/* translators: 1: requests today, 2: daily request limit. */
						__( 'Daily limit: %1$s of %2$s used.', 'ai-chat-for-amazon-bedrock' ),
						number_format_i18n( (int) $today['requests'] ),
						number_format_i18n( (int) $daily_limit )
					)
					: __( 'No plugin-side daily limit.', 'ai-chat-for-amazon-bedrock' );
				echo esc_html(
					AI_Chat_Bedrock_Translation::sentences(
						$limit_sentence,
						sprintf(
							/* translators: %s: requests in the last seven days. */
							_n( 'Last 7 days: %s request.', 'Last 7 days: %s requests.', (int) $week['requests'], 'ai-chat-for-amazon-bedrock' ),
							number_format_i18n( (int) $week['requests'] )
						)
					)
				);
				?>
			</p>
			<?php if ( ! empty( $week['embedding_requests'] ) ) : ?>
				<p class="aicfab-card-detail">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: embedding requests in the last seven days, 2: tokens they read. */
							_n( 'Search and indexing, last 7 days: %1$s embedding request, %2$s tokens. They are not counted above or against the daily limit.', 'Search and indexing, last 7 days: %1$s embedding requests, %2$s tokens. They are not counted above or against the daily limit.', (int) $week['embedding_requests'], 'ai-chat-for-amazon-bedrock' ),
							number_format_i18n( (int) $week['embedding_requests'] ),
							number_format_i18n( (int) $week['embedding_tokens'] )
						)
					);
					?>
				</p>
			<?php endif; ?>
			<?php if ( ! empty( $week['rerank_requests'] ) ) : ?>
				<p class="aicfab-card-detail">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: rerank requests in the last seven days. */
							_n( 'Reranking, last 7 days: %s request. It is not counted above or against the daily limit.', 'Reranking, last 7 days: %s requests. They are not counted above or against the daily limit.', (int) $week['rerank_requests'], 'ai-chat-for-amazon-bedrock' ),
							number_format_i18n( (int) $week['rerank_requests'] )
						)
					);
					?>
				</p>
			<?php endif; ?>
			<?php if ( ! empty( $week['speech_requests'] ) ) : ?>
				<p class="aicfab-card-detail">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: Amazon Polly requests in the last seven days, 2: characters read aloud. */
							_n( 'Read aloud, last 7 days: %1$s Amazon Polly request, %2$s characters. They are not counted above or against the daily limit.', 'Read aloud, last 7 days: %1$s Amazon Polly requests, %2$s characters. They are not counted above or against the daily limit.', (int) $week['speech_requests'], 'ai-chat-for-amazon-bedrock' ),
							number_format_i18n( (int) $week['speech_requests'] ),
							number_format_i18n( (int) $week['speech_characters'] )
						)
					);
					?>
				</p>
			<?php endif; ?>
			<?php if ( ! empty( $week['cache_read_tokens'] ) ) : ?>
				<p class="aicfab-card-detail">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %s: number of input tokens read from the Claude prompt cache in the last seven days. */
							_n( 'Prompt cache, last 7 days: %s input token reused at a tenth of the input price.', 'Prompt cache, last 7 days: %s input tokens reused at a tenth of the input price.', (int) $week['cache_read_tokens'], 'ai-chat-for-amazon-bedrock' ),
							number_format_i18n( (int) $week['cache_read_tokens'] )
						)
					);
					?>
				</p>
			<?php endif; ?>
		</div>
	</div>

	<div class="aicfab-panel aicfab-usage-panel">
		<h2><?php esc_html_e( 'Usage over the last seven days', 'ai-chat-for-amazon-bedrock' ); ?></h2>

		<?php if ( $week['requests'] + $week['embedding_requests'] < 1 ) : ?>
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
							echo esc_html(
								sprintf(
									/* translators: 1: request count, 2: input tokens, 3: output tokens. */
									_n( '%1$s request · %2$s in / %3$s out', '%1$s requests · %2$s in / %3$s out', (int) $row['requests'], 'ai-chat-for-amazon-bedrock' ),
									number_format_i18n( $row['requests'] ),
									number_format_i18n( $row['input_tokens'] ),
									number_format_i18n( $row['output_tokens'] )
								)
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

		<p class="aicfab-card-detail aicfab-usage-failures">
			<?php
			if ( (int) $failed_week['total'] < 1 ) {
				esc_html_e( 'No failed chat requests recorded in the last 7 days.', 'ai-chat-for-amazon-bedrock' );
			} else {
				// The first category starts a sentence, so it has its own capitalized label.
				$aicfab_first_labels = array(
					'throttled'     => _x( 'Throttled', 'failure category, starting a sentence', 'ai-chat-for-amazon-bedrock' ),
					'access_denied' => _x( 'Access denied', 'failure category, starting a sentence', 'ai-chat-for-amazon-bedrock' ),
					'validation'    => _x( 'Rejected request', 'failure category, starting a sentence', 'ai-chat-for-amazon-bedrock' ),
					'unavailable'   => _x( 'Service unavailable', 'failure category, starting a sentence', 'ai-chat-for-amazon-bedrock' ),
					'network'       => _x( 'Network', 'failure category, starting a sentence', 'ai-chat-for-amazon-bedrock' ),
					'other'         => _x( 'Other', 'failure category, starting a sentence', 'ai-chat-for-amazon-bedrock' ),
				);
				$aicfab_later_labels = array(
					'throttled'     => _x( 'throttled', 'failure category, inside a list', 'ai-chat-for-amazon-bedrock' ),
					'access_denied' => _x( 'access denied', 'failure category, inside a list', 'ai-chat-for-amazon-bedrock' ),
					'validation'    => _x( 'rejected request', 'failure category, inside a list', 'ai-chat-for-amazon-bedrock' ),
					'unavailable'   => _x( 'service unavailable', 'failure category, inside a list', 'ai-chat-for-amazon-bedrock' ),
					'network'       => _x( 'network', 'failure category, inside a list', 'ai-chat-for-amazon-bedrock' ),
					'other'         => _x( 'other', 'failure category, inside a list', 'ai-chat-for-amazon-bedrock' ),
				);
				/* translators: 1: failure category name, 2: number of failed chat requests in that category. */
				$aicfab_item_format   = _x( '%1$s %2$s', 'failure category and count', 'ai-chat-for-amazon-bedrock' );
				$aicfab_failure_parts = array();
				foreach ( $failed_week['by_category'] as $aicfab_category => $aicfab_count ) {
					$aicfab_labels = empty( $aicfab_failure_parts ) ? $aicfab_first_labels : $aicfab_later_labels;
					if ( (int) $aicfab_count > 0 && isset( $aicfab_labels[ $aicfab_category ] ) ) {
						$aicfab_failure_parts[] = sprintf( $aicfab_item_format, $aicfab_labels[ $aicfab_category ], number_format_i18n( (int) $aicfab_count ) );
					}
				}
				$aicfab_count_sentence = sprintf(
					/* translators: %s: chat requests that reached Amazon Bedrock and failed in the last seven days. */
					__( 'Failed chat requests, last 7 days: %s.', 'ai-chat-for-amazon-bedrock' ),
					number_format_i18n( (int) $failed_week['total'] )
				);
				if ( empty( $aicfab_failure_parts ) ) {
					echo esc_html( $aicfab_count_sentence );
				} else {
					$aicfab_list_sentence = sprintf(
						/* translators: %s: failure categories with their counts, joined by the list separator. */
						_x( '%s.', 'sentence listing failure categories', 'ai-chat-for-amazon-bedrock' ),
						implode( _x( ', ', 'failure category list separator', 'ai-chat-for-amazon-bedrock' ), $aicfab_failure_parts )
					);
					echo esc_html(
						sprintf(
							/* translators: 1: sentence with the failed request total, 2: sentence listing the failure categories. */
							_x( '%1$s %2$s', 'failed request total then category list', 'ai-chat-for-amazon-bedrock' ),
							$aicfab_count_sentence,
							$aicfab_list_sentence
						)
					);
				}
			}
			?>
		</p>
	</div>

	<div class="aicfab-columns">
		<div class="aicfab-panel">
			<h2><?php esc_html_e( 'Quick start', 'ai-chat-for-amazon-bedrock' ); ?></h2>
			<p class="aicfab-progress<?php echo $aicfab_progress['complete'] ? ' is-complete' : ''; ?>">
				<?php if ( $aicfab_progress['complete'] ) : ?>
					<?php esc_html_e( 'Setup is complete. The chat is live on this site.', 'ai-chat-for-amazon-bedrock' ); ?>
				<?php else : ?>
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: steps completed, 2: steps in total. */
							_n( '%1$s of %2$s step done.', '%1$s of %2$s steps done.', (int) $aicfab_progress['total'], 'ai-chat-for-amazon-bedrock' ),
							number_format_i18n( $aicfab_progress['done'] ),
							number_format_i18n( $aicfab_progress['total'] )
						)
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

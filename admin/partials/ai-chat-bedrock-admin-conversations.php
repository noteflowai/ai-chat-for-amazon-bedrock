<?php
/*
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 *
 * This view is included from inside a class method, so the variables below are method
 * scope rather than globals. The sniff analyses the file on its own and cannot see the
 * include site.
 */
/** Conversation log view. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( 'manage_options' ) ) {
	return;
}

$enabled  = AI_Chat_Bedrock_Conversations::enabled();
$summary  = AI_Chat_Bedrock_Conversations::summary();
$ratings  = AI_Chat_Bedrock_Conversations::ratings();
$settings = admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock-settings' );

// Read-only list filters, so a nonce is not required here.
// phpcs:disable WordPress.Security.NonceVerification.Recommended
$aicfab_search = isset( $_GET['aicfab_s'] ) ? sanitize_text_field( wp_unslash( $_GET['aicfab_s'] ) ) : '';
$source        = isset( $_GET['aicfab_source'] ) ? sanitize_key( wp_unslash( $_GET['aicfab_source'] ) ) : '';
$rating        = isset( $_GET['aicfab_rating'] ) ? sanitize_key( wp_unslash( $_GET['aicfab_rating'] ) ) : '';
$aicfab_paged  = isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1;
$aicfab_log    = isset( $_GET['aicfab-log'] ) ? sanitize_key( wp_unslash( $_GET['aicfab-log'] ) ) : '';
// phpcs:enable WordPress.Security.NonceVerification.Recommended

$sources = array(
	'chat'    => __( 'Chat', 'ai-chat-for-amazon-bedrock' ),
	'stream'  => __( 'Chat, streamed', 'ai-chat-for-amazon-bedrock' ),
	'editor'  => __( 'Editor tools', 'ai-chat-for-amazon-bedrock' ),
	'ability' => __( 'Abilities API', 'ai-chat-for-amazon-bedrock' ),
);
$source  = isset( $sources[ $source ] ) ? $source : '';
$rating  = in_array( $rating, array( 'up', 'down', 'none' ), true ) ? $rating : '';
// Times follow the site's date and time formats, as they do everywhere else in the admin.
$aicfab_datetime = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

$results    = AI_Chat_Bedrock_Conversations::query(
	array(
		'search'   => $aicfab_search,
		'source'   => $source,
		'rating'   => $rating,
		'page'     => $aicfab_paged,
		'per_page' => 20,
	)
);
$entries    = $results['entries'];
$page_base  = admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock-conversations' );
$filter_url = add_query_arg(
	array_filter(
		array(
			'aicfab_s'      => $aicfab_search,
			'aicfab_source' => $source,
			'aicfab_rating' => $rating,
		),
		static function ( $value ) {
			return '' !== $value;
		}
	),
	$page_base
);
?>
<div class="wrap aicfab-dashboard">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<?php if ( 'cleared' === $aicfab_log ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'All stored conversations were deleted.', 'ai-chat-for-amazon-bedrock' ); ?></p></div>
	<?php endif; ?>

	<?php if ( ! $enabled ) : ?>
		<div class="notice notice-info">
			<p>
				<?php esc_html_e( 'Conversation logging is disabled, so no chat content is stored. Enable it in the settings if you need to review answers for quality or moderation.', 'ai-chat-for-amazon-bedrock' ); ?>
				<a href="<?php echo esc_url( $settings ); ?>"><?php esc_html_e( 'Open settings', 'ai-chat-for-amazon-bedrock' ); ?></a>
			</p>
		</div>
	<?php else : ?>
		<p class="aicfab-lede">
			<?php
			$aicfab_days = (int) AI_Chat_Bedrock_Conversations::retention_days();
			echo esc_html(
				sprintf(
					/* translators: 1: number of stored exchanges, such as "12 exchanges", 2: retention, such as "30 days". */
					__( 'Storing the most recent %1$s for up to %2$s. Inform your visitors that chat content is recorded.', 'ai-chat-for-amazon-bedrock' ),
					/* translators: %s: number of stored exchanges. */
					sprintf( _n( '%s exchange', '%s exchanges', (int) $summary['count'], 'ai-chat-for-amazon-bedrock' ), number_format_i18n( (int) $summary['count'] ) ),
					/* translators: %s: number of days. */
					sprintf( _n( '%s day', '%s days', $aicfab_days, 'ai-chat-for-amazon-bedrock' ), number_format_i18n( $aicfab_days ) )
				)
			);
			?>
		</p>
	<?php endif; ?>

	<div class="aicfab-cards">
		<div class="aicfab-card">
			<h2><?php esc_html_e( 'Content gaps', 'ai-chat-for-amazon-bedrock' ); ?></h2>
			<?php
			$aicfab_gap_summary = AI_Chat_Bedrock_Insights::summary( 30 );
			$aicfab_gaps        = AI_Chat_Bedrock_Insights::content_gaps( array( 'days' => 30 ) );
			?>
			<p>
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: number of questions asked, 2: number with no site content behind them, 3: percentage, 4: number of days. */
						_n( 'Of %1$s question in the last %4$s days, %2$s had no site content behind the answer (%3$s%%). Those are subjects visitors expect you to cover.', 'Of %1$s questions in the last %4$s days, %2$s had no site content behind the answer (%3$s%%). Those are subjects visitors expect you to cover.', (int) $aicfab_gap_summary['asked'], 'ai-chat-for-amazon-bedrock' ),
						number_format_i18n( $aicfab_gap_summary['asked'] ),
						number_format_i18n( $aicfab_gap_summary['ungrounded'] ),
						number_format_i18n( $aicfab_gap_summary['percent'] ),
						number_format_i18n( $aicfab_gap_summary['days'] )
					)
				);
				?>
			</p>

			<?php if ( empty( $aicfab_gaps ) ) : ?>
				<p class="description">
					<?php esc_html_e( 'Nothing to report yet. Gaps appear once visitors ask something the site has no content for, or mark an answer unhelpful.', 'ai-chat-for-amazon-bedrock' ); ?>
				</p>
			<?php else : ?>
				<div class="aicfab-table-scroll">
				<table class="widefat striped">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Question', 'ai-chat-for-amazon-bedrock' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Asked', 'ai-chat-for-amazon-bedrock' ); ?></th>
							<th scope="col"><?php esc_html_e( 'No content found', 'ai-chat-for-amazon-bedrock' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Marked unhelpful', 'ai-chat-for-amazon-bedrock' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Action', 'ai-chat-for-amazon-bedrock' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $aicfab_gaps as $aicfab_gap ) : ?>
						<tr>
							<td><?php echo esc_html( $aicfab_gap['question'] ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $aicfab_gap['asked'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $aicfab_gap['ungrounded'] ) ); ?></td>
							<td><?php echo esc_html( number_format_i18n( $aicfab_gap['disliked'] ) ); ?></td>
							<td>
								<?php if ( current_user_can( 'edit_posts' ) ) : ?>
									<?php
									/* translators: %s: the question a draft would be written about. */
									$aicfab_gap_label = sprintf( __( 'Draft an answer about: %s', 'ai-chat-for-amazon-bedrock' ), $aicfab_gap['question'] );
									?>
									<a class="button button-small"
										href="<?php echo esc_url( AI_Chat_Bedrock_Insights::draft_link( $aicfab_gap['question'] ) ); ?>"
										aria-label="<?php echo esc_attr( $aicfab_gap_label ); ?>">
										<?php esc_html_e( 'Draft an answer', 'ai-chat-for-amazon-bedrock' ); ?>
									</a>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				</div>
				<p class="description">
					<?php esc_html_e( 'Questions built from the same significant words are counted as one gap, in any order. Drafting opens the content generator with the subject filled in; the draft is yours to review before publishing.', 'ai-chat-for-amazon-bedrock' ); ?>
				</p>
				<?php // Only offered when there is something to download: an empty file would be a header row alone. ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="aicfab-gap-export">
					<?php wp_nonce_field( 'ai_chat_bedrock_export_gaps' ); ?>
					<input type="hidden" name="action" value="ai_chat_bedrock_export_gaps">
					<button type="submit" class="button"><?php esc_html_e( 'Download content gaps CSV', 'ai-chat-for-amazon-bedrock' ); ?></button>
				</form>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Stored exchanges', 'ai-chat-for-amazon-bedrock' ); ?></h2>
			<div class="aicfab-metrics">
				<div><span class="aicfab-metric"><?php echo esc_html( number_format_i18n( $summary['count'] ) ); ?></span><span class="aicfab-metric-label"><?php esc_html_e( 'entries', 'ai-chat-for-amazon-bedrock' ); ?></span></div>
				<div><span class="aicfab-metric"><?php echo esc_html( number_format_i18n( $summary['input_tokens'] ) ); ?></span><span class="aicfab-metric-label"><?php esc_html_e( 'input tokens', 'ai-chat-for-amazon-bedrock' ); ?></span></div>
				<div><span class="aicfab-metric"><?php echo esc_html( number_format_i18n( $summary['output_tokens'] ) ); ?></span><span class="aicfab-metric-label"><?php esc_html_e( 'output tokens', 'ai-chat-for-amazon-bedrock' ); ?></span></div>
				<div><span class="aicfab-metric"><?php echo esc_html( number_format_i18n( $ratings['up'] ) ); ?></span><span class="aicfab-metric-label"><?php esc_html_e( 'rated helpful', 'ai-chat-for-amazon-bedrock' ); ?></span></div>
				<div><span class="aicfab-metric"><?php echo esc_html( number_format_i18n( $ratings['down'] ) ); ?></span><span class="aicfab-metric-label"><?php esc_html_e( 'rated unhelpful', 'ai-chat-for-amazon-bedrock' ); ?></span></div>
			</div>
			<?php if ( $summary['oldest'] ) : ?>
				<p class="aicfab-card-detail">
					<?php
					printf(
						/* translators: %s: date of the oldest stored entry. */
						esc_html__( 'Oldest entry: %s', 'ai-chat-for-amazon-bedrock' ),
						esc_html( wp_date( $aicfab_datetime, (int) $summary['oldest'] ) )
					);
					?>
				</p>
			<?php endif; ?>
		</div>
	</div>

	<?php if ( $enabled ) : ?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="aicfab-log-filters">
			<input type="hidden" name="page" value="ai-chat-for-amazon-bedrock-conversations">
			<label class="screen-reader-text" for="aicfab-log-search"><?php esc_html_e( 'Search conversations', 'ai-chat-for-amazon-bedrock' ); ?></label>
			<input type="search" id="aicfab-log-search" name="aicfab_s" value="<?php echo esc_attr( $aicfab_search ); ?>" placeholder="<?php esc_attr_e( 'Search questions and answers', 'ai-chat-for-amazon-bedrock' ); ?>" class="regular-text">

			<label class="screen-reader-text" for="aicfab-log-source"><?php esc_html_e( 'Source', 'ai-chat-for-amazon-bedrock' ); ?></label>
			<select id="aicfab-log-source" name="aicfab_source">
				<option value=""><?php esc_html_e( 'All sources', 'ai-chat-for-amazon-bedrock' ); ?></option>
				<?php foreach ( $sources as $option => $aicfab_source_label ) : ?>
					<option value="<?php echo esc_attr( $option ); ?>" <?php selected( $source, $option ); ?>><?php echo esc_html( $aicfab_source_label ); ?></option>
				<?php endforeach; ?>
			</select>

			<label class="screen-reader-text" for="aicfab-log-rating"><?php esc_html_e( 'Rating', 'ai-chat-for-amazon-bedrock' ); ?></label>
			<select id="aicfab-log-rating" name="aicfab_rating">
				<option value=""><?php esc_html_e( 'Any rating', 'ai-chat-for-amazon-bedrock' ); ?></option>
				<option value="up" <?php selected( $rating, 'up' ); ?>><?php esc_html_e( 'Helpful', 'ai-chat-for-amazon-bedrock' ); ?></option>
				<option value="down" <?php selected( $rating, 'down' ); ?>><?php esc_html_e( 'Not helpful', 'ai-chat-for-amazon-bedrock' ); ?></option>
				<option value="none" <?php selected( $rating, 'none' ); ?>><?php esc_html_e( 'Not rated', 'ai-chat-for-amazon-bedrock' ); ?></option>
			</select>

			<button type="submit" class="button"><?php esc_html_e( 'Filter', 'ai-chat-for-amazon-bedrock' ); ?></button>
			<?php if ( '' !== $aicfab_search || '' !== $source || '' !== $rating ) : ?>
				<a class="button-link" href="<?php echo esc_url( $page_base ); ?>"><?php esc_html_e( 'Reset', 'ai-chat-for-amazon-bedrock' ); ?></a>
			<?php endif; ?>
		</form>

		<div class="aicfab-log-actions">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ai_chat_bedrock_export_conversations' ); ?>
				<input type="hidden" name="action" value="ai_chat_bedrock_export_conversations">
				<button type="submit" class="button"><?php esc_html_e( 'Export CSV', 'ai-chat-for-amazon-bedrock' ); ?></button>
			</form>
			<?php if ( $summary['count'] > 0 ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-aicfab-confirm="<?php esc_attr_e( 'Delete every stored conversation? This cannot be undone.', 'ai-chat-for-amazon-bedrock' ); ?>">
					<?php wp_nonce_field( 'ai_chat_bedrock_clear_conversations' ); ?>
					<input type="hidden" name="action" value="ai_chat_bedrock_clear_conversations">
					<button type="submit" class="button"><?php esc_html_e( 'Delete all stored conversations', 'ai-chat-for-amazon-bedrock' ); ?></button>
				</form>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $entries ) ) : ?>
		<p class="aicfab-log-count">
			<?php
			echo esc_html(
				sprintf(
					/* translators: 1: number of matching entries, 2: current page, 3: total pages. */
					_n( '%1$s matching entry, page %2$s of %3$s.', '%1$s matching entries, page %2$s of %3$s.', (int) $results['total'], 'ai-chat-for-amazon-bedrock' ),
					number_format_i18n( (int) $results['total'] ),
					number_format_i18n( (int) $results['page'] ),
					number_format_i18n( (int) $results['pages'] )
				)
			);
			?>
		</p>

		<div class="aicfab-table-scroll">
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Time', 'ai-chat-for-amazon-bedrock' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Source', 'ai-chat-for-amazon-bedrock' ); ?></th>
					<th scope="col"><?php esc_html_e( 'User', 'ai-chat-for-amazon-bedrock' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Question', 'ai-chat-for-amazon-bedrock' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Answer', 'ai-chat-for-amazon-bedrock' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Rating', 'ai-chat-for-amazon-bedrock' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Tokens (in / out)', 'ai-chat-for-amazon-bedrock' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entries as $entry ) : ?>
					<?php
					$user   = ! empty( $entry['user'] ) ? get_userdata( (int) $entry['user'] ) : null;
					$score  = isset( $entry['rating'] ) ? (int) $entry['rating'] : 0;
					$symbol = 1 === $score ? __( 'Helpful', 'ai-chat-for-amazon-bedrock' ) : ( -1 === $score ? __( 'Not helpful', 'ai-chat-for-amazon-bedrock' ) : '—' );
					?>
					<tr>
						<td><?php echo esc_html( wp_date( $aicfab_datetime, (int) $entry['time'] ) ); ?></td>
						<td><?php echo esc_html( isset( $sources[ $entry['source'] ] ) ? $sources[ $entry['source'] ] : $entry['source'] ); ?></td>
						<td><?php echo esc_html( $user ? $user->display_name : __( 'Guest', 'ai-chat-for-amazon-bedrock' ) ); ?></td>
						<td><?php echo esc_html( wp_trim_words( $entry['question'], 22 ) ); ?></td>
						<td><?php echo esc_html( wp_trim_words( $entry['answer'], 28 ) ); ?></td>
						<td><?php echo esc_html( $symbol ); ?></td>
						<td><?php echo esc_html( (int) $entry['input_tokens'] . ' / ' . (int) $entry['output_tokens'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		</div>

		<?php if ( $results['pages'] > 1 ) : ?>
			<div class="tablenav"><div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%', $filter_url ),
							'format'    => '',
							'current'   => (int) $results['page'],
							'total'     => (int) $results['pages'],
							'prev_text' => __( 'Previous', 'ai-chat-for-amazon-bedrock' ),
							'next_text' => __( 'Next', 'ai-chat-for-amazon-bedrock' ),
						)
					)
				);
				?>
			</div></div>
		<?php endif; ?>
	<?php elseif ( $enabled ) : ?>
		<p class="aicfab-tool-log-empty">
			<?php
			if ( '' !== $aicfab_search || '' !== $source || '' !== $rating ) {
				esc_html_e( 'No conversations match these filters.', 'ai-chat-for-amazon-bedrock' );
			} else {
				esc_html_e( 'No conversations recorded yet.', 'ai-chat-for-amazon-bedrock' );
			}
			?>
		</p>
	<?php endif; ?>
</div>

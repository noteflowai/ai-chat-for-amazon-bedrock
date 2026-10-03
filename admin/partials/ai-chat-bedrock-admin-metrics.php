<?php
/**
 * Business insights screen: one figure for a period, compared, split over time and broken
 * down, or asked for in words.
 *
 * The screen only reads. Every figure comes from AI_Chat_Bedrock_Metrics::query(), which
 * checks the query and the person's capabilities and withholds small counts.
 *
 * @package AI_Chat_Bedrock
 */

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( AI_Chat_Bedrock_Metrics::CAPABILITY ) ) {
	wp_die( esc_html__( 'You are not allowed to see this metric.', 'ai-chat-for-amazon-bedrock' ) );
}

$aicfab_page       = $this->plugin_name . '-metrics';
$aicfab_screen_url = admin_url( 'admin.php?page=' . $aicfab_page );
$aicfab_available  = AI_Chat_Bedrock_Metrics::available( 'analytics' );
$aicfab_dimensions = AI_Chat_Bedrock_Metrics::dimensions();
// A read-only view: the query in the address only chooses which figure is shown.
$aicfab_args      = AI_Chat_Bedrock_Admin::metrics_query_args( wp_unslash( $_GET ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$aicfab_asked     = isset( $_GET['aicfab_asked'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$aicfab_ask_error = isset( $_GET['aicfab_ask_error'] ) ? sanitize_key( wp_unslash( $_GET['aicfab_ask_error'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$aicfab_dropped   = '';

// The form lists every dimension; one the chosen metric lacks is dropped here rather than refused.
if ( isset( $aicfab_args['metric'], $aicfab_args['dimension'], $aicfab_available[ $aicfab_args['metric'] ] ) && 'none' !== $aicfab_args['dimension'] && ! in_array( $aicfab_args['dimension'], $aicfab_available[ $aicfab_args['metric'] ]['dimensions'], true ) ) {
	$aicfab_dropped = isset( $aicfab_dimensions[ $aicfab_args['dimension'] ] ) ? $aicfab_dimensions[ $aicfab_args['dimension'] ] : $aicfab_args['dimension'];
	unset( $aicfab_args['dimension'] );
}

$aicfab_result = null;
if ( ! empty( $aicfab_args['metric'] ) ) {
	$aicfab_result = AI_Chat_Bedrock_Metrics::query( $aicfab_args, 'analytics' );
}
$aicfab_query = is_array( $aicfab_result ) ? $aicfab_result['query'] : array_merge(
	array(
		'metric'    => '',
		'period'    => 'last_30_days',
		'after'     => '',
		'before'    => '',
		'compare'   => 'previous_period',
		'interval'  => 'none',
		'dimension' => 'none',
		'limit'     => AI_Chat_Bedrock_Metrics::DEFAULT_LIMIT,
	),
	$aicfab_args
);

$aicfab_ask_errors = array(
	'aicfab_not_a_metric'     => __( 'That question is not one these metrics can answer. Ask about one of the figures listed on this screen.', 'ai-chat-for-amazon-bedrock' ),
	'aicfab_bad_plan'         => __( 'The question could not be turned into a query. Please rephrase it.', 'ai-chat-for-amazon-bedrock' ),
	'aicfab_bad_value'        => __( 'The question could not be turned into a query. Please rephrase it.', 'ai-chat-for-amazon-bedrock' ),
	'aicfab_bad_dates'        => __( 'The dates in the question could not be used. A period can cover at most 366 days and cannot start in the future.', 'ai-chat-for-amazon-bedrock' ),
	'aicfab_missing_question' => __( 'Type a question first.', 'ai-chat-for-amazon-bedrock' ),
	'aicfab_rate_limited'     => __( 'Too many requests. Please wait a moment.', 'ai-chat-for-amazon-bedrock' ),
	'aicfab_forbidden'        => __( 'You are not allowed to see this metric.', 'ai-chat-for-amazon-bedrock' ),
);

/**
 * One figure, its earlier period and the change, as a card.
 *
 * @param array  $result Result from query().
 * @param string $link   Address of the detail view, or empty.
 */
$aicfab_card = static function ( $result, $link = '' ) {
	$currency = isset( $result['currency'] ) ? $result['currency'] : '';
	echo '<div class="aicfab-metric-card">';
	echo '<h3 class="aicfab-metric-label">';
	if ( '' !== $link ) {
		echo '<a href="' . esc_url( $link ) . '">' . esc_html( $result['label'] ) . '</a>';
	} else {
		echo esc_html( $result['label'] );
	}
	echo '</h3>';
	echo '<p class="aicfab-metric-value">' . esc_html( AI_Chat_Bedrock_Metrics::format_value( $result['value'], $result['unit'], $currency ) ) . '</p>';
	if ( $result['hidden'] ) {
		echo '<p class="aicfab-metric-note">' . esc_html__( 'Withheld, so that no one can be picked out.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
	}
	if ( isset( $result['compare'] ) ) {
		$compare = $result['compare'];
		echo '<p class="aicfab-metric-change">';
		if ( null !== $compare['change_percent'] ) {
			$class = $compare['change_percent'] > 0 ? 'up' : ( $compare['change_percent'] < 0 ? 'down' : 'flat' );
			$arrow = 'up' === $class ? '▲' : ( 'down' === $class ? '▼' : '' );
			echo '<span class="aicfab-change-' . esc_attr( $class ) . '" aria-hidden="true">' . esc_html( $arrow ) . '</span> ';
			echo esc_html( ( $compare['change_percent'] > 0 ? '+' : '' ) . number_format_i18n( $compare['change_percent'], 1 ) . '%' ) . ' ';
		} elseif ( null !== $compare['change'] && 0 !== $compare['change'] && 0.0 !== $compare['change'] ) {
			echo esc_html( ( $compare['change'] > 0 ? '+' : '' ) . AI_Chat_Bedrock_Metrics::format_value( $compare['change'], $result['unit'], $currency ) ) . ' ';
		}
		printf(
			/* translators: 1: earlier value, 2: first day, 3: last day. */
			esc_html__( 'against %1$s (%2$s – %3$s)', 'ai-chat-for-amazon-bedrock' ),
			esc_html( AI_Chat_Bedrock_Metrics::format_value( $compare['value'], $result['unit'], $currency ) ),
			esc_html( $compare['after'] ),
			esc_html( $compare['before'] )
		);
		echo '</p>';
	}
	echo '</div>';
};
?>
<div class="wrap aicfab-metrics">
	<h1><?php esc_html_e( 'Business insights', 'ai-chat-for-amazon-bedrock' ); ?></h1>
	<p class="aicfab-metrics-intro">
		<?php esc_html_e( 'Figures for the site over a period, read from data it already keeps: published content, the conversation log, AI usage and, with WooCommerce, its Analytics. Figures counted from fewer than five questions or orders are withheld, so that no one can be picked out.', 'ai-chat-for-amazon-bedrock' ); ?>
	</p>

	<?php if ( empty( $aicfab_available ) ) : ?>
		<div class="notice notice-info inline"><p><?php esc_html_e( 'Your account cannot see any of these figures.', 'ai-chat-for-amazon-bedrock' ); ?></p></div>
	</div>
		<?php
		return;
	endif;
	?>

	<div class="aicfab-panel aicfab-metrics-ask">
		<h2><?php esc_html_e( 'Ask in words', 'ai-chat-for-amazon-bedrock' ); ?></h2>
		<?php if ( '' !== $aicfab_ask_error ) : ?>
			<div class="notice notice-warning inline" role="alert"><p><?php echo esc_html( isset( $aicfab_ask_errors[ $aicfab_ask_error ] ) ? $aicfab_ask_errors[ $aicfab_ask_error ] : __( 'The request could not be completed.', 'ai-chat-for-amazon-bedrock' ) ); ?></p></div>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ai_chat_bedrock_metrics_ask">
			<?php wp_nonce_field( 'ai_chat_bedrock_metrics_ask' ); ?>
			<label for="aicfab-question" class="screen-reader-text"><?php esc_html_e( 'Question', 'ai-chat-for-amazon-bedrock' ); ?></label>
			<input type="text" class="large-text" id="aicfab-question" name="aicfab_question" maxlength="<?php echo esc_attr( AI_Chat_Bedrock_Metrics::MAX_QUESTION ); ?>" placeholder="<?php esc_attr_e( 'For example: net sales by week this quarter, against last year', 'ai-chat-for-amazon-bedrock' ); ?>" aria-describedby="aicfab-question-note">
			<?php submit_button( __( 'Ask', 'ai-chat-for-amazon-bedrock' ), 'secondary', 'submit', false ); ?>
		</form>
		<p class="description" id="aicfab-question-note"><?php esc_html_e( 'Amazon Bedrock reads your question and the list of figures below, and chooses one query. It never sees a figure; the query is checked and run here, and shown so you can see how the question was read.', 'ai-chat-for-amazon-bedrock' ); ?></p>
	</div>

	<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="aicfab-metrics-form">
		<input type="hidden" name="page" value="<?php echo esc_attr( $aicfab_page ); ?>">
		<p>
			<label for="aicfab-metric"><?php esc_html_e( 'Figure', 'ai-chat-for-amazon-bedrock' ); ?></label>
			<select id="aicfab-metric" name="metric">
				<?php foreach ( $aicfab_available as $aicfab_id => $aicfab_definition ) : ?>
					<option value="<?php echo esc_attr( $aicfab_id ); ?>" <?php selected( $aicfab_query['metric'], $aicfab_id ); ?>><?php echo esc_html( $aicfab_definition['label'] ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="aicfab-period"><?php esc_html_e( 'Period', 'ai-chat-for-amazon-bedrock' ); ?></label>
			<select id="aicfab-period" name="period">
				<?php foreach ( AI_Chat_Bedrock_Metrics::periods() as $aicfab_id => $aicfab_label ) : ?>
					<option value="<?php echo esc_attr( $aicfab_id ); ?>" <?php selected( $aicfab_query['period'], $aicfab_id ); ?>><?php echo esc_html( $aicfab_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="aicfab-after"><?php esc_html_e( 'From', 'ai-chat-for-amazon-bedrock' ); ?></label>
			<input type="date" id="aicfab-after" name="after" value="<?php echo esc_attr( 'custom' === $aicfab_query['period'] ? $aicfab_query['after'] : '' ); ?>" aria-describedby="aicfab-dates-note">
			<label for="aicfab-before"><?php esc_html_e( 'To', 'ai-chat-for-amazon-bedrock' ); ?></label>
			<input type="date" id="aicfab-before" name="before" value="<?php echo esc_attr( 'custom' === $aicfab_query['period'] ? $aicfab_query['before'] : '' ); ?>" aria-describedby="aicfab-dates-note">
		</p>
		<p>
			<label for="aicfab-compare"><?php esc_html_e( 'Compare with', 'ai-chat-for-amazon-bedrock' ); ?></label>
			<select id="aicfab-compare" name="compare">
				<?php foreach ( AI_Chat_Bedrock_Metrics::comparisons() as $aicfab_id => $aicfab_label ) : ?>
					<option value="<?php echo esc_attr( $aicfab_id ); ?>" <?php selected( $aicfab_query['compare'], $aicfab_id ); ?>><?php echo esc_html( $aicfab_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="aicfab-interval"><?php esc_html_e( 'Over time', 'ai-chat-for-amazon-bedrock' ); ?></label>
			<select id="aicfab-interval" name="interval">
				<?php foreach ( AI_Chat_Bedrock_Metrics::intervals() as $aicfab_id => $aicfab_label ) : ?>
					<option value="<?php echo esc_attr( $aicfab_id ); ?>" <?php selected( $aicfab_query['interval'], $aicfab_id ); ?>><?php echo esc_html( $aicfab_label ); ?></option>
				<?php endforeach; ?>
			</select>
		</p>
		<p>
			<label for="aicfab-dimension"><?php esc_html_e( 'Break down by', 'ai-chat-for-amazon-bedrock' ); ?></label>
			<select id="aicfab-dimension" name="dimension">
				<option value="none" <?php selected( $aicfab_query['dimension'], 'none' ); ?>><?php esc_html_e( 'Nothing', 'ai-chat-for-amazon-bedrock' ); ?></option>
				<?php foreach ( $aicfab_dimensions as $aicfab_id => $aicfab_label ) : ?>
					<option value="<?php echo esc_attr( $aicfab_id ); ?>" <?php selected( $aicfab_query['dimension'], $aicfab_id ); ?>><?php echo esc_html( $aicfab_label ); ?></option>
				<?php endforeach; ?>
			</select>
			<label for="aicfab-limit"><?php esc_html_e( 'Rows', 'ai-chat-for-amazon-bedrock' ); ?></label>
			<input type="number" id="aicfab-limit" name="limit" min="1" max="<?php echo esc_attr( AI_Chat_Bedrock_Metrics::MAX_LIMIT ); ?>" value="<?php echo esc_attr( $aicfab_query['limit'] ); ?>" class="small-text">
		</p>
		<p>
			<?php submit_button( __( 'Show', 'ai-chat-for-amazon-bedrock' ), 'primary', '', false ); ?>
		</p>
		<p class="description" id="aicfab-dates-note">
			<?php
			printf(
				/* translators: %s: time zone name. */
				esc_html__( 'From and To apply to Custom dates and include both days. Days follow the site\'s time zone, %s.', 'ai-chat-for-amazon-bedrock' ),
				esc_html( AI_Chat_Bedrock_Metrics::timezone()->getName() )
			);
			?>
		</p>
	</form>

	<?php if ( '' !== $aicfab_dropped ) : ?>
		<div class="notice notice-info inline"><p>
			<?php
			printf(
				/* translators: %s: dimension name, such as Product. */
				esc_html__( 'This figure cannot be broken down by %s, so it is shown without a breakdown.', 'ai-chat-for-amazon-bedrock' ),
				esc_html( $aicfab_dropped )
			);
			?>
		</p></div>
	<?php endif; ?>

	<?php if ( is_wp_error( $aicfab_result ) ) : ?>
		<div class="notice notice-error inline" role="alert"><p><?php echo esc_html( $aicfab_result->get_error_message() ); ?></p></div>
	<?php elseif ( is_array( $aicfab_result ) ) : ?>
		<?php
		$aicfab_currency = isset( $aicfab_result['currency'] ) ? $aicfab_result['currency'] : '';
		if ( $aicfab_asked ) :
			?>
			<p class="aicfab-metrics-read-as">
				<?php
				printf(
					/* translators: %s: the query in words, such as "Net sales · Last month". */
					esc_html__( 'Your question was read as: %s', 'ai-chat-for-amazon-bedrock' ),
					'<strong>' . esc_html( AI_Chat_Bedrock_Metrics::summarize( $aicfab_result['query'] ) ) . '</strong>'
				);
				?>
			</p>
		<?php endif; ?>

		<h2>
			<?php
			printf(
				/* translators: 1: first day, 2: last day. */
				esc_html__( '%1$s – %2$s', 'ai-chat-for-amazon-bedrock' ),
				esc_html( $aicfab_result['period']['after'] ),
				esc_html( $aicfab_result['period']['before'] )
			);
			?>
		</h2>
		<p class="description"><?php echo esc_html( $aicfab_result['description'] ); ?></p>
		<div class="aicfab-metric-cards">
			<?php $aicfab_card( $aicfab_result ); ?>
		</div>

		<?php
		if ( ! empty( $aicfab_result['series'] ) ) :
			$aicfab_series = $aicfab_result['series'];
			$aicfab_values = array_filter(
				array_column( $aicfab_series, 'value' ),
				static function ( $value ) {
					return null !== $value;
				}
			);
			$aicfab_max    = empty( $aicfab_values ) ? 0 : max( $aicfab_values );
			$aicfab_count  = count( $aicfab_series );
			$aicfab_width  = max( 320, $aicfab_count * 16 );
			$aicfab_bar    = $aicfab_width / $aicfab_count;
			$aicfab_height = 160;
			?>
			<h3><?php echo esc_html( AI_Chat_Bedrock_Metrics::intervals()[ $aicfab_result['query']['interval'] ] ); ?></h3>
			<figure class="aicfab-metric-chart">
				<svg viewBox="0 0 <?php echo esc_attr( $aicfab_width ); ?> <?php echo esc_attr( $aicfab_height ); ?>" preserveAspectRatio="none" role="img" aria-labelledby="aicfab-chart-title">
					<title id="aicfab-chart-title"><?php echo esc_html( $aicfab_result['label'] ); ?></title>
					<?php
					foreach ( $aicfab_series as $aicfab_index => $aicfab_item ) :
						$aicfab_x     = $aicfab_index * $aicfab_bar;
						$aicfab_text  = $aicfab_item['start'] . ( $aicfab_item['start'] !== $aicfab_item['end'] ? ' – ' . $aicfab_item['end'] : '' ) . ': ';
						$aicfab_text .= $aicfab_item['hidden'] ? __( 'withheld', 'ai-chat-for-amazon-bedrock' ) : AI_Chat_Bedrock_Metrics::format_value( $aicfab_item['value'], $aicfab_result['unit'], $aicfab_currency );
						if ( $aicfab_item['hidden'] ) :
							?>
							<rect class="aicfab-bar-withheld" x="<?php echo esc_attr( round( $aicfab_x + 1, 2 ) ); ?>" y="<?php echo esc_attr( $aicfab_height - 6 ); ?>" width="<?php echo esc_attr( round( max( 1, $aicfab_bar - 2 ), 2 ) ); ?>" height="6"><title><?php echo esc_html( $aicfab_text ); ?></title></rect>
							<?php
						else :
							$aicfab_h = $aicfab_max > 0 && null !== $aicfab_item['value'] ? max( 1, ( $aicfab_item['value'] / $aicfab_max ) * ( $aicfab_height - 4 ) ) : 1;
							?>
							<rect class="aicfab-bar" x="<?php echo esc_attr( round( $aicfab_x + 1, 2 ) ); ?>" y="<?php echo esc_attr( round( $aicfab_height - $aicfab_h, 2 ) ); ?>" width="<?php echo esc_attr( round( max( 1, $aicfab_bar - 2 ), 2 ) ); ?>" height="<?php echo esc_attr( round( $aicfab_h, 2 ) ); ?>"><title><?php echo esc_html( $aicfab_text ); ?></title></rect>
							<?php
						endif;
					endforeach;
					?>
				</svg>
				<figcaption class="description"><?php esc_html_e( 'Grey marks are withheld figures. The table below lists every period.', 'ai-chat-for-amazon-bedrock' ); ?></figcaption>
			</figure>
			<table class="widefat striped aicfab-metric-table">
				<thead><tr>
					<th scope="col"><?php esc_html_e( 'From', 'ai-chat-for-amazon-bedrock' ); ?></th>
					<th scope="col"><?php esc_html_e( 'To', 'ai-chat-for-amazon-bedrock' ); ?></th>
					<th scope="col" class="num"><?php echo esc_html( $aicfab_result['label'] ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $aicfab_series as $aicfab_item ) : ?>
					<tr>
						<td><?php echo esc_html( $aicfab_item['start'] ); ?></td>
						<td><?php echo esc_html( $aicfab_item['end'] ); ?></td>
						<td class="num"><?php echo esc_html( $aicfab_item['hidden'] ? __( 'Withheld', 'ai-chat-for-amazon-bedrock' ) : AI_Chat_Bedrock_Metrics::format_value( $aicfab_item['value'], $aicfab_result['unit'], $aicfab_currency ) ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( ! empty( $aicfab_result['breakdown'] ) ) : ?>
			<h3>
				<?php
				printf(
					/* translators: %s: dimension name, such as Product. */
					esc_html__( 'By %s', 'ai-chat-for-amazon-bedrock' ),
					esc_html( $aicfab_result['breakdown']['label'] )
				);
				?>
			</h3>
			<?php if ( empty( $aicfab_result['breakdown']['rows'] ) ) : ?>
				<p><?php esc_html_e( 'Nothing in this period.', 'ai-chat-for-amazon-bedrock' ); ?></p>
			<?php else : ?>
				<table class="widefat striped aicfab-metric-table">
					<thead><tr>
						<th scope="col"><?php echo esc_html( $aicfab_result['breakdown']['label'] ); ?></th>
						<th scope="col" class="num"><?php echo esc_html( $aicfab_result['label'] ); ?></th>
					</tr></thead>
					<tbody>
					<?php foreach ( $aicfab_result['breakdown']['rows'] as $aicfab_item ) : ?>
						<tr>
							<td><?php echo esc_html( $aicfab_item['label'] ); ?></td>
							<td class="num"><?php echo esc_html( $aicfab_item['hidden'] ? __( 'Withheld', 'ai-chat-for-amazon-bedrock' ) : AI_Chat_Bedrock_Metrics::format_value( $aicfab_item['value'], $aicfab_result['unit'], $aicfab_currency ) ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( ! empty( $aicfab_result['notes'] ) ) : ?>
			<ul class="aicfab-metric-notes">
				<?php foreach ( $aicfab_result['notes'] as $aicfab_note ) : ?>
					<li><?php echo esc_html( $aicfab_note ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="ai_chat_bedrock_export_metrics">
			<?php wp_nonce_field( 'ai_chat_bedrock_export_metrics' ); ?>
			<?php foreach ( array( 'metric', 'period', 'after', 'before', 'compare', 'interval', 'dimension', 'limit' ) as $aicfab_key ) : ?>
				<input type="hidden" name="<?php echo esc_attr( $aicfab_key ); ?>" value="<?php echo esc_attr( (string) $aicfab_result['query'][ $aicfab_key ] ); ?>">
			<?php endforeach; ?>
			<?php submit_button( __( 'Download CSV', 'ai-chat-for-amazon-bedrock' ), 'secondary', 'submit', false ); ?>
		</form>

	<?php else : ?>
		<h2><?php esc_html_e( 'Last 30 days', 'ai-chat-for-amazon-bedrock' ); ?></h2>
		<div class="aicfab-metric-cards">
			<?php
			$aicfab_skipped = array();
			foreach ( array_keys( $aicfab_available ) as $aicfab_id ) {
				$aicfab_overview = AI_Chat_Bedrock_Metrics::query(
					array(
						'metric'  => $aicfab_id,
						'period'  => 'last_30_days',
						'compare' => 'previous_period',
					),
					'analytics'
				);
				if ( is_wp_error( $aicfab_overview ) ) {
					$aicfab_skipped[ $aicfab_overview->get_error_message() ] = true;
					continue;
				}
				$aicfab_card(
					$aicfab_overview,
					add_query_arg(
						array(
							'metric'   => $aicfab_id,
							'period'   => 'last_30_days',
							'compare'  => 'previous_period',
							'interval' => 'day',
						),
						$aicfab_screen_url
					)
				);
			}
			?>
		</div>
		<?php foreach ( array_keys( $aicfab_skipped ) as $aicfab_note ) : ?>
			<p class="description"><?php echo esc_html( $aicfab_note ); ?></p>
		<?php endforeach; ?>
	<?php endif; ?>
</div>

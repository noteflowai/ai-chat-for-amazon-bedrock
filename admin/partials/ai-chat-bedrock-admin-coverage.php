<?php
/**
 * Dashboard usage line: chat answers with site content behind them over seven days.
 *
 * Read from per-day integer counters only, so it works with conversation logging off.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$aicfab_coverage = is_callable( array( 'AI_Chat_Bedrock_Usage', 'coverage_summary' ) )
	? AI_Chat_Bedrock_Usage::coverage_summary( 7 )
	: array( 'status' => 'unknown' );
?>
<p class="aicfab-card-detail aicfab-usage-failures">
	<?php
	if ( isset( $aicfab_coverage['status'] ) && 'measured' === $aicfab_coverage['status'] ) {
		echo esc_html(
			sprintf(
				/* translators: 1: answers whose question matched site content, 2: answers counted, 3: percentage of answers with site content. */
				_n( 'Answers with site content (7 days): %1$s of %2$s (%3$s%%).', 'Answers with site content (7 days): %1$s of %2$s (%3$s%%).', (int) $aicfab_coverage['total'], 'ai-chat-for-amazon-bedrock' ),
				number_format_i18n( (int) $aicfab_coverage['grounded'] ),
				number_format_i18n( (int) $aicfab_coverage['total'] ),
				number_format_i18n( (int) $aicfab_coverage['percent'] )
			)
		);
	} else {
		esc_html_e( 'Answers with site content (7 days): unknown, no answers counted yet.', 'ai-chat-for-amazon-bedrock' );
	}
	echo ' ';
	esc_html_e( 'This counts answers whose question matched site content, not whether the answer was correct.', 'ai-chat-for-amazon-bedrock' );
	?>
</p>

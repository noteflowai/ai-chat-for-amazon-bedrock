<?php
/**
 * Answer checks screen: author a golden set, run it, read the result by category.
 *
 * @package AI_Chat_Bedrock
 */

defined( 'ABSPATH' ) || exit;

if ( ! current_user_can( AI_Chat_Bedrock_Eval::CAPABILITY ) ) {
	wp_die( esc_html__( 'You are not allowed to run evaluations on this site.', 'ai-chat-for-amazon-bedrock' ) );
}

$aicfab_cases = AI_Chat_Bedrock_Eval::cases();
$aicfab_runs  = AI_Chat_Bedrock_Eval::runs();
?>
<div class="wrap aicfab-eval">
	<h1><?php esc_html_e( 'Answer checks', 'ai-chat-for-amazon-bedrock' ); ?></h1>

	<p>
		<?php esc_html_e( 'Write questions whose right answer you already know, then run them. Each question goes through the same pipeline the chat uses, and the result is reported by category, so you can tell which part of an answer changed. Every check is a program: nothing here scores style, tone or helpfulness, and a model is never asked to judge another model.', 'ai-chat-for-amazon-bedrock' ); ?>
	</p>
	<p>
		<?php
		printf(
			/* translators: %s: the WP-CLI command, already escaped. */
			esc_html__( 'A run sends one Amazon Bedrock request per case, so it happens only when you ask. The same set runs from the command line with %s, which exits nonzero when a case fails and can gate a deployment.', 'ai-chat-for-amazon-bedrock' ),
			'<code>wp ai-chat-bedrock eval</code>'
		);
		?>
	</p>

	<h2><?php esc_html_e( 'Cases', 'ai-chat-for-amazon-bedrock' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Expect "grounded" when the site should answer from its own content, "unsupported" when it has nothing on the subject and must not invent specifics, or "tool" when a named tool should be called. Leave a field empty to skip that check for the case; a skipped check is reported as unchecked, never as a pass.', 'ai-chat-for-amazon-bedrock' ); ?>
	</p>

	<div class="aicfab-eval-scroll">
	<table class="widefat striped aicfab-eval-cases">
		<thead>
			<tr>
				<th scope="col"><?php esc_html_e( 'Question', 'ai-chat-for-amazon-bedrock' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Expect', 'ai-chat-for-amazon-bedrock' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Must include', 'ai-chat-for-amazon-bedrock' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Must not include', 'ai-chat-for-amazon-bedrock' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Credit page', 'ai-chat-for-amazon-bedrock' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Minimum match', 'ai-chat-for-amazon-bedrock' ); ?></th>
				<th scope="col"><?php esc_html_e( 'Token ceiling', 'ai-chat-for-amazon-bedrock' ); ?></th>
				<th scope="col"><span class="screen-reader-text"><?php esc_html_e( 'Actions', 'ai-chat-for-amazon-bedrock' ); ?></span></th>
			</tr>
		</thead>
		<tbody id="aicfab-eval-rows"></tbody>
	</table>
	</div>

	<p>
		<button type="button" class="button" id="aicfab-eval-add"><?php esc_html_e( 'Add a case', 'ai-chat-for-amazon-bedrock' ); ?></button>
		<button type="button" class="button" id="aicfab-eval-propose"><?php esc_html_e( 'Propose from recorded questions', 'ai-chat-for-amazon-bedrock' ); ?></button>
		<button type="button" class="button button-primary" id="aicfab-eval-save"><?php esc_html_e( 'Save cases', 'ai-chat-for-amazon-bedrock' ); ?></button>
		<button type="button" class="button button-primary" id="aicfab-eval-run"><?php esc_html_e( 'Run the checks', 'ai-chat-for-amazon-bedrock' ); ?></button>
		<span id="aicfab-eval-status" role="status"></span>
	</p>

	<div id="aicfab-eval-proposals" hidden>
		<h3><?php esc_html_e( 'Proposed from recorded questions', 'ai-chat-for-amazon-bedrock' ); ?></h3>
		<p class="description">
			<?php esc_html_e( 'These are questions this site was actually asked and did not answer well. The expectation follows from the record; what a good answer should contain is for you to state, because nothing here can know it.', 'ai-chat-for-amazon-bedrock' ); ?>
		</p>
		<ul id="aicfab-eval-proposal-list"></ul>
	</div>

	<div id="aicfab-eval-results" hidden>
		<h2><?php esc_html_e( 'Last run', 'ai-chat-for-amazon-bedrock' ); ?></h2>
		<div id="aicfab-eval-summary"></div>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Case', 'ai-chat-for-amazon-bedrock' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Result', 'ai-chat-for-amazon-bedrock' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Checks', 'ai-chat-for-amazon-bedrock' ); ?></th>
				</tr>
			</thead>
			<tbody id="aicfab-eval-result-rows"></tbody>
		</table>
	</div>

	<?php if ( count( $aicfab_runs ) > 1 ) : ?>
		<h2><?php esc_html_e( 'Recorded runs', 'ai-chat-for-amazon-bedrock' ); ?></h2>
		<p class="description">
			<?php esc_html_e( 'A category whose number of checks changed between runs is not comparable on passes alone, and is marked so. Dropping a case raises a pass rate without improving anything.', 'ai-chat-for-amazon-bedrock' ); ?>
		</p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Recorded', 'ai-chat-for-amazon-bedrock' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Model', 'ai-chat-for-amazon-bedrock' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Cases passed', 'ai-chat-for-amazon-bedrock' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( array_reverse( $aicfab_runs ) as $aicfab_run ) : ?>
					<tr>
						<td><?php echo esc_html( $aicfab_run['time'] ); ?></td>
						<td><code><?php echo esc_html( $aicfab_run['model'] ); ?></code></td>
						<td><?php echo esc_html( $aicfab_run['passed'] . ' / ' . $aicfab_run['cases'] ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<script type="application/json" id="aicfab-eval-initial"><?php echo wp_json_encode( $aicfab_cases ); ?></script>
</div>

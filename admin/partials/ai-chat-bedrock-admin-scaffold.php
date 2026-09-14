<?php
/*
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 *
 * This view is included from inside a class method, so the variables below are method
 * scope rather than globals.
 */
/** Site scaffold view. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( AI_Chat_Bedrock_Scaffold::CAPABILITY ) ) {
	return;
}
?>
<div class="wrap">
	<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

	<p>
		<?php esc_html_e( 'Describe the site and get a first set of pages as drafts. Nothing is published, existing pages are never changed, and the theme and menus are left alone. Review and edit every draft before publishing.', 'ai-chat-for-amazon-bedrock' ); ?>
	</p>
	<p class="description">
		<?php esc_html_e( 'The model is told not to invent prices, addresses, statistics, testimonials or credentials. Where a real detail is needed it leaves a bracketed placeholder for you to fill in.', 'ai-chat-for-amazon-bedrock' ); ?>
	</p>

	<h2 class="title"><?php esc_html_e( 'What is this site about?', 'ai-chat-for-amazon-bedrock' ); ?></h2>
	<p>
		<label class="screen-reader-text" for="aicfab-scaffold-description"><?php esc_html_e( 'Site description', 'ai-chat-for-amazon-bedrock' ); ?></label>
		<textarea id="aicfab-scaffold-description" class="large-text" rows="4"
			placeholder="<?php echo esc_attr__( 'A two-person bicycle repair shop in Utrecht. We service city bikes and e-bikes, sell refurbished bikes, and offer a pickup service within the city.', 'ai-chat-for-amazon-bedrock' ); ?>"></textarea>
	</p>
	<p>
		<button type="button" class="button button-primary" id="aicfab-scaffold-plan"><?php esc_html_e( 'Suggest pages', 'ai-chat-for-amazon-bedrock' ); ?></button>
		<span id="aicfab-scaffold-status" role="status"></span>
	</p>

	<div id="aicfab-scaffold-plan-wrap" hidden>
		<h2 class="title"><?php esc_html_e( 'Proposed pages', 'ai-chat-for-amazon-bedrock' ); ?></h2>
		<p><?php esc_html_e( 'Clear anything you do not want, edit the titles if you like, then create the drafts.', 'ai-chat-for-amazon-bedrock' ); ?></p>
		<table class="widefat striped" id="aicfab-scaffold-plan-table">
			<thead>
				<tr>
					<th scope="col" class="check-column"><span class="screen-reader-text"><?php esc_html_e( 'Include', 'ai-chat-for-amazon-bedrock' ); ?></span></th>
					<th scope="col"><?php esc_html_e( 'Title', 'ai-chat-for-amazon-bedrock' ); ?></th>
					<th scope="col"><?php esc_html_e( 'What it covers', 'ai-chat-for-amazon-bedrock' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Result', 'ai-chat-for-amazon-bedrock' ); ?></th>
				</tr>
			</thead>
			<tbody></tbody>
		</table>
		<p>
			<button type="button" class="button button-primary" id="aicfab-scaffold-create"><?php esc_html_e( 'Create the selected drafts', 'ai-chat-for-amazon-bedrock' ); ?></button>
			<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=page&post_status=draft' ) ); ?>"><?php esc_html_e( 'View draft pages', 'ai-chat-for-amazon-bedrock' ); ?></a>
			<span id="aicfab-scaffold-progress" role="status"></span>
		</p>
	</div>
</div>

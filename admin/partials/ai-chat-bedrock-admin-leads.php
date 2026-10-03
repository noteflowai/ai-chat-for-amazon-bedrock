<?php
/*
 * phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound
 *
 * This view is included from inside a class method, so the variables below are method
 * scope rather than globals. The sniff analyses the file on its own and cannot see the
 * include site.
 */
/** Contact requests left in the chat. */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
if ( ! current_user_can( AI_Chat_Bedrock_Leads::CAPABILITY ) ) {
	return;
}

// Read-only list filters, so a nonce is not required here.
// phpcs:disable WordPress.Security.NonceVerification.Recommended
$aicfab_status  = isset( $_GET['aicfab_status'] ) ? sanitize_key( wp_unslash( $_GET['aicfab_status'] ) ) : 'new';
$aicfab_paged   = isset( $_GET['paged'] ) ? absint( wp_unslash( $_GET['paged'] ) ) : 1;
$aicfab_message = isset( $_GET['aicfab-message'] ) ? sanitize_key( wp_unslash( $_GET['aicfab-message'] ) ) : '';
// phpcs:enable WordPress.Security.NonceVerification.Recommended

$views         = array(
	'new'     => __( 'New', 'ai-chat-for-amazon-bedrock' ),
	'handled' => __( 'Handled', 'ai-chat-for-amazon-bedrock' ),
	'spam'    => __( 'Spam', 'ai-chat-for-amazon-bedrock' ),
	'all'     => __( 'All', 'ai-chat-for-amazon-bedrock' ),
);
$aicfab_status = isset( $views[ $aicfab_status ] ) ? $aicfab_status : 'new';
$results       = AI_Chat_Bedrock_Leads::query(
	array(
		'status' => 'all' === $aicfab_status ? '' : $aicfab_status,
		'page'   => $aicfab_paged,
	)
);
$page_count    = (int) ceil( $results['total'] / AI_Chat_Bedrock_Leads::PER_PAGE );
$page_base     = admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock-leads' );
$filter_url    = add_query_arg( 'aicfab_status', $aicfab_status, $page_base );
$settings      = admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock-settings' );
$datetime      = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );
$notices       = array(
	'lead-handled' => __( 'Marked as handled.', 'ai-chat-for-amazon-bedrock' ),
	'lead-new'     => __( 'Marked as new.', 'ai-chat-for-amazon-bedrock' ),
	'lead-spam'    => __( 'Marked as spam.', 'ai-chat-for-amazon-bedrock' ),
	'lead-delete'  => __( 'Request deleted.', 'ai-chat-for-amazon-bedrock' ),
);
$labels        = array(
	'new'     => __( 'New', 'ai-chat-for-amazon-bedrock' ),
	'handled' => __( 'Handled', 'ai-chat-for-amazon-bedrock' ),
	'spam'    => __( 'Spam', 'ai-chat-for-amazon-bedrock' ),
);
$action_url    = function ( $id, $action ) {
	return wp_nonce_url(
		add_query_arg(
			array(
				'action' => 'ai_chat_bedrock_lead',
				'do'     => $action,
				'lead'   => (int) $id,
			),
			admin_url( 'admin-post.php' )
		),
		'ai_chat_bedrock_lead_' . (int) $id
	);
};
$export_url    = wp_nonce_url(
	add_query_arg(
		array(
			'action' => 'ai_chat_bedrock_lead',
			'do'     => 'export',
		),
		admin_url( 'admin-post.php' )
	),
	'ai_chat_bedrock_lead_export'
);
?>
<div class="wrap aicfab-dashboard">
	<h1 class="wp-heading-inline"><?php echo esc_html( get_admin_page_title() ); ?></h1>
	<a href="<?php echo esc_url( $export_url ); ?>" class="page-title-action"><?php esc_html_e( 'Export CSV', 'ai-chat-for-amazon-bedrock' ); ?></a>
	<hr class="wp-header-end">

	<?php if ( isset( $notices[ $aicfab_message ] ) ) : ?>
		<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notices[ $aicfab_message ] ); ?></p></div>
	<?php elseif ( 'lead-failed' === $aicfab_message ) : ?>
		<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'That request could not be changed. It may have been deleted already.', 'ai-chat-for-amazon-bedrock' ); ?></p></div>
	<?php endif; ?>

	<?php if ( ! AI_Chat_Bedrock_Leads::enabled() ) : ?>
		<div class="notice notice-info">
			<p>
				<?php esc_html_e( 'The chat is not taking contact requests now. Those already stored stay until they are deleted or reach the days kept.', 'ai-chat-for-amazon-bedrock' ); ?>
				<a href="<?php echo esc_url( $settings ); ?>"><?php esc_html_e( 'Open settings', 'ai-chat-for-amazon-bedrock' ); ?></a>
			</p>
		</div>
	<?php else : ?>
		<p class="aicfab-lede">
			<?php
			echo esc_html(
				sprintf(
					/* translators: %d: number of days. */
					_n( 'Visitors who asked for a person from the chat. Requests are deleted after %d day; spam is kept so it can be checked, but is not passed on.', 'Visitors who asked for a person from the chat. Requests are deleted after %d days; spam is kept so it can be checked, but is not passed on.', AI_Chat_Bedrock_Leads::retention_days(), 'ai-chat-for-amazon-bedrock' ),
					AI_Chat_Bedrock_Leads::retention_days()
				)
			);
			?>
		</p>
	<?php endif; ?>

	<ul class="subsubsub">
		<?php
		$last = array_key_last( $views );
		foreach ( $views as $key => $label ) :
			?>
			<li><a href="<?php echo esc_url( add_query_arg( 'aicfab_status', $key, $page_base ) ); ?>"<?php echo $key === $aicfab_status ? ' class="current" aria-current="page"' : ''; ?>><?php echo esc_html( $label ); ?></a><?php echo $key === $last ? '' : ' |'; ?></li>
		<?php endforeach; ?>
	</ul>

	<?php if ( $results['items'] ) : ?>
		<table class="widefat striped aicfab-leads">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Received', 'ai-chat-for-amazon-bedrock' ); ?></th>
					<th scope="col"><?php echo esc_html_x( 'From', 'sender of a contact request', 'ai-chat-for-amazon-bedrock' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Message', 'ai-chat-for-amazon-bedrock' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Status', 'ai-chat-for-amazon-bedrock' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $results['items'] as $lead ) : ?>
					<tr>
						<td>
							<?php echo esc_html( wp_date( $datetime, $lead['time'] ) ); ?>
							<?php if ( '' !== $lead['page'] ) : ?>
								<br><a href="<?php echo esc_url( $lead['page'] ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Page', 'ai-chat-for-amazon-bedrock' ); ?></a>
							<?php endif; ?>
						</td>
						<td>
							<strong><?php echo esc_html( '' !== $lead['name'] ? $lead['name'] : __( '(no name)', 'ai-chat-for-amazon-bedrock' ) ); ?></strong>
							<?php if ( '' !== $lead['email'] ) : ?>
								<br><a href="<?php echo esc_url( 'mailto:' . $lead['email'] ); ?>"><?php echo esc_html( $lead['email'] ); ?></a>
							<?php endif; ?>
							<?php if ( '' !== $lead['phone'] ) : ?>
								<br><a href="<?php echo esc_url( 'tel:' . preg_replace( '/[^0-9+]/', '', $lead['phone'] ) ); ?>"><?php echo esc_html( $lead['phone'] ); ?></a>
							<?php endif; ?>
						</td>
						<td>
							<?php echo wp_kses_post( wpautop( esc_html( $lead['message'] ) ) ); ?>
							<?php if ( $lead['conversation'] ) : ?>
								<details>
									<summary><?php esc_html_e( 'Conversation', 'ai-chat-for-amazon-bedrock' ); ?></summary>
									<?php foreach ( $lead['conversation'] as $turn ) : ?>
										<p><strong><?php echo esc_html( 'user' === $turn['role'] ? __( 'Visitor', 'ai-chat-for-amazon-bedrock' ) : _x( 'AI', 'chat avatar for the assistant', 'ai-chat-for-amazon-bedrock' ) ); ?>:</strong> <?php echo esc_html( $turn['content'] ); ?></p>
									<?php endforeach; ?>
								</details>
							<?php endif; ?>
						</td>
						<td>
							<?php echo esc_html( $labels[ $lead['status'] ] ); ?>
							<div class="row-actions visible">
								<?php
								$links = array();
								if ( 'new' === $lead['status'] ) {
									$links[] = '<a href="' . esc_url( $action_url( $lead['id'], 'handled' ) ) . '">' . esc_html__( 'Mark handled', 'ai-chat-for-amazon-bedrock' ) . '</a>';
								} elseif ( 'handled' === $lead['status'] ) {
									$links[] = '<a href="' . esc_url( $action_url( $lead['id'], 'new' ) ) . '">' . esc_html__( 'Mark new', 'ai-chat-for-amazon-bedrock' ) . '</a>';
								}
								if ( 'spam' === $lead['status'] ) {
									$links[] = '<a href="' . esc_url( $action_url( $lead['id'], 'new' ) ) . '">' . esc_html__( 'Not spam', 'ai-chat-for-amazon-bedrock' ) . '</a>';
								} else {
									$links[] = '<a href="' . esc_url( $action_url( $lead['id'], 'spam' ) ) . '">' . esc_html__( 'Spam', 'ai-chat-for-amazon-bedrock' ) . '</a>';
								}
								$links[] = '<span class="delete"><a class="submitdelete" href="' . esc_url( $action_url( $lead['id'], 'delete' ) ) . '" onclick="return window.confirm(this.dataset.confirm);" data-confirm="' . esc_attr__( 'Delete this request for good?', 'ai-chat-for-amazon-bedrock' ) . '">' . esc_html__( 'Delete', 'ai-chat-for-amazon-bedrock' ) . '</a></span>';
								echo implode( ' | ', $links ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- each link is escaped above.
								?>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>

		<?php if ( $page_count > 1 ) : ?>
			<div class="tablenav"><div class="tablenav-pages">
				<?php
				echo wp_kses_post(
					paginate_links(
						array(
							'base'      => add_query_arg( 'paged', '%#%', $filter_url ),
							'format'    => '',
							'current'   => max( 1, $aicfab_paged ),
							'total'     => $page_count,
							'prev_text' => __( 'Previous', 'ai-chat-for-amazon-bedrock' ),
							'next_text' => __( 'Next', 'ai-chat-for-amazon-bedrock' ),
						)
					)
				);
				?>
			</div></div>
		<?php endif; ?>
	<?php else : ?>
		<p class="aicfab-tool-log-empty" style="clear:both"><?php esc_html_e( 'No requests here.', 'ai-chat-for-amazon-bedrock' ); ?></p>
	<?php endif; ?>
</div>

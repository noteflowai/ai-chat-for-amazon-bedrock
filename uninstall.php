<?php
/**
 * Plugin uninstall cleanup.
 *
 * @package AI_Chat_Bedrock
 */

/*
 * phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
 *
 * Uninstalling deletes plugin rows by option-name pattern. There is no API for that,
 * caching is pointless because the data is being removed, and the table name comes
 * from $wpdb rather than from input.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

foreach ( array( 'ai_chat_bedrock_settings', 'ai_chat_bedrock_role_limits', 'ai_chat_bedrock_enable_mcp', 'ai_chat_bedrock_mcp_public_access', 'ai_chat_bedrock_mcp_servers', 'ai_chat_bedrock_db_version', 'ai_chat_bedrock_usage', 'ai_chat_bedrock_mcp_tool_policy', 'ai_chat_bedrock_mcp_capability', 'ai_chat_bedrock_mcp_max_rounds', 'ai_chat_bedrock_mcp_log_enabled', 'ai_chat_bedrock_tool_log', 'ai_chat_bedrock_conversations', 'ai_chat_bedrock_log_conversations', 'ai_chat_bedrock_log_retention_days', 'ai_chat_bedrock_oauth_clients', 'ai_chat_bedrock_oauth_grants', 'ai_chat_bedrock_oauth_revoked', 'ai_chat_bedrock_oauth_enabled', 'ai_chat_bedrock_site_abilities', 'ai_chat_bedrock_profiles' ) as $ai_chat_bedrock_option ) {
	delete_option( $ai_chat_bedrock_option );
}

delete_transient( 'ai_chat_bedrock_cache' );
delete_transient( 'aicfab_role_credentials' );

global $wpdb;
$ai_chat_bedrock_legacy_table = esc_sql( $wpdb->prefix . 'ai_chat_bedrock_history' );
$wpdb->query( "DROP TABLE IF EXISTS `{$ai_chat_bedrock_legacy_table}`" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange

$ai_chat_bedrock_pattern = $wpdb->esc_like( '_transient_aicfab_rl_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $ai_chat_bedrock_pattern ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
$ai_chat_bedrock_timeout_pattern = $wpdb->esc_like( '_transient_timeout_aicfab_rl_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $ai_chat_bedrock_timeout_pattern ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

$ai_chat_bedrock_model_pattern = $wpdb->esc_like( '_transient_aicfab_models_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $ai_chat_bedrock_model_pattern ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
$ai_chat_bedrock_model_timeout_pattern = $wpdb->esc_like( '_transient_timeout_aicfab_models_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $ai_chat_bedrock_model_timeout_pattern ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

// Post meta this plugin wrote, removed by key rather than by option name. Every key the
// plugin writes has to appear here; tests/security-regression.php checks that it does.
foreach ( array( '_aicfab_embedding', '_aicfab_embedding_model', '_aicfab_embedding_hash', '_aicfab_scaffolded' ) as $ai_chat_bedrock_meta_key ) {
	delete_post_meta_by_key( $ai_chat_bedrock_meta_key );
}

// Cached managed prompt text is stored in transients keyed by prompt and version.
$ai_chat_bedrock_prompt_pattern = $wpdb->esc_like( '_transient_aicfab_prompt_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $ai_chat_bedrock_prompt_pattern ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
$ai_chat_bedrock_prompt_timeout = $wpdb->esc_like( '_transient_timeout_aicfab_prompt_' ) . '%';
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $ai_chat_bedrock_prompt_timeout ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery

// Any scheduled index run is removed with the plugin data.
$ai_chat_bedrock_cron_next = wp_next_scheduled( 'ai_chat_bedrock_index_embeddings' );
while ( $ai_chat_bedrock_cron_next ) {
	wp_unschedule_event( $ai_chat_bedrock_cron_next, 'ai_chat_bedrock_index_embeddings' );
	$ai_chat_bedrock_cron_next = wp_next_scheduled( 'ai_chat_bedrock_index_embeddings' );
}

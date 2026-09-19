<?php
/**
 * Plugin Name: AI Agents & Chat for Amazon Bedrock
 * Plugin URI: https://github.com/noteflowai/ai-chat-for-amazon-bedrock
 * Description: Streaming chat and governed tool-using agents on Amazon Bedrock, with IAM role credentials, a standards-compliant MCP server and client, and security-first defaults.
 * Version: 1.38.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Author: Glay
 * Author URI: https://github.com/noteflowai
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ai-chat-for-amazon-bedrock
 * Domain Path: /languages
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

define( 'AI_CHAT_BEDROCK_VERSION', '1.38.0' );
define( 'AI_CHAT_BEDROCK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AI_CHAT_BEDROCK_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

function ai_chat_bedrock_activate_plugin() {
	require_once AI_CHAT_BEDROCK_PLUGIN_DIR . 'includes/class-ai-chat-bedrock-activator.php';
	AI_Chat_Bedrock_Activator::activate();
}

function ai_chat_bedrock_deactivate_plugin() {
	require_once AI_CHAT_BEDROCK_PLUGIN_DIR . 'includes/class-ai-chat-bedrock-deactivator.php';
	AI_Chat_Bedrock_Deactivator::deactivate();

	// A scheduled index run must not survive deactivation.
	if ( class_exists( 'AI_Chat_Bedrock_Embeddings' ) ) {
		AI_Chat_Bedrock_Embeddings::unschedule();
	} else {
		$ai_chat_bedrock_next = wp_next_scheduled( 'ai_chat_bedrock_index_embeddings' );
		while ( $ai_chat_bedrock_next ) {
			wp_unschedule_event( $ai_chat_bedrock_next, 'ai_chat_bedrock_index_embeddings' );
			$ai_chat_bedrock_next = wp_next_scheduled( 'ai_chat_bedrock_index_embeddings' );
		}
	}
}

register_activation_hook( __FILE__, 'ai_chat_bedrock_activate_plugin' );
register_deactivation_hook( __FILE__, 'ai_chat_bedrock_deactivate_plugin' );

function ai_chat_bedrock_action_links( $links ) {
	$own = array(
		'<a href="' . esc_url( admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock-settings' ) ) . '">' . esc_html__( 'Settings', 'ai-chat-for-amazon-bedrock' ) . '</a>',
		'<a href="' . esc_url( admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock-diagnostics' ) ) . '">' . esc_html__( 'Diagnostics', 'ai-chat-for-amazon-bedrock' ) . '</a>',
	);
	return array_merge( $own, (array) $links );
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'ai_chat_bedrock_action_links' );

require AI_CHAT_BEDROCK_PLUGIN_DIR . 'includes/class-ai-chat-bedrock.php';

function ai_chat_bedrock_run_plugin() {
	$plugin = new AI_Chat_Bedrock();
	$plugin->run();
}

ai_chat_bedrock_run_plugin();

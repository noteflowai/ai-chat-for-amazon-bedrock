<?php
/**
 * Plugin Name: AI Chatbot & Agents for Amazon Bedrock
 * Plugin URI: https://github.com/noteflowai/ai-chat-for-amazon-bedrock
 * Description: Streaming chat and governed tool-using agents on Amazon Bedrock, with IAM role credentials, a standards-compliant MCP server and client, and security-first defaults.
 * Version: 1.68.0
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * WC requires at least: 8.0
 * WC tested up to: 11.1
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

define( 'AI_CHAT_BEDROCK_VERSION', '1.68.0' );
// Scripts and styles carry the build as well as the version. A CDN may keep them as immutable,
// and a build installed again under the same version would otherwise get its old assets.
define( 'AI_CHAT_BEDROCK_ASSET_VERSION', AI_CHAT_BEDROCK_VERSION . '.' . (int) filemtime( __FILE__ ) );
define( 'AI_CHAT_BEDROCK_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'AI_CHAT_BEDROCK_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'AI_CHAT_BEDROCK_PLUGIN_FILE', __FILE__ );

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

/**
 * Link to the support forum beside the plugin's description on the Plugins screen.
 *
 * @param array  $links Links under the description.
 * @param string $file  Plugin the row is for.
 * @return array
 */
function ai_chat_bedrock_row_meta( $links, $file ) {
	if ( plugin_basename( __FILE__ ) === $file ) {
		$links[] = '<a href="' . esc_url( 'https://wordpress.org/support/plugin/ai-chat-for-amazon-bedrock/' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Support', 'ai-chat-for-amazon-bedrock' ) . '</a>';
	}
	return $links;
}
add_filter( 'plugin_row_meta', 'ai_chat_bedrock_row_meta', 10, 2 );

/**
 * Declare WooCommerce feature compatibility. WooCommerce asks before it loads, so this cannot
 * wait for the plugin's own hooks.
 */
function ai_chat_bedrock_declare_woocommerce_compatibility() {
	if ( class_exists( 'AI_Chat_Bedrock_WooCommerce' ) ) {
		AI_Chat_Bedrock_WooCommerce::declare_compatibility( __FILE__ );
	}
}
add_action( 'before_woocommerce_init', 'ai_chat_bedrock_declare_woocommerce_compatibility' );

// The plugin follows the WP Consent API: what it stores is described and analytics wait for consent.
add_filter( 'wp_consent_api_registered_' . plugin_basename( __FILE__ ), '__return_true' );

require AI_CHAT_BEDROCK_PLUGIN_DIR . 'includes/class-ai-chat-bedrock.php';

function ai_chat_bedrock_run_plugin() {
	$plugin = new AI_Chat_Bedrock();
	$plugin->run();
}

ai_chat_bedrock_run_plugin();

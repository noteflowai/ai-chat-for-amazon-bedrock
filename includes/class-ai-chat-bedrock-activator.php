<?php
/**
 * Plugin activation.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Activator {
	public static function activate() {
		$defaults = array(
			'aws_region'            => 'us-east-1',
			'model_id'              => 'anthropic.claude-3-haiku-20240307-v1:0',
			'max_tokens'            => 1000,
			'temperature'           => 0.7,
			'chat_title'            => 'Chat with AI',
			'welcome_message'       => 'Hello! How can I help you today?',
			'system_prompt'         => 'You are a helpful AI assistant powered by Amazon Bedrock.',
			'enable_streaming'      => 'off',
			'allow_public_chat'     => false,
			'rate_limit_per_minute' => 5,
			'debug_mode'            => 'off',
		);
		add_option( 'ai_chat_bedrock_settings', $defaults, '', false );
		add_option( 'ai_chat_bedrock_enable_mcp', false, '', false );
		add_option( 'ai_chat_bedrock_mcp_public_access', false, '', false );
		update_option( 'ai_chat_bedrock_db_version', AI_CHAT_BEDROCK_VERSION, false );
	}
}

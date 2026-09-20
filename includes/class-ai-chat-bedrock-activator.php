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

			/*
			 * Amazon's own small model, chosen because a fresh install must be able to answer
			 * before anyone visits the settings screen. The previous default, Claude 3 Haiku,
			 * was retired by its provider for accounts not already using it, so a new install
			 * failed its first request with a message about choosing a current model. Bedrock
			 * still lists retired models in ListFoundationModels, so discovery cannot catch
			 * this; only an invocation can, which is what the Diagnostics screen does.
			 */
			'model_id'              => 'amazon.nova-lite-v1:0',
			'max_tokens'            => 1000,
			'temperature'           => 0.7,
			'chat_title'            => 'Chat with AI',
			'welcome_message'       => 'Hello! How can I help you today?',
			'system_prompt'         => 'You are a helpful AI assistant powered by Amazon Bedrock.',
			'enable_streaming'      => 'on',
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

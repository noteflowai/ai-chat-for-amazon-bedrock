<?php
/**
 * Text generation through the plugin request path.
 *
 * Part of the WordPress AI Client provider. Loaded only when core's AI Client exists.
 *
 * @package AI_Chat_Bedrock
 */

defined( 'ABSPATH' ) || exit;

// The camelCase parameter names below mirror the core interfaces this implements;
// renaming them would break callers using named arguments.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

/**
 * Generates text through this plugin's Bedrock request path.
 */
class AI_Chat_Bedrock_AI_Model implements ModelInterface, TextGenerationModelInterface {

	/**
	 * Model metadata.
	 *
	 * @var ModelMetadata
	 */
	private $model_metadata;

	/**
	 * Provider metadata.
	 *
	 * @var ProviderMetadata
	 */
	private $provider_metadata;

	/**
	 * Model configuration set by the caller.
	 *
	 * @var ModelConfig
	 */
	private $config;

	/**
	 * Constructor.
	 *
	 * @param ModelMetadata    $model_metadata    Model metadata.
	 * @param ProviderMetadata $provider_metadata Provider metadata.
	 */
	public function __construct( ModelMetadata $model_metadata, ProviderMetadata $provider_metadata ) {
		$this->model_metadata    = $model_metadata;
		$this->provider_metadata = $provider_metadata;
		$this->config            = new ModelConfig();
	}

	/**
	 * Model metadata.
	 *
	 * @return ModelMetadata
	 */
	public function metadata(): ModelMetadata {
		return $this->model_metadata;
	}

	/**
	 * Provider metadata.
	 *
	 * @return ProviderMetadata
	 */
	public function providerMetadata(): ProviderMetadata {
		return $this->provider_metadata;
	}

	/**
	 * Accept a configuration.
	 *
	 * @param ModelConfig $config Configuration.
	 * @return void
	 */
	public function setConfig( ModelConfig $config ): void {
		$this->config = $config;
	}

	/**
	 * The active configuration.
	 *
	 * @return ModelConfig
	 */
	public function getConfig(): ModelConfig {
		return $this->config;
	}

	/**
	 * Flatten one core message into the role and text this plugin sends.
	 *
	 * Only text is carried across. A prompt containing a file or a function call is
	 * rejected rather than silently reduced to its text parts, because a caller that
	 * attached an image and received an answer about nothing would have no way to tell.
	 *
	 * @param Message $message Core message.
	 * @return array{role: string, content: string}
	 * @throws RuntimeException When a part cannot be represented.
	 */
	private function flatten( Message $message ) {
		$text = '';
		foreach ( $message->getParts() as $part ) {
			if ( ! $part instanceof MessagePart || null === $part->getText() ) {
				throw new RuntimeException(
					esc_html__( 'This provider currently sends text only; the prompt contains a part it cannot represent.', 'ai-chat-for-amazon-bedrock' )
				);
			}
			$text .= ( '' === $text ? '' : "\n\n" ) . $part->getText();
		}
		$role = $message->getRole()->equals( MessageRoleEnum::model() ) ? 'assistant' : 'user';
		return array(
			'role'    => $role,
			'content' => $text,
		);
	}

	/**
	 * Generate a result for a prompt.
	 *
	 * @param Message[] $prompt Core messages.
	 * @return GenerativeAiResult
	 * @throws RuntimeException When the site blocks the call or Bedrock reports a failure.
	 */
	public function generateTextResult( array $prompt ): GenerativeAiResult {
		// The same gate core's filter consults, applied again here: a caller can reach a
		// model object directly without going through the prompt builder.
		$allowed = AI_Chat_Bedrock_Core_AI::can_generate();
		if ( is_wp_error( $allowed ) ) {
			throw new RuntimeException( esc_html( $allowed->get_error_message() ) );
		}

		$messages = array();
		$system   = $this->config->getSystemInstruction();
		if ( is_string( $system ) && '' !== $system ) {
			$messages[] = array(
				'role'    => 'system',
				'content' => $system,
			);
		}
		foreach ( $prompt as $message ) {
			if ( $message instanceof Message ) {
				$messages[] = $this->flatten( $message );
			}
		}
		if ( empty( $messages ) ) {
			throw new RuntimeException( esc_html__( 'The prompt contained no messages.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$overrides = array( 'model_id' => $this->model_metadata->getId() );
		$max       = $this->config->getMaxTokens();
		if ( is_int( $max ) && $max > 0 ) {
			$overrides['max_tokens'] = $max;
		}
		$temperature = $this->config->getTemperature();
		if ( is_numeric( $temperature ) ) {
			$overrides['temperature'] = (float) $temperature;
		}

		$aws      = new AI_Chat_Bedrock_AWS( $overrides );
		$response = $aws->handle_chat_message( array( 'messages' => $messages ) );
		if ( empty( $response['success'] ) ) {
			$message = isset( $response['data']['message'] ) ? (string) $response['data']['message'] : __( 'The Amazon Bedrock request failed.', 'ai-chat-for-amazon-bedrock' );
			throw new RuntimeException( esc_html( $message ) );
		}

		$text  = isset( $response['data']['message'] ) ? (string) $response['data']['message'] : '';
		$usage = isset( $response['usage'] ) && is_array( $response['usage'] ) ? $response['usage'] : array();
		$in    = isset( $usage['input_tokens'] ) ? (int) $usage['input_tokens'] : 0;
		$out   = isset( $usage['output_tokens'] ) ? (int) $usage['output_tokens'] : 0;

		// A fallback model answered, so report the model that actually ran rather than the
		// one that was asked for.
		$model_metadata = $this->model_metadata;
		if ( ! empty( $response['fallback_model'] ) ) {
			$directory = new AI_Chat_Bedrock_AI_Model_Directory();
			$fallback  = (string) $response['fallback_model'];
			if ( $directory->hasModelMetadata( $fallback ) ) {
				$model_metadata = $directory->getModelMetadata( $fallback );
			}
		}

		$candidate = new Candidate(
			new Message(
				MessageRoleEnum::model(),
				array( new MessagePart( $text ) )
			),
			FinishReasonEnum::stop()
		);

		return new GenerativeAiResult(
			isset( $response['request_id'] ) ? (string) $response['request_id'] : uniqid( 'aicfab_', false ),
			array( $candidate ),
			new TokenUsage( $in, $out, $in + $out ),
			$this->provider_metadata,
			$model_metadata
		);
	}
}

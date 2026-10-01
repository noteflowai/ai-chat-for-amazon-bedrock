<?php
/**
 * Embedding generation through the plugin's request path.
 *
 * Part of the WordPress AI Client provider. Loaded only when core's AI Client has embedding
 * generation, which arrived in php-ai-client 1.4 (WordPress 7.2).
 *
 * @package AI_Chat_Bedrock
 */

defined( 'ABSPATH' ) || exit;

// The camelCase parameter names below mirror the core interfaces this implements;
// renaming them would break callers using named arguments.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\EmbeddingGeneration\Contracts\EmbeddingGenerationModelInterface;
use WordPress\AiClient\Results\DTO\EmbeddingResult;
use WordPress\AiClient\Results\DTO\TokenUsage;

/**
 * Generates embeddings with an Amazon Titan or Cohere model on Amazon Bedrock.
 */
class AI_Chat_Bedrock_AI_Embedding_Model implements ModelInterface, EmbeddingGenerationModelInterface {

	/**
	 * Most inputs one call embeds. Bedrock embeds one text per request, so each is a call.
	 */
	const MAX_INPUTS = 96;

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
	 * Embed each input, in order.
	 *
	 * The custom option "purpose" set to "query" embeds a search question rather than a
	 * document, which Cohere models retrieve noticeably better with.
	 *
	 * @param MessagePart[] $inputs Text parts.
	 * @return EmbeddingResult
	 * @throws RuntimeException When the site blocks the call or Bedrock reports a failure.
	 */
	public function generateEmbeddingResult( array $inputs ): EmbeddingResult {
		$allowed = AI_Chat_Bedrock_Core_AI::can_generate();
		if ( is_wp_error( $allowed ) ) {
			throw new RuntimeException( esc_html( $allowed->get_error_message() ) );
		}
		if ( empty( $inputs ) || count( $inputs ) > self::MAX_INPUTS ) {
			/* translators: %d: most inputs one request embeds. */
			throw new RuntimeException( esc_html( sprintf( __( 'Embed between 1 and %d inputs at a time.', 'ai-chat-for-amazon-bedrock' ), self::MAX_INPUTS ) ) );
		}

		$custom     = $this->config->getCustomOptions();
		$purpose    = isset( $custom['purpose'] ) && 'query' === $custom['purpose'] ? 'query' : 'document';
		$dimensions = method_exists( $this->config, 'getDimensions' ) ? (int) $this->config->getDimensions() : 0;
		$model_id   = $this->model_metadata->getId();
		$aws        = new AI_Chat_Bedrock_AWS();
		$vectors    = array();
		foreach ( $inputs as $input ) {
			if ( ! $input instanceof MessagePart || null === $input->getText() ) {
				throw new RuntimeException( esc_html__( 'These embedding models take text only.', 'ai-chat-for-amazon-bedrock' ) );
			}
			$vector = $aws->embed( $input->getText(), $model_id, $purpose, $dimensions );
			if ( is_wp_error( $vector ) ) {
				throw new RuntimeException( esc_html( $vector->get_error_message() ) );
			}
			$vectors[] = $vector;
		}

		return new EmbeddingResult(
			uniqid( 'aicfab_embedding_', false ),
			$vectors,
			count( $vectors[0] ),
			// Bedrock reports input tokens per call; the plugin's usage log has them.
			new TokenUsage( 0, 0, 0 ),
			$this->provider_metadata,
			$this->model_metadata
		);
	}
}

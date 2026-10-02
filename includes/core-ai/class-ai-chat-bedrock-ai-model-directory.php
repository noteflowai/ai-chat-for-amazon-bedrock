<?php
/**
 * The Bedrock models this site offers to core.
 *
 * Part of the WordPress AI Client provider. Loaded only when core's AI Client exists.
 *
 * @package AI_Chat_Bedrock
 */

defined( 'ABSPATH' ) || exit;

// The camelCase parameter names below mirror the core interfaces this implements;
// renaming them would break callers using named arguments.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

use WordPress\AiClient\Files\Enums\FileTypeEnum;
use WordPress\AiClient\Files\Enums\MediaOrientationEnum;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

/**
 * Lists the models this site is configured to use.
 */
class AI_Chat_Bedrock_AI_Model_Directory implements ModelMetadataDirectoryInterface {

	/**
	 * Interface core requires of an embedding model, from php-ai-client 1.4 (WordPress 7.2).
	 */
	const EMBEDDING_INTERFACE = '\WordPress\AiClient\Providers\Models\EmbeddingGeneration\Contracts\EmbeddingGenerationModelInterface';

	/**
	 * Build metadata for one text model id.
	 *
	 * @param string $model_id Bedrock model identifier.
	 * @return ModelMetadata
	 */
	private function metadata_for( $model_id ) {
		$text  = array( ModalityEnum::text() );
		$input = array( $text );
		// Core matches the exact set of modalities in a prompt, so a model that reads images
		// is listed for text alone and for text with an image.
		if ( class_exists( 'AI_Chat_Bedrock_Models' ) && AI_Chat_Bedrock_Models::accepts_images( $model_id ) ) {
			$input[] = array( ModalityEnum::text(), ModalityEnum::image() );
		}
		// Only options this adapter actually honours are declared, with their real limits.
		// Declaring more would make core hand over a request that then gets quietly ignored,
		// which is worse than core reporting no matching model.
		$options = array(
			new SupportedOption( OptionEnum::inputModalities(), $input ),
			new SupportedOption( OptionEnum::outputModalities(), array( $text ) ),
			new SupportedOption( OptionEnum::systemInstruction() ),
			new SupportedOption( OptionEnum::maxTokens() ),
			new SupportedOption( OptionEnum::temperature() ),
			// Bedrock returns one candidate per request, so more are separate requests, capped.
			new SupportedOption( OptionEnum::candidateCount(), range( 1, AI_Chat_Bedrock_AI_Model::MAX_CANDIDATES ) ),
			// Models that refuse stop sequences have the answer cut at the first one instead.
			new SupportedOption( OptionEnum::stopSequences() ),
			// JSON is asked for in the prompt, constrained by the schema where the model can
			// be, and checked before it is returned.
			new SupportedOption( OptionEnum::outputMimeType(), array( 'text/plain', 'application/json' ) ),
			new SupportedOption( OptionEnum::outputSchema() ),
		);
		if ( AI_Chat_Bedrock_AWS::accepts_top_p( $model_id ) ) {
			$options[] = new SupportedOption( OptionEnum::topP() );
		}
		return new ModelMetadata(
			(string) $model_id,
			(string) $model_id,
			array(
				CapabilityEnum::textGeneration(),
				// Multi-message prompts are what the chat path already sends.
				CapabilityEnum::chatHistory(),
			),
			$options
		);
	}

	/**
	 * Build metadata for one Stability image model.
	 *
	 * @param string $model_id Model ID.
	 * @param string $label    Model name.
	 * @return ModelMetadata
	 */
	private function image_metadata_for( $model_id, $label ) {
		$input = array( array( ModalityEnum::text() ) );
		if ( AI_Chat_Bedrock_Images::accepts_reference( $model_id ) ) {
			$input[] = array( ModalityEnum::text(), ModalityEnum::image() );
		}
		return new ModelMetadata(
			(string) $model_id,
			(string) $label,
			array( CapabilityEnum::imageGeneration() ),
			array(
				new SupportedOption( OptionEnum::inputModalities(), $input ),
				new SupportedOption( OptionEnum::outputModalities(), array( array( ModalityEnum::image() ) ) ),
				new SupportedOption( OptionEnum::systemInstruction() ),
				new SupportedOption( OptionEnum::candidateCount(), array( 1 ) ),
				// The models return base64 in the response body, never a link.
				new SupportedOption( OptionEnum::outputFileType(), array( FileTypeEnum::inline() ) ),
				new SupportedOption( OptionEnum::outputMimeType(), array_keys( AI_Chat_Bedrock_Images::mime_types() ) ),
				new SupportedOption( OptionEnum::outputMediaOrientation(), array( MediaOrientationEnum::square(), MediaOrientationEnum::landscape(), MediaOrientationEnum::portrait() ) ),
				new SupportedOption( OptionEnum::outputMediaAspectRatio(), AI_Chat_Bedrock_Images::aspect_ratios() ),
			)
		);
	}

	/**
	 * Build metadata for one embedding model.
	 *
	 * @param string $model_id Model ID.
	 * @param string $label    Model name.
	 * @return ModelMetadata
	 */
	private function embedding_metadata_for( $model_id, $label ) {
		// Vector sizes of the models the plugin lists. A model added by filter declares none,
		// so a request for a particular size never reaches a model that may not produce it.
		$sizes   = array(
			'amazon.titan-embed-text-v2:0' => array( 256, 512, 1024 ),
			'amazon.titan-embed-text-v1'   => array( 1536 ),
			'cohere.embed-english-v3'      => array( 1024 ),
			'cohere.embed-multilingual-v3' => array( 1024 ),
		);
		$options = array(
			new SupportedOption( OptionEnum::inputModalities(), array( array( ModalityEnum::text() ) ) ),
			// "purpose" => "query" for a search question; see the embedding model.
			new SupportedOption( OptionEnum::customOptions() ),
		);
		// The enum's members are magic methods, so method_exists() cannot see them.
		if ( isset( $sizes[ $model_id ] ) && OptionEnum::isValidValue( 'dimensions' ) ) {
			$options[] = new SupportedOption( OptionEnum::dimensions(), $sizes[ $model_id ] );
		}
		return new ModelMetadata(
			(string) $model_id,
			(string) $label,
			array( CapabilityEnum::embeddingGeneration() ),
			$options
		);
	}

	/**
	 * Whether core's AI Client has embedding generation.
	 *
	 * @return bool
	 */
	public static function embeddings_available() {
		return interface_exists( self::EMBEDDING_INTERFACE ) && class_exists( 'AI_Chat_Bedrock_AI_Embedding_Model', false );
	}

	/**
	 * Whether core's AI Client has image generation and the site turned it on.
	 *
	 * @return bool
	 */
	public static function images_available() {
		return class_exists( 'AI_Chat_Bedrock_AI_Image_Model', false ) && class_exists( 'AI_Chat_Bedrock_Images' ) && AI_Chat_Bedrock_Images::enabled();
	}

	/**
	 * The models on offer.
	 *
	 * Text models come from the site's own configuration rather than a hardcoded list, so a
	 * site that only has access to one model does not advertise others it cannot call. Image
	 * models are listed once the site picks one, with that one first, because core takes the
	 * first model that fits. Embedding models are listed where core can use them; core never
	 * picks one by itself, because vectors only compare within one model.
	 *
	 * @return list<ModelMetadata>
	 */
	public function listModelMetadata(): array {
		$ids     = array();
		$options = get_option( 'ai_chat_bedrock_settings', array() );
		if ( is_array( $options ) && ! empty( $options['model_id'] ) ) {
			$ids[] = (string) $options['model_id'];
		}
		if ( class_exists( 'AI_Chat_Bedrock_Models' ) ) {
			foreach ( array_keys( AI_Chat_Bedrock_Models::fallback_models() ) as $id ) {
				$ids[] = (string) $id;
			}
		}
		$metadata = array();
		foreach ( array_unique( $ids ) as $id ) {
			if ( '' !== $id ) {
				$metadata[] = $this->metadata_for( $id );
			}
		}

		if ( self::images_available() ) {
			$models     = AI_Chat_Bedrock_Images::models();
			$configured = AI_Chat_Bedrock_Images::configured_model();
			$models     = array( $configured => $models[ $configured ] ) + $models;
			foreach ( $models as $id => $label ) {
				$metadata[] = $this->image_metadata_for( $id, $label );
			}
		}

		if ( self::embeddings_available() && class_exists( 'AI_Chat_Bedrock_Embeddings' ) ) {
			foreach ( (array) AI_Chat_Bedrock_Embeddings::models() as $id => $label ) {
				$metadata[] = $this->embedding_metadata_for( (string) $id, is_string( $label ) ? $label : (string) $id );
			}
		}
		return $metadata;
	}

	/**
	 * Whether a model id is one this site offers.
	 *
	 * @param string $modelId Model identifier.
	 * @return bool
	 */
	public function hasModelMetadata( string $modelId ): bool {
		foreach ( $this->listModelMetadata() as $metadata ) {
			if ( $metadata->getId() === $modelId ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Metadata for a model id.
	 *
	 * @param string $modelId Model identifier.
	 * @return ModelMetadata
	 * @throws InvalidArgumentException When the site does not offer that model.
	 */
	public function getModelMetadata( string $modelId ): ModelMetadata {
		foreach ( $this->listModelMetadata() as $metadata ) {
			if ( $metadata->getId() === $modelId ) {
				return $metadata;
			}
		}
		throw new InvalidArgumentException(
			sprintf(
				/* translators: %s: model identifier. */
				esc_html__( 'This site is not configured for the model %s.', 'ai-chat-for-amazon-bedrock' ),
				esc_html( $modelId )
			)
		);
	}
}

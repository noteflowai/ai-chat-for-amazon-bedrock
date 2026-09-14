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
	 * Build metadata for one model id.
	 *
	 * @param string $model_id Bedrock model identifier.
	 * @return ModelMetadata
	 */
	private function metadata_for( $model_id ) {
		$text = array( ModalityEnum::text() );
		return new ModelMetadata(
			(string) $model_id,
			(string) $model_id,
			array(
				CapabilityEnum::textGeneration(),
				// Multi-message prompts are what the chat path already sends.
				CapabilityEnum::chatHistory(),
			),
			// Only options this adapter actually honours are declared, with their real
			// limits. Declaring more would make core hand over a request that then gets
			// quietly ignored, which is worse than core reporting no matching model.
			array(
				new SupportedOption( OptionEnum::inputModalities(), array( $text ) ),
				new SupportedOption( OptionEnum::outputModalities(), array( $text ) ),
				new SupportedOption( OptionEnum::systemInstruction() ),
				new SupportedOption( OptionEnum::maxTokens() ),
				new SupportedOption( OptionEnum::temperature() ),
				// Bedrock Converse returns a single candidate, so asking for more is a
				// request this cannot meet and should fail rather than under-deliver.
				new SupportedOption( OptionEnum::candidateCount(), array( 1 ) ),
			)
		);
	}

	/**
	 * The models on offer.
	 *
	 * Taken from the site's own configuration rather than a hardcoded list, so a site that
	 * only has access to one model does not advertise others it cannot call.
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
		if ( ! $this->hasModelMetadata( $modelId ) ) {
			throw new InvalidArgumentException(
				sprintf(
					/* translators: %s: model identifier. */
					esc_html__( 'This site is not configured for the model %s.', 'ai-chat-for-amazon-bedrock' ),
					esc_html( $modelId )
				)
			);
		}
		return $this->metadata_for( $modelId );
	}
}

<?php
/**
 * The Amazon Bedrock provider core registers.
 *
 * Part of the WordPress AI Client provider. Loaded only when core's AI Client exists.
 *
 * @package AI_Chat_Bedrock
 */

defined( 'ABSPATH' ) || exit;

// The camelCase parameter names below mirror the core interfaces this implements;
// renaming them would break callers using named arguments.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

use WordPress\AiClient\Providers\AbstractProvider;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;

/**
 * The provider core registers.
 */
class AI_Chat_Bedrock_AI_Provider extends AbstractProvider {

	/**
	 * Build a model instance.
	 *
	 * @param ModelMetadata    $modelMetadata    Model metadata.
	 * @param ProviderMetadata $providerMetadata Provider metadata.
	 * @return ModelInterface
	 */
	protected static function createModel( ModelMetadata $modelMetadata, ProviderMetadata $providerMetadata ): ModelInterface {
		return new AI_Chat_Bedrock_AI_Model( $modelMetadata, $providerMetadata );
	}

	/**
	 * Describe the provider.
	 *
	 * No credentials URL, because there is no key to fetch: access comes from an IAM role.
	 *
	 * @return ProviderMetadata
	 */
	protected static function createProviderMetadata(): ProviderMetadata {
		return new ProviderMetadata(
			AI_Chat_Bedrock_Core_AI::PROVIDER_ID,
			__( 'Amazon Bedrock', 'ai-chat-for-amazon-bedrock' ),
			ProviderTypeEnum::cloud()
		);
	}

	/**
	 * Report configuration state.
	 *
	 * @return ProviderAvailabilityInterface
	 */
	protected static function createProviderAvailability(): ProviderAvailabilityInterface {
		return new AI_Chat_Bedrock_AI_Availability();
	}

	/**
	 * Report available models.
	 *
	 * @return ModelMetadataDirectoryInterface
	 */
	protected static function createModelMetadataDirectory(): ModelMetadataDirectoryInterface {
		return new AI_Chat_Bedrock_AI_Model_Directory();
	}
}

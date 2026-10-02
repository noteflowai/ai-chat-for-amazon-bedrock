<?php
/**
 * Image generation through the plugin's Stability AI path.
 *
 * Part of the WordPress AI Client provider. Loaded only when core's AI Client exists.
 *
 * @package AI_Chat_Bedrock
 */

defined( 'ABSPATH' ) || exit;

// The camelCase parameter names below mirror the core interfaces this implements;
// renaming them would break callers using named arguments.
// phpcs:disable WordPress.NamingConventions.ValidVariableName.VariableNotSnakeCase

use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Models\Contracts\ModelInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\ImageGeneration\Contracts\ImageGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

/**
 * Generates images with a Stability AI model on Amazon Bedrock.
 */
class AI_Chat_Bedrock_AI_Image_Model implements ModelInterface, ImageGenerationModelInterface {

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
	 * The aspect ratio to ask for.
	 *
	 * An explicit ratio wins. An orientation alone maps to the common ratio for it.
	 *
	 * @return string
	 */
	private function aspect_ratio() {
		$ratio = $this->config->getOutputMediaAspectRatio();
		if ( is_string( $ratio ) && in_array( $ratio, AI_Chat_Bedrock_Images::aspect_ratios(), true ) ) {
			return $ratio;
		}
		$orientation = $this->config->getOutputMediaOrientation();
		if ( null !== $orientation ) {
			if ( $orientation->isLandscape() ) {
				return '16:9';
			}
			if ( $orientation->isPortrait() ) {
				return '9:16';
			}
		}
		return '1:1';
	}

	/**
	 * Generate an image for a prompt.
	 *
	 * The text of every user message becomes the prompt, and an image in the prompt is the
	 * reference for image-to-image. Stability models take one prompt and no conversation,
	 * so earlier model turns carry nothing they could use.
	 *
	 * @param Message[] $prompt Core messages.
	 * @return GenerativeAiResult
	 * @throws RuntimeException When the site blocks the call or Bedrock reports a failure.
	 */
	public function generateImageResult( array $prompt ): GenerativeAiResult {
		$allowed = AI_Chat_Bedrock_Core_AI::can_generate();
		if ( is_wp_error( $allowed ) ) {
			throw new RuntimeException( esc_html( $allowed->get_error_message() ) );
		}

		$text      = '';
		$reference = array();
		foreach ( $prompt as $message ) {
			if ( ! $message instanceof Message || $message->getRole()->equals( MessageRoleEnum::model() ) ) {
				continue;
			}
			foreach ( $message->getParts() as $part ) {
				if ( ! $part instanceof MessagePart ) {
					continue;
				}
				if ( null !== $part->getText() ) {
					$text .= ( '' === $text ? '' : "\n\n" ) . $part->getText();
					continue;
				}
				$file = $part->getFile();
				if ( null === $file || ! $file->isImage() || ! $file->isInline() ) {
					throw new RuntimeException( esc_html__( 'Image models here take a text prompt and, for editing, one inline image.', 'ai-chat-for-amazon-bedrock' ) );
				}
				if ( ! empty( $reference ) ) {
					throw new RuntimeException( esc_html__( 'Only one reference image can be edited at a time.', 'ai-chat-for-amazon-bedrock' ) );
				}
				$reference = array(
					'media_type' => $file->getMimeType(),
					'data'       => (string) $file->getBase64Data(),
				);
			}
		}
		$system = $this->config->getSystemInstruction();
		if ( is_string( $system ) && '' !== trim( $system ) ) {
			$text = trim( $system ) . "\n\n" . $text;
		}

		$args = array(
			'model'        => $this->model_metadata->getId(),
			'aspect_ratio' => $this->aspect_ratio(),
			'mime_type'    => (string) $this->config->getOutputMimeType(),
		);
		if ( ! empty( $reference ) ) {
			$args['image'] = $reference;
		}
		$custom = $this->config->getCustomOptions();
		if ( isset( $custom['strength'] ) && is_numeric( $custom['strength'] ) ) {
			$args['strength'] = (float) $custom['strength'];
		}

		$image = AI_Chat_Bedrock_Images::generate( $text, $args );
		if ( is_wp_error( $image ) ) {
			throw new RuntimeException( esc_html( $image->get_error_message() ) );
		}

		$candidate = new Candidate(
			new Message(
				MessageRoleEnum::model(),
				array( new MessagePart( new File( $image['data'], $image['mime_type'] ) ) )
			),
			FinishReasonEnum::stop()
		);
		return new GenerativeAiResult(
			uniqid( 'aicfab_image_', false ),
			array( $candidate ),
			new TokenUsage( 0, 0, 0 ),
			$this->provider_metadata,
			$this->model_metadata,
			array( 'seed' => $image['seed'] )
		);
	}
}

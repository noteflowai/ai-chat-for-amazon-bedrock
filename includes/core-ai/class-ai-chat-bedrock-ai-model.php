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
	 * Most candidates one call returns. Bedrock answers with one, so each is a request.
	 */
	const MAX_CANDIDATES = 8;

	/**
	 * Flatten one core message into the role, text and images this plugin sends.
	 *
	 * Text and inline images are carried across; images only for models that read them,
	 * which is what the directory declares. Anything else, such as a remote file or a
	 * function call, is rejected rather than silently dropped, because a caller that
	 * attached something and received an answer about nothing would have no way to tell.
	 *
	 * @param Message $message Core message.
	 * @return array{role: string, content: string, images: array}
	 * @throws RuntimeException When a part cannot be represented.
	 */
	private function flatten( Message $message ) {
		$text   = '';
		$images = array();
		$role   = $message->getRole()->equals( MessageRoleEnum::model() ) ? 'assistant' : 'user';
		foreach ( $message->getParts() as $part ) {
			if ( ! $part instanceof MessagePart ) {
				throw new RuntimeException( esc_html__( 'The prompt contains a part this provider cannot represent.', 'ai-chat-for-amazon-bedrock' ) );
			}
			if ( null !== $part->getText() ) {
				$text .= ( '' === $text ? '' : "\n\n" ) . $part->getText();
				continue;
			}
			$file = $part->getFile();
			if ( null === $file || 'user' !== $role || ! AI_Chat_Bedrock_Models::accepts_images( $this->model_metadata->getId() ) ) {
				throw new RuntimeException( esc_html__( 'This model takes text only; the prompt contains a part it cannot represent.', 'ai-chat-for-amazon-bedrock' ) );
			}
			if ( ! $file->isInline() || ! in_array( $file->getMimeType(), array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) ) {
				throw new RuntimeException( esc_html__( 'Images must be inline JPEG, PNG, GIF or WebP data. Remote files are not fetched.', 'ai-chat-for-amazon-bedrock' ) );
			}
			$images[] = array(
				'media_type' => $file->getMimeType(),
				'data'       => (string) $file->getBase64Data(),
			);
		}
		return array(
			'role'    => $role,
			'content' => $text,
			'images'  => $images,
		);
	}

	/**
	 * Request data for the plugin's request path, from the prompt and the configuration.
	 *
	 * @param Message[] $prompt Core messages.
	 * @return array
	 * @throws RuntimeException When the prompt has nothing to send.
	 */
	private function request_data( array $prompt ) {
		$messages = array();
		$system   = (string) $this->config->getSystemInstruction();
		if ( $this->wants_json() ) {
			$schema  = $this->config->getOutputSchema();
			$system .= ( '' === $system ? '' : "\n\n" ) . 'Respond with a single JSON value and nothing else: no explanation and no code fences.';
			if ( is_array( $schema ) && ! empty( $schema ) ) {
				$system .= ' The JSON must validate against this JSON Schema: ' . wp_json_encode( $schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			}
		}
		if ( '' !== $system ) {
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

		$data  = array( 'messages' => $messages );
		$top_p = $this->config->getTopP();
		if ( is_numeric( $top_p ) ) {
			$data['top_p'] = (float) $top_p;
		}
		$stop = $this->config->getStopSequences();
		if ( is_array( $stop ) && ! empty( $stop ) ) {
			$data['stop_sequences'] = array_values( array_filter( $stop, 'is_string' ) );
		}
		// Constrained decoding takes an object schema; anything else is left to the prompt
		// and to the check in parse_json().
		$schema = $this->config->getOutputSchema();
		if ( is_array( $schema ) && isset( $schema['type'] ) && 'object' === $schema['type'] ) {
			$data['json_schema'] = $schema;
		}
		return $data;
	}

	/**
	 * Whether the caller asked for JSON.
	 *
	 * @return bool
	 */
	private function wants_json() {
		return 'application/json' === $this->config->getOutputMimeType() || null !== $this->config->getOutputSchema();
	}

	/**
	 * The JSON in an answer, or null when it holds none.
	 *
	 * Models that cannot be constrained sometimes wrap the JSON in a code fence or a sentence.
	 *
	 * @param string $text Model answer.
	 * @return string|null The JSON text.
	 */
	public static function parse_json( $text ) {
		$text = trim( (string) $text );
		if ( preg_match( '/^```(?:json)?\s*(.*?)\s*```$/is', $text, $fence ) ) {
			$text = trim( $fence[1] );
		}
		json_decode( $text );
		if ( '' !== $text && JSON_ERROR_NONE === json_last_error() ) {
			return $text;
		}
		$starts = array_filter(
			array( strpos( $text, '{' ), strpos( $text, '[' ) ),
			static function ( $position ) {
				return false !== $position;
			}
		);
		if ( empty( $starts ) ) {
			return null;
		}
		$start = min( $starts );
		$end   = max( (int) strrpos( $text, '}' ), (int) strrpos( $text, ']' ) );
		if ( $end <= $start ) {
			return null;
		}
		$candidate = substr( $text, $start, $end - $start + 1 );
		json_decode( $candidate );
		return JSON_ERROR_NONE === json_last_error() ? $candidate : null;
	}

	/**
	 * Cut an answer at the first stop sequence, for models that ignored or refused them.
	 *
	 * @param string $text Model answer.
	 * @return array{0: string, 1: bool} The text, and whether a stop sequence was found.
	 */
	private function apply_stop_sequences( $text ) {
		$stop = $this->config->getStopSequences();
		$cut  = null;
		foreach ( is_array( $stop ) ? $stop : array() as $sequence ) {
			if ( is_string( $sequence ) && '' !== $sequence ) {
				$position = strpos( $text, $sequence );
				if ( false !== $position && ( null === $cut || $position < $cut ) ) {
					$cut = $position;
				}
			}
		}
		return null === $cut ? array( $text, false ) : array( substr( $text, 0, $cut ), true );
	}

	/**
	 * One answer from Bedrock.
	 *
	 * @param AI_Chat_Bedrock_AWS $aws  Client.
	 * @param array               $data Request data.
	 * @return array Response from the request path.
	 * @throws RuntimeException When Bedrock reports a failure.
	 */
	private function ask( $aws, $data ) {
		$response = $aws->handle_chat_message( $data );
		if ( empty( $response['success'] ) ) {
			$message = isset( $response['data']['message'] ) ? (string) $response['data']['message'] : __( 'The Amazon Bedrock request failed.', 'ai-chat-for-amazon-bedrock' );
			throw new RuntimeException( esc_html( $message ) );
		}
		return $response;
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

		$data      = $this->request_data( $prompt );
		$overrides = array( 'model_id' => $this->model_metadata->getId() );
		$max       = $this->config->getMaxTokens();
		if ( is_int( $max ) && $max > 0 ) {
			$overrides['max_tokens'] = $max;
		}
		$temperature = $this->config->getTemperature();
		if ( is_numeric( $temperature ) ) {
			$overrides['temperature'] = (float) $temperature;
		}
		$count = (int) $this->config->getCandidateCount();
		$count = max( 1, min( self::MAX_CANDIDATES, $count ) );

		$aws        = new AI_Chat_Bedrock_AWS( $overrides );
		$candidates = array();
		$in         = 0;
		$out        = 0;
		$fallback   = '';
		$request_id = '';
		for ( $i = 0; $i < $count; $i++ ) {
			$response = $this->ask( $aws, $data );
			$text     = isset( $response['data']['message'] ) ? (string) $response['data']['message'] : '';
			$reason   = isset( $response['stop_reason'] ) ? (string) $response['stop_reason'] : '';
			$this->add_usage( $response, $in, $out );

			if ( $this->wants_json() ) {
				$json = self::parse_json( $text );
				if ( null === $json ) {
					// One repair turn, then a clear failure: a caller that asked for JSON
					// cannot use prose, and a partial result would be worse than an error.
					$repair               = $data;
					$repair['messages'][] = array(
						'role'    => 'assistant',
						'content' => $text,
					);
					$repair['messages'][] = array(
						'role'    => 'user',
						'content' => 'That was not valid JSON. Reply again with only the JSON value.',
					);
					$response             = $this->ask( $aws, $repair );
					$this->add_usage( $response, $in, $out );
					$reason = isset( $response['stop_reason'] ) ? (string) $response['stop_reason'] : '';
					$json   = self::parse_json( isset( $response['data']['message'] ) ? (string) $response['data']['message'] : '' );
				}
				if ( null === $json ) {
					throw new RuntimeException( esc_html__( 'The model did not return valid JSON.', 'ai-chat-for-amazon-bedrock' ) );
				}
				$text = $json;
			}

			list( $text, $stopped ) = $this->apply_stop_sequences( $text );
			if ( ! empty( $response['fallback_model'] ) ) {
				$fallback = (string) $response['fallback_model'];
			}
			if ( '' === $request_id && ! empty( $response['request_id'] ) ) {
				$request_id = (string) $response['request_id'];
			}
			$candidates[] = new Candidate(
				new Message(
					MessageRoleEnum::model(),
					array( new MessagePart( $text ) )
				),
				$stopped ? FinishReasonEnum::stop() : self::finish_reason( $reason )
			);
		}

		// A fallback model answered, so report the model that actually ran rather than the
		// one that was asked for.
		$model_metadata = $this->model_metadata;
		if ( '' !== $fallback ) {
			$directory = new AI_Chat_Bedrock_AI_Model_Directory();
			if ( $directory->hasModelMetadata( $fallback ) ) {
				$model_metadata = $directory->getModelMetadata( $fallback );
			}
		}

		return new GenerativeAiResult(
			'' !== $request_id ? $request_id : uniqid( 'aicfab_', false ),
			$candidates,
			new TokenUsage( $in, $out, $in + $out ),
			$this->provider_metadata,
			$model_metadata
		);
	}

	/**
	 * Add one response's token counts.
	 *
	 * @param array $response Response from the request path.
	 * @param int   $in       Input tokens so far.
	 * @param int   $out      Output tokens so far.
	 * @return void
	 */
	private function add_usage( $response, &$in, &$out ) {
		$usage = isset( $response['usage'] ) && is_array( $response['usage'] ) ? $response['usage'] : array();
		$in   += isset( $usage['input_tokens'] ) ? (int) $usage['input_tokens'] : 0;
		$out  += isset( $usage['output_tokens'] ) ? (int) $usage['output_tokens'] : 0;
	}

	/**
	 * Core's finish reason for a Bedrock stop reason.
	 *
	 * @param string $reason Stop reason, such as end_turn, max_tokens or guardrail_intervened.
	 * @return FinishReasonEnum
	 */
	private static function finish_reason( $reason ) {
		if ( in_array( $reason, array( 'max_tokens', 'length', 'model_context_window_exceeded' ), true ) ) {
			return FinishReasonEnum::length();
		}
		if ( in_array( $reason, array( 'guardrail_intervened', 'content_filtered', 'content_filter', 'refusal' ), true ) ) {
			return FinishReasonEnum::contentFilter();
		}
		return FinishReasonEnum::stop();
	}
}

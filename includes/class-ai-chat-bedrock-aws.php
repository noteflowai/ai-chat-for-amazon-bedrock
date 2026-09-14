<?php
/**
 * Amazon Bedrock Runtime client using WordPress HTTP APIs and AWS SigV4.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_AWS {
	private $region;
	private $access_key;
	private $secret_key;
	private $session_token;
	private $credential_source;
	private $credential_error;
	private $debug;

	private $overrides = array();

	public function __construct( $overrides = array() ) {
		$this->overrides         = is_array( $overrides ) ? $overrides : array();
		$options                 = get_option( 'ai_chat_bedrock_settings', array() );
		$options                 = is_array( $options ) ? $options : array();
		$options                 = array_merge( $options, $this->overrides );
		$this->region            = isset( $options['aws_region'] ) ? sanitize_key( $options['aws_region'] ) : 'us-east-1';
		$this->access_key        = '';
		$this->secret_key        = '';
		$this->session_token     = '';
		$this->credential_source = 'none';
		$this->credential_error  = '';
		$this->debug             = isset( $options['debug_mode'] ) && 'on' === $options['debug_mode'];

		$credentials = AI_Chat_Bedrock_AWS_Credentials::resolve( $options );
		if ( is_wp_error( $credentials ) ) {
			$this->credential_error = $credentials->get_error_message();
			return;
		}
		$this->access_key        = $credentials['access_key'];
		$this->secret_key        = $credentials['secret_key'];
		$this->session_token     = $credentials['session_token'];
		$this->credential_source = $credentials['source'];
	}

	/**
	 * Identifier of the resolved credential source.
	 *
	 * @return string
	 */
	/**
	 * What is known about the credentials in use, for error messages.
	 *
	 * @return array
	 */
	private function credential_context() {
		return array(
			'source'    => $this->credential_source,
			'temporary' => '' !== $this->session_token,
		);
	}

	public function credential_source() {
		return $this->credential_source;
	}

	/**
	 * Whether usable credentials were resolved.
	 *
	 * @return bool
	 */
	public function has_credentials() {
		return '' !== $this->access_key && '' !== $this->secret_key;
	}

	/**
	 * Send a conversation to the configured Bedrock model.
	 *
	 * @param array $message_data Request data containing messages and optional tools.
	 * @return array WordPress AJAX-compatible response data.
	 */
	public function handle_chat_message( $message_data ) {
		$prepared = $this->prepare_request( $message_data );
		if ( is_wp_error( $prepared ) ) {
			return $this->error( $prepared->get_error_message(), $prepared->get_error_code() );
		}

		$response = $this->invoke_model( $prepared['payload'], $prepared['model_id'] );
		if ( ! $this->should_fall_back( $response ) ) {
			return $response;
		}

		$fallback = $this->fallback_model_id( $prepared['model_id'] );
		if ( '' === $fallback ) {
			return $response;
		}

		$retry = $this->prepare_request( $message_data, $fallback );
		if ( is_wp_error( $retry ) ) {
			return $response;
		}

		$this->log_debug(
			'Falling back to another model',
			array(
				'from' => $prepared['model_id'],
				'to'   => $fallback,
			)
		);
		$second = $this->invoke_model( $retry['payload'], $fallback );
		if ( empty( $second['success'] ) ) {
			// The primary failure is the more useful one to report, but keep the retry visible.
			return $this->with_fallback_failure( $response, $second );
		}

		$second['fallback_model'] = $fallback;
		return $second;
	}

	/**
	 * Read a prompt from Amazon Bedrock Prompt Management.
	 *
	 * Lets a team keep the system prompt in AWS, versioned and reviewable, instead of
	 * pasting it into every site. The control plane lives on a different host from the
	 * runtime but signs with the same service name.
	 *
	 * @param string $identifier Prompt ID or ARN.
	 * @param string $version    Prompt version, or an empty string for the draft.
	 * @return array|WP_Error
	 */
	public function get_prompt( $identifier, $version = '' ) {
		$identifier = trim( (string) $identifier );
		if ( '' === $identifier || ! preg_match( '#^[A-Za-z0-9:._/-]{1,2048}$#', $identifier ) ) {
			return new WP_Error( 'aicfab_invalid_prompt_id', __( 'The prompt identifier is not valid.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$version = trim( (string) $version );
		if ( '' !== $version && ! preg_match( '/^(?:DRAFT|[0-9]{1,10})$/', $version ) ) {
			return new WP_Error( 'aicfab_invalid_prompt_version', __( 'The prompt version must be a number or DRAFT.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$path = '/prompts/' . rawurlencode( $identifier ) . '/';
		if ( '' !== $version && 'DRAFT' !== $version ) {
			$path .= '?promptVersion=' . rawurlencode( $version );
		}

		return $this->control_plane_get( $path, 'bedrock-agent' );
	}

	/**
	 * Base URL for an AWS service in the configured region.
	 *
	 * Kept in one place so a deployment can point at an alternative endpoint, such as
	 * a FIPS endpoint or a VPC interface endpoint, without patching call sites.
	 *
	 * @param string $service Service host prefix, for example bedrock-runtime.
	 * @return string Base URL with no trailing slash.
	 */
	private function service_endpoint( $service ) {
		$service = preg_match( '/^[a-z][a-z0-9-]{0,60}$/', (string) $service ) ? (string) $service : 'bedrock-runtime';
		// An AWS service API endpoint, not offloaded assets: every request is a signed API call.
		$default = 'https://' . $service . '.' . $this->region . '.amazonaws.com'; // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent
		$url     = (string) apply_filters( 'ai_chat_bedrock_service_endpoint', $default, $service, $this->region );

		if ( 0 !== strpos( $url, 'https://' ) ) {
			return $default;
		}
		return untrailingslashit( $url );
	}

	/**
	 * Create an embedding vector for one piece of text.
	 *
	 * Titan and Cohere use different request and response shapes, so both are
	 * handled here rather than in the caller.
	 *
	 * @param string $text     Text to embed.
	 * @param string $model_id Embedding model identifier.
	 * @return array|WP_Error List of floats, or an error.
	 */
	/**
	 * Read a guardrail so a misconfiguration is caught before a visitor hits it.
	 *
	 * Bedrock fails closed on a wrong guardrail identifier: every request is refused with a
	 * ValidationException, so the whole chat stops working. Checking here turns that into a
	 * sentence on the Diagnostics screen instead.
	 *
	 * @param string $identifier Guardrail ID or ARN.
	 * @param string $version    Guardrail version, or DRAFT.
	 * @return array|WP_Error Array with name, status and version.
	 */
	public function get_guardrail( $identifier, $version = '' ) {
		$identifier = trim( (string) $identifier );
		if ( ! preg_match( '#^[a-zA-Z0-9]{1,64}$|^arn:aws[a-zA-Z0-9:/._-]{1,2000}$#', $identifier ) ) {
			return new WP_Error( 'aicfab_invalid_guardrail', __( 'The guardrail identifier is not in a usable format.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$path    = '/guardrails/' . rawurlencode( $identifier );
		$version = trim( (string) $version );
		if ( '' !== $version && 'DRAFT' !== strtoupper( $version ) ) {
			if ( ! preg_match( '/^[0-9]{1,8}$/', $version ) ) {
				return new WP_Error( 'aicfab_invalid_guardrail_version', __( 'The guardrail version must be DRAFT or a number.', 'ai-chat-for-amazon-bedrock' ) );
			}
			$path .= '?guardrailVersion=' . rawurlencode( $version );
		}

		$data = $this->control_plane_get( $path );
		if ( is_wp_error( $data ) ) {
			return $data;
		}

		return array(
			'name'    => isset( $data['name'] ) ? sanitize_text_field( (string) $data['name'] ) : '',
			'status'  => isset( $data['status'] ) ? sanitize_text_field( (string) $data['status'] ) : '',
			'version' => isset( $data['version'] ) ? sanitize_text_field( (string) $data['version'] ) : '',
		);
	}

	public function embed( $text, $model_id ) {
		$text = trim( (string) $text );
		if ( '' === $text ) {
			return new WP_Error( 'aicfab_empty_text', __( 'There is nothing to embed.', 'ai-chat-for-amazon-bedrock' ) );
		}
		if ( ! $this->has_credentials() ) {
			$message = '' !== $this->credential_error ? $this->credential_error : __( 'Amazon Bedrock credentials are not configured.', 'ai-chat-for-amazon-bedrock' );
			return new WP_Error( 'aicfab_no_credentials', $message );
		}

		$model_id = sanitize_text_field( (string) $model_id );
		if ( ! preg_match( '/^[A-Za-z0-9._:\/-]{1,200}$/', $model_id ) ) {
			return new WP_Error( 'aicfab_invalid_model', __( 'The configured embedding model ID is invalid.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$text    = AI_Chat_Bedrock_Security::string_substr( $text, 0, 8000 );
		$payload = false !== strpos( $model_id, 'cohere' )
			? array(
				'texts'      => array( $text ),
				'input_type' => 'search_document',
			)
			: array( 'inputText' => $text );

		$response = $this->invoke_model( $payload, $model_id );
		if ( empty( $response['success'] ) ) {
			$code = isset( $response['data']['code'] ) ? (string) $response['data']['code'] : 'aicfab_error';
			return new WP_Error( $code, isset( $response['data']['message'] ) ? (string) $response['data']['message'] : __( 'The embedding request failed.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$vector = isset( $response['embedding'] ) && is_array( $response['embedding'] ) ? $response['embedding'] : array();
		if ( empty( $vector ) ) {
			return new WP_Error( 'aicfab_no_embedding', __( 'Amazon Bedrock returned no embedding.', 'ai-chat-for-amazon-bedrock' ) );
		}
		return $vector;
	}

	/**
	 * Whether a failed response is worth retrying on the fallback model.
	 *
	 * Only infrastructure and access problems qualify. A rejected payload, a missing
	 * credential or the site's own daily limit would fail again on any model.
	 *
	 * @param array $response Response from a model invocation.
	 * @return bool
	 */
	private function should_fall_back( $response ) {
		if ( ! is_array( $response ) || ! empty( $response['success'] ) ) {
			return false;
		}

		$code   = isset( $response['data']['code'] ) ? (string) $response['data']['code'] : '';
		$status = isset( $response['data']['status'] ) ? (int) $response['data']['status'] : 0;

		if ( 'aicfab_unreachable' === $code ) {
			return true;
		}
		if ( 'aicfab_http_error' !== $code ) {
			return false;
		}

		/*
		 * Bedrock answers 400 both for a payload it rejected and for a model
		 * identifier it does not recognize. Only the second case is worth another
		 * model, so a 400 is retried only when the error body names the model.
		 */
		if ( 400 === $status ) {
			return ! empty( $response['data']['model_error'] );
		}

		// 403 covers a model the account cannot invoke, 429 throttling, 5xx service faults.
		return 403 === $status || 429 === $status || $status >= 500;
	}

	/**
	 * Whether a Bedrock error body blames the model rather than the request.
	 *
	 * Only the shape of the message is inspected; the body itself is never logged
	 * or returned to the browser.
	 *
	 * @param string $body Raw response body.
	 * @return bool
	 */
	public static function is_model_unavailable( $body ) {
		$body = (string) $body;
		if ( '' === $body ) {
			return false;
		}

		$decoded = json_decode( $body, true );
		$message = '';
		if ( is_array( $decoded ) ) {
			$message .= isset( $decoded['message'] ) ? (string) $decoded['message'] : '';
			$message .= ' ' . ( isset( $decoded['Message'] ) ? (string) $decoded['Message'] : '' );
			$message .= ' ' . ( isset( $decoded['__type'] ) ? (string) $decoded['__type'] : '' );
		} else {
			$message = $body;
		}

		$needles = apply_filters(
			'ai_chat_bedrock_model_error_needles',
			array(
				'model identifier',
				'model not found',
				'resourcenotfound',
				"isn't supported",
				'is not supported',
				'do not have access to the model',
				"don't have access to the model",
				'model is not available',
				'invalid model',
			)
		);

		$message = strtolower( $message );
		foreach ( (array) $needles as $needle ) {
			if ( '' !== $needle && false !== strpos( $message, strtolower( (string) $needle ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The configured fallback model, when it is usable and different from the primary.
	 *
	 * @param string $primary Model that already failed.
	 * @return string Empty string when no usable fallback is configured.
	 */
	private function fallback_model_id( $primary ) {
		$options  = get_option( 'ai_chat_bedrock_settings', array() );
		$options  = is_array( $options ) ? $options : array();
		$options  = array_merge( $options, $this->overrides );
		$fallback = isset( $options['fallback_model_id'] ) ? sanitize_text_field( (string) $options['fallback_model_id'] ) : '';
		$fallback = (string) apply_filters( 'ai_chat_bedrock_fallback_model', $fallback, $primary );

		if ( '' === $fallback || $fallback === $primary ) {
			return '';
		}
		if ( ! preg_match( '/^[A-Za-z0-9._:-]{1,200}$/', $fallback ) ) {
			return '';
		}
		if ( class_exists( 'AI_Chat_Bedrock_Models' ) && ! AI_Chat_Bedrock_Models::is_valid_id( $fallback ) ) {
			return '';
		}
		return $fallback;
	}

	/**
	 * Validate configuration and build the model payload for a conversation.
	 *
	 * @param array $message_data Request data containing messages and optional tools.
	 * @return array|WP_Error
	 */
	private function prepare_request( $message_data, $force_model = '' ) {
		if ( ! $this->has_credentials() ) {
			$message = '' !== $this->credential_error ? $this->credential_error : __( 'Amazon Bedrock credentials are not configured.', 'ai-chat-for-amazon-bedrock' );
			return new WP_Error( 'aicfab_no_credentials', $message );
		}
		if ( ! preg_match( '/^[a-z]{2}(?:-gov)?-[a-z]+-\d$/', $this->region ) ) {
			return new WP_Error( 'aicfab_invalid_region', __( 'The configured AWS region is invalid.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$options  = get_option( 'ai_chat_bedrock_settings', array() );
		$options  = is_array( $options ) ? $options : array();
		$options  = array_merge( $options, $this->overrides );
		$model_id = isset( $options['model_id'] ) ? sanitize_text_field( $options['model_id'] ) : 'anthropic.claude-3-haiku-20240307-v1:0';
		if ( '' !== $force_model ) {
			$model_id = sanitize_text_field( (string) $force_model );
		}
		$max_tokens  = max( 100, min( 4000, isset( $options['max_tokens'] ) ? absint( $options['max_tokens'] ) : 1000 ) );
		$temperature = max( 0, min( 1, isset( $options['temperature'] ) ? (float) $options['temperature'] : 0.7 ) );
		if ( ! preg_match( '/^[A-Za-z0-9._:-]{1,200}$/', $model_id ) ) {
			return new WP_Error( 'aicfab_invalid_model', __( 'The configured model ID is invalid.', 'ai-chat-for-amazon-bedrock' ) );
		}

		if ( class_exists( 'AI_Chat_Bedrock_Usage' ) && AI_Chat_Bedrock_Usage::daily_limit_reached( $options ) ) {
			return new WP_Error( 'aicfab_daily_limit', __( 'The daily Amazon Bedrock request limit for this site has been reached.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$payload = $this->format_payload_for_model( $model_id, $message_data, $max_tokens, $temperature );
		if ( is_wp_error( $payload ) ) {
			return $payload;
		}
		return array(
			'payload'  => $payload,
			'model_id' => $model_id,
		);
	}

	/**
	 * Whether the server can stream Bedrock responses.
	 *
	 * @return bool
	 */
	public static function streaming_supported() {
		$supported = function_exists( 'curl_init' ) && function_exists( 'curl_setopt' );
		return (bool) apply_filters( 'ai_chat_bedrock_streaming_supported', $supported );
	}

	/**
	 * Stream a conversation from Bedrock, emitting incremental text as it arrives.
	 *
	 * @param array    $message_data Request data containing messages and optional tools.
	 * @param callable $on_delta     Receives each text delta.
	 * @return array Response array with the complete message, tool calls and usage.
	 */
	public function stream_chat_message( $message_data, $on_delta ) {
		if ( ! self::streaming_supported() ) {
			return $this->error( __( 'Streaming is not available on this server.', 'ai-chat-for-amazon-bedrock' ), 'aicfab_streaming_unavailable' );
		}

		$prepared = $this->prepare_request( $message_data );
		if ( is_wp_error( $prepared ) ) {
			return $this->error( $prepared->get_error_message(), $prepared->get_error_code() );
		}

		/*
		 * A stream can only be retried while nothing has been sent to the browser yet.
		 * Once a delta is out, restarting would duplicate text, so the failure stands.
		 */
		$emitted  = false;
		$observer = function ( $delta ) use ( $on_delta, &$emitted ) {
			$emitted = true;
			if ( is_callable( $on_delta ) ) {
				// Pass the answer through: false means the consumer wants to stop.
				return call_user_func( $on_delta, $delta );
			}
			return true;
		};

		$response = $this->stream_once( $observer, $prepared );
		if ( $emitted || ! $this->should_fall_back( $response ) ) {
			return $response;
		}

		$fallback = $this->fallback_model_id( $prepared['model_id'] );
		if ( '' === $fallback ) {
			return $response;
		}

		$retry = $this->prepare_request( $message_data, $fallback );
		if ( is_wp_error( $retry ) ) {
			return $response;
		}

		$this->log_debug(
			'Falling back to another model for streaming',
			array(
				'from' => $prepared['model_id'],
				'to'   => $fallback,
			)
		);
		$second = $this->stream_once( $observer, $retry );
		if ( empty( $second['success'] ) ) {
			return $this->with_fallback_failure( $response, $second );
		}

		$second['fallback_model'] = $fallback;
		return $second;
	}

	/**
	 * Run one streaming attempt against an already prepared request.
	 *
	 * @param callable $on_delta Receives each text delta.
	 * @param array    $prepared Prepared payload and model.
	 * @return array
	 */
	private function stream_once( $on_delta, $prepared ) {
		$model_id = $prepared['model_id'];
		$body     = wp_json_encode( $prepared['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $body ) {
			return $this->error( __( 'The Bedrock request could not be encoded.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$endpoint          = $this->service_endpoint( 'bedrock-runtime' ) . '/model/' . rawurlencode( $model_id ) . '/invoke-with-response-stream';
		$headers           = $this->signed_headers( $endpoint, $body, 'POST', 'bedrock', $this->guardrail_headers() );
		$headers['Accept'] = 'application/vnd.amazon.eventstream';

		$curl_headers = array();
		foreach ( $headers as $name => $value ) {
			$curl_headers[] = $name . ': ' . $value;
		}

		$state   = array(
			'buffer'     => '',
			'raw'        => '',
			'stopped'    => false,
			'text'       => '',
			'bytes'      => 0,
			'tools'      => array(),
			'usage'      => array(),
			'stream_err' => '',
		);
		$limit   = (int) apply_filters( 'ai_chat_bedrock_stream_max_bytes', 4194304 );
		$timeout = max( 10, min( 300, (int) apply_filters( 'ai_chat_bedrock_http_timeout', 120 ) ) );

		$this->log_debug(
			'Streaming request',
			array(
				'model'         => $model_id,
				'region'        => $this->region,
				'payload_bytes' => strlen( $body ),
			)
		);

		$handle = curl_init(); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init
		curl_setopt_array( // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
			$handle,
			array(
				CURLOPT_URL            => $endpoint,
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => $body,
				CURLOPT_HTTPHEADER     => $curl_headers,
				CURLOPT_RETURNTRANSFER => false,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_CAINFO         => ABSPATH . WPINC . '/certificates/ca-bundle.crt',
				CURLOPT_TIMEOUT        => $timeout,
				CURLOPT_CONNECTTIMEOUT => 10,
				CURLOPT_WRITEFUNCTION  => function ( $unused, $chunk ) use ( &$state, $model_id, $on_delta, $limit ) {
					$length         = strlen( $chunk );
					$state['bytes'] += $length;
					if ( $state['bytes'] > $limit ) {
						return 0;
					}
					if ( strlen( $state['raw'] ) < 2048 ) {
						$state['raw'] .= substr( $chunk, 0, 2048 - strlen( $state['raw'] ) );
					}
					$state['buffer'] .= $chunk;

					foreach ( AI_Chat_Bedrock_Event_Stream::extract_events( $state['buffer'] ) as $event ) {
						if ( '' !== $event['error'] || ( '' !== $event['message'] && 'event' !== $event['message'] ) ) {
							$state['stream_err'] = 'stream_exception';
							continue;
						}

						$delta = AI_Chat_Bedrock_Event_Stream::text_delta( $event['payload'], $model_id );
						if ( '' !== $delta ) {
							$state['text'] .= $delta;
							if ( is_callable( $on_delta ) ) {
								// A consumer that returns false is asking to stop, which is
								// how a visitor closing the tab stops the paid request
								// instead of it running to completion unseen.
								if ( false === call_user_func( $on_delta, $delta ) ) {
									$state['stopped'] = true;
									return 0;
								}
							}
						}

						$fragment = AI_Chat_Bedrock_Event_Stream::tool_fragment( $event['payload'], $model_id );
						if ( is_array( $fragment ) ) {
							$index = $fragment['index'];
							if ( 'start' === $fragment['stage'] ) {
								$state['tools'][ $index ] = array(
									'id'   => $fragment['id'],
									'name' => $fragment['name'],
									'json' => '',
								);
							} elseif ( isset( $state['tools'][ $index ] ) ) {
								$state['tools'][ $index ]['json'] .= $fragment['partial'];
							}
						}

						$usage = AI_Chat_Bedrock_Event_Stream::usage( $event['payload'] );
						if ( ! empty( $usage ) ) {
							$state['usage'] = array_merge( $state['usage'], $usage );
						}
					}
					return $length;
				},
			)
		);

		$completed = curl_exec( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec
		$stopped   = ! empty( $state['stopped'] );
		$status    = (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo
		$errno     = curl_errno( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_errno
		curl_close( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close

		$this->log_debug(
			'Streaming response',
			array(
				'status' => $status,
				'code'   => $errno,
			)
		);

		if ( $status >= 400 ) {
			// The streaming body is consumed by the write callback, so classify what it captured.
			$explained = AI_Chat_Bedrock_Bedrock_Errors::explain( $status, $state['raw'], $model_id, $this->region, $this->credential_context() );
			$failure   = $this->error(
				$explained['message'],
				'aicfab_http_error',
				$status
			);
			// The streaming body is consumed by the write callback, so use whatever it captured.
			if ( self::is_model_unavailable( $state['raw'] ) ) {
				$failure['data']['model_error'] = true;
			}
			return $failure;
		}
		// Stopping on purpose is not an interruption: cURL reports a write error because the
		// callback asked it to stop, and whatever arrived is a real partial answer.
		if ( ! $stopped && false === $completed && 0 !== $errno && '' === $state['text'] ) {
			return $this->error( __( 'The Bedrock response stream was interrupted.', 'ai-chat-for-amazon-bedrock' ), 'aicfab_stream_interrupted' );
		}

		$tool_calls = array();
		foreach ( $state['tools'] as $tool ) {
			if ( '' === $tool['name'] ) {
				continue;
			}
			$parameters = array();
			if ( '' !== trim( $tool['json'] ) ) {
				$decoded    = json_decode( $tool['json'], true );
				$parameters = is_array( $decoded ) ? $decoded : array();
			}
			$tool_calls[] = array(
				'id'         => $tool['id'],
				'name'       => $tool['name'],
				'parameters' => $parameters,
			);
		}

		if ( '' === trim( $state['text'] ) && empty( $tool_calls ) ) {
			return $this->error( __( 'Amazon Bedrock returned no usable content.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$result = array(
			'success' => true,
			'data'    => array( 'message' => $state['text'] ),
		);
		if ( ! empty( $tool_calls ) ) {
			$result['tool_calls'] = $tool_calls;
		}
		if ( ! empty( $state['usage'] ) ) {
			$result['usage'] = $state['usage'];
		}
		if ( $stopped ) {
			// The caller asked to stop, so the answer is partial by design.
			$result['stopped'] = true;
		}
		$this->record_usage( $state['usage'], $model_id );
		return $result;
	}

	private function format_payload_for_model( $model_id, $message_data, $max_tokens, $temperature ) {
		$messages = isset( $message_data['messages'] ) && is_array( $message_data['messages'] ) ? $message_data['messages'] : array();
		$system   = '';
		$chat     = array();
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) || ! isset( $message['role'], $message['content'] ) || ! is_string( $message['content'] ) ) {
				continue;
			}
			$role    = sanitize_key( $message['role'] );
			$content = $message['content'];
			if ( 'system' === $role ) {
				$system .= ( '' === $system ? '' : "\n\n" ) . $content;
			} elseif ( in_array( $role, array( 'user', 'assistant' ), true ) && '' !== trim( $content ) ) {
				$chat[] = array(
					'role'    => $role,
					'content' => $content,
				);
			}
		}
		if ( empty( $chat ) ) {
			return new WP_Error( 'empty_conversation', __( 'No valid chat messages were supplied.', 'ai-chat-for-amazon-bedrock' ) );
		}

		if ( false !== strpos( $model_id, 'anthropic.claude' ) ) {
			$messages_out = $this->normalize_claude_messages( $chat );

			// Optional image input, used by features such as alt text generation.
			if ( ! empty( $message_data['image'] ) && is_array( $message_data['image'] ) ) {
				$image = $message_data['image'];
				$media = isset( $image['media_type'] ) ? (string) $image['media_type'] : '';
				$data  = isset( $image['data'] ) ? (string) $image['data'] : '';
				if ( in_array( $media, array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true ) && '' !== $data ) {
					$last                             = count( $messages_out ) - 1;
					$text                             = isset( $messages_out[ $last ]['content'] ) ? (string) $messages_out[ $last ]['content'] : '';
					$messages_out[ $last ]['content'] = array(
						array(
							'type'   => 'image',
							'source' => array(
								'type'       => 'base64',
								'media_type' => $media,
								'data'       => $data,
							),
						),
						array(
							'type' => 'text',
							'text' => $text,
						),
					);
				}
			}

			$payload = array(
				'anthropic_version' => 'bedrock-2023-05-31',
				'max_tokens'        => $max_tokens,
				'temperature'       => $temperature,
				'messages'          => $messages_out,
			);
			if ( '' !== $system ) {
				$payload['system'] = $system;
			}
			if ( ! empty( $message_data['tools'] ) && is_array( $message_data['tools'] ) ) {
				$payload['tools']       = array_slice( $message_data['tools'], 0, 50 );
				$payload['tool_choice'] = array( 'type' => 'auto' );
			}
			return $payload;
		}

		if ( false !== strpos( $model_id, 'amazon.nova' ) ) {
			$nova_messages = array();
			foreach ( $chat as $message ) {
				$nova_messages[] = array(
					'role'    => $message['role'],
					'content' => array( array( 'text' => $message['content'] ) ),
				);
			}
			$payload = array(
				'messages'        => $nova_messages,
				'inferenceConfig' => array(
					'maxTokens'   => $max_tokens,
					'temperature' => $temperature,
				),
			);
			if ( '' !== $system ) {
				$payload['system'] = array( array( 'text' => $system ) );
			}
			return $payload;
		}

		$prompt = '' !== $system ? $system . "\n\n" : '';
		foreach ( $chat as $message ) {
			$prompt .= ( 'user' === $message['role'] ? 'User: ' : 'Assistant: ' ) . $message['content'] . "\n";
		}
		$prompt .= 'Assistant: ';

		if ( false !== strpos( $model_id, 'amazon.titan' ) ) {
			return array(
				'inputText'            => $prompt,
				'textGenerationConfig' => array(
					'maxTokenCount' => $max_tokens,
					'temperature'   => $temperature,
				),
			);
		}
		if ( false !== strpos( $model_id, 'meta.llama' ) ) {
			return array(
				'prompt'      => $prompt,
				'max_gen_len' => $max_tokens,
				'temperature' => $temperature,
			);
		}
		return array(
			'prompt'      => $prompt,
			'max_tokens'  => $max_tokens,
			'temperature' => $temperature,
		);
	}

	private function normalize_claude_messages( $messages ) {
		$normalized = array();
		foreach ( $messages as $message ) {
			$last = count( $normalized ) - 1;
			if ( $last >= 0 && $normalized[ $last ]['role'] === $message['role'] ) {
				$normalized[ $last ]['content'] .= "\n\n" . $message['content'];
			} else {
				$normalized[] = $message;
			}
		}
		if ( 'user' !== $normalized[0]['role'] ) {
			array_unshift(
				$normalized,
				array(
					'role'    => 'user',
					'content' => 'Continue the conversation.',
				)
			);
		}
		return $normalized;
	}

	private function invoke_model( $payload, $model_id ) {
		$endpoint = $this->service_endpoint( 'bedrock-runtime' ) . '/model/' . rawurlencode( $model_id ) . '/invoke';
		$body     = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $body ) {
			return $this->error( __( 'The Bedrock request could not be encoded.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$headers = $this->signed_headers( $endpoint, $body, 'POST', 'bedrock', $this->guardrail_headers() );
		$timeout = (int) apply_filters( 'ai_chat_bedrock_http_timeout', 120 );
		$this->log_debug(
			'Sending request',
			array(
				'model'         => $model_id,
				'region'        => $this->region,
				'payload_bytes' => strlen( $body ),
			)
		);
		$response = wp_safe_remote_post(
			$endpoint,
			array(
				'timeout'            => max( 10, min( 300, $timeout ) ),
				'redirection'        => 0,
				'httpversion'        => '1.1',
				'reject_unsafe_urls' => true,
				'headers'            => $headers,
				'body'               => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->log_debug( 'Transport error', array( 'code' => $response->get_error_code() ) );
			return $this->error( __( 'Amazon Bedrock could not be reached.', 'ai-chat-for-amazon-bedrock' ), 'aicfab_unreachable', 0 );
		}
		$status     = (int) wp_remote_retrieve_response_code( $response );
		$request_id = wp_remote_retrieve_header( $response, 'x-amzn-requestid' );
		$this->log_debug(
			'Response received',
			array(
				'status'     => $status,
				'request_id' => sanitize_text_field( $request_id ),
			)
		);
		if ( $status < 200 || $status >= 300 ) {
			$explained = AI_Chat_Bedrock_Bedrock_Errors::explain( $status, wp_remote_retrieve_body( $response ), $model_id, $this->region, $this->credential_context() );
			$failure   = $this->error(
				$explained['message'],
				'aicfab_http_error',
				$status
			);
			if ( self::is_model_unavailable( wp_remote_retrieve_body( $response ) ) ) {
				$failure['data']['model_error'] = true;
			}
			return $failure;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return $this->error( __( 'Amazon Bedrock returned an invalid response.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$parsed = $this->parse_model_response( $data, $model_id );
		if ( ! empty( $parsed['success'] ) ) {
			$usage = $this->merge_header_usage( isset( $parsed['usage'] ) ? $parsed['usage'] : array(), $response );
			if ( ! empty( $usage ) ) {
				$parsed['usage'] = $usage;
			}
			$this->record_usage( $usage, $model_id );
		}
		return $parsed;
	}

	/**
	 * Optional Amazon Bedrock Guardrails headers.
	 *
	 * @return array
	 */
	private function guardrail_headers() {
		$options = get_option( 'ai_chat_bedrock_settings', array() );
		$options = is_array( $options ) ? $options : array();
		$id      = isset( $options['guardrail_id'] ) ? trim( (string) $options['guardrail_id'] ) : '';
		$version = isset( $options['guardrail_version'] ) ? trim( (string) $options['guardrail_version'] ) : '';

		if ( '' === $id || ! preg_match( '#^[A-Za-z0-9._:/-]{1,200}$#', $id ) ) {
			return array();
		}
		if ( '' === $version || ! preg_match( '/^(?:DRAFT|[0-9]{1,10})$/', $version ) ) {
			$version = 'DRAFT';
		}
		return array(
			'x-amzn-bedrock-guardrailidentifier' => $id,
			'x-amzn-bedrock-guardrailversion'    => $version,
		);
	}

	private function merge_header_usage( $usage, $response ) {
		$usage  = is_array( $usage ) ? $usage : array();
		$input  = wp_remote_retrieve_header( $response, 'x-amzn-bedrock-input-token-count' );
		$output = wp_remote_retrieve_header( $response, 'x-amzn-bedrock-output-token-count' );

		if ( ! isset( $usage['input_tokens'] ) && is_numeric( $input ) ) {
			$usage['input_tokens'] = (int) $input;
		}
		if ( ! isset( $usage['output_tokens'] ) && is_numeric( $output ) ) {
			$usage['output_tokens'] = (int) $output;
		}
		return $usage;
	}

	private function record_usage( $usage, $model_id = '' ) {
		if ( class_exists( 'AI_Chat_Bedrock_Usage' ) ) {
			AI_Chat_Bedrock_Usage::record( is_array( $usage ) ? $usage : array(), (string) $model_id );
		}
	}

	/**
	 * Configured AWS region.
	 *
	 * @return string
	 */
	public function region() {
		return $this->region;
	}

	/**
	 * List text-capable Bedrock foundation models available in the region.
	 *
	 * @return array|WP_Error
	 */
	public function list_foundation_models() {
		$response = $this->control_plane_get( '/foundation-models?byOutputModality=TEXT' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$models = array();
		foreach ( isset( $response['modelSummaries'] ) && is_array( $response['modelSummaries'] ) ? $response['modelSummaries'] : array() as $summary ) {
			if ( ! is_array( $summary ) || empty( $summary['modelId'] ) ) {
				continue;
			}
			$lifecycle = isset( $summary['modelLifecycle']['status'] ) ? (string) $summary['modelLifecycle']['status'] : '';
			$types     = isset( $summary['inferenceTypesSupported'] ) && is_array( $summary['inferenceTypesSupported'] ) ? $summary['inferenceTypesSupported'] : array();
			if ( ! in_array( 'ON_DEMAND', $types, true ) && ! in_array( 'INFERENCE_PROFILE', $types, true ) ) {
				continue;
			}
			$models[] = array(
				'id'        => (string) $summary['modelId'],
				'name'      => isset( $summary['modelName'] ) ? (string) $summary['modelName'] : (string) $summary['modelId'],
				'provider'  => isset( $summary['providerName'] ) ? (string) $summary['providerName'] : '',
				'lifecycle' => $lifecycle,
				'on_demand' => in_array( 'ON_DEMAND', $types, true ),
				'profile'   => in_array( 'INFERENCE_PROFILE', $types, true ),
			);
		}
		return $models;
	}

	/**
	 * List cross-region inference profiles, which many current models require.
	 *
	 * @return array|WP_Error
	 */
	public function list_inference_profiles() {
		$response = $this->control_plane_get( '/inference-profiles?maxResults=100' );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$profiles = array();
		foreach ( isset( $response['inferenceProfileSummaries'] ) && is_array( $response['inferenceProfileSummaries'] ) ? $response['inferenceProfileSummaries'] : array() as $summary ) {
			if ( ! is_array( $summary ) || empty( $summary['inferenceProfileId'] ) ) {
				continue;
			}
			if ( isset( $summary['status'] ) && 'ACTIVE' !== $summary['status'] ) {
				continue;
			}
			$profiles[] = array(
				'id'   => (string) $summary['inferenceProfileId'],
				'name' => isset( $summary['inferenceProfileName'] ) ? (string) $summary['inferenceProfileName'] : (string) $summary['inferenceProfileId'],
				'type' => isset( $summary['type'] ) ? (string) $summary['type'] : '',
			);
		}
		return $profiles;
	}

	/**
	 * Send a minimal request to confirm the configured model can be invoked.
	 *
	 * @param string $model_id Optional model override.
	 * @return array Result with success, message and duration keys.
	 */
	public function test_model_access( $model_id = '' ) {
		$options  = get_option( 'ai_chat_bedrock_settings', array() );
		$options  = is_array( $options ) ? $options : array();
		$model_id = '' !== $model_id ? sanitize_text_field( $model_id ) : ( isset( $options['model_id'] ) ? sanitize_text_field( $options['model_id'] ) : '' );

		if ( ! $this->has_credentials() ) {
			$message = '' !== $this->credential_error ? $this->credential_error : __( 'Amazon Bedrock credentials are not configured.', 'ai-chat-for-amazon-bedrock' );
			return array(
				'success' => false,
				'message' => $message,
			);
		}
		if ( ! preg_match( '/^[A-Za-z0-9._:-]{1,200}$/', (string) $model_id ) ) {
			return array(
				'success' => false,
				'message' => __( 'The configured model ID is invalid.', 'ai-chat-for-amazon-bedrock' ),
			);
		}

		$payload = $this->format_payload_for_model(
			$model_id,
			array(
				'messages' => array(
					array(
						'role'    => 'user',
						'content' => 'Reply with the single word: ok',
					),
				),
			),
			16,
			0
		);
		if ( is_wp_error( $payload ) ) {
			return array(
				'success' => false,
				'message' => $payload->get_error_message(),
			);
		}

		$started  = microtime( true );
		$response = $this->invoke_model( $payload, $model_id );
		$duration = (int) round( ( microtime( true ) - $started ) * 1000 );

		if ( empty( $response['success'] ) ) {
			return array(
				'success'  => false,
				'message'  => isset( $response['data']['message'] ) ? $response['data']['message'] : __( 'Amazon Bedrock could not be reached.', 'ai-chat-for-amazon-bedrock' ),
				'duration' => $duration,
				'model'    => $model_id,
			);
		}

		return array(
			'success'  => true,
			'message'  => __( 'Amazon Bedrock responded successfully.', 'ai-chat-for-amazon-bedrock' ),
			'duration' => $duration,
			'model'    => $model_id,
			'usage'    => isset( $response['usage'] ) ? $response['usage'] : array(),
		);
	}

	/**
	 * Retrieve passages from an Amazon Bedrock knowledge base.
	 *
	 * @param string $knowledge_base_id Knowledge base identifier.
	 * @param string $query             Search query.
	 * @param int    $limit             Maximum passages.
	 * @return array|WP_Error
	 */
	public function retrieve_from_knowledge_base( $knowledge_base_id, $query, $limit = 3 ) {
		$knowledge_base_id = trim( (string) $knowledge_base_id );
		$query             = trim( (string) $query );
		$limit             = max( 1, min( 10, absint( $limit ) ) );

		if ( ! $this->has_credentials() ) {
			$message = '' !== $this->credential_error ? $this->credential_error : __( 'Amazon Bedrock credentials are not configured.', 'ai-chat-for-amazon-bedrock' );
			return new WP_Error( 'aicfab_no_credentials', $message );
		}
		if ( ! preg_match( '/^[A-Za-z0-9]{1,64}$/', $knowledge_base_id ) ) {
			return new WP_Error( 'aicfab_invalid_knowledge_base', __( 'The knowledge base ID is invalid.', 'ai-chat-for-amazon-bedrock' ) );
		}
		if ( '' === $query ) {
			return new WP_Error( 'aicfab_empty_query', __( 'A search query is required.', 'ai-chat-for-amazon-bedrock' ) );
		}
		if ( ! preg_match( '/^[a-z]{2}(?:-gov)?-[a-z]+-\d$/', $this->region ) ) {
			return new WP_Error( 'aicfab_invalid_region', __( 'The configured AWS region is invalid.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$endpoint = $this->service_endpoint( 'bedrock-agent-runtime' ) . '/knowledgebases/' . rawurlencode( $knowledge_base_id ) . '/retrieve';
		$payload  = array(
			'retrievalQuery'         => array( 'text' => AI_Chat_Bedrock_Security::string_substr( $query, 0, 1000 ) ),
			'retrievalConfiguration' => array(
				'vectorSearchConfiguration' => array( 'numberOfResults' => $limit ),
			),
		);
		$body     = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $body ) {
			return new WP_Error( 'aicfab_encode_failed', __( 'The retrieval request could not be encoded.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$response = wp_safe_remote_post(
			$endpoint,
			array(
				'timeout'            => 20,
				'redirection'        => 0,
				'httpversion'        => '1.1',
				'reject_unsafe_urls' => true,
				'headers'            => $this->signed_headers( $endpoint, $body ),
				'body'               => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'aicfab_transport', __( 'The Amazon Bedrock knowledge base could not be reached.', 'ai-chat-for-amazon-bedrock' ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			$this->log_debug( 'Knowledge base error', array( 'status' => $status ) );
			/* translators: %d: HTTP status code returned by Amazon Bedrock. */
			return new WP_Error( 'aicfab_http_error', sprintf( __( 'The knowledge base returned HTTP %d.', 'ai-chat-for-amazon-bedrock' ), $status ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'aicfab_invalid_response', __( 'The knowledge base returned an invalid response.', 'ai-chat-for-amazon-bedrock' ) );
		}
		return $data;
	}

	/**
	 * The AWS account and identity the resolved credentials belong to.
	 *
	 * Onboarding goes wrong most often because the credentials in use are not the ones the
	 * administrator thinks they are. This answers that directly, and supplies the account
	 * ID the generated IAM policy needs. Cached because it never changes for a given set
	 * of credentials.
	 *
	 * @param bool $refresh Ignore the cached value.
	 * @return array|WP_Error Array with account and arn keys.
	 */
	public function caller_identity( $refresh = false ) {
		$cache_key = 'aicfab_caller_identity';
		if ( ! $refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		if ( ! $this->has_credentials() ) {
			$message = '' !== $this->credential_error ? $this->credential_error : __( 'Amazon Bedrock credentials are not configured.', 'ai-chat-for-amazon-bedrock' );
			return new WP_Error( 'aicfab_no_credentials', $message );
		}
		if ( ! preg_match( '/^[a-z]{2}(?:-gov)?-[a-z]+-\d$/', $this->region ) ) {
			return new WP_Error( 'aicfab_invalid_region', __( 'The configured AWS region is invalid.', 'ai-chat-for-amazon-bedrock' ) );
		}

		// The query form of GetCallerIdentity, so the request carries no body to sign.
		$endpoint = $this->service_endpoint( 'sts' ) . '/?Action=GetCallerIdentity&Version=2011-06-15';
		$headers  = $this->signed_headers( $endpoint, '', 'GET', 'sts' );
		if ( is_wp_error( $headers ) ) {
			return $headers;
		}

		$response = wp_safe_remote_get(
			$endpoint,
			array(
				'timeout'            => 10,
				'redirection'        => 0,
				'httpversion'        => '1.1',
				'reject_unsafe_urls' => true,
				'headers'            => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'aicfab_transport', __( 'AWS Security Token Service could not be reached.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			return new WP_Error(
				'aicfab_sts_error',
				/* translators: %d: HTTP status code returned by AWS STS. */
				sprintf( __( 'AWS Security Token Service returned HTTP %d.', 'ai-chat-for-amazon-bedrock' ), $status )
			);
		}

		// A small XML response. Read the two fields directly rather than requiring an
		// XML extension that a minimal PHP build may not have.
		$body    = (string) wp_remote_retrieve_body( $response );
		$account = '';
		$arn     = '';
		if ( preg_match( '#<Account>(\d{12})</Account>#', $body, $match ) ) {
			$account = $match[1];
		}
		if ( preg_match( '#<Arn>([^<]{1,2048})</Arn>#', $body, $match ) ) {
			$arn = $match[1];
		}
		if ( '' === $account ) {
			return new WP_Error( 'aicfab_invalid_response', __( 'AWS Security Token Service returned an unexpected response.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$identity = array(
			'account' => $account,
			'arn'     => $arn,
		);
		set_transient( $cache_key, $identity, 12 * HOUR_IN_SECONDS );
		return $identity;
	}

	private function control_plane_get( $path, $host_prefix = 'bedrock' ) {
		if ( ! $this->has_credentials() ) {
			$message = '' !== $this->credential_error ? $this->credential_error : __( 'Amazon Bedrock credentials are not configured.', 'ai-chat-for-amazon-bedrock' );
			return new WP_Error( 'aicfab_no_credentials', $message );
		}
		if ( ! preg_match( '/^[a-z]{2}(?:-gov)?-[a-z]+-\d$/', $this->region ) ) {
			return new WP_Error( 'aicfab_invalid_region', __( 'The configured AWS region is invalid.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$host_prefix = preg_match( '/^[a-z][a-z0-9-]{0,40}$/', (string) $host_prefix ) ? (string) $host_prefix : 'bedrock';
		$endpoint    = $this->service_endpoint( $host_prefix ) . $path;
		$headers     = $this->signed_headers( $endpoint, '', 'GET' );
		$response    = wp_safe_remote_get(
			$endpoint,
			array(
				'timeout'            => 15,
				'redirection'        => 0,
				'httpversion'        => '1.1',
				'reject_unsafe_urls' => true,
				'headers'            => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'aicfab_transport', __( 'Amazon Bedrock could not be reached.', 'ai-chat-for-amazon-bedrock' ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( 403 === $status || 401 === $status ) {
			return new WP_Error( 'aicfab_forbidden', __( 'The AWS identity is not authorized for this Amazon Bedrock operation.', 'ai-chat-for-amazon-bedrock' ) );
		}
		if ( $status < 200 || $status >= 300 ) {
			/* translators: %d: HTTP status code returned by Amazon Bedrock. */
			return new WP_Error( 'aicfab_http_error', sprintf( __( 'Amazon Bedrock returned HTTP %d.', 'ai-chat-for-amazon-bedrock' ), $status ) );
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'aicfab_invalid_response', __( 'Amazon Bedrock returned an invalid response.', 'ai-chat-for-amazon-bedrock' ) );
		}
		return $data;
	}

	/**
	 * Sign an arbitrary AWS request with the resolved credentials.
	 *
	 * Used for services beyond Bedrock Runtime, such as an Amazon Bedrock
	 * AgentCore Gateway MCP endpoint.
	 *
	 * @param string $endpoint Absolute HTTPS endpoint.
	 * @param string $body     Request body.
	 * @param string $method   HTTP method.
	 * @param string $service  AWS service name for the signature scope.
	 * @param string $region   Optional region override.
	 * @return array|WP_Error Signed headers.
	 */
	public static function sign_request( $endpoint, $body, $method = 'POST', $service = 'bedrock-agentcore', $region = '' ) {
		$client  = new self();
		$service = preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) $service ) );
		if ( '' === $service ) {
			return new WP_Error( 'aicfab_invalid_service', __( 'The AWS service name is invalid.', 'ai-chat-for-amazon-bedrock' ) );
		}
		if ( ! $client->has_credentials() ) {
			return new WP_Error( 'aicfab_no_credentials', __( 'Amazon Bedrock credentials are not configured.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$region = sanitize_key( (string) $region );
		if ( '' !== $region ) {
			if ( ! preg_match( '/^[a-z]{2}(?:-gov)?-[a-z]+-\d$/', $region ) ) {
				return new WP_Error( 'aicfab_invalid_region', __( 'The configured AWS region is invalid.', 'ai-chat-for-amazon-bedrock' ) );
			}
			$client->region = $region;
		}

		return $client->signed_headers( $endpoint, (string) $body, $method, $service );
	}

	private function signed_headers( $endpoint, $body, $method = 'POST', $service = 'bedrock', $extra = array() ) {
		$host       = wp_parse_url( $endpoint, PHP_URL_HOST );
		$path       = wp_parse_url( $endpoint, PHP_URL_PATH );
		$query      = wp_parse_url( $endpoint, PHP_URL_QUERY );
		$method     = strtoupper( preg_replace( '/[^A-Za-z]/', '', (string) $method ) );
		$method     = '' === $method ? 'POST' : $method;
		$now        = new DateTimeImmutable( 'now', new DateTimeZone( 'UTC' ) );
		$amz_date   = $now->format( 'Ymd\THis\Z' );
		$date_stamp = $now->format( 'Ymd' );
		$canonical  = array(
			'content-type' => 'application/json',
			'host'         => $host,
			'x-amz-date'   => $amz_date,
		);
		if ( '' !== $this->session_token ) {
			$canonical['x-amz-security-token'] = $this->session_token;
		}
		foreach ( (array) $extra as $name => $value ) {
			$name = strtolower( preg_replace( '/[^A-Za-z0-9-]/', '', (string) $name ) );
			if ( '' === $name || isset( $canonical[ $name ] ) ) {
				continue;
			}
			$canonical[ $name ] = (string) $value;
		}
		ksort( $canonical );
		$canonical_headers = '';
		foreach ( $canonical as $name => $value ) {
			$canonical_headers .= $name . ':' . trim( preg_replace( '/\s+/', ' ', $value ) ) . "\n";
		}
		$signed_headers    = implode( ';', array_keys( $canonical ) );
		$canonical_request = $method . "\n" . $this->canonical_uri( $path ) . "\n" . $this->canonical_query( $query ) . "\n{$canonical_headers}\n{$signed_headers}\n" . hash( 'sha256', $body );
		$scope             = $date_stamp . '/' . $this->region . '/' . $service . '/aws4_request';
		$string_to_sign    = "AWS4-HMAC-SHA256\n{$amz_date}\n{$scope}\n" . hash( 'sha256', $canonical_request );
		$signature         = hash_hmac( 'sha256', $string_to_sign, $this->signature_key( $date_stamp, $service ) );

		$headers = array(
			'Content-Type'  => 'application/json',
			'X-Amz-Date'    => $amz_date,
			'Authorization' => 'AWS4-HMAC-SHA256 Credential=' . $this->access_key . '/' . $scope . ', SignedHeaders=' . $signed_headers . ', Signature=' . $signature,
		);
		if ( '' !== $this->session_token ) {
			$headers['X-Amz-Security-Token'] = $this->session_token;
		}
		foreach ( (array) $extra as $name => $value ) {
			$name = strtolower( preg_replace( '/[^A-Za-z0-9-]/', '', (string) $name ) );
			if ( '' !== $name && isset( $canonical[ $name ] ) ) {
				$headers[ $name ] = (string) $value;
			}
		}
		return $headers;
	}

	private function canonical_query( $query ) {
		$query = (string) $query;
		if ( '' === $query ) {
			return '';
		}

		$pairs = array();
		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			$parts   = explode( '=', $pair, 2 );
			$name    = rawurlencode( rawurldecode( $parts[0] ) );
			$value   = isset( $parts[1] ) ? rawurlencode( rawurldecode( $parts[1] ) ) : '';
			$pairs[] = $name . '=' . $value;
		}
		sort( $pairs );
		return implode( '&', $pairs );
	}

	/**
	 * Canonical URI for SigV4.
	 *
	 * The request path already contains percent-encoded model identifiers, and
	 * Amazon Bedrock canonicalizes by encoding that path a second time, so each
	 * segment is encoded without decoding it first. This keeps identifiers that
	 * contain colons or slashes, such as model ARNs, consistent with the signature.
	 *
	 * @param string $path Request path.
	 * @return string
	 */
	private function canonical_uri( $path ) {
		$path = (string) $path;
		if ( '' === $path ) {
			return '/';
		}

		// A trailing slash is part of the resource path and must survive: some Bedrock
		// control plane operations, such as GetPrompt, sign it and reject it otherwise.
		$trailing = '/' === substr( $path, -1 );
		$segments = explode( '/', trim( $path, '/' ) );
		$segments = array_map(
			function ( $segment ) {
				return rawurlencode( $segment );
			},
			$segments
		);

		$canonical = '/' . implode( '/', $segments );
		if ( $trailing && '/' !== $canonical ) {
			$canonical .= '/';
		}
		return $canonical;
	}

	private function signature_key( $date_stamp, $service ) {
		$date    = hash_hmac( 'sha256', $date_stamp, 'AWS4' . $this->secret_key, true );
		$region  = hash_hmac( 'sha256', $this->region, $date, true );
		$service = hash_hmac( 'sha256', $service, $region, true );
		return hash_hmac( 'sha256', 'aws4_request', $service, true );
	}

	private function parse_model_response( $data, $model_id ) {
		// Embedding models answer with a vector rather than text.
		$vector = self::extract_embedding( $data );
		if ( ! empty( $vector ) ) {
			$result = array(
				'success'   => true,
				'data'      => array( 'message' => '' ),
				'embedding' => $vector,
			);
			if ( isset( $data['inputTextTokenCount'] ) ) {
				$result['usage'] = array(
					'input_tokens'  => (int) $data['inputTextTokenCount'],
					'output_tokens' => 0,
				);
			}
			return $result;
		}

		$content    = '';
		$tool_calls = array();
		if ( false !== strpos( $model_id, 'anthropic.claude' ) && isset( $data['content'] ) && is_array( $data['content'] ) ) {
			foreach ( $data['content'] as $block ) {
				if ( isset( $block['type'] ) && 'text' === $block['type'] && isset( $block['text'] ) ) {
					$content .= $block['text'];
				} elseif ( isset( $block['type'], $block['name'] ) && 'tool_use' === $block['type'] ) {
					$tool_calls[] = array(
						'id'         => isset( $block['id'] ) ? $block['id'] : '',
						'name'       => $block['name'],
						'parameters' => isset( $block['input'] ) ? $block['input'] : array(),
					);
				}
			}
		} elseif ( isset( $data['results'][0]['outputText'] ) ) {
			$content = $data['results'][0]['outputText'];
		} elseif ( isset( $data['output']['message']['content'] ) && is_array( $data['output']['message']['content'] ) ) {
			foreach ( $data['output']['message']['content'] as $block ) {
				$content .= isset( $block['text'] ) ? $block['text'] : '';
			}
		} elseif ( isset( $data['generation'] ) ) {
			$content = $data['generation'];
		} elseif ( isset( $data['outputs'][0]['text'] ) ) {
			$content = $data['outputs'][0]['text'];
		} elseif ( isset( $data['choices'][0]['message']['content'] ) ) {
			$content = $data['choices'][0]['message']['content'];
		} elseif ( isset( $data['choices'][0]['text'] ) ) {
			$content = $data['choices'][0]['text'];
		}

		if ( '' === trim( (string) $content ) && empty( $tool_calls ) ) {
			return $this->error( __( 'Amazon Bedrock returned no usable content.', 'ai-chat-for-amazon-bedrock' ) );
		}
		$result = array(
			'success' => true,
			'data'    => array( 'message' => (string) $content ),
		);
		if ( ! empty( $tool_calls ) ) {
			$result['tool_calls'] = $tool_calls;
		}
		$usage = AI_Chat_Bedrock_Event_Stream::usage( $data );
		if ( ! empty( $usage ) ) {
			$result['usage'] = $usage;
		}
		return $result;
	}

	/**
	 * Pull an embedding vector out of a model response.
	 *
	 * @param array $data Decoded model response.
	 * @return array List of floats, empty when the response is not an embedding.
	 */
	private static function extract_embedding( $data ) {
		if ( ! is_array( $data ) ) {
			return array();
		}

		$candidate = array();
		if ( isset( $data['embedding'] ) && is_array( $data['embedding'] ) ) {
			$candidate = $data['embedding'];
		} elseif ( isset( $data['embeddings'] ) && is_array( $data['embeddings'] ) ) {
			$first = reset( $data['embeddings'] );
			// Cohere nests one vector per input text.
			$candidate = is_array( $first ) ? $first : $data['embeddings'];
		}

		$vector = array();
		foreach ( $candidate as $value ) {
			if ( ! is_numeric( $value ) ) {
				return array();
			}
			$vector[] = (float) $value;
		}
		return $vector;
	}

	/**
	 * Record that the fallback model was tried and also failed.
	 *
	 * The primary failure is still what gets reported, because that is the model the
	 * site is configured to use, but the retry outcome is kept so the reason a
	 * fallback did not help is diagnosable.
	 *
	 * @param array $primary  Primary failure response.
	 * @param array $fallback Fallback failure response.
	 * @return array
	 */
	private function with_fallback_failure( $primary, $fallback ) {
		if ( ! is_array( $primary ) || ! isset( $primary['data'] ) || ! is_array( $primary['data'] ) ) {
			return $primary;
		}

		$primary['data']['fallback_tried']  = true;
		$primary['data']['fallback_code']   = isset( $fallback['data']['code'] ) ? sanitize_key( (string) $fallback['data']['code'] ) : '';
		$primary['data']['fallback_status'] = isset( $fallback['data']['status'] ) ? (int) $fallback['data']['status'] : 0;

		$this->log_debug(
			'Fallback model also failed',
			array(
				'code'   => $primary['data']['fallback_code'],
				'status' => $primary['data']['fallback_status'],
			)
		);

		return $primary;
	}

	private function error( $message, $code = 'aicfab_error', $status = 0 ) {
		return array(
			'success' => false,
			'data'    => array(
				'message' => $message,
				'code'    => sanitize_key( $code ),
				'status'  => max( 0, (int) $status ),
			),
		);
	}

	private function log_debug( $event, $context = array() ) {
		if ( ! $this->debug ) {
			return;
		}
		$allowed = array();
		foreach ( (array) $context as $key => $value ) {
			if ( in_array( $key, array( 'model', 'region', 'payload_bytes', 'status', 'request_id', 'code' ), true ) && is_scalar( $value ) ) {
				$allowed[ $key ] = sanitize_text_field( (string) $value );
			}
		}
		error_log( 'AI Chat Bedrock: ' . sanitize_text_field( $event ) . ' ' . wp_json_encode( $allowed ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
	}
}

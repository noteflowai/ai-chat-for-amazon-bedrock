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

	/**
	 * Transient holding request fields each Converse model has been seen to refuse.
	 */
	const CONVERSE_QUIRKS = 'aicfab_converse_quirks';

	/**
	 * Most documents one rerank call scores. The API takes 1,000; retrieval sends far fewer.
	 */
	const MAX_RERANK_DOCUMENTS = 100;

	private $region;
	private $access_key;
	private $secret_key;
	private $session_token;
	private $credential_source;
	private $credential_error;
	private $debug;

	/**
	 * What the invocation in progress is counted as: answer, or embedding.
	 *
	 * @var string
	 */
	private $usage_kind = 'answer';

	/**
	 * Amazon Bedrock API key, sent as a bearer token to Bedrock and Bedrock Runtime.
	 *
	 * @var string
	 */
	private $api_key = '';

	/**
	 * Whether the API key is a short-term one.
	 *
	 * @var bool
	 */
	private $api_key_temporary = false;

	/**
	 * Whether the signing credential chain has been consulted.
	 *
	 * @var bool
	 */
	private $signing_resolved = false;

	/**
	 * Plugin options the client was built with, kept for resolving signing credentials later.
	 *
	 * @var array
	 */
	private $options = array();

	private $overrides = array();

	/**
	 * Whether the current chat call has sent at least one Bedrock HTTP request.
	 *
	 * @var bool
	 */
	private $request_sent = false;

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
		$this->options           = $options;

		$api_key = AI_Chat_Bedrock_AWS_Credentials::api_key( $options );
		if ( null !== $api_key ) {
			// Signing credentials are looked up only if a request needs them, so a site that
			// uses an API key on shared hosting does not wait on metadata endpoints it lacks.
			$this->api_key           = $api_key['key'];
			$this->api_key_temporary = $api_key['temporary'];
			$this->credential_source = $api_key['source'];
			return;
		}
		$this->resolve_signing_credentials();
	}

	/**
	 * Look up the credentials that sign requests with Signature Version 4.
	 */
	private function resolve_signing_credentials() {
		if ( $this->signing_resolved ) {
			return;
		}
		$this->signing_resolved = true;

		$credentials = AI_Chat_Bedrock_AWS_Credentials::resolve( $this->options );
		if ( is_wp_error( $credentials ) ) {
			$this->credential_error = $credentials->get_error_message();
			return;
		}
		$this->access_key    = $credentials['access_key'];
		$this->secret_key    = $credentials['secret_key'];
		$this->session_token = $credentials['session_token'];
		if ( '' === $this->api_key ) {
			$this->credential_source = $credentials['source'];
		}
	}

	/**
	 * Whether requests can be signed with Signature Version 4.
	 *
	 * Knowledge Bases, Prompt Management, STS and AgentCore need this; an API key is not enough.
	 *
	 * @return bool
	 */
	public function has_signing_credentials() {
		$this->resolve_signing_credentials();
		return '' !== $this->access_key && '' !== $this->secret_key;
	}

	/**
	 * Why a request to an AWS service cannot be authorised, or null when it can.
	 *
	 * @param string $service    Endpoint prefix: bedrock, bedrock-runtime, bedrock-agent, sts and so on.
	 * @param bool   $use_bearer Whether the request may be authorised with an API key.
	 * @return WP_Error|null
	 */
	private function missing_credentials( $service = 'bedrock-runtime', $use_bearer = true ) {
		if ( $use_bearer && '' !== $this->api_key && in_array( $service, array( 'bedrock', 'bedrock-runtime' ), true ) ) {
			return null;
		}
		if ( $this->has_signing_credentials() ) {
			return null;
		}
		if ( '' !== $this->api_key ) {
			return new WP_Error(
				'aicfab_api_key_unsupported',
				sprintf(
					/* translators: %s: AWS service endpoint name, such as bedrock-agent-runtime. */
					__( 'An Amazon Bedrock API key cannot call %s, which only accepts signed AWS requests. Add AWS access keys or an IAM role to use this feature; chat keeps working with the API key.', 'ai-chat-for-amazon-bedrock' ),
					$service
				)
			);
		}
		$message = '' !== $this->credential_error ? $this->credential_error : __( 'Amazon Bedrock credentials are not configured.', 'ai-chat-for-amazon-bedrock' );
		return new WP_Error( 'aicfab_no_credentials', $message );
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
			'temporary' => '' !== $this->api_key ? $this->api_key_temporary : '' !== $this->session_token,
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
		return '' !== $this->api_key || $this->has_signing_credentials();
	}

	/**
	 * Send a conversation to the configured Bedrock model.
	 *
	 * @param array $message_data Request data containing messages and optional tools.
	 * @return array WordPress AJAX-compatible response data.
	 */
	public function handle_chat_message( $message_data ) {
		$this->request_sent = false;

		$prepared = $this->prepare_request( $message_data );
		if ( is_wp_error( $prepared ) ) {
			return $this->error( $prepared->get_error_message(), $prepared->get_error_code() );
		}

		$response = $this->invoke_model( $prepared['payload'], $prepared['model_id'], $prepared['api'] );
		if ( ! $this->should_fall_back( $response ) ) {
			return $this->record_chat_outcome( $response );
		}

		$fallback = $this->fallback_model_id( $prepared['model_id'] );
		if ( '' === $fallback ) {
			return $this->record_chat_outcome( $response );
		}

		$retry = $this->prepare_request( $message_data, $fallback );
		if ( is_wp_error( $retry ) ) {
			return $this->record_chat_outcome( $response );
		}

		$this->log_debug(
			'Falling back to another model',
			array(
				'from' => $prepared['model_id'],
				'to'   => $fallback,
			)
		);
		$second = $this->invoke_model( $retry['payload'], $fallback, $retry['api'] );
		if ( empty( $second['success'] ) ) {
			// The primary failure is the more useful one to report, but keep the retry visible.
			return $this->record_chat_outcome( $this->with_fallback_failure( $response, $second ) );
		}

		$second['fallback_model'] = $fallback;
		return $this->record_chat_outcome( $second );
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
	 * Send a request to an AWS endpoint through the WordPress HTTP API.
	 *
	 * WordPress's wp_safe_remote_*() refuses a host that resolves to a private address. An interface VPC
	 * endpoint with private DNS does exactly that to bedrock-runtime.<region>.amazonaws.com (and to
	 * STS and the other Bedrock services), so on EC2, ECS or EKS in such a VPC every non-streaming
	 * call failed with "could not be reached". For the one AWS host of this request, and only while
	 * it runs, that check is waived; the rest of wp_http_validate_url() (scheme, port, no user or
	 * password in the URL) still applies, and any other host is still refused.
	 *
	 * @param string $method GET or POST.
	 * @param string $url    Request URL.
	 * @param array  $args   Arguments for wp_safe_remote_get() or wp_safe_remote_post().
	 * @return array|WP_Error Response, or an error.
	 */
	private static function aws_remote( $method, $url, $args ) {
		$host  = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$allow = null;
		if ( self::is_aws_host( $host ) ) {
			$allow = static function ( $external, $requested ) use ( $host ) {
				return $external || strtolower( (string) $requested ) === $host;
			};
			add_filter( 'http_request_host_is_external', $allow, 10, 2 );
		}
		try {
			return 'GET' === $method ? wp_safe_remote_get( $url, $args ) : wp_safe_remote_post( $url, $args );
		} finally {
			if ( $allow ) {
				remove_filter( 'http_request_host_is_external', $allow, 10 );
			}
		}
	}

	/**
	 * Whether a host name belongs to an AWS service endpoint: *.amazonaws.com, the China regions'
	 * *.amazonaws.com.cn, or the dual-stack *.api.aws. VPC endpoint-specific names
	 * (vpce-….bedrock-runtime.<region>.vpce.amazonaws.com) are included.
	 *
	 * @param string $host Host name.
	 * @return bool
	 */
	public static function is_aws_host( $host ) {
		return 1 === preg_match( '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+(?:amazonaws\.com|amazonaws\.com\.cn|api\.aws)$/', (string) $host );
	}

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

	/**
	 * Create an embedding vector for one piece of text.
	 *
	 * Titan and Cohere use different request and response shapes, so both are
	 * handled here rather than in the caller.
	 *
	 * @param string $text     Text to embed.
	 * @param string $model_id Embedding model identifier.
	 * @param string $purpose  document for indexed text, query for a question. Cohere embeds the
	 *                         two differently and retrieves noticeably better when told which is which.
	 * @param int    $dimensions Vector size for Titan Text Embeddings V2 (256, 512 or 1024), or 0 for the default.
	 * @return array|WP_Error List of floats, or an error.
	 */
	public function embed( $text, $model_id, $purpose = 'document', $dimensions = 0 ) {
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
				'input_type' => 'query' === $purpose ? 'search_query' : 'search_document',
			)
			: array( 'inputText' => $text );
		// Titan Text Embeddings V2 can return a shorter vector; the other models have one size.
		$dimensions = absint( $dimensions );
		if ( false !== strpos( $model_id, 'titan-embed-text-v2' ) && in_array( $dimensions, array( 256, 512, 1024 ), true ) ) {
			$payload['dimensions'] = $dimensions;
		}

		$this->usage_kind = 'embedding';
		$response         = $this->invoke_model( $payload, $model_id );
		$this->usage_kind = 'answer';
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
	 * Run an image model and return what it made.
	 *
	 * Image models take the same InvokeModel call as text models, but Bedrock Guardrails
	 * headers do not apply to them, so the caller checks the prompt with apply_guardrail()
	 * first. There is no fallback model: a picture from a different model is not a retry.
	 *
	 * @param array  $payload  Request body in the model's own format.
	 * @param string $model_id Model or inference profile ID.
	 * @return array|WP_Error Array with images (base64 strings) and finish_reasons, or an error.
	 */
	public function invoke_image( $payload, $model_id ) {
		if ( ! $this->has_credentials() ) {
			$message = '' !== $this->credential_error ? $this->credential_error : __( 'Amazon Bedrock credentials are not configured.', 'ai-chat-for-amazon-bedrock' );
			return new WP_Error( 'aicfab_no_credentials', $message );
		}
		if ( ! preg_match( '/^[a-z]{2}(?:-gov)?-[a-z]+-\d$/', $this->region ) ) {
			return new WP_Error( 'aicfab_invalid_region', __( 'The configured AWS region is invalid.', 'ai-chat-for-amazon-bedrock' ) );
		}
		$model_id = (string) $model_id;
		if ( ! preg_match( '/^[A-Za-z0-9._:-]{1,200}$/', $model_id ) ) {
			return new WP_Error( 'aicfab_invalid_model', __( 'The configured image model ID is invalid.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$this->usage_kind = 'image';
		$response         = $this->invoke_model( $payload, $model_id, 'image', 0 );
		$this->usage_kind = 'answer';
		if ( empty( $response['success'] ) ) {
			$code = isset( $response['data']['code'] ) ? (string) $response['data']['code'] : 'aicfab_error';
			return new WP_Error( $code, isset( $response['data']['message'] ) ? (string) $response['data']['message'] : __( 'The image request failed.', 'ai-chat-for-amazon-bedrock' ) );
		}
		if ( empty( $response['images'] ) ) {
			return new WP_Error( 'aicfab_no_image', __( 'Amazon Bedrock returned no image.', 'ai-chat-for-amazon-bedrock' ) );
		}
		return array(
			'images'         => $response['images'],
			'finish_reasons' => isset( $response['finish_reasons'] ) ? $response['finish_reasons'] : array(),
		);
	}

	/**
	 * Check text against the site's guardrail with ApplyGuardrail.
	 *
	 * For the calls that cannot carry the guardrail themselves, such as image models. With no
	 * guardrail configured there is nothing to check. A guardrail that cannot be reached fails
	 * closed, because the site chose to have every request checked.
	 *
	 * @param string $text   Text to check.
	 * @param string $source INPUT for a prompt, OUTPUT for a model answer.
	 * @return true|WP_Error True when the text may be used.
	 */
	public function apply_guardrail( $text, $source = 'INPUT' ) {
		$guardrail = $this->guardrail_setting();
		if ( empty( $guardrail ) ) {
			return true;
		}
		if ( ! $this->has_credentials() ) {
			$message = '' !== $this->credential_error ? $this->credential_error : __( 'Amazon Bedrock credentials are not configured.', 'ai-chat-for-amazon-bedrock' );
			return new WP_Error( 'aicfab_no_credentials', $message );
		}

		$endpoint = $this->service_endpoint( 'bedrock-runtime' ) . '/guardrail/' . rawurlencode( $guardrail['id'] ) . '/version/' . rawurlencode( $guardrail['version'] ) . '/apply';
		$body     = wp_json_encode(
			array(
				'source'  => 'OUTPUT' === $source ? 'OUTPUT' : 'INPUT',
				'content' => array( array( 'text' => array( 'text' => (string) $text ) ) ),
			),
			JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
		);
		if ( false === $body ) {
			return new WP_Error( 'aicfab_error', __( 'The Bedrock request could not be encoded.', 'ai-chat-for-amazon-bedrock' ) );
		}
		$response = self::aws_remote(
			'POST',
			$endpoint,
			array(
				'timeout'            => 30,
				'redirection'        => 0,
				'httpversion'        => '1.1',
				'reject_unsafe_urls' => true,
				'headers'            => $this->signed_headers( $endpoint, $body, 'POST', 'bedrock' ),
				'body'               => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'aicfab_unreachable', __( 'The guardrail could not be reached, so the request was not sent.', 'ai-chat-for-amazon-bedrock' ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $status < 200 || $status >= 300 ) {
			$explained = AI_Chat_Bedrock_Bedrock_Errors::explain( $status, wp_remote_retrieve_body( $response ), '', $this->region, $this->credential_context() );
			return new WP_Error( 'aicfab_guardrail_error', $explained['message'], array( 'status' => $status ) );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || ! isset( $data['action'] ) ) {
			return new WP_Error( 'aicfab_guardrail_error', __( 'The guardrail returned an invalid response, so the request was not sent.', 'ai-chat-for-amazon-bedrock' ) );
		}
		if ( 'GUARDRAIL_INTERVENED' === $data['action'] ) {
			$message = isset( $data['outputs'][0]['text'] ) && '' !== trim( (string) $data['outputs'][0]['text'] )
				? sanitize_text_field( (string) $data['outputs'][0]['text'] )
				: __( 'The request was blocked by the site guardrail.', 'ai-chat-for-amazon-bedrock' );
			return new WP_Error( 'aicfab_guardrail_blocked', $message );
		}
		return true;
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
		$model_id = isset( $options['model_id'] ) ? sanitize_text_field( $options['model_id'] ) : ( class_exists( 'AI_Chat_Bedrock_Models' ) ? AI_Chat_Bedrock_Models::DEFAULT_MODEL : 'amazon.nova-lite-v1:0' );
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
			'api'      => self::chat_api( $model_id ),
		);
	}

	/**
	 * Which Bedrock Runtime operation carries a chat request for this model.
	 *
	 * Claude, Nova and Titan have native request bodies the plugin builds itself, and Claude's
	 * is the one that carries tools and images. Every other family goes through Converse,
	 * which gives one request shape and applies each model's own chat template on the AWS
	 * side. Before 1.46.0 those families were sent a raw "User: ... Assistant:" prompt:
	 * OpenAI gpt-oss and Qwen3 reject that outright with "missing field `messages`", and
	 * DeepSeek R1 accepted it but carried on writing both sides of the conversation.
	 *
	 * @param string $model_id Model or inference profile ID.
	 * @return string Either 'invoke' or 'converse'.
	 */
	public static function chat_api( $model_id ) {
		$model_id = (string) $model_id;
		foreach ( array( 'anthropic.claude', 'amazon.nova', 'amazon.titan' ) as $native ) {
			if ( false !== strpos( $model_id, $native ) ) {
				return 'invoke';
			}
		}
		return 'converse';
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
		$this->request_sent = false;

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
			return $this->record_chat_outcome( $response );
		}

		$fallback = $this->fallback_model_id( $prepared['model_id'] );
		if ( '' === $fallback ) {
			return $this->record_chat_outcome( $response );
		}

		$retry = $this->prepare_request( $message_data, $fallback );
		if ( is_wp_error( $retry ) ) {
			return $this->record_chat_outcome( $response );
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
			return $this->record_chat_outcome( $this->with_fallback_failure( $response, $second ) );
		}

		$second['fallback_model'] = $fallback;
		return $this->record_chat_outcome( $second );
	}

	/**
	 * Run one streaming attempt against an already prepared request.
	 *
	 * @param callable $on_delta Receives each text delta.
	 * @param array    $prepared Prepared payload and model.
	 * @return array
	 */
	private function stream_once( $on_delta, $prepared, $adaptations = 2 ) {
		$model_id = $prepared['model_id'];
		$body     = wp_json_encode( $prepared['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $body ) {
			return $this->error( __( 'The Bedrock request could not be encoded.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$converse          = isset( $prepared['api'] ) && 'converse' === $prepared['api'];
		$endpoint          = $this->service_endpoint( 'bedrock-runtime' ) . '/model/' . rawurlencode( $model_id ) . ( $converse ? '/converse-stream' : '/invoke-with-response-stream' );
		$headers           = $this->signed_headers( $endpoint, $body, 'POST', 'bedrock', $converse ? array() : $this->guardrail_headers() );
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

		$this->request_sent = true;

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

		if ( $converse && 400 === $status && $adaptations > 0 ) {
			// Refused before anything streamed, so asking again cannot duplicate text.
			$adapted = self::adapt_refused_converse( $prepared['payload'], $model_id, $state['raw'] );
			if ( null !== $adapted ) {
				$prepared['payload'] = $adapted;
				return $this->stream_once( $on_delta, $prepared, $adaptations - 1 );
			}
		}
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

	/**
	 * Whether a Claude model still takes the temperature parameter.
	 *
	 * Observed on Bedrock: ValidationException, "`temperature` is deprecated for this model."
	 * from Claude Opus 4.7, Opus 4.8, Opus 5, Opus 5.5, Sonnet 5, Fable 5 and Fable 5.1, which
	 * made every chat, and the Diagnostics model test, fail on those models. Claude 3, 3.x and
	 * 4 through 4.6 still accept it. Unknown and newer Claude models get no temperature,
	 * because sending it is an error and leaving it out is not.
	 *
	 * @param string $model_id Model or inference profile ID.
	 * @return bool
	 */
	private static function claude_accepts_temperature( $model_id ) {
		return 1 === preg_match( '/anthropic\.claude-(?:v2|instant|3|(?:opus|sonnet|haiku)-4(?:-[0-6])?(?:-\d{8}|-v\d|:|$))/', (string) $model_id );
	}

	/**
	 * Whether a model takes top_p (nucleus sampling).
	 *
	 * Claude models that refuse temperature refuse top_p too. On Converse, GPT-5.4 and later
	 * and Grok answer "This model doesn't support the topP field". Any other model that turns
	 * out to refuse it is remembered as a quirk and sent without it, see converse_quirks().
	 *
	 * @param string $model_id Model or inference profile ID.
	 * @return bool
	 */
	public static function accepts_top_p( $model_id ) {
		$model_id = (string) $model_id;
		if ( false !== strpos( $model_id, 'anthropic.claude' ) ) {
			return self::claude_accepts_temperature( $model_id );
		}
		if ( preg_match( '/openai\.gpt-(?:5\.[4-9]|[6-9])|xai\.grok/', $model_id ) ) {
			return false;
		}
		return ! in_array( 'no_top_p', self::converse_quirks( $model_id ), true );
	}

	/**
	 * Whether a Claude model on Bedrock accepts a prompt cache checkpoint.
	 *
	 * AWS lists Claude 3.5 Haiku, 3.7 Sonnet and every Claude 4 and later model. The older
	 * ones are left alone because a checkpoint is a field they were never documented to take.
	 *
	 * @param string $model_id Model or inference profile ID.
	 * @return bool
	 */
	private static function claude_caches_prompts( $model_id ) {
		$model_id = (string) $model_id;
		if ( false === strpos( $model_id, 'anthropic.claude' ) ) {
			return false;
		}
		return 1 !== preg_match( '/anthropic\.claude-(?:v2|instant|3-(?:haiku|sonnet|opus)|3-5-sonnet)/', $model_id );
	}

	/**
	 * Mark the part of a Claude request that repeats, so Bedrock can reuse it.
	 *
	 * Bedrock reads a request as tools, then system, then messages, and a checkpoint caches
	 * everything before it. The first system section is the site's own prompt and the tools
	 * are the site's abilities, and both are the same for every visitor. What follows them is
	 * the retrieved site content and the conversation, which change with every question, so
	 * the checkpoint goes at the end of the first section and nowhere later. A cached read
	 * costs a tenth of the input price and a write costs a quarter more. A prefix shorter
	 * than the model's minimum (1,024 tokens on most models) is simply not cached, so a short
	 * prompt costs what it did before. The ai_chat_bedrock_prompt_caching filter turns this
	 * off.
	 *
	 * @param array  $payload  Claude request.
	 * @param string $model_id Model or inference profile ID.
	 * @param array  $sections System prompt sections, in order.
	 * @return array
	 */
	private function with_prompt_cache( $payload, $model_id, $sections ) {
		if ( ! self::claude_caches_prompts( $model_id ) || ! apply_filters( 'ai_chat_bedrock_prompt_caching', true, $model_id ) ) {
			return $payload;
		}
		$checkpoint = array( 'type' => 'ephemeral' );

		if ( ! empty( $sections ) && '' !== trim( (string) $sections[0] ) ) {
			$blocks = array(
				array(
					'type'          => 'text',
					'text'          => (string) $sections[0],
					'cache_control' => $checkpoint,
				),
			);
			$rest   = implode( "\n\n", array_slice( $sections, 1 ) );
			if ( '' !== trim( $rest ) ) {
				$blocks[] = array(
					'type' => 'text',
					'text' => $rest,
				);
			}
			$payload['system'] = $blocks;
			return $payload;
		}

		// No stable system prompt, so the tool definitions are the repeating part.
		if ( ! empty( $payload['tools'] ) && is_array( $payload['tools'] ) ) {
			$last = count( $payload['tools'] ) - 1;
			if ( is_array( $payload['tools'][ $last ] ) ) {
				$payload['tools'][ $last ]['cache_control'] = $checkpoint;
			}
		}
		return $payload;
	}

	/**
	 * Image formats every vision family on Bedrock accepts, by media type.
	 */
	const IMAGE_FORMATS = array(
		'image/jpeg' => 'jpeg',
		'image/png'  => 'png',
		'image/gif'  => 'gif',
		'image/webp' => 'webp',
	);

	/**
	 * Images attached to one message, kept only when they are something a model can read.
	 *
	 * @param mixed $images List of arrays with media_type and base64 data.
	 * @return array
	 */
	private static function clean_images( $images ) {
		$clean = array();
		foreach ( is_array( $images ) ? $images : array() as $image ) {
			$media = is_array( $image ) && isset( $image['media_type'] ) ? (string) $image['media_type'] : '';
			$data  = is_array( $image ) && isset( $image['data'] ) ? (string) $image['data'] : '';
			if ( isset( self::IMAGE_FORMATS[ $media ] ) && '' !== $data ) {
				$clean[] = array(
					'media_type' => $media,
					'data'       => $data,
				);
			}
		}
		// Claude takes up to 20 images per request and Nova 20 as well; more is refused.
		return array_slice( $clean, 0, 20 );
	}

	/**
	 * Sampling and output controls a caller asked for, beyond temperature and length.
	 *
	 * @param array $message_data Request data.
	 * @return array{top_p: float|null, stop: array, schema: array|null}
	 */
	private static function request_controls( $message_data ) {
		$top_p = isset( $message_data['top_p'] ) && is_numeric( $message_data['top_p'] ) ? max( 0.0, min( 1.0, (float) $message_data['top_p'] ) ) : null;
		$stop  = array();
		foreach ( isset( $message_data['stop_sequences'] ) && is_array( $message_data['stop_sequences'] ) ? $message_data['stop_sequences'] : array() as $sequence ) {
			if ( is_string( $sequence ) && '' !== $sequence ) {
				$stop[] = $sequence;
			}
		}
		$schema = isset( $message_data['json_schema'] ) && is_array( $message_data['json_schema'] ) && ! empty( $message_data['json_schema'] ) ? $message_data['json_schema'] : null;
		return array(
			'top_p'  => $top_p,
			// Nova and Converse take at most four.
			'stop'   => array_slice( array_values( array_unique( $stop ) ), 0, 4 ),
			'schema' => $schema,
		);
	}

	private function format_payload_for_model( $model_id, $message_data, $max_tokens, $temperature ) {
		$messages = isset( $message_data['messages'] ) && is_array( $message_data['messages'] ) ? $message_data['messages'] : array();
		$system   = '';
		$sections = array();
		$chat     = array();
		foreach ( $messages as $message ) {
			if ( ! is_array( $message ) || ! isset( $message['role'], $message['content'] ) || ! is_string( $message['content'] ) ) {
				continue;
			}
			$role    = sanitize_key( $message['role'] );
			$content = $message['content'];
			$images  = self::clean_images( isset( $message['images'] ) ? $message['images'] : array() );
			if ( 'system' === $role ) {
				$system    .= ( '' === $system ? '' : "\n\n" ) . $content;
				$sections[] = $content;
			} elseif ( in_array( $role, array( 'user', 'assistant' ), true ) && ( '' !== trim( $content ) || ( 'user' === $role && ! empty( $images ) ) ) ) {
				$chat[] = array(
					'role'    => $role,
					'content' => $content,
					'images'  => 'user' === $role ? $images : array(),
				);
			}
		}
		if ( empty( $chat ) ) {
			return new WP_Error( 'empty_conversation', __( 'No valid chat messages were supplied.', 'ai-chat-for-amazon-bedrock' ) );
		}

		// The single image the media helpers send goes with the last user turn. Before 1.57.0
		// only Claude received it, so on Nova the alt text described an image it never saw.
		if ( ! empty( $message_data['image'] ) && is_array( $message_data['image'] ) ) {
			for ( $i = count( $chat ) - 1; $i >= 0; $i-- ) {
				if ( 'user' === $chat[ $i ]['role'] ) {
					$chat[ $i ]['images'] = array_slice( array_merge( $chat[ $i ]['images'], self::clean_images( array( $message_data['image'] ) ) ), 0, 20 );
					break;
				}
			}
		}
		$has_images = false;
		foreach ( $chat as $turn ) {
			$has_images = $has_images || ! empty( $turn['images'] );
		}

		$controls = self::request_controls( $message_data );
		$quirks   = self::converse_quirks( $model_id );

		if ( false !== strpos( $model_id, 'anthropic.claude' ) ) {
			$messages_out = array();
			foreach ( $this->normalize_claude_messages( $chat ) as $turn ) {
				if ( empty( $turn['images'] ) ) {
					$messages_out[] = array(
						'role'    => $turn['role'],
						'content' => $turn['content'],
					);
					continue;
				}
				$blocks = array();
				foreach ( $turn['images'] as $image ) {
					$blocks[] = array(
						'type'   => 'image',
						'source' => array(
							'type'       => 'base64',
							'media_type' => $image['media_type'],
							'data'       => $image['data'],
						),
					);
				}
				if ( '' !== trim( $turn['content'] ) ) {
					$blocks[] = array(
						'type' => 'text',
						'text' => $turn['content'],
					);
				}
				$messages_out[] = array(
					'role'    => $turn['role'],
					'content' => $blocks,
				);
			}

			$payload = array(
				'anthropic_version' => 'bedrock-2023-05-31',
				'max_tokens'        => $max_tokens,
				'messages'          => $messages_out,
			);
			if ( self::claude_accepts_temperature( $model_id ) ) {
				// Claude 4.5 models refuse temperature and top_p together, so an explicit top_p wins.
				if ( null !== $controls['top_p'] ) {
					$payload['top_p'] = $controls['top_p'];
				} else {
					$payload['temperature'] = $temperature;
				}
			}
			if ( ! empty( $controls['stop'] ) ) {
				$payload['stop_sequences'] = $controls['stop'];
			}
			if ( null !== $controls['schema'] && ! in_array( 'no_output_schema', $quirks, true ) ) {
				$payload['output_config'] = array(
					'format' => array(
						'type'   => 'json_schema',
						'schema' => $controls['schema'],
					),
				);
			}
			if ( '' !== $system ) {
				$payload['system'] = $system;
			}
			if ( ! empty( $message_data['tools'] ) && is_array( $message_data['tools'] ) ) {
				$payload['tools']       = array_slice( $message_data['tools'], 0, 50 );
				$payload['tool_choice'] = array( 'type' => 'auto' );
			}
			return $this->with_prompt_cache( $payload, $model_id, $sections );
		}

		if ( false !== strpos( $model_id, 'amazon.nova' ) ) {
			$nova_messages = array();
			foreach ( $chat as $message ) {
				$nova_messages[] = array(
					'role'    => $message['role'],
					'content' => self::content_blocks( $message ),
				);
			}
			$payload = array(
				'messages'        => $nova_messages,
				'inferenceConfig' => self::inference_config( $max_tokens, $temperature, $controls ),
			);
			if ( '' !== $system ) {
				$payload['system'] = array( array( 'text' => $system ) );
			}
			return $payload;
		}

		if ( 'converse' === self::chat_api( $model_id ) ) {
			return $this->format_converse_payload( $model_id, $chat, $system, $max_tokens, $temperature, $controls );
		}

		if ( $has_images ) {
			return new WP_Error( 'aicfab_images_unsupported', __( 'The selected model does not accept images.', 'ai-chat-for-amazon-bedrock' ) );
		}

		// Titan Text, the one native family left without a chat format of its own.
		$prompt = '' !== $system ? $system . "\n\n" : '';
		foreach ( $chat as $message ) {
			$prompt .= ( 'user' === $message['role'] ? 'User: ' : 'Assistant: ' ) . $message['content'] . "\n";
		}
		$prompt .= 'Assistant: ';

		$config = array(
			'maxTokenCount' => $max_tokens,
			'temperature'   => $temperature,
		);
		if ( null !== $controls['top_p'] ) {
			$config['topP'] = $controls['top_p'];
		}
		return array(
			'inputText'            => $prompt,
			'textGenerationConfig' => $config,
		);
	}

	/**
	 * One turn as Nova and Converse content blocks: its images, then its text.
	 *
	 * Both take the image bytes as base64 in the JSON body.
	 *
	 * @param array $turn Turn with content and images.
	 * @return array
	 */
	private static function content_blocks( $turn ) {
		$blocks = array();
		foreach ( isset( $turn['images'] ) ? $turn['images'] : array() as $image ) {
			$blocks[] = array(
				'image' => array(
					'format' => self::IMAGE_FORMATS[ $image['media_type'] ],
					'source' => array( 'bytes' => $image['data'] ),
				),
			);
		}
		if ( '' !== trim( (string) $turn['content'] ) || empty( $blocks ) ) {
			$blocks[] = array( 'text' => (string) $turn['content'] );
		}
		return $blocks;
	}

	/**
	 * The inferenceConfig block Nova and Converse share.
	 *
	 * @param int   $max_tokens  Output token ceiling.
	 * @param float $temperature Sampling temperature.
	 * @param array $controls    Output of request_controls().
	 * @return array
	 */
	private static function inference_config( $max_tokens, $temperature, $controls ) {
		$config = array(
			'maxTokens'   => $max_tokens,
			'temperature' => $temperature,
		);
		if ( null !== $controls['top_p'] ) {
			$config['topP'] = $controls['top_p'];
		}
		if ( ! empty( $controls['stop'] ) ) {
			$config['stopSequences'] = $controls['stop'];
		}
		return $config;
	}

	/**
	 * Build a Converse request.
	 *
	 * Converse wants turns that alternate and start with the user, which is what the Claude
	 * normaliser already guarantees. The guardrail travels in the body here, because
	 * Converse ignores the guardrail headers InvokeModel reads, and sending a chat without
	 * the guardrail the administrator configured would be worse than failing.
	 *
	 * A JSON schema is sent as outputConfig, which constrains decoding on the models that
	 * support it. A model that does not says so, and the request is retried without it; the
	 * instruction in the prompt and the check on the answer still apply.
	 *
	 * @param string $model_id    Model or inference profile ID.
	 * @param array  $chat        User and assistant turns with string content.
	 * @param string $system      Combined system prompt.
	 * @param int    $max_tokens  Output token ceiling.
	 * @param float  $temperature Sampling temperature.
	 * @param array  $controls    Output of request_controls().
	 * @return array
	 */
	private function format_converse_payload( $model_id, $chat, $system, $max_tokens, $temperature, $controls = array() ) {
		$controls = array_merge(
			array(
				'top_p'  => null,
				'stop'   => array(),
				'schema' => null,
			),
			$controls
		);
		$messages = array();
		foreach ( $this->normalize_claude_messages( $chat ) as $turn ) {
			$messages[] = array(
				'role'    => $turn['role'],
				'content' => self::content_blocks( $turn ),
			);
		}

		$payload = array(
			'messages'        => $messages,
			'inferenceConfig' => self::inference_config( $max_tokens, $temperature, $controls ),
		);
		if ( '' !== $system ) {
			$payload['system'] = array( array( 'text' => $system ) );
		}
		if ( null !== $controls['schema'] ) {
			$schema = wp_json_encode( $controls['schema'] );
			if ( is_string( $schema ) ) {
				$payload['outputConfig'] = array(
					'textFormat' => array(
						'type'      => 'json_schema',
						'structure' => array(
							'jsonSchema' => array(
								'schema' => $schema,
								'name'   => 'response',
							),
						),
					),
				);
			}
		}

		$guardrail = $this->guardrail_setting();
		if ( ! empty( $guardrail ) ) {
			$payload['guardrailConfig'] = array(
				'guardrailIdentifier' => $guardrail['id'],
				'guardrailVersion'    => $guardrail['version'],
			);
		}
		return self::apply_converse_quirks( $payload, self::converse_quirks( $model_id ) );
	}

	/**
	 * Request fields a Converse model is known to refuse.
	 *
	 * Converse accepts one request shape, but not every model accepts every field in it, and
	 * each says so in a sentence rather than an error code. Observed on Bedrock: Mistral 7B
	 * Instruct refuses a system block, and GPT-6 Astra, GPT-5.6, Grok 4.6 and Kimi K3 refuse
	 * temperature. A model the plugin has not met yet is learned from its first refusal, so
	 * the next request is right the first time.
	 *
	 * @param string $model_id Model or inference profile ID.
	 * @return array List of quirk names: no_system, no_temperature.
	 */
	private static function converse_quirks( $model_id ) {
		$quirks = array();
		if ( 1 === preg_match( '/mistral\.mi[sx]tral-(?:7b|8x7b)-instruct/', (string) $model_id ) ) {
			$quirks[] = 'no_system';
		}
		$learned = get_transient( self::CONVERSE_QUIRKS );
		if ( is_array( $learned ) && isset( $learned[ $model_id ] ) && is_array( $learned[ $model_id ] ) ) {
			$quirks = array_merge( $quirks, $learned[ $model_id ] );
		}
		return array_values( array_intersect( array( 'no_system', 'no_temperature', 'no_top_p', 'no_stop_sequences', 'no_output_schema' ), $quirks ) );
	}

	/**
	 * Which field a Converse rejection complains about, if it is one the plugin can drop.
	 *
	 * @param string $body Error response body.
	 * @return string Quirk name, or an empty string.
	 */
	private static function converse_quirk_from_error( $body ) {
		$body = strtolower( (string) $body );
		if ( false !== strpos( $body, 'support the temperature field' ) ) {
			return 'no_temperature';
		}
		if ( false !== strpos( $body, 'support system messages' ) ) {
			return 'no_system';
		}
		// Seen on GPT-6 Astra and Grok 4.6 (topP), Qwen3 VL (stopSequences), Nova and Llama 4
		// (outputConfig), and Claude Sonnet 5, which answers a schema with "Extra inputs are not permitted".
		if ( false !== strpos( $body, 'support the topp field' ) ) {
			return 'no_top_p';
		}
		if ( false !== strpos( $body, 'support the stopsequences field' ) ) {
			return 'no_stop_sequences';
		}
		if ( false !== strpos( $body, 'support the outputconfig field' ) || false !== strpos( $body, 'output_config.format: extra inputs' ) ) {
			return 'no_output_schema';
		}
		return '';
	}

	/**
	 * Remember that a model refuses a field, so later requests leave it out.
	 *
	 * @param string $model_id Model or inference profile ID.
	 * @param string $quirk    Quirk name.
	 */
	private static function remember_converse_quirk( $model_id, $quirk ) {
		$learned = get_transient( self::CONVERSE_QUIRKS );
		$learned = is_array( $learned ) ? $learned : array();
		$known   = isset( $learned[ $model_id ] ) && is_array( $learned[ $model_id ] ) ? $learned[ $model_id ] : array();
		if ( in_array( $quirk, $known, true ) ) {
			return;
		}
		$known[]              = $quirk;
		$learned[ $model_id ] = $known;
		// Bounded, because the key is a model ID and there is no reason for it to grow.
		set_transient( self::CONVERSE_QUIRKS, array_slice( $learned, -50, null, true ), 30 * DAY_IN_SECONDS );
	}

	/**
	 * Leave out the fields a Converse model refuses.
	 *
	 * A refused system prompt still matters, so it leads the first user turn instead.
	 *
	 * @param array $payload Converse request.
	 * @param array $quirks  Quirk names.
	 * @return array
	 */
	private static function apply_converse_quirks( $payload, $quirks ) {
		if ( in_array( 'no_temperature', $quirks, true ) ) {
			unset( $payload['inferenceConfig']['temperature'] );
		}
		if ( in_array( 'no_top_p', $quirks, true ) ) {
			unset( $payload['inferenceConfig']['topP'] );
		}
		// The caller still gets text cut at the stop sequence: the core AI adapter truncates it.
		if ( in_array( 'no_stop_sequences', $quirks, true ) ) {
			unset( $payload['inferenceConfig']['stopSequences'] );
		}
		// The prompt still asks for JSON and the answer is still checked; only the constraint goes.
		if ( in_array( 'no_output_schema', $quirks, true ) ) {
			unset( $payload['outputConfig'], $payload['output_config'] );
		}
		if ( in_array( 'no_system', $quirks, true ) && ! empty( $payload['system'] ) && is_array( $payload['system'] ) ) {
			$instructions = array();
			foreach ( $payload['system'] as $block ) {
				if ( isset( $block['text'] ) ) {
					$instructions[] = (string) $block['text'];
				}
			}
			unset( $payload['system'] );
			// Image blocks come before the text, so the instructions join the first text block.
			$blocks = ! empty( $instructions ) && isset( $payload['messages'][0]['content'] ) && is_array( $payload['messages'][0]['content'] ) ? $payload['messages'][0]['content'] : array();
			foreach ( $blocks as $index => $block ) {
				if ( isset( $block['text'] ) ) {
					$payload['messages'][0]['content'][ $index ]['text'] = implode( "\n\n", $instructions ) . "\n\n" . $block['text'];
					break;
				}
			}
		}
		return $payload;
	}

	/**
	 * The same Converse request without the field Bedrock just refused, if that helps.
	 *
	 * @param array  $payload  Converse request that was refused.
	 * @param string $model_id Model or inference profile ID.
	 * @param string $body     Error response body.
	 * @return array|null Adapted request, or null when there is nothing to drop.
	 */
	private static function adapt_refused_converse( $payload, $model_id, $body ) {
		$quirk = self::converse_quirk_from_error( $body );
		if ( '' === $quirk ) {
			// A schema the constrained decoder cannot take is a fault of this request, not of
			// the model, so it is dropped for this request only and not remembered.
			$lower = strtolower( (string) $body );
			if ( ( isset( $payload['outputConfig'] ) || isset( $payload['output_config'] ) ) && ( false !== strpos( $lower, 'output_config' ) || false !== strpos( $lower, 'outputconfig' ) || false !== strpos( $lower, 'schema' ) ) ) {
				$adapted = self::apply_converse_quirks( $payload, array( 'no_output_schema' ) );
				return $adapted === $payload ? null : $adapted;
			}
			return null;
		}
		self::remember_converse_quirk( $model_id, $quirk );
		$adapted = self::apply_converse_quirks( $payload, array( $quirk ) );
		return $adapted === $payload ? null : $adapted;
	}

	private function normalize_claude_messages( $messages ) {
		$normalized = array();
		foreach ( $messages as $message ) {
			$message['images'] = isset( $message['images'] ) && is_array( $message['images'] ) ? $message['images'] : array();
			$last              = count( $normalized ) - 1;
			if ( $last >= 0 && $normalized[ $last ]['role'] === $message['role'] ) {
				$normalized[ $last ]['content'] = trim( $normalized[ $last ]['content'] . "\n\n" . $message['content'] );
				$normalized[ $last ]['images']  = array_slice( array_merge( $normalized[ $last ]['images'], $message['images'] ), 0, 20 );
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
					'images'  => array(),
				)
			);
		}
		return $normalized;
	}

	private function invoke_model( $payload, $model_id, $api = 'invoke', $adaptations = 2 ) {
		$converse = 'converse' === $api;
		$endpoint = $this->service_endpoint( 'bedrock-runtime' ) . '/model/' . rawurlencode( $model_id ) . ( $converse ? '/converse' : '/invoke' );
		$body     = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $body ) {
			return $this->error( __( 'The Bedrock request could not be encoded.', 'ai-chat-for-amazon-bedrock' ) );
		}

		// Converse takes the guardrail in the body, and ignores these headers. Image models are
		// checked with ApplyGuardrail before the call instead, see apply_guardrail().
		$headers = $this->signed_headers( $endpoint, $body, 'POST', 'bedrock', 'invoke' === $api ? $this->guardrail_headers() : array() );
		$timeout = (int) apply_filters( 'ai_chat_bedrock_http_timeout', 120 );
		$this->log_debug(
			'Sending request',
			array(
				'model'         => $model_id,
				'region'        => $this->region,
				'payload_bytes' => strlen( $body ),
			)
		);
		// From here on a request leaves the site, so a final failure of this chat call counts.
		$this->request_sent = true;
		$response           = self::aws_remote(
			'POST',
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
		// Claude on InvokeModel refuses an output schema the same way some Converse models do.
		if ( ( $converse || false !== strpos( $model_id, 'anthropic.claude' ) ) && 400 === $status && $adaptations > 0 ) {
			$adapted = self::adapt_refused_converse( $payload, $model_id, wp_remote_retrieve_body( $response ) );
			if ( null !== $adapted ) {
				$this->log_debug( 'Retrying without a field the model refused', array( 'model' => $model_id ) );
				return $this->invoke_model( $adapted, $model_id, $api, $adaptations - 1 );
			}
		}
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
		$guardrail = $this->guardrail_setting();
		if ( empty( $guardrail ) ) {
			return array();
		}
		return array(
			'x-amzn-bedrock-guardrailidentifier' => $guardrail['id'],
			'x-amzn-bedrock-guardrailversion'    => $guardrail['version'],
		);
	}

	/**
	 * The configured guardrail, validated, or an empty array when there is none.
	 *
	 * @return array Array with id and version keys.
	 */
	private function guardrail_setting() {
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
			'id'      => $id,
			'version' => $version,
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

	/**
	 * Count a chat call's final failure, once, when it actually reached Bedrock.
	 *
	 * Called on every return of handle_chat_message() and stream_chat_message() after the
	 * first request was prepared. Configuration errors never send a request and are not
	 * counted. Only the status and error code are used; no message text is stored.
	 *
	 * @param array $result Final result returned to the caller.
	 * @return array The same result, unchanged.
	 */
	private function record_chat_outcome( $result ) {
		if ( is_array( $result ) && empty( $result['success'] ) && $this->request_sent && class_exists( 'AI_Chat_Bedrock_Usage' ) ) {
			$status = isset( $result['data']['status'] ) ? (int) $result['data']['status'] : 0;
			$code   = isset( $result['data']['code'] ) ? (string) $result['data']['code'] : '';
			AI_Chat_Bedrock_Usage::record_failure( AI_Chat_Bedrock_Usage::classify_failure( $status, $code ) );
		}
		return $result;
	}

	private function record_usage( $usage, $model_id = '' ) {
		if ( class_exists( 'AI_Chat_Bedrock_Usage' ) ) {
			AI_Chat_Bedrock_Usage::record( is_array( $usage ) ? $usage : array(), (string) $model_id, $this->usage_kind );
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
		$response = $this->invoke_model( $payload, $model_id, self::chat_api( $model_id ) );
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

		$missing = $this->missing_credentials( 'bedrock-agent-runtime' );
		if ( null !== $missing ) {
			return $missing;
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

		$response = self::aws_remote(
			'POST',
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
	 * Score documents by how well they answer a query, with a Bedrock reranking model.
	 *
	 * Uses the Rerank API of Bedrock Agent Runtime in the configured Region. It is billed per
	 * query, so the plugin sends every candidate in one call.
	 *
	 * @param string $query     Search query.
	 * @param array  $documents Texts to score, in any order.
	 * @param string $model_id  Reranking model, such as cohere.rerank-v3-5:0.
	 * @return array|WP_Error Map of document position to relevance score (0 to 1), best first.
	 */
	public function rerank( $query, $documents, $model_id ) {
		$query     = trim( (string) $query );
		$documents = array_values( array_map( 'strval', (array) $documents ) );
		$model_id  = (string) $model_id;

		$missing = $this->missing_credentials( 'bedrock-agent-runtime' );
		if ( null !== $missing ) {
			return $missing;
		}
		if ( ! preg_match( '/^[a-z0-9-]+\.rerank-[a-z0-9.-]+:\d+$/', $model_id ) ) {
			return new WP_Error( 'aicfab_invalid_model', __( 'The reranking model ID is invalid.', 'ai-chat-for-amazon-bedrock' ) );
		}
		if ( '' === $query || empty( $documents ) ) {
			return new WP_Error( 'aicfab_empty_query', __( 'A search query is required.', 'ai-chat-for-amazon-bedrock' ) );
		}
		if ( ! preg_match( '/^[a-z]{2}(?:-gov)?-[a-z]+-\d$/', $this->region ) ) {
			return new WP_Error( 'aicfab_invalid_region', __( 'The configured AWS region is invalid.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$sources = array();
		foreach ( array_slice( $documents, 0, self::MAX_RERANK_DOCUMENTS ) as $document ) {
			$sources[] = array(
				'type'                 => 'INLINE',
				'inlineDocumentSource' => array(
					'type'         => 'TEXT',
					'textDocument' => array( 'text' => AI_Chat_Bedrock_Security::string_substr( '' !== trim( $document ) ? $document : '-', 0, 4000 ) ),
				),
			);
		}
		$payload  = array(
			'queries'                => array(
				array(
					'type'      => 'TEXT',
					'textQuery' => array( 'text' => AI_Chat_Bedrock_Security::string_substr( $query, 0, 1000 ) ),
				),
			),
			'sources'                => $sources,
			'rerankingConfiguration' => array(
				'type'                          => 'BEDROCK_RERANKING_MODEL',
				'bedrockRerankingConfiguration' => array(
					'numberOfResults'    => count( $sources ),
					'modelConfiguration' => array( 'modelArn' => sprintf( 'arn:aws:bedrock:%s::foundation-model/%s', $this->region, $model_id ) ),
				),
			),
		);
		$endpoint = $this->service_endpoint( 'bedrock-agent-runtime' ) . '/rerank';
		$body     = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $body ) {
			return new WP_Error( 'aicfab_encode_failed', __( 'The reranking request could not be encoded.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$response = self::aws_remote(
			'POST',
			$endpoint,
			array(
				// A visitor is waiting, and the passages are usable in their original order.
				'timeout'            => 10,
				'redirection'        => 0,
				'httpversion'        => '1.1',
				'reject_unsafe_urls' => true,
				'headers'            => $this->signed_headers( $endpoint, $body ),
				'body'               => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'aicfab_transport', __( 'The Amazon Bedrock reranking model could not be reached.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 0 ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 ) {
			$this->log_debug( 'Rerank error', array( 'status' => $status ) );
			$message = is_array( $data ) && isset( $data['message'] ) ? sanitize_text_field( (string) $data['message'] ) : '';
			return new WP_Error(
				'aicfab_http_error',
				'' !== $message
					? $message
					/* translators: %d: HTTP status code returned by Amazon Bedrock. */
					: sprintf( __( 'The reranking model returned HTTP %d.', 'ai-chat-for-amazon-bedrock' ), $status ),
				array( 'status' => $status )
			);
		}

		$scores = array();
		foreach ( is_array( $data ) && isset( $data['results'] ) && is_array( $data['results'] ) ? $data['results'] : array() as $result ) {
			if ( isset( $result['index'], $result['relevanceScore'] ) && is_numeric( $result['index'] ) && is_numeric( $result['relevanceScore'] ) && (int) $result['index'] < count( $sources ) ) {
				$scores[ (int) $result['index'] ] = (float) $result['relevanceScore'];
			}
		}
		if ( empty( $scores ) ) {
			return new WP_Error( 'aicfab_invalid_response', __( 'The reranking model returned no scores.', 'ai-chat-for-amazon-bedrock' ) );
		}
		arsort( $scores );
		if ( class_exists( 'AI_Chat_Bedrock_Usage' ) ) {
			AI_Chat_Bedrock_Usage::record( array(), $model_id, 'rerank' );
		}
		return $scores;
	}

	/**
	 * Call the Amazon S3 Vectors API.
	 *
	 * Every operation is a signed JSON POST to https://s3vectors.<region>.api.aws/<Operation>.
	 * S3 Vectors accepts only Signature Version 4, never a Bedrock API key.
	 *
	 * @param string $operation One of the operations listed below.
	 * @param array  $payload   Request body.
	 * @param string $region    Region of the vector bucket, when it differs from the Bedrock Region.
	 * @return array|WP_Error Decoded response, or an error whose data holds the HTTP status.
	 */
	public function s3_vectors( $operation, $payload, $region = '' ) {
		$operations = array( 'CreateIndex', 'DeleteVectors', 'GetIndex', 'GetVectorBucket', 'GetVectors', 'ListIndexes', 'ListVectors', 'PutVectors', 'QueryVectors' );
		if ( ! in_array( $operation, $operations, true ) ) {
			return new WP_Error( 'aicfab_s3v_operation', __( 'That S3 Vectors operation is not supported.', 'ai-chat-for-amazon-bedrock' ) );
		}
		$missing = $this->missing_credentials( 's3vectors', false );
		if ( null !== $missing ) {
			return $missing;
		}

		$saved  = $this->region;
		$region = '' !== (string) $region ? sanitize_key( (string) $region ) : $this->region;
		if ( ! preg_match( '/^[a-z]{2}(?:-gov)?-[a-z]+-\d$/', $region ) ) {
			return new WP_Error( 'aicfab_invalid_region', __( 'The configured AWS region is invalid.', 'ai-chat-for-amazon-bedrock' ) );
		}

		// S3 Vectors lives under api.aws, not amazonaws.com. An API endpoint, not offloaded assets.
		$default  = 'https://s3vectors.' . $region . '.api.aws'; // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent
		$base     = (string) apply_filters( 'ai_chat_bedrock_service_endpoint', $default, 's3vectors', $region );
		$base     = 0 === strpos( $base, 'https://' ) ? untrailingslashit( $base ) : $default;
		$endpoint = $base . '/' . $operation;
		$body     = wp_json_encode( empty( $payload ) ? new stdClass() : $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false === $body ) {
			return new WP_Error( 'aicfab_encode_failed', __( 'The S3 Vectors request could not be encoded.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$this->region = $region;
		try {
			$headers = $this->signed_headers( $endpoint, $body, 'POST', 's3vectors', array(), false );
		} finally {
			$this->region = $saved;
		}

		$response = self::aws_remote(
			'POST',
			$endpoint,
			array(
				'timeout'            => 30,
				'redirection'        => 0,
				'httpversion'        => '1.1',
				'reject_unsafe_urls' => true,
				'headers'            => $headers,
				'body'               => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'aicfab_transport', __( 'Amazon S3 Vectors could not be reached.', 'ai-chat-for-amazon-bedrock' ), array( 'status' => 0 ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$data   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( $status < 200 || $status >= 300 ) {
			$type = (string) wp_remote_retrieve_header( $response, 'x-amzn-errortype' );
			if ( '' === $type && is_array( $data ) && isset( $data['__type'] ) && is_string( $data['__type'] ) ) {
				$type = substr( (string) strrchr( '#' . $data['__type'], '#' ), 1 );
			}
			$type = preg_replace( '/[^A-Za-z]/', '', (string) strtok( $type, ':' ) );
			if ( '' === $type ) {
				$by_status = array(
					403 => 'AccessDeniedException',
					404 => 'NotFoundException',
					409 => 'ConflictException',
					429 => 'TooManyRequestsException',
				);
				$type      = isset( $by_status[ $status ] ) ? $by_status[ $status ] : 'HttpError';
			}
			$this->log_debug(
				'S3 Vectors error',
				array(
					'status' => $status,
					'code'   => $type,
				)
			);
			$message = is_array( $data ) && isset( $data['message'] ) ? sanitize_text_field( (string) $data['message'] ) : '';
			if ( 403 === $status || 'AccessDeniedException' === $type ) {
				$message = __( 'The AWS identity is not authorized for this S3 Vectors operation. Add the s3vectors actions from the IAM policy on the Diagnostics screen.', 'ai-chat-for-amazon-bedrock' );
			} elseif ( '' === $message ) {
				/* translators: %d: HTTP status code returned by Amazon S3 Vectors. */
				$message = sprintf( __( 'Amazon S3 Vectors returned HTTP %d.', 'ai-chat-for-amazon-bedrock' ), $status );
			}
			return new WP_Error(
				'aicfab_s3v_' . $type,
				$message,
				array( 'status' => $status )
			);
		}
		return is_array( $data ) ? $data : array();
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

		$missing = $this->missing_credentials( 'sts' );
		if ( null !== $missing ) {
			return $missing;
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

		$response = self::aws_remote(
			'GET',
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
		$missing = $this->missing_credentials( (string) $host_prefix );
		if ( null !== $missing ) {
			return $missing;
		}
		if ( ! preg_match( '/^[a-z]{2}(?:-gov)?-[a-z]+-\d$/', $this->region ) ) {
			return new WP_Error( 'aicfab_invalid_region', __( 'The configured AWS region is invalid.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$host_prefix = preg_match( '/^[a-z][a-z0-9-]{0,40}$/', (string) $host_prefix ) ? (string) $host_prefix : 'bedrock';
		$endpoint    = $this->service_endpoint( $host_prefix ) . $path;
		$headers     = $this->signed_headers( $endpoint, '', 'GET' );
		$response    = self::aws_remote(
			'GET',
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
		$missing = $client->missing_credentials( $service, false );
		if ( null !== $missing ) {
			return $missing;
		}

		$region = sanitize_key( (string) $region );
		if ( '' !== $region ) {
			if ( ! preg_match( '/^[a-z]{2}(?:-gov)?-[a-z]+-\d$/', $region ) ) {
				return new WP_Error( 'aicfab_invalid_region', __( 'The configured AWS region is invalid.', 'ai-chat-for-amazon-bedrock' ) );
			}
			$client->region = $region;
		}

		return $client->signed_headers( $endpoint, (string) $body, $method, $service, array(), false );
	}

	/**
	 * Headers that authorise a request: a bearer API key where Bedrock accepts one,
	 * otherwise a Signature Version 4 signature.
	 *
	 * @param string $endpoint   Absolute endpoint URL.
	 * @param string $body       Request body.
	 * @param string $method     HTTP method.
	 * @param string $service    Signing name.
	 * @param array  $extra      Additional headers to send (and sign).
	 * @param bool   $use_bearer Whether an API key may be used for this request.
	 * @return array
	 */
	private function signed_headers( $endpoint, $body, $method = 'POST', $service = 'bedrock', $extra = array(), $use_bearer = true ) {
		$host = wp_parse_url( $endpoint, PHP_URL_HOST );

		/*
		 * Bedrock API keys work for Bedrock and Bedrock Runtime, which both sign as "bedrock".
		 * The Agents endpoints (Knowledge Bases and Prompt Management) sign as "bedrock" as
		 * well but refuse API keys, so they are told apart by host.
		 */
		if ( $use_bearer && '' !== $this->api_key && 'bedrock' === $service && false === strpos( (string) $host, 'bedrock-agent' ) ) {
			$headers = array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $this->api_key,
			);
			foreach ( (array) $extra as $name => $value ) {
				$name = preg_replace( '/[^A-Za-z0-9-]/', '', (string) $name );
				if ( '' !== $name && ! isset( $headers[ $name ] ) ) {
					$headers[ $name ] = (string) $value;
				}
			}
			return $headers;
		}
		$this->resolve_signing_credentials();

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
		// Stability image models answer with base64 images and one finish reason per image.
		if ( isset( $data['images'] ) && is_array( $data['images'] ) ) {
			$images = array();
			foreach ( $data['images'] as $image ) {
				if ( is_string( $image ) && '' !== $image ) {
					$images[] = $image;
				}
			}
			if ( empty( $images ) ) {
				return $this->error( __( 'Amazon Bedrock returned no image.', 'ai-chat-for-amazon-bedrock' ) );
			}
			return array(
				'success'        => true,
				'data'           => array( 'message' => '' ),
				'images'         => $images,
				'finish_reasons' => isset( $data['finish_reasons'] ) && is_array( $data['finish_reasons'] ) ? $data['finish_reasons'] : array(),
			);
		}

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
		// Claude says stop_reason, Nova and Converse stopReason, Titan completionReason.
		foreach ( array( 'stop_reason', 'stopReason' ) as $key ) {
			if ( isset( $data[ $key ] ) && is_string( $data[ $key ] ) ) {
				$result['stop_reason'] = sanitize_key( $data[ $key ] );
				break;
			}
		}
		if ( ! isset( $result['stop_reason'] ) && isset( $data['results'][0]['completionReason'] ) && is_string( $data['results'][0]['completionReason'] ) ) {
			$result['stop_reason'] = sanitize_key( $data['results'][0]['completionReason'] );
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

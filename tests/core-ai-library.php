<?php
/**
 * The WordPress AI Client provider, run against the real php-ai-client library.
 *
 * tests/core-ai.php reads the provider's source because the library is not there. This suite
 * loads the library itself, so it can ask core's own requirement matching whether a prompt
 * reaches the model the provider meant it to, and run the models end to end against a stand-in
 * for the plugin's request path. Point AICFAB_AI_CLIENT at the library directory (the one
 * holding src/, such as wp-includes/php-ai-client); without it the suite skips. Run it against
 * each library version the plugin supports: embeddings exist from 1.4 (WordPress 7.2) only.
 *
 * Run: AICFAB_AI_CLIENT=/path/to/php-ai-client php tests/core-ai-library.php
 *
 * @package AI_Chat_Bedrock
 */

$aicfab_lib = (string) getenv( 'AICFAB_AI_CLIENT' );
if ( '' === $aicfab_lib || ! is_dir( $aicfab_lib . '/src' ) ) {
	echo "SKIP: set AICFAB_AI_CLIENT to a php-ai-client directory to run the provider against it\n";
	exit( 0 );
}
if ( file_exists( $aicfab_lib . '/autoload.php' ) ) {
	// The copy bundled in WordPress leaves the PHP 8 string functions to core's compat.php.
	if ( ! function_exists( 'str_starts_with' ) ) {
		function str_starts_with( $haystack, $needle ) {
			return 0 === strncmp( (string) $haystack, (string) $needle, strlen( (string) $needle ) );
		}
	}
	if ( ! function_exists( 'str_ends_with' ) ) {
		function str_ends_with( $haystack, $needle ) {
			return '' === (string) $needle || substr( (string) $haystack, -strlen( (string) $needle ) ) === (string) $needle;
		}
	}
	if ( ! function_exists( 'array_is_list' ) ) {
		function array_is_list( $arr ) {
			return array() === $arr || array_keys( $arr ) === range( 0, count( $arr ) - 1 );
		}
	}
	if ( ! function_exists( 'str_contains' ) ) {
		function str_contains( $haystack, $needle ) {
			return '' === (string) $needle || false !== strpos( (string) $haystack, (string) $needle );
		}
	}
	require $aicfab_lib . '/autoload.php';
} else {
	// What Composer's "files" entry loads; PHP 7.4 needs it.
	if ( file_exists( $aicfab_lib . '/src/polyfills.php' ) ) {
		require $aicfab_lib . '/src/polyfills.php';
	}
	spl_autoload_register(
		static function ( $class_name ) use ( $aicfab_lib ) {
			if ( 0 === strpos( $class_name, 'WordPress\\AiClient\\' ) ) {
				$file = $aicfab_lib . '/src/' . str_replace( '\\', '/', substr( $class_name, 19 ) ) . '.php';
				if ( file_exists( $file ) ) {
					require $file;
				}
			}
		}
	);
}

// The provider declines a partial library rather than fatal on it, and so does this suite.
if ( ! interface_exists( 'WordPress\\AiClient\\Providers\\Models\\TextGeneration\\Contracts\\TextGenerationModelInterface' ) ) {
	echo "SKIP: the library at AICFAB_AI_CLIENT has no text generation interface\n";
	exit( 0 );
}

use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Files\Enums\MediaOrientationEnum;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\UserMessage;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\DTO\ProviderMetadata;
use WordPress\AiClient\Providers\Enums\ProviderTypeEnum;
use WordPress\AiClient\Providers\Models\DTO\ModelConfig;
use WordPress\AiClient\Providers\Models\DTO\ModelRequirements;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['aicfab_options'] = array(
	'ai_chat_bedrock_settings' => array(
		'model_id'       => 'us.anthropic.claude-haiku-4-5-20251001-v1:0',
		'image_model_id' => 'stability.stable-image-core-v1:1',
	),
);

class WP_Error {
	private $code;
	private $message;
	public function __construct( $code, $message = '' ) {
		$this->code    = $code;
		$this->message = $message;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
	}
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['aicfab_options'] ) ? $GLOBALS['aicfab_options'][ $name ] : $default;
}
function apply_filters( $hook, $value ) {
	return $value;
}
function add_filter() {}
function add_action() {}
function __( $text, $domain = '' ) {
	return $text;
}
function esc_html__( $text, $domain = '' ) {
	return $text;
}
function esc_html( $text ) {
	return $text;
}
function sanitize_text_field( $text ) {
	return trim( (string) $text );
}
function wp_strip_all_tags( $text ) {
	return strip_tags( (string) $text );
}
function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}

/** Stands in for the plugin's usage accounting. */
class AI_Chat_Bedrock_Usage {
	public static function daily_limit_reached( $options = null ) {
		return false;
	}
}

/** Stands in for the plugin's string helpers. */
class AI_Chat_Bedrock_Security {
	public static function string_length( $text ) {
		return strlen( (string) $text );
	}
}

/** Stands in for the plugin's embedding catalog. */
class AI_Chat_Bedrock_Embeddings {
	public static function models() {
		return array(
			'amazon.titan-embed-text-v2:0' => 'Amazon Titan Text Embeddings V2',
			'amazon.titan-embed-text-v1'   => 'Amazon Titan Embeddings G1',
		);
	}
}

/**
 * Stands in for the plugin's request path. Records every call and answers from a script.
 */
class AI_Chat_Bedrock_AWS {
	public static $calls     = array();
	public static $replies   = array();
	public static $guardrail = true;
	public $overrides;
	public function __construct( $overrides = array() ) {
		$this->overrides = $overrides;
	}
	public static function accepts_top_p( $model_id ) {
		return false === strpos( (string) $model_id, 'gpt-6' );
	}
	private function reply( $kind, $data ) {
		self::$calls[] = array( $kind, $data, $this->overrides );
		return array_shift( self::$replies );
	}
	public function handle_chat_message( $data ) {
		return $this->reply( 'chat', $data );
	}
	public function invoke_image( $payload, $model_id ) {
		return $this->reply( 'image', array( 'payload' => $payload, 'model' => $model_id ) );
	}
	public function apply_guardrail( $text, $source = 'INPUT' ) {
		self::$calls[] = array( 'guardrail', $text, $this->overrides );
		return self::$guardrail;
	}
	public function embed( $text, $model_id, $purpose = 'document', $dimensions = 0 ) {
		return $this->reply( 'embed', array( $text, $model_id, $purpose, $dimensions ) );
	}
}

define( 'AI_CHAT_BEDROCK_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
require_once dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-core-ai.php';
require_once dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-models.php';
require_once dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-images.php';
foreach ( array( 'ai-model', 'ai-model-directory', 'ai-image-model' ) as $aicfab_file ) {
	require_once dirname( __DIR__ ) . '/includes/core-ai/class-ai-chat-bedrock-' . $aicfab_file . '.php';
}
$aicfab_embeddings = interface_exists( AI_Chat_Bedrock_AI_Model_Directory::EMBEDDING_INTERFACE );
if ( $aicfab_embeddings ) {
	require_once dirname( __DIR__ ) . '/includes/core-ai/class-ai-chat-bedrock-ai-embedding-model.php';
}

$failures = array();
function check_library( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

/**
 * Whether core's own matching sends a prompt with this configuration to a model.
 */
function aicfab_matches( $capability, $messages, $config, $metadata ) {
	return ModelRequirements::fromPromptData( $capability, $messages, $config )->areMetBy( $metadata );
}

/**
 * A model's metadata from the directory.
 */
function aicfab_metadata( $id ) {
	return ( new AI_Chat_Bedrock_AI_Model_Directory() )->getModelMetadata( $id );
}

/**
 * A text part, an inline PNG part, and a provider to hand the models.
 */
$aicfab_png      = base64_encode( "\x89PNG\r\n\x1a\nfake" );
$aicfab_image    = new MessagePart( new File( $aicfab_png, 'image/png' ) );
$aicfab_provider = new ProviderMetadata( 'amazon-bedrock', 'Amazon Bedrock', ProviderTypeEnum::cloud() );
$aicfab_text_msg = array( new UserMessage( array( new MessagePart( 'Describe this.' ) ) ) );
$aicfab_img_msg  = array( new UserMessage( array( new MessagePart( 'Describe this.' ), $aicfab_image ) ) );

// --- What the directory offers --------------------------------------------------

$aicfab_list = ( new AI_Chat_Bedrock_AI_Model_Directory() )->listModelMetadata();
$aicfab_ids  = array_map(
	static function ( $metadata ) {
		return $metadata->getId();
	},
	$aicfab_list
);
check_library( 'us.anthropic.claude-haiku-4-5-20251001-v1:0' === $aicfab_ids[0], 'The configured text model is listed first.' );
$aicfab_first_image = null;
foreach ( $aicfab_list as $aicfab_metadata ) {
	if ( null === $aicfab_first_image && in_array( CapabilityEnum::imageGeneration(), $aicfab_metadata->getSupportedCapabilities(), false ) ) {
		$aicfab_first_image = $aicfab_metadata->getId();
	}
}
check_library( 'stability.stable-image-core-v1:1' === $aicfab_first_image, 'The chosen image model is the first image model, since core takes the first that fits.' );
check_library( in_array( 'stability.sd3-5-large-v1:0', $aicfab_ids, true ), 'Every image model is listed once image generation is on.' );
check_library( $aicfab_embeddings === in_array( 'amazon.titan-embed-text-v2:0', $aicfab_ids, true ), 'Embedding models are listed exactly where the library has embeddings.' );

$GLOBALS['aicfab_options']['ai_chat_bedrock_settings']['image_model_id'] = '';
$aicfab_off = array_map(
	static function ( $metadata ) {
		return $metadata->getId();
	},
	( new AI_Chat_Bedrock_AI_Model_Directory() )->listModelMetadata()
);
check_library( ! in_array( 'stability.stable-image-core-v1:1', $aicfab_off, true ), 'No image model is offered while image generation is off.' );
$GLOBALS['aicfab_options']['ai_chat_bedrock_settings']['image_model_id'] = 'stability.stable-image-core-v1:1';

// --- Core's matching, for text ----------------------------------------------------

$aicfab_haiku = aicfab_metadata( 'us.anthropic.claude-haiku-4-5-20251001-v1:0' );
$aicfab_micro = aicfab_metadata( 'amazon.nova-micro-v1:0' );
$aicfab_plain = new ModelConfig();
check_library( aicfab_matches( CapabilityEnum::textGeneration(), $aicfab_text_msg, $aicfab_plain, $aicfab_haiku ), 'A text prompt reaches a vision model.' );
check_library( aicfab_matches( CapabilityEnum::textGeneration(), $aicfab_img_msg, $aicfab_plain, $aicfab_haiku ), 'A prompt with an image reaches a model that reads images.' );
check_library( aicfab_matches( CapabilityEnum::textGeneration(), $aicfab_text_msg, $aicfab_plain, $aicfab_micro ), 'A text prompt reaches a text-only model.' );
check_library( ! aicfab_matches( CapabilityEnum::textGeneration(), $aicfab_img_msg, $aicfab_plain, $aicfab_micro ), 'A prompt with an image does not reach a text-only model.' );

$aicfab_json = new ModelConfig();
$aicfab_json->setOutputMimeType( 'application/json' );
$aicfab_json->setOutputSchema( array( 'type' => 'object', 'properties' => array( 'title' => array( 'type' => 'string' ) ) ) );
$aicfab_json->setCandidateCount( 3 );
$aicfab_json->setStopSequences( array( 'END' ) );
$aicfab_json->setTopP( 0.9 );
check_library( aicfab_matches( CapabilityEnum::textGeneration(), $aicfab_text_msg, $aicfab_json, $aicfab_haiku ), 'JSON with a schema, three candidates, a stop sequence and top P reach the model.' );
$GLOBALS['aicfab_options']['ai_chat_bedrock_settings']['model_id'] = 'openai.gpt-6';
check_library( ! aicfab_matches( CapabilityEnum::textGeneration(), $aicfab_text_msg, $aicfab_json, aicfab_metadata( 'openai.gpt-6' ) ), 'Top P does not reach a model that refuses it.' );
$GLOBALS['aicfab_options']['ai_chat_bedrock_settings']['model_id'] = 'us.anthropic.claude-haiku-4-5-20251001-v1:0';
$aicfab_many = new ModelConfig();
$aicfab_many->setCandidateCount( AI_Chat_Bedrock_AI_Model::MAX_CANDIDATES + 1 );
check_library( ! aicfab_matches( CapabilityEnum::textGeneration(), $aicfab_text_msg, $aicfab_many, $aicfab_haiku ), 'More candidates than the cap do not match.' );

// --- Core's matching, for images --------------------------------------------------

$aicfab_core  = aicfab_metadata( 'stability.stable-image-core-v1:1' );
$aicfab_sd35  = aicfab_metadata( 'stability.sd3-5-large-v1:0' );
$aicfab_wide  = new ModelConfig();
$aicfab_wide->setOutputMediaOrientation( MediaOrientationEnum::landscape() );
$aicfab_wide->setOutputMediaAspectRatio( '16:9' );
$aicfab_wide->setOutputMimeType( 'image/jpeg' );
check_library( aicfab_matches( CapabilityEnum::imageGeneration(), $aicfab_text_msg, $aicfab_wide, $aicfab_core ), 'A landscape JPEG request reaches Stable Image Core.' );
check_library( ! aicfab_matches( CapabilityEnum::textGeneration(), $aicfab_text_msg, $aicfab_plain, $aicfab_core ), 'An image model is never offered for text.' );
check_library( ! aicfab_matches( CapabilityEnum::imageGeneration(), $aicfab_text_msg, $aicfab_plain, $aicfab_haiku ), 'A text model is never offered for images.' );
check_library( aicfab_matches( CapabilityEnum::imageGeneration(), $aicfab_img_msg, $aicfab_plain, $aicfab_sd35 ), 'Editing an image reaches Stable Diffusion 3.5 Large.' );
check_library( ! aicfab_matches( CapabilityEnum::imageGeneration(), $aicfab_img_msg, $aicfab_plain, $aicfab_core ), 'Editing an image does not reach a model without image-to-image.' );
$aicfab_odd = new ModelConfig();
$aicfab_odd->setOutputMediaAspectRatio( '7:3' );
check_library( ! aicfab_matches( CapabilityEnum::imageGeneration(), $aicfab_text_msg, $aicfab_odd, $aicfab_core ), 'An aspect ratio the models lack does not match.' );

// --- The text model, end to end ---------------------------------------------------

$aicfab_model = new AI_Chat_Bedrock_AI_Model( $aicfab_haiku, $aicfab_provider );
$aicfab_cfg   = new ModelConfig();
$aicfab_cfg->setCandidateCount( 2 );
$aicfab_cfg->setStopSequences( array( 'STOP' ) );
$aicfab_cfg->setTopP( 0.8 );
$aicfab_model->setConfig( $aicfab_cfg );
AI_Chat_Bedrock_AWS::$calls   = array();
AI_Chat_Bedrock_AWS::$replies = array(
	array( 'success' => true, 'data' => array( 'message' => 'First STOP and more' ), 'usage' => array( 'input_tokens' => 10, 'output_tokens' => 4 ) ),
	array( 'success' => true, 'data' => array( 'message' => 'Second' ), 'usage' => array( 'input_tokens' => 10, 'output_tokens' => 2 ), 'stop_reason' => 'max_tokens' ),
);
$aicfab_result = $aicfab_model->generateTextResult( $aicfab_img_msg );
$aicfab_cands  = $aicfab_result->getCandidates();
check_library( 2 === count( $aicfab_cands ) && 2 === count( AI_Chat_Bedrock_AWS::$calls ), 'Two candidates are two requests and two results.' );
check_library( 'First ' === $aicfab_cands[0]->getMessage()->getParts()[0]->getText(), 'An answer is cut at the stop sequence a model ignored.' );
check_library( $aicfab_cands[0]->getFinishReason()->isStop() && $aicfab_cands[1]->getFinishReason()->isLength(), 'Finish reasons follow the stop sequence and Bedrock\'s max_tokens.' );
check_library( 20 === $aicfab_result->getTokenUsage()->getPromptTokens() && 6 === $aicfab_result->getTokenUsage()->getCompletionTokens(), 'Usage is the sum over candidates.' );
$aicfab_sent = AI_Chat_Bedrock_AWS::$calls[0][1];
$aicfab_user = end( $aicfab_sent['messages'] );
check_library( isset( $aicfab_user['images'][0] ) && array( 'media_type' => 'image/png', 'data' => $aicfab_png ) === $aicfab_user['images'][0], 'The image reaches the request path as base64 with its type.' );
check_library( 0.8 === $aicfab_sent['top_p'] && array( 'STOP' ) === $aicfab_sent['stop_sequences'], 'Top P and stop sequences reach the request path.' );
check_library( 'us.anthropic.claude-haiku-4-5-20251001-v1:0' === AI_Chat_Bedrock_AWS::$calls[0][2]['model_id'], 'The request goes to the model core chose.' );

$aicfab_thrown = false;
try {
	( new AI_Chat_Bedrock_AI_Model( $aicfab_micro, $aicfab_provider ) )->generateTextResult( $aicfab_img_msg );
} catch ( RuntimeException $e ) {
	$aicfab_thrown = true;
}
check_library( $aicfab_thrown, 'A model that reads no images refuses one rather than answering without it.' );

$aicfab_remote = array( new UserMessage( array( new MessagePart( 'Describe.' ), new MessagePart( new File( 'https://example.com/a.png', 'image/png' ) ) ) ) );
$aicfab_thrown = false;
try {
	$aicfab_model->setConfig( new ModelConfig() );
	$aicfab_model->generateTextResult( $aicfab_remote );
} catch ( RuntimeException $e ) {
	$aicfab_thrown = true;
}
check_library( $aicfab_thrown, 'A remote image is refused, never fetched.' );

// JSON: a fenced answer is unwrapped; prose gets one repair turn and then fails.
$aicfab_object = array( 'type' => 'object', 'properties' => array( 'title' => array( 'type' => 'string' ) ) );
$aicfab_cfg    = new ModelConfig();
$aicfab_cfg->setOutputMimeType( 'application/json' );
$aicfab_cfg->setOutputSchema( $aicfab_object );
$aicfab_model->setConfig( $aicfab_cfg );
AI_Chat_Bedrock_AWS::$calls   = array();
AI_Chat_Bedrock_AWS::$replies = array( array( 'success' => true, 'data' => array( 'message' => "```json\n{\"title\":\"Hi\"}\n```" ) ) );
$aicfab_text = $aicfab_model->generateTextResult( $aicfab_text_msg )->toText();
check_library( '{"title":"Hi"}' === $aicfab_text, 'A JSON answer in a code fence is returned as the JSON alone.' );
$aicfab_sent = AI_Chat_Bedrock_AWS::$calls[0][1];
check_library( $aicfab_object === $aicfab_sent['json_schema'], 'An object schema is passed on for constrained decoding.' );
check_library( 'system' === $aicfab_sent['messages'][0]['role'] && false !== strpos( $aicfab_sent['messages'][0]['content'], 'single JSON value' ), 'JSON mode tells the model to answer with JSON only.' );

AI_Chat_Bedrock_AWS::$calls   = array();
AI_Chat_Bedrock_AWS::$replies = array(
	array( 'success' => true, 'data' => array( 'message' => 'Sure! Here is a title.' ) ),
	array( 'success' => true, 'data' => array( 'message' => 'Here: {"title":"Hi"} hope that helps' ) ),
);
check_library( '{"title":"Hi"}' === $aicfab_model->generateTextResult( $aicfab_text_msg )->toText() && 2 === count( AI_Chat_Bedrock_AWS::$calls ), 'Prose gets one repair turn, and JSON inside a sentence is extracted.' );

AI_Chat_Bedrock_AWS::$replies = array(
	array( 'success' => true, 'data' => array( 'message' => 'No.' ) ),
	array( 'success' => true, 'data' => array( 'message' => 'Still no.' ) ),
);
$aicfab_thrown = false;
try {
	$aicfab_model->generateTextResult( $aicfab_text_msg );
} catch ( RuntimeException $e ) {
	$aicfab_thrown = false !== strpos( $e->getMessage(), 'valid JSON' );
}
check_library( $aicfab_thrown, 'Two answers without JSON fail clearly instead of returning prose.' );

$aicfab_cfg = new ModelConfig();
$aicfab_cfg->setOutputSchema( array( 'type' => 'array', 'items' => array( 'type' => 'string' ) ) );
$aicfab_model->setConfig( $aicfab_cfg );
AI_Chat_Bedrock_AWS::$calls   = array();
AI_Chat_Bedrock_AWS::$replies = array( array( 'success' => true, 'data' => array( 'message' => '["a","b"]' ) ) );
check_library( '["a","b"]' === $aicfab_model->generateTextResult( $aicfab_text_msg )->toText(), 'An array schema alone turns JSON mode on.' );
check_library( ! isset( AI_Chat_Bedrock_AWS::$calls[0][1]['json_schema'] ), 'A schema that is not an object is left to the prompt, since constrained decoding takes objects.' );

foreach ( array( '{"a":1}' => '{"a":1}', 'text' => null, '[1,2' => null, 'x [1] y {z' => '[1]' ) as $aicfab_in => $aicfab_out ) {
	check_library( $aicfab_out === AI_Chat_Bedrock_AI_Model::parse_json( $aicfab_in ), 'parse_json( ' . $aicfab_in . ' )' );
}

// --- The image model, end to end --------------------------------------------------

$aicfab_imodel = new AI_Chat_Bedrock_AI_Image_Model( $aicfab_core, $aicfab_provider );
$aicfab_imodel->setConfig( $aicfab_wide );
AI_Chat_Bedrock_AWS::$calls   = array();
AI_Chat_Bedrock_AWS::$replies = array( array( 'images' => array( $aicfab_png ), 'finish_reasons' => array( null ), 'seeds' => array( 7 ) ) );
$aicfab_out  = $aicfab_imodel->generateImageResult( array( new UserMessage( array( new MessagePart( 'A lighthouse at dawn' ) ) ) ) );
$aicfab_file = $aicfab_out->toImageFile();
check_library( $aicfab_file->isInline() && 'image/jpeg' === $aicfab_file->getMimeType() && $aicfab_png === $aicfab_file->getBase64Data(), 'The image comes back inline with the type asked for.' );
check_library( 7 === $aicfab_out->getAdditionalData()['seed'], 'The seed is reported.' );
check_library( 'guardrail' === AI_Chat_Bedrock_AWS::$calls[0][0] && 'A lighthouse at dawn' === AI_Chat_Bedrock_AWS::$calls[0][1], 'The prompt is checked with the guardrail first.' );
$aicfab_payload = AI_Chat_Bedrock_AWS::$calls[1][1]['payload'];
check_library( '16:9' === $aicfab_payload['aspect_ratio'] && 'jpeg' === $aicfab_payload['output_format'], 'Aspect ratio and format reach Stability.' );
check_library( array( 'aws_region' => 'us-west-2' ) === AI_Chat_Bedrock_AWS::$calls[1][2], 'Image requests go to the image region.' );

AI_Chat_Bedrock_AWS::$replies = array( array( 'images' => array( $aicfab_png ), 'finish_reasons' => array( 'Filter reason: prompt' ), 'seeds' => array( 1 ) ) );
$aicfab_thrown                = false;
try {
	$aicfab_imodel->generateImageResult( $aicfab_text_msg );
} catch ( RuntimeException $e ) {
	$aicfab_thrown = false !== strpos( $e->getMessage(), 'Filter reason: prompt' );
}
check_library( $aicfab_thrown, 'A filtered (blurred) image is reported, never returned.' );

AI_Chat_Bedrock_AWS::$guardrail = new WP_Error( 'aicfab_guardrail_blocked', 'Blocked by policy.' );
AI_Chat_Bedrock_AWS::$calls     = array();
$aicfab_thrown                  = false;
try {
	$aicfab_imodel->generateImageResult( $aicfab_text_msg );
} catch ( RuntimeException $e ) {
	$aicfab_thrown = 'Blocked by policy.' === $e->getMessage();
}
check_library( $aicfab_thrown && 1 === count( AI_Chat_Bedrock_AWS::$calls ), 'A blocked prompt never reaches the image model.' );
AI_Chat_Bedrock_AWS::$guardrail = true;

$aicfab_edit = new AI_Chat_Bedrock_AI_Image_Model( $aicfab_sd35, $aicfab_provider );
$aicfab_ecfg = new ModelConfig();
$aicfab_ecfg->setCustomOptions( array( 'strength' => 0.3 ) );
$aicfab_edit->setConfig( $aicfab_ecfg );
AI_Chat_Bedrock_AWS::$calls   = array();
AI_Chat_Bedrock_AWS::$replies = array( array( 'images' => array( $aicfab_png ), 'finish_reasons' => array( null ), 'seeds' => array( 2 ) ) );
$aicfab_edit->generateImageResult( $aicfab_img_msg );
$aicfab_payload = AI_Chat_Bedrock_AWS::$calls[1][1]['payload'];
check_library( 'image-to-image' === $aicfab_payload['mode'] && $aicfab_png === $aicfab_payload['image'] && 0.3 === $aicfab_payload['strength'] && ! isset( $aicfab_payload['aspect_ratio'] ), 'An image in the prompt is edited with the strength asked for.' );

// --- Embeddings, where the library has them ---------------------------------------

if ( $aicfab_embeddings ) {
	$aicfab_v2 = aicfab_metadata( 'amazon.titan-embed-text-v2:0' );
	$aicfab_v1 = aicfab_metadata( 'amazon.titan-embed-text-v1' );
	$aicfab_d  = new ModelConfig();
	$aicfab_d->setDimensions( 512 );
	$aicfab_in = array( new MessagePart( 'first' ), new MessagePart( 'second' ) );
	check_library( ModelRequirements::fromEmbeddingData( $aicfab_in, $aicfab_d )->areMetBy( $aicfab_v2 ), 'A 512-dimension request reaches Titan V2.' );
	check_library( ! ModelRequirements::fromEmbeddingData( $aicfab_in, $aicfab_d )->areMetBy( $aicfab_v1 ), 'A 512-dimension request does not reach a model that cannot produce it.' );
	check_library( ! aicfab_matches( CapabilityEnum::textGeneration(), $aicfab_text_msg, $aicfab_plain, $aicfab_v2 ), 'An embedding model is never offered for text.' );

	$aicfab_emodel = new AI_Chat_Bedrock_AI_Embedding_Model( $aicfab_v2, $aicfab_provider );
	$aicfab_d->setCustomOptions( array( 'purpose' => 'query' ) );
	$aicfab_emodel->setConfig( $aicfab_d );
	AI_Chat_Bedrock_AWS::$calls   = array();
	AI_Chat_Bedrock_AWS::$replies = array( array( 0.1, 0.2, 0.3 ), array( 0.4, 0.5, 0.6 ) );
	$aicfab_er                    = $aicfab_emodel->generateEmbeddingResult( $aicfab_in );
	check_library( 2 === count( $aicfab_er->getEmbeddings() ) && 3 === $aicfab_er->getDimensions(), 'Each input gets its vector, in order.' );
	check_library( array( 'first', 'amazon.titan-embed-text-v2:0', 'query', 512 ) === AI_Chat_Bedrock_AWS::$calls[0][1], 'The purpose and size reach the request path.' );
	AI_Chat_Bedrock_AWS::$replies = array( new WP_Error( 'aicfab_x', 'Throttled.' ) );
	$aicfab_thrown                = false;
	try {
		$aicfab_emodel->generateEmbeddingResult( array( new MessagePart( 'one' ) ) );
	} catch ( RuntimeException $e ) {
		$aicfab_thrown = 'Throttled.' === $e->getMessage();
	}
	check_library( $aicfab_thrown, 'A failed embedding is reported, not returned as an empty vector.' );
}

if ( $failures ) {
	echo "FAILED\n";
	foreach ( $failures as $failure ) {
		echo "- $failure\n";
	}
	exit( 1 );
}
printf( "OK: AI Client provider checks passed against the real library%s\n", $aicfab_embeddings ? ', with embeddings' : ', without embeddings (library before 1.4)' );

<?php
/**
 * Standalone tests for uploading videos to YouTube.
 *
 * Google's endpoints are scripted: each test queues the responses Google would give and
 * reads back the requests the plugin made.
 *
 * Run: php tests/youtube.php
 *
 * @package AI_Chat_Bedrock
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['aicfab_options']    = array();
$GLOBALS['aicfab_transients'] = array();
$GLOBALS['aicfab_meta']       = array();
$GLOBALS['aicfab_requests']   = array();
$GLOBALS['aicfab_responses']  = array();
$GLOBALS['aicfab_cron']       = array();
$GLOBALS['aicfab_can']        = array( 'manage_options', 'upload_files', 'edit_post:7' );
$GLOBALS['aicfab_redirect']   = '';
$GLOBALS['aicfab_records']    = array();

class WP_Error {
	private $code;
	private $message;
	private $data;
	public function __construct( $code, $message = '', $data = array() ) {
		$this->code    = $code;
		$this->message = $message;
		$this->data    = $data;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return $this->message;
	}
	public function get_error_data() {
		return $this->data;
	}
}
class WP_Post {
	public $ID;
	public $post_title;
	public function __construct( $id, $title ) {
		$this->ID         = $id;
		$this->post_title = $title;
	}
}
class AICFAB_Redirect extends Exception {}
function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}
function get_option( $name, $fallback = false ) {
	return array_key_exists( $name, $GLOBALS['aicfab_options'] ) ? $GLOBALS['aicfab_options'][ $name ] : $fallback;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['aicfab_options'][ $name ] = $value;
	return true;
}
function delete_option( $name ) {
	unset( $GLOBALS['aicfab_options'][ $name ] );
	return true;
}
function get_transient( $key ) {
	return array_key_exists( $key, $GLOBALS['aicfab_transients'] ) ? $GLOBALS['aicfab_transients'][ $key ] : false;
}
function set_transient( $key, $value, $ttl = 0 ) {
	$GLOBALS['aicfab_transients'][ $key ] = $value;
	return true;
}
function delete_transient( $key ) {
	unset( $GLOBALS['aicfab_transients'][ $key ] );
	return true;
}
function get_post_meta( $id, $key, $single = false ) {
	return isset( $GLOBALS['aicfab_meta'][ $id ][ $key ] ) ? $GLOBALS['aicfab_meta'][ $id ][ $key ] : '';
}
function update_post_meta( $id, $key, $value ) {
	$GLOBALS['aicfab_meta'][ $id ][ $key ] = $value;
	return true;
}
function get_post( $id ) {
	return 7 === (int) $id ? new WP_Post( 7, 'Lesson 1.1 <b>Kinematics</b>' ) : null;
}
function get_the_title( $post ) {
	return is_object( $post ) ? $post->post_title : 'Untitled';
}
function get_attached_file( $id ) {
	return isset( $GLOBALS['aicfab_files'][ $id ] ) ? $GLOBALS['aicfab_files'][ $id ] : '';
}
function get_post_mime_type( $id ) {
	return isset( $GLOBALS['aicfab_mimes'][ $id ] ) ? $GLOBALS['aicfab_mimes'][ $id ] : '';
}
function current_user_can( $capability, $id = 0 ) {
	return in_array( $capability . ( $id ? ':' . $id : '' ), $GLOBALS['aicfab_can'], true );
}
function get_current_user_id() {
	return 3;
}
function check_admin_referer( $action ) {
	return true;
}
function wp_die( $message = '', $code = 0 ) {
	throw new AICFAB_Redirect( 'die:' . $code );
}
function wp_redirect( $url ) {
	$GLOBALS['aicfab_redirect'] = $url;
	throw new AICFAB_Redirect( $url );
}
function wp_safe_redirect( $url ) {
	wp_redirect( $url );
}
function admin_url( $path = '' ) {
	return 'https://example.test/wp-admin/' . $path;
}
function add_query_arg( $args, $url, $third = null ) {
	if ( ! is_array( $args ) ) {
		$args = array( $args => $url );
		$url  = $third;
	}
	$pairs = array();
	foreach ( $args as $key => $value ) {
		$pairs[] = $key . '=' . $value;
	}
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . implode( '&', $pairs );
}
function wp_generate_password( $length, $special = true ) {
	return substr( str_repeat( 'abcdefghij0123456789', 5 ), 0, $length );
}
function sanitize_text_field( $value ) {
	return trim( preg_replace( '/[\r\n\t]+/', ' ', strip_tags( (string) $value ) ) );
}
function sanitize_textarea_field( $value ) {
	return trim( strip_tags( (string) $value ) );
}
function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}
function wp_strip_all_tags( $value ) {
	return trim( strip_tags( (string) $value ) );
}
function wp_unslash( $value ) {
	return $value;
}
function absint( $value ) {
	return abs( (int) $value );
}
function esc_html__( $text, $domain = null ) {
	return $text;
}
function __( $text, $domain = null ) {
	return $text;
}
function wp_json_encode( $value ) {
	return json_encode( $value );
}
function wp_salt() {
	return 'test-salt';
}
function wp_next_scheduled( $hook, $args ) {
	foreach ( $GLOBALS['aicfab_cron'] as $event ) {
		if ( $event[1] === $hook && $event[2] === $args ) {
			return $event[0];
		}
	}
	return false;
}
function wp_schedule_single_event( $time, $hook, $args ) {
	$GLOBALS['aicfab_cron'][] = array( $time, $hook, $args );
	return true;
}
function wp_clear_scheduled_hook( $hook, $args ) {
	$GLOBALS['aicfab_cron'] = array_values( array_filter( $GLOBALS['aicfab_cron'], function ( $event ) use ( $hook, $args ) { return ! ( $event[1] === $hook && $event[2] === $args ); } ) );
}

/** Google, as scripted: each request takes the next response in the queue. */
function aicfab_http( $method, $url, $args ) {
	$GLOBALS['aicfab_requests'][] = array( 'method' => $method, 'url' => $url, 'args' => $args );
	$next = array_shift( $GLOBALS['aicfab_responses'] );
	if ( null === $next ) {
		return new WP_Error( 'unscripted', 'No response scripted for ' . $method . ' ' . $url );
	}
	return $next;
}
function wp_safe_remote_post( $url, $args ) {
	return aicfab_http( 'POST', $url, $args );
}
function wp_safe_remote_request( $url, $args ) {
	return aicfab_http( isset( $args['method'] ) ? $args['method'] : 'GET', $url, $args );
}
function wp_remote_retrieve_body( $response ) {
	return isset( $response['body'] ) ? $response['body'] : '';
}
function wp_remote_retrieve_response_code( $response ) {
	return isset( $response['code'] ) ? $response['code'] : 0;
}
function wp_remote_retrieve_header( $response, $name ) {
	return isset( $response['headers'][ $name ] ) ? $response['headers'][ $name ] : '';
}
function google( $code, $body = array(), $headers = array() ) {
	return array( 'code' => $code, 'body' => is_string( $body ) ? $body : json_encode( $body ), 'headers' => $headers );
}

class AI_Chat_Bedrock_Distribution {
	public static function enabled() {
		return ! empty( $GLOBALS['aicfab_options']['ai_chat_bedrock_settings']['distribution_enabled'] );
	}
	public static function record( $post_id, $input, $source ) {
		$GLOBALS['aicfab_records'][] = array( $post_id, $input, $source );
		return $input;
	}
}

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-security.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-youtube.php';

$failures = array();
function check_yt( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}
function redirect_of( $callable ) {
	$GLOBALS['aicfab_redirect'] = '';
	try {
		$callable();
	} catch ( AICFAB_Redirect $redirect ) {
		return $redirect->getMessage();
	}
	return '';
}
function last_request() {
	return end( $GLOBALS['aicfab_requests'] );
}

$GLOBALS['aicfab_options']['ai_chat_bedrock_settings'] = array(
	'distribution_enabled'  => true,
	'youtube_client_id'     => '123456-abcdef.apps.googleusercontent.com',
	'youtube_client_secret' => AI_Chat_Bedrock_Security::encrypt_secret( 'GOCSPX-test-secret' ),
);

// --- Settings ---------------------------------------------------------------------------------------

check_yt( '' === AI_Chat_Bedrock_YouTube::clean_client_id( 'abc' ) && '1-a.apps.googleusercontent.com' === AI_Chat_Bedrock_YouTube::clean_client_id( ' 1-a.apps.googleusercontent.com ' ), 'A client ID is a Google OAuth client ID.' );
check_yt( 5 === AI_Chat_Bedrock_YouTube::daily_limit( array() ) && 6 === AI_Chat_Bedrock_YouTube::daily_limit( array( 'youtube_daily_uploads' => 50 ) ) && 1 === AI_Chat_Bedrock_YouTube::daily_limit( array( 'youtube_daily_uploads' => '0' ) ), 'Uploads a day default to 5, at most the 6 a day\'s quota allows.' );
check_yt( 'GOCSPX-test-secret' === AI_Chat_Bedrock_YouTube::client()['secret'], 'The client secret is stored encrypted and read back.' );
check_yt( null === AI_Chat_Bedrock_YouTube::channel() && ! AI_Chat_Bedrock_YouTube::ready(), 'Nothing is ready before a channel is connected.' );

// --- Connecting -----------------------------------------------------------------------------------------

$url = redirect_of( function () { AI_Chat_Bedrock_YouTube::handle_connect(); } );
parse_str( (string) parse_url( $url, PHP_URL_QUERY ), $query );
$saved = $GLOBALS['aicfab_transients']['aicfab_youtube_state_3'];
check_yt( 0 === strpos( $url, 'https://accounts.google.com/o/oauth2/v2/auth?' ), 'Connecting goes to Google\'s sign-in.' );
check_yt( 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly' === $query['scope'] && 'offline' === $query['access_type'] && 'consent' === $query['prompt'], 'It asks only to upload and read the channel, offline, so a refresh token comes back.' );
check_yt( 'S256' === $query['code_challenge_method'] && rtrim( strtr( base64_encode( hash( 'sha256', $saved[1], true ) ), '+/', '-_' ), '=' ) === $query['code_challenge'], 'It uses PKCE.' );
check_yt( $saved[0] === $query['state'] && 'https://example.test/wp-admin/admin-post.php?action=ai_chat_bedrock_youtube_callback' === $query['redirect_uri'], 'It binds a state to this user and names the redirect URI.' );

$_GET = array( 'state' => 'forged', 'code' => 'c' );
check_yt( false !== strpos( redirect_of( function () { AI_Chat_Bedrock_YouTube::handle_callback(); } ), 'youtube_state' ) && array() === $GLOBALS['aicfab_requests'], 'A sign-in with another state is ignored, before Google is asked anything.' );

set_transient( 'aicfab_youtube_state_3', $saved, 600 );
$_GET = array( 'state' => $saved[0], 'code' => 'auth-code' );
$GLOBALS['aicfab_responses'] = array(
	google( 200, array( 'access_token' => 'at-1', 'refresh_token' => 'rt-1', 'expires_in' => 3599 ) ),
	google( 200, array( 'items' => array( array( 'id' => 'UCv33IdkS5-8Kohix3DnLgVw', 'snippet' => array( 'title' => 'Physical AI Lab' ) ) ) ) ),
);
$back = redirect_of( function () { AI_Chat_Bedrock_YouTube::handle_callback(); } );
$exchange = $GLOBALS['aicfab_requests'][0];
check_yt( false !== strpos( $back, 'youtube_connected' ), 'Coming back from Google connects the channel.' );
check_yt( 'https://oauth2.googleapis.com/token' === $exchange['url'] && 'authorization_code' === $exchange['args']['body']['grant_type'] && $saved[1] === $exchange['args']['body']['code_verifier'], 'The code is exchanged with the PKCE verifier.' );
check_yt( array( 'id' => 'UCv33IdkS5-8Kohix3DnLgVw', 'title' => 'Physical AI Lab' ) === AI_Chat_Bedrock_YouTube::channel() && AI_Chat_Bedrock_YouTube::ready(), 'The channel is remembered.' );
check_yt( false === strpos( json_encode( $GLOBALS['aicfab_options'] ), 'rt-1' ) && false === strpos( json_encode( $GLOBALS['aicfab_transients'] ), 'at-1' ), 'Neither token is stored in the clear.' );
check_yt( 'Bearer at-1' === $GLOBALS['aicfab_requests'][1]['args']['headers']['Authorization'], 'The channel is read with the new token.' );

// The access token is refreshed when it runs out.
$GLOBALS['aicfab_requests'] = array();
unset( $GLOBALS['aicfab_transients']['aicfab_youtube_access'] );
$GLOBALS['aicfab_responses'] = array( google( 200, array( 'access_token' => 'at-2', 'expires_in' => 3599 ) ) );
check_yt( 'at-2' === AI_Chat_Bedrock_YouTube::access_token() && 'refresh_token' === $GLOBALS['aicfab_requests'][0]['args']['body']['grant_type'] && 'rt-1' === $GLOBALS['aicfab_requests'][0]['args']['body']['refresh_token'], 'An expired access token is refreshed.' );
check_yt( 'at-2' === AI_Chat_Bedrock_YouTube::access_token() && 1 === count( $GLOBALS['aicfab_requests'] ), 'And cached.' );

// --- Queueing -------------------------------------------------------------------------------------------

$file = tempnam( sys_get_temp_dir(), 'aicfab-video' );
file_put_contents( $file, str_repeat( 'v', AI_Chat_Bedrock_YouTube::CHUNK * 2 + 1000 ) );
$GLOBALS['aicfab_files'] = array( 41 => $file, 42 => __FILE__ );
$GLOBALS['aicfab_mimes'] = array( 41 => 'video/mp4', 42 => 'text/x-php' );
$input                   = array( 'attachment' => 41, 'title' => '', 'description' => "Watch it <script>\nhttps://example.test/lesson/", 'tags' => 'robots, kinematics, ', 'privacy' => 'public', 'made_for_kids' => 'no', 'synthetic' => true, 'language' => 'zh' );

check_yt( 'aicfab_youtube_file' === AI_Chat_Bedrock_YouTube::queue( 7, array( 'attachment' => 42 ) + $input )->get_error_code(), 'Only a video can be uploaded.' );
check_yt( 'aicfab_youtube_audience' === AI_Chat_Bedrock_YouTube::queue( 7, array( 'made_for_kids' => '' ) + $input )->get_error_code(), 'The audience must be declared.' );
check_yt( 'aicfab_youtube_forbidden' === AI_Chat_Bedrock_YouTube::queue( 8, $input )->get_error_code(), 'A post the user cannot edit is refused.' );
$job = AI_Chat_Bedrock_YouTube::queue( 7, $input );
check_yt( is_array( $job ) && 'queued' === $job['state'] && filesize( $file ) === $job['size'], 'A video is queued.' );
check_yt( 'Lesson 1.1 Kinematics' === $job['resource']['snippet']['title'] && false === strpos( $job['resource']['snippet']['description'], '<' ) && array( 'robots', 'kinematics' ) === $job['resource']['snippet']['tags'], 'The title comes from the post, and nothing YouTube refuses, such as angle brackets, is sent.' );
check_yt( 'public' === $job['resource']['status']['privacyStatus'] && false === $job['resource']['status']['selfDeclaredMadeForKids'] && true === $job['resource']['status']['containsSyntheticMedia'] && 'zh' === $job['resource']['snippet']['defaultAudioLanguage'], 'Visibility, audience, the synthetic media disclosure and language are sent.' );
check_yt( wp_next_scheduled( AI_Chat_Bedrock_YouTube::CRON, array( 7 ) ), 'The upload runs in the background.' );
check_yt( 'aicfab_youtube_busy' === AI_Chat_Bedrock_YouTube::queue( 7, $input )->get_error_code(), 'A second upload for the same post waits for the first.' );

// --- Uploading --------------------------------------------------------------------------------------------

$GLOBALS['aicfab_requests']  = array();
$session                     = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status&upload_id=xyz';
$size                        = filesize( $file );
$chunk                       = AI_Chat_Bedrock_YouTube::CHUNK;
$GLOBALS['aicfab_responses'] = array(
	google( 200, '', array( 'location' => $session ) ),
	google( 308, '', array( 'range' => 'bytes=0-' . ( $chunk - 1 ) ) ),
	google( 503, array( 'error' => array( 'message' => 'Backend Error' ) ) ),
);
AI_Chat_Bedrock_YouTube::run( 7 );
$job   = AI_Chat_Bedrock_YouTube::job( 7 );
$start = $GLOBALS['aicfab_requests'][0];
check_yt( 0 === strpos( $start['url'], 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status' ) && (string) $size === $start['args']['headers']['X-Upload-Content-Length'] && 'video/mp4' === $start['args']['headers']['X-Upload-Content-Type'] && 0 === $start['args']['redirection'], 'A resumable session is opened with the size and type, and no redirect is followed.' );
check_yt( 'bytes 0-' . ( $chunk - 1 ) . '/' . $size === $GLOBALS['aicfab_requests'][1]['args']['headers']['Content-Range'] && $chunk === strlen( $GLOBALS['aicfab_requests'][1]['args']['body'] ), 'The first chunk goes with its range.' );
check_yt( 'uploading' === $job['state'] && $chunk === $job['offset'] && 1 === $job['retries'] && ! empty( $job['interrupted'] ), 'A server error after the first chunk keeps what arrived and retries later.' );
$next = wp_next_scheduled( AI_Chat_Bedrock_YouTube::CRON, array( 7 ) );
check_yt( $next >= time() + 25 && 1 === count( array_filter( $GLOBALS['aicfab_cron'], function ( $event ) { return AI_Chat_Bedrock_YouTube::CRON === $event[1]; } ) ), 'The retry waits, and only one run is due.' );

// The next run asks what arrived before sending more, and finishes.
$GLOBALS['aicfab_requests']  = array();
$GLOBALS['aicfab_responses'] = array(
	google( 308, '', array( 'range' => 'bytes=0-' . ( $chunk - 1 ) ) ),
	google( 308, '', array( 'range' => 'bytes=0-' . ( 2 * $chunk - 1 ) ) ),
	google( 200, array( 'id' => 'aBcDeFgHiJ0', 'status' => array( 'uploadStatus' => 'uploaded' ) ) ),
);
AI_Chat_Bedrock_YouTube::run( 7 );
$job = AI_Chat_Bedrock_YouTube::job( 7 );
check_yt( 'bytes */' . $size === $GLOBALS['aicfab_requests'][0]['args']['headers']['Content-Range'] && '' === $GLOBALS['aicfab_requests'][0]['args']['body'], 'After an error, YouTube is asked how much arrived.' );
check_yt( 'bytes ' . $chunk . '-' . ( 2 * $chunk - 1 ) . '/' . $size === $GLOBALS['aicfab_requests'][1]['args']['headers']['Content-Range'] && 'bytes ' . ( 2 * $chunk ) . '-' . ( $size - 1 ) . '/' . $size === $GLOBALS['aicfab_requests'][2]['args']['headers']['Content-Range'], 'The upload continues from there, to the last byte.' );
check_yt( 'uploaded' === $job['state'] && 'aBcDeFgHiJ0' === $job['video_id'], 'The finished upload names the video.' );
$record = end( $GLOBALS['aicfab_records'] );
check_yt( 7 === $record[0] && 'youtube' === $record[1]['platform'] && 'aBcDeFgHiJ0' === $record[1]['item_id'] && 'processing' === $record[1]['status'] && 'Physical AI Lab (UCv33IdkS5-8Kohix3DnLgVw)' === $record[1]['account'] && 'youtube' === $record[2], 'It is added to the post\'s publishing record, as processing.' );
check_yt( wp_next_scheduled( AI_Chat_Bedrock_YouTube::POLL, array( 7 ) ) && ! wp_next_scheduled( AI_Chat_Bedrock_YouTube::CRON, array( 7 ) ), 'Its processing is followed, and no more upload runs are due.' );

// Processing ends public, or private when YouTube keeps an unaudited project's upload private.
$GLOBALS['aicfab_responses'] = array( google( 200, array( 'items' => array( array( 'status' => array( 'uploadStatus' => 'uploaded' ) ) ) ) ) );
AI_Chat_Bedrock_YouTube::poll( 7 );
$GLOBALS['aicfab_cron'] = array_values( array_filter( $GLOBALS['aicfab_cron'], function ( $event ) { return AI_Chat_Bedrock_YouTube::POLL !== $event[1]; } ) );
$GLOBALS['aicfab_responses'] = array( google( 200, array( 'items' => array( array( 'status' => array( 'uploadStatus' => 'uploaded' ) ) ) ) ) );
AI_Chat_Bedrock_YouTube::poll( 7 );
check_yt( 'uploaded' === AI_Chat_Bedrock_YouTube::job( 7 )['state'] && wp_next_scheduled( AI_Chat_Bedrock_YouTube::POLL, array( 7 ) ), 'While YouTube is still processing, it is asked again later.' );
$GLOBALS['aicfab_responses'] = array( google( 200, array( 'items' => array( array( 'status' => array( 'uploadStatus' => 'processed', 'privacyStatus' => 'private' ) ) ) ) ) );
AI_Chat_Bedrock_YouTube::poll( 7 );
$record = end( $GLOBALS['aicfab_records'] );
check_yt( 'done' === AI_Chat_Bedrock_YouTube::job( 7 )['state'] && 'private' === $record[1]['status'] && false !== strpos( $record[1]['note'], 'audit' ), 'Asked public but kept private, the record says why.' );

// --- Failures ---------------------------------------------------------------------------------------------

$GLOBALS['aicfab_meta'][7] = array();
$job                       = AI_Chat_Bedrock_YouTube::queue( 7, $input );
$GLOBALS['aicfab_responses'] = array( google( 403, array( 'error' => array( 'message' => 'The request cannot be completed because you have exceeded your quota.', 'errors' => array( array( 'reason' => 'quotaExceeded' ) ) ) ) ) );
AI_Chat_Bedrock_YouTube::run( 7 );
$job = AI_Chat_Bedrock_YouTube::job( 7 );
check_yt( 'failed' === $job['state'] && false !== strpos( $job['error'], 'quota' ) && ! wp_next_scheduled( AI_Chat_Bedrock_YouTube::CRON, array( 7 ) ), 'A used-up quota fails the upload with an explanation, without retrying.' );

$GLOBALS['aicfab_meta'][7]   = array();
$job                         = AI_Chat_Bedrock_YouTube::queue( 7, array( 'title' => 'Speed < 2 m/s > 1 m/s' ) + $input );
check_yt( 'Speed  2 m/s  1 m/s' === $job['resource']['snippet']['title'], 'Angle brackets, which YouTube refuses in a title, are taken out: ' . $job['resource']['snippet']['title'] );
$GLOBALS['aicfab_requests']  = array();
$GLOBALS['aicfab_responses'] = array( google( 200, '', array( 'location' => 'https://evil.example/upload' ) ) );
AI_Chat_Bedrock_YouTube::run( 7 );
check_yt( 1 === count( $GLOBALS['aicfab_requests'] ) && '' === AI_Chat_Bedrock_YouTube::job( 7 )['session'], 'A session address that is not YouTube\'s upload address is never sent the video.' );
$GLOBALS['aicfab_meta'][7]   = array();
$job                         = AI_Chat_Bedrock_YouTube::queue( 7, $input );
$GLOBALS['aicfab_responses'] = array(
	google( 200, '', array( 'location' => $session ) ),
	google( 404, '' ),
);
AI_Chat_Bedrock_YouTube::run( 7 );
$job = AI_Chat_Bedrock_YouTube::job( 7 );
check_yt( '' === $job['session'] && 0 === $job['offset'] && 'queued' === $job['state'], 'An expired session starts the upload again from the first byte.' );

$GLOBALS['aicfab_meta'][7]   = array();
$GLOBALS['aicfab_options']['aicfab_youtube_uploads'] = array( 'day' => gmdate( 'Ymd' ), 'n' => 5 );
check_yt( 'aicfab_youtube_daily' === AI_Chat_Bedrock_YouTube::queue( 7, $input )->get_error_code(), 'Past the daily limit nothing is queued.' );
$GLOBALS['aicfab_options']['aicfab_youtube_uploads'] = array( 'day' => '20000101', 'n' => 5 );
check_yt( is_array( AI_Chat_Bedrock_YouTube::queue( 7, $input ) ), 'The limit starts again each day.' );

// A changed file is not uploaded halfway.
file_put_contents( $file, 'shorter' );
AI_Chat_Bedrock_YouTube::run( 7 );
check_yt( 'failed' === AI_Chat_Bedrock_YouTube::job( 7 )['state'], 'A file that changed after queueing is not uploaded.' );

// A run already under way is not doubled.
$GLOBALS['aicfab_meta'][7] = array();
file_put_contents( $file, str_repeat( 'v', 1000 ) );
AI_Chat_Bedrock_YouTube::queue( 7, $input );
set_transient( 'aicfab_youtube_lock_7', 1, 60 );
$GLOBALS['aicfab_requests'] = array();
AI_Chat_Bedrock_YouTube::run( 7 );
check_yt( array() === $GLOBALS['aicfab_requests'], 'A second run while one is under way does nothing.' );
delete_transient( 'aicfab_youtube_lock_7' );

// Refresh refused: the channel must be connected again.
unset( $GLOBALS['aicfab_transients']['aicfab_youtube_access'] );
$GLOBALS['aicfab_responses'] = array( google( 400, array( 'error' => 'invalid_grant' ) ) );
AI_Chat_Bedrock_YouTube::run( 7 );
check_yt( 'failed' === AI_Chat_Bedrock_YouTube::job( 7 )['state'] && null === AI_Chat_Bedrock_YouTube::channel(), 'Access withdrawn at Google disconnects the channel and fails the upload.' );

// --- Disconnecting ---------------------------------------------------------------------------------------

$GLOBALS['aicfab_options']['ai_chat_bedrock_youtube'] = array( 'refresh_token' => AI_Chat_Bedrock_Security::encrypt_secret( 'rt-9' ), 'channel_id' => 'UC1', 'channel_title' => 'x' );
$GLOBALS['aicfab_requests']  = array();
$GLOBALS['aicfab_responses'] = array( google( 200, '' ) );
redirect_of( function () { AI_Chat_Bedrock_YouTube::handle_disconnect(); } );
check_yt( 'https://oauth2.googleapis.com/revoke' === $GLOBALS['aicfab_requests'][0]['url'] && 'rt-9' === $GLOBALS['aicfab_requests'][0]['args']['body']['token'] && null === AI_Chat_Bedrock_YouTube::channel(), 'Disconnecting revokes the token at Google and forgets the channel.' );
$GLOBALS['aicfab_can'] = array();
check_yt( 'die:403' === redirect_of( function () { AI_Chat_Bedrock_YouTube::handle_connect(); } ), 'Only an administrator can connect a channel.' );

// --- Wiring ---------------------------------------------------------------------------------------------

$bootstrap = (string) file_get_contents( dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock.php' );
foreach ( array( 'admin_post_ai_chat_bedrock_youtube_connect', 'admin_post_ai_chat_bedrock_youtube_callback', 'admin_post_ai_chat_bedrock_youtube_disconnect', 'admin_post_ai_chat_bedrock_youtube_upload', "'ai_chat_bedrock_youtube_upload', 'AI_Chat_Bedrock_YouTube', 'run'", "'ai_chat_bedrock_youtube_status', 'AI_Chat_Bedrock_YouTube', 'poll'" ) as $hook ) {
	check_yt( false !== strpos( $bootstrap, $hook ), $hook . ' is hooked.' );
}
$uninstall = (string) file_get_contents( dirname( __DIR__ ) . '/uninstall.php' );
check_yt( false !== strpos( $uninstall, "'ai_chat_bedrock_youtube'" ) && false !== strpos( $uninstall, "'_aicfab_youtube_upload'" ) && false !== strpos( $uninstall, "'_aicfab_distribution'" ), 'Uninstalling removes the connection, the jobs and the record.' );

unlink( $file );
if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: YouTube upload checks passed\n";

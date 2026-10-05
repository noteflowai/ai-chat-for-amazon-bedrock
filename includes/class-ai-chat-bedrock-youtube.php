<?php
/**
 * Upload videos from the Media Library to the site's YouTube channel.
 *
 * Through the YouTube Data API, with the channel owner's consent given once in Google's own
 * sign-in: the site keeps a refresh token, encrypted, and nothing else of the account. Each
 * upload runs in the background with YouTube's resumable protocol, a few megabytes at a time,
 * so a large video survives PHP's time limits, a restart and a dropped connection. When it is
 * done the video is added to the post's publishing record, and its processing is followed until
 * YouTube says whether it is public, unlisted or private.
 *
 * YouTube keeps a video uploaded from an API project that Google has not audited private, and
 * each upload costs 1,600 of the 10,000 quota units a project gets a day, which is why uploads
 * are limited per day and default to private.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_YouTube {

	const AUTH_URL   = 'https://accounts.google.com/o/oauth2/v2/auth';
	const TOKEN_URL  = 'https://oauth2.googleapis.com/token';
	const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';
	const API_URL    = 'https://www.googleapis.com/youtube/v3';
	const UPLOAD_URL = 'https://www.googleapis.com/upload/youtube/v3/videos';

	// Uploading needs youtube.upload; reading the channel and the video's processing needs readonly.
	const SCOPES = 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly';

	// The connection, kept apart from the settings so it is never exported with them.
	const OPTION = 'ai_chat_bedrock_youtube';

	const JOB_META = '_aicfab_youtube_upload';
	const CRON     = 'ai_chat_bedrock_youtube_upload';
	const POLL     = 'ai_chat_bedrock_youtube_status';

	// A multiple of 256 KB, as the resumable protocol requires of every chunk but the last.
	const CHUNK = 8388608;

	// Seconds one background run uploads for before it hands over to the next.
	const BUDGET = 20;

	const MAX_RETRIES    = 8;
	const MAX_POLLS      = 30;
	const DEFAULT_DAILY  = 5;
	const MAX_DAILY      = 6;
	const MAX_TITLE      = 100;
	const MAX_DESC_BYTES = 5000;

	const PRIVACY = array( 'private', 'unlisted', 'public' );

	/**
	 * The OAuth client the site owner created in Google Cloud.
	 *
	 * @param array|null $options Settings.
	 * @return array Client ID and secret, either empty when not set.
	 */
	public static function client( $options = null ) {
		$options = self::options( $options );
		$id      = isset( $options['youtube_client_id'] ) ? self::clean_client_id( $options['youtube_client_id'] ) : '';
		$secret  = isset( $options['youtube_client_secret'] ) && '' !== $options['youtube_client_secret'] ? trim( (string) AI_Chat_Bedrock_Security::decrypt_secret( $options['youtube_client_secret'] ) ) : '';
		return array(
			'id'     => $id,
			'secret' => $secret,
		);
	}

	/**
	 * A Google OAuth client ID, or an empty string.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function clean_client_id( $value ) {
		$value = trim( (string) $value );
		return preg_match( '/^[0-9]+-[a-z0-9]+\.apps\.googleusercontent\.com$/', $value ) ? $value : '';
	}

	/**
	 * Uploads allowed a day.
	 *
	 * @param array|null $options Settings.
	 * @return int
	 */
	public static function daily_limit( $options = null ) {
		$options = self::options( $options );
		$limit   = isset( $options['youtube_daily_uploads'] ) && '' !== trim( (string) $options['youtube_daily_uploads'] ) ? absint( $options['youtube_daily_uploads'] ) : self::DEFAULT_DAILY;
		return max( 1, min( self::MAX_DAILY, $limit ) );
	}

	/**
	 * The connected channel, or null.
	 *
	 * @return array|null Channel ID and title.
	 */
	public static function channel() {
		$state = get_option( self::OPTION, array() );
		return is_array( $state ) && ! empty( $state['refresh_token'] ) && ! empty( $state['channel_id'] ) ? array(
			'id'    => (string) $state['channel_id'],
			'title' => isset( $state['channel_title'] ) ? (string) $state['channel_title'] : '',
		) : null;
	}

	/**
	 * Whether uploads can be made: the record is on and a channel is connected.
	 *
	 * @return bool
	 */
	public static function ready() {
		return class_exists( 'AI_Chat_Bedrock_Distribution' ) && AI_Chat_Bedrock_Distribution::enabled() && null !== self::channel();
	}

	/**
	 * Where Google sends the owner back, to be entered as an authorized redirect URI.
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return admin_url( 'admin-post.php?action=ai_chat_bedrock_youtube_callback' );
	}

	/*
	 * ------------------------------------------------------------------
	 * Connecting a channel
	 * ------------------------------------------------------------------
	 */

	/**
	 * Send the administrator to Google to allow uploads to their channel.
	 */
	public static function handle_connect() {
		self::require_admin( 'ai_chat_bedrock_youtube_connect' );
		$client = self::client();
		if ( '' === $client['id'] || '' === $client['secret'] ) {
			self::back( 'youtube_client' );
		}
		$state    = wp_generate_password( 32, false );
		$verifier = wp_generate_password( 64, false );
		set_transient( self::state_key(), array( $state, $verifier ), 10 * MINUTE_IN_SECONDS );
		$url = add_query_arg(
			array(
				'client_id'              => rawurlencode( $client['id'] ),
				'redirect_uri'           => rawurlencode( self::redirect_uri() ),
				'response_type'          => 'code',
				'scope'                  => rawurlencode( self::SCOPES ),
				'access_type'            => 'offline',
				'include_granted_scopes' => 'true',
				'prompt'                 => 'consent',
				'state'                  => $state,
				'code_challenge'         => rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- PKCE code challenge.
				'code_challenge_method'  => 'S256',
			),
			self::AUTH_URL
		);
		wp_redirect( $url ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Google's sign-in is on another host by design.
		exit;
	}

	/**
	 * Finish connecting when Google sends the administrator back.
	 */
	public static function handle_callback() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'ai-chat-for-amazon-bedrock' ), 403 );
		}
		$saved = get_transient( self::state_key() );
		delete_transient( self::state_key() );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the OAuth state, bound to this user, stands in for a nonce on a request Google makes the browser send.
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:enable
		if ( ! is_array( $saved ) || ! hash_equals( (string) $saved[0], $state ) ) {
			self::back( 'youtube_state' );
		}
		if ( '' !== $error || '' === $code ) {
			self::back( 'youtube_denied' );
		}
		$client = self::client();
		$tokens = self::token_request(
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'client_id'     => $client['id'],
				'client_secret' => $client['secret'],
				'redirect_uri'  => self::redirect_uri(),
				'code_verifier' => (string) $saved[1],
			)
		);
		if ( is_wp_error( $tokens ) || empty( $tokens['refresh_token'] ) ) {
			self::back( 'youtube_token' );
		}
		self::cache_access_token( $tokens );
		$channel = self::api(
			'GET',
			'/channels',
			array(
				'part' => 'snippet',
				'mine' => 'true',
			),
			null,
			$tokens['access_token']
		);
		$item    = ! is_wp_error( $channel ) && ! empty( $channel['items'][0] ) ? $channel['items'][0] : null;
		if ( null === $item ) {
			self::back( 'youtube_channel' );
		}
		update_option(
			self::OPTION,
			array(
				'refresh_token' => AI_Chat_Bedrock_Security::encrypt_secret( (string) $tokens['refresh_token'] ),
				'channel_id'    => sanitize_text_field( (string) $item['id'] ),
				'channel_title' => isset( $item['snippet']['title'] ) ? sanitize_text_field( (string) $item['snippet']['title'] ) : '',
				'connected_at'  => time(),
				'connected_by'  => get_current_user_id(),
			),
			false
		);
		self::back( 'youtube_connected' );
	}

	/**
	 * Disconnect the channel, revoking the site's access at Google.
	 */
	public static function handle_disconnect() {
		self::require_admin( 'ai_chat_bedrock_youtube_disconnect' );
		$state = get_option( self::OPTION, array() );
		if ( is_array( $state ) && ! empty( $state['refresh_token'] ) ) {
			wp_safe_remote_post(
				self::REVOKE_URL,
				array(
					'timeout' => 15,
					'body'    => array( 'token' => AI_Chat_Bedrock_Security::decrypt_secret( $state['refresh_token'] ) ),
				)
			);
		}
		delete_option( self::OPTION );
		delete_transient( 'aicfab_youtube_access' );
		self::back( 'youtube_disconnected' );
	}

	/**
	 * An access token, from the cache or by refreshing.
	 *
	 * @return string|WP_Error
	 */
	public static function access_token() {
		$cached = get_transient( 'aicfab_youtube_access' );
		if ( is_string( $cached ) && '' !== $cached ) {
			return AI_Chat_Bedrock_Security::decrypt_secret( $cached );
		}
		$state  = get_option( self::OPTION, array() );
		$client = self::client();
		if ( ! is_array( $state ) || empty( $state['refresh_token'] ) || '' === $client['id'] ) {
			return new WP_Error( 'aicfab_youtube_disconnected', __( 'No YouTube channel is connected.', 'ai-chat-for-amazon-bedrock' ) );
		}
		$tokens = self::token_request(
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => AI_Chat_Bedrock_Security::decrypt_secret( $state['refresh_token'] ),
				'client_id'     => $client['id'],
				'client_secret' => $client['secret'],
			)
		);
		if ( is_wp_error( $tokens ) ) {
			// Access withdrawn at Google, or the client changed: the channel has to be connected again.
			if ( 'invalid_grant' === $tokens->get_error_code() ) {
				delete_option( self::OPTION );
			}
			return $tokens;
		}
		self::cache_access_token( $tokens );
		return (string) $tokens['access_token'];
	}

	private static function cache_access_token( $tokens ) {
		$ttl = isset( $tokens['expires_in'] ) ? (int) $tokens['expires_in'] - 120 : 3000;
		set_transient( 'aicfab_youtube_access', AI_Chat_Bedrock_Security::encrypt_secret( (string) $tokens['access_token'] ), max( 60, $ttl ) );
	}

	/**
	 * Call Google's token endpoint.
	 *
	 * @param array $body Form fields.
	 * @return array|WP_Error Decoded response.
	 */
	private static function token_request( $body ) {
		$response = wp_safe_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => 20,
				'body'    => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code || ! is_array( $data ) || empty( $data['access_token'] ) ) {
			$error = is_array( $data ) && ! empty( $data['error'] ) ? sanitize_key( (string) $data['error'] ) : 'aicfab_youtube_token';
			return new WP_Error( $error, __( 'Google did not grant access to the channel.', 'ai-chat-for-amazon-bedrock' ) );
		}
		return $data;
	}

	/**
	 * Call the YouTube Data API.
	 *
	 * @param string      $method HTTP method.
	 * @param string      $path   Path after /youtube/v3.
	 * @param array       $query  Query arguments.
	 * @param array|null  $body   JSON body.
	 * @param string|null $token  Access token; a fresh one when omitted.
	 * @return array|WP_Error
	 */
	public static function api( $method, $path, $query = array(), $body = null, $token = null ) {
		$token = null === $token ? self::access_token() : $token;
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$args = array(
			'method'  => $method,
			'timeout' => 20,
			'headers' => array( 'Authorization' => 'Bearer ' . $token ),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json; charset=UTF-8';
			$args['body']                    = wp_json_encode( $body );
		}
		$response = wp_safe_remote_request( add_query_arg( array_map( 'rawurlencode', $query ), self::API_URL . $path ), $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return self::api_error( $code, $data );
		}
		return is_array( $data ) ? $data : array();
	}

	/**
	 * A YouTube error as a WP_Error named after its reason, such as quotaExceeded.
	 *
	 * @param int        $code HTTP status.
	 * @param array|null $data Decoded body.
	 * @return WP_Error
	 */
	private static function api_error( $code, $data ) {
		$reason  = is_array( $data ) && ! empty( $data['error']['errors'][0]['reason'] ) ? (string) $data['error']['errors'][0]['reason'] : 'http_' . (int) $code;
		$message = is_array( $data ) && ! empty( $data['error']['message'] ) ? wp_strip_all_tags( (string) $data['error']['message'] ) : '';
		return new WP_Error( 'aicfab_youtube_' . sanitize_key( $reason ), $message, array( 'status' => (int) $code ) );
	}

	/*
	 * ------------------------------------------------------------------
	 * Uploading
	 * ------------------------------------------------------------------
	 */

	/**
	 * Queue a video for upload.
	 *
	 * @param int   $post_id Post the video belongs to.
	 * @param array $input   Attachment, title, description, tags, privacy, made_for_kids,
	 *                       synthetic and language.
	 * @return array|WP_Error The job.
	 */
	public static function queue( $post_id, $input ) {
		$post = get_post( absint( $post_id ) );
		if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post->ID ) || ! current_user_can( 'upload_files' ) ) {
			return new WP_Error( 'aicfab_youtube_forbidden', __( 'You cannot upload a video for this post.', 'ai-chat-for-amazon-bedrock' ) );
		}
		if ( ! self::ready() ) {
			return new WP_Error( 'aicfab_youtube_disconnected', __( 'Connect a YouTube channel under Settings > Publishing first.', 'ai-chat-for-amazon-bedrock' ) );
		}
		$job = self::job( $post->ID );
		if ( is_array( $job ) && in_array( $job['state'], array( 'queued', 'uploading' ), true ) ) {
			return new WP_Error( 'aicfab_youtube_busy', __( 'A video for this post is already being uploaded.', 'ai-chat-for-amazon-bedrock' ) );
		}
		if ( self::uploads_today() >= self::daily_limit() ) {
			return new WP_Error( 'aicfab_youtube_daily', __( 'Today\'s uploads are used up. Each one costs 1,600 of the 10,000 quota units Google gives a project a day.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$attachment = isset( $input['attachment'] ) ? absint( $input['attachment'] ) : 0;
		$file       = $attachment ? get_attached_file( $attachment ) : '';
		$mime       = $attachment ? (string) get_post_mime_type( $attachment ) : '';
		if ( '' === (string) $file || 0 !== strpos( $mime, 'video/' ) || ! is_readable( $file ) ) {
			return new WP_Error( 'aicfab_youtube_file', __( 'Choose a video in the Media Library whose file is on this server.', 'ai-chat-for-amazon-bedrock' ) );
		}
		$privacy = isset( $input['privacy'] ) && in_array( $input['privacy'], self::PRIVACY, true ) ? $input['privacy'] : 'private';
		if ( ! isset( $input['made_for_kids'] ) || ! in_array( (string) $input['made_for_kids'], array( 'yes', 'no' ), true ) ) {
			// YouTube requires the audience to be declared, and it is the uploader's to declare.
			return new WP_Error( 'aicfab_youtube_audience', __( 'Say whether the video is made for children.', 'ai-chat-for-amazon-bedrock' ) );
		}

		$title = isset( $input['title'] ) && '' !== trim( (string) $input['title'] ) ? (string) $input['title'] : get_the_title( $post );
		$title = trim( str_replace( array( '<', '>' ), '', wp_strip_all_tags( html_entity_decode( $title, ENT_QUOTES, 'UTF-8' ) ) ) );
		$desc  = isset( $input['description'] ) ? (string) $input['description'] : '';
		$desc  = str_replace( array( '<', '>' ), '', sanitize_textarea_field( $desc ) );
		$tags  = array();
		foreach ( explode( ',', isset( $input['tags'] ) ? (string) $input['tags'] : '' ) as $tag ) {
			$tag = trim( sanitize_text_field( $tag ) );
			if ( '' !== $tag ) {
				$tags[] = AI_Chat_Bedrock_Security::string_substr( $tag, 0, 30 );
			}
		}

		$job = array(
			'state'      => 'queued',
			'attachment' => $attachment,
			'size'       => (int) filesize( $file ),
			'mime'       => $mime,
			'offset'     => 0,
			'session'    => '',
			'retries'    => 0,
			'polls'      => 0,
			'video_id'   => '',
			'error'      => '',
			'queued_at'  => time(),
			'user'       => get_current_user_id(),
			'resource'   => array(
				'snippet' => array(
					'title'       => '' !== $title ? AI_Chat_Bedrock_Security::string_substr( $title, 0, self::MAX_TITLE ) : __( 'Untitled video', 'ai-chat-for-amazon-bedrock' ),
					'description' => AI_Chat_Bedrock_Security::truncate_bytes( $desc, self::MAX_DESC_BYTES ),
					'tags'        => array_slice( $tags, 0, 30 ),
				),
				'status'  => array(
					'privacyStatus'           => $privacy,
					'selfDeclaredMadeForKids' => 'yes' === $input['made_for_kids'],
					// Realistic altered or synthetic content, which YouTube asks creators to disclose.
					'containsSyntheticMedia'  => ! empty( $input['synthetic'] ),
					'embeddable'              => true,
				),
			),
			'language'   => isset( $input['language'] ) ? sanitize_key( (string) $input['language'] ) : '',
		);
		if ( '' !== $job['language'] ) {
			$job['resource']['snippet']['defaultLanguage']      = $job['language'];
			$job['resource']['snippet']['defaultAudioLanguage'] = $job['language'];
		}
		update_post_meta( $post->ID, self::JOB_META, $job );
		self::count_upload();
		self::schedule( self::CRON, $post->ID, 0 );
		return $job;
	}

	/**
	 * The upload job of a post, or null.
	 *
	 * @param int $post_id Post ID.
	 * @return array|null
	 */
	public static function job( $post_id ) {
		$job = get_post_meta( absint( $post_id ), self::JOB_META, true );
		return is_array( $job ) && isset( $job['state'] ) ? $job : null;
	}

	/**
	 * Upload what can be uploaded within one background run.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function run( $post_id ) {
		$post_id = absint( $post_id );
		$job     = self::job( $post_id );
		if ( null === $job || ! in_array( $job['state'], array( 'queued', 'uploading' ), true ) ) {
			return;
		}
		// One run at a time per post: cron can start a second while the first is still uploading.
		$lock = 'aicfab_youtube_lock_' . $post_id;
		if ( false !== get_transient( $lock ) ) {
			return;
		}
		set_transient( $lock, 1, self::BUDGET * 3 );
		// Should this run be cut short, by a time limit or a restart, the next one is already due.
		wp_clear_scheduled_hook( self::CRON, array( $post_id ) );
		wp_schedule_single_event( time() + self::BUDGET * 6, self::CRON, array( $post_id ) );

		$job   = self::upload_step( $job, $post_id );
		$state = $job['state'];
		update_post_meta( $post_id, self::JOB_META, $job );
		delete_transient( $lock );

		wp_clear_scheduled_hook( self::CRON, array( $post_id ) );
		if ( 'uploading' === $state || 'queued' === $state ) {
			$delay = $job['retries'] > 0 ? min( HOUR_IN_SECONDS, 30 * ( 2 ** ( $job['retries'] - 1 ) ) ) : 5;
			self::schedule( self::CRON, $post_id, $delay );
		} elseif ( 'uploaded' === $state ) {
			self::schedule( self::POLL, $post_id, 60 );
		}
	}

	/**
	 * Advance an upload as far as one run allows.
	 *
	 * @param array $job     Job.
	 * @param int   $post_id Post ID.
	 * @return array The job, updated.
	 */
	public static function upload_step( $job, $post_id ) {
		$file = get_attached_file( (int) $job['attachment'] );
		if ( '' === (string) $file || ! is_readable( $file ) || (int) filesize( $file ) !== (int) $job['size'] ) {
			return self::fail( $job, __( 'The video file is gone or has changed since the upload was queued.', 'ai-chat-for-amazon-bedrock' ) );
		}
		$token = self::access_token();
		if ( is_wp_error( $token ) ) {
			return 'aicfab_youtube_disconnected' === $token->get_error_code() || 'invalid_grant' === $token->get_error_code() ? self::fail( $job, __( 'The YouTube channel is no longer connected.', 'ai-chat-for-amazon-bedrock' ) ) : self::retry( $job, $token->get_error_message() );
		}

		if ( '' === $job['session'] ) {
			$session = self::start_session( $job, $token );
			if ( is_wp_error( $session ) ) {
				$status = (int) ( is_array( $session->get_error_data() ) && isset( $session->get_error_data()['status'] ) ? $session->get_error_data()['status'] : 0 );
				return self::is_transient_status( $status ) || 0 === $status ? self::retry( $job, $session->get_error_message() ) : self::fail( $job, self::describe_error( $session ) );
			}
			$job['session'] = $session;
			$job['offset']  = 0;
			$job['state']   = 'uploading';
		} elseif ( $job['retries'] > 0 || ! empty( $job['interrupted'] ) ) {
			// After an error the server may hold more or less than was last confirmed: ask it.
			$asked = self::put( $job['session'], '', 'bytes */' . (int) $job['size'], $token, $job['mime'] );
			$done  = self::read_put( $asked, $job, $post_id );
			if ( null !== $done ) {
				return $done;
			}
			$job = self::with_offset( $job, $asked );
		}
		// Saved before sending, so a run cut short leaves word to ask YouTube how much arrived.
		$job['interrupted'] = true;
		update_post_meta( $post_id, self::JOB_META, $job );

		$started = microtime( true );
		$handle  = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- the video is read a chunk at a time, which WP_Filesystem cannot do.
		if ( false === $handle ) {
			return self::retry( $job, __( 'The video file could not be read.', 'ai-chat-for-amazon-bedrock' ) );
		}
		while ( microtime( true ) - $started < self::BUDGET && $job['offset'] < $job['size'] ) {
			fseek( $handle, (int) $job['offset'] );
			$chunk = fread( $handle, self::CHUNK ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$last     = (int) $job['offset'] + strlen( $chunk ) - 1;
			$response = self::put( $job['session'], $chunk, 'bytes ' . (int) $job['offset'] . '-' . $last . '/' . (int) $job['size'], $token, $job['mime'] );
			$done     = self::read_put( $response, $job, $post_id );
			if ( null !== $done ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				return $done;
			}
			$job            = self::with_offset( $job, $response );
			$job['retries'] = 0;
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		unset( $job['interrupted'] );
		return $job;
	}

	/**
	 * Open a resumable upload session.
	 *
	 * @param array  $job   Job.
	 * @param string $token Access token.
	 * @return string|WP_Error The session address.
	 */
	private static function start_session( $job, $token ) {
		$response = wp_safe_remote_post(
			add_query_arg(
				array(
					'uploadType' => 'resumable',
					'part'       => 'snippet,status',
				),
				self::UPLOAD_URL
			),
			array(
				'timeout'     => 20,
				'redirection' => 0,
				'headers'     => array(
					'Authorization'           => 'Bearer ' . $token,
					'Content-Type'            => 'application/json; charset=UTF-8',
					'X-Upload-Content-Length' => (string) (int) $job['size'],
					'X-Upload-Content-Type'   => (string) $job['mime'],
				),
				'body'        => wp_json_encode( $job['resource'] ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code     = (int) wp_remote_retrieve_response_code( $response );
		$location = (string) wp_remote_retrieve_header( $response, 'location' );
		if ( 200 !== $code || 0 !== strpos( $location, 'https://www.googleapis.com/upload/youtube/v3/videos' ) ) {
			return self::api_error( $code, json_decode( (string) wp_remote_retrieve_body( $response ), true ) );
		}
		return $location;
	}

	/**
	 * Send one chunk, or ask how much has arrived.
	 *
	 * @param string $session Session address.
	 * @param string $chunk   Bytes; empty to ask.
	 * @param string $range   Content-Range.
	 * @param string $token   Access token.
	 * @param string $mime    Video type.
	 * @return array|WP_Error
	 */
	private static function put( $session, $chunk, $range, $token, $mime ) {
		return wp_safe_remote_request(
			$session,
			array(
				'method'      => 'PUT',
				'timeout'     => 60,
				'redirection' => 0,
				'headers'     => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => $mime,
					'Content-Range' => $range,
				),
				'body'        => $chunk,
			)
		);
	}

	/**
	 * What a response means for the job: finished, failed, to retry, or null to go on.
	 *
	 * @param array|WP_Error $response Response.
	 * @param array          $job      Job.
	 * @param int            $post_id  Post ID.
	 * @return array|null
	 */
	private static function read_put( $response, $job, $post_id ) {
		if ( is_wp_error( $response ) ) {
			return self::retry( $job, $response->get_error_message() );
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 308 === $code ) {
			return null;
		}
		if ( 200 === $code || 201 === $code ) {
			$video = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			return is_array( $video ) && ! empty( $video['id'] ) ? self::finish( $job, $video, $post_id ) : self::retry( $job, __( 'YouTube did not say which video was made.', 'ai-chat-for-amazon-bedrock' ) );
		}
		if ( 404 === $code || 410 === $code ) {
			// The session expired: start again from the first byte.
			$job['session'] = '';
			$job['offset']  = 0;
			return self::retry( $job, __( 'The upload session expired, so the upload starts again.', 'ai-chat-for-amazon-bedrock' ) );
		}
		if ( 401 === $code ) {
			delete_transient( 'aicfab_youtube_access' );
			return self::retry( $job, __( 'The access token expired.', 'ai-chat-for-amazon-bedrock' ) );
		}
		if ( self::is_transient_status( $code ) ) {
			return self::retry( $job, sprintf( 'HTTP %d', $code ) );
		}
		return self::fail( $job, self::describe_error( self::api_error( $code, json_decode( (string) wp_remote_retrieve_body( $response ), true ) ) ) );
	}

	/**
	 * The job with the offset YouTube reports in a 308's Range header.
	 *
	 * @param array $job      Job.
	 * @param array $response Response.
	 * @return array
	 */
	private static function with_offset( $job, $response ) {
		$range = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_header( $response, 'range' );
		// No Range header means nothing has arrived yet.
		$job['offset'] = preg_match( '/^bytes=0-(\d+)$/', trim( $range ), $match ) ? (int) $match[1] + 1 : 0;
		return $job;
	}

	private static function is_transient_status( $code ) {
		return in_array( (int) $code, array( 408, 429, 500, 502, 503, 504 ), true );
	}

	private static function retry( $job, $message ) {
		$job['retries'] = (int) $job['retries'] + 1;
		$job['error']   = (string) $message;
		if ( $job['retries'] > self::MAX_RETRIES ) {
			return self::fail( $job, (string) $message );
		}
		$job['state'] = '' === $job['session'] ? 'queued' : 'uploading';
		return $job;
	}

	private static function fail( $job, $message ) {
		$job['state']  = 'failed';
		$job['error']  = (string) $message;
		$job['failed'] = time();
		return $job;
	}

	/**
	 * A YouTube error in words an editor can act on.
	 *
	 * @param WP_Error $error Error.
	 * @return string
	 */
	private static function describe_error( $error ) {
		$known = array(
			'aicfab_youtube_quotaexceeded'       => __( 'The Google Cloud project has used up its YouTube quota for today. Uploads can start again after midnight Pacific time.', 'ai-chat-for-amazon-bedrock' ),
			'aicfab_youtube_uploadlimitexceeded' => __( 'The channel has reached YouTube\'s limit on uploads for now.', 'ai-chat-for-amazon-bedrock' ),
			'aicfab_youtube_forbidden'           => __( 'YouTube refused the upload for this channel. Check that the channel can upload videos and that the account is in good standing.', 'ai-chat-for-amazon-bedrock' ),
		);
		$code  = strtolower( $error->get_error_code() );
		return isset( $known[ $code ] ) ? $known[ $code ] : ( '' !== $error->get_error_message() ? $error->get_error_message() : $code );
	}

	/**
	 * Record a finished upload, and start following its processing.
	 *
	 * @param array $job     Job.
	 * @param array $video   Video resource YouTube returned.
	 * @param int   $post_id Post ID.
	 * @return array
	 */
	private static function finish( $job, $video, $post_id ) {
		$job['state']    = 'uploaded';
		$job['video_id'] = sanitize_text_field( (string) $video['id'] );
		$job['offset']   = (int) $job['size'];
		$job['error']    = '';
		$job['done_at']  = time();
		unset( $job['interrupted'] );
		$channel = self::channel();
		AI_Chat_Bedrock_Distribution::record(
			$post_id,
			array(
				'platform' => 'youtube',
				'item_id'  => $job['video_id'],
				'account'  => null !== $channel ? $channel['title'] . ' (' . $channel['id'] . ')' : '',
				'language' => $job['language'],
				'status'   => 'processing',
				'title'    => $job['resource']['snippet']['title'],
				'version'  => 'attachment-' . (int) $job['attachment'] . '-' . (int) $job['size'],
			),
			'youtube'
		);
		return $job;
	}

	/**
	 * Follow a video's processing until YouTube says what became of it.
	 *
	 * @param int $post_id Post ID.
	 */
	public static function poll( $post_id ) {
		$post_id = absint( $post_id );
		$job     = self::job( $post_id );
		if ( null === $job || 'uploaded' !== $job['state'] || '' === $job['video_id'] ) {
			return;
		}
		$job['polls'] = (int) $job['polls'] + 1;
		$found        = self::api(
			'GET',
			'/videos',
			array(
				'part' => 'status,processingDetails',
				'id'   => $job['video_id'],
			)
		);
		$video        = ! is_wp_error( $found ) && ! empty( $found['items'][0] ) ? $found['items'][0] : null;
		$upload       = null !== $video && isset( $video['status']['uploadStatus'] ) ? (string) $video['status']['uploadStatus'] : '';
		$status       = '';
		if ( 'processed' === $upload ) {
			$privacy = isset( $video['status']['privacyStatus'] ) ? (string) $video['status']['privacyStatus'] : 'private';
			$status  = in_array( $privacy, self::PRIVACY, true ) ? $privacy : 'private';
		} elseif ( in_array( $upload, array( 'failed', 'rejected', 'deleted' ), true ) ) {
			$status       = 'deleted' === $upload ? 'removed' : 'failed';
			$job['error'] = isset( $video['status']['rejectionReason'] ) ? sanitize_key( (string) $video['status']['rejectionReason'] ) : ( isset( $video['status']['failureReason'] ) ? sanitize_key( (string) $video['status']['failureReason'] ) : $upload );
		}
		if ( '' !== $status ) {
			$job['state'] = 'done';
			AI_Chat_Bedrock_Distribution::record(
				$post_id,
				array(
					'platform' => 'youtube',
					'item_id'  => $job['video_id'],
					'status'   => $status,
					// An unaudited project's uploads stay private whatever was asked.
					'note'     => 'private' === $status && 'private' !== $job['resource']['status']['privacyStatus'] ? __( 'Kept private by YouTube: uploads from a Google Cloud project that has not passed YouTube\'s audit stay private.', 'ai-chat-for-amazon-bedrock' ) : '',
				),
				'youtube'
			);
		} elseif ( $job['polls'] < self::MAX_POLLS ) {
			self::schedule( self::POLL, $post_id, min( 30 * MINUTE_IN_SECONDS, 60 * $job['polls'] ) );
		}
		update_post_meta( $post_id, self::JOB_META, $job );
	}

	private static function schedule( $hook, $post_id, $delay ) {
		if ( ! wp_next_scheduled( $hook, array( (int) $post_id ) ) ) {
			wp_schedule_single_event( time() + max( 0, (int) $delay ), $hook, array( (int) $post_id ) );
		}
	}

	private static function uploads_today() {
		$count = get_option( 'aicfab_youtube_uploads', array() );
		return is_array( $count ) && isset( $count['day'] ) && gmdate( 'Ymd' ) === $count['day'] ? (int) $count['n'] : 0;
	}

	private static function count_upload() {
		update_option(
			'aicfab_youtube_uploads',
			array(
				'day' => gmdate( 'Ymd' ),
				'n'   => self::uploads_today() + 1,
			),
			false
		);
	}

	/*
	 * ------------------------------------------------------------------
	 * Screens
	 * ------------------------------------------------------------------
	 */

	/**
	 * The upload status, and a link to upload, in the Published elsewhere box.
	 *
	 * @param WP_Post $post Post.
	 */
	public static function render_box_section( $post ) {
		if ( ! self::ready() || ! current_user_can( 'upload_files' ) ) {
			return;
		}
		$job = self::job( $post->ID );
		if ( null !== $job ) {
			echo '<p>' . esc_html( self::job_summary( $job ) ) . '</p>';
		}
		echo '<p><a class="button" href="' . esc_url( admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock-youtube&post=' . (int) $post->ID ) ) . '">' . esc_html__( 'Upload a video to YouTube', 'ai-chat-for-amazon-bedrock' ) . '</a></p>';
	}

	/**
	 * A job in words.
	 *
	 * @param array $job Job.
	 * @return string
	 */
	public static function job_summary( $job ) {
		switch ( $job['state'] ) {
			case 'queued':
				return __( 'YouTube upload queued.', 'ai-chat-for-amazon-bedrock' );
			case 'uploading':
				/* translators: %d: percentage uploaded. */
				return sprintf( __( 'Uploading to YouTube: %d%%.', 'ai-chat-for-amazon-bedrock' ), $job['size'] > 0 ? (int) floor( 100 * $job['offset'] / $job['size'] ) : 0 );
			case 'uploaded':
				return __( 'Uploaded; YouTube is processing the video.', 'ai-chat-for-amazon-bedrock' );
			case 'done':
				return __( 'Uploaded to YouTube. Its status is in the record above.', 'ai-chat-for-amazon-bedrock' );
			default:
				/* translators: %s: reason the upload failed. */
				return sprintf( __( 'The YouTube upload failed: %s', 'ai-chat-for-amazon-bedrock' ), (string) $job['error'] );
		}
	}

	/**
	 * Queue an upload from the upload screen.
	 */
	public static function handle_upload() {
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified on the next line.
		check_admin_referer( 'ai_chat_bedrock_youtube_upload_' . $post_id );
		// phpcs:disable WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each field is cleaned in queue().
		$input = array(
			'attachment'    => isset( $_POST['attachment'] ) ? absint( $_POST['attachment'] ) : 0,
			'title'         => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'description'   => isset( $_POST['description'] ) ? sanitize_textarea_field( wp_unslash( $_POST['description'] ) ) : '',
			'tags'          => isset( $_POST['tags'] ) ? sanitize_text_field( wp_unslash( $_POST['tags'] ) ) : '',
			'privacy'       => isset( $_POST['privacy'] ) ? sanitize_key( wp_unslash( $_POST['privacy'] ) ) : 'private',
			'made_for_kids' => isset( $_POST['made_for_kids'] ) ? sanitize_key( wp_unslash( $_POST['made_for_kids'] ) ) : '',
			'synthetic'     => ! empty( $_POST['synthetic'] ),
			'language'      => isset( $_POST['language'] ) ? sanitize_key( wp_unslash( $_POST['language'] ) ) : '',
		);
		// phpcs:enable
		$job      = self::queue( $post_id, $input );
		$redirect = add_query_arg(
			array(
				'page'   => 'ai-chat-for-amazon-bedrock-youtube',
				'post'   => $post_id,
				'queued' => is_wp_error( $job ) ? 0 : 1,
				'error'  => is_wp_error( $job ) ? rawurlencode( $job->get_error_code() ) : '',
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Add the upload screen.
	 *
	 * @param string $menu Parent menu slug.
	 */
	public static function add_page( $menu ) {
		if ( self::ready() ) {
			add_submenu_page( $menu, __( 'YouTube uploads', 'ai-chat-for-amazon-bedrock' ), __( 'YouTube uploads', 'ai-chat-for-amazon-bedrock' ), 'upload_files', 'ai-chat-for-amazon-bedrock-youtube', array( __CLASS__, 'render_page' ) );
		}
	}

	/**
	 * The upload screen: the form for one post, and the uploads under way.
	 */
	public static function render_page() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display only.
		$post_id = isset( $_GET['post'] ) ? absint( $_GET['post'] ) : 0;
		$error   = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
		$queued  = ! empty( $_GET['queued'] );
		// phpcs:enable
		$post    = $post_id ? get_post( $post_id ) : null;
		$channel = self::channel();
		echo '<div class="wrap"><h1>' . esc_html__( 'YouTube uploads', 'ai-chat-for-amazon-bedrock' ) . '</h1>';
		if ( null !== $channel ) {
			/* translators: %s: YouTube channel name. */
			echo '<p>' . esc_html( sprintf( __( 'Videos are uploaded to the channel %s.', 'ai-chat-for-amazon-bedrock' ), $channel['title'] ) ) . '</p>';
		}
		if ( $queued ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'The upload is queued and runs in the background. You can leave this page.', 'ai-chat-for-amazon-bedrock' ) . '</p></div>';
		} elseif ( '' !== $error ) {
			$messages = array(
				'aicfab_youtube_forbidden'    => __( 'You cannot upload a video for this post.', 'ai-chat-for-amazon-bedrock' ),
				'aicfab_youtube_busy'         => __( 'A video for this post is already being uploaded.', 'ai-chat-for-amazon-bedrock' ),
				'aicfab_youtube_daily'        => __( 'Today\'s uploads are used up.', 'ai-chat-for-amazon-bedrock' ),
				'aicfab_youtube_file'         => __( 'Choose a video in the Media Library whose file is on this server.', 'ai-chat-for-amazon-bedrock' ),
				'aicfab_youtube_audience'     => __( 'Say whether the video is made for children.', 'ai-chat-for-amazon-bedrock' ),
				'aicfab_youtube_disconnected' => __( 'Connect a YouTube channel under Settings > Publishing first.', 'ai-chat-for-amazon-bedrock' ),
			);
			echo '<div class="notice notice-error"><p>' . esc_html( isset( $messages[ $error ] ) ? $messages[ $error ] : $error ) . '</p></div>';
		}

		if ( $post instanceof WP_Post && current_user_can( 'edit_post', $post->ID ) ) {
			self::render_form( $post );
		} else {
			echo '<p>' . esc_html__( 'Open a post and choose Upload a video to YouTube in its Published elsewhere box.', 'ai-chat-for-amazon-bedrock' ) . '</p>';
		}
		echo '</div>';
	}

	private static function render_form( $post ) {
		$videos  = get_posts(
			array(
				'post_type'      => 'attachment',
				'post_mime_type' => 'video',
				'post_status'    => 'inherit',
				'posts_per_page' => 50,
			)
		);
		$tags    = wp_get_post_terms( $post->ID, 'post_tag', array( 'fields' => 'names' ) );
		$excerpt = trim( wp_strip_all_tags( (string) get_the_excerpt( $post ) ) );
		$lang    = class_exists( 'AI_Chat_Bedrock_Content' ) ? AI_Chat_Bedrock_Content::language( $post ) : '';
		$job     = self::job( $post->ID );
		/* translators: %s: post title. */
		echo '<h2>' . esc_html( sprintf( __( 'Upload a video for %s', 'ai-chat-for-amazon-bedrock' ), get_the_title( $post ) ) ) . '</h2>';
		if ( null !== $job ) {
			echo '<p><strong>' . esc_html( self::job_summary( $job ) ) . '</strong></p>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'ai_chat_bedrock_youtube_upload_' . $post->ID );
		echo '<input type="hidden" name="action" value="ai_chat_bedrock_youtube_upload"><input type="hidden" name="post_id" value="' . esc_attr( $post->ID ) . '"><input type="hidden" name="language" value="' . esc_attr( $lang ) . '">';
		echo '<table class="form-table" role="presentation"><tbody>';
		echo '<tr><th scope="row"><label for="aicfab-yt-video">' . esc_html__( 'Video', 'ai-chat-for-amazon-bedrock' ) . '</label></th><td><select id="aicfab-yt-video" name="attachment" required><option value="">' . esc_html__( 'Choose a video', 'ai-chat-for-amazon-bedrock' ) . '</option>';
		foreach ( $videos as $video ) {
			echo '<option value="' . esc_attr( $video->ID ) . '">' . esc_html( get_the_title( $video ) . ' (' . size_format( (int) filesize( (string) get_attached_file( $video->ID ) ) ) . ')' ) . '</option>';
		}
		echo '</select></td></tr>';
		echo '<tr><th scope="row"><label for="aicfab-yt-title">' . esc_html__( 'Title', 'ai-chat-for-amazon-bedrock' ) . '</label></th><td><input type="text" id="aicfab-yt-title" class="large-text" name="title" maxlength="' . esc_attr( self::MAX_TITLE ) . '" value="' . esc_attr( html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ) ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="aicfab-yt-description">' . esc_html__( 'Description', 'ai-chat-for-amazon-bedrock' ) . '</label></th><td><textarea id="aicfab-yt-description" class="large-text" rows="6" name="description">' . esc_textarea( trim( $excerpt . "\n\n" . get_permalink( $post ) ) ) . '</textarea></td></tr>';
		echo '<tr><th scope="row"><label for="aicfab-yt-tags">' . esc_html__( 'Tags', 'ai-chat-for-amazon-bedrock' ) . '</label></th><td><input type="text" id="aicfab-yt-tags" class="large-text" name="tags" value="' . esc_attr( is_wp_error( $tags ) ? '' : implode( ', ', $tags ) ) . '"></td></tr>';
		echo '<tr><th scope="row"><label for="aicfab-yt-privacy">' . esc_html__( 'Visibility', 'ai-chat-for-amazon-bedrock' ) . '</label></th><td><select id="aicfab-yt-privacy" name="privacy"><option value="private">' . esc_html__( 'Private', 'ai-chat-for-amazon-bedrock' ) . '</option><option value="unlisted">' . esc_html__( 'Unlisted', 'ai-chat-for-amazon-bedrock' ) . '</option><option value="public">' . esc_html__( 'Public', 'ai-chat-for-amazon-bedrock' ) . '</option></select><p class="description">' . esc_html__( 'YouTube keeps uploads from a Google Cloud project that has not passed its audit private, whatever is chosen here.', 'ai-chat-for-amazon-bedrock' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Audience', 'ai-chat-for-amazon-bedrock' ) . '</th><td><fieldset><legend class="screen-reader-text">' . esc_html__( 'Audience', 'ai-chat-for-amazon-bedrock' ) . '</legend><label><input type="radio" name="made_for_kids" value="no" required> ' . esc_html__( 'Not made for children', 'ai-chat-for-amazon-bedrock' ) . '</label><br><label><input type="radio" name="made_for_kids" value="yes"> ' . esc_html__( 'Made for children', 'ai-chat-for-amazon-bedrock' ) . '</label></fieldset></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Altered or synthetic content', 'ai-chat-for-amazon-bedrock' ) . '</th><td><label><input type="checkbox" name="synthetic" value="1"> ' . esc_html__( 'The video has realistic altered or synthetic content, such as an AI voice or generated footage', 'ai-chat-for-amazon-bedrock' ) . '</label></td></tr>';
		echo '</tbody></table>';
		submit_button( __( 'Upload to YouTube', 'ai-chat-for-amazon-bedrock' ) );
		echo '</form>';
	}

	private static function require_admin( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.', 'ai-chat-for-amazon-bedrock' ), 403 );
		}
		check_admin_referer( $action );
	}

	private static function back( $notice ) {
		wp_safe_redirect( add_query_arg( 'aicfab_youtube', $notice, admin_url( 'admin.php?page=ai-chat-for-amazon-bedrock-settings&tab=publishing' ) ) );
		exit;
	}

	private static function state_key() {
		return 'aicfab_youtube_state_' . get_current_user_id();
	}

	private static function options( $options ) {
		if ( is_array( $options ) ) {
			return $options;
		}
		$options = get_option( 'ai_chat_bedrock_settings', array() );
		return is_array( $options ) ? $options : array();
	}
}

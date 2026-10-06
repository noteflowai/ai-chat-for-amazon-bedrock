<?php
/**
 * Calls to WeChat's server API, for the Official Account and the mini game.
 *
 * An access token comes from the stable token API, which does not cancel a token another
 * server of the same account holds, as the older API would. It is kept encrypted until shortly
 * before it expires, and fetched again once when WeChat says it is no longer valid.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_WeChat_API {

	const API_URL = 'https://api.weixin.qq.com/cgi-bin/';

	// WeChat's codes for an access token that expired early or was replaced.
	const STALE_TOKEN = array( 'wx_40001', 'wx_40014', 'wx_42001' );

	/**
	 * Call the API with the account's access token.
	 *
	 * @param string $path    Path and query after cgi-bin/.
	 * @param string $body    Request body.
	 * @param array  $account app_id, secret, and cache: the transient that keeps the token.
	 * @param int    $timeout  Seconds.
	 * @param string $type     Content type of the body.
	 * @param float  $deadline Time after which a stale token is not fetched again; 0 for none.
	 * @return array|WP_Error The response, or an error named wx_ and WeChat's error code.
	 */
	public static function call( $path, $body, $account, $timeout = 3, $type = 'application/json', $deadline = 0.0 ) {
		foreach ( array( false, true ) as $fresh ) {
			if ( $fresh && $deadline > 0 && microtime( true ) > $deadline ) {
				return new WP_Error( 'wx_timeout', 'Out of time' );
			}
			$token = self::access_token( $account, $fresh );
			if ( is_wp_error( $token ) ) {
				return $token;
			}
			$result = self::post( $path . ( false === strpos( $path, '?' ) ? '?' : '&' ) . 'access_token=' . rawurlencode( $token ), $body, $timeout, $type );
			if ( ! $fresh && is_wp_error( $result ) && in_array( $result->get_error_code(), self::STALE_TOKEN, true ) ) {
				continue;
			}
			return $result;
		}
		return new WP_Error( 'wx_token', 'No access token' );
	}

	/**
	 * An access token for the account.
	 *
	 * @param array $account app_id, secret and cache.
	 * @param bool  $fresh   Ask WeChat for a new one.
	 * @return string|WP_Error
	 */
	public static function access_token( $account, $fresh = false ) {
		$app_id = isset( $account['app_id'] ) ? (string) $account['app_id'] : '';
		$secret = isset( $account['secret'] ) ? (string) $account['secret'] : '';
		$cache  = isset( $account['cache'] ) ? (string) $account['cache'] : '';
		$cached = '' !== $cache ? get_transient( $cache ) : false;
		if ( ! $fresh && is_array( $cached ) && isset( $cached['app_id'], $cached['token'] ) && $app_id === $cached['app_id'] ) {
			$token = AI_Chat_Bedrock_Security::decrypt_secret( $cached['token'] );
			if ( '' !== $token ) {
				return $token;
			}
		}
		if ( '' === $secret || '' === $app_id ) {
			return new WP_Error( 'wx_secret', 'No AppSecret' );
		}
		$data = self::post(
			'stable_token',
			wp_json_encode(
				array(
					'grant_type'    => 'client_credential',
					'appid'         => $app_id,
					'secret'        => $secret,
					'force_refresh' => (bool) $fresh,
				)
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['access_token'] ) || ! is_string( $data['access_token'] ) ) {
			return new WP_Error( 'wx_token', 'No access token' );
		}
		if ( '' !== $cache ) {
			$ttl = isset( $data['expires_in'] ) ? (int) $data['expires_in'] - 300 : 0;
			set_transient(
				$cache,
				array(
					'app_id' => $app_id,
					'token'  => AI_Chat_Bedrock_Security::encrypt_secret( $data['access_token'] ),
				),
				max( 60, $ttl )
			);
		}
		return $data['access_token'];
	}

	/**
	 * Post to the API.
	 *
	 * @param string $path    Path and query after cgi-bin/.
	 * @param string $body    Body.
	 * @param int    $timeout Seconds.
	 * @param string $type    Content type.
	 * @return array|WP_Error
	 */
	public static function post( $path, $body, $timeout = 3, $type = 'application/json' ) {
		$response = wp_safe_remote_post(
			self::API_URL . $path,
			array(
				'timeout' => $timeout,
				'headers' => array( 'Content-Type' => $type ),
				'body'    => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'wx_http', $response->get_error_message() );
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'wx_http', 'HTTP ' . (int) wp_remote_retrieve_response_code( $response ) );
		}
		if ( ! empty( $data['errcode'] ) ) {
			return new WP_Error( 'wx_' . (int) $data['errcode'], isset( $data['errmsg'] ) ? (string) $data['errmsg'] : '' );
		}
		return $data;
	}

	/**
	 * A file as the multipart form WeChat's upload interfaces take.
	 *
	 * @param string $file Path.
	 * @param string $mime image/jpeg or image/png.
	 * @return array|null Body and content type, or null when the file cannot be read.
	 */
	public static function multipart( $file, $mime, $fields = array() ) {
		$data = is_readable( $file ) ? file_get_contents( $file ) : false; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local upload, read to send it.
		if ( false === $data ) {
			return null;
		}
		$boundary = 'aicfab' . bin2hex( random_bytes( 12 ) );
		$names    = array(
			'image/png' => 'image.png',
			'video/mp4' => 'video.mp4',
		);
		$name     = isset( $names[ $mime ] ) ? $names[ $mime ] : 'image.jpg';
		$body     = '';
		// Other fields first, such as a video's title and introduction.
		foreach ( $fields as $field => $value ) {
			$body .= '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"" . $field . "\"\r\n\r\n" . $value . "\r\n";
		}
		return array(
			'body' => $body . '--' . $boundary . "\r\nContent-Disposition: form-data; name=\"media\"; filename=\"" . $name . "\"\r\nContent-Type: " . $mime . "\r\n\r\n" . $data . "\r\n--" . $boundary . "--\r\n",
			'type' => 'multipart/form-data; boundary=' . $boundary,
		);
	}

	/**
	 * Whether a signed request is new, or WeChat sending the same request again.
	 *
	 * In plaintext mode the signature covers the token, timestamp and nonce but not the message,
	 * so anyone who saw one signed address, in a log for instance, could send other messages with
	 * it until the timestamp is too old. Each signed address is kept with its message's hash for
	 * as long as it is accepted: WeChat's own repeats carry the same message and pass; a different
	 * message under the same signature is refused.
	 *
	 * @param string $route     Route.
	 * @param string $signature Signature or msg_signature.
	 * @param string $timestamp Timestamp.
	 * @param string $nonce     Nonce.
	 * @param string $body      Body.
	 * @param int    $ttl       Seconds the signature is accepted.
	 * @return bool False for a different message under a signature already used.
	 */
	public static function fresh( $route, $signature, $timestamp, $nonce, $body, $ttl ) {
		$key  = 'aicfab_wxn_' . md5( $route . '|' . $signature . '|' . $timestamp . '|' . $nonce );
		$hash = sha1( (string) $body );
		$seen = get_transient( $key );
		if ( false !== $seen ) {
			return hash_equals( (string) $seen, $hash );
		}
		set_transient( $key, $hash, $ttl + 60 );
		return true;
	}

	/**
	 * The address WeChat refused, when an error says the server is not in the IP whitelist.
	 *
	 * @param WP_Error $error Error.
	 * @return string IPv4 address, or an empty string.
	 */
	public static function refused_ip( $error ) {
		return $error instanceof WP_Error && preg_match( '/invalid ip ((?:\d{1,3}\.){3}\d{1,3})/', $error->get_error_message(), $found ) ? $found[1] : '';
	}
}

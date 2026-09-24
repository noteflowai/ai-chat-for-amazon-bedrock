<?php
/**
 * Bedrock model catalog with live discovery and caching.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Models {

	const CACHE_PREFIX = 'aicfab_models_';
	const CACHE_TTL    = 43200;

	/*
	 * Must match the model activation writes. 1.44.0 moved activation off Claude 3 Haiku and
	 * left this constant behind, so a settings form saved without a model, and a site whose
	 * settings were never written, still fell back to the retired model.
	 */
	const DEFAULT_MODEL = 'amazon.nova-lite-v1:0';

	/**
	 * Model identifiers that remain selectable when discovery is unavailable.
	 *
	 * @return array
	 */
	public static function fallback_models() {
		return array(

			/*
			 * Only consulted when discovery fails, so it is ordered with the models most
			 * likely to be usable first. Claude 3 Haiku led this list until its provider
			 * retired it for accounts not already using it, which made the first entry the
			 * one most likely to fail. Claude 3.7 Sonnet and Titan Text Express were removed
			 * in 1.45.0: Bedrock answers both with "This model version has reached the end
			 * of its life".
			 */
			'amazon.nova-lite-v1:0'                       => 'Amazon Nova Lite',
			'amazon.nova-micro-v1:0'                      => 'Amazon Nova Micro',
			'amazon.nova-pro-v1:0'                        => 'Amazon Nova Pro',
			'us.anthropic.claude-haiku-4-5-20251001-v1:0' => 'Claude Haiku 4.5 (inference profile)',
			'us.anthropic.claude-sonnet-5'                => 'Claude Sonnet 5 (inference profile)',
			'us.anthropic.claude-opus-5-5'                => 'Claude Opus 5.5 (inference profile)',
			'meta.llama3-8b-instruct-v1:0'                => 'Meta Llama 3 8B',
			'mistral.mistral-7b-instruct-v0:2'            => 'Mistral 7B',
			'us.deepseek.r1-v1:0'                         => 'DeepSeek R1 (inference profile)',
		);
	}

	/**
	 * Whether a model identifier is structurally valid.
	 *
	 * Bedrock adds models and inference profiles continuously, so identifiers are
	 * validated by format instead of a fixed allowlist.
	 *
	 * @param string $model_id Model identifier.
	 * @return bool
	 */
	public static function is_valid_id( $model_id ) {
		$model_id = (string) $model_id;
		if ( '' === $model_id || strlen( $model_id ) > 200 ) {
			return false;
		}
		return (bool) preg_match( '/^[A-Za-z0-9][A-Za-z0-9._:\/-]*$/', $model_id );
	}

	/**
	 * Selectable models for the current region, using cached discovery results.
	 *
	 * @param string $region  AWS region.
	 * @param bool   $refresh Whether to bypass the cache.
	 * @return array Map of model identifier to label.
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- part of the callback signature.
	public static function options( $region = '', $refresh = false ) {
		$region = '' !== $region ? sanitize_key( $region ) : self::current_region();
		$cache  = self::CACHE_PREFIX . $region;

		if ( ! $refresh ) {
			$cached = get_transient( $cache );
			if ( is_array( $cached ) && ! empty( $cached ) ) {
				return $cached;
			}
		}

		$discovered = self::discover( $region );
		if ( empty( $discovered ) ) {
			return self::with_configured_model( self::fallback_models() );
		}

		set_transient( $cache, $discovered, self::CACHE_TTL );
		return self::with_configured_model( $discovered );
	}

	/**
	 * Refresh the cached catalog for a region.
	 *
	 * @param string $region AWS region.
	 * @return array|WP_Error
	 */
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- part of the callback signature.
	public static function refresh( $region = '' ) {
		$region = '' !== $region ? sanitize_key( $region ) : self::current_region();
		$aws    = new AI_Chat_Bedrock_AWS();
		$models = $aws->list_foundation_models();
		if ( is_wp_error( $models ) ) {
			return $models;
		}

		$discovered = self::build_catalog( $models, $aws->list_inference_profiles() );
		if ( empty( $discovered ) ) {
			return new WP_Error( 'aicfab_no_models', __( 'No text models are available to this AWS identity in the selected region.', 'ai-chat-for-amazon-bedrock' ) );
		}

		set_transient( self::CACHE_PREFIX . $region, $discovered, self::CACHE_TTL );
		return self::with_configured_model( $discovered );
	}

	/**
	 * Remove cached catalogs.
	 */
	public static function flush_cache() {
		foreach ( array_keys( self::regions() ) as $region ) {
			delete_transient( self::CACHE_PREFIX . $region );
		}
	}

	/**
	 * Supported AWS regions for Amazon Bedrock selection.
	 *
	 * @return array
	 */
	public static function regions() {
		return array(
			'us-east-1'      => 'US East (N. Virginia)',
			'us-east-2'      => 'US East (Ohio)',
			'us-west-1'      => 'US West (N. California)',
			'us-west-2'      => 'US West (Oregon)',
			'ca-central-1'   => 'Canada (Central)',
			'sa-east-1'      => 'South America (São Paulo)',
			'eu-west-1'      => 'Europe (Ireland)',
			'eu-west-2'      => 'Europe (London)',
			'eu-west-3'      => 'Europe (Paris)',
			'eu-central-1'   => 'Europe (Frankfurt)',
			'eu-north-1'     => 'Europe (Stockholm)',
			'eu-south-1'     => 'Europe (Milan)',
			'ap-northeast-1' => 'Asia Pacific (Tokyo)',
			'ap-northeast-2' => 'Asia Pacific (Seoul)',
			'ap-northeast-3' => 'Asia Pacific (Osaka)',
			'ap-southeast-1' => 'Asia Pacific (Singapore)',
			'ap-southeast-2' => 'Asia Pacific (Sydney)',
			'ap-south-1'     => 'Asia Pacific (Mumbai)',
			'me-central-1'   => 'Middle East (UAE)',
			'us-gov-west-1'  => 'AWS GovCloud (US-West)',
		);
	}

	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- part of the callback signature.
	private static function discover( $region ) {
		$aws    = new AI_Chat_Bedrock_AWS();
		$models = $aws->list_foundation_models();
		if ( is_wp_error( $models ) ) {
			return array();
		}
		return self::build_catalog( $models, $aws->list_inference_profiles() );
	}

	private static function build_catalog( $models, $profiles ) {
		$catalog        = array();
		$profile_lookup = array();

		if ( is_array( $profiles ) ) {
			foreach ( $profiles as $profile ) {
				if ( ! empty( $profile['id'] ) ) {
					$profile_lookup[ (string) $profile['id'] ] = isset( $profile['name'] ) ? (string) $profile['name'] : (string) $profile['id'];
				}
			}
		}

		foreach ( (array) $models as $model ) {
			if ( empty( $model['id'] ) || ! self::is_valid_id( $model['id'] ) ) {
				continue;
			}
			$label = self::label( $model );

			if ( ! empty( $model['on_demand'] ) ) {
				$catalog[ (string) $model['id'] ] = $label;
			}

			if ( ! empty( $model['profile'] ) ) {
				foreach ( self::profile_candidates( (string) $model['id'] ) as $candidate ) {
					if ( isset( $profile_lookup[ $candidate ] ) ) {
						$catalog[ $candidate ] = $label . ' — ' . __( 'inference profile', 'ai-chat-for-amazon-bedrock' );
						break;
					}
				}
			}
		}

		asort( $catalog );
		return $catalog;
	}

	private static function label( $model ) {
		$name     = isset( $model['name'] ) ? (string) $model['name'] : (string) $model['id'];
		$provider = isset( $model['provider'] ) ? (string) $model['provider'] : '';
		$label    = '' !== $provider ? $provider . ' ' . $name : $name;

		if ( isset( $model['lifecycle'] ) && 'LEGACY' === $model['lifecycle'] ) {
			$label .= ' (' . __( 'legacy', 'ai-chat-for-amazon-bedrock' ) . ')';
		}
		return $label;
	}

	private static function profile_candidates( $model_id ) {
		$prefixes   = array( 'us', 'eu', 'apac', 'ap', 'us-gov' );
		$candidates = array();
		foreach ( $prefixes as $prefix ) {
			$candidates[] = $prefix . '.' . $model_id;
		}
		$candidates[] = $model_id;
		return $candidates;
	}

	private static function with_configured_model( $catalog ) {
		$options    = get_option( 'ai_chat_bedrock_settings', array() );
		$options    = is_array( $options ) ? $options : array();
		$configured = isset( $options['model_id'] ) ? (string) $options['model_id'] : '';

		if ( '' !== $configured && self::is_valid_id( $configured ) && ! isset( $catalog[ $configured ] ) ) {
			$catalog = array( $configured => $configured . ' — ' . __( 'configured', 'ai-chat-for-amazon-bedrock' ) ) + $catalog;
		}
		return $catalog;
	}

	private static function current_region() {
		$options = get_option( 'ai_chat_bedrock_settings', array() );
		$options = is_array( $options ) ? $options : array();
		$region  = isset( $options['aws_region'] ) ? sanitize_key( $options['aws_region'] ) : 'us-east-1';
		return isset( self::regions()[ $region ] ) ? $region : 'us-east-1';
	}
}

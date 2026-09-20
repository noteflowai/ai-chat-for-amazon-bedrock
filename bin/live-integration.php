<?php
/**
 * Does the plugin actually attach where it says it does?
 *
 * Run through WP-CLI against a real WordPress with the plugin active. Every check here is one that
 * the unit suites cannot make, because they call the plugin's own methods directly and so cannot
 * see whether the hook the method is attached to is one WordPress ever fires.
 *
 * This exists because it would have caught a real defect. The Abilities API integration registered
 * on `abilities_api_init`, which is not a hook WordPress has, and the fallback on `init` was refused
 * by core. Every suite passed, the feature was inert on every install, and WordPress's own MCP
 * adapter therefore exposed nothing from this plugin. Nothing in the test suites could have noticed.
 *
 * @package AI_Chat_Bedrock
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	fwrite( STDERR, "This script runs through WP-CLI.\n" );
	exit( 1 );
}

/**
 * Record one expectation, and read back what has been recorded.
 *
 * Deliberately not using globals. WP-CLI runs an eval-file inside a function scope, so a top-level
 * $notes here is not a global at all, and the first version of this script declared it global
 * inside this function, accumulated into a different empty variable, checked nothing and reported
 * success. A static belongs to the function wherever the file is executed from.
 *
 * @param bool|null $ok      Whether it held, or null to read the tally.
 * @param string    $message What was expected.
 * @param string    $detail  What was observed.
 * @return array Notes and failures so far.
 */
function aicfab_live( $ok = null, $message = '', $detail = '' ) {
	static $notes    = array();
	static $failures = array();

	if ( null !== $ok ) {
		$notes[] = sprintf( '  %-4s %s%s', $ok ? 'ok' : 'FAIL', $message, '' !== $detail ? ' (' . $detail . ')' : '' );
		if ( ! $ok ) {
			$failures[] = $message . ( '' !== $detail ? ': ' . $detail : '' );
		}
	}
	return array(
		'notes'    => $notes,
		'failures' => $failures,
	);
}

/**
 * Add a line that is not an expectation, such as something skipped.
 *
 * @param string $line Line to record.
 */
function aicfab_note( $line ) {
	aicfab_live( true, $line );
}

// --- The plugin is the thing being examined ------------------------------------

aicfab_live(
	defined( 'AI_CHAT_BEDROCK_VERSION' ),
	'the plugin is loaded',
	defined( 'AI_CHAT_BEDROCK_VERSION' ) ? AI_CHAT_BEDROCK_VERSION : 'not active'
);

// --- Abilities reach the registry WordPress publishes --------------------------

/*
 * Turned on for the duration of the check. The feature is off by default on purpose, and a check
 * that silently accepted "off" would pass on a broken hook name exactly as the suites did.
 */
$aicfab_had_abilities = get_option( 'ai_chat_bedrock_site_abilities', false );
update_option( 'ai_chat_bedrock_site_abilities', 1 );

if ( ! function_exists( 'wp_get_abilities' ) ) {
	aicfab_note( 'skipped: this WordPress has no Abilities API' );
} else {
	// The registry initialises lazily, so ask for it rather than reading a did_action counter.
	$aicfab_all = (array) wp_get_abilities();
	$aicfab_ours = array();
	foreach ( $aicfab_all as $aicfab_ability ) {
		$aicfab_name = is_object( $aicfab_ability ) && method_exists( $aicfab_ability, 'get_name' )
			? $aicfab_ability->get_name()
			: '';
		if ( 0 === strpos( (string) $aicfab_name, 'ai-chat-bedrock/' ) ) {
			$aicfab_ours[ $aicfab_name ] = $aicfab_ability;
		}
	}

	aicfab_live(
		count( $aicfab_ours ) >= 5,
		'the abilities register with WordPress',
		count( $aicfab_ours ) . ' of ' . count( $aicfab_all ) . ' in the registry'
	);

	// Each one must be filed somewhere real, or WordPress drops it without saying so.
	$aicfab_uncategorised = array();
	foreach ( $aicfab_ours as $aicfab_name => $aicfab_ability ) {
		$aicfab_cat = method_exists( $aicfab_ability, 'get_category' ) ? (string) $aicfab_ability->get_category() : '';
		if ( '' === $aicfab_cat ) {
			$aicfab_uncategorised[] = $aicfab_name;
		}
	}
	aicfab_live( array() === $aicfab_uncategorised, 'every ability carries a category', implode( ', ', $aicfab_uncategorised ) );

	if ( function_exists( 'wp_get_ability_categories' ) ) {
		$aicfab_slugs = array();
		foreach ( (array) wp_get_ability_categories() as $aicfab_category ) {
			$aicfab_slugs[] = is_object( $aicfab_category ) && method_exists( $aicfab_category, 'get_slug' )
				? $aicfab_category->get_slug()
				: '';
		}
		aicfab_live(
			in_array( 'ai-chat-bedrock', $aicfab_slugs, true ),
			'the category the abilities claim exists',
			implode( ', ', array_filter( $aicfab_slugs ) )
		);
	}

	/*
	 * The behaviour each ability declares is what a client reads to decide whether calling it
	 * unattended is safe, and WordPress enforces it at the transport layer. Exactly one ability
	 * here writes, so exactly one should say so.
	 */
	$aicfab_writers = array();
	$aicfab_silent  = array();
	foreach ( $aicfab_ours as $aicfab_name => $aicfab_ability ) {
		$aicfab_meta = method_exists( $aicfab_ability, 'get_meta' ) ? (array) $aicfab_ability->get_meta() : array();
		$aicfab_ann  = isset( $aicfab_meta['annotations'] ) ? (array) $aicfab_meta['annotations'] : array();
		if ( array() === $aicfab_ann ) {
			$aicfab_silent[] = $aicfab_name;
		}
		if ( array_key_exists( 'readonly', $aicfab_ann ) && false === $aicfab_ann['readonly'] ) {
			$aicfab_writers[] = $aicfab_name;
		}
	}
	aicfab_live( array() === $aicfab_silent, 'every ability declares its behaviour', implode( ', ', $aicfab_silent ) );
	aicfab_live(
		array( 'ai-chat-bedrock/create-draft' ) === $aicfab_writers,
		'the draft ability is the only one that declares it writes',
		$aicfab_writers ? implode( ', ', $aicfab_writers ) : 'none declared'
	);
}

if ( false === $aicfab_had_abilities ) {
	delete_option( 'ai_chat_bedrock_site_abilities' );
} else {
	update_option( 'ai_chat_bedrock_site_abilities', $aicfab_had_abilities );
}

// --- The rest of what the plugin attaches to something it does not own ----------

if ( class_exists( 'WP_Block_Type_Registry' ) ) {
	aicfab_live(
		WP_Block_Type_Registry::get_instance()->is_registered( 'ai-chat-bedrock/chat' ),
		'the chat block is registered'
	);
}

aicfab_live( shortcode_exists( 'ai_chat_bedrock' ), 'the shortcode is registered' );

$aicfab_tests = apply_filters( 'site_status_tests', array( 'direct' => array(), 'async' => array() ) );
$aicfab_found = false;
foreach ( array( 'direct', 'async' ) as $aicfab_kind ) {
	foreach ( (array) ( isset( $aicfab_tests[ $aicfab_kind ] ) ? $aicfab_tests[ $aicfab_kind ] : array() ) as $aicfab_key => $aicfab_test ) {
		if ( false !== stripos( (string) $aicfab_key, 'bedrock' ) ) {
			$aicfab_found = true;
		}
	}
}
aicfab_live( $aicfab_found, 'the Site Health check is offered to WordPress' );

$aicfab_exporters = apply_filters( 'wp_privacy_personal_data_exporters', array() );
$aicfab_erasers   = apply_filters( 'wp_privacy_personal_data_erasers', array() );
aicfab_live( isset( $aicfab_exporters['ai-chat-for-amazon-bedrock'] ), 'the privacy exporter is registered' );
aicfab_live( isset( $aicfab_erasers['ai-chat-for-amazon-bedrock'] ), 'the privacy eraser is registered' );

$aicfab_routes = array();
foreach ( rest_get_server()->get_routes() as $aicfab_route => $aicfab_handlers ) {
	if ( false !== strpos( $aicfab_route, 'ai-chat-bedrock' ) ) {
		$aicfab_routes[] = $aicfab_route;
	}
}
aicfab_live( count( $aicfab_routes ) >= 5, 'the REST routes are registered', count( $aicfab_routes ) . ' routes' );

/*
 * Core's AI Client, where the install has one. The provider must be registered and configured, or
 * wp_ai_client_prompt() cannot reach Bedrock and the integration is decoration.
 */
/*
 * The provider can only register where the bundled library is complete, and on some installs it is
 * not: continuous integration on WordPress 7.1.1 has the AiClient class but not the interfaces the
 * provider implements. That state is legitimate, and the plugin's job there is to decline rather
 * than to fail, so this asserts the two halves of the contract separately. Enough detail is printed
 * to tell which half applies without another round trip.
 */
if ( ! class_exists( 'AI_Chat_Bedrock_Core_AI' ) ) {
	aicfab_note( 'skipped: the core AI integration is not part of this build' );
} elseif ( ! class_exists( '\WordPress\AiClient\AiClient' ) ) {
	aicfab_note( 'skipped: this WordPress has no AI Client at all' );
} else {
	$aicfab_iface    = '\WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface';
	$aicfab_usable   = AI_Chat_Bedrock_Core_AI::core_ai_available();
	$aicfab_registry = \WordPress\AiClient\AiClient::defaultRegistry();
	$aicfab_ids      = (array) $aicfab_registry->getRegisteredProviderIds();
	$aicfab_present  = in_array( 'amazon-bedrock', $aicfab_ids, true );

	aicfab_note(
		sprintf(
			'AI Client: library usable=%s, TextGenerationModelInterface=%s, autoloader file=%s',
			$aicfab_usable ? 'yes' : 'no',
			interface_exists( $aicfab_iface ) ? 'present' : 'absent',
			file_exists( ABSPATH . WPINC . '/php-ai-client/autoload.php' ) ? 'present' : 'absent'
		)
	);

	if ( $aicfab_usable ) {
		aicfab_live( $aicfab_present, 'the provider registers where the library is complete', implode( ', ', $aicfab_ids ) );
	} else {
		// Declining is the correct behaviour, and it must decline rather than half-register.
		aicfab_live( ! $aicfab_present, 'the provider stays out where the library is incomplete', implode( ', ', $aicfab_ids ) );
	}
}

if ( function_exists( 'wp_is_connector_registered' ) ) {
	aicfab_live( wp_is_connector_registered( 'amazon-bedrock' ), 'the connector is registered' );
}

$aicfab_tally = aicfab_live();
WP_CLI::line( implode( "\n", $aicfab_tally['notes'] ) );

// A run that checked nothing is not a pass. This script existed once in that state.
if ( count( $aicfab_tally['notes'] ) < 8 ) {
	WP_CLI::error( sprintf( 'only %d checks ran, so this proves nothing', count( $aicfab_tally['notes'] ) ) );
}

if ( $aicfab_tally['failures'] ) {
	WP_CLI::error( 'the plugin does not attach where it says: ' . implode( '; ', $aicfab_tally['failures'] ) );
}
WP_CLI::line( sprintf( 'OK: %d integration points verified against this WordPress', count( $aicfab_tally['notes'] ) ) );

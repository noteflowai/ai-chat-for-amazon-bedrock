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
// The site description and business insights are switched on through filters, so no setting is written.
add_filter( 'ai_chat_bedrock_ontology_enabled', '__return_true' );
add_filter( 'ai_chat_bedrock_metrics_enabled', '__return_true' );

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
	 * unattended is safe, and WordPress enforces it at the transport layer. With optional draft
	 * sending disabled, post draft creation, protected edition preparation and review write.
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
	sort( $aicfab_writers );
	aicfab_live(
		array( 'ai-chat-bedrock/create-draft', 'ai-chat-bedrock/review-wechat-post', 'ai-chat-bedrock/set-wechat-edition' ) === $aicfab_writers,
		'only draft creation, protected edition preparation and review declare writes',
		$aicfab_writers ? implode( ', ', $aicfab_writers ) : 'none declared'
	);
	$aicfab_actor = get_current_user_id();
	wp_set_current_user( 0 );
	foreach ( array( 'get-wechat-review' => true, 'review-wechat-post' => false, 'get-wechat-edition' => true, 'set-wechat-edition' => false ) as $aicfab_review_name => $aicfab_readonly ) {
		$aicfab_review = isset( $aicfab_ours[ 'ai-chat-bedrock/' . $aicfab_review_name ] ) ? $aicfab_ours[ 'ai-chat-bedrock/' . $aicfab_review_name ] : null;
		$aicfab_meta   = $aicfab_review ? (array) $aicfab_review->get_meta() : array();
		$aicfab_ann    = isset( $aicfab_meta['annotations'] ) ? (array) $aicfab_meta['annotations'] : array();
		aicfab_live(
			isset( $aicfab_ann['readonly'], $aicfab_ann['destructive'] ) && $aicfab_readonly === $aicfab_ann['readonly'] && false === $aicfab_ann['destructive'],
			$aicfab_review_name . ' registers with its exact review behaviour'
		);
		$aicfab_permission = $aicfab_review ? $aicfab_review->check_permissions( array( 'post_id' => 1 ) ) : true;
		aicfab_live(
			is_wp_error( $aicfab_permission ) || false === $aicfab_permission,
			$aicfab_review_name . ' rejects unauthenticated review access'
		);
	}
	wp_set_current_user( $aicfab_actor );
	aicfab_live( isset( $aicfab_ours['ai-chat-bedrock/describe-site'] ), 'the site description registers as an ability' );
	aicfab_live( isset( $aicfab_ours['ai-chat-bedrock/query-metrics'] ), 'business insights register as an ability' );
}

// --- The site description, against the real post types and counts ---------------

$aicfab_site  = AI_Chat_Bedrock_Ontology::describe();
$aicfab_types = is_array( $aicfab_site ) && isset( $aicfab_site['types'] ) ? array_column( $aicfab_site['types'], null, 'name' ) : array();
aicfab_live(
	isset( $aicfab_types['Article'] ) && (int) wp_count_posts( 'post' )->publish === $aicfab_types['Article']['count'],
	'the site description counts published posts as WordPress does',
	isset( $aicfab_types['Article'] ) ? (string) $aicfab_types['Article']['count'] : 'no Article type'
);
aicfab_live(
	class_exists( 'WooCommerce' ) === isset( $aicfab_types['Product'] ),
	'products are described exactly when WooCommerce is active',
	implode( ', ', array_keys( $aicfab_types ) )
);
$aicfab_claimed = array();
foreach ( $aicfab_types as $aicfab_type ) {
	foreach ( isset( $aicfab_type['post_types'] ) ? $aicfab_type['post_types'] : array() as $aicfab_post_type ) {
		$aicfab_claimed[] = $aicfab_post_type;
	}
}
$aicfab_public = array_diff( get_post_types( array( 'public' => true ) ), array( 'attachment' ) );
aicfab_live(
	array() === array_diff( $aicfab_public, $aicfab_claimed ) && count( $aicfab_claimed ) === count( array_unique( $aicfab_claimed ) ),
	'every public post type is described exactly once',
	implode( ', ', $aicfab_claimed )
);
aicfab_live(
	5 === has_filter( 'ai_chat_bedrock_retrieved_passages', array( 'AI_Chat_Bedrock_Ontology', 'annotate_passages' ) ),
	'retrieved passages are labelled before other filters see them'
);

// --- Business insights, against WordPress's own counts and WooCommerce Analytics ---

// Figures are read as a person who may see them all; the script itself runs as nobody.
$aicfab_admins = get_users(
	array(
		'role'   => 'administrator',
		'number' => 1,
		'fields' => 'ID',
	)
);
if ( empty( $aicfab_admins ) ) {
	aicfab_note( 'skipped: no administrator to read business insights as' );
} else {
	wp_set_current_user( (int) $aicfab_admins[0] );
	$aicfab_catalog = AI_Chat_Bedrock_Metrics::catalog();
	$aicfab_store   = array_keys( wp_list_filter( $aicfab_catalog, array( 'source' => 'store' ) ) );
	aicfab_live(
		class_exists( 'WooCommerce' ) === ( array() !== $aicfab_store ),
		'store figures are offered exactly when WooCommerce is active',
		implode( ', ', $aicfab_store )
	);

	// Counted here straight from the posts table, so a WP_Query argument WordPress ignores shows up.
	$aicfab_published = AI_Chat_Bedrock_Metrics::query(
		array(
			'metric' => 'content_published',
			'period' => 'last_30_days',
		)
	);
	if ( is_wp_error( $aicfab_published ) ) {
		aicfab_live( false, 'published items over the last 30 days can be read', $aicfab_published->get_error_message() );
	} else {
		global $wpdb;
		$aicfab_counted = AI_Chat_Bedrock_Ontology::public_post_types();
		$aicfab_in    = implode( ', ', array_fill( 0, count( $aicfab_counted ), '%s' ) );
		$aicfab_want  = $aicfab_counted ? (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ($aicfab_in) AND post_date >= %s AND post_date <= %s", // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				array_merge( $aicfab_counted, array( $aicfab_published['period']['after'] . ' 00:00:00', $aicfab_published['period']['before'] . ' 23:59:59' ) )
			)
		) : 0;
		aicfab_live(
			$aicfab_want === $aicfab_published['value'],
			'published items over the last 30 days match the posts table',
			$aicfab_published['value'] . ' against ' . $aicfab_want
		);
	}

	// Through WooCommerce's own Analytics routes, which the suites can only imitate.
	if ( in_array( 'net_revenue', $aicfab_store, true ) ) {
		$aicfab_sales = AI_Chat_Bedrock_Metrics::query(
			array(
				'metric'   => 'net_revenue',
				'period'   => 'last_30_days',
				'interval' => 'week',
			)
		);
		aicfab_live(
			is_array( $aicfab_sales ) && ! empty( $aicfab_sales['series'] ),
			'net sales by week are read from WooCommerce Analytics',
			is_wp_error( $aicfab_sales ) ? $aicfab_sales->get_error_message() : count( $aicfab_sales['series'] ) . ' weeks'
		);
	}

	$aicfab_refused = AI_Chat_Bedrock_Metrics::query(
		array(
			'metric' => 'questions',
			'period' => 'last_30_days',
		),
		'agent'
	);
	aicfab_live(
		is_wp_error( $aicfab_refused ) && 'aicfab_metric_restricted' === $aicfab_refused->get_error_code(),
		'figures about visitors\' questions are kept from agents',
		is_wp_error( $aicfab_refused ) ? $aicfab_refused->get_error_code() : 'answered'
	);
	wp_set_current_user( 0 );
}

remove_filter( 'ai_chat_bedrock_metrics_enabled', '__return_true' );
remove_filter( 'ai_chat_bedrock_ontology_enabled', '__return_true' );

if ( false === $aicfab_had_abilities ) {
	delete_option( 'ai_chat_bedrock_site_abilities' );
} else {
	update_option( 'ai_chat_bedrock_site_abilities', $aicfab_had_abilities );
}

// --- Members-only blocks, through WordPress's own block parser -----------------
// The suite stubs the parser, so whether the rebuilt markup is what WordPress renders is asked here.

$aicfab_markup = '<!-- wp:paragraph --><p>Public intro.</p><!-- /wp:paragraph -->'
	. '<!-- wp:group {"layout":{"type":"constrained"}} --><div class="wp-block-group">'
	. '<!-- wp:paragraph --><p>Public note.</p><!-- /wp:paragraph -->'
	. '<!-- wp:group {"blockVisibility":{"controlSets":[{"id":1,"enable":true,"controls":{"userRole":{"visibilityByRole":"logged-in"}}}]},"className":"members"} --><div class="wp-block-group members">'
	. '<!-- wp:paragraph --><p>Members only.</p><!-- /wp:paragraph --></div><!-- /wp:group -->'
	. '<!-- wp:paragraph --><p>Public outro.</p><!-- /wp:paragraph --></div><!-- /wp:group -->';
$aicfab_rendered = do_blocks( AI_Chat_Bedrock_Content::without_restricted_blocks( $aicfab_markup ) );
aicfab_live(
	false === strpos( $aicfab_rendered, 'Members only' ) && false !== strpos( $aicfab_rendered, 'Public note' ) && false !== strpos( $aicfab_rendered, 'Public outro' ) && false === strpos( $aicfab_rendered, 'members' ),
	'a members-only block is removed and the rest renders',
	AI_Chat_Bedrock_Content::flatten( wp_strip_all_tags( $aicfab_rendered ) )
);

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

/*
 * The bundled translations, through WordPress's own just-in-time loading. The suite calls the
 * filter directly, so whether WordPress asks it, and then reads the file it points at, is asked here.
 */
if ( version_compare( get_bloginfo( 'version' ), '6.6', '<' ) ) {
	aicfab_note( 'skipped: WordPress before 6.6 reads translations from language packs only' );
} else {
	$aicfab_locale = static function () {
		return 'ja';
	};
	add_filter( 'determine_locale', $aicfab_locale );
	unload_textdomain( 'ai-chat-for-amazon-bedrock', true );
	$aicfab_send = __( 'Send', 'ai-chat-for-amazon-bedrock' );
	remove_filter( 'determine_locale', $aicfab_locale );
	unload_textdomain( 'ai-chat-for-amazon-bedrock', true );
	aicfab_live( '送信' === $aicfab_send, 'a Japanese page reads the chat in the bundled Japanese', $aicfab_send );
}

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

	/*
	 * Reported in enough detail to tell a partial install from a moved file. A CI run reached a
	 * state where the autoloader was on disk and this interface did not resolve, and answering why
	 * cost a day of guessing that a file count would have settled.
	 */
	$aicfab_lib  = ABSPATH . WPINC . '/php-ai-client';
	$aicfab_path = $aicfab_lib . '/src/Providers/Models/TextGeneration/Contracts/TextGenerationModelInterface.php';
	$aicfab_count = 0;
	if ( is_dir( $aicfab_lib . '/src' ) ) {
		$aicfab_iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $aicfab_lib . '/src' ) );
		foreach ( $aicfab_iterator as $aicfab_file ) {
			if ( $aicfab_file->isFile() && 'php' === strtolower( $aicfab_file->getExtension() ) ) {
				++$aicfab_count;
			}
		}
	}
	aicfab_note(
		sprintf(
			'AI Client: usable=%s, interface resolves=%s, its file on disk=%s, src/ holds %d php files, autoloader=%s, wp=%s',
			$aicfab_usable ? 'yes' : 'no',
			interface_exists( $aicfab_iface ) ? 'yes' : 'no',
			file_exists( $aicfab_path ) ? 'yes' : 'no',
			$aicfab_count,
			file_exists( $aicfab_lib . '/autoload.php' ) ? 'present' : 'absent',
			get_bloginfo( 'version' )
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
	$aicfab_connector = wp_get_connector( 'amazon-bedrock' );
	aicfab_live( isset( $aicfab_connector['authentication']['method'] ) && 'api_key' === $aicfab_connector['authentication']['method'], 'the connector offers a key, so Settings > Connectors shows it' );
	aicfab_live( isset( get_registered_settings()[ AI_Chat_Bedrock_Core_AI::CONNECTOR_SETTING ] ), 'core registered the setting the key is saved in' );
	// Saving goes through core's check, which hands the key to the provider; this must not throw.
	if ( class_exists( '\WordPress\AiClient\AiClient' ) && function_exists( '_wp_connectors_is_ai_api_key_valid' ) ) {
		$aicfab_verdict = _wp_connectors_is_ai_api_key_valid( 'not-a-bedrock-key-but-long-enough', 'amazon-bedrock' );
		aicfab_live( false === $aicfab_verdict, 'core asks Bedrock about a pasted key, and a bad one is refused', var_export( $aicfab_verdict, true ) );
	}
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

<?php
/**
 * Standalone tests for the optional fixes to other plugins.
 *
 * Run: php tests/integrations.php
 *
 * @package AI_Chat_Bedrock
 */

define( 'ABSPATH', __DIR__ . '/' );

$GLOBALS['aicfab_options'] = array();
$GLOBALS['aicfab_filters'] = array();
$GLOBALS['aicfab_actions'] = array();

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['aicfab_options'] ) ? $GLOBALS['aicfab_options'][ $name ] : $default;
}
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['aicfab_filters'][ $hook ][] = $callback;
}
function add_action( $hook, $callback, $priority = 10, $args = 1 ) {
	$GLOBALS['aicfab_actions'][ $hook ][] = $callback;
}
function apply_filters( $hook, $value ) {
	foreach ( isset( $GLOBALS['aicfab_filters'][ $hook ] ) ? $GLOBALS['aicfab_filters'][ $hook ] : array() as $callback ) {
		$value = $callback( $value );
	}
	return $value;
}
function __( $text, $domain = null ) {
	return $text;
}
function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}
function pll_languages_list( $args = array() ) {
	return 'name' === $args['fields'] ? array( '中文', 'English', '日本語' ) : array( 'zh', 'en', 'ja' );
}

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-integrations.php';

$failures = array();
function check_int( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

// --- Nothing happens until switched on -------------------------------------------

AI_Chat_Bedrock_Integrations::init();
check_int( array() === $GLOBALS['aicfab_filters'] && array() === $GLOBALS['aicfab_actions'], 'No hook is added while every switch is off.' );

$GLOBALS['aicfab_options']['ai_chat_bedrock_settings'] = array(
	'github_read_scope'        => true,
	'social_only_registration' => true,
	'hreflang_x_default'       => 'en',
	'organization_author'      => true,
);
AI_Chat_Bedrock_Integrations::init();
foreach ( array( 'wp_redirect', 'register', 'pll_rel_hreflang_attributes', 'wpseo_schema_article', 'wpseo_schema_author', 'wpseo_meta_author', 'wpseo_enhanced_slack_data' ) as $hook ) {
	check_int( ! empty( $GLOBALS['aicfab_filters'][ $hook ] ), 'The switch adds ' . $hook . '.' );
}
check_int( ! empty( $GLOBALS['aicfab_actions']['login_form_register'] ), 'The switch adds login_form_register.' );
$GLOBALS['aicfab_filters'] = array();

$detected = AI_Chat_Bedrock_Integrations::detected();
check_int( true === $detected['polylang'] && false === $detected['fluentauth'] && false === $detected['yoast'], 'Active plugins are detected.' );
check_int( array( 'zh' => '中文', 'en' => 'English', 'ja' => '日本語' ) === AI_Chat_Bedrock_Integrations::languages(), 'Polylang languages are listed by slug.' );

// --- GitHub scope ------------------------------------------------------------------

$authorize = 'https://github.com/login/oauth/authorize?client_id=abc&scope=user&state=xyz';
check_int( 'https://github.com/login/oauth/authorize?client_id=abc&scope=read:user%20user:email&state=xyz' === AI_Chat_Bedrock_Integrations::github_scope( $authorize ), 'The user scope becomes read-only.' );
check_int( 'https://github.com/login/oauth/authorize?scope=read:user%20user:email' === AI_Chat_Bedrock_Integrations::github_scope( 'https://github.com/login/oauth/authorize?scope=user' ), 'A trailing scope is narrowed.' );
$other = 'https://github.com/login/oauth/authorize?scope=user:email&client_id=abc';
check_int( $other === AI_Chat_Bedrock_Integrations::github_scope( $other ), 'A scope that is already narrower is left alone.' );
$elsewhere = 'https://example.test/?scope=user';
check_int( $elsewhere === AI_Chat_Bedrock_Integrations::github_scope( $elsewhere ), 'Other redirects are untouched.' );
$lookalike = 'https://github.com.evil.test/login/oauth/authorize?scope=user';
check_int( $lookalike === AI_Chat_Bedrock_Integrations::github_scope( $lookalike ), 'A look-alike host is untouched.' );

// --- Registration --------------------------------------------------------------------

$link = '<a href="/wp-login.php?action=register">Register</a>';
check_int( $link === AI_Chat_Bedrock_Integrations::hide_register_link( $link ), 'The link stays while no social login plugin is active.' );
add_filter(
	'ai_chat_bedrock_social_login_active',
	function () {
		return true;
	}
);
check_int( '' === AI_Chat_Bedrock_Integrations::hide_register_link( $link ), 'The link goes once a social login plugin is active.' );
$GLOBALS['aicfab_filters'] = array();

// --- hreflang x-default --------------------------------------------------------------

$alternates = array(
	'zh-CN' => 'https://example.test/',
	'en-US' => 'https://example.test/en/',
	'ja'    => 'https://example.test/ja/',
);
$result = AI_Chat_Bedrock_Integrations::hreflang_x_default( $alternates );
check_int( 'https://example.test/en/' === $result['x-default'], 'x-default points at the chosen language, matched by locale prefix.' );
$GLOBALS['aicfab_options']['ai_chat_bedrock_settings']['hreflang_x_default'] = 'ja';
$result = AI_Chat_Bedrock_Integrations::hreflang_x_default( $alternates );
check_int( 'https://example.test/ja/' === $result['x-default'], 'x-default matches an exact code.' );
$existing = array_merge( $alternates, array( 'x-default' => 'https://example.test/zh/' ) );
check_int( 'https://example.test/zh/' === AI_Chat_Bedrock_Integrations::hreflang_x_default( $existing )['x-default'], 'An x-default set elsewhere is kept.' );
$GLOBALS['aicfab_options']['ai_chat_bedrock_settings']['hreflang_x_default'] = 'fr';
check_int( ! isset( AI_Chat_Bedrock_Integrations::hreflang_x_default( $alternates )['x-default'] ), 'A page without the chosen language gets no x-default.' );
$GLOBALS['aicfab_options']['ai_chat_bedrock_settings']['hreflang_x_default'] = 'e';
check_int( ! isset( AI_Chat_Bedrock_Integrations::hreflang_x_default( array( 'es' => 'https://example.test/es/' ) )['x-default'] ), 'A prefix only matches before a hyphen.' );

// --- Organization as author ----------------------------------------------------------

$company = (object) array(
	'site_represents' => 'company',
	'company_name'    => 'OneAI',
	'site_url'        => 'https://example.test/',
);
$person  = (object) array(
	'site_represents' => 'person',
	'company_name'    => '',
	'site_url'        => 'https://example.test/',
);

$article = AI_Chat_Bedrock_Integrations::article_author( array( 'author' => array( '@id' => '#person' ) ), $company );
check_int( array( '@id' => 'https://example.test/#organization', 'name' => 'OneAI' ) === $article['author'], 'The article is credited to the organization.' );
check_int( array( 'author' => array( '@id' => '#person' ) ) === AI_Chat_Bedrock_Integrations::article_author( array( 'author' => array( '@id' => '#person' ) ), $person ), 'A person site keeps its author.' );
check_int( false === AI_Chat_Bedrock_Integrations::drop_person( array( '@type' => 'Person' ), $company ), 'The Person piece is dropped for an organization.' );
check_int( array( '@type' => 'Person' ) === AI_Chat_Bedrock_Integrations::drop_person( array( '@type' => 'Person' ), $person ), 'The Person piece stays for a person site.' );
check_int( 'OneAI' === AI_Chat_Bedrock_Integrations::meta_author( 'admin', (object) array( 'context' => $company ) ), 'The author meta names the organization.' );
check_int( 'admin' === AI_Chat_Bedrock_Integrations::meta_author( 'admin', (object) array() ), 'Without a context the author meta is unchanged.' );
$slack = AI_Chat_Bedrock_Integrations::slack_author( array( 'Written by' => 'admin', 'Est. reading time' => '2 minutes' ), (object) array( 'context' => $company ) );
check_int( 'OneAI' === $slack['Written by'] && '2 minutes' === $slack['Est. reading time'], 'The Slack preview names the organization and keeps the rest.' );
$blank = (object) array(
	'site_represents' => 'company',
	'company_name'    => '  ',
	'site_url'        => 'https://example.test/',
);
check_int( 'admin' === AI_Chat_Bedrock_Integrations::meta_author( 'admin', (object) array( 'context' => $blank ) ), 'An organization without a name changes nothing.' );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: integration checks passed\n";

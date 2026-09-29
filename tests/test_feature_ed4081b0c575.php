<?php
/**
 * Behavioral tests: the content gaps period (7, 30 or 90 days) on the Conversations screen.
 *
 * The real conversations partial is rendered against stubbed WordPress functions, with
 * exchanges seeded through the real conversation log option, and read as a document.
 * All seeded exchanges are synthetic.
 *
 * Run: php tests/test_feature_ed4081b0c575.php
 */

define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['aicfab_options'] = array();

function get_option( $name, $default = false ) {
	return array_key_exists( $name, $GLOBALS['aicfab_options'] ) ? $GLOBALS['aicfab_options'][ $name ] : $default;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['aicfab_options'][ $name ] = $value;
	return true;
}
function delete_option( $name ) {
	unset( $GLOBALS['aicfab_options'][ $name ] );
	return true;
}
function get_current_user_id() { return 0; }
function sanitize_key( $key ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) ); }
function sanitize_text_field( $value ) { return trim( preg_replace( '/<[^>]*>/', '', (string) $value ) ); }
function wp_strip_all_tags( $value ) { return preg_replace( '/<[^>]*>/', '', (string) $value ); }
function absint( $value ) { return abs( (int) $value ); }
function apply_filters( $hook, $value ) { return $value; }
function __( $text, $domain = null ) { return $text; }
function _n( $single, $plural, $number, $domain = null ) { return 1 === (int) $number ? $single : $plural; }
function esc_html( $text ) { return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' ); }
function esc_attr( $text ) { return esc_html( $text ); }
function esc_url( $text ) { return esc_html( $text ); }
function esc_html__( $text, $domain = null ) { return esc_html( $text ); }
function esc_html_e( $text, $domain = null ) { echo esc_html( $text ); }
function esc_attr_e( $text, $domain = null ) { echo esc_attr( $text ); }
function wp_unslash( $value ) { return $value; }
function number_format_i18n( $number ) { return number_format( (float) $number ); }
function current_user_can( $capability ) { return in_array( $capability, array( 'manage_options', 'edit_posts' ), true ); }
function admin_url( $path = '' ) { return 'https://example.test/wp-admin/' . ltrim( (string) $path, '/' ); }
function add_query_arg( $key, $value = null, $url = null ) {
	// Like WordPress, values are inserted as given, without encoding.
	if ( is_array( $key ) ) {
		$args = $key;
		$url  = $value;
	} else {
		$args = array( $key => $value );
	}
	$pairs = array();
	foreach ( $args as $name => $item ) {
		$pairs[] = $name . '=' . $item;
	}
	if ( empty( $pairs ) ) {
		return $url;
	}
	return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . implode( '&', $pairs );
}
function get_admin_page_title() { return 'Conversations'; }
function selected( $selected, $current = true, $echo = true ) {
	$result = (string) $selected === (string) $current ? " selected='selected'" : '';
	if ( $echo ) {
		echo $result; // phpcs:ignore
	}
	return $result;
}
function wp_nonce_field( $action ) { echo '<input type="hidden" name="_wpnonce" value="' . esc_attr( 'nonce-' . $action ) . '">'; }
function wp_kses_post( $html ) { return $html; }
function paginate_links( $args ) {
	$out = '';
	for ( $i = 1; $i <= (int) $args['total']; $i++ ) {
		if ( $i === (int) $args['current'] ) {
			$out .= '<span aria-current="page" class="page-numbers current">' . $i . '</span>';
		} else {
			$out .= '<a class="page-numbers" href="' . esc_url( str_replace( '%#%', (string) $i, $args['base'] ) ) . '">' . $i . '</a>';
		}
	}
	return $out;
}
function wp_date( $format, $timestamp = null ) { return gmdate( $format, null === $timestamp ? time() : (int) $timestamp ); }
function get_userdata( $id ) { return false; }
function wp_trim_words( $text, $words = 55 ) { return (string) $text; }
function wp_json_encode( $value ) { return json_encode( $value ); }

class AI_Chat_Bedrock_Security {
	public static function string_substr( $value, $start, $length ) {
		return function_exists( 'mb_substr' ) ? mb_substr( (string) $value, $start, $length, 'UTF-8' ) : substr( (string) $value, $start, $length );
	}
}

require_once dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-conversations.php';
require_once dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-insights.php';

$failures = array();
function check_period( $condition, $label ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $label;
	}
}

if ( ! method_exists( 'AI_Chat_Bedrock_Insights', 'gap_window' ) || ! method_exists( 'AI_Chat_Bedrock_Insights', 'export_filename' ) ) {
	echo "FAIL: the content gaps period is not implemented (gap_window/export_filename missing).\n";
	exit( 1 );
}

// Validation: only 7, 30 or 90 as an int or an all-digit string; everything else is 30.
foreach ( array( 7, 30, 90, '7', '30', '90' ) as $accepted ) {
	check_period( (int) $accepted === AI_Chat_Bedrock_Insights::gap_window( $accepted ), 'gap_window accepts ' . var_export( $accepted, true ) );
}
foreach ( array( null, '', 0, -7, '-7', '7abc', '90.5', ' 90', "7\n", "90\n", 365, '30abc', 'abc', array( 7 ), 7.0, true ) as $rejected ) {
	check_period( 30 === AI_Chat_Bedrock_Insights::gap_window( $rejected ), 'gap_window falls back to 30 for ' . str_replace( "\n", '\\n', var_export( $rejected, true ) ) );
}

/**
 * Replace the stored log with synthetic exchanges.
 *
 * @param array $rows Each: question, age in days, grounded flag.
 */
function seed_log( $rows ) {
	$entries = array();
	foreach ( array_values( $rows ) as $index => $row ) {
		$entries[] = array(
			'id'            => str_pad( dechex( $index + 1 ), 24, '0', STR_PAD_LEFT ),
			'time'          => time() - (int) round( $row[1] * DAY_IN_SECONDS ),
			'user'          => 0,
			'source'        => 'chat',
			'model'         => 'synthetic-model',
			'question'      => $row[0],
			'answer'        => 'Synthetic answer.',
			'input_tokens'  => 10,
			'output_tokens' => 20,
			'rating'        => 0,
			'rating_time'   => 0,
			'grounded'      => $row[2] ? 1 : 0,
		);
	}
	update_option( AI_Chat_Bedrock_Conversations::OPTION_LOG, $entries );
}

update_option( AI_Chat_Bedrock_Conversations::OPTION_ENABLED, true );
update_option( 'date_format', 'Y-m-d' );
update_option( 'time_format', 'H:i' );

// Windows: exchanges aged 3, 20 and 60 days.
seed_log(
	array(
		array( 'Gripper torque limits for humanoid hands', 3, false ),
		array( 'Lidar calibration drift outdoors', 20, false ),
		array( 'Sim to real transfer benchmark results', 60, false ),
	)
);
foreach ( array( 7 => 1, 30 => 2, 90 => 3 ) as $days => $expected ) {
	$gaps    = AI_Chat_Bedrock_Insights::content_gaps( array( 'days' => $days ) );
	$summary = AI_Chat_Bedrock_Insights::summary( $days );
	check_period( $expected === count( $gaps ), "{$days} days reports {$expected} gaps" );
	check_period( $expected === (int) $summary['asked'] && $expected === (int) $summary['ungrounded'], "{$days} days summarises {$expected} questions" );
}
$rows = AI_Chat_Bedrock_Insights::export_rows( array( 'days' => 7 ) );
check_period( 2 === count( $rows ), 'a 7-day export has the header and exactly one row' );
check_period( array( 'question', 'occurrences', 'last_asked_utc', 'grounded_count', 'ungrounded_count' ) === $rows[0], 'the export header is unchanged' );
check_period( isset( $rows[1][0] ) && 'Gripper torque limits for humanoid hands' === $rows[1][0], 'the 7-day export row is the 3-day-old question' );

// File names at a fixed time.
$fixed = 1700000000;
$stamp = gmdate( 'Ymd-His', $fixed );
check_period( 'ai-chat-bedrock-content-gaps-7d-' . $stamp . '.csv' === AI_Chat_Bedrock_Insights::export_filename( 7, $fixed ), 'a 7-day file is named -7d-' );
check_period( 'ai-chat-bedrock-content-gaps-90d-' . $stamp . '.csv' === AI_Chat_Bedrock_Insights::export_filename( '90', $fixed ), 'a 90-day file is named -90d-' );
foreach ( array( 30, -7, "7\n", null ) as $legacy ) {
	check_period( 'ai-chat-bedrock-content-gaps-' . $stamp . '.csv' === AI_Chat_Bedrock_Insights::export_filename( $legacy, $fixed ), 'the legacy file name is kept for ' . str_replace( "\n", '\\n', var_export( $legacy, true ) ) );
}

/**
 * Render the real conversations partial for a request.
 *
 * @param array $get Query parameters.
 * @return array Raw HTML and an XPath over it.
 */
function render_conversations( $get ) {
	$_GET = $get;
	ob_start();
	include dirname( __DIR__ ) . '/admin/partials/ai-chat-bedrock-admin-conversations.php';
	$html     = ob_get_clean();
	$document = new DOMDocument();
	$previous = libxml_use_internal_errors( true );
	$document->loadHTML( '<?xml encoding="utf-8"?>' . $html );
	libxml_clear_errors();
	libxml_use_internal_errors( $previous );
	return array( $html, new DOMXPath( $document ) );
}

/**
 * Attribute values of every node an XPath query finds.
 */
function attrs( $xpath, $query, $name ) {
	$values = array();
	foreach ( $xpath->query( $query ) as $node ) {
		$values[] = $node->getAttribute( $name );
	}
	return $values;
}

$page_base   = 'https://example.test/wp-admin/admin.php?page=ai-chat-for-amazon-bedrock-conversations';
$period_form = '//form[contains(@class,"aicfab-gap-period")]';
$csv_form    = '//form[contains(@class,"aicfab-gap-export")]';
$log_form    = '//form[contains(@class,"aicfab-log-filters")]';
$page_links  = '//div[contains(@class,"tablenav-pages")]//a';
$reset_link  = $log_form . '/a[contains(@class,"button-link")]';

$robots = array();
for ( $i = 1; $i <= 25; $i++ ) {
	$robots[] = array( "Robot arm model {$i} calibration steps", 1, false );
}

// Case one: a 7-day period with a log search.
seed_log( $robots );
list( $html, $xpath ) = render_conversations( array( 'aicfab_gap_days' => '7', 'aicfab_s' => 'robot' ) );
check_period( 1 === $xpath->query( $period_form )->length, 'case one: the period form is wrapped in aicfab-gap-period' );
check_period( 1 === $xpath->query( $period_form . '/label[@for="aicfab-gap-days"]' )->length, 'case one: the Period select has a visible label' );
check_period( 1 === $xpath->query( $period_form . '//select[@id="aicfab-gap-days" and @name="aicfab_gap_days"]/option[@value="7" and @selected]' )->length, 'case one: Last 7 days is selected' );
check_period( 3 === $xpath->query( '//select[@id="aicfab-gap-days"]/option' )->length, 'case one: three periods are offered' );
check_period( 1 === $xpath->query( $period_form . '//button[@type="submit" and contains(@class,"button")]' )->length, 'case one: the period form has a Show button' );
check_period( 1 === $xpath->query( $period_form . '/input[@type="hidden" and @name="aicfab_s" and @value="robot"]' )->length, 'case one: the period form keeps the log search' );
check_period( 0 === $xpath->query( $period_form . '/input[@name="paged"]' )->length, 'case one: the period form does not carry the page' );
check_period( false !== strpos( $html, 'in the last 7 days' ), 'case one: the summary names 7 days' );
check_period( 1 === $xpath->query( $csv_form . '/input[@type="hidden" and @name="aicfab_gap_days" and @value="7"]' )->length, 'case one: the CSV form downloads 7 days' );
check_period( 1 === $xpath->query( $log_form . '/input[@type="hidden" and @name="aicfab_gap_days" and @value="7"]' )->length, 'case one: the log filter keeps the period' );
$links = attrs( $xpath, $page_links, 'href' );
check_period( count( $links ) > 0, 'case one: pagination renders' );
foreach ( $links as $href ) {
	check_period( false !== strpos( $href, 'aicfab_gap_days=7' ) && false !== strpos( $href, 'aicfab_s=robot' ), 'case one: a page link keeps the period and search: ' . $href );
}
$reset = attrs( $xpath, $reset_link, 'href' );
check_period( array( $page_base . '&aicfab_gap_days=7' ) === $reset, 'case one: Reset clears the search but keeps the period' );

// Case two: an invalid period falls back to 30 and leaves the log URLs as in 1.47.6.
list( $html, $xpath ) = render_conversations( array( 'aicfab_gap_days' => '-7', 'aicfab_s' => 'robot' ) );
check_period( 1 === $xpath->query( '//select[@id="aicfab-gap-days"]/option[@value="30" and @selected]' )->length, 'case two: Last 30 days is selected' );
check_period( 0 === $xpath->query( '//select[@id="aicfab-gap-days"]/option[@value="7" and @selected]' )->length, 'case two: Last 7 days is not selected' );
check_period( false !== strpos( $html, 'in the last 30 days' ), 'case two: the summary names 30 days' );
check_period( 1 === $xpath->query( $csv_form . '/input[@name="aicfab_gap_days" and @value="30"]' )->length, 'case two: the CSV form downloads 30 days' );
check_period( 0 === $xpath->query( $log_form . '/input[@name="aicfab_gap_days"]' )->length, 'case two: the log filter has no period' );
$links = attrs( $xpath, $page_links, 'href' );
check_period( count( $links ) > 0, 'case two: pagination renders' );
foreach ( $links as $href ) {
	check_period( false !== strpos( $href, 'aicfab_s=robot' ) && false === strpos( $href, 'aicfab_gap_days' ), 'case two: a page link keeps the search without a period: ' . $href );
}
check_period( array( $page_base ) === attrs( $xpath, $reset_link, 'href' ), 'case two: Reset is exactly the page base' );

// Case three: nothing to report names the chosen period, and no empty download is offered.
seed_log(
	array(
		array( 'Gripper torque limits for humanoid hands', 1, true ),
		array( 'Lidar calibration drift outdoors', 2, true ),
	)
);
list( $html, $xpath ) = render_conversations( array( 'aicfab_gap_days' => '7' ) );
check_period( false !== strpos( $html, 'Nothing to report yet for the last 7 days.' ), 'case three: the empty state names 7 days' );
check_period( 0 === $xpath->query( $csv_form )->length, 'case three: no CSV form renders' );
check_period( 1 === $xpath->query( $period_form )->length, 'case three: the period control is still offered' );

// The export handler checks capability and nonce before reading the period.
$admin   = (string) file_get_contents( dirname( __DIR__ ) . '/admin/class-ai-chat-bedrock-admin.php' );
$partial = (string) file_get_contents( dirname( __DIR__ ) . '/admin/partials/ai-chat-bedrock-admin-conversations.php' );
$start   = strpos( $admin, 'function handle_export_gaps' );
$body    = false === $start ? '' : substr( $admin, $start, (int) strpos( $admin, 'public function', $start + 10 ) - $start );
$cap     = strpos( $body, 'current_user_can' );
$nonce   = strpos( $body, 'check_admin_referer' );
$read    = strpos( $body, 'aicfab_gap_days' );
check_period( false !== $cap && false !== $nonce && false !== $read && $cap < $nonce && $nonce < $read, 'the export checks capability and nonce before reading the period' );
check_period( false !== strpos( $body, 'gap_window(' ) && false !== strpos( $body, 'export_filename(' ), 'the export validates the period and names the file from it' );
foreach ( array( 'admin class' => $admin, 'partial' => $partial ) as $label => $source ) {
	check_period( ! preg_match( '/absint\s*\([^;]*aicfab_gap_days/', $source ), "the {$label} does not absint the period" );
}

// Release metadata and documentation.
$main   = (string) file_get_contents( dirname( __DIR__ ) . '/ai-chat-for-amazon-bedrock.php' );
$readme = (string) file_get_contents( dirname( __DIR__ ) . '/readme.txt' );
$docs   = (string) file_get_contents( dirname( __DIR__ ) . '/docs/content-gap-export.md' );
check_period( 1 === preg_match( '/^ \* Version: 1\.48\.0$/m', $main ), 'the plugin header is 1.48.0' );
check_period( false !== strpos( $main, "define( 'AI_CHAT_BEDROCK_VERSION', '1.48.0' );" ), 'the version constant is 1.48.0' );
check_period( 1 === preg_match( '/^Stable tag: 1\.48\.0$/m', $readme ), 'the readme stable tag is 1.48.0' );
$changelog = strpos( $readme, '== Changelog ==' );
check_period( false !== $changelog && false !== strpos( $readme, '= 1.48.0 =', $changelog ), 'the changelog has 1.48.0' );
$notice = strpos( $readme, '== Upgrade Notice ==' );
$match  = array();
check_period( false !== $notice && 1 === preg_match( '/= 1\.48\.0 =\s*\n(.+?)(?:\n\s*\n|\n=|\z)/s', substr( $readme, (int) $notice ), $match ) && '' !== trim( $match[1] ) && strlen( trim( $match[1] ) ) < 300, 'the 1.48.0 upgrade notice exists and is under 300 characters' );
check_period( false !== strpos( $docs, 'Period' ) && false !== strpos( $docs, '-7d-' ) && false !== strpos( $docs, '-90d-' ), 'the docs describe the control and file names' );

if ( $failures ) {
	echo 'FAIL (' . count( $failures ) . "):\n - " . implode( "\n - ", $failures ) . "\n";
	exit( 1 );
}
echo "OK: content gaps period\n";

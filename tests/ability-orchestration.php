<?php
/**
 * Which abilities of other plugins reach the model, and how an administrator chooses.
 *
 * The list used to be cut at twenty in registration order, so on a site where WooCommerce
 * registered first nothing from a plugin after it was ever offered, and an ability that changes
 * data could not be allowed at all: the tool policy form listed only MCP tools and rewrote the
 * whole policy from them on every save.
 *
 * @package AI_Chat_Bedrock
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.PHP.DevelopmentFunctions, Squiz.Commenting, Generic.Commenting, WordPress.NamingConventions.PrefixAllGlobals, WordPress.WP.GlobalVariablesOverride

define( 'ABSPATH', __DIR__ );

$failures = array();
function check_or( $condition, $message ) {
	global $failures;
	if ( ! $condition ) {
		$failures[] = $message;
	}
}

$GLOBALS['aicfab_caps']       = array( 'edit_posts' => true, 'manage_options' => true );
$GLOBALS['aicfab_abilities']  = array();
$GLOBALS['aicfab_categories'] = array();
$GLOBALS['aicfab_opts']       = array(
	'ai_chat_bedrock_settings'        => array( 'abilities_tools' => true ),
	'ai_chat_bedrock_enable_mcp'      => true,
	'ai_chat_bedrock_mcp_capability'  => 'edit_posts',
	'ai_chat_bedrock_mcp_tool_policy' => array(),
);

function current_user_can( $capability ) {
	return ! empty( $GLOBALS['aicfab_caps'][ $capability ] );
}
function get_option( $name, $default_value = false ) {
	return array_key_exists( $name, $GLOBALS['aicfab_opts'] ) ? $GLOBALS['aicfab_opts'][ $name ] : $default_value;
}
function update_option( $name, $value, $autoload = null ) {
	$GLOBALS['aicfab_opts'][ $name ] = $value;
	return true;
}
function is_user_logged_in() {
	return true;
}
function wp_register_ability( $id, $args = array() ) {
	return true;
}
function wp_get_abilities() {
	return $GLOBALS['aicfab_abilities'];
}
function wp_get_ability_category( $slug ) {
	return isset( $GLOBALS['aicfab_categories'][ $slug ] ) ? $GLOBALS['aicfab_categories'][ $slug ] : null;
}
function sanitize_key( $value ) {
	return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
}
function wp_generate_uuid4() {
	return '00000000-0000-4000-8000-000000000000';
}
function wp_json_encode( $value, $flags = 0 ) {
	return json_encode( $value, $flags );
}
function wp_list_pluck( $items, $field ) {
	return array_map(
		function ( $item ) use ( $field ) {
			return $item[ $field ];
		},
		(array) $items
	);
}
function __( $text, $domain = null ) {
	return $text;
}
function is_wp_error( $thing ) {
	return $thing instanceof WP_Error;
}
$GLOBALS['aicfab_filters'] = array();
function apply_filters( $hook, $value, ...$args ) {
	return isset( $GLOBALS['aicfab_filters'][ $hook ] ) ? call_user_func( $GLOBALS['aicfab_filters'][ $hook ], $value, ...$args ) : $value;
}

class WP_Error {
	public $code;
	public function __construct( $code = '', $message = '' ) {
		$this->code = $code;
	}
	public function get_error_code() {
		return $this->code;
	}
	public function get_error_message() {
		return '';
	}
}

class Aicfab_Category {
	public $label;
	public function __construct( $label ) {
		$this->label = $label;
	}
	public function get_label() {
		return $this->label;
	}
}

class Aicfab_Ability {
	public $name;
	public $label;
	public $description;
	public $category;
	public $meta;
	public $ran = false;
	public function __construct( $name, $label, $description, $readonly = true, $category = '' ) {
		$this->name        = $name;
		$this->label       = $label;
		$this->description = $description;
		$this->category    = '' !== $category ? $category : explode( '/', $name )[0];
		$this->meta        = array( 'annotations' => array( 'readonly' => $readonly, 'destructive' => false ) );
	}
	public function get_name() {
		return $this->name;
	}
	public function get_label() {
		return $this->label;
	}
	public function get_description() {
		return $this->description;
	}
	public function get_category() {
		return $this->category;
	}
	public function get_meta() {
		return $this->meta;
	}
	public function get_input_schema() {
		return array( 'type' => 'object' );
	}
	public function has_permission() {
		return true;
	}
	public function execute( $parameters ) {
		$this->ran = true;
		return 'ran';
	}
}

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-security.php';
require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-tool-policy.php';

class AI_Chat_Bedrock_Tool_Log {
	public static function record( $entry ) {
	}
}

require dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-abilities.php';

$abilities = new AI_Chat_Bedrock_Abilities();
function aicfab_names( $tools ) {
	return array_map(
		function ( $tool ) {
			return $tool['name'];
		},
		$tools
	);
}

// --- A plugin registered first no longer crowds out the rest ------------------

$GLOBALS['aicfab_abilities'] = array();
for ( $i = 0; $i < 25; $i++ ) {
	$GLOBALS['aicfab_abilities'][] = new Aicfab_Ability( 'shop/thing-' . $i, 'Thing ' . $i, 'Read shop thing ' . $i );
}
$GLOBALS['aicfab_abilities'][] = new Aicfab_Ability( 'forms/list-entries', 'List form entries', 'Read submitted form entries' );
$GLOBALS['aicfab_abilities'][] = new Aicfab_Ability( 'seo/get-score', 'Get SEO score', 'Read the SEO score of a post' );

$names = aicfab_names( $abilities->available_ability_tools() );
check_or( AI_Chat_Bedrock_Abilities::MAX_TOOLS === count( $names ), 'the list is still capped, got ' . count( $names ) );
check_or( in_array( 'wpability___forms__list_entries', $names, true ), 'a plugin registered after a large one is offered' );
check_or( in_array( 'wpability___seo__get_score', $names, true ), 'every plugin gets a place before any gets a second' );
check_or( 'wpability___shop__thing_0' === $names[0], 'within a plugin, registration order is kept' );

// --- The question decides which tools come first ------------------------------

$GLOBALS['aicfab_abilities'] = array();
for ( $i = 0; $i < 25; $i++ ) {
	$GLOBALS['aicfab_abilities'][] = new Aicfab_Ability( 'shop/report-' . $i, 'Report ' . $i, 'Sales report ' . $i );
}
$GLOBALS['aicfab_abilities'][] = new Aicfab_Ability( 'shop/orders-query', 'Query orders', 'Find orders by status and date' );
$GLOBALS['aicfab_abilities'][] = new Aicfab_Ability( 'crm/contacts-query', '查询联系人', '按标签查找联系人' );

$names = aicfab_names( $abilities->available_ability_tools( 'Which orders came in this week?' ) );
check_or( 'wpability___shop__orders_query' === $names[0], 'the ability that matches an English question comes first, got ' . $names[0] );

$names = aicfab_names( $abilities->available_ability_tools( '帮我找一下标签是 VIP 的联系人' ) );
check_or( 'wpability___crm__contacts_query' === $names[0], 'Chinese, which has no spaces, is matched too, got ' . $names[0] );

$terms = AI_Chat_Bedrock_Abilities::terms( 'Orders 订单状态 the' );
check_or( isset( $terms['order'] ), 'a plural matches its singular' );
check_or( isset( $terms['订单'], $terms['单状'], $terms['状态'] ), 'Chinese text is split into pairs of characters' );
check_or( ! isset( $terms['the'] ), 'filler words do not count as a match' );

// A site can offer fewer or more, within bounds.
$GLOBALS['aicfab_filters']['ai_chat_bedrock_ability_tool_limit'] = function () {
	return 500;
};
check_or( 27 === count( $abilities->available_ability_tools() ), 'a raised limit offers every eligible ability' );
$GLOBALS['aicfab_filters']['ai_chat_bedrock_ability_tool_limit'] = function () {
	return 3;
};
check_or( 3 === count( $abilities->available_ability_tools() ), 'a lowered limit is honoured' );
unset( $GLOBALS['aicfab_filters']['ai_chat_bedrock_ability_tool_limit'] );

// The work per request is bounded however many abilities a site registers.
$GLOBALS['aicfab_abilities'] = array();
for ( $i = 0; $i < AI_Chat_Bedrock_Abilities::MAX_SCAN + 5; $i++ ) {
	$GLOBALS['aicfab_abilities'][] = new Aicfab_Ability( 'bulk/item-' . $i, 'Item', 'Item' );
}
$GLOBALS['aicfab_abilities'][] = new Aicfab_Ability( 'late/orders-query', 'Query orders', 'Find orders' );
check_or( ! in_array( 'wpability___late__orders_query', aicfab_names( $abilities->available_ability_tools( 'orders' ) ), true ), 'abilities past the scan limit are not looked at' );

// --- A plugin can be switched off as a whole ----------------------------------

$shop                        = new Aicfab_Ability( 'shop/orders-query', 'Query orders', 'Find orders' );
$crm                         = new Aicfab_Ability( 'crm/contacts-query', 'Query contacts', 'Find contacts' );
$GLOBALS['aicfab_abilities'] = array( $shop, $crm );
check_or( true === AI_Chat_Bedrock_Abilities::source_enabled( 'shop' ), 'a plugin is on until switched off, including one installed later' );

AI_Chat_Bedrock_Abilities::save_disabled_sources( array( 'shop', 'shop', 'Bad Name!', '' ) );
check_or( array( 'shop', 'badname' ) === AI_Chat_Bedrock_Abilities::disabled_sources(), 'the switched-off list is cleaned and unique' );
$names = aicfab_names( $abilities->available_ability_tools() );
check_or( array( 'wpability___crm__contacts_query' ) === $names, 'a switched-off plugin offers nothing, got ' . implode( ',', $names ) );

$run = $abilities->execute_ability_tools( array( 'tool_calls' => array( array( 'id' => 'a', 'name' => 'wpability___shop__orders_query' ) ) ) );
check_or( ! $shop->ran && isset( $run['tool_calls'][0]['error'] ), 'a switched-off plugin cannot be run by naming its tool' );
AI_Chat_Bedrock_Abilities::save_disabled_sources( array() );
$run = $abilities->execute_ability_tools( array( 'tool_calls' => array( array( 'id' => 'b', 'name' => 'wpability___shop__orders_query' ) ) ) );
check_or( $shop->ran && 'ran' === $run['tool_calls'][0]['result'], 'switched back on, it runs' );

// --- The catalog the settings screens show -----------------------------------

$GLOBALS['aicfab_categories']['woocommerce'] = new Aicfab_Category( 'WooCommerce' );
$write                       = new Aicfab_Ability( 'woocommerce/product-update', 'Update product', 'Change a product', false );
$GLOBALS['aicfab_abilities'] = array(
	new Aicfab_Ability( 'woocommerce/products-query', 'Query products', 'Find products' ),
	$write,
	new Aicfab_Ability( 'core/get-site-info', 'Get Site Information', 'Site name', true, 'site' ),
	new Aicfab_Ability( 'fluent-forms/list-entries', 'List entries', 'Entries' ),
	new Aicfab_Ability( 'ai-chat-bedrock/search-content', 'Search', 'Own ability' ),
);
$catalog = $abilities->catalog();
check_or( array( 'fluent-forms', 'woocommerce', 'core' ) === array_keys( $catalog ), 'plugins are grouped and sorted by name, got ' . implode( ',', array_keys( $catalog ) ) );
check_or( 'WooCommerce' === $catalog['woocommerce']['label'], 'a plugin is named by the category it registered' );
check_or( 'WordPress' === $catalog['core']['label'], 'core abilities are WordPress' );
check_or( 'Fluent Forms' === $catalog['fluent-forms']['label'], 'otherwise the namespace is made readable' );
check_or( ! isset( $catalog['ai-chat-bedrock'] ), "the plugin's own abilities are not listed as another plugin's" );
check_or( true === $catalog['woocommerce']['abilities'][0]['allowed'], 'a read-only ability is allowed by default' );
check_or( false === $catalog['woocommerce']['abilities'][1]['allowed'] && false === $catalog['woocommerce']['abilities'][1]['readonly'], 'an ability that changes data is not' );
check_or( array( 'Fluent Forms', 'WooCommerce', 'WordPress' ) === $abilities->source_labels(), 'the names offered to the setup checklist' );

// --- Saving the policy form ---------------------------------------------------

$current = array(
	'offline___delete_all' => 'allow',
	'mcp___search'         => 'deny',
	'wpability___woocommerce__products_query' => 'deny',
);
$merged  = AI_Chat_Bedrock_Tool_Policy::merge_form(
	$current,
	array( 'mcp___search' ),
	array(
		'wpability___woocommerce__products_query' => true,
		'wpability___woocommerce__product_update' => false,
		'wpability___core__get_site_info'         => true,
	),
	array( 'mcp___search', 'wpability___woocommerce__products_query', 'wpability___woocommerce__product_update' )
);
check_or( isset( $merged['offline___delete_all'] ) && 'allow' === $merged['offline___delete_all'], 'a tool the form did not show keeps its decision' );
check_or( 'allow' === $merged['mcp___search'], 'an MCP tool is stored whichever way it goes' );
check_or( ! isset( $merged['wpability___woocommerce__products_query'] ), 'a read-only ability ticked again goes back to what it declares' );
check_or( 'allow' === $merged['wpability___woocommerce__product_update'], 'an ability that changes data can now be allowed' );
check_or( 'deny' === $merged['wpability___core__get_site_info'], 'a read-only ability can be refused' );

$GLOBALS['aicfab_opts']['ai_chat_bedrock_mcp_tool_policy'] = $merged;
$names = aicfab_names( $abilities->available_ability_tools() );
check_or( in_array( 'wpability___woocommerce__product_update', $names, true ), 'the allowed write is offered to an administrator' );
check_or( ! in_array( 'wpability___core__get_site_info', $names, true ), 'the refused read is not offered' );
$GLOBALS['aicfab_caps'] = array( 'edit_posts' => true );
check_or( ! in_array( 'wpability___woocommerce__product_update', aicfab_names( $abilities->available_ability_tools() ), true ), 'and never to anyone else' );
$GLOBALS['aicfab_caps'] = array( 'edit_posts' => true, 'manage_options' => true );

// The handler decides from the server's catalog and keeps the nonce and capability checks.
$handler = file_get_contents( dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-mcp-integration.php' );
$start   = strpos( $handler, 'function handle_save_tool_policy' );
$body    = substr( $handler, $start, strpos( $handler, "\n\t}\n", $start ) - $start );
check_or( false !== strpos( $body, "current_user_can( 'manage_options' )" ) && false !== strpos( $body, "check_admin_referer( 'ai_chat_bedrock_tool_policy' )" ), 'saving still needs an administrator and the nonce' );
check_or( false !== strpos( $body, 'merge_form(' ) && false !== strpos( $body, '->catalog()' ), 'the policy is merged, with defaults from the catalog rather than the form' );
check_or( false === strpos( $body, '$policy[ $name ]' ), 'the policy is no longer rebuilt from MCP tools alone' );

$form = file_get_contents( dirname( __DIR__ ) . '/admin/partials/ai-chat-bedrock-admin-mcp-tab.php' );
check_or( false !== strpos( $form, 'name="ability_shown[]"' ) && false !== strpos( $form, 'name="ability_sources_on[]"' ), 'the form lists abilities and plugin switches' );

// Removed and carried over with the rest of the configuration.
check_or( false !== strpos( file_get_contents( dirname( __DIR__ ) . '/uninstall.php' ), "'ai_chat_bedrock_ability_sources_off'" ), 'uninstall removes the plugin switches' );
check_or( false !== strpos( file_get_contents( dirname( __DIR__ ) . '/includes/class-ai-chat-bedrock-transfer.php' ), "'ai_chat_bedrock_ability_sources_off'" ), 'export and import carry the plugin switches' );

if ( $failures ) {
	fwrite( STDERR, "FAILED\n- " . implode( "\n- ", $failures ) . "\n" );
	exit( 1 );
}
echo "OK: ability orchestration checks passed\n";

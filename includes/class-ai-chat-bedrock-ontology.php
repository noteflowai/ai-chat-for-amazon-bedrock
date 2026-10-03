<?php
/**
 * A machine-readable description of the site: what kinds of things it holds, how they relate,
 * and which of them an AI may see.
 *
 * The vocabulary is schema.org, and entity IDs follow the ones Yoast SEO and WooCommerce
 * already print in the page markup, so a node described here is the same node a search
 * engine sees. Nothing is stored: the description is built from the live site on request.
 *
 * Every property carries a sensitivity class, and the classes come with a fixed policy for
 * each use. The policy states what the plugin already does, so an agent can read the rules
 * instead of discovering them by being refused.
 *
 * @package AI_Chat_Bedrock
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class AI_Chat_Bedrock_Ontology {

	const VERSION    = '1';
	const VOCABULARY = 'https://schema.org/';
	const MAX_TERMS  = 20;

	/**
	 * Sensitivity classes, from least to most restricted.
	 */
	const PUBLIC_DATA = 'public';
	const MEMBER      = 'member';
	const PERSONAL    = 'personal';
	const FINANCIAL   = 'financial';

	/**
	 * What each class allows, for each use.
	 *
	 * The uses:
	 *   index         Stored in the semantic index or a knowledge base.
	 *   visitor_chat  Sent to the model while answering a visitor.
	 *   agent         Returned to an agent through an ability or the MCP server.
	 *   analytics     Shown in reports.
	 *
	 * "own" means only to the signed-in person the data is about, "aggregate" only as totals,
	 * and for people only when at least MIN_GROUP of them are counted together.
	 */
	const POLICY = array(
		'public'    => array(
			'index'        => 'yes',
			'visitor_chat' => 'yes',
			'agent'        => 'yes',
			'analytics'    => 'yes',
		),
		'member'    => array(
			'index'        => 'no',
			'visitor_chat' => 'no',
			'agent'        => 'no',
			'analytics'    => 'aggregate',
		),
		'personal'  => array(
			'index'        => 'no',
			'visitor_chat' => 'own',
			'agent'        => 'no',
			'analytics'    => 'aggregate',
		),
		'financial' => array(
			'index'        => 'no',
			'visitor_chat' => 'no',
			'agent'        => 'aggregate',
			'analytics'    => 'aggregate',
		),
	);

	/**
	 * Fewest people a count about people may describe.
	 */
	const MIN_GROUP = 5;

	/**
	 * Whether the ability was already registered in this request.
	 *
	 * @var bool
	 */
	private $registered = false;

	/**
	 * Whether the site description is turned on.
	 *
	 * @return bool
	 */
	public static function enabled() {
		$options = get_option( 'ai_chat_bedrock_settings', array() );
		$enabled = is_array( $options ) && ! empty( $options['site_ontology'] );

		/**
		 * Whether the site description is offered to agents and used to label passages.
		 *
		 * @since 1.61.0
		 *
		 * @param bool $enabled Whether the site ontology is on.
		 */
		return (bool) apply_filters( 'ai_chat_bedrock_ontology_enabled', $enabled );
	}

	/**
	 * The rule for one class and one use.
	 *
	 * @param string $sensitivity Sensitivity class.
	 * @param string $usage       index, visitor_chat, agent or analytics.
	 * @return string yes, no, own or aggregate. Unknown classes and uses are "no".
	 */
	public static function rule( $sensitivity, $usage ) {
		return isset( self::POLICY[ $sensitivity ][ $usage ] ) ? self::POLICY[ $sensitivity ][ $usage ] : 'no';
	}

	/**
	 * Whether data of a class may be used without restriction.
	 *
	 * @param string $sensitivity Sensitivity class.
	 * @param string $usage       index, visitor_chat, agent or analytics.
	 * @return bool
	 */
	public static function may( $sensitivity, $usage ) {
		return 'yes' === self::rule( $sensitivity, $usage );
	}

	/**
	 * Whether a count of people may be shown. Smaller groups are withheld, since a count of
	 * one or two can identify the person behind it.
	 *
	 * @param int $count People counted.
	 * @return bool
	 */
	public static function may_show_count( $count ) {
		$count = (int) $count;
		return 0 === $count || $count >= self::MIN_GROUP;
	}

	/**
	 * The sensitivity classes with their rules, as published to agents.
	 *
	 * @return array
	 */
	public static function sensitivity_classes() {
		$descriptions = array(
			self::PUBLIC_DATA => __( 'Published content anyone can read.', 'ai-chat-for-amazon-bedrock' ),
			self::MEMBER      => __( 'Published content only signed-in members can read. It is removed before any text reaches a model or an index.', 'ai-chat-for-amazon-bedrock' ),
			self::PERSONAL    => __( 'Data about one person, such as their orders or the questions they asked. Only that person, signed in, sees it in a chat; reports show counts.', 'ai-chat-for-amazon-bedrock' ),
			self::FINANCIAL   => __( 'Store revenue and order figures. Only totals, and only for accounts that may view store reports.', 'ai-chat-for-amazon-bedrock' ),
		);
		$classes      = array();
		foreach ( self::POLICY as $class => $rules ) {
			$classes[] = array_merge(
				array(
					'class'       => $class,
					'description' => $descriptions[ $class ],
				),
				$rules
			);
		}
		return $classes;
	}

	/**
	 * Entity types the site can hold, keyed by schema.org type name.
	 *
	 * Each type lists its properties with their sensitivity and the tools that read it.
	 * Plugin concepts with no schema.org counterpart have an empty IRI.
	 *
	 * @return array
	 */
	public static function types() {
		$public = self::PUBLIC_DATA;
		$types  = array(
			'WebSite'      => array(
				'label'       => __( 'The site', 'ai-chat-for-amazon-bedrock' ),
				'sensitivity' => $public,
				'properties'  => array(
					'name'        => $public,
					'url'         => $public,
					'description' => $public,
					'inLanguage'  => $public,
					'publisher'   => $public,
				),
				'tools'       => array( 'get_site_info', 'describe_site' ),
			),
			'Article'      => array(
				'label'       => __( 'Posts', 'ai-chat-for-amazon-bedrock' ),
				'sensitivity' => $public,
				'post_types'  => array( 'post' ),
				'properties'  => array(
					'headline'        => $public,
					'url'             => $public,
					'inLanguage'      => $public,
					'datePublished'   => $public,
					'dateModified'    => $public,
					'about'           => $public,
					'articleBody'     => $public,
					'workTranslation' => $public,
					'hasPart'         => self::MEMBER,
				),
				'tools'       => array( 'search_posts', 'get_post', 'describe_site' ),
			),
			'WebPage'      => array(
				'label'       => __( 'Pages', 'ai-chat-for-amazon-bedrock' ),
				'sensitivity' => $public,
				'post_types'  => array( 'page' ),
				'properties'  => array(
					'name'            => $public,
					'url'             => $public,
					'inLanguage'      => $public,
					'dateModified'    => $public,
					'text'            => $public,
					'workTranslation' => $public,
					'hasPart'         => self::MEMBER,
				),
				'tools'       => array( 'get_post', 'describe_site' ),
			),
			'CreativeWork' => array(
				'label'       => __( 'Other published content', 'ai-chat-for-amazon-bedrock' ),
				'sensitivity' => $public,
				'post_types'  => array(),
				'properties'  => array(
					'name'       => $public,
					'url'        => $public,
					'inLanguage' => $public,
					'text'       => $public,
				),
				'tools'       => array( 'describe_site' ),
			),
			'DefinedTerm'  => array(
				'label'       => __( 'Categories and tags', 'ai-chat-for-amazon-bedrock' ),
				'sensitivity' => $public,
				'taxonomies'  => array(),
				'properties'  => array(
					'name'             => $public,
					'url'              => $public,
					'inDefinedTermSet' => $public,
				),
				'tools'       => array( 'get_categories', 'get_tags' ),
			),
		);

		if ( class_exists( 'WooCommerce' ) ) {
			$types['Product'] = array(
				'label'       => __( 'Products', 'ai-chat-for-amazon-bedrock' ),
				'sensitivity' => $public,
				'post_types'  => array( 'product' ),
				'properties'  => array(
					'name'        => $public,
					'url'         => $public,
					'sku'         => $public,
					'description' => $public,
					'category'    => $public,
					'offers'      => $public,
				),
				'tools'       => array( 'get_products', 'describe_site' ),
			);
			$types['Offer']   = array(
				'label'       => __( 'Prices and availability', 'ai-chat-for-amazon-bedrock' ),
				'sensitivity' => $public,
				'properties'  => array(
					'price'         => $public,
					'priceCurrency' => $public,
					'availability'  => $public,
				),
				'tools'       => array( 'get_products', 'describe_site' ),
			);
			$types['Order']   = array(
				'label'       => __( 'Orders', 'ai-chat-for-amazon-bedrock' ),
				'sensitivity' => self::PERSONAL,
				'properties'  => array(
					'orderNumber'     => self::PERSONAL,
					'orderDate'       => self::PERSONAL,
					'orderStatus'     => self::PERSONAL,
					'orderedItem'     => self::PERSONAL,
					'customer'        => self::PERSONAL,
					'totalPaymentDue' => self::FINANCIAL,
				),
				'tools'       => array(),
			);
		}

		$types['Question']   = array(
			'label'       => __( 'Questions visitors asked', 'ai-chat-for-amazon-bedrock' ),
			'sensitivity' => self::PERSONAL,
			'properties'  => array(
				'text'        => self::PERSONAL,
				'dateCreated' => self::PERSONAL,
			),
			'tools'       => array(),
		);
		$types['ContentGap'] = array(
			'label'       => __( 'Questions the site could not answer, grouped', 'ai-chat-for-amazon-bedrock' ),
			'iri'         => '',
			'sensitivity' => self::PERSONAL,
			'properties'  => array(
				'question' => self::PERSONAL,
				'count'    => self::PERSONAL,
				'lastSeen' => self::PERSONAL,
			),
			'tools'       => array(),
		);

		/**
		 * Filters the entity types the site description lists.
		 *
		 * A type is keyed by its schema.org name and has a label, a sensitivity class,
		 * properties mapped to their sensitivity class, and the tools that read it. Add
		 * "post_types" to map a custom post type, or "iri" => '' for a concept schema.org
		 * does not have.
		 *
		 * @since 1.61.0
		 *
		 * @param array $types Entity types.
		 */
		$types = (array) apply_filters( 'ai_chat_bedrock_ontology_types', $types );

		// Every public post type and taxonomy is accounted for exactly once.
		$claimed = array();
		foreach ( $types as $name => $type ) {
			foreach ( isset( $type['post_types'] ) ? (array) $type['post_types'] : array() as $post_type ) {
				$claimed[ $post_type ] = $name;
			}
		}
		foreach ( self::public_post_types() as $post_type ) {
			if ( ! isset( $claimed[ $post_type ] ) && isset( $types['CreativeWork'] ) ) {
				$types['CreativeWork']['post_types'][] = $post_type;
			}
		}
		if ( isset( $types['DefinedTerm'] ) ) {
			$types['DefinedTerm']['taxonomies'] = self::public_taxonomies();
		}
		return $types;
	}

	/**
	 * Relations between the types, as subject, predicate, object. Relations whose types the
	 * site does not have are left out.
	 *
	 * @return array
	 */
	public static function relations() {
		$relations = array(
			array( 'Article', 'about', 'DefinedTerm' ),
			array( 'Article', 'isPartOf', 'WebSite' ),
			array( 'WebPage', 'isPartOf', 'WebSite' ),
			array( 'CreativeWork', 'isPartOf', 'WebSite' ),
			array( 'Product', 'category', 'DefinedTerm' ),
			array( 'Product', 'offers', 'Offer' ),
			array( 'Product', 'isPartOf', 'WebSite' ),
			array( 'Order', 'orderedItem', 'Product' ),
			array( 'ContentGap', 'groups', 'Question', '' ),
			array( 'ContentGap', 'answeredBy', 'Article', '' ),
		);
		if ( self::multilingual() ) {
			$relations[] = array( 'Article', 'workTranslation', 'Article' );
			$relations[] = array( 'WebPage', 'workTranslation', 'WebPage' );
		}

		$types = self::types();
		$out   = array();
		foreach ( $relations as $relation ) {
			if ( ! isset( $types[ $relation[0] ], $types[ $relation[2] ] ) ) {
				continue;
			}
			$out[] = array(
				'subject'   => $relation[0],
				'predicate' => $relation[1],
				'iri'       => isset( $relation[3] ) ? $relation[3] : self::VOCABULARY . $relation[1],
				'object'    => $relation[2],
			);
		}
		return $out;
	}

	/**
	 * The type a post is described as.
	 *
	 * @param WP_Post $post Post.
	 * @return string Type name, or an empty string for something that is not an entity here.
	 */
	public static function type_of( $post ) {
		if ( ! $post instanceof WP_Post ) {
			return '';
		}
		$type = '';
		foreach ( self::types() as $name => $definition ) {
			if ( isset( $definition['post_types'] ) && in_array( $post->post_type, (array) $definition['post_types'], true ) ) {
				$type = $name;
				break;
			}
		}

		/**
		 * Filters the type a post is described as.
		 *
		 * @since 1.61.0
		 *
		 * @param string  $type Type name, such as Article.
		 * @param WP_Post $post Post.
		 */
		return (string) apply_filters( 'ai_chat_bedrock_ontology_type', $type, $post );
	}

	/**
	 * The node ID of a post, the same one Yoast SEO and WooCommerce print in the page markup.
	 *
	 * @param WP_Post $post Post.
	 * @return string
	 */
	public static function entity_id( $post ) {
		$url = (string) get_permalink( $post );
		switch ( self::type_of( $post ) ) {
			case 'Article':
				return $url . '#article';
			case 'Product':
				return $url . '#product';
			default:
				return $url;
		}
	}

	/**
	 * The node ID of the site.
	 *
	 * @return string
	 */
	public static function site_id() {
		return home_url( '/#website' );
	}

	/**
	 * One published post as a node, or null for a post that is not public.
	 *
	 * Only what a signed-out visitor can see is described: no members-only text, no author
	 * account, and no draft or private translation.
	 *
	 * @param WP_Post $post Post.
	 * @return array|null
	 */
	public static function entity( $post ) {
		if ( ! AI_Chat_Bedrock_Content::is_public( $post ) ) {
			return null;
		}
		$type = self::type_of( $post );
		if ( '' === $type ) {
			return null;
		}

		$node     = array(
			'@id'      => self::entity_id( $post ),
			'@type'    => $type,
			'name'     => AI_Chat_Bedrock_Content::title( $post ),
			'url'      => (string) get_permalink( $post ),
			'isPartOf' => array( '@id' => self::site_id() ),
		);
		$language = AI_Chat_Bedrock_Content::language( $post );
		if ( '' !== $language ) {
			$node['inLanguage'] = $language;
		}
		$published = get_post_time( 'c', true, $post );
		if ( $published ) {
			$node['datePublished'] = (string) $published;
		}
		$modified = get_post_modified_time( 'c', true, $post );
		if ( $modified ) {
			$node['dateModified'] = (string) $modified;
		}

		foreach ( self::terms( $post ) as $property => $terms ) {
			$node[ $property ] = $terms;
		}

		$translations = self::translations( $post );
		if ( ! empty( $translations ) ) {
			$node['workTranslation'] = $translations;
		}

		if ( 'Product' === $type ) {
			$product = self::product_properties( $post );
			// A product hidden from the shop is not described either.
			if ( null === $product ) {
				return null;
			}
			$node = array_merge( $node, $product );
		}

		/**
		 * Filters the node a post is described as.
		 *
		 * Add only what a signed-out visitor can see on the page.
		 *
		 * @since 1.61.0
		 *
		 * @param array   $node Node.
		 * @param WP_Post $post Post.
		 */
		return (array) apply_filters( 'ai_chat_bedrock_ontology_entity', $node, $post );
	}

	/**
	 * The whole site description, or one entity when a post ID is given.
	 *
	 * @param array $input Ability input, with an optional post ID.
	 * @return array|WP_Error
	 */
	public static function describe( $input = array() ) {
		$input   = is_array( $input ) ? $input : array();
		$post_id = isset( $input['id'] ) ? absint( $input['id'] ) : 0;
		if ( $post_id > 0 ) {
			$entity = self::entity( get_post( $post_id ) );
			if ( null === $entity ) {
				return new WP_Error( 'aicfab_post_not_available', __( 'That post is not published or not publicly readable.', 'ai-chat-for-amazon-bedrock' ) );
			}
			return array(
				'ontology_version' => self::VERSION,
				'vocabulary'       => self::VOCABULARY,
				'entity'           => $entity,
			);
		}

		$languages = self::languages();
		$types     = array();
		foreach ( self::types() as $name => $definition ) {
			$types[] = self::describe_type( $name, $definition, $languages );
		}

		/**
		 * Filters the business metrics the site description lists.
		 *
		 * @since 1.61.0
		 *
		 * @param array $metrics Metric definitions.
		 */
		$metrics = (array) apply_filters( 'ai_chat_bedrock_ontology_metrics', array() );

		return array(
			'ontology_version' => self::VERSION,
			'vocabulary'       => self::VOCABULARY,
			'site'             => array(
				'@id'         => self::site_id(),
				'@type'       => 'WebSite',
				'name'        => wp_strip_all_tags( (string) get_bloginfo( 'name' ) ),
				'description' => wp_strip_all_tags( (string) get_bloginfo( 'description' ) ),
				'url'         => home_url( '/' ),
				'inLanguage'  => array_column( $languages, 'code' ),
				'publisher'   => array( '@id' => home_url( '/#organization' ) ),
			),
			'languages'        => $languages,
			'types'            => $types,
			'relations'        => self::relations(),
			'sensitivity'      => self::sensitivity_classes(),
			'metrics'          => array_values( $metrics ),
			'notes'            => __( 'Counts are of published content. Members-only sections, drafts, private posts and password-protected posts are never described. Personal data is never returned to an agent.', 'ai-chat-for-amazon-bedrock' ),
			'generated_at'     => gmdate( 'c' ),
		);
	}

	/**
	 * Register the describe-site ability.
	 */
	public function register() {
		if ( ! function_exists( 'wp_register_ability' ) || ! self::enabled() || $this->registered ) {
			return;
		}
		$this->registered = true;

		wp_register_ability(
			'ai-chat-bedrock/describe-site',
			array(
				'label'               => __( 'Describe the site', 'ai-chat-for-amazon-bedrock' ),
				'description'         => __( 'Return the kinds of content the site holds, with schema.org types, counts per language, how they relate, and which data an AI may see. With a post ID, describe that one published item. Read only.', 'ai-chat-for-amazon-bedrock' ),
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array(
							'type'        => 'integer',
							'description' => __( 'Optional post ID, to describe one published item.', 'ai-chat-for-amazon-bedrock' ),
						),
					),
				),
				'output_schema'       => array( 'type' => 'object' ),
				'execute_callback'    => array( __CLASS__, 'describe' ),
				'category'            => AI_Chat_Bedrock_Abilities::CATEGORY,
				'meta'                => array(
					// Read-only and repeatable, so a client may call it without asking first.
					'annotations' => array(
						'readonly'   => true,
						'idempotent' => true,
					),
					'public'      => true,
				),
				'permission_callback' => array( $this, 'can_read' ),
			)
		);
	}

	/**
	 * Permission callback, the same as the site's other read-only abilities.
	 *
	 * @return bool
	 */
	public function can_read() {
		return is_user_logged_in() && current_user_can( AI_Chat_Bedrock_Tool_Policy::required_capability() );
	}

	/**
	 * Add each passage's type and language, so the model can tell a product from a post and
	 * answer from the page in the visitor's language.
	 *
	 * @param array $passages Retrieved passages.
	 * @return array
	 */
	public static function annotate_passages( $passages ) {
		if ( ! is_array( $passages ) || ! self::enabled() ) {
			return $passages;
		}
		foreach ( $passages as $index => $passage ) {
			if ( ! is_array( $passage ) || empty( $passage['post_id'] ) ) {
				continue;
			}
			$post = get_post( absint( $passage['post_id'] ) );
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$type = self::type_of( $post );
			if ( '' !== $type ) {
				$passages[ $index ]['entity_type'] = $type;
			}
			$language = AI_Chat_Bedrock_Content::language( $post );
			if ( '' !== $language ) {
				$passages[ $index ]['language'] = $language;
			}
		}
		return $passages;
	}

	/**
	 * The site's languages, the default first.
	 *
	 * @return array List of arrays with code, name and default.
	 */
	public static function languages() {
		if ( function_exists( 'pll_languages_list' ) ) {
			$default = function_exists( 'pll_default_language' ) ? (string) pll_default_language( 'slug' ) : '';
			$slugs   = array_map( 'strval', (array) pll_languages_list( array( 'fields' => 'slug' ) ) );
			$names   = (array) pll_languages_list( array( 'fields' => 'name' ) );
			$out     = array();
			foreach ( $slugs as $index => $slug ) {
				$out[] = array(
					'code'    => sanitize_key( $slug ),
					'name'    => isset( $names[ $index ] ) ? wp_strip_all_tags( (string) $names[ $index ] ) : $slug,
					'default' => $slug === $default,
				);
			}
			if ( ! empty( $out ) ) {
				return self::default_first( $out );
			}
		}
		if ( function_exists( 'has_filter' ) && has_filter( 'wpml_active_languages' ) ) {
			$active  = apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's documented API.
			$default = (string) apply_filters( 'wpml_default_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's documented API.
			if ( is_array( $active ) && ! empty( $active ) ) {
				$out = array();
				foreach ( $active as $code => $language ) {
					$out[] = array(
						'code'    => sanitize_key( (string) $code ),
						'name'    => AI_Chat_Bedrock_Content::language_name( $code ),
						'default' => (string) $code === $default,
					);
				}
				return self::default_first( $out );
			}
		}
		$locale = (string) get_bloginfo( 'language' );
		return array(
			array(
				'code'    => '' !== $locale ? $locale : 'en-US',
				'name'    => '',
				'default' => true,
			),
		);
	}

	/**
	 * Whether a translation plugin links translations of the same post.
	 *
	 * @return bool
	 */
	public static function multilingual() {
		return function_exists( 'pll_get_post_translations' ) || ( function_exists( 'has_filter' ) && has_filter( 'wpml_element_trid' ) );
	}

	private static function default_first( $languages ) {
		usort(
			$languages,
			static function ( $left, $right ) {
				return (int) $right['default'] - (int) $left['default'];
			}
		);
		return $languages;
	}

	private static function describe_type( $name, $definition, $languages ) {
		$iri  = array_key_exists( 'iri', $definition ) ? (string) $definition['iri'] : self::VOCABULARY . $name;
		$type = array(
			'name'        => (string) $name,
			'iri'         => $iri,
			'label'       => isset( $definition['label'] ) ? (string) $definition['label'] : (string) $name,
			'sensitivity' => isset( $definition['sensitivity'] ) ? (string) $definition['sensitivity'] : self::PERSONAL,
		);
		if ( isset( $definition['post_types'] ) ) {
			$type['post_types'] = array_values( (array) $definition['post_types'] );
			$type['count']      = 0;
			foreach ( $type['post_types'] as $post_type ) {
				$type['count'] += self::published_count( $post_type );
			}
			$by_language = self::counts_by_language( $type['post_types'], $languages );
			if ( ! empty( $by_language ) ) {
				$type['count_by_language'] = $by_language;
			}
		}
		if ( ! empty( $definition['taxonomies'] ) ) {
			$type['taxonomies'] = array_values( (array) $definition['taxonomies'] );
		}

		$properties = array();
		foreach ( isset( $definition['properties'] ) ? (array) $definition['properties'] : array() as $property => $sensitivity ) {
			$properties[] = array(
				'name'        => (string) $property,
				'iri'         => '' === $iri ? '' : self::VOCABULARY . $property,
				'sensitivity' => (string) $sensitivity,
			);
		}
		$type['properties'] = $properties;
		$type['tools']      = array_values( isset( $definition['tools'] ) ? (array) $definition['tools'] : array() );
		return $type;
	}

	private static function published_count( $post_type ) {
		$counts = wp_count_posts( $post_type );
		return is_object( $counts ) && isset( $counts->publish ) ? (int) $counts->publish : 0;
	}

	private static function counts_by_language( $post_types, $languages ) {
		if ( empty( $post_types ) || ! function_exists( 'pll_count_posts' ) || count( $languages ) < 2 ) {
			return array();
		}
		$counts = array();
		foreach ( $languages as $language ) {
			$total = 0;
			foreach ( $post_types as $post_type ) {
				if ( function_exists( 'pll_is_translated_post_type' ) && ! pll_is_translated_post_type( $post_type ) ) {
					return array();
				}
				$total += (int) pll_count_posts( $language['code'], array( 'post_type' => $post_type ) );
			}
			$counts[ $language['code'] ] = $total;
		}
		return $counts;
	}

	/**
	 * Public post types, without attachments.
	 *
	 * @return array
	 */
	public static function public_post_types() {
		$types = get_post_types( array( 'public' => true ), 'names' );
		return array_values( array_diff( array_map( 'strval', (array) $types ), array( 'attachment' ) ) );
	}

	private static function public_taxonomies() {
		$taxonomies = get_taxonomies(
			array(
				'public'             => true,
				'publicly_queryable' => true,
			),
			'names'
		);
		return array_values( array_diff( array_map( 'strval', (array) $taxonomies ), array( 'post_format' ) ) );
	}

	/**
	 * The post's terms, keyed by the property they fill: category for product categories,
	 * about for everything else.
	 */
	private static function terms( $post ) {
		$out = array();
		foreach ( self::public_taxonomies() as $taxonomy ) {
			$terms = get_the_terms( $post, $taxonomy );
			if ( ! is_array( $terms ) ) {
				continue;
			}
			$property = 'product_cat' === $taxonomy ? 'category' : 'about';
			foreach ( $terms as $term ) {
				if ( ! is_object( $term ) || ( isset( $out[ $property ] ) && count( $out[ $property ] ) >= self::MAX_TERMS ) ) {
					continue;
				}
				$link               = get_term_link( $term );
				$out[ $property ][] = array(
					'@id'              => is_string( $link ) ? $link : '',
					'@type'            => 'DefinedTerm',
					'name'             => wp_strip_all_tags( (string) $term->name ),
					'inDefinedTermSet' => $taxonomy,
				);
			}
		}
		return $out;
	}

	/**
	 * Published translations of a post, never drafts or private ones.
	 */
	private static function translations( $post ) {
		$ids = array();
		if ( function_exists( 'pll_get_post_translations' ) ) {
			$ids = (array) pll_get_post_translations( $post->ID );
		}
		$out = array();
		foreach ( $ids as $language => $id ) {
			if ( (int) $id === (int) $post->ID ) {
				continue;
			}
			$translation = get_post( (int) $id );
			if ( ! AI_Chat_Bedrock_Content::is_public( $translation ) ) {
				continue;
			}
			$out[] = array(
				'@id'        => self::entity_id( $translation ),
				'inLanguage' => sanitize_key( (string) $language ),
			);
		}
		return $out;
	}

	/**
	 * A product's SKU and offer, or null for a product the shop does not list.
	 */
	private static function product_properties( $post ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return array();
		}
		$product = wc_get_product( $post->ID );
		if ( ! $product || ! is_callable( array( $product, 'get_price' ) ) || ! AI_Chat_Bedrock_WooCommerce::is_listable( $product ) ) {
			return null;
		}

		$out = array();
		$sku = is_callable( array( $product, 'get_sku' ) ) ? (string) $product->get_sku() : '';
		if ( '' !== $sku ) {
			$out['sku'] = $sku;
		}
		$price = (string) $product->get_price();
		if ( '' !== $price ) {
			$availability = array(
				'instock'     => 'InStock',
				'outofstock'  => 'OutOfStock',
				'onbackorder' => 'BackOrder',
			);
			$status       = is_callable( array( $product, 'get_stock_status' ) ) ? (string) $product->get_stock_status() : '';
			$offer        = array(
				'@type'         => 'Offer',
				'price'         => $price,
				'priceCurrency' => function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '',
			);
			if ( isset( $availability[ $status ] ) ) {
				$offer['availability'] = self::VOCABULARY . $availability[ $status ];
			}
			$out['offers'] = $offer;
		}
		return $out;
	}
}

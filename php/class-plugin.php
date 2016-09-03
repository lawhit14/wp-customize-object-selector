<?php
/**
 * Customize Object Selector Class
 *
 * @package CustomizeObjectSelector
 */

namespace CustomizeObjectSelector;

/**
 * Class Plugin
 */
class Plugin {

	const OBJECT_SELECTOR_QUERY_AJAX_ACTION = 'customize_object_selector_query';

	/**
	 * Plugin version.
	 *
	 * @var string
	 */
	public $version;

	/**
	 * Plugin constructor.
	 *
	 * @access public
	 */
	public function __construct() {

		// Parse plugin version.
		if ( preg_match( '/Version:\s*(\S+)/', file_get_contents( dirname( __FILE__ ) . '/../customize-object-selector.php' ), $matches ) ) {
			$this->version = $matches[1];
		}
	}

	/**
	 * Initialize.
	 */
	function init() {

		add_action( 'wp_default_scripts', array( $this, 'register_scripts' ), 100 );
		add_action( 'wp_default_styles', array( $this, 'register_styles' ), 100 );

		add_action( 'customize_register', array( $this, 'customize_register' ), 9 );

		add_action( 'customize_controls_enqueue_scripts', array( $this, 'customize_controls_enqueue_scripts' ) );
		add_action( 'customize_controls_print_footer_scripts', array( $this, 'print_templates' ) );
		add_action( 'wp_ajax_' . static::OBJECT_SELECTOR_QUERY_AJAX_ACTION, array( $this, 'handle_ajax_object_selector_query' ) );
		add_filter( 'customize_refresh_nonces', array( $this, 'add_customize_object_selector_nonce' ) );
	}

	/**
	 * Load theme and plugin compatibility classes.
	 *
	 * @param \WP_Customize_Manager $wp_customize Manager.
	 */
	function customize_register( \WP_Customize_Manager $wp_customize ) {
		require_once __DIR__ . '/class-control.php';
		$wp_customize->register_control_type( __NAMESPACE__ . '\\Control' );
	}

	/**
	 * Register scripts.
	 *
	 * @param \WP_Scripts $wp_scripts Scripts.
	 */
	public function register_scripts( \WP_Scripts $wp_scripts ) {
		$suffix = ( SCRIPT_DEBUG ? '' : '.min' ) . '.js';

		$handle = 'select2';
		if ( ! $wp_scripts->query( $handle, 'registered' ) ) {
			$src = plugins_url( 'bower_components/select2/dist/js/select2.full' . $suffix, dirname( __FILE__ ) );
			$deps = array( 'jquery' );
			$in_footer = 1;
			$wp_scripts->add( $handle, $src, $deps, $this->version, $in_footer );
		}

		$handle = 'customize-object-selector-component';
		$src = plugins_url( 'js/customize-object-selector-component' . $suffix, dirname( __FILE__ ) );
		$deps = array( 'jquery', 'select2', 'customize-base', 'jquery-ui-sortable' );
		$in_footer = 1;
		$wp_scripts->add( $handle, $src, $deps, $this->version, $in_footer );

		$handle = 'customize-object-selector-control';
		$src = plugins_url( 'js/customize-object-selector-control' . $suffix, dirname( __FILE__ ) );
		$deps = array( 'customize-controls', 'customize-object-selector-component' );
		$in_footer = 1;
		$wp_scripts->add( $handle, $src, $deps, $this->version, $in_footer );
	}

	/**
	 * Register styles.
	 *
	 * @param \WP_Styles $wp_styles Styles.
	 */
	public function register_styles( \WP_Styles $wp_styles ) {
		$suffix = ( SCRIPT_DEBUG ? '' : '.min' ) . '.css';

		$handle = 'select2';
		if ( ! $wp_styles->query( $handle, 'registered' ) ) {
			$src = plugins_url( 'bower_components/select2/dist/css/select2' . $suffix, dirname( __FILE__ ) );
			$deps = array();
			$wp_styles->add( $handle, $src, $deps, $this->version );
		}

		$handle = 'customize-object-selector';
		$src = plugins_url( 'css/customize-object-selector' . $suffix, dirname( __FILE__ ) );
		$deps = array( 'customize-controls', 'select2' );
		$wp_styles->add( $handle, $src, $deps, $this->version );
	}

	/**
	 * Enqueue controls scripts.
	 */
	public function customize_controls_enqueue_scripts() {
		wp_enqueue_script( 'customize-object-selector-control' );
		wp_enqueue_style( 'customize-object-selector-control' );
	}

	/**
	 * Handle ajax request for objects.
	 *
	 * @global \WP_Customize_Manager $wp_customize
	 */
	public function handle_ajax_object_selector_query() {
		global $wp_customize;

		// Close output buffering to ensure that _ajax_wp_die_handler doesn't clobber the status_header(). See <https://core.trac.wordpress.org/ticket/35666#comment:6>.
		while ( 0 !== ob_get_level()  ) {
			ob_end_clean();
		}

		$nonce_query_var_name = 'customize_object_selector_query_nonce';
		if ( ! check_ajax_referer( static::OBJECT_SELECTOR_QUERY_AJAX_ACTION, $nonce_query_var_name, false ) ) {
			status_header( 400 );
			wp_send_json_error( array( 'code' => 'bad_nonce' ), 400 ); // Note: The redundant 400 status is needed until #35666 is resolved.
		}
		if ( ! isset( $_POST['post_query_args'] ) ) {
			status_header( 400 );
			wp_send_json_error( array( 'code' => 'missing_post_query_args' ), 400 );
		}
		$post_query_args = json_decode( wp_unslash( $_POST['post_query_args'] ), true );
		if ( ! is_array( $post_query_args ) ) {
			status_header( 400 );
			wp_send_json_error( array( 'code' => 'invalid_post_query_args' ), 400 );
		}

		$post_query_args = $this->process_post_query_vars( $post_query_args );
		if ( is_wp_error( $post_query_args ) ) {
			status_header( 400 );
			wp_send_json_error( array(
				'code' => $post_query_args->get_error_code(),
				'message' => $post_query_args->get_error_message(),
				'data' => $post_query_args->get_error_data(),
			), 400 );
		}

		// Make sure that the Customizer state is applied in any query results (especially via the Customize Posts plugin).
		if ( ! empty( $wp_customize ) ) {
			foreach ( $wp_customize->settings() as $setting ) {
				/**
				 * Setting.
				 *
				 * @var \WP_Customize_Setting $setting
				 */
				$setting->preview();
			}
		}

		if ( '' === trim( $post_query_args['s'] ) && ! empty( $post_query_args['tree_args'] ) ) {
			$results = $this->build_post_tree( $post_query_args );
		} else {
			$results = $this->query_posts( $post_query_args );
		}
		if ( is_wp_error( $results ) ) {
			status_header( 400 );
			wp_send_json_error( array(
				'code' => $results->get_error_code(),
				'message' => $results->get_error_message(),
				'data' => $results->get_error_data(),
			), 400 );
		} else {
			wp_send_json_success( $results );
		}
	}

	/**
	 * Process post query vars (i.e. WP_Query vars).
	 *
	 * @param array $post_query_vars Post query vars.
	 *
	 * @return array|\WP_Error Processed post query vars or WP_Error on error.
	 */
	public function process_post_query_vars( $post_query_vars ) {

		$post_query_vars = array_merge(
			array(
				's' => '',
				'paged' => 1,
				'tree_args' => array(),
			),
			$post_query_vars
		);
		if ( ! empty( $post_query_vars['post__in'] ) ) {
			$post_query_vars['posts_per_page'] = -1;
		}

		// Whitelist allowed query vars.
		$allowed_query_vars = array(
			'include_featured_images',
			'tree_args',
			'post_status',
			'post_type',
			's',
			'paged',
			'post__in',
			'post__not_in',
			'meta_key',
			'meta_value',
			'meta_query',
			'meta_compare',
			'orderby',
			'order',
		);

		// White list allowed query vars.
		$allowed_meta_query_vars = array(
			'key',
			'value',
			'compare',
		);

		// White list allowed meta query compare values.
		$allowed_meta_query_compare_values = array( '=', '!=', '>', '>=', '<', '<=' );

		$extra_query_vars = array_diff( array_keys( $post_query_vars ), $allowed_query_vars );
		if ( ! empty( $extra_query_vars ) ) {
			return new \WP_Error(
				'disallowed_query_var',
				__( 'Disallowed query var', 'customize-object-selector' ),
				array( 'query_vars' => array_values( $extra_query_vars ) )
			);
		}
		if ( ! empty( $post_query_vars['meta_compare'] ) && ! in_array( $post_query_vars['meta_compare'], $allowed_meta_query_compare_values, true ) ) {
			return new \WP_Error(
				'disallowed_meta_compare_query_var',
				__( 'Disallowed meta_compare query var', 'customize-object-selector' ),
				array( 'query_var' => $post_query_vars['meta_compare'] )
			);
		}

		// Currently this does not support nested meta_query.
		if ( isset( $post_query_vars['meta_query'] ) && ! empty( $post_query_vars['meta_query'] ) ) {
			foreach ( $post_query_vars['meta_query'] as $key => $val ) {
				if ( 'relation' === $key ) {
					if ( 'AND' !== $val ) {
						return new \WP_Error(
							'disallowed_meta_query_relation_var',
							__( 'Disallowed meta_query relation', 'customize-object-selector' ),
							array( 'query_vars' => array_values( $val ) )
						);
					}
					continue;
				}
				$extra_meta_query_vars = array_diff( array_keys( $val ), $allowed_meta_query_vars );
				if ( ! empty( $extra_meta_query_vars ) ) {
					return new \WP_Error(
						'disallowed_meta_query_var',
						__( 'Disallowed meta_query var', 'customize-object-selector' ),
						array( 'query_vars' => array_values( $extra_meta_query_vars ) )
					);
				}
				if ( ! empty( $val['compare'] ) && ! in_array( $val['compare'], $allowed_meta_query_compare_values, true ) ) {
					return new \WP_Error(
						'disallowed_meta_compare_query_var',
						__( 'Disallowed meta_compare query var', 'customize-object-selector' ),
						array( 'query_vars' => $post_query_vars['meta_query']['compare'] )
					);
				}
			}
		}

		// Get the queried post statuses and determine if if any of them are for a non-publicly queryable status.
		$has_private_status = false;
		if ( empty( $post_query_vars['post_status'] ) ) {
			$post_query_vars['post_status'] = array( 'publish' );
		} elseif ( is_string( $post_query_vars['post_status'] ) ) {
			$post_query_vars['post_status'] = explode( ',', $post_query_vars['post_status'] );
		} else {
			$post_query_vars['post_status'] = (array) $post_query_vars['post_status'];
		}
		foreach ( $post_query_vars['post_status'] as $post_status ) {
			$post_status_object = get_post_status_object( $post_status );
			if ( ! $post_status_object ) {
				return new \WP_Error(
					'bad_post_status',
					__( 'Bad post status', 'customize-object-selector' ),
					array( 'post_status' => $post_status )
				);
			}
			if ( ! empty( $post_status_object->publicly_queryable ) ) {
				$has_private_status = true;
			}
		}

		// Get the queried post types and determine if any of them are not allowed to be queried.
		if ( empty( $post_query_vars['post_type'] ) ) {
			$post_query_vars['post_type'] = array( 'post' );
		} elseif ( is_string( $post_query_vars['post_type'] ) ) {
			$post_query_vars['post_type'] = explode( ',', $post_query_vars['post_type'] );
		} else {
			$post_query_vars['post_type'] = (array) $post_query_vars['post_type'];
		}
		foreach ( $post_query_vars['post_type'] as $post_type ) {
			$post_type_object = get_post_type_object( $post_type );
			if ( ! $post_type_object ) {
				return new \WP_Error(
					'bad_post_type',
					__( 'Bad post type', 'customize-object-selector' ),
					array( 'post_type' => $post_type )
				);
			}
			if ( ! current_user_can( $post_type_object->cap->read ) ) {
				return new \WP_Error(
					'cannot_query_posts',
					__( 'Cannot query posts', 'customize-object-selector' ),
					array( 'post_type' => $post_type )
				);
			}
			if ( $has_private_status && ! current_user_can( $post_type_object->cap->read_private_posts ) ) {
				return new \WP_Error(
					'cannot_query_private_posts',
					__( 'Cannot query private posts', 'customize-object-selector' ),
					array( 'post_type' => $post_type )
				);
			}
		}

		$post_query_vars['include_featured_images'] = ! empty( $post_query_args['include_featured_images'] );

		if ( ! is_array( $post_query_vars['tree_args'] ) ) {
			return new \WP_Error(
				'bad_tree_args',
				__( 'Expected tree_args as array', 'customize-object-selector' )
			);
		}

		// See wp_dropdown_pages(), get_pages(), walk_page_dropdown_tree(), and the page_attributes_dropdown_pages_args filter.
		$allowed_args = array(
			'exclude_tree',
			'sort_column',
			// @todo Support more args as they are supported by Customize Posts for get_pages().
		);
		foreach ( array_keys( $post_query_vars['tree_args'] ) as $key ) {
			if ( ! in_array( $key, $allowed_args, true ) ) {
				return new \WP_Error(
					'unsupported_tree_arg',
					sprintf( __( 'Unsupported arg "%s" in tree_args', 'customize-object-selector' ), $key ),
					array( 'arg' => $key )
				);
			}
		}

		return $post_query_vars;
	}

	/**
	 * Build post tree results.
	 *
	 * @param array $post_query_args Post query args.
	 *
	 * @return array|\WP_Error Response data or WP_Error if error.
	 */
	public function build_post_tree( $post_query_args ) {

		if ( count( $post_query_args['post_type'] ) !== 1 ) {
			return new \WP_Error(
				'cannot_show_tree_for_multiple_post_types',
				__( 'Cannot show tree for multiple post types', 'customize-object-selector' )
			);
		}
		$post_type = $post_query_args['post_type'][0];
		if ( ! post_type_exists( $post_type ) ) {
			return new \WP_Error(
				'unrecognized_post_type',
				__( 'Unrecognized post type', 'customize-object-selector' )
			);
		}
		$post_type_object = get_post_type_object( $post_type );
		if ( ! $post_type_object->hierarchical ) {
			return new \WP_Error(
				'cannot_show_tree_for_non_hierarchical_post_type',
				__( 'Cannot show tree for non-hierarchical post type', 'customize-object-selector' )
			);
		}

		require_once __DIR__ . '/class-post-tree-walker.php';
		$walker = new Post_Tree_Walker();

		// @todo Add support for applying page_attributes_dropdown_pages_args filters if requested.
		$tree_args = array_merge(
			$post_query_args['tree_args'],
			array(
				'post_type' => $post_query_args['post_type'][0],
				'depth' => 0,
				'walker' => $walker,
			)
		);
		$posts = get_pages( $tree_args );
		walk_page_dropdown_tree( $posts, $tree_args['depth'], $tree_args );

		$results = array();
		foreach ( $walker->items as $item ) {
			$post = $item['post'];
			$title = htmlspecialchars_decode( html_entity_decode( $post->post_title ), ENT_QUOTES );
			$post_status_obj = get_post_status_object( $post->post_status );
			$is_publish_status = ( 'publish' === $post->post_status );

			$text = '';
			if ( ! $is_publish_status && $post_status_obj ) {
				/* translators: 1: post status */
				$text .= sprintf( __( '[%1$s] ', 'customize-object-selector' ), $post_status_obj->label );
			}
			$text .= $title;
			$results[] = array(
				'id' => $post->ID,
				'text' => $text,
				'depth' => $item['depth'],
				'title' => $title, // Option tooltip.
				'post_title' => $title,
				'post_type' => $post->post_type,
				'post_status' => $post->post_status,
				'post_date_gmt' => $post->post_date_gmt,
				'post_author' => $post->post_author,
			);
		}

		return array(
			'results' => $results,
		);
	}

	/**
	 * Query posts.
	 *
	 * @param array $post_query_args Post query vars.
	 * @return array Success results.
	 */
	public function query_posts( $post_query_args ) {
		$query = new \WP_Query( array_merge(
			array(
				'post_status' => 'publish',
				'post_type' => array( 'post' ),
				'ignore_sticky_posts' => true,
				'update_post_meta_cache' => $post_query_args['include_featured_images'],
				'update_post_term_cache' => false,
				'no_found_rows' => false,
			),
			$post_query_args
		) );

		$is_multiple_post_types = count( $query->get( 'post_type' ) ) > 1;

		$results = array_map(
			function( $post ) use ( $is_multiple_post_types, $post_query_args ) {
				$title = htmlspecialchars_decode( html_entity_decode( $post->post_title ), ENT_QUOTES );
				$post_type_obj = get_post_type_object( $post->post_type );
				$post_status_obj = get_post_status_object( $post->post_status );
				$is_publish_status = ( 'publish' === $post->post_status );

				$text = '';
				if ( ! $is_publish_status && $post_status_obj ) {
					/* translators: 1: post status */
					$text .= sprintf( __( '[%1$s] ', 'customize-object-selector' ), $post_status_obj->label );
				}
				$text .= $title;
				if ( $is_multiple_post_types && $post_type_obj ) {
					/* translators: 1: post type name */
					$text .= sprintf( __( ' (%1$s)', 'customize-object-selector' ), $post_type_obj->labels->singular_name );
				}
				$result = array(
					'id' => $post->ID,
					'text' => $text,
					'title' => $title, // Option tooltip.
					'post_title' => $title,
					'post_type' => $post->post_type,
					'post_status' => $post->post_status,
					'post_date_gmt' => $post->post_date_gmt,
					'post_author' => $post->post_author,
				);
				if ( $post_query_args['include_featured_images'] ) {
					$attachment_id = get_post_thumbnail_id( $post->ID );

					/**
					 * Filters the featured image attachment ID for a given post.
					 *
					 * @param int|bool $attachment_id Attachment ID or `false`.
					 * @param \WP_Post $post Post object.
					 */
					$attachment_id = apply_filters( 'customize_object_selector_attachment_id', $attachment_id, $post );

					if ( $attachment_id ) {
						$result['featured_image'] = wp_prepare_attachment_for_js( $attachment_id );
					} else {
						$result['featured_image'] = null;
					}
				}
				return $result;
			},
			$query->posts
		);

		return array(
			'results' => $results,
			'pagination' => array(
				'more' => $post_query_args['paged'] < $query->max_num_pages,
			),
		);
	}

	/**
	 * Add nonce for doing object selector query.
	 *
	 * @param array $nonces Nonces.
	 * @return array Amended nonces.
	 */
	public function add_customize_object_selector_nonce( $nonces ) {
		$nonces[ static::OBJECT_SELECTOR_QUERY_AJAX_ACTION ] = wp_create_nonce( static::OBJECT_SELECTOR_QUERY_AJAX_ACTION );
		return $nonces;
	}

	/**
	 * Print templates.
	 */
	public function print_templates() {
		?>
		<script id="tmpl-customize-object-selector-component" type="text/html">
			<select id="{{ data.select_id }}"
				<# if ( data.multiple ) { #>
					multiple="multiple"
				<# } #>
				>
			</select>

			<# if ( ! _.isEmpty( data.addable_post_types ) ) { #>
				<span class="add-new-post">
					<# _.each( data.addable_post_types, function( addable_post_type ) { #>
						<button type="button" class="button secondary-button add-new-post-button" data-post-type="{{ addable_post_type.post_type }}">
							{{ addable_post_type.add_button_label }}
						</button>
					<# } ) #>
				</span>
			<# } #>
		</script>

		<script id="tmpl-customize-object-selector-item" type="text/html">
			<# if ( data.featured_image && data.featured_image.sizes && data.featured_image.sizes.thumbnail && data.featured_image.sizes.thumbnail.url ) { #>
				<span class="select2-thumbnail-wrapper">
					<img src="{{ data.featured_image.sizes.thumbnail.url }}">
					{{{ data.text }}}
				</span>
			<# } else { #>
				<# if ( data.depth ) { #>
					<#
					var i;
					data.indentation = '';
					for ( i = 0; i < data.depth; i += 1 ) {
						data.indentation += '&nbsp;&nbsp;&nbsp;';
					}
					#>
					{{{ data.indentation }}}
				<# } #>

				{{{ data.text }}}
			<# } #>
		</script>
		<?php
	}
}

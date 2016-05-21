<?php

class Responsive_Slider_Lite_Ordering {

	public static function get_instance() {

		static $instance = null;

		if ( null === $instance ) {
			$instance = new self();
			self::_add_actions();
		}

		return $instance;
	}

	public function __construct() {

	}

	public static function _add_actions() {
		add_action( 'load-edit.php', array( __CLASS__, 'load_edit_screen' ) );
		add_action( 'wp_ajax_responsive_slider_lite_ordering', array( __CLASS__, 'ajax_responsive_slider_lite_ordering' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'load_textdomain' ) );
	}

	public static function load_textdomain() {
		load_plugin_textdomain( 'responsive_slider_lite_ordering', false, dirname( plugin_basename( __FILE__ ) ) . '/localization/' );
	}

	public static function load_edit_screen() {
		$screen = get_current_screen();
		$post_type = $screen->post_type;

		// is post type sortable?
		$sortable = ( post_type_supports( $post_type, 'page-attributes' ) || is_post_type_hierarchical( $post_type ) );		// check permission
		if ( ! $sortable = apply_filters( 'responsive_slider_lite_ordering_is_sortable', $sortable, $post_type ) ) {
			return;
		}

		// does user have the right to manage these post objects?
		if ( ! self::check_edit_others_caps( $post_type ) ) {
			return;
		}

		add_filter( 'views_' . $screen->id, array( __CLASS__, 'sort_by_order_link' )  );		// add view by menu order to views
		add_action( 'wp', array( __CLASS__, 'wp' ) );
	}

	/**
	 * when we load up our posts query, if we're actually sorting by menu order, initialize sorting scripts
	 */
	public static function wp() {
		if (get_post_type()=='responsive_slider_l') {
			$orderby = get_query_var('orderby');
			if ( ( is_string( $orderby ) && 0 === strpos( $orderby, 'menu_order' ) ) || ( isset( $orderby['menu_order'] ) && $orderby['menu_order'] == 'ASC' ) ) {
				$script_name = 'admin\js\responsive-slider-lite-admin-ordering.min.js';
				wp_enqueue_script( 'responsive-slider-lite-ordering', plugins_url( $script_name, __FILE__ ), array('jquery-ui-sortable'), '2.1', true );
				wp_enqueue_style( 'responsive-slider-lite-ordering', plugins_url( 'admin/css/responsive-slider-lite-admin.min.css', __FILE__ ) );
			}
		}
	}

	public static function ajax_responsive_slider_lite_ordering() {
		// check and make sure we have what we need
		if ( empty( $_POST['id'] ) || ( !isset( $_POST['previd'] ) && !isset( $_POST['nextid'] ) ) ) {
			die(-1);
		}

		// real post?
		if ( ! $post = get_post( $_POST['id'] ) ) {
			die(-1);
		}

		// does user have the right to manage these post objects?
		if ( ! self::check_edit_others_caps( $post->post_type ) ) {
			die(-1);
		}

		// badly written plug-in hooks for save post can break things
		if ( ! defined( 'WP_DEBUG' ) || ! WP_DEBUG ) {
			error_reporting( 0 );
		}

		global $wp_version;

		$previd = empty( $_POST['previd'] ) ? false : (int) $_POST['previd'];
		$nextid = empty( $_POST['nextid'] ) ? false : (int) $_POST['nextid'];
		$start = empty( $_POST['start'] ) ? 1 : (int) $_POST['start'];
		$excluded = empty( $_POST['excluded'] ) ? array( $post->ID ) : array_filter( (array) $_POST['excluded'], 'intval' );

		$new_pos = array(); // store new positions for ajax
		$return_data = new stdClass;

		do_action( 'responsive_slider_lite_ordering_pre_order_posts', $post, $start );

		// attempt to get the intended parent... if either sibling has a matching parent ID, use that
		$parent_id = $post->post_parent;
		$next_post_parent = $nextid ? wp_get_post_parent_id( $nextid ) : false;
		if ( $previd == $next_post_parent ) {	// if the preceding post is the parent of the next post, move it inside
			$parent_id = $next_post_parent;
		} elseif ( $next_post_parent !== $parent_id ) {  // otherwise, if the next post's parent isn't the same as our parent, we need to study
			$prev_post_parent = $previd ? wp_get_post_parent_id( $previd ) : false;
			if ( $prev_post_parent !== $parent_id ) {	// if the previous post is not our parent now, make it so!
				$parent_id = ( $prev_post_parent !== false ) ? $prev_post_parent : $next_post_parent;
			}
		}
		// if the next post's parent isn't our parent, it might as well be false (irrelevant to our query)
		if ( $next_post_parent !== $parent_id ) {
			$nextid = false;
		}

		$max_sortable_posts = (int) apply_filters( 'responsive_slider_lite_ordering_limit', 50 );	// should reliably be able to do about 50 at a time
		if ( $max_sortable_posts < 5 ) {	// don't be ridiculous!
			$max_sortable_posts = 50;
		}

		// we need to handle all post stati, except trash (in case of custom stati)
		$post_stati = get_post_stati(array(
			'show_in_admin_all_list' => true,
		));

		$siblings_query = array(
			'depth'						=> 1,
			'posts_per_page'			=> $max_sortable_posts,
			'post_type' 				=> $post->post_type,
			'post_status' 				=> $post_stati,
			'post_parent' 				=> $parent_id,
			'orderby' 					=> array( 'menu_order' => 'ASC', 'title' => 'ASC' ),
			'post__not_in'				=> $excluded,
			'update_post_term_cache'	=> false,
			'update_post_meta_cache'	=> false,
			'suppress_filters' 			=> true,
			'ignore_sticky_posts'		=> true,
		);
		if ( version_compare( $wp_version, '4.0', '<' ) ) {
			$siblings_query['orderby'] = 'menu_order title';
			$siblings_query['order'] = 'ASC';
		}
		$siblings = new WP_Query( $siblings_query ); // fetch all the siblings (relative ordering)

		// don't waste overhead of revisions on a menu order change (especially since they can't *all* be rolled back at once)
		remove_action( 'pre_post_update', 'wp_save_post_revision' );

		foreach( $siblings->posts as $sibling ) :

			// don't handle the actual post
			if ( $sibling->ID === $post->ID ) {
				continue;
			}

			// if this is the post that comes after our repositioned post, set our repositioned post position and increment menu order
			if ( $nextid === $sibling->ID ) {
				wp_update_post(array(
					'ID'			=> $post->ID,
					'menu_order'	=> $start,
					'post_parent'	=> $parent_id,
				));
				$ancestors = get_post_ancestors( $post->ID );
				$new_pos[$post->ID] = array(
					'menu_order'	=> $start,
					'post_parent'	=> $parent_id,
					'depth'			=> count( $ancestors ),
				);
				$start++;
			}

			// if repositioned post has been set, and new items are already in the right order, we can stop
			if ( isset( $new_pos[$post->ID] ) && $sibling->menu_order >= $start ) {
				$return_data->next = false;
				break;
			}

			// set the menu order of the current sibling and increment the menu order
			if ( $sibling->menu_order != $start ) {
				wp_update_post(array(
					'ID' 			=> $sibling->ID,
					'menu_order'	=> $start,
				));
			}
			$new_pos[$sibling->ID] = $start;
			$start++;

			if ( !$nextid && $previd == $sibling->ID ) {
				wp_update_post(array(
					'ID' 			=> $post->ID,
					'menu_order' 	=> $start,
					'post_parent' 	=> $parent_id
				));
				$ancestors = get_post_ancestors( $post->ID );
				$new_pos[$post->ID] = array(
					'menu_order'	=> $start,
					'post_parent' 	=> $parent_id,
					'depth' 		=> count($ancestors) );
				$start++;
			}

		endforeach;

		// max per request
		if ( !isset( $return_data->next ) && $siblings->max_num_pages > 1 ) {
			$return_data->next = array(
				'id' 		=> $post->ID,
				'previd' 	=> $previd,
				'nextid' 	=> $nextid,
				'start'		=> $start,
				'excluded'	=> array_merge( array_keys( $new_pos ), $excluded ),
			);
		} else {
			$return_data->next = false;
		}

		do_action( 'responsive_slider_lite_ordering_ordered_posts', $post, $new_pos );

		if ( ! $return_data->next ) {
			// if the moved post has children, we need to refresh the page (unless we're continuing)
			$children = get_posts(array(
				'numberposts'				=> 1,
				'post_type' 				=> $post->post_type,
				'post_status' 				=> $post_stati,
				'post_parent' 				=> $post->ID,
				'fields'					=> 'ids',
				'update_post_term_cache'	=> false,
				'update_post_meta_cache'	=> false,
			));

			if ( ! empty( $children ) ) {
				die( 'children' );
			}
		}

		$return_data->new_pos = $new_pos;

		die( json_encode( $return_data ) );
	}

	/**
	 * Append a sort by order link to the post actions
	 *
	 * @param string $views
	 * @return string
	 */
	public static function sort_by_order_link( $views ) {
		$class = ( get_query_var('orderby') == 'menu_order title' ) ? 'current' : '';
		$query_string = esc_url( remove_query_arg( array( 'orderby', 'order' ) ) );
		if ( ! is_post_type_hierarchical( get_post_type() ) ) {
			$query_string = add_query_arg( 'orderby', 'menu_order title', $query_string );
			$query_string = add_query_arg( 'order', 'asc', $query_string );
		}
		$views['byorder'] = sprintf('<a href="%s" class="%s">%s</a>', $query_string, $class, __("Sort by Order", 'responsive_slider_lite_ordering'));

		return $views;
	}

	/**
	 * Checks to see if the current user has the capability to "edit others" for a post type
	 *
	 * @param string $post_type Post type name
	 * @return bool True or false
	 */
	private static function check_edit_others_caps( $post_type ) {
		$post_type_object = get_post_type_object( $post_type );
		$edit_others_cap = empty( $post_type_object ) ? 'edit_others_' . $post_type . 's' : $post_type_object->cap->edit_others_posts;
		return apply_filters( 'responsive_slider_lite_ordering_edit_rights', current_user_can( $edit_others_cap ), $post_type );
	}
}

Responsive_Slider_Lite_Ordering::get_instance();
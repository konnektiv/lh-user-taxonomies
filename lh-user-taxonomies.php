<?php
/*
Plugin Name: LH User Taxonomies
Plugin URI: https://lhero.org/plugins/lh-user-taxonomies/
Author: Peter Shaw
Author URI: https://shawfactor.com/
Description: Simplify the process of adding support for custom taxonomies for Users. Just use `register_taxonomy` and everything else is taken care of. With added functions by Peter Shaw.
Version:	1.52

License:
Released under the GPL license
http://www.gnu.org/copyleft/gpl.html
Copyright 2014  Peter Shaw  (email : pete@localhero.biz)
This program is free software; you can redistribute it and/or modify it under the terms of the GNU General Public License as published bythe Free Software Foundation; either version 2 of the License, or (at your option) any later version.
This program is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU General Public License for more details.
You should have received a copy of the GNU General Public License along with this program; if not, write to the Free Software Foundation, Inc., 51 Franklin St, Fifth Floor, Boston, MA  02110-1301  USA
*/

class LH_User_Taxonomies_plugin {
	public static $wp_term_relationships = '';
	public static $lh_term_relationships = '';
	public static $taxonomies	= array();
	var $namespace = 'lh_user_taxonomies';



	/**
	 * This is our way into manipulating registered taxonomies
	 * It's fired at the end of the register_taxonomy function
	 *
	 * @param String $taxonomy	- The name of the taxonomy being registered
	 * @param String $object	- The object type the taxonomy is for; We only care if this is "user"
	 * @param Array $args		- The user supplied + default arguments for registering the taxonomy
	 */
	public function registered_taxonomy($taxonomy, $object, $args) {
		global $wp_taxonomies;

		// Only modify user taxonomies, everything else can stay as is
		if ($object != 'user') return;

		// only use for public taxonomies
		if ( ! $args['public'] ) return;

		// We're given an array, but expected to work with an object later on
		$args	= (object) $args;

		// Register any hooks/filters that rely on knowing the taxonomy now
		add_filter("manage_edit-{$taxonomy}_columns",	array($this, 'set_user_column'));
		add_action("manage_{$taxonomy}_custom_column",	array($this, 'set_user_column_values'), 10, 3);

		// Set the callback to update the count if not already set
		if(empty($args->update_count_callback)) {
			$args->update_count_callback	= array($this, 'update_count');
		}

		// We're finished, make sure we save out changes
		$wp_taxonomies[$taxonomy]		= $args;
		self::$taxonomies[$taxonomy]	= $args;
	}

	/**
	 * We need to manually update the number of users for a taxonomy term
	 *
	 * @see	_update_post_term_count()
	 * @param Array $terms		- List of Term taxonomy IDs
	 * @param Object $taxonomy	- Current taxonomy object of terms
	 */
	public function update_count($terms, $taxonomy) {
		global $wpdb;

		$this->set_tables();
		foreach((array) $terms as $term) {
			$count	= $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $wpdb->term_relationships, $wpdb->users WHERE $wpdb->term_relationships.object_id = $wpdb->users.ID and $wpdb->term_relationships.term_taxonomy_id = %d", $term));

			do_action('edit_term_taxonomy', $term, $taxonomy);
			$wpdb->update($wpdb->term_taxonomy, compact('count'), array('term_taxonomy_id'=>$term));
			do_action('edited_term_taxonomy', $term, $taxonomy);
		}
		$this->reset_tables();
	}

	/**
	 * Add each of the taxonomies to the Users menu
	 * They will behave in the same was as post taxonomies under the Posts menu item
	 * Taxonomies will appear in alphabetical order
	 */
	public function admin_menu() {
		// Put the taxonomies in alphabetical order
		$taxonomies	= self::$taxonomies;
		ksort($taxonomies);

		foreach($taxonomies as $key=>$taxonomy) {
			if ( ! $taxonomy->show_in_menu )
				continue;

			add_users_page(
				$taxonomy->labels->menu_name,
				$taxonomy->labels->menu_name,
				$taxonomy->cap->manage_terms,
				"edit-tags.php?taxonomy={$key}&object_type=user"
			);
		}
	}

	/**
	 * Make sure the edit term link for the user terms
	 * will include the object_type parameter
	 *
	 * @access public
	 */
	public function edit_term_link( $link = '', $term_id = 0, $taxonomy = '', $object_type = '' ) {

		if ( empty( $taxonomy ) || ! isset( self::$taxonomies[$taxonomy] ) ||
			 empty( $_GET['object_type'] ) || $_GET['object_type'] != 'user' ) {
			return $link;
		}

		$args = explode('?', $link);
		$query_args = wp_parse_args(  $args[1], array() );

		if ( isset( $query_args['post_type'] ) )
			unset( $query_args['post_type'] );

		$query_args['object_type'] = 'user';

		return add_query_arg( $query_args, get_admin_url( null, 'term.php' ) );
	}

	/**
	 * Fix a bug with highlighting the parent menu item
	 * By default, when on the edit taxonomy page for a user taxonomy, the Posts tab is highlighted
	 * This will correct that bug
	 */
	function parent_menu($parent = '') {
		global $pagenow;

		// If we're editing one of the user taxonomies
		// We must be within the users menu, so highlight that
		if(!empty($_GET['taxonomy']) && ( $pagenow == 'edit-tags.php' || $pagenow == 'term.php' ) && isset(self::$taxonomies[$_GET['taxonomy']]) &&
		   !empty($_GET['object_type']) && $_GET['object_type'] == 'user' ) {
			$parent	= 'users.php';
		}

		return $parent;
	}

	/**
	 * Correct the column names for user taxonomies
	 * Need to replace "Posts" with "Users"
	 */
	public function set_user_column($columns) {
		unset($columns['posts']);
		$columns['users']	= __('Users');
		return $columns;
	}

	/**
	 * Set values for custom columns in user taxonomies
	 */
	public function set_user_column_values($display, $column, $term_id) {
		if('users' === $column) {
			$term	= get_term($term_id, $_REQUEST['taxonomy']);
			echo $term->count;
		}
	}



	private function buildTree( array &$elements, $parentId = 0 ) {
	$branch = array();
		foreach ($elements as $key=>$element) {
			if ($element->parent == $parentId) {
				$children = $this->buildTree($elements, $element->term_id);
					if ($children) {
						$element->children = $children;
						}
				$branch[$element->term_id] = $element;
			unset($elements[$element->$key]);
			}
			}
		return $branch;
	}


	private function renderTree( $elements, $stack, $user, $key, $input = 'checkbox' ) {
		foreach ( $elements as $element ) {
			?>
			<div>
				<input type="<?php echo $input ?>" name="<?php echo $key?>[]" id="<?php echo "{$key}-{$element->slug}"?>" value="<?php echo $element->slug?>" <?php
				if ($user->ID){
					if (in_array($element->slug, $stack)) {
						echo "checked=\"checked\"";
					}
				}
				?> />
				<label for="<?php echo "{$key}-{$element->slug}"?>"><?php echo $element->name ?></label>
				<?php if( isset( $element->children ) ) {
					?><div style="padding-left: 24px;"><?php
						$this->renderTree( $element->children, $stack, $user, $key, $input );
					?></div><?php
				}
			?></div><?php
	    }
	}

	/**
	 * Set needed $wpdb->tables to user specific taxonomy tables
	 *
	 *
	 * @access public
	 * @static
	 */
	public static function set_tables() {
		global $wpdb;

		$wpdb->term_relationships = self::$lh_term_relationships;
	}

	/**
	 * Reset $wpdb->tables to the one set by WordPress
	 *
	 * @access public
	 * @static
	 */
	public static function reset_tables() {
		global $wpdb;

		$wpdb->term_relationships = self::$wp_term_relationships;
	}

	/**
	 * Get user terms
	 *
	 * @access public
	 * @static
	 *
	 * @uses wp_get_object_terms()
	 */
	public static function get_object_terms( $user_id, $taxonomy ) {
		self::set_tables();
		$return = wp_get_object_terms( $user_id, $taxonomy );
		self::reset_tables();
		return $return;
	}

	private static function get_xprofile_field_ids_from_taxonomy( $taxonomy ) {
		global $wpdb, $bp;

		return $wpdb->get_col( $wpdb->prepare( "SELECT object_id FROM {$bp->profile->table_name_meta} WHERE object_type = 'field' AND meta_key = 'taxonomy' AND meta_value = %s", $taxonomy ) );
	}

	public static function update_xprofile_data( $user_id, $tt_ids, $taxonomy ) {

		if ( ! bp_is_active( 'xprofile' ) )
			return;

		$field_ids = self::get_xprofile_field_ids_from_taxonomy( $taxonomy );

		foreach ( $field_ids as $field_id ) {
			foreach ($tt_ids as $tt_id) {
				$term = get_term_by ( 'term_taxonomy_id', $tt_id, $taxonomy );
				$data = new BP_XProfile_ProfileData( $field_id, $user_id );
				$data->value = $term->slug;
				$data->save();
			}
		}
	}

	public static function remove_xprofile_data( $user_id, $taxonomy ) {
		global $wpdb, $bp;

		if ( ! bp_is_active( 'xprofile' ) )
			return;

		$field_ids = self::get_xprofile_field_ids_from_taxonomy( $taxonomy );

		foreach ( $field_ids as $field_id ) {
			$data = new BP_XProfile_ProfileData( $field_id, $user_id );
			$data->delete();
		}
	}

	/**
	 * Set user terms
	 *
	 * @access public
	 * @static
	 *
	 * @uses wp_set_object_terms()
	 */
	public static function set_object_terms( $object_id, $terms, $taxonomy, $append = false, $update_profile = true ) {
		self::set_tables();

		$return = wp_set_object_terms( $object_id, $terms, $taxonomy, $append );

		if ( $update_profile ) {
			self::update_xprofile_data( $object_id, $return, $taxonomy );
		}

		self::reset_tables();
		return $return;
	}

	/**
	 * Remove user terms
	 *
	 * @access public
	 * @static
	 *
	 * @uses wp_remove_object_terms()
	 */
	public static function remove_object_terms( $object_id, $terms, $taxonomy, $update_profile = true ) {
		self::set_tables();

		$return = wp_remove_object_terms( $object_id, $terms, $taxonomy );
		if ( $update_profile ) {
			self::remove_xprofile_data( $object_id, $taxonomy );
		}
		self::reset_tables();
		return $return;
	}


	/**
	 * Remove all user relationships
	 *
	 * @access public
	 * @static
	 *
	 * @uses wp_delete_object_term_relationships()
	 */
	public static function delete_object_term_relationships( $object_id, $taxonomy, $update_profile = true ) {
		self::set_tables();
		$return = wp_delete_object_term_relationships( $object_id, $taxonomy );
		if ( $update_profile ) {
			self::remove_xprofile_data( $object_id, $taxonomy );
		}
		self::reset_tables();
		return $return;
	}

	/**
	 * Get all terms for a given taxonomy
	 *
	 * @access public
	 * @static
	 *
	 * @uses get_terms()
	 */
	public static function get_terms( $taxonomies, $args = '' ) {
		self::set_tables();
		$return = get_terms( $taxonomies, $args );
		self::reset_tables();
		return $return;
	}

	/**
	 * Get term thanks to a specific field
	 *
	 * @access public
	 * @static
	 *
	 * @uses get_term_by()
	 */
	public static function get_term_by( $field, $value, $taxonomy, $output = OBJECT, $filter = 'raw' ) {
		self::set_tables();
		$return = get_term_by( $field, $value, $taxonomy, $output, $filter = 'raw' );
		self::reset_tables();
		return $return;
	}

	/**
	 * Get user ids for a given term
	 *
	 * @access public
	 * @static
	 *
	 * @uses get_objects_in_term()
	 */
	public static function get_objects_in_term( $term_ids, $taxonomies, $args = array() ) {
		self::set_tables();
		$return = get_objects_in_term( $term_ids, $taxonomies, $args );
		self::reset_tables();
		return $return;
	}

	/**
	 * Add the taxonomies to the user view/edit screen
	 *
	 * @param Object $user	- The user of the view/edit screen
	 */
	public function user_profile($user) {

		// Using output buffering as we need to make sure we have something before outputting the header
		// But we can't rely on the number of taxonomies, as capabilities may vary
		ob_start();

		foreach(self::$taxonomies as $key=>$taxonomy):

			if ( ( $output = apply_filters('lh_user_taxonomies_user_profile', false, $user, $key, $taxonomy ) ) ) {
				echo $output;
				continue;
			}

			// Check the current user can assign terms for this taxonomy
			//if(!current_user_can($taxonomy->cap->assign_terms)) continue;
			// Get all the terms in this taxonomy
			$terms		= self::get_terms($key, array('hide_empty'=>false));
			$stack 		= wp_list_pluck( self::get_object_terms( $user->ID, $key ), 'slug' );
			$input_type = ( $taxonomy->single_value ) ? 'radio' : 'checkbox' ;
			?>

				<table class="form-table">
					<tr>
						<th><label for=""><?php _e("Select {$taxonomy->labels->singular_name}")?></label></th>
						<td>
							<?php if(!empty($terms)): ?>

								<?php $this->renderTree( $this->buildTree( $terms ), $stack, $user, $key, $input_type ); ?>

							<?php else:?>
								<?php _e("There are no {$taxonomy->name} available.")?>
							<?php endif?>
						</td>
					</tr>
				</table>

		<?php endforeach; // Taxonomies
		// Output the above if we have anything, with a heading
		$output	= ob_get_clean();
		if(!empty($output)) {
			echo '<h3>', __('Taxonomies'), '</h3>';
			echo $output;
		}
	}

	/**
	 * Save the custom user taxonomies when saving a users profile
	 *
	 * @param Integer $user_id	- The ID of the user to update
	 */
public function save_profile($user_id) {
		foreach(self::$taxonomies as $key=>$taxonomy) {
			// Check the current user can edit this user and assign terms for this taxonomy
			if(current_user_can('edit_user', $user_id) && current_user_can($taxonomy->cap->assign_terms)){

					if (is_array($_POST[$key])){
						$term = $_POST[$key];
						self::set_object_terms($user_id, $term, $key, false);
					} else {
						$term	= esc_attr($_POST[$key]);
						self::set_object_terms($user_id, array($term), $key, false);
					}
				// Save the data
			clean_object_term_cache($user_id, $key);

}
		}
	}

	/**
	 * Usernames can't match any of our user taxonomies
	 * As otherwise it will cause a URL conflict
	 * This method prevents that happening
	 */
	public function restrict_username($username) {
		if(isset(self::$taxonomies[$username])) return '';

		return $username;
	}
	/**
	 * Add columns for columns with
	 * show_admin_column
	 */
	public function lh_user_taxonomies_add_user_id_column($columns) {

		foreach (self::$taxonomies as $taxonomy) {

			if ( $taxonomy->show_admin_column )
				$columns[$taxonomy->name] = $taxonomy->single_value?$taxonomy->labels->singular_name:
					$taxonomy->labels->name;
		}
	    return $columns;
	}
	/**
	 * Just a private function to
	 * populate column content
	 */
	private function lh_user_taxonomies_get_user_taxonomies($user, $taxonomy, $page = null) {
		$terms = self::get_object_terms( $user, $taxonomy);
		if(empty($terms)) { return false; }
		$in = array();
		foreach($terms as $term) {
			$href = empty($page) ? add_query_arg(array($taxonomy => $term->slug), admin_url('users.php')) : add_query_arg(array('user-group' => $term->slug), $page);
			$in[] = sprintf('%s%s%s', '<a href="'.$href.'" title="'.esc_attr($term->description).'">', $term->name, '</a>');
		}
	  	return implode(', ', $in);
	}


/**
 * Get terms for a user and a taxonomy
 *
 * @since 0.1.0
 *
 * @param  mixed  $user
 * @param  int    $taxonomy
 *
 * @return boolean
 */
private function get_terms_for_user( $user = false, $taxonomy = '' ) {

	// Verify user ID
	$user_id = is_object( $user )
		? $user->ID
		: absint( $user );

	// Bail if empty
	if ( empty( $user_id ) ) {
		return false;
	}

	// Return user terms
	return self::get_object_terms( $user_id, $taxonomy, array(
		'fields' => 'all_with_object_id'
	) );
}

private function set_terms_for_user( $user_id, $taxonomy, $terms = array(), $bulk = false ) {

	// Get the taxonomy
	$tax = get_taxonomy( $taxonomy );

	// Make sure the current user can edit the user and assign terms before proceeding.
	if ( ! current_user_can( 'edit_user', $user_id ) && current_user_can( $tax->cap->assign_terms ) ) {
		return false;
	}

	if ( empty( $terms ) && empty( $bulk ) ) {
		$terms = isset( $_POST[ $taxonomy ] )
			? $_POST[ $taxonomy ]
			: null;
	}

	// Delete all user terms
	if ( is_null( $terms ) || empty( $terms ) ) {
		self::delete_object_term_relationships( $user_id, $taxonomy );

	// Set the terms
	} else {
		$_terms = array_map( 'sanitize_key', $terms );

		// Sets the terms for the user
		self::set_object_terms( $user_id, $_terms, $taxonomy, false );
	}

	// Clean the cache
	clean_object_term_cache( $user_id, $taxonomy );
}


	/**
	 * Add the column content
	 *
	 */
	public function lh_user_taxonomies_add_taxonomy_column_content($value, $column_name, $user_id) {
		if (taxonomy_exists($column_name)) {
			return $this->lh_user_taxonomies_get_user_taxonomies($user_id,$column_name);
		} else {
		    return $value;
		}
	}
	/**
	 * Alters the User query
	 * to return a different list based on query vars on users.php
	 */
	public function user_query($Query = '') {
		global $pagenow,$wpdb;
		if ( $pagenow == 'users.php' ){

			foreach (self::$taxonomies as $taxonomy) {
				if(!empty($_GET[$taxonomy->name])) {
					$term = self::get_term_by('slug', esc_attr($_GET[$taxonomy->name]), $taxonomy->name);
					$new_ids = self::get_objects_in_term($term->term_id, $taxonomy->name);
					if (!isset($ids) || empty($ids)){
						$ids = $new_ids;
					} else {
						$ids = array_intersect($ids, $new_ids);
					}
				}
			}
			if ( isset( $ids ) ){
				$ids = implode(',', wp_parse_id_list( $ids ) );
				$Query->query_where .= " AND $wpdb->users.ID IN ($ids)";
			}
		}
	}

/**
	 * Handle bulk editing of users
	 *
	 */
	public function bulk_edit_action() {

		// Action if it is a bulk edit request

		//need to fix this nonce and name are same

		if ( ! isset( $_POST[$this->namespace."-bulk_edit-taxonomy"] ) )
			return;

		if ( ! wp_verify_nonce($_POST[$this->namespace."-bulk_edit-nonce"], $this->namespace."-bulk_edit-nonce" ) )
			return;

		$taxonomy = $_POST[$this->namespace."-bulk_edit-taxonomy"];

		// Setup the empty users array
		$users = array();

		// Get an array of users from the string
		parse_str( urldecode( $_POST[ $taxonomy . '-bulk_users_to_action'] ), $users );



		if ( empty( $users['users'] ) ) {
			return;
		}

		$users    = $users['users'];


		$action   = strstr( $_POST[$this->namespace."-bulk_edit-action"], '-', true );
		$term     = str_replace( $action, '', $_POST[$this->namespace."-bulk_edit-action"] );

		foreach ( $users as $user ) {


			if ( current_user_can( 'edit_user', $user ) ) {

				// Get term slugs of user for this taxonomy
				$terms = $this->get_terms_for_user( $user, $taxonomy);

				$update_terms = wp_list_pluck( $terms, 'slug' );


				// Adding
				if ( 'add' === $action ) {
					if ( ! in_array( $term, $update_terms ) ) {
						$update_terms[] = $term;
					}

				// Removing
				} elseif ( 'remove' === $action ) {
					$index = array_search( $term, $update_terms );
					if ( isset( $update_terms[ $index ] ) ) {
						unset( $update_terms[ $index ] );
					}
				} elseif ( 'set' === $action ) {
					$update_terms = array( $term );
				} elseif ( 'unset' === $action ) {
					$update_terms = null;
				}

				// Delete all groups if they're empty
				if ( empty( $update_terms ) ) {
					$update_terms = null;
				}

				// Update terms for users
				if ( $update_terms !== $terms ) {


					$this->set_terms_for_user( $user, $taxonomy, $update_terms, true );
				}
			}
		}

		// Success
		wp_safe_redirect( admin_url( 'users.php' ) );
		die;

	}


	/**
	 * Output the bulk edit markup where show_admin_column is true
	 *
	 *
	 * @param   type  $views
	 * @return  type
	 */
	public function bulk_edit( $views = array() ) {

		// Bail if user cannot edit other users
		if ( ! current_user_can( 'list_users' ) ) {
			return $views;
		}

		foreach (self::$taxonomies as $taxonomy){
			if ( ! $taxonomy->show_admin_column )
				continue;

			$terms = self::get_terms( $taxonomy->name, array('hide_empty' => false ) );


		?>
		<form method="post" class="user-tax-form">
			<fieldset class="alignleft">
				<legend class="screen-reader-text"><?php esc_html_e( 'Update Groups', $this->namespace ); ?></legend>

				<input name="<?php echo esc_attr( $taxonomy->name ); ?>-bulk_users_to_action" value="" type="hidden" id="<?php echo esc_attr( $taxonomy->name ); ?>-bulk_users_to_action" class="user-tax-users-input" />

				<label for="<?php echo esc_attr( $taxonomy->name ); ?>-select" class="screen-reader-text">
					<?php echo esc_html( $taxonomy->labels->name ); ?>
				</label>

				<select class="tax-picker" name="<?php echo esc_attr( $this->namespace ); ?>-bulk_edit-action" id="<?php echo esc_attr( $this->namespace ); ?>-<?php echo esc_attr( $taxonomy->name ); ?>-bulk_edit-action" required="required">
					<option value=""><?php printf( esc_html__( '%s Bulk Update', $this->namespace ), ($taxonomy->single_value?$taxonomy->labels->singular_name:$taxonomy->labels->name) ); ?></option>

					<?php if ( $taxonomy->single_value ) { ?>
						<option value="unset-all"><?php printf( esc_html__( 'Remove %s', $this->namespace ), $taxonomy->labels->singular_name ) ; ?></option>
					<?php } ?>

					<optgroup label="<?php $taxonomy->single_value?esc_html_e( 'Set', $this->namespace ):esc_html_e( 'Add', $this->namespace ); ?>">

						<?php foreach ( $terms as $term ) : ?>

							<option value="<?php echo ($taxonomy->single_value?'set':'add') ?>-<?php echo esc_attr( $term->slug ); ?>"><?php echo esc_html( $term->name ); ?></option>

						<?php endforeach; ?>

					</optgroup>


					<?php if ( ! $taxonomy->single_value ) { ?>
					<optgroup label="<?php esc_html_e( 'Remove', $this->namespace ); ?>">

						<?php foreach ( $terms as $term ) : ?>

							<option value="remove-<?php echo esc_attr( $term->slug ); ?>"><?php echo esc_html( $term->name ); ?></option>

						<?php endforeach; ?>

					</optgroup>
					<?php } ?>

				</select>

				<input id="<?php echo $this->namespace;  ?>-<?php echo $taxonomy->name;  ?>-bulk_edit-nonce" name="<?php  echo $this->namespace;  ?>-bulk_edit-nonce" value="<?php echo wp_create_nonce($this->namespace."-bulk_edit-nonce"); ?>" type="hidden" />
				<input id="<?php echo $this->namespace;  ?>-<?php echo $taxonomy->name;  ?>-bulk_edit-taxonomy" name="<?php  echo $this->namespace;  ?>-bulk_edit-taxonomy" value="<?php echo $taxonomy->name; ?>" type="hidden" />

				<?php submit_button( esc_html__( 'Apply' ), 'action', $taxonomy->name . '-submit', false ); ?>

			</fieldset>
		</form>


		<?php } ?>

		<script type="text/javascript">
			jQuery( document ).ready( function( $ ) {
				$( '.tablenav .actions:last' ).after( $('.user-tax-form' ).wrap('<div class="alignleft actions"></div>').parent() );
				$( '.user-tax-form' ).on( 'submit', function(e) {
					var users = $( '.wp-list-table.users .check-column input:checked' ).serialize();
					$(this).find('.user-tax-users-input').val( users );
				} );
			} );
		</script>

		<?php


		return $views;
	}


	function bp_include() {
		if ( bp_is_active( 'xprofile' ) ) {
			require_once( 'classes/class-bp-xprofile-field-type-taxonomy.php');
		}
	}

	function xprofile_field_types( $field_types ) {
		$field_types['taxonomy']  = 'BP_XProfile_Field_Type_Taxonomy';
		return $field_types;
	}

	/**
	 * Create tables
	 *
	 * @access private
	 */
	public static function create_tables() {
		global $wpdb, $blog_id;

		$sql = array();
		$blog_prefix = ($blog_id > 1)?"{$blog_id}_":"";
		$charset_collate = !empty( $wpdb->charset ) ? "DEFAULT CHARACTER SET $wpdb->charset" : '';

		$sql[] = "CREATE TABLE {$wpdb->base_prefix}{$blog_prefix}user_term_relationships (
				object_id bigint(20) unsigned NOT NULL DEFAULT '0',
			  	term_taxonomy_id bigint(20) unsigned NOT NULL DEFAULT '0',
			  	term_order int(11) NOT NULL DEFAULT '0',
			  	PRIMARY KEY (object_id,term_taxonomy_id),
			  	KEY term_taxonomy_id (term_taxonomy_id)
			) {$charset_collate};";

		require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

		dbDelta( $sql );
	}

	/**
	 * Check for current term before member query is made
	 *
	 */
	public function set_current_term() {

		if ( is_tax( $this->taxonomies ) ) {
			$this->current_term = get_queried_object();
			$this->add_members_term_cookie( $this->current_term );
		} else {
			$this->remove_members_term_cookie();
		}
	}

	function get_members_term_from_cookie() {

		if ( ! defined( 'DOING_AJAX' ) || ! DOING_AJAX ) {
			return false;
		}

		if ( ! empty( $_POST['cookie'] ) )
			$_BP_COOKIE = wp_parse_args( str_replace( '; ', '&', urldecode( $_POST['cookie'] ) ) );
		else
			$_BP_COOKIE = &$_COOKIE;

		if ( ! empty( $_BP_COOKIE['bp-member-term'] ) ) {
			return json_decode( $_BP_COOKIE['bp-member-term'] );
		}

		return false;
    }

	// add taxonomy query to bp user queries
	function filter_member_sql( $sql, $user_query ) {
		global $wpdb;

		$current_term = false;

		if ( $this->current_term )
			$current_term = $this->current_term;
		else
		 	$current_term = $this->get_members_term_from_cookie();


		if ( ! $current_term )
			return $sql;

		$tax_query = new WP_Tax_Query( array(
			array(
				'taxonomy' => $current_term->taxonomy,
				'field' => 'term_id',
				'terms'    => $current_term->term_id,
			),
		) );

		self::set_tables();
		$sql_clauses = $tax_query->get_sql( 'u', $user_query->uid_name );

		if ( preg_match( '/' . self::$lh_term_relationships . '\.term_taxonomy_id IN \([0-9, ]+\)/', $sql_clauses['where'], $matches ) ) {
			$clause = "u.{$user_query->uid_name} IN ( SELECT object_id FROM " . self::$lh_term_relationships . " WHERE {$matches[0]} )";
			$sql['where'][] = $clause;
		}
		self::reset_tables();

		return $sql;
	}

	function remove_members_term_cookie() {
		unset( $_COOKIE['bp-member-term'] );
  		setcookie( 'bp-member-term', '', time() - ( 15 * 60 ) );
	}

	function add_members_term_cookie( $term ) {
		setcookie( 'bp-member-term', json_encode($term), 30 * DAYS_IN_SECONDS, '/' );
	}

	/**
	 * Register all the hooks and filters we can in advance
	 * Some will need to be registered later on, as they require knowledge of the taxonomy name
	 */
	public function __construct() {
		global $wpdb, $blog_id;

		self::$wp_term_relationships = $wpdb->term_relationships;
		$blog_prefix = ($blog_id > 1)?"{$blog_id}_":"";
		self::$lh_term_relationships = $wpdb->base_prefix . $blog_prefix . 'user_term_relationships';

		// Taxonomies
		add_action('registered_taxonomy', array($this, 'registered_taxonomy'), 10, 3);

		// Menus
		add_action('admin_menu', array($this, 'admin_menu'));
		add_filter('parent_file', array($this, 'parent_menu'));
		add_filter( 'get_edit_term_link', array( $this, 'edit_term_link'  ), 10, 4 );

		// User Profiles
		add_action('show_user_profile',	array($this, 'user_profile'));
		add_action('edit_user_profile',	array($this, 'user_profile'));
		add_action('personal_options_update', array($this, 'save_profile'));
		add_action('edit_user_profile_update', array($this, 'save_profile'));
		add_action('user_register', array($this, 'save_profile'));
		add_filter('sanitize_user', array($this, 'restrict_username'));
		add_filter('manage_users_columns', array($this, 'lh_user_taxonomies_add_user_id_column'));
		add_action('manage_users_custom_column', array($this, 'lh_user_taxonomies_add_taxonomy_column_content'), 10, 3);
        add_action('pre_user_query', array($this, 'user_query'));

        // buddypress members loop
        add_filter( 'bp_user_query_uid_clauses', array( $this, 'filter_member_sql' ), 10, 2 );
        add_action( 'template_redirect', array( $this, 'set_current_term' ) );

		// Bulk edit
		add_filter( 'views_users', array( $this, 'bulk_edit') );
		add_action( 'admin_init',  array( $this, 'bulk_edit_action' ) );

		// add buddpress xprofile field type
		add_action( 'bp_include', array( $this, 'bp_include') );
		add_filter( 'bp_xprofile_get_field_types', array( $this, 'xprofile_field_types') );
	}




}

$GLOBALS['lh_user_taxonomies_instance'] = new LH_User_Taxonomies_plugin;
register_activation_hook( __FILE__, array( 'LH_User_Taxonomies_plugin', 'create_tables' ) );
<?php
/**
 * BuddyPress XProfile Classes.
 *
 * @package BuddyPress
 * @subpackage XProfileClasses
 * @since 2.0.0
 */

// Exit if accessed directly.
defined( 'ABSPATH' ) || exit;

/**
 * Selectbox xprofile field type.
 *
 * @since 2.0.0
 */
class BP_XProfile_Field_Type_Taxonomy extends BP_XProfile_Field_Type {

	/**
	 * Constructor for the selectbox field type.
	 *
	 * @since 2.0.0
	 */
	public function __construct() {
		parent::__construct();

		$this->category = _x( 'Multi Fields', 'xprofile field type category', 'buddypress' );
		$this->name     = _x( 'Taxonomy Select Box', 'xprofile field type', 'buddypress' );

		$this->do_settings_section = true;

		$this->set_format( '/^.+$/', 'replace' );

		if ( ! has_action( 'xprofile_data_before_save', array( 'BP_XProfile_Field_Type_Taxonomy' , 'before_save') ) )
			add_action( 'xprofile_data_before_save', array( 'BP_XProfile_Field_Type_Taxonomy' , 'before_save') );

		if ( ! has_action( 'xprofile_data_before_delete', array( 'BP_XProfile_Field_Type_Taxonomy' , 'before_delete') ) )
			add_action( 'xprofile_data_before_delete', array( 'BP_XProfile_Field_Type_Taxonomy', 'before_delete') );


		/**
		 * Fires inside __construct() method for BP_XProfile_Field_Type_Taxonomy class.
		 *
		 * @since 2.0.0
		 *
		 * @param BP_XProfile_Field_Type_Taxonomy $this Current instance of
		 *                                               the field type select box.
		 */
		do_action( 'BP_XProfile_Field_Type_Taxonomy', $this );
	}

	private static function maybe_unserialize_terms( $terms ) {
		$terms = maybe_unserialize( $terms );
		return is_array( $terms ) ? $terms : array( $terms );
	}

	private static function get_current_terms( $profile_data ) {
		$terms = BP_XProfile_ProfileData::get_data_for_user( $profile_data->user_id, array( $profile_data->field_id ) );

		$terms = reset( $terms );
		$terms = $terms->value;
		return self::maybe_unserialize_terms( $terms );
	}

	private static function get_terms_to_delete( $profile_data, $settings, $old_terms = null, $new_terms = array() ) {

		if ( ! $old_terms )
			$old_terms = self::get_current_terms( $profile_data );

		// check if old terms are set by any other profile field for this taxonomy
		$field_ids = LH_User_Taxonomies_plugin::get_xprofile_field_ids_from_taxonomy( $settings['taxonomy'] );

		// only get fields which are synced with the taxonomy
		$field_ids = array_filter( $field_ids, array( 'BP_XProfile_Field_Type_Taxonomy', 'is_sync_to_terms_field' ) );

		$other_terms = array();
		foreach ( $field_ids as $field_id ) {
			if ( $field_id == $profile_data->field_id )
				continue;

			$data = new BP_XProfile_ProfileData( $field_id, $profile_data->user_id );
			$other_terms = array_merge( $other_terms, self::maybe_unserialize_terms( $data->value ) );
		}

		// only return terms which are not set by other fields and which are not in
		// new terms
		return array_diff( $old_terms, $other_terms, $new_terms );
	}

	static function before_save($profile_data) {

		if ( ! self::is_sync_to_terms_field( $profile_data->field_id ) )
			return;

		$settings = self::get_field_settings( $profile_data->field_id );

		$new_terms = self::maybe_unserialize_terms( $profile_data->value );

		// get current terms
		$current_terms = self::get_current_terms( $profile_data );

		// nothing to do
		if ( $new_terms == $current_terms )
			return;

		// get terms to delete
		$old_terms = self::get_terms_to_delete( $profile_data, $settings, $current_terms, $new_terms );

		// remove old terms
		LH_User_Taxonomies_plugin::remove_object_terms( $profile_data->user_id, $old_terms, $settings['taxonomy'], false );

		// set new terms
		LH_User_Taxonomies_plugin::set_object_terms( $profile_data->user_id, $new_terms, $settings['taxonomy'], true, false );

	}

	static function before_delete( $profile_data ) {

		if ( ! self::is_sync_to_terms_field( $profile_data->field_id ) )
			return;

		$settings = self::get_field_settings( $profile_data->field_id );

		$terms = self::get_terms_to_delete( $profile_data, $settings );

		LH_User_Taxonomies_plugin::remove_object_terms( $profile_data->user_id, $terms, $settings['taxonomy'], false );
	}

	/**
	 * Get settings for a given date field.
	 *
	 * @since 2.7.0
	 *
	 * @param int $field_id ID of the field.
	 * @return array
	 */
	public static function get_field_settings( $field_id ) {
		$defaults = array(
			'taxonomy'              => null,
			'sync_terms'            => false,
			'sync_terms_to_profile' => false,
			'multiple'              => false,
			'empty_label'           => esc_html__( 'Choose your %s', 'buddypress' ),
			'display'               => 'select'
		);

		$settings = array();
		foreach ( $defaults as $key => $value ) {
			$saved = bp_xprofile_get_meta( $field_id, 'field', $key, true );

			if ( $saved ) {
				$settings[ $key ] = $saved;
			} else {
				$settings[ $key ] = $value;
			}
		}

		return $settings;
	}

	public static function is_sync_to_terms_field( $field_id ) {
		$settings = self::get_field_settings( $field_id );
		return (bool) $settings['sync_terms'];
	}

	public static function is_sync_to_profile_field( $field_id ) {
		$settings = self::get_field_settings( $field_id );
		return (bool) $settings['sync_terms_to_profile'];
	}

	public static function is_multiple_field( $field_id ) {
		$settings = self::get_field_settings( $field_id );
		return (bool) $settings['multiple'];
	}

	/**
	 * Save settings from the field edit screen in the Dashboard.
	 *
	 * @param int   $field_id ID of the field.
	 * @param array $settings Array of settings.
	 * @return bool True on success.
	 */
	public function admin_save_settings( $field_id, $settings ) {
		global $wpdb, $bp;

		$existing_settings = self::get_field_settings( $field_id );
		$add_to_terms = false;

		$saved_settings = array();
		foreach ( array_keys( $existing_settings ) as $setting ) {
			switch ( $setting ) {

				case 'sync_terms':
					$add_to_terms = isset( $settings[ $setting ] ) && ! $existing_settings[ $setting ];

				case 'sync_terms_to_profile':
				case 'multiple':
					$saved_settings[ $setting ] = ( isset( $settings[ $setting ] ) );
					break;
				default :
					if ( isset( $settings[ $setting ] ) ) {
						$saved_settings[ $setting ] = $settings[ $setting ];
					}
				break;
			}
		}

		foreach ( $saved_settings as $setting_key => $setting_value ) {

		    if ( $setting_key == 'empty_label' ) {

		        // register empty label for string translation
			    do_action( 'wpml_register_single_string', 'lh_user_taxonomies', "$saved_settings[taxonomy]_empty_label_$field_id", $setting_value );
            }

			bp_xprofile_update_meta( $field_id, 'field', $setting_key, $setting_value );
		}

		// if sync to terms has been switched on, set terms from profile data
		if ( $add_to_terms ) {
			$results = $wpdb->get_results( "SELECT user_id, value FROM {$bp->profile->table_name_data} WHERE field_id = $field_id" );

			foreach ( $results as $result ) {
				$terms = $this->maybe_unserialize_terms( $result->value );

				LH_User_Taxonomies_plugin::set_object_terms( $result->user_id, $terms, $saved_settings['taxonomy'], true, false );
			}
		}

		return true;
	}

	/**
	 * Output the edit field HTML for this field type.
	 *
	 * Must be used inside the {@link bp_profile_fields()} template loop.
	 *
	 * @since 2.0.0
	 *
	 * @param array $raw_properties Optional key/value array of
	 *                              {@link http://dev.w3.org/html5/markup/select.html permitted attributes}
	 *                              that you want to add.
	 */
	public function edit_field_html( array $raw_properties = array() ) {

		// User_id is a special optional parameter that we pass to
		// {@link bp_the_profile_field_options()}.
		if ( isset( $raw_properties['user_id'] ) ) {
			$user_id = (int) $raw_properties['user_id'];
			unset( $raw_properties['user_id'] );
		} else {
			$user_id = bp_displayed_user_id();
		} ?>

		<label for="<?php bp_the_profile_field_input_name(); ?>">
			<?php bp_the_profile_field_name(); ?>
			<?php bp_the_profile_field_required_label(); ?>
		</label>

		<?php

		/** This action is documented in bp-xprofile/bp-xprofile-classes */
		do_action( bp_get_the_profile_field_errors_action() ); ?>

		<?php
		$this->get_field_html( $raw_properties, array( 'user_id' => $user_id ) ); ?>
		<p class="description"><?php bp_the_profile_field_description(); ?></p><?php
	}

	/**
	 * Get all child fields for this field ID.
	 *
	 * @since 1.2.0
	 *
	 * @global object $wpdb
	 *
	 * @param bool $for_editing Whether or not the field is for editing.
	 * @return array
	 */
	public function get_children( $for_editing = false ) {

		$settings = self::get_field_settings( $this->field_obj->id );

		$children = LH_User_Taxonomies_plugin::get_terms( $settings['taxonomy'], array( 'hide_empty' => false ) );

		if ( is_wp_error( $children) ) {
			$children = array( (object)array(
				'name' => $children->get_error_message(),
				'slug' => 'error' )
			);
		}

		/**
		 * Filters the found children for a field.
		 *
		 * @since 1.2.5
		 *
		 * @param object $children    Found children for a field.
		 * @param bool   $for_editing Whether or not the field is for editing.
		 */
		return apply_filters( 'bp_xprofile_field_taxonomy_get_children', $children, $for_editing );
	}

	/**
	 * Output the edit field options HTML for this field type.
	 *
	 * BuddyPress considers a field's "options" to be, for example, the items in a selectbox.
	 * These are stored separately in the database, and their templating is handled separately.
	 *
	 * This templating is separate from {@link BP_XProfile_Field_Type::edit_field_html()} because
	 * it's also used in the wp-admin screens when creating new fields, and for backwards compatibility.
	 *
	 * Must be used inside the {@link bp_profile_fields()} template loop.
	 *
	 * @since 2.0.0
	 *
	 * @param array $args Optional. The arguments passed to {@link bp_the_profile_field_options()}.
	 */
	public function edit_field_options_html( array $args = array() ) {
		$settings = self::get_field_settings( $this->field_obj->id );
		$original_option_values = apply_filters( 'bp_taxonomy_profile_field_original_option_values',
            maybe_unserialize( BP_XProfile_ProfileData::get_value_byid( $this->field_obj->id, $args['user_id'] ) ),
            $this->field_obj->id, $args['user_id'] );
		$tax = get_taxonomy( $settings['taxonomy'] );

		$options = $this->get_children();
		/* translators: no option picked in select box */
		$empty_label = apply_filters( 'wpml_translate_single_string', $settings['empty_label'], 'lh_user_taxonomies', "$settings[taxonomy]_empty_label_{$this->field_obj->id}" );
		$empty_label = sprintf( $empty_label, $tax->labels->singular_name );

		if ( ! $settings['multiple'] && $settings['display'] === 'select' ) {
			$html    = '<option value="">' . $empty_label . '</option>';
		}

		if ( empty( $original_option_values ) && !empty( $_POST['field_' . $this->field_obj->id] ) ) {
			$original_option_values = sanitize_text_field(  $_POST['field_' . $this->field_obj->id] );
		}

		$option_values = ( $original_option_values ) ? (array) $original_option_values : array();

		$option_values = array_map( function( $slug ) use ( $settings ) {
			$term = get_term_by( 'slug', $slug, $settings['taxonomy'] );
			return $term->slug;
		}, $option_values );

		for ( $k = 0, $count = count( $options ); $k < $count; ++$k ) {
			$selected = '';

			// Check for updated posted values, but errors preventing them from
			// being saved first time.
			foreach( $option_values as $i => $option_value ) {
				if ( isset( $_POST['field_' . $this->field_obj->id] ) && $_POST['field_' . $this->field_obj->id] != $option_value ) {
					if ( ! empty( $_POST['field_' . $this->field_obj->id] ) ) {
						$option_values[$i] = sanitize_text_field( $_POST['field_' . $this->field_obj->id] );
					}
				}
			}

			// Run the allowed option name through the before_save filter, so
			// we'll be sure to get a match.
			$allowed_options = xprofile_sanitize_data_value_before_save( $options[$k]->slug, false, false );

			// First, check to see whether the user-entered value matches.
			if ( in_array( $allowed_options, $option_values ) ||
			     // Then, if the user has not provided a value, check for defaults.
			     ! is_array( $original_option_values ) && empty( $option_values ) && $options[ $k ]->is_default_option ) {
				$selected = $settings['display'] === 'select' ? ' selected="selected"' : ' checked';
			}

			if ( $settings['display'] === 'select' ) {
				$input = '<option' . $selected . ' value="' . esc_attr( stripslashes( $options[ $k ]->slug ) ) . '">' . esc_html( stripslashes( $options[ $k ]->name ) ) . '</option>';
			} else {
                $input = '<label class="taxonomy-field-item"><input type=' . ( $settings['multiple'] ? 'checkbox' : 'radio' )  . $selected .
                         ' value="' . esc_attr( stripslashes( $options[ $k ]->slug ) ) . '"' .
                         ' name="' . bp_get_the_profile_field_input_name()  . ( $settings['multiple'] ? '[]' : '' ) . '"  />' .
                         esc_html( stripslashes( $options[ $k ]->name ) ) . '</label>';
			}
			/**
			 * Filters the HTML output for options in a select input.
			 *
			 * @since 1.1.0
			 *
			 * @param string $value    Option tag for current value being rendered.
			 * @param object $value    Current option being rendered for.
			 * @param int    $id       ID of the field object being rendered.
			 * @param string $selected Current selected value.
			 * @param string $k        Current index in the foreach loop.
			 */
			$html .= apply_filters( 'bp_get_the_profile_field_options_taxonomy', $input, $options[$k], $this->field_obj->id, $selected, $k );
		}

		echo $html;
	}

	/** Protected *************************************************************/

	/**
	 * Get a sanitised and escaped string of the edit field's HTML elements and attributes.
	 *
	 * Must be used inside the {@link bp_profile_fields()} template loop.
	 * This method was intended to be static but couldn't be because php.net/lsb/ requires PHP >= 5.3.
	 *
	 * @since 2.0.0
	 *
	 * @param array $properties Optional key/value array of attributes for this edit field.
	 * @return string
	 */
	protected function get_edit_field_html_elements( array $properties = array() ) {
		global $field;
		$settings = self::get_field_settings( $field->id );

		if ( isset( $settings['multiple'] ) && $settings['multiple'] ) {
			$properties['multiple'] = 'true';
			$properties['id']       = bp_get_the_profile_field_input_name() . '[]';
			$properties['name']     = bp_get_the_profile_field_input_name() . '[]';
		}

		return parent::get_edit_field_html_elements( $properties );
	}


	/**
	 * Output HTML for this field type on the wp-admin Profile Fields screen.
	 *
	 * Must be used inside the {@link bp_profile_fields()} template loop.
	 *
	 * @since 2.0.0
	 *
	 * @param array $raw_properties Optional key/value array of permitted attributes that you want to add.
	 */
	public function admin_field_html( array $raw_properties = array() ) {

		?><label for="<?php bp_the_profile_field_input_name(); ?>" class="screen-reader-text"><?php
            /* translators: accessibility text */
            esc_html_e( 'Select', 'buddypress' );
		?></label>
		<?php
		$this->get_field_html( $raw_properties );
	}

	function get_field_html( array $raw_properties = array(), $args = null ) {
		global $field;
		$settings = self::get_field_settings( $field->id );

		if ( isset( $settings['display'] ) && $settings['display'] === 'select' ) {
			?><select <?php echo $this->get_edit_field_html_elements( $raw_properties ); ?>><?php
		}
		bp_the_profile_field_options( $args );
		if ( isset( $settings['display'] ) && $settings['display'] === 'select' ) {
			?></select><?php
		}
	}

	public static function display_filter( $field_value, $field_id = '' ) {
		$settings = self::get_field_settings( $field_id );
		$values = explode(',', $field_value);
		$terms = array();

		foreach ( $values as $value ) {
			$term = get_term_by( 'slug', $value, $settings['taxonomy'] );
			$terms[] = empty( $term ) ? $value : $term->name;
		}

		return implode( ',', $terms );
	}

	/**
	 * Output HTML for this field type's children options on the wp-admin Profile Fields "Add Field" and "Edit Field" screens.
	 *
	 * Must be used inside the {@link bp_profile_fields()} template loop.
	 *
	 * @since 2.0.0
	 *
	 * @param BP_XProfile_Field $current_field The current profile field on the add/edit screen.
	 * @param string            $control_type  Optional. HTML input type used to render the current
	 *                                         field's child options.
	 */
	/**
	 * Output HTML for this field type's children options on the wp-admin Profile Fields "Add Field" and "Edit Field" screens.
	 *
	 * You don't need to implement this method for all field types. It's used in core by the
	 * selectbox, multi selectbox, checkbox, and radio button fields, to allow the admin to
	 * enter the child option values (e.g. the choices in a select box).
	 *
	 * Must be used inside the {@link bp_profile_fields()} template loop.
	 *
	 * @since 2.0.0
	 *
	 * @param BP_XProfile_Field $current_field The current profile field on the add/edit screen.
	 * @param string            $control_type  Optional. HTML input type used to render the current
	 *                          field's child options.
	 */
	public function admin_new_field_html( BP_XProfile_Field $current_field, $control_type = '' ) {
		$type = array_search( get_class( $this ), bp_xprofile_get_field_types() );
		if ( false === $type ) {
			return;
		}

		$class            = $current_field->type != $type ? 'display: none;' : '';
		$current_type_obj = bp_xprofile_create_field_type( $type );
		$settings = self::get_field_settings( $current_field->id );
		?>

		<div id="<?php echo esc_attr( $type ); ?>" class="postbox bp-options-box" style="<?php echo esc_attr( $class ); ?> margin-top: 15px;">
			<h3><?php esc_html_e( 'Please enter options for this Field:', 'buddypress' ); ?></h3>
			<div class="inside" aria-live="polite" aria-atomic="true" aria-relevant="all">
				<p>
					<label for="sort_order_<?php echo esc_attr( $type ); ?>"><?php esc_html_e( 'Sort Order:', 'buddypress' ); ?></label>
					<select name="sort_order_<?php echo esc_attr( $type ); ?>" id="sort_order_<?php echo esc_attr( $type ); ?>" >
						<option value="asc"    <?php selected( 'asc',    $current_field->order_by ); ?>><?php esc_html_e( 'Ascending',  'buddypress' ); ?></option>
						<option value="desc"   <?php selected( 'desc',   $current_field->order_by ); ?>><?php esc_html_e( 'Descending', 'buddypress' ); ?></option>
					</select>
				</p>
				<p>
					<label for="taxonomy_<?php echo esc_attr( $type ); ?>"><?php esc_html_e( 'Taxonomy:', 'buddypress' ); ?></label>
					<select name="field-settings[taxonomy]" id="taxonomy_<?php echo esc_attr( $type ); ?>" >
						<?php foreach ($GLOBALS['lh_user_taxonomies_instance']::$taxonomies as $taxonomy => $tax) { ?>
							<option value="<?php echo $taxonomy ?>" <?php selected( $taxonomy, $settings['taxonomy'] ); ?>><?php echo $tax->labels->name ?></option>
						<?php } ?>
					</select>
				</p>
				<p>
					<label for="empty_label_<?php echo esc_attr( $type ); ?>"><?php esc_html_e( 'Empty Label:', 'buddypress' ); ?></label>
					<input type="text" value="<?php echo $settings['empty_label'] ?>" name="field-settings[empty_label]" id="empty_label_<?php echo esc_attr( $type ); ?>" >
				</p>
				<p>
					<label for="sync_terms_<?php echo esc_attr( $type ); ?>"><?php esc_html_e( 'Synchronise profile field to user terms:', 'buddypress' ); ?></label>
					<input type="checkbox" value="1" <?php checked( $settings['sync_terms'] ); ?> name="field-settings[sync_terms]" id="sync_terms_<?php echo esc_attr( $type ); ?>" >
				</p>
				<p>
					<label for="sync_terms_to_profile_<?php echo esc_attr( $type ); ?>"><?php esc_html_e( 'Synchronise user terms to profile field:', 'buddypress' ); ?></label>
					<input type="checkbox" value="1" <?php checked( $settings['sync_terms_to_profile'] ); ?> name="field-settings[sync_terms_to_profile]" id="sync_terms_to_profile_<?php echo esc_attr( $type ); ?>" >
				</p>
				<p>
					<label for="multiple_<?php echo esc_attr( $type ); ?>"><?php esc_html_e( 'Allow multiple values:', 'buddypress' ); ?></label>
					<input type="checkbox" value="1" <?php checked( $settings['multiple'] ); ?> name="field-settings[multiple]" id="multiple_<?php echo esc_attr( $type ); ?>" >
				</p>
                <p>
                    <label for="display_<?php echo esc_attr( $type ); ?>"><?php esc_html_e( 'Display:', 'buddypress' ); ?></label>
                    <select name="field-settings[display]" id="display_<?php echo esc_attr( $type ); ?>" >
                        <option value="select" <?php selected( 'select', $settings['display'] ); ?>><?php  esc_html_e( 'Select box', 'buddypress' ); ?></option>
                        <option value="checkbox_radio" <?php selected( 'checkbox_radio', $settings['display'] ); ?>><?php  esc_html_e( 'Check boxes/Radio buttons', 'buddypress' ); ?></option>
                    </select>
                </p>

				<?php

				/**
				 * Fires at the end of the new field additional settings area.
				 *
				 * @since 2.3.0
				 *
				 * @param BP_XProfile_Field $current_field Current field being rendered.
				 */
				do_action( 'bp_xprofile_admin_new_field_additional_settings', $current_field ) ?>
			</div>
		</div>

		<?php
	}
}

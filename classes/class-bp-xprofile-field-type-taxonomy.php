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

		add_action( 'xprofile_data_before_save', array( $this , 'before_save') );
		add_action( 'xprofile_data_before_delete', array( $this, 'before_delete') );


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

	function before_save($profile_data) {

		if ( $profile_data->field_id != $this->field_obj->id )
			return;

		$settings = self::get_field_settings( $this->field_obj->id );

		LH_User_Taxonomies_plugin::set_object_terms( $profile_data->user_id, array($profile_data->value), $settings['taxonomy'], false, false );
	}

	function before_delete($profile_data) {

		if ( $profile_data->field_id != $this->field_obj->id )
			return;

		$settings = self::get_field_settings( $this->field_obj->id );

		$terms = wp_list_pluck( LH_User_Taxonomies_plugin::get_object_terms( $profile_data->user_id, $settings['taxonomy'] ), 'slug' );

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
			'taxonomy'          => null,
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

	/**
	 * Save settings from the field edit screen in the Dashboard.
	 *
	 * @param int   $field_id ID of the field.
	 * @param array $settings Array of settings.
	 * @return bool True on success.
	 */
	public function admin_save_settings( $field_id, $settings ) {
		$existing_settings = self::get_field_settings( $field_id );

		$saved_settings = array();
		foreach ( array_keys( $existing_settings ) as $setting ) {
			switch ( $setting ) {

				default :
					if ( isset( $settings[ $setting ] ) ) {
						$saved_settings[ $setting ] = $settings[ $setting ];
					}
				break;
			}
		}

		foreach ( $saved_settings as $setting_key => $setting_value ) {
			bp_xprofile_update_meta( $field_id, 'field', $setting_key, $setting_value );
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

		<select <?php echo $this->get_edit_field_html_elements( $raw_properties ); ?>>
			<?php bp_the_profile_field_options( array( 'user_id' => $user_id ) ); ?>
		</select>

		<?php
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
		$original_option_values = wp_list_pluck( LH_User_Taxonomies_plugin::get_object_terms( $args['user_id'], $settings['taxonomy'] ), 'slug' );
		$tax = get_taxonomy( $settings['taxonomy'] );

		$options = $this->get_children();
		$html    = '<option value="">' . /* translators: no option picked in select box */ sprintf( esc_html__( 'Choose your %s', 'buddypress' ), $tax->labels->singular_name ) . '</option>';

		if ( empty( $original_option_values ) && !empty( $_POST['field_' . $this->field_obj->id] ) ) {
			$original_option_values = sanitize_text_field(  $_POST['field_' . $this->field_obj->id] );
		}

		$option_values = ( $original_option_values ) ? (array) $original_option_values : array();
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
			if ( in_array( $allowed_options, $option_values ) ) {
				$selected = ' selected="selected"';
			}

			// Then, if the user has not provided a value, check for defaults.
			if ( ! is_array( $original_option_values ) && empty( $option_values ) && $options[$k]->is_default_option ) {
				$selected = ' selected="selected"';
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
			$html .= apply_filters( 'bp_get_the_profile_field_options_taxonomy', '<option' . $selected . ' value="' . esc_attr( stripslashes( $options[$k]->slug ) ) . '">' . esc_html( stripslashes( $options[$k]->name ) ) . '</option>', $options[$k], $this->field_obj->id, $selected, $k );
		}

		echo $html;
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
		?>

		<label for="<?php bp_the_profile_field_input_name(); ?>" class="screen-reader-text"><?php
			/* translators: accessibility text */
			esc_html_e( 'Select', 'buddypress' );
		?></label>
		<select <?php echo $this->get_edit_field_html_elements( $raw_properties ); ?>>
			<?php bp_the_profile_field_options(); ?>
		</select>

		<?php
	}

	public static function display_filter( $field_value, $field_id = '' ) {
		$settings = self::get_field_settings( $field_id );
		$terms = LH_User_Taxonomies_plugin::get_object_terms( bp_displayed_user_id(), $settings['taxonomy'] );

		if ( ! empty( $terms ) ) {
			$field_value = implode(', ', wp_list_pluck( $terms, 'name' ) );
		}
		return $field_value;
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

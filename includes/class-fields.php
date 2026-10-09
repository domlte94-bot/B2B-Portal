<?php
/**
 * Configurable fields: the field builder used for personalization
 * (name, title…) and for quote request forms.
 *
 * @package B2BCP
 */

defined( 'ABSPATH' ) || exit;

/**
 * Field definitions: sanitize from admin input, render on the front end, validate and format answers.
 */
class B2BCP_Fields {

	/**
	 * Field types available to each builder.
	 *
	 * @param string $context 'person' or 'quote'.
	 * @return array<string,string>
	 */
	public static function types( $context = 'person' ) {
		$types = array(
			'text'     => __( 'Short text', 'b2b-contract-portal' ),
			'select'   => __( 'Dropdown', 'b2b-contract-portal' ),
			'textarea' => __( 'Note / long text', 'b2b-contract-portal' ),
		);
		if ( 'quote' === $context ) {
			$types['number']   = __( 'Number', 'b2b-contract-portal' );
			$types['checkbox'] = __( 'Checkbox', 'b2b-contract-portal' );
			$types['file']     = __( 'File upload', 'b2b-contract-portal' );
		}
		return $types;
	}

	/**
	 * Text formats applied to typed answers.
	 *
	 * @return array<string,string>
	 */
	public static function formats() {
		return array(
			'none'  => __( 'As typed', 'b2b-contract-portal' ),
			'title' => __( 'Title Case', 'b2b-contract-portal' ),
			'upper' => __( 'UPPERCASE', 'b2b-contract-portal' ),
		);
	}

	/**
	 * Sanitize the builder rows posted from the product screen.
	 *
	 * @param mixed  $raw     Posted rows.
	 * @param string $context 'person' or 'quote'.
	 * @return array[]
	 */
	public static function sanitize_definitions( $raw, $context = 'person' ) {
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$types  = self::types( $context );
		$out    = array();
		$seen   = array();
		foreach ( $raw as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$label = isset( $row['label'] ) ? sanitize_text_field( wp_unslash( $row['label'] ) ) : '';
			if ( '' === $label ) {
				continue;
			}
			$key = isset( $row['key'] ) && '' !== trim( (string) $row['key'] ) ? sanitize_key( wp_unslash( $row['key'] ) ) : sanitize_key( remove_accents( str_replace( ' ', '_', $label ) ) );
			if ( '' === $key ) {
				$key = 'field';
			}
			$base = $key;
			$n    = 2;
			while ( isset( $seen[ $key ] ) ) {
				$key = $base . '_' . $n++;
			}
			$seen[ $key ] = true;

			$type    = isset( $row['type'], $types[ $row['type'] ] ) ? $row['type'] : 'text';
			$format  = isset( $row['format'] ) && array_key_exists( $row['format'], self::formats() ) ? $row['format'] : 'none';
			$options = array();
			if ( 'select' === $type && ! empty( $row['options'] ) ) {
				$options = array_values( array_filter( array_map( 'trim', explode( ',', sanitize_text_field( wp_unslash( $row['options'] ) ) ) ), 'strlen' ) );
			}

			$out[] = array(
				'key'       => $key,
				'label'     => $label,
				'type'      => $type,
				'required'  => ! empty( $row['required'] ),
				'maxlength' => isset( $row['maxlength'] ) ? max( 0, (int) $row['maxlength'] ) : 0,
				'format'    => $format,
				'options'   => $options,
			);
		}
		return $out;
	}

	/**
	 * Apply the field's text format.
	 *
	 * @param string $value Value.
	 * @param array  $field Definition.
	 * @return string
	 */
	public static function format_value( $value, array $field ) {
		$value = trim( preg_replace( '/\s+/u', ' ', (string) $value ) );
		if ( 'title' === $field['format'] ) {
			$value = mb_convert_case( mb_strtolower( $value, 'UTF-8' ), MB_CASE_TITLE, 'UTF-8' );
		} elseif ( 'upper' === $field['format'] ) {
			$value = mb_strtoupper( $value, 'UTF-8' );
		}
		return $value;
	}

	/**
	 * Clean and validate one answer.
	 *
	 * @param mixed $raw   Raw value (unslashed).
	 * @param array $field Definition.
	 * @return array{0:string,1:string} [ value, error message or '' ]
	 */
	public static function clean_answer( $raw, array $field ) {
		if ( 'checkbox' === $field['type'] ) {
			$value = $raw ? __( 'Yes', 'b2b-contract-portal' ) : '';
		} elseif ( 'textarea' === $field['type'] ) {
			$value = trim( sanitize_textarea_field( (string) $raw ) );
		} else {
			$value = self::format_value( sanitize_text_field( (string) $raw ), $field );
		}

		if ( 'select' === $field['type'] && '' !== $value && ! in_array( $value, $field['options'], true ) ) {
			/* translators: %s: field label */
			return array( '', sprintf( __( '%s: choose an option from the list.', 'b2b-contract-portal' ), $field['label'] ) );
		}
		if ( 'number' === $field['type'] && '' !== $value && ! is_numeric( $value ) ) {
			/* translators: %s: field label */
			return array( '', sprintf( __( '%s must be a number.', 'b2b-contract-portal' ), $field['label'] ) );
		}
		if ( $field['required'] && '' === $value ) {
			/* translators: %s: field label */
			return array( '', sprintf( __( '%s is required.', 'b2b-contract-portal' ), $field['label'] ) );
		}
		if ( $field['maxlength'] && mb_strlen( $value, 'UTF-8' ) > $field['maxlength'] ) {
			/* translators: 1: field label, 2: character limit, 3: the typed value */
			return array( '', sprintf( __( '%1$s can have at most %2$d characters ("%3$s" is too long for the space on the product).', 'b2b-contract-portal' ), $field['label'], $field['maxlength'], $value ) );
		}
		return array( $value, '' );
	}

	/**
	 * Render one input.
	 *
	 * @param array  $field Definition.
	 * @param string $name  Input name attribute.
	 * @param string $value Current value.
	 * @param string $id    Optional id attribute.
	 */
	public static function render_input( array $field, $name, $value = '', $id = '' ) {
		$attrs = sprintf( ' name="%s"%s%s', esc_attr( $name ), $id ? ' id="' . esc_attr( $id ) . '"' : '', $field['required'] ? ' required' : '' );
		$attrs .= ' aria-label="' . esc_attr( $field['label'] ) . '"';
		if ( $field['maxlength'] ) {
			$attrs .= ' maxlength="' . (int) $field['maxlength'] . '"';
		}
		switch ( $field['type'] ) {
			case 'select':
				echo '<select' . $attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
				echo '<option value="">' . esc_html__( 'Choose…', 'b2b-contract-portal' ) . '</option>';
				foreach ( $field['options'] as $option ) {
					printf( '<option value="%1$s"%2$s>%3$s</option>', esc_attr( $option ), selected( $value, $option, false ), esc_html( $option ) );
				}
				echo '</select>';
				break;
			case 'textarea':
				echo '<textarea rows="2"' . $attrs . '>' . esc_textarea( $value ) . '</textarea>'; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'number':
				echo '<input type="number" step="any"' . $attrs . ' value="' . esc_attr( $value ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'checkbox':
				echo '<input type="checkbox" value="1"' . $attrs . checked( (bool) $value, true, false ) . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			case 'file':
				echo '<input type="file" accept=".jpg,.jpeg,.png,.pdf,.ai,.eps"' . $attrs . '>'; // phpcs:ignore WordPress.Security.EscapeOutput
				break;
			default:
				$css = '' !== $field['format'] && 'none' !== $field['format'] ? ' data-format="' . esc_attr( $field['format'] ) . '"' : '';
				echo '<input type="text"' . $attrs . $css . ' value="' . esc_attr( $value ) . '">'; // phpcs:ignore WordPress.Security.EscapeOutput
		}
	}

	/**
	 * Admin builder table (used on the product screen).
	 *
	 * @param string $name    Base input name, e.g. b2bcp_fields.
	 * @param array  $fields  Current definitions.
	 * @param string $context 'person' or 'quote'.
	 */
	public static function render_builder( $name, array $fields, $context = 'person' ) {
		$types = self::types( $context );
		?>
		<table class="widefat b2bcp-repeater b2bcp-field-builder" data-name="<?php echo esc_attr( $name ); ?>">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Label', 'b2b-contract-portal' ); ?></th>
					<th><?php esc_html_e( 'Type', 'b2b-contract-portal' ); ?></th>
					<th><?php esc_html_e( 'Required', 'b2b-contract-portal' ); ?></th>
					<th><?php esc_html_e( 'Max chars', 'b2b-contract-portal' ); ?></th>
					<th><?php esc_html_e( 'Format', 'b2b-contract-portal' ); ?></th>
					<th><?php esc_html_e( 'Options (comma separated)', 'b2b-contract-portal' ); ?></th>
					<th></th>
				</tr>
			</thead>
			<tbody>
				<?php
				foreach ( $fields as $i => $field ) {
					self::builder_row( $name, (string) $i, $field, $types );
				}
				?>
			</tbody>
			<tfoot>
				<tr><td colspan="7"><button type="button" class="button b2bcp-add-row"><?php esc_html_e( '+ Add field', 'b2b-contract-portal' ); ?></button></td></tr>
			</tfoot>
		</table>
		<script type="text/html" class="b2bcp-row-template">
			<?php
			self::builder_row(
				$name,
				'__i__',
				array(
					'key'       => '',
					'label'     => '',
					'type'      => 'text',
					'required'  => false,
					'maxlength' => 0,
					'format'    => 'none',
					'options'   => array(),
				),
				$types
			);
			?>
		</script>
		<?php
	}

	/**
	 * One builder row.
	 *
	 * @param string $name  Base name.
	 * @param string $i     Row index.
	 * @param array  $field Definition.
	 * @param array  $types Allowed types.
	 */
	private static function builder_row( $name, $i, array $field, array $types ) {
		$base = $name . '[' . $i . ']';
		?>
		<tr>
			<td>
				<input type="text" name="<?php echo esc_attr( $base ); ?>[label]" value="<?php echo esc_attr( $field['label'] ); ?>" placeholder="<?php esc_attr_e( 'e.g. Name', 'b2b-contract-portal' ); ?>">
				<input type="hidden" name="<?php echo esc_attr( $base ); ?>[key]" value="<?php echo esc_attr( $field['key'] ); ?>">
			</td>
			<td>
				<select name="<?php echo esc_attr( $base ); ?>[type]">
					<?php foreach ( $types as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $field['type'], $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td><input type="checkbox" name="<?php echo esc_attr( $base ); ?>[required]" value="1" <?php checked( $field['required'] ); ?>></td>
			<td><input type="number" min="0" class="small-text" name="<?php echo esc_attr( $base ); ?>[maxlength]" value="<?php echo $field['maxlength'] ? (int) $field['maxlength'] : ''; ?>"></td>
			<td>
				<select name="<?php echo esc_attr( $base ); ?>[format]">
					<?php foreach ( self::formats() as $value => $label ) : ?>
						<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $field['format'], $value ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
			<td><input type="text" name="<?php echo esc_attr( $base ); ?>[options]" value="<?php echo esc_attr( implode( ', ', $field['options'] ) ); ?>"></td>
			<td><button type="button" class="button-link b2bcp-remove-row" aria-label="<?php esc_attr_e( 'Remove field', 'b2b-contract-portal' ); ?>">&times;</button></td>
		</tr>
		<?php
	}
}

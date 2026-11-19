<?php
/**
 * The site's business identity: NAP, hours, geo, and profile URLs. Stored as
 * one option, `optima_business`, because it is site identity, not per-post data.
 *
 * optima-schema reads this to build LocalBusiness JSON-LD; templates can pull
 * any value through the `optima/business` block binding. One source of truth for
 * the NAP that has to match the Google Business Profile byte-for-byte.
 *
 * @package Optima_Core
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Optima_Core_Business {

	const OPTION = 'optima_business';

	/** @return array<string,string> field key => label */
	public static function fields() {
		return array(
			'name'          => 'Business name',
			'legal_name'    => 'Legal name',
			'business_type' => 'Business type in search',
			'phone'         => 'Phone',
			'email'         => 'Email',
			'street'        => 'Street address',
			'city'          => 'City',
			'region'        => 'State / region',
			'postal'        => 'Postal code',
			'country'       => 'Country code',
			'price_range'   => 'Price range (e.g. $$)',
			'latitude'      => 'Latitude',
			'longitude'     => 'Longitude',
			'hours'         => 'Hours (one line per day)',
			'same_as'       => 'Profile addresses (one per line: Google, Facebook, Yelp)',
		);
	}

	/** @return array<string,string> */
	public static function get() {
		$defaults = array_fill_keys( array_keys( self::fields() ), '' );
		return wp_parse_args( (array) get_option( self::OPTION, array() ), $defaults );
	}

	public static function admin_menu() {
		add_options_page( 'Optima Business', 'Optima Business', 'manage_options', 'optima-business', array( __CLASS__, 'render_page' ) );
	}

	public static function register_settings() {
		register_setting(
			'optima_business_group',
			self::OPTION,
			array( 'sanitize_callback' => array( __CLASS__, 'sanitize' ) )
		);
	}

	/**
	 * Schema types to pick from (Details shows a list, not a typed name). A value
	 * saved before release 3 that is not in the list stays and shows as its own choice.
	 *
	 * `Attorney` is no longer offered: schema.org deprecates it for a firm. A site
	 * that saved it keeps the saved value; the schema prints it as LegalService
	 * (see schema_type()).
	 *
	 * @return array<string,string> type => plain label
	 */
	public static function types() {
		return (array) apply_filters(
			'optima_core_business_types',
			array(
				'LegalService'       => 'Law firm (LegalService)',
				'LocalBusiness'      => 'Local business (LocalBusiness)',
				'ProfessionalService' => 'Professional service (ProfessionalService)',
				'MedicalBusiness'    => 'Medical practice (MedicalBusiness)',
				'Restaurant'         => 'Restaurant (Restaurant)',
				'FoodEstablishment'  => 'Food and catering (FoodEstablishment)',
				'Campground'         => 'Campground or RV park (Campground)',
				'HomeAndConstructionBusiness' => 'Home and construction (HomeAndConstructionBusiness)',
				'Store'              => 'Shop (Store)',
				'Organization'       => 'Organization (Organization)',
			)
		);
	}

	/**
	 * The schema type to print for a saved business type. A saved `Attorney`
	 * prints as `LegalService`; the saved value itself never changes.
	 */
	public static function schema_type( $type ) {
		$type = (string) $type;
		return 'Attorney' === $type ? 'LegalService' : $type;
	}

	/** Is the hours box owned by Optima Hours (rule 45: hours typed on one tab only)? */
	public static function hours_elsewhere() {
		return class_exists( 'Optima_Hours' );
	}

	public static function sanitize( $input ) {
		$input = (array) $input;
		$clean = array();
		// A field the form did not show (hours while Optima Hours owns them, keys a
		// newer form adds) keeps its saved value: a save never blanks what it did not show.
		$saved = self::get();
		foreach ( self::fields() as $key => $label ) {
			if ( ! array_key_exists( $key, $input ) ) {
				$clean[ $key ] = (string) $saved[ $key ];
				continue;
			}
			$value = $input[ $key ];
			if ( in_array( $key, array( 'hours', 'same_as' ), true ) ) {
				$clean[ $key ] = sanitize_textarea_field( $value );
			} elseif ( 'email' === $key ) {
				$clean[ $key ] = sanitize_email( $value );
			} else {
				$clean[ $key ] = sanitize_text_field( $value );
			}
		}
		return $clean;
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$values = self::get();
		?>
		<div class="wrap">
			<h1>Optima Business Identity</h1>
			<p>One source of truth for NAP and profiles. optima-schema renders this as LocalBusiness JSON-LD; templates can bind to it with the <code>optima/business</code> source.</p>
			<form method="post" action="options.php">
				<?php settings_fields( 'optima_business_group' ); ?>
				<table class="form-table" role="presentation"><tbody>
				<?php foreach ( self::fields() as $key => $label ) : ?>
					<tr>
						<th scope="row"><label for="optima-<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></label></th>
						<td>
						<?php if ( in_array( $key, array( 'hours', 'same_as' ), true ) ) : ?>
							<textarea id="optima-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( self::OPTION ); ?>[<?php echo esc_attr( $key ); ?>]" rows="5" class="large-text"><?php echo esc_textarea( $values[ $key ] ); ?></textarea>
						<?php else : ?>
							<input type="text" id="optima-<?php echo esc_attr( $key ); ?>" name="<?php echo esc_attr( self::OPTION ); ?>[<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $values[ $key ] ); ?>" class="regular-text" />
						<?php endif; ?>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody></table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}
}

/**
 * Public getter for the business identity array. Used by optima-schema, the
 * `optima/business` binding, and anywhere a template needs the canonical NAP.
 *
 * @return array<string,string>
 */
function optima_core_business() {
	return Optima_Core_Business::get();
}

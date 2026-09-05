<?php
/**
 * Speedy sender-origin contract.
 *
 * A contract client identifies the merchant object and its address. Handing the
 * parcel over at a Speedy office changes the pickup place, but must not discard
 * that contract client from the calculation or shipment payload.
 *
 * Run: php tests/test-speedy-sender-origin.php
 */

namespace BgCommerce3\Modules\Shipping {
	abstract class Abstract_Courier {}
	abstract class Abstract_Client {}
	interface Locations_Provider {}
}

namespace BgCommerce3\Support {
	class Selection {
		public $delivery_type = '';
		public $office = array();
	}
	class Price_Result {}
	class Tracking_Result {}
	class Shipping_Availability {}
	class Label_Pdf_Store {}
	class Setup_Status {}

	class Sync_Result {
		public static function success( $message, array $metrics = array(), array $updated = array() ) {
			return new self();
		}

		public static function error( $message, array $details = array() ) {
			return new self();
		}
	}
}

namespace BgCommerce3\Shipping {
	class Office_Store {}
	class Cod {}
	class Pricing {
		const MODE_API = 'api';
		const MODE_OWN = 'own';
	}
}

namespace BgCommerce3\Admin {
	class Icons {}
}

namespace BgCommerce3\Container {
	class Container {}
}

namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'BGCS3_PATH', dirname( __DIR__ ) . DIRECTORY_SEPARATOR );
	define( 'HOUR_IN_SECONDS', 3600 );
	define( 'DAY_IN_SECONDS', 86400 );

	$GLOBALS['bgcs_options'] = array();
	$GLOBALS['bgcs_transients'] = array();

	function get_option( $name, $default = false ) {
		return array_key_exists( $name, $GLOBALS['bgcs_options'] ) ? $GLOBALS['bgcs_options'][ $name ] : $default;
	}

	function update_option( $name, $value, $autoload = null ) {
		$GLOBALS['bgcs_options'][ $name ] = $value;
		return true;
	}

	function get_transient( $name ) {
		return array_key_exists( $name, $GLOBALS['bgcs_transients'] ) ? $GLOBALS['bgcs_transients'][ $name ] : false;
	}

	function set_transient( $name, $value, $expiration = 0 ) {
		$GLOBALS['bgcs_transients'][ $name ] = $value;
		return true;
	}

	function delete_transient( $name ) {
		if ( ! array_key_exists( $name, $GLOBALS['bgcs_transients'] ) ) {
			return false;
		}
		unset( $GLOBALS['bgcs_transients'][ $name ] );
		return true;
	}

	function apply_filters( $hook, $value = null ) {
		return $value;
	}

	function __( $text, $domain = null ) {
		return $text;
	}

	function sanitize_key( $value ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) );
	}

	function sanitize_text_field( $value ) {
		return is_scalar( $value ) ? trim( strip_tags( (string) $value ) ) : '';
	}

	function bgcs3_substr( $value, $start, $length = null ) {
		return null === $length ? mb_substr( $value, $start ) : mb_substr( $value, $start, $length );
	}

	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}

	function bgcs3_get_option( $group, $key = null, $default = null ) {
		return \BgCommerce3\Support\Options::get( $group, $key, $default );
	}

	class WP_Error {}

	require BGCS3_PATH . 'app/Support/Options.php';
	require BGCS3_PATH . 'app/Support/Module_Settings.php';
	require BGCS3_PATH . 'app/Support/Cache.php';
	require BGCS3_PATH . 'app/Modules/Shipping/Speedy/Client.php';
	require BGCS3_PATH . 'app/Modules/Shipping/Speedy/Locations.php';
	require BGCS3_PATH . 'app/Modules/Shipping/Speedy/Speedy.php';

	use BgCommerce3\Modules\Shipping\Speedy\Locations;
	use BgCommerce3\Modules\Shipping\Speedy\Speedy;
	use BgCommerce3\Support\Module_Settings;

	$failures = 0;

	function sender_check( $condition, $message ) {
		global $failures;
		echo ( $condition ? '  [PASS] ' : '  [FAIL] ' ) . $message . PHP_EOL;
		if ( ! $condition ) {
			$failures++;
		}
	}

	class Sender_Fake_Client {
		/** @var array<int,array<string,mixed>> */
		public $clients;

		public function __construct( array $clients = array() ) {
			$this->clients = $clients;
		}

		public function has_credentials() {
			return true;
		}

		public function validate_credentials() {
			return array( 'ok' => true, 'clients' => $this->clients );
		}
	}

	class Sender_Fake_Locations {
		public $contracts;
		public $offices;
		public $cached_clients = array();
		public $refresh_contract_calls = 0;
		public $refresh_office_calls = 0;

		public function __construct( array $contracts = array(), array $offices = array() ) {
			$this->contracts = $contracts;
			$this->offices   = $offices;
		}

		public function cached_services() {
			return array();
		}

		public function cached_contracts() {
			return $this->contracts;
		}

		public function contracts() {
			return $this->contracts;
		}

		public function refresh_contracts() {
			$this->refresh_contract_calls++;
			return $this->contracts;
		}

		public function cached_all_offices_options() {
			return $this->offices;
		}

		public function refresh_all_offices_options() {
			$this->refresh_office_calls++;
			return $this->offices;
		}

		public function cache_contract_clients( array $clients ) {
			$this->cached_clients = $clients;
			return $this->contracts;
		}
	}

	class Sender_Test_Speedy extends Speedy {
		private $test_client;
		private $test_locations;

		public function __construct( $client = null, $locations = null ) {
			$this->test_client    = $client ? $client : new Sender_Fake_Client();
			$this->test_locations = $locations ? $locations : new Sender_Fake_Locations();
		}

		public function client() {
			return $this->test_client;
		}

		public function locations() {
			return $this->test_locations;
		}
	}

	Module_Settings::prime(
		Speedy::ID,
		array(
			'client_id'            => array( 'default' => '' ),
			'sender_handover'      => array( 'default' => 'address' ),
			'dropoff_office_id'    => array( 'default' => '' ),
			'show_office'          => array( 'default' => 'yes' ),
			'show_locker'          => array( 'default' => 'yes' ),
			'show_address'         => array( 'default' => 'yes' ),
			'locker_capacity_policy' => array( 'default' => Speedy::LOCKER_CAPACITY_POLICY_MERCHANT_INSTRUCTION ),
			'locker_capacity_note' => array( 'default' => Speedy::DEFAULT_LOCKER_CAPACITY_NOTE ),
			'cod_processing'       => array( 'default' => 'CASH' ),
			'administrative_fee'   => array( 'default' => 'no' ),
		)
	);

	function set_sender_options( array $values ) {
		$GLOBALS['bgcs_options']['bgcs3_speedy'] = $values;
		Module_Settings::flush( Speedy::ID );
	}

	function sender_payload( Speedy $speedy, array $waybill = array() ) {
		$method = new ReflectionMethod( Speedy::class, 'sender' );
		$method->setAccessible( true );
		return $method->invoke( $speedy, $waybill );
	}

	echo "--- Sender payload keeps the contract object authoritative ---\n";
	$speedy = new Sender_Test_Speedy();

	set_sender_options( array( 'client_id' => '101', 'sender_handover' => 'address', 'dropoff_office_id' => '202' ) );
	sender_check(
		array( 'clientId' => 101 ) === sender_payload( $speedy ),
		'Pickup from the contracted address sends only clientId'
	);

	set_sender_options( array( 'client_id' => '101', 'sender_handover' => 'office', 'dropoff_office_id' => '202' ) );
	sender_check(
		array( 'clientId' => 101, 'dropoffOfficeId' => 202 ) === sender_payload( $speedy ),
		'Office handover keeps clientId and adds dropoffOfficeId'
	);

	set_sender_options( array( 'client_id' => '', 'sender_handover' => 'office', 'dropoff_office_id' => '202' ) );
	sender_check(
		array() === sender_payload( $speedy ),
		'An office without a contracted sender object is not accepted as configured'
	);

	set_sender_options( array( 'client_id' => '101', 'sender_handover' => 'address', 'dropoff_office_id' => '202' ) );
	sender_check(
		array( 'clientId' => 303, 'dropoffOfficeId' => 404 ) === sender_payload(
			$speedy,
			array( 'x' => array( 'sender_type' => 'office', 'sender_client_id' => '303', 'sender_dropoff_office_id' => '404' ) )
		),
		'Per-order office handover sends its client and office together'
	);

	sender_check(
		array( 'clientId' => 101, 'dropoffOfficeId' => 505 ) === sender_payload(
			$speedy,
			array( 'x' => array( 'sender_type' => 'office', 'sender_dropoff_office_id' => '505' ) )
		),
		'Per-order office handover inherits the configured contract object'
	);

	echo "--- Sender settings expose object and handover as separate decisions ---\n";
	set_sender_options( array( 'client_id' => '101', 'client_label' => '#101 · Main warehouse' ) );
	$locations = new Sender_Fake_Locations(
		array( '101' => '#101 · Main warehouse — Sofia' ),
		array( '202' => 'Speedy Central — Sofia (#202)' )
	);
	$fields = ( new Sender_Test_Speedy( new Sender_Fake_Client(), $locations ) )->settings_fields();
	sender_check( isset( $fields['sender_handover'] ), 'The sender handover mode is a normal setting' );
	sender_check(
		isset( $fields['dropoff_office_id']['show_if']['sender_handover'] )
			&& 'office' === $fields['dropoff_office_id']['show_if']['sender_handover'],
		'The sender office is shown for office handover, not for an empty client id'
	);
	sender_check(
		in_array( 'sender_handover', $fields_in_sender_section = ( new Sender_Test_Speedy() )->settings_sections()[1]['fields'], true ),
		'The sender handover mode is rendered in the Sender settings section'
	);
	sender_check(
		Speedy::LOCKER_CAPACITY_POLICY_MERCHANT_INSTRUCTION === $fields['locker_capacity_policy']['default'],
		'The merchant hold instruction is the default full-locker policy'
	);
	sender_check(
		Speedy::DEFAULT_LOCKER_CAPACITY_NOTE === $fields['locker_capacity_note']['default'],
		'The requested Bulgarian locker instruction is the default shipment note'
	);
	sender_check(
		in_array( 'locker_capacity_policy', ( new Sender_Test_Speedy() )->settings_sections()[2]['fields'], true )
			&& in_array( 'locker_capacity_note', ( new Sender_Test_Speedy() )->settings_sections()[2]['fields'], true ),
		'The full-locker policy is visible in the main Shipping and pricing section'
	);
	$settings_page_source = php_strip_whitespace( dirname( __DIR__ ) . '/app/Admin/Settings/Settings_Page.php' );
	$workspace_start      = strpos( $settings_page_source, "'speedy' => array(" );
	$workspace_end        = strpos( $settings_page_source, "'econt' => array(", $workspace_start );
	$workspace_source     = false !== $workspace_start && false !== $workspace_end
		? substr( $settings_page_source, $workspace_start, $workspace_end - $workspace_start )
		: '';
	sender_check(
		false !== strpos( $workspace_source, "'locker_capacity_policy'" )
			&& false !== strpos( $workspace_source, "'locker_capacity_note'" ),
		'The task-oriented Speedy admin workspace renders and saves both full-locker fields'
	);

	$shipment_note = new ReflectionMethod( Speedy::class, 'shipment_note' );
	$shipment_note->setAccessible( true );
	$locker_selection = new \BgCommerce3\Support\Selection();
	$locker_selection->delivery_type = 'locker';
	$locker_selection->office = array( 'id' => 606 );
	$office_selection = new \BgCommerce3\Support\Selection();
	$office_selection->delivery_type = 'office';

	set_sender_options(
		array(
			'locker_capacity_policy' => Speedy::LOCKER_CAPACITY_POLICY_MERCHANT_INSTRUCTION,
			'locker_capacity_note'   => Speedy::DEFAULT_LOCKER_CAPACITY_NOTE,
		)
	);
	sender_check(
		Speedy::DEFAULT_LOCKER_CAPACITY_NOTE === $shipment_note->invoke( $speedy, array(), $locker_selection ),
		'A locker shipment with no order note receives the configured merchant instruction'
	);
	$recipient = new ReflectionMethod( Speedy::class, 'recipient' );
	$recipient->setAccessible( true );
	$locker_recipient = $recipient->invoke( $speedy, $locker_selection, array(), true );
	sender_check(
		606 === $locker_recipient['pickupOfficeId'] && isset( $locker_recipient['autoSelectNearestOffice'] ) && false === $locker_recipient['autoSelectNearestOffice'],
		'A selected locker is explicit and nearest-office auto-selection is disabled'
	);
	sender_check(
		'' === $shipment_note->invoke( $speedy, array(), $office_selection ),
		'The locker instruction never leaks into an office shipment'
	);
	sender_check(
		'Ръчна забележка' === $shipment_note->invoke( $speedy, array( 'x' => array( 'note' => 'Ръчна забележка' ) ), $locker_selection ),
		'An order-specific shipment note overrides the configured locker instruction'
	);
	set_sender_options( array( 'locker_capacity_policy' => Speedy::LOCKER_CAPACITY_POLICY_SPEEDY_DEFAULT ) );
	sender_check(
		'' === $shipment_note->invoke( $speedy, array(), $locker_selection ),
		'The merchant can explicitly restore standard Speedy behaviour'
	);

	$empty_fields = ( new Sender_Test_Speedy( new Sender_Fake_Client(), new Sender_Fake_Locations() ) )->settings_fields();
	sender_check(
		'select' === $empty_fields['client_id']['type'],
		'A missing cache never turns the contracted sender object into a manual text id'
	);
	$waybill_fields = ( new Sender_Test_Speedy( new Sender_Fake_Client(), $locations ) )->waybill_fields();
	sender_check(
		'Courier pickup from contracted address' === $waybill_fields['sender_type']['options']['client']
			&& 'Drop off at Speedy office' === $waybill_fields['sender_type']['options']['office'],
		'Per-order controls describe handover modes rather than exclusive sender sources'
	);

	echo "--- Connection and nomenclature data remain truthful ---\n";
	$raw_clients = array(
		array(
			'clientId'     => 101,
			'clientName'   => 'Merchant Ltd',
			'objectName'   => 'Main warehouse',
			'contactName'  => 'Ivan Petrov',
			'email'        => 'warehouse@example.com',
			'privatePerson' => false,
			'address'      => array( 'fullAddressString' => 'Sofia 1000, 1 Test St' ),
		),
	);
	$cache_locations = new Sender_Fake_Locations( array( '101' => '#101 · Main warehouse' ) );
	( new Sender_Test_Speedy( new Sender_Fake_Client( $raw_clients ), $cache_locations ) )->check_connection();
	sender_check(
		$raw_clients === $cache_locations->cached_clients,
		'Connection check caches the clients it already received from Speedy'
	);

	set_sender_options( array( 'client_id' => '101', 'sender_handover' => 'address' ) );
	$refresh_locations = new Sender_Fake_Locations( array( '101' => '#101 · Main warehouse' ) );
	( new Sender_Test_Speedy( new Sender_Fake_Client( $raw_clients ), $refresh_locations ) )->refresh_sender_data();
	sender_check(
		1 === $refresh_locations->refresh_contract_calls,
		'Explicit sender refresh bypasses the cached contract list'
	);

	set_sender_options( array( 'client_id' => '101', 'sender_handover' => 'office', 'dropoff_office_id' => '202' ) );
	$office_refresh_locations = new Sender_Fake_Locations(
		array( '101' => '#101 · Main warehouse' ),
		array( '202' => 'Speedy Central — Sofia (#202)' )
	);
	( new Sender_Test_Speedy( new Sender_Fake_Client( $raw_clients ), $office_refresh_locations ) )->refresh_sender_data();
	sender_check(
		1 === $office_refresh_locations->refresh_contract_calls && 1 === $office_refresh_locations->refresh_office_calls,
		'Office-handover refresh validates both the contract object and drop-off office from fresh data'
	);

	$locations_ref = ( new ReflectionClass( Locations::class ) )->newInstanceWithoutConstructor();
	$contract_method = new ReflectionMethod( Locations::class, 'contract_options' );
	$contract_method->setAccessible( true );
	$contract_options = $contract_method->invoke( $locations_ref, $raw_clients );
	sender_check(
		isset( $contract_options['101'] )
			&& false !== strpos( $contract_options['101'], 'Sofia 1000, 1 Test St' )
			&& false !== strpos( $contract_options['101'], 'warehouse@example.com' ),
		'Contract labels show the address and email needed to identify the sender object'
	);

	if ( method_exists( Locations::class, 'cache_contract_clients' ) ) {
		$locations_ref->cache_contract_clients( $raw_clients );
		$real_cached_contracts = $locations_ref->cached_contracts();
		sender_check(
			isset( $real_cached_contracts['101'] ),
			'The real locations provider persists connection-check clients for screen rendering'
		);
	} else {
		sender_check( false, 'The real locations provider persists connection-check clients for screen rendering' );
	}

	$office_method = new ReflectionMethod( Locations::class, 'office_options' );
	$office_method->setAccessible( true );
	$office_options = $office_method->invoke(
		$locations_ref,
		array(
			array( 'id' => 202, 'name' => 'Central', 'type' => 'OFFICE', 'dropOffAllowed' => true, 'address' => array( 'siteName' => 'Sofia', 'fullAddressString' => 'Sofia 1000, 2 Office St' ) ),
			array( 'id' => 203, 'name' => 'Delivery only', 'type' => 'OFFICE', 'dropOffAllowed' => false, 'address' => array( 'siteName' => 'Sofia' ) ),
			array( 'id' => 204, 'name' => 'Unknown capability', 'type' => 'OFFICE', 'address' => array( 'siteName' => 'Sofia' ) ),
			array( 'id' => 205, 'name' => 'Locker', 'type' => 'APT', 'dropOffAllowed' => true, 'address' => array( 'siteName' => 'Sofia' ) ),
		)
	);
	sender_check(
		array( 202 ) === array_keys( $office_options ),
		'Only regular offices explicitly allowing merchant drop-off are selectable'
	);
	sender_check(
		false !== strpos( $office_options['202'], 'Sofia 1000, 2 Office St' ),
		'Sender-office labels include the full address'
	);

	if ( $failures > 0 ) {
		echo "\n{$failures} sender-origin check(s) failed\n";
		exit( 1 );
	}

	echo "\nAll Speedy sender-origin checks passed\n";
}

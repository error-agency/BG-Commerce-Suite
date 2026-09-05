<?php
/**
 * Изгражда Econt label payload-а, споделен от quote() и create_label().
 *
 * Чист строител без странични ефекти: чете настройките на куриера, selection-а
 * и (по избор) поръчката, и връща структурата, която `Client::LABEL_CREATE`
 * очаква. Изнесен от модула `Econt`, за да е изолирана и тестваема Econt API
 * схемата, без да се променят payload ключовете или семантиката им.
 *
 * @package BgCommerce3\Econt
 */

namespace BgCommerce3\Modules\Shipping\Econt;

use BgCommerce3\Shipping\Cod;
use BgCommerce3\Shipping\Overrides;
use BgCommerce3\Shipping\Package_Dimensions;
use BgCommerce3\Shipping\Shipment_Reference;
use BgCommerce3\Shipping\Weight;
use BgCommerce3\Support\Selection;

defined( 'ABSPATH' ) || exit;

class Label_Builder {

	/** @var string Courier module ID, използван за option lookup. */
	private $courier_id;

	/**
	 * @param string $courier_id ID на куриера (напр. Econt::ID).
	 */
	public function __construct( $courier_id ) {
		$this->courier_id = (string) $courier_id;
	}

	/**
	 * Брой пакети от панела „Товарителница“.
	 *
	 * Econt accepts both packCount and an optional packs[] list. The generic
	 * multi-pack editor therefore controls the count and, when every row is
	 * complete, is also serialized to the official PackElement structure.
	 *
	 * @param array<string,mixed> $wb Waybill overrides.
	 * @return int
	 */
	private function pack_count( array $wb ) {
		if ( ! empty( $wb['packages'] ) && is_array( $wb['packages'] ) ) {
			$count = count( $wb['packages'] );
			if ( $count > 0 ) {
				return $count;
			}
		}

		return ( isset( $wb['parcels'] ) && (int) $wb['parcels'] > 1 ) ? (int) $wb['parcels'] : 1;
	}

	/**
	 * Serialize complete Core package rows to Econt PackElement entries.
	 * One incomplete row suppresses the whole optional packs[] array instead of
	 * sending a partially valid list; packCount and total weight still remain.
	 *
	 * @param array<string,mixed> $wb Waybill overrides.
	 * @return array<int,array<string,float>>
	 */
	private function pack_elements( array $wb ) {
		if ( empty( $wb['packages'] ) || ! is_array( $wb['packages'] ) ) {
			return array();
		}
		$out = array();
		foreach ( $wb['packages'] as $pack ) {
			if ( ! is_array( $pack ) ) {
				return array();
			}
			$length = isset( $pack['length'] ) ? (float) $pack['length'] : 0.0;
			$width  = isset( $pack['width'] ) ? (float) $pack['width'] : 0.0;
			$height = isset( $pack['height'] ) ? (float) $pack['height'] : 0.0;
			$weight = isset( $pack['weight'] ) ? (float) $pack['weight'] : 0.0;
			if ( $length <= 0 || $width <= 0 || $height <= 0 || $weight <= 0 ) {
				return array();
			}
			$out[] = array(
				'width'  => $width,
				'height' => $height,
				'length' => $length,
				'weight' => max( Weight::MIN_KG, $weight ),
			);
		}
		return $out;
	}

	/** Resolve and validate the Econt shipment type used for ordinary goods. */
	private function shipment_type( array $wb ) {
		$type = strtolower( $this->wbx_value( $wb, 'shipment_type', 'shipment_type', 'pack' ) );
		$allowed = array( 'document', 'pack', 'pallet', 'cargo', 'documentpallet', 'big_letter', 'small_letter' );
		return in_array( $type, $allowed, true ) ? $type : 'pack';
	}

	/**
	 * Кратък хелпър за настройка на куриера.
	 *
	 * @param string $key     Ключ на настройката.
	 * @param mixed  $default Стойност по подразбиране.
	 * @return mixed
	 */
	private function option( $key, $default = '' ) {
		return bgcs3_get_option( $this->courier_id, $key, $default );
	}

	/** Per-order courier-specific override from `_bgcs3_wb[x]`. */
	private function wbx( array $wb, $key ) {
		return isset( $wb['x'] ) && is_array( $wb['x'] ) && array_key_exists( $key, $wb['x'] )
			? trim( (string) $wb['x'][ $key ] )
			: '';
	}

	/** Resolve an order yes/no override over a module yes/no default. */
	private function wbx_bool( array $wb, $key, $setting_key, $setting_default = 'no' ) {
		$value = $this->wbx( $wb, $key );
		if ( in_array( $value, array( 'yes', 'no' ), true ) ) {
			return 'yes' === $value;
		}
		return 'yes' === (string) $this->option( $setting_key, $setting_default );
	}

	/**
	 * Resolve text/select overrides. Blank inherits; literal `0` explicitly
	 * suppresses a configured template/time for this order.
	 */
	private function wbx_value( array $wb, $key, $setting_key, $setting_default = '' ) {
		$value = $this->wbx( $wb, $key );
		if ( '0' === $value ) {
			return '';
		}
		return '' !== $value ? $value : trim( (string) $this->option( $setting_key, $setting_default ) );
	}

	/**
	 * Resolve the shipment currency without ever emitting an empty code.
	 * Order currency is authoritative; legacy/imported orders fall back to the
	 * current WooCommerce store currency. Econt validates whether the resolved
	 * currency is supported for the account/service.
	 *
	 * @param \WC_Order|null $order Order or null during cart calculation.
	 * @return string|\WP_Error
	 */
	public function resolve_currency( ?\WC_Order $order = null ) {
		$candidates = array();
		if ( $order ) {
			$candidates[] = (string) $order->get_currency();
		}
		$candidates[] = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';
		foreach ( $candidates as $candidate ) {
			$currency = strtoupper( trim( $candidate ) );
			if ( preg_match( '/^[A-Z]{3}$/', $currency ) ) {
				return $currency;
			}
		}
		return new \WP_Error(
			'bgcs3_econt_currency_missing',
			__( 'Econt requires a valid three-letter currency code for cash on delivery and declared value. Check the order/store currency before creating the shipment.', 'bg-commerce-suite' )
		);
	}

	/**
	 * Изгражда Econt label payload-а.
	 *
	 * @param Selection            $selection Selection.
	 * @param float                $weight    Weight in kg.
	 * @param array<string,string> $receiver  Receiver name/phone/email.
	 * @param \WC_Order|null       $order     Order (null when quoting from the cart).
	 * @return array<string,mixed>|null|\WP_Error
	 */
	public function build( Selection $selection, $weight, array $receiver, ?\WC_Order $order = null, array $wb = array() ) {
		$phones = array();
		if ( ! empty( $receiver['phone'] ) ) {
			$phones[] = $receiver['phone'];
		}

		$sender_name = $this->wbx_value( $wb, 'sender_company', 'sender_company', '' );
		if ( '' === $sender_name ) {
			$sender_name = $this->wbx_value( $wb, 'sender_contact_name', 'sender_name', '' );
		}
		$sender_phone = $this->wbx_value( $wb, 'sender_phone', 'sender_phone', '' );

		$sender_client = array();
		if ( '' !== $sender_name ) {
			$sender_client['name'] = $sender_name;
		}
		if ( '' !== $sender_phone ) {
			$sender_client['phones'] = array( $sender_phone );
		}

		$receiver_client = array(
			'name' => ! empty( $receiver['name'] ) ? $receiver['name'] : __( 'Recipient', 'bg-commerce-suite' ),
		);
		if ( ! empty( $phones ) ) {
			$receiver_client['phones'] = $phones;
		}

		$label = array(
			'senderClient'   => $sender_client,
			'receiverClient' => $receiver_client,
			'packCount'      => $this->pack_count( $wb ),
			'shipmentType'   => $this->shipment_type( $wb ),
			'weight'         => $weight,
		);

		$pack_elements = $this->pack_elements( $wb );
		if ( ! empty( $pack_elements ) ) {
			$label['packs']     = $pack_elements;
			$label['packCount'] = count( $pack_elements );
			$label['weight']    = array_sum( array_column( $pack_elements, 'weight' ) );
		}

		if ( $order ) {
			$label['orderNumber'] = Shipment_Reference::for_order( $order );
		}

		// Sender email.
		$sender_email = $this->wbx_value( $wb, 'sender_email', 'sender_email', '' );
		if ( '' !== $sender_email ) {
			$label['senderClient']['email'] = $sender_email;
		}

		// Sender contact person / authorised agent. `contactName` is not a
		// ClientProfile property in Econt's SOAP/JSON contract; authorised people
		// belong in ShippingLabel.senderAgent.
		$sender_contact = $this->wbx_value( $wb, 'sender_contact_name', 'sender_name', '' );
		if ( '' !== $sender_contact ) {
			$label['senderAgent'] = array( 'name' => $sender_contact );
			if ( '' !== $sender_phone ) {
				$label['senderAgent']['phones'] = array( $sender_phone );
			}
			if ( '' !== $sender_email ) {
				$label['senderAgent']['email'] = $sender_email;
			}
		}

		if ( ! empty( $receiver['email'] ) ) {
			$label['receiverClient']['email'] = $receiver['email'];
		}
		$email_on_delivery = $this->wbx_bool( $wb, 'email_on_delivery', 'email_on_delivery', 'no' );
		if ( $email_on_delivery && $order ) {
			if ( empty( $receiver['email'] ) ) {
				return new \WP_Error( 'bgcs3_econt_email_on_delivery_missing', __( 'Econt email-on-delivery notification requires a recipient email address.', 'bg-commerce-suite' ) );
			}
			$label['emailOnDelivery'] = (string) $receiver['email'];
		}

		// Destination: office/locker vs address.
		if ( in_array( $selection->delivery_type, array( 'office', 'locker' ), true ) ) {
			$label['receiverOfficeCode'] = isset( $selection->office['id'] ) ? $selection->office['id'] : '';
		} else {
			$address_other = array();
			foreach ( array(
				'block'     => __( 'Block', 'bg-commerce-suite' ),
				'entrance'  => __( 'Entrance', 'bg-commerce-suite' ),
				'floor'     => __( 'Floor', 'bg-commerce-suite' ),
				'apartment' => __( 'Apartment', 'bg-commerce-suite' ),
			) as $address_key => $address_label ) {
				if ( ! empty( $selection->address[ $address_key ] ) ) {
					$address_other[] = $address_label . ': ' . (string) $selection->address[ $address_key ];
				}
			}
			if ( ! empty( $selection->address['note'] ) ) {
				$address_other[] = (string) $selection->address['note'];
			}

			$city = array(
				'name'     => isset( $selection->city['name'] ) ? $selection->city['name'] : '',
				'postCode' => isset( $selection->city['post_code'] ) ? $selection->city['post_code'] : '',
			);
			if ( ! empty( $selection->city['id'] ) ) {
				$city['id'] = (int) $selection->city['id'];
			}

			$label['receiverAddress'] = array(
				'city'   => $city,
				'street' => isset( $selection->address['street'] ) ? $selection->address['street'] : '',
				'num'    => isset( $selection->address['num'] ) ? $selection->address['num'] : '',
				'other'  => implode( ', ', $address_other ),
			);
		}

		// -- Payment configuration ----------------------------------------
		// BGCS always represents the customer-facing delivery charge as a
		// WooCommerce shipping line. If Econt is also told that the RECEIVER pays
		// the courier services, Econt collects those services once more on top of
		// the WooCommerce order/COD total. That produces a real double charge.
		// Therefore the standard BGCS Econt contract is: merchant/sender pays the
		// courier invoice, while WooCommerce decides what delivery amount the
		// customer sees and pays. A future explicit "pay courier separately" mode
		// would need a zero WooCommerce shipping line and is intentionally not
		// emulated through this field.
		$payment_type = strtoupper( $this->wbx_value( $wb, 'payment_type', 'payment_type', 'CASH' ) );
		if ( ! in_array( $payment_type, array( 'CASH', 'CREDIT', 'VOUCHER' ), true ) ) {
			return null;
		}

		$label['paymentReceiverMethod'] = '';
		$label['paymentSenderMethod']   = strtolower( $payment_type );

		// -- Services -----------------------------------------------------
		$services = array();

		// -- SMS notification ---------------------------------------------
		// Send the resolved boolean explicitly so the create/validate payload is
		// deterministic and diagnostics show the merchant's effective choice.
		$sms_notification = $this->wbx_bool( $wb, 'sms_notification', 'sms_notification', 'no' );
		if ( $sms_notification && empty( $phones ) ) {
			if ( $order ) {
				return new \WP_Error( 'bgcs3_econt_sms_phone_missing', __( 'Econt SMS notification requires a recipient phone number.', 'bg-commerce-suite' ) );
			}
			// Cart quotes can run before the customer has entered a phone number.
			// Do not invent recipient data merely to make the calculation request pass.
			$sms_notification = false;
		}
		$services['smsNotification'] = $sms_notification;
		if ( $sms_notification ) {
			$label['smsOnDelivery'] = $phones[0];
		}

		// -- Declared value -----------------------------------------------
		$dv_setting = (float) $this->option( 'declared_value', '0' );
		$cart_total = ( $order ) ? (float) $order->get_subtotal() : ( ( function_exists( 'WC' ) && WC()->cart ) ? (float) WC()->cart->get_displayed_subtotal() : 0.0 );
		$apply_dv   = false;

		if ( $dv_setting >= 1 ) {
			if ( 1.0 === $dv_setting ) {
				$apply_dv = true; // Always.
			} elseif ( $cart_total >= $dv_setting ) {
				$apply_dv = true; // Above threshold.
			}
		}

		// The admin panel overrides the setting: „Ръчна стойност“ declares that
		// amount, „Изрично без“ declares nothing at all, and an untouched panel
		// leaves the threshold logic above in charge (Rule 15 — a blank amount
		// never means „no value“ by itself).
		$dv_mode = Overrides::mode( $wb, 'dv_mode' );
		if ( Overrides::CUSTOM === $dv_mode ) {
			$custom_dv = (float) Overrides::resolve( $wb, 'dv_mode', 'declared_value', 0.0 );
			if ( $custom_dv > 0 ) {
				$cart_total = $custom_dv;
				$apply_dv   = true;
			}
		} elseif ( Overrides::DISABLED === $dv_mode ) {
			$apply_dv = false;
		}

		if ( $apply_dv && $cart_total > 0 ) {
			// Order currency е source of truth, когато поръчката вече съществува
			// (Rule 19/144) — $cart_total идва от $order->get_subtotal() в тази
			// валута, така валутата на декларираната стойност трябва да я следва,
			// а не текущата store currency (BUG-013 — иначе на multi-currency
			// магазин сумата и валутата не съвпадат). Съгласувано с cdCurrency по-долу.
			$currency = $this->resolve_currency( $order );
			if ( is_wp_error( $currency ) ) {
				return $currency;
			}
			$services['declaredValueAmount']   = $cart_total;
			$services['declaredValueCurrency'] = $currency;
		}

		// -- Cash on delivery (COD) amount & Pay Options ------------------
		$cod_mode    = Overrides::mode( $wb, 'cod_mode' );
		$cod_enabled = Overrides::CUSTOM === $cod_mode
			? true
			: ( Overrides::DISABLED === $cod_mode ? false : ( 'yes' === (string) $this->option( 'cd_enabled', 'yes' ) ) );
		if ( $cod_enabled ) {
			// Готова поръчка → нейният метод; на checkout → текущият избор.
			$is_cod = $order ? Cod::is_order( $order ) : Cod::is_chosen();

			// With an order present the amount goes through the shared resolver, so
			// „Ръчна сума“ and „Без НП“ from the panel apply exactly as they do for
			// every other courier.
			$cod_amount = $order
				? Cod::resolve_amount( $order, $wb )
				: ( ( function_exists( 'WC' ) && WC()->cart ) ? (float) WC()->cart->get_total( 'edit' ) : 0.0 );

			if ( $order ) {
				$is_cod = $cod_amount > 0;
			}

			if ( $is_cod ) {
				$currency = $this->resolve_currency( $order );
				if ( is_wp_error( $currency ) ) {
					return $currency;
				}
				$services['cdType']     = 'get';
				$services['cdAmount']   = $cod_amount;
				$services['cdCurrency'] = $currency;

				$cd_pay_options = $this->wbx_value( $wb, 'cd_pay_options', 'cd_pay_options', '' );
				if ( '' !== $cd_pay_options ) {
					$services['cdPayOptionsTemplate'] = $cd_pay_options;
				}
			}
		}

		// -- Econt packaging / handling service counts ---------------------
		$pack_service_map = array(
			'econt_pack5'             => 'pack5',
			'econt_pack6'             => 'pack6',
			'econt_pack8'             => 'pack8',
			'econt_pack9'             => 'pack9',
			'econt_pack10'            => 'pack10',
			'econt_pack12'            => 'pack12',
			'econt_refrigerated_pack' => 'refrigeratedPack',
		);
		foreach ( $pack_service_map as $setting_key => $api_key ) {
			$count = (int) $this->wbx_value( $wb, $setting_key, $setting_key, '0' );
			if ( $count > 0 ) {
				$services[ $api_key ] = $count;
			}
		}

		// -- Invoice before COD payment -----------------------------------
		$services['invoiceBeforePayCD'] = $this->wbx_bool( $wb, 'invoice_before_payment', 'invoice_before_payment', 'no' );
		$services['deliveryReceipt']    = $this->wbx_bool( $wb, 'delivery_receipt', 'delivery_receipt', 'no' );
		$services['digitalReceipt']     = $this->wbx_bool( $wb, 'digital_receipt', 'digital_receipt', 'no' );
		$services['goodsReceipt']       = $this->wbx_bool( $wb, 'goods_receipt', 'goods_receipt', 'no' );
		$services['twoWayShipment']     = $this->wbx_bool( $wb, 'two_way_shipment', 'two_way_shipment', 'no' );
		$services['deliveryToFloor']    = $this->wbx_bool( $wb, 'delivery_to_floor', 'delivery_to_floor', 'no' );

		// -- Pay after accept / test --------------------------------------
		// „Преглед и тест“ in the panel IS Econt's payAfterAccept/payAfterTest.
		$obp       = ! empty( $wb['obp'] ) ? (string) $wb['obp'] : '';
		$pay_after = ( 'OPEN' === $obp ) ? 'accept' : ( ( 'TEST' === $obp ) ? 'test' : '' );
		if ( '' === $pay_after ) {
			$pay_after = ( 'NO' === $obp ) ? 'none' : (string) $this->option( 'pay_after', 'none' );
		}
		// Explicit false values make the create/validate payload deterministic and
		// prevent an inherited module default from being mistaken for an order
		// override in diagnostics.
		$label['payAfterAccept'] = in_array( $pay_after, array( 'accept', 'test' ), true );
		$label['payAfterTest']   = 'test' === $pay_after;

		// -- Dimensions & Size Under 60cm ---------------------------------
		$explicit_dimensions = array(
			'length' => isset( $wb['depth'] ) ? $wb['depth'] : '',
			'width'  => isset( $wb['width'] ) ? $wb['width'] : '',
			'height' => isset( $wb['height'] ) ? $wb['height'] : '',
		);
		$default_dimensions  = array(
			'length' => $this->option( 'default_length', '' ),
			'width'  => $this->option( 'default_width', '' ),
			'height' => $this->option( 'default_height', '' ),
		);
		$dimensions = $order
			? Package_Dimensions::resolve_for_order( $order, $explicit_dimensions, $default_dimensions )
			: Package_Dimensions::resolve_for_package( array(), $explicit_dimensions, $default_dimensions );

		$length = ! empty( $dimensions ) ? (float) $dimensions['length'] : 0.0;
		$width  = ! empty( $dimensions ) ? (float) $dimensions['width'] : 0.0;
		$height = ! empty( $dimensions ) ? (float) $dimensions['height'] : 0.0;

		if ( $length > 0 && $width > 0 && $height > 0 ) {
			$label['shipmentDimensionsL'] = $length;
			$label['shipmentDimensionsW'] = $width;
			$label['shipmentDimensionsH'] = $height;
			$max_dim = max( $length, $width, $height );
			$label['sizeUnder60cm'] = ( $max_dim < 60 );
		}

		// -- Shipment Description -----------------------------------------
		$description = '';
		if ( $order ) {
			$items = array();
			foreach ( $order->get_items() as $item ) {
				$items[] = $item->get_name() . ' x ' . $item->get_quantity();
			}
			$description = implode( ', ', $items );
		} else {
			$description = __( 'Products from cart', 'bg-commerce-suite' );
		}
		if ( ! empty( $wb['contents'] ) ) {
			$description = (string) $wb['contents'];
		}
		// Do not silently truncate merchant data. Econt's public JSON/OpenAPI
		// contract does not declare a maxLength for shipmentDescription; mode=validate
		// remains authoritative if the provider/account enforces a limit.
		$label['shipmentDescription'] = $description;

		// -- Returned Loading (Relationship) ------------------------------
		if ( $order ) {
			$first_loading_num   = (string) $order->get_meta( '_bgcs3_first_loading_num' );
			$first_loading_phone = (string) $order->get_meta( '_bgcs3_first_loading_receiver_phone' );
			if ( '' !== $first_loading_num ) {
				$label['previousShipmentNumber'] = $first_loading_num;
				if ( '' !== $first_loading_phone ) {
					$label['previousShipmentReceiverPhone'] = $first_loading_phone;
				}
			}
		}

		// -- Instructions -------------------------------------------------
		$instructions = array();
		$instr_take   = $this->wbx_value( $wb, 'instructions_take', 'instructions_take', '' );
		if ( '' !== $instr_take ) {
			$instructions[] = array(
				'type'     => 'take',
				'id'       => (int) $instr_take,
			);
		}
		$instr_give = $this->wbx_value( $wb, 'instructions_give', 'instructions_give', '' );
		if ( '' !== $instr_give ) {
			$instructions[] = array(
				'type'     => 'give',
				'id'       => (int) $instr_give,
			);
		}
		$instr_return = $this->wbx_value( $wb, 'instructions_return', 'instructions_return', '' );
		if ( '' !== $instr_return ) {
			$instructions[] = array(
				'type'     => 'return',
				'id'       => (int) $instr_return,
			);
		}
		// Empty array is intentional on an order payload: a per-order `0` must mean
		// "no instruction" instead of silently falling back to the module default.
		$label['instructions'] = $instructions;

		// -- Envelope Number ----------------------------------------------
		if ( $order ) {
			$envelope_num = (string) $order->get_meta( '_bgcs3_envelope_num' );
			if ( '' !== $envelope_num ) {
				$label['envelopeNumbers'] = array( $envelope_num );
			}
		}

		// -- Keep upright / partial delivery ------------------------------
		$label['keepUpright'] = $this->wbx_bool( $wb, 'keep_upright', 'keep_upright', 'no' );

		$partial_delivery = $this->wbx_bool( $wb, 'partial_delivery', 'partial_delivery', 'no' );
		$label['partialDelivery'] = $partial_delivery;
		if ( $partial_delivery && $order ) {
			// Econt's “Review/Test and choice” contract requires a digital
			// packing list. Each WooCommerce line item becomes one
			// PackingListElement. Weight is intentionally omitted here because it
			// is optional and shop weight units are not guaranteed to be kg.
			$packing_list = array();
			foreach ( $order->get_items( 'line_item' ) as $item ) {
				$qty = max( 1, (int) $item->get_quantity() );
				$product = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
				$sku = $product && method_exists( $product, 'get_sku' ) ? trim( (string) $product->get_sku() ) : '';
				if ( '' === $sku ) {
					$sku = (string) ( method_exists( $item, 'get_product_id' ) ? $item->get_product_id() : '' );
				}
				$line_total = (float) $item->get_total() + (float) $item->get_total_tax();
				$packing_list[] = array(
					'inventoryNum' => $sku,
					'description'  => (string) $item->get_name(),
					'count'        => $qty,
					'price'        => round( $line_total / $qty, 2 ),
				);
			}
			$label['packingListType'] = 'digital';
			$label['packingList']     = $packing_list;
		}

		// -- Priority delivery window ---------------------------------------
		$priority_from = $this->wbx_value( $wb, 'priority_time_from', 'priority_time_from', '' );
		$priority_to   = $this->wbx_value( $wb, 'priority_time_to', 'priority_time_to', '' );
		if ( preg_match( '/^\d{1,2}:\d{2}$/', $priority_from ) ) {
			$services['priorityTimeFrom'] = $priority_from;
		}
		if ( preg_match( '/^\d{1,2}:\d{2}$/', $priority_to ) ) {
			$services['priorityTimeTo'] = $priority_to;
		}

		if ( ! empty( $services ) ) {
			$label['services'] = $services;
		}

		return $label;
	}

	/**
	 * Общо тегло на поръчката в кг (fallback към настройката по подразбиране).
	 *
	 * @param \WC_Order $order Order.
	 * @return float
	 */
	public function order_weight( \WC_Order $order ) {
		return Weight::for_order( Econt::ID, $order );
	}

	/**
	 * Общо тегло на пакета в кг (fallback към настройката по подразбиране).
	 *
	 * @param array<string,mixed> $package WC shipping package.
	 * @return float
	 */
	public function package_weight( array $package ) {
		return Weight::for_package( Econt::ID, $package );
	}
}

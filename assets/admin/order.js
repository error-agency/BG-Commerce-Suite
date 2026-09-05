/**
 * Admin order metabox actions (plain jQuery, no build step required).
 * Creates/cancels a waybill and refreshes tracking via admin-ajax.
 */
( function ( $ ) {
	'use strict';

	$( document ).on( 'click', '[data-bgcs-order-panel-toggle]', function () {
		const $trigger = $( this );
		const $panel = $trigger.closest( '.bgcs-order-panel' );
		const contentId = $trigger.attr( 'aria-controls' );
		const $body = $( document.getElementById( contentId ) );
		const expanded = 'true' === $trigger.attr( 'aria-expanded' );

		$trigger.attr( 'aria-expanded', String( ! expanded ) );
		$body.prop( 'hidden', expanded );
		$panel.toggleClass( 'is-open', ! expanded );
	} );

	// One compact shipment workspace: switch tabs client-side, no page reload.
	$( document ).on( 'click', '[data-bgcs-order-tab]', function () {
		const $tab = $( this );
		const $tabs = $tab.closest( '[data-bgcs-order-tabs]' );
		const target = String( $tab.attr( 'data-bgcs-order-tab' ) || '' );
		if ( ! target ) {
			return;
		}
		$tabs.find( '[data-bgcs-order-tab]' ).removeClass( 'is-active' ).attr( 'aria-selected', 'false' );
		$tab.addClass( 'is-active' ).attr( 'aria-selected', 'true' );
		$tabs.find( '[data-bgcs-order-tabpanel]' ).each( function () {
			const $panel = $( this );
			const active = target === String( $panel.attr( 'data-bgcs-order-tabpanel' ) || '' );
			$panel.toggleClass( 'is-active', active ).prop( 'hidden', ! active );
		} );
	} );

	// `extra` carries action-specific payload (e.g. the edited waybill fields on
	// create) — without it the request is just the bare envelope and the server
	// sees none of what the merchant typed.
	function request( action, $box, $msg, extra ) {
		$msg.text( window.bgcsOrder.i18n.working );

		return $.post( window.bgcsOrder.ajaxUrl, Object.assign( {
			action: action,
			nonce: window.bgcsOrder.nonce,
			order_id: $box.data( 'order-id' ),
		}, extra || {} ) )
			.done( function ( res ) {
				if ( res && res.success ) {
					window.location.reload();
				} else {
					$msg.text( ( res && res.data && res.data.message ) || window.bgcsOrder.i18n.error );
				}
			} )
			.fail( function () {
				$msg.text( window.bgcsOrder.i18n.error );
			} );
	}

	function selectedText( $select ) {
		const option = $select.find( 'option:selected' );
		return option.length ? option.text() : '';
	}

	// Multi-pack editor (Add/Remove Pack) — only present when the resolved
	// courier module declares multi-pack support; a no-op otherwise since
	// `.bgcs-wb-packages` simply won't exist on the page.
	function renumberPacks( $packages ) {
		$packages.find( '.bgcs-wb-pack-row' ).each( function ( index ) {
			$( this ).find( '.bgcs-wb-pack-row__label' ).text( bgcsOrder.i18n.packLabel.replace( '%d', index + 1 ) );
		} );
	}

	// Columns are declared by the courier module (`pack_columns()`), so the row
	// is serialized by whatever `data-pack-key` each control carries rather than
	// by a hardcoded length/width/height/weight shape: a locker network sends a
	// compartment size where a road courier sends centimetres.
	function serializePackages( $box ) {
		const packs = [];
		$box.find( '.bgcs-wb-packages > .bgcs-wb-pack-row' ).each( function () {
			const pack = {};
			$( this ).find( '[data-pack-key]' ).each( function () {
				pack[ $( this ).data( 'pack-key' ) ] = $( this ).val();
			} );
			packs.push( pack );
		} );
		return packs.length ? JSON.stringify( packs ) : '';
	}

	$( document ).on( 'click', '.bgcs-wb-add-pack', function () {
		const $field = $( this ).closest( '.bgcs-wb-packages-field' );
		const $packages = $field.find( '.bgcs-wb-packages' );
		const $clone = $( $field.find( 'template.bgcs-wb-pack-template' ).prop( 'content' ) ).clone();
		$packages.append( $clone );
		renumberPacks( $packages );
	} );

	$( document ).on( 'click', '.bgcs-wb-remove-pack', function () {
		const $packages = $( this ).closest( '.bgcs-wb-packages' );
		if ( $packages.find( '.bgcs-wb-pack-row' ).length <= 1 ) {
			return; // always keep at least one row
		}
		$( this ).closest( '.bgcs-wb-pack-row' ).remove();
		renumberPacks( $packages );
	} );

	function initLocationSearch( $box ) {
		if ( ! $.fn.selectWoo || ! window.bgcsOrder.restUrl ) {
			return;
		}
		$box.find( '.bgcs-order-city-search, .bgcs-order-office-search, .bgcs-order-street-search' ).each( function () {
			const $select = $( this );
			const dependencyClass = $select.data( 'depends-on' );
			const dependency = dependencyClass ? $box.find( '.' + dependencyClass ) : $();

			$select.selectWoo( {
				width: '100%',
				allowClear: true,
				minimumInputLength: 'offices' === $select.data( 'resource' ) ? 0 : 2,
				ajax: {
					url: bgcsOrder.restUrl + encodeURIComponent( $select.data( 'courier' ) ) + '/' + encodeURIComponent( $select.data( 'resource' ) ),
					dataType: 'json',
					delay: 300,
					headers: { 'X-WP-Nonce': bgcsOrder.restNonce },
					data: function ( params ) {
						return {
							query: params.term || '',
							city_id: dependency.length ? dependency.val() : '',
							type: $box.find( '.bgcs-edit-type' ).val()
						};
					},
					processResults: function ( response ) {
						return response && response.results ? response : { results: [] };
					},
					error: function () {
						$box.find( '.bgcs-order-msg' ).text( bgcsOrder.i18n.loadError );
					}
				}
			} );

			if ( dependency.length ) {
				dependency.on( 'change', function () {
					$select.val( null ).trigger( 'change' );
				} );
			}
		} );
	}

	// Amount field only matters in „Ръчна сума/стойност“ (custom) mode — disabling
	// it otherwise is a UX hint only, jQuery .val() still reads it for the POST
	// below, the actual tri-state resolution happens server-side.
	function bindOverrideMode( $box, modeClass, amountClass ) {
		const $mode = $box.find( modeClass );
		const $amount = $box.find( amountClass );
		if ( ! $mode.length || ! $amount.length ) {
			return;
		}
		$mode.on( 'change', function () {
			$amount.prop( 'disabled', 'custom' !== $( this ).val() );
		} ).trigger( 'change' );
	}

	// Courier fields that only matter once another control says so (e.g. Speedy's
	// ОПП return service/payer, relevant only when „Преглед и тест“ is on). The
	// dependency is declared server-side; this just keeps the DOM honest.
	function bindConditionalFields( $box ) {
		$box.find( '[data-bgcs-show-if]' ).each( function () {
			const $field = $( this );
			const $control = $box.find( '.' + $field.attr( 'data-bgcs-show-if' ) );
			if ( ! $control.length ) {
				return;
			}

			const allowed = ( $field.attr( 'data-bgcs-show-if-value' ) || '' ).split( ',' ).filter( Boolean );

			const sync = function () {
				const value = String( $control.val() || '' );
				$field.prop( 'hidden', allowed.length > 0 && -1 === allowed.indexOf( value ) );
			};

			$control.on( 'change', sync );
			sync();
		} );
	}

	$( '.bgcs-order-box' ).each( function () {
		const $box = $( this );
		initLocationSearch( $box );
		bindConditionalFields( $box );
		$box.find( '.bgcs-edit-type' ).on( 'change', function () {
			$box.find( '.bgcs-order-office-search, .bgcs-order-street-search' ).val( null ).trigger( 'change' );
		} );
		bindOverrideMode( $box, '.bgcs-wb-cod-mode', '.bgcs-wb-cod' );
		bindOverrideMode( $box, '.bgcs-wb-dv-mode', '.bgcs-wb-dv' );
	} );

	// BUG-037 — „Създай товарителница“ must carry the fields the merchant just
	// edited. Previously it sent only {action, nonce, order_id}, so чупливо,
	// обявена стойност, пакети, опаковка и т.н. were silently dropped and the
	// shipment was built from store defaults alone.
	// BUG-043 — the „Доставка“ panel must ride along too, or a street picked from
	// the dropdown is discarded and the courier receives the previous address.
	$( document ).on( 'click', '.bgcs-create-label', function () {
		const $box = $( this ).closest( '.bgcs-order-box' );
		request(
			'bgcs3_create_label',
			$box,
			$box.find( '.bgcs-order-msg' ),
			Object.assign( {}, deliveryFields( $box ), waybillFields( $box ) )
		);
	} );

	$( document ).on( 'click', '.bgcs-delete-label', function () {
		if ( ! window.confirm( window.bgcsOrder.i18n.confirmDelete ) ) {
			return;
		}
		const $box = $( this ).closest( '.bgcs-order-box' );
		request( 'bgcs3_delete_label', $box, $box.find( '.bgcs-order-msg' ) );
	} );

	$( document ).on( 'click', '.bgcs-refresh-tracking', function () {
		const $box = $( this ).closest( '.bgcs-order-box' );
		request( 'bgcs3_refresh_tracking', $box, $box.find( '.bgcs-order-msg' ) );
	} );

	$( document ).on( 'click', '.bgcs-resend-shipment-email', function () {
		const $box = $( this ).closest( '.bgcs-order-box' );
		request( 'bgcs3_resend_shipment_email', $box, $box.find( '.bgcs-order-msg' ) );
	} );

	// Courier-declared extra waybill fields rendered by the unified shipment editor.
	// Each carries its own key in data-bgcs-wb-key and round-trips as wb_x_{key}.
	function extraWaybillFields( $box ) {
		const out = {};
		$box.find( '[data-bgcs-wb-key]' ).each( function () {
			const key = $( this ).attr( 'data-bgcs-wb-key' );
			if ( key ) {
				out[ 'wb_x_' + key ] = $( this ).val();
			}
		} );
		return out;
	}


	// Waybill/package fields shared by Create and Save order settings.
	// Saving after creation changes only order-level overrides; it never calls a courier update/cancel API.
	function waybillFields( $box ) {
		return Object.assign( {
			wb_contact: $box.find( '.bgcs-wb-contact' ).val(),
			wb_phone: $box.find( '.bgcs-wb-phone' ).val(),
			wb_email: $box.find( '.bgcs-wb-email' ).val(),
			wb_parcels: $box.find( '.bgcs-wb-parcels' ).val(),
			wb_weight: $box.find( '.bgcs-wb-weight' ).val(),
			wb_width: $box.find( '.bgcs-wb-width' ).val(),
			wb_depth: $box.find( '.bgcs-wb-depth' ).val(),
			wb_height: $box.find( '.bgcs-wb-height' ).val(),
			wb_packages: serializePackages( $box ),
			wb_package_type: $box.find( '.bgcs-wb-package-type' ).val(),
			wb_cod_mode: $box.find( '.bgcs-wb-cod-mode' ).val(),
			wb_cod: $box.find( '.bgcs-wb-cod' ).val(),
			wb_dv_mode: $box.find( '.bgcs-wb-dv-mode' ).val(),
			wb_dv: $box.find( '.bgcs-wb-dv' ).val(),
			wb_fragile: $box.find( '.bgcs-wb-fragile' ).val(),
			wb_contents: $box.find( '.bgcs-wb-contents' ).val(),
			wb_ref2: $box.find( '.bgcs-wb-ref2' ).val(),
			wb_payer: $box.find( '.bgcs-wb-payer' ).val(),
			wb_obp: $box.find( '.bgcs-wb-obp' ).val(),
		}, extraWaybillFields( $box ) );
	}

	// „Доставка“ panel (тип/град/пощенски код/офис/улица/номер). Returns an empty
	// object when the panel is not on screen. Both pre-create and post-create settings views
	// render it; the guard prevents accidental wipes if another screen reuses the actions.
	function deliveryFields( $box ) {
		const $type = $box.find( '.bgcs-edit-type' );
		if ( ! $type.length ) {
			return {};
		}

		return {
			delivery_type: $type.val(),
			city_id: $box.find( '.bgcs-order-city-search' ).val(),
			city_label: selectedText( $box.find( '.bgcs-order-city-search' ) ),
			post_code: $box.find( '.bgcs-edit-pc' ).val(),
			office_id: $box.find( '.bgcs-order-office-search' ).val(),
			office_label: selectedText( $box.find( '.bgcs-order-office-search' ) ),
			street_id: $box.find( '.bgcs-order-street-search' ).val(),
			street_label: selectedText( $box.find( '.bgcs-order-street-search' ) ),
			num: $box.find( '.bgcs-edit-num' ).val(),
			block: $box.find( '.bgcs-edit-block' ).val(),
			entrance: $box.find( '.bgcs-edit-entrance' ).val(),
			floor: $box.find( '.bgcs-edit-floor' ).val(),
			apartment: $box.find( '.bgcs-edit-apartment' ).val(),
			note: $box.find( '.bgcs-edit-note' ).val(),
		};
	}

	// Save admin-edited delivery data before generating the waybill.
	$( document ).on( 'click', '.bgcs-save-selection', function () {
		const $box = $( this ).closest( '.bgcs-order-box' );
		const $msg = $box.find( '.bgcs-order-msg' );
		$msg.text( window.bgcsOrder.i18n.working );

		$.post( window.bgcsOrder.ajaxUrl, Object.assign( {
			action: 'bgcs3_save_selection',
			nonce: window.bgcsOrder.nonce,
			order_id: $box.data( 'order-id' ),
		}, deliveryFields( $box ), waybillFields( $box ) ) )
			.done( function ( res ) {
				if ( res && res.success ) {
					window.location.reload();
				} else {
					$msg.text( ( res && res.data && res.data.message ) || window.bgcsOrder.i18n.error );
				}
			} )
			.fail( function () {
				$msg.text( window.bgcsOrder.i18n.error );
			} );
	} );

} )( jQuery );

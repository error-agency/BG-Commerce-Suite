( function () {
	'use strict';

	function initWeightPricing() {
		var editor = document.querySelector( '.bgcs-boxnow-weight-pricing' );
		if ( ! editor ) {
			return;
		}

		var rows = editor.querySelector( '[data-boxnow-weight-rows]' );
		var template = editor.querySelector( '[data-boxnow-weight-row-template]' );
		var addButton = editor.querySelector( '[data-boxnow-add-weight-row]' );
		var form = editor.closest( 'form' );
		var strings = window.bgcsBoxNowAdmin || {};

		function rowInputs( row ) {
			return {
				min: row.querySelector( 'input[name$="[min]"]' ),
				max: row.querySelector( 'input[name$="[max]"]' ),
				price: row.querySelector( 'input[name$="[price]"]' )
			};
		}

		function reindexRows() {
			rows.querySelectorAll( '[data-boxnow-weight-row]' ).forEach( function ( row, index ) {
				row.querySelectorAll( 'input' ).forEach( function ( input ) {
					input.name = input.name.replace( /boxnow_weight_ranges\[[^\]]+\]/, 'boxnow_weight_ranges[' + index + ']' );
					input.id = input.id.replace( /-[^-]+$/, '-' + index );
				} );
				row.querySelectorAll( 'label[for]' ).forEach( function ( label ) {
					label.htmlFor = label.htmlFor.replace( /-[^-]+$/, '-' + index );
				} );
			} );
		}

		function addRow() {
			var index = rows.querySelectorAll( '[data-boxnow-weight-row]' ).length;
			var previousRow = rows.lastElementChild;
			var previousMax = previousRow ? rowInputs( previousRow ).max.value : '';
			rows.insertAdjacentHTML( 'beforeend', template.innerHTML.replace( /__index__/g, String( index ) ) );
			reindexRows();
			var newRow = rows.lastElementChild;
			if ( newRow ) {
				var newInputs = rowInputs( newRow );
				if ( '' !== previousMax.trim() ) {
					newInputs.min.value = previousMax;
				}
				newInputs.min.focus();
			}
		}

		function clearValidity() {
			rows.querySelectorAll( 'input' ).forEach( function ( input ) {
				input.setCustomValidity( '' );
			} );
		}

		function numberValue( input ) {
			if ( ! input || '' === input.value.trim() ) {
				return null;
			}
			var parsed = Number( input.value.replace( ',', '.' ) );
			return Number.isFinite( parsed ) ? parsed : null;
		}

		function validateRows() {
			clearValidity();
			if ( editor.hidden || editor.style.display === 'none' ) {
				return true;
			}
			var configured = [];

			rows.querySelectorAll( '[data-boxnow-weight-row]' ).forEach( function ( row ) {
				var inputs = rowInputs( row );
				var min = numberValue( inputs.min );
				var max = numberValue( inputs.max );
				var price = numberValue( inputs.price );
				var isBlank = null === min && null === max && null === price;

				if ( isBlank ) {
					return;
				}

				if ( null === min || null === price ) {
					( null === min ? inputs.min : inputs.price ).setCustomValidity( strings.incomplete || 'Incomplete range.' );
					return;
				}

				if ( null !== max && max <= min ) {
					inputs.max.setCustomValidity( strings.invalidRange || 'Invalid range.' );
					return;
				}

				configured.push( { min: min, max: max, inputs: inputs } );
			} );

			configured.sort( function ( left, right ) {
				return left.min - right.min;
			} );

			for ( var index = 0; index < configured.length - 1; index += 1 ) {
				var current = configured[ index ];
				var next = configured[ index + 1 ];

				if ( null === current.max ) {
					current.inputs.max.setCustomValidity( strings.openEnded || 'Open-ended range must be last.' );
					break;
				}

				if ( next.min < current.max ) {
					next.inputs.min.setCustomValidity( strings.overlap || 'Ranges must not overlap.' );
					break;
				}
			}

			var invalid = rows.querySelector( 'input:invalid' );
			if ( invalid ) {
				invalid.reportValidity();
				return false;
			}

			return true;
		}

		addButton.addEventListener( 'click', addRow );

		rows.addEventListener( 'click', function ( event ) {
			var removeButton = event.target.closest( '[data-boxnow-remove-weight-row]' );
			if ( ! removeButton ) {
				return;
			}

			var row = removeButton.closest( '[data-boxnow-weight-row]' );
			if ( row ) {
				row.remove();
			}

			if ( ! rows.querySelector( '[data-boxnow-weight-row]' ) ) {
				addRow();
			} else {
				reindexRows();
			}
		} );

		rows.addEventListener( 'input', clearValidity );

		if ( form ) {
			form.addEventListener( 'submit', function ( event ) {
				var submitter = event.submitter || null;
				var scope = submitter && submitter.name === 'bgcs_task_scope' ? String( submitter.value || '' ) : '';
				var action = submitter && submitter.name === 'bgcs_task_action' ? String( submitter.value || '' ) : '';

				// Hidden shipping-price controls must never block saving/checking the
				// Account, Tracking or Diagnostics tabs. Validate this repeater only
				// when the Shipping methods task is the action being submitted.
				if ( action === 'check_connection' || action === 'refresh_sender' || ( scope && scope !== 'methods' ) ) {
					clearValidity();
					return;
				}

				if ( ! validateRows() ) {
					event.preventDefault();
				}
			} );
		}

		reindexRows();
	}

	/**
	 * Add/remove rows in the per-warehouse pickup-contact table.
	 *
	 * The row indexes must stay contiguous: they are the array keys the whole
	 * table is submitted under, and a gap would silently drop a warehouse.
	 */
	function initWarehouseContacts() {
		var editor = document.querySelector( '.bgcs-boxnow-warehouses' );
		if ( ! editor ) {
			return;
		}

		var rows = editor.querySelector( '[data-boxnow-warehouse-rows]' );
		var template = editor.querySelector( '[data-boxnow-warehouse-row-template]' );
		var addButton = editor.querySelector( '[data-boxnow-add-warehouse-row]' );
		if ( ! rows || ! template || ! addButton ) {
			return;
		}

		function reindex() {
			rows.querySelectorAll( '[data-boxnow-warehouse-row]' ).forEach( function ( row, index ) {
				row.querySelectorAll( 'input, select' ).forEach( function ( field ) {
					field.name = field.name.replace( /boxnow_warehouses\[[^\]]*\]/, 'boxnow_warehouses[' + index + ']' );
					if ( field.id ) {
						field.id = field.id.replace( /-[^-]+$/, '-' + index );
					}
				} );
				row.querySelectorAll( 'label[for]' ).forEach( function ( label ) {
					label.htmlFor = label.htmlFor.replace( /-[^-]+$/, '-' + index );
				} );
			} );
		}

		addButton.addEventListener( 'click', function () {
			var index = rows.querySelectorAll( '[data-boxnow-warehouse-row]' ).length;
			rows.insertAdjacentHTML( 'beforeend', template.innerHTML.replace( /__index__/g, String( index ) ) );
			reindex();
			var added = rows.lastElementChild;
			if ( added ) {
				var first = added.querySelector( 'input, select' );
				if ( first ) {
					first.focus();
				}
			}
		} );

		rows.addEventListener( 'click', function ( event ) {
			var button = event.target.closest ? event.target.closest( '[data-boxnow-remove-warehouse-row]' ) : null;
			if ( ! button ) {
				return;
			}
			var row = button.closest( '[data-boxnow-warehouse-row]' );
			if ( row ) {
				row.remove();
				reindex();
			}
		} );

		reindex();
	}

	function init() {
		initWeightPricing();
		initWarehouseContacts();
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );

/**
 * BG Commerce Suite — settings page helpers.
 * Keeps the native colour swatch and its hex text input in sync.
 */
( function () {
	'use strict';

	function t( key, fallback ) {
		return window.bgcsSettings && bgcsSettings.i18n && bgcsSettings.i18n[ key ] ? bgcsSettings.i18n[ key ] : fallback;
	}

	function isHex( v ) {
		return /^#[0-9a-fA-F]{6}$/.test( v );
	}

	function moduleToggleControls( moduleId ) {
		return Array.prototype.slice.call( document.querySelectorAll( '[data-bgcs-module-toggle]' ) ).filter( function ( control ) {
			return String( control.getAttribute( 'data-bgcs-module-id' ) || '' ) === moduleId;
		} );
	}

	function updateModuleStatusBadge( moduleId, enabled ) {
		var card = Array.prototype.slice.call( document.querySelectorAll( '[data-bgcs-module-card]' ) ).find( function ( candidate ) {
			return String( candidate.getAttribute( 'data-bgcs-module-card' ) || '' ) === moduleId;
		} );
		if ( ! card ) {
			return;
		}

		var badge = card.querySelector( '[data-bgcs-module-status]' );
		if ( ! badge ) {
			return;
		}

		var onClass = badge.getAttribute( 'data-bgcs-on-class' ) || 'bgcs-badge--active';
		var offClass = badge.getAttribute( 'data-bgcs-off-class' ) || 'bgcs-badge--soon';
		badge.classList.remove( onClass, offClass );
		badge.classList.add( enabled ? onClass : offClass );
		badge.textContent = enabled
			? ( badge.getAttribute( 'data-bgcs-on-label' ) || t( 'enabled', 'Enabled' ) )
			: ( badge.getAttribute( 'data-bgcs-off-label' ) || t( 'disabled', 'Disabled' ) );
	}

	function setModuleToggleUi( moduleId, enabled, busy ) {
		moduleToggleControls( moduleId ).forEach( function ( control ) {
			control.setAttribute( 'data-bgcs-enabled', enabled ? 'yes' : 'no' );

			if ( control.matches( 'input[type="checkbox"]' ) ) {
				control.checked = enabled;
				control.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
				var wrapper = control.closest( '.bgcs-module-toggle' );
				if ( wrapper ) {
					wrapper.classList.toggle( 'is-saving', busy );
					var inputLabel = wrapper.querySelector( '.bgcs-module-toggle__label' );
					if ( inputLabel ) {
						inputLabel.textContent = busy ? t( 'saving', 'Saving…' ) : ( enabled ? t( 'enabled', 'Enabled' ) : t( 'disabled', 'Disabled' ) );
					}
				}
				return;
			}

			control.classList.toggle( 'is-on', enabled );
			control.classList.toggle( 'is-saving', busy );
			control.setAttribute( 'aria-checked', enabled ? 'true' : 'false' );
			control.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
			var switchLabel = control.querySelector( '.bgcs-switch__text' );
			if ( switchLabel ) {
				switchLabel.textContent = busy ? t( 'saving', 'Saving…' ) : ( enabled ? t( 'enabled', 'Enabled' ) : t( 'disabled', 'Disabled' ) );
			}
		} );

		if ( ! busy ) {
			updateModuleStatusBadge( moduleId, enabled );
		}
	}

	function moduleToggleErrorMessage( response ) {
		if ( response && response.data && response.data.message ) {
			return String( response.data.message );
		}
		return t( 'toggleError', 'The module state could not be saved. Please try again.' );
	}

	function saveModuleToggle( moduleId, enabled, previousEnabled ) {
		if ( ! window.bgcsSettings || ! bgcsSettings.ajaxUrl || ! bgcsSettings.toggleAction || ! bgcsSettings.toggleNonce || ! window.fetch ) {
			// Checkbox-based module pages keep their original form-save fallback when
			// Fetch is unavailable. Link switches are handled by their signed href.
			setModuleToggleUi( moduleId, enabled, false );
			return;
		}

		setModuleToggleUi( moduleId, enabled, true );

		var body = new URLSearchParams();
		body.append( 'action', bgcsSettings.toggleAction );
		body.append( 'nonce', bgcsSettings.toggleNonce );
		body.append( 'module', moduleId );
		body.append( 'enabled', enabled ? 'yes' : 'no' );

		window.fetch( bgcsSettings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString()
		} ).then( function ( httpResponse ) {
			return httpResponse.json().catch( function () { return null; } );
		} ).then( function ( response ) {
			if ( ! response || ! response.success ) {
				throw new Error( moduleToggleErrorMessage( response ) );
			}

			var effective = !! ( response.data && response.data.enabled );
			setModuleToggleUi( moduleId, effective, false );
			document.dispatchEvent( new CustomEvent( 'bgcs:module-state-changed', {
				detail: { module: moduleId, enabled: effective }
			} ) );
		} ).catch( function ( error ) {
			setModuleToggleUi( moduleId, previousEnabled, false );
			window.alert( error && error.message ? error.message : t( 'toggleError', 'The module state could not be saved. Please try again.' ) );
		} );
	}

	function updateSelectedLabel( select ) {
		var targetId = select.getAttribute( 'data-label-input' );
		var target = targetId ? document.getElementById( targetId ) : null;
		var option = select.options && select.selectedIndex >= 0 ? select.options[ select.selectedIndex ] : null;
		if ( target ) {
			target.value = option ? option.text : '';
		}
	}

	function initSearchableSelects( scope ) {
		if ( ! window.jQuery || ! jQuery.fn.selectWoo ) {
			return;
		}
		jQuery( scope ).find( 'select.bgcs-searchable-select' ).each( function () {
			var $select = jQuery( this );
			if ( $select.hasClass( 'select2-hidden-accessible' ) ) {
				return;
			}
			$select.selectWoo( {
				width: '100%',
				allowClear: ! $select.prop( 'required' ),
				placeholder: $select.data( 'placeholder' ) || ''
			} );
			$select.on( 'change', function () {
				updateSelectedLabel( this );
			} );
		} );
	}

	function dependencyValue( select ) {
		var key = select.getAttribute( 'data-depends-on' );
		var dependency = key ? document.querySelector( '[name$="[' + key + ']"]' ) : null;
		return dependency ? String( dependency.value || '' ) : '';
	}

	function initRemoteSelects( scope ) {
		if ( ! window.jQuery || ! jQuery.fn.selectWoo || ! window.bgcsSettings ) {
			return;
		}
		jQuery( scope ).find( 'select.bgcs-remote-select' ).each( function () {
			var select = this;
			var $select = jQuery( select );
			var dependencyKey = select.getAttribute( 'data-depends-on' );
			var dependency = dependencyKey ? document.querySelector( '[name$="[' + dependencyKey + ']"]' ) : null;

			if ( $select.hasClass( 'select2-hidden-accessible' ) ) {
				return;
			}

			function updateDisabled() {
				$select.prop( 'disabled', !! dependencyKey && ! dependencyValue( select ) );
			}

			$select.selectWoo( {
				width: '100%',
				allowClear: ! $select.prop( 'required' ),
				minimumInputLength: Number( select.getAttribute( 'data-minimum-input-length' ) || 2 ),
				ajax: {
					url: bgcsSettings.restUrl + encodeURIComponent( select.getAttribute( 'data-module' ) ) + '/' + encodeURIComponent( select.getAttribute( 'data-resource' ) ),
					dataType: 'json',
					delay: 300,
					headers: { 'X-WP-Nonce': bgcsSettings.restNonce },
					data: function ( params ) {
						return {
							query: params.term || '',
							city_id: dependencyValue( select ),
							type: select.getAttribute( 'data-location-type' ) || ''
						};
					},
					processResults: function ( response ) {
						return response && response.results ? response : { results: [] };
					},
					error: function () {
						if ( window.console ) {
							console.warn( bgcsSettings.i18n.error );
						}
					}
				},
				language: {
					searching: function () { return bgcsSettings.i18n.searching; },
					noResults: function () { return bgcsSettings.i18n.noResults; }
				}
			} );

			$select.on( 'change', function () {
				updateSelectedLabel( select );
			} );

			if ( dependency ) {
				dependency.addEventListener( 'change', function () {
					$select.val( null ).trigger( 'change' );
					updateDisabled();
				} );
			}
			updateDisabled();
		} );
	}

	function initPricingRules( scope ) {
		( scope || document ).querySelectorAll( '.bgcs-pricing-rules' ).forEach( function ( repeater ) {
			if ( repeater.dataset.bgcsPricingReady === 'yes' ) {
				return;
			}
			repeater.dataset.bgcsPricingReady = 'yes';

			var rows = repeater.querySelector( '.bgcs-pricing-rules__rows' );
			var template = repeater.querySelector( '.bgcs-pricing-rule-template' );
			var add = repeater.querySelector( '.bgcs-pricing-rule-add' );
			if ( ! rows || ! template || ! add ) {
				return;
			}

			function newIndex() {
				return String( Date.now() ) + String( Math.floor( Math.random() * 1000 ) );
			}

			add.addEventListener( 'click', function () {
				var index = newIndex();
				var html = template.innerHTML.replace( /__INDEX__/g, index );
				var holder = document.createElement( 'div' );
				holder.innerHTML = html.trim();
				var row = holder.firstElementChild;
				if ( row ) {
					var id = row.querySelector( '[data-rule-field="id"]' );
					if ( id ) {
						id.value = 'rule-' + index;
					}
					rows.appendChild( row );
					var first = row.querySelector( 'select, input' );
					if ( first ) {
						first.focus();
					}
				}
			} );

			repeater.addEventListener( 'click', function ( event ) {
				var remove = event.target && event.target.closest ? event.target.closest( '.bgcs-pricing-rule-remove' ) : null;
				if ( remove && repeater.contains( remove ) ) {
					var row = remove.closest( '.bgcs-pricing-rule' );
					if ( row ) {
						row.remove();
					}
				}
			} );

			repeater.addEventListener( 'input', function ( event ) {
				if ( event.target && event.target.getAttribute( 'data-rule-field' ) === 'currency' ) {
					event.target.value = String( event.target.value || '' ).toUpperCase().replace( /[^A-Z]/g, '' ).slice( 0, 3 );
				}
			} );
		} );
	}


	function initTaskTabs( scope ) {
		( scope || document ).querySelectorAll( '[data-bgcs-task-tabs]' ).forEach( function ( workspace ) {
			if ( workspace.dataset.bgcsTaskTabsReady === 'yes' ) {
				return;
			}
			workspace.dataset.bgcsTaskTabsReady = 'yes';

			var workspaceId = String( workspace.getAttribute( 'data-bgcs-task-tabs' ) || '' ).replace( /[^a-z0-9_-]/gi, '' );
			var tabs = Array.prototype.slice.call( workspace.querySelectorAll( '[data-bgcs-task-tab]' ) );
			var panels = Array.prototype.slice.call( workspace.querySelectorAll( '[data-bgcs-task-panel]' ) );
			if ( ! workspaceId || ! tabs.length || ! panels.length ) {
				return;
			}

			function activate( id, focus, writeHash ) {
				var switchStarted = window.performance && performance.now ? performance.now() : 0;
				var found = false;
				tabs.forEach( function ( tab ) {
					var on = tab.getAttribute( 'data-bgcs-task-tab' ) === id;
					found = found || on;
					tab.classList.toggle( 'is-active', on );
					tab.setAttribute( 'aria-selected', on ? 'true' : 'false' );
					tab.tabIndex = on ? 0 : -1;
					if ( on && focus ) {
						tab.focus();
					}
				} );
				if ( ! found ) {
					return;
				}
				var activeScope = document.getElementById( 'bgcs-active-task-scope' );
				if ( activeScope ) {
					activeScope.value = id;
				}
				panels.forEach( function ( panel ) {
					var on = panel.getAttribute( 'data-bgcs-task-panel' ) === id;
					panel.hidden = ! on;
					panel.classList.toggle( 'is-active', on );
				} );
				if ( writeHash && window.history && window.history.replaceState ) {
					window.history.replaceState( null, '', '#bgcs-' + workspaceId + '-' + id );
				}
				applyShowIf();
				initSearchableSelects( workspace );
				initRemoteSelects( workspace );
				initPricingRules( workspace );
				var perfTab = document.getElementById( 'bgcs-perf-tab-switch' );
				if ( perfTab && switchStarted && window.performance && performance.now ) {
					perfTab.textContent = ( performance.now() - switchStarted ).toFixed( 1 ) + ' ms';
				}
			}

			tabs.forEach( function ( tab, index ) {
				tab.addEventListener( 'click', function () {
					activate( tab.getAttribute( 'data-bgcs-task-tab' ), false, true );
				} );
				tab.addEventListener( 'keydown', function ( event ) {
					if ( event.key !== 'ArrowLeft' && event.key !== 'ArrowRight' && event.key !== 'Home' && event.key !== 'End' ) {
						return;
					}
					event.preventDefault();
					var next = index;
					if ( event.key === 'ArrowLeft' ) { next = ( index - 1 + tabs.length ) % tabs.length; }
					if ( event.key === 'ArrowRight' ) { next = ( index + 1 ) % tabs.length; }
					if ( event.key === 'Home' ) { next = 0; }
					if ( event.key === 'End' ) { next = tabs.length - 1; }
					activate( tabs[ next ].getAttribute( 'data-bgcs-task-tab' ), true, true );
				} );
			} );

			var hash = window.location.hash || '';
			var prefix = '#bgcs-' + workspaceId + '-';
			var hashTab = hash.indexOf( prefix ) === 0 ? hash.slice( prefix.length ) : '';
			var validHash = tabs.some( function ( tab ) { return tab.getAttribute( 'data-bgcs-task-tab' ) === hashTab; } );
			activate( validHash ? hashTab : tabs[0].getAttribute( 'data-bgcs-task-tab' ), false, false );
		} );

		document.addEventListener( 'click', function ( event ) {
			var link = event.target && event.target.closest ? event.target.closest( 'a[href^="#bgcs-"]' ) : null;
			if ( ! link ) {
				return;
			}
			var href = link.getAttribute( 'href' ) || '';
			var workspaces = document.querySelectorAll( '[data-bgcs-task-tabs]' );
			for ( var i = 0; i < workspaces.length; i++ ) {
				var workspace = workspaces[i];
				var workspaceId = String( workspace.getAttribute( 'data-bgcs-task-tabs' ) || '' );
				var prefix = '#bgcs-' + workspaceId + '-';
				if ( href.indexOf( prefix ) !== 0 ) {
					continue;
				}
				var tabId = href.slice( prefix.length );
				var tab = workspace.querySelector( '[data-bgcs-task-tab="' + tabId + '"]' );
				if ( tab ) {
					event.preventDefault();
					tab.click();
					tab.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
				}
				break;
			}
		} );
	}

	function createInfoIcon() {
		var namespace = 'http://www.w3.org/2000/svg';
		var svg = document.createElementNS( namespace, 'svg' );
		var circle = document.createElementNS( namespace, 'circle' );
		var stem = document.createElementNS( namespace, 'path' );
		var dot = document.createElementNS( namespace, 'path' );
		svg.setAttribute( 'class', 'bgcs-ico' );
		svg.setAttribute( 'width', '16' );
		svg.setAttribute( 'height', '16' );
		svg.setAttribute( 'viewBox', '0 0 24 24' );
		svg.setAttribute( 'fill', 'none' );
		svg.setAttribute( 'stroke', 'currentColor' );
		svg.setAttribute( 'stroke-width', '2' );
		svg.setAttribute( 'aria-hidden', 'true' );
		circle.setAttribute( 'cx', '12' );
		circle.setAttribute( 'cy', '12' );
		circle.setAttribute( 'r', '10' );
		stem.setAttribute( 'd', 'M12 16v-4' );
		dot.setAttribute( 'd', 'M12 8h.01' );
		svg.appendChild( circle );
		svg.appendChild( stem );
		svg.appendChild( dot );
		return svg;
	}

	function initHelpDisclosures( scope ) {
		var form = document.getElementById( 'bgcs-settings-form' );
		if ( ! form ) {
			return;
		}

		function closePanel( panel, returnFocus ) {
			var trigger = form.querySelector( '[aria-controls="' + panel.id + '"]' );
			panel.hidden = true;
			if ( trigger ) {
				trigger.setAttribute( 'aria-expanded', 'false' );
				if ( returnFocus ) {
					trigger.focus();
				}
			}
		}

		function closeOthers( current ) {
			form.querySelectorAll( '.bgcs-help-panel:not([hidden])' ).forEach( function ( panel ) {
				if ( panel !== current ) {
					closePanel( panel, false );
				}
			} );
		}

		var index = 0;
		form.querySelectorAll( '.bgcs-field__desc, .bgcs-check__desc' ).forEach( function ( panel ) {
			if ( panel.dataset.bgcsHelpReady === 'yes' ) {
				return;
			}
			index += 1;
			panel.id = panel.id || 'bgcs-help-custom-' + index;
			panel.classList.add( 'bgcs-help-panel' );

			var trigger = form.querySelector( '[aria-controls="' + panel.id + '"]' );
			if ( ! trigger ) {
				trigger = document.createElement( 'button' );
				trigger.type = 'button';
				trigger.className = 'bgcs-help-toggle';
				trigger.setAttribute( 'aria-expanded', 'false' );
				trigger.setAttribute( 'aria-controls', panel.id );
				trigger.setAttribute( 'aria-label', t( 'showMoreInfo', 'Show more information' ) );
				trigger.appendChild( createInfoIcon() );

				var check = panel.closest( '.bgcs-check' );
				var label = panel.closest( '.bgcs-field' ) ? panel.closest( '.bgcs-field' ).querySelector( '.bgcs-field__label' ) : null;
				if ( check && check.parentNode ) {
					check.parentNode.insertBefore( trigger, check.nextSibling );
					check.parentNode.insertBefore( panel, trigger.nextSibling );
				} else if ( label ) {
					label.insertAdjacentElement( 'afterend', trigger );
				}
			}

			panel.hidden = true;
			panel.dataset.bgcsHelpReady = 'yes';
			trigger.addEventListener( 'click', function () {
				var open = trigger.getAttribute( 'aria-expanded' ) === 'true';
				closeOthers( panel );
				trigger.setAttribute( 'aria-expanded', open ? 'false' : 'true' );
				panel.hidden = open;
			} );
		} );

		document.documentElement.classList.add( 'bgcs-help-ready' );
		form.addEventListener( 'keydown', function ( event ) {
			if ( event.key !== 'Escape' ) {
				return;
			}
			var open = form.querySelector( '.bgcs-help-panel:not([hidden])' );
			if ( open ) {
				event.preventDefault();
				closePanel( open, true );
			}
		} );
	}

	/* -----------------------------------------------------------------
	 * Conditional fields: hide any .bgcs-field[data-show-if] whose
	 * controlling sibling field doesn't match. Keeps the admin tidy —
	 * options appear only when their toggle/select activates them.
	 * ----------------------------------------------------------------- */
	function controllerValue( key ) {
		// Checkbox: 'yes' when checked, '' otherwise.
		var cb = document.querySelector( 'input[type="checkbox"][name$="[' + key + ']"]' );
		if ( cb ) {
			return cb.checked ? 'yes' : 'no';
		}
		var el = document.querySelector( '[name$="[' + key + ']"]' );
		return el ? String( el.value ) : '';
	}

	function applyShowIf() {
		var nodes = document.querySelectorAll( '[data-show-if]' );
		nodes.forEach( function ( node ) {
			var conds;
			try {
				conds = JSON.parse( node.getAttribute( 'data-show-if' ) );
			} catch ( err ) {
				return;
			}
			var visible = true;
			Object.keys( conds ).forEach( function ( key ) {
				var want = conds[ key ] || [];
				if ( want.indexOf( controllerValue( key ) ) === -1 ) {
					visible = false;
				}
			} );

			node.hidden = ! visible;
			node.style.display = visible ? '' : 'none';
			node.setAttribute( 'aria-hidden', visible ? 'false' : 'true' );

			node.querySelectorAll( 'input, select, textarea, button' ).forEach( function ( control ) {
				if ( ! visible ) {
					if ( ! control.disabled ) {
						control.dataset.bgcsConditionalDisabled = 'yes';
					}
					control.disabled = true;
					if ( typeof control.setCustomValidity === 'function' ) {
						control.setCustomValidity( '' );
					}
				} else if ( control.dataset.bgcsConditionalDisabled === 'yes' ) {
					control.disabled = false;
					delete control.dataset.bgcsConditionalDisabled;
				}
			} );
		} );

		// A remote select can have both a show-if condition and a data dependency.
		// Re-apply the dependency after a hidden branch becomes visible.
		document.querySelectorAll( 'select.bgcs-remote-select[data-depends-on]' ).forEach( function ( select ) {
			var hiddenParent = select.closest( '[data-show-if][hidden]' );
			if ( hiddenParent ) {
				select.disabled = true;
				return;
			}
			if ( select.dataset.bgcsConditionalDisabled !== 'yes' ) {
				select.disabled = ! dependencyValue( select );
			}
		} );
	}

	function createChevronIcon() {
		var namespace = 'http://www.w3.org/2000/svg';
		var svg = document.createElementNS( namespace, 'svg' );
		var path = document.createElementNS( namespace, 'path' );

		svg.setAttribute( 'class', 'bgcs-ico' );
		svg.setAttribute( 'width', '18' );
		svg.setAttribute( 'height', '18' );
		svg.setAttribute( 'viewBox', '0 0 24 24' );
		svg.setAttribute( 'fill', 'none' );
		svg.setAttribute( 'stroke', 'currentColor' );
		svg.setAttribute( 'stroke-width', '2' );
		svg.setAttribute( 'stroke-linecap', 'round' );
		svg.setAttribute( 'stroke-linejoin', 'round' );
		svg.setAttribute( 'aria-hidden', 'true' );
		svg.setAttribute( 'focusable', 'false' );
		path.setAttribute( 'd', 'm6 9 6 6 6-6' );
		svg.appendChild( path );

		return svg;
	}

	function upgradeLegacyCards() {
		var form = document.getElementById( 'bgcs-settings-form' );
		if ( ! form ) {
			return;
		}

		form.querySelectorAll( '.bgcs-card--standalone:not(.bgcs-accordion)' ).forEach( function ( card, index ) {
			var head = Array.prototype.find.call( card.children, function ( child ) {
				return child.classList && child.classList.contains( 'bgcs-card__head' );
			} );
			var body = Array.prototype.find.call( card.children, function ( child ) {
				return child.classList && child.classList.contains( 'bgcs-card__body' );
			} );
			if ( ! head || ! body ) {
				return;
			}

			var title = head.querySelector( '.bgcs-card__title' );
			var slug = title ? title.textContent.toLowerCase().replace( /[^a-z0-9а-я]+/gi, '-' ).replace( /^-|-$/g, '' ) : '';
			var id = 'custom-' + ( slug || index );
			var panelId = 'bgcs-accordion-' + id;
			var trigger = document.createElement( 'button' );
			var chevron = document.createElement( 'span' );

			trigger.type = 'button';
			trigger.className = head.className + ' bgcs-accordion__trigger';
			trigger.setAttribute( 'aria-expanded', 'false' );
			trigger.setAttribute( 'aria-controls', panelId );
			while ( head.firstChild ) {
				trigger.appendChild( head.firstChild );
			}
			chevron.className = 'bgcs-accordion__chevron';
			chevron.setAttribute( 'aria-hidden', 'true' );
			chevron.appendChild( createChevronIcon() );
			trigger.appendChild( chevron );
			head.parentNode.replaceChild( trigger, head );

			body.id = panelId;
			body.classList.add( 'bgcs-accordion__panel' );
			body.hidden = true;
			card.classList.add( 'bgcs-accordion' );
			card.setAttribute( 'data-bgcs-accordion', id );
		} );
	}

	function bgcsAccordion() {
		upgradeLegacyCards();

		function setOpen( section, open ) {
			var trigger = section.querySelector( '.bgcs-accordion__trigger' );
			var panel = section.querySelector( '.bgcs-accordion__panel' );
			if ( ! trigger || ! panel ) {
				return;
			}
			trigger.setAttribute( 'aria-expanded', open ? 'true' : 'false' );
			panel.hidden = ! open;
			section.classList.toggle( 'is-open', open );
			if ( open ) {
				applyShowIf();
			}
		}

		document.querySelectorAll( '[data-bgcs-accordion]' ).forEach( function ( section ) {
			var trigger = section.querySelector( '.bgcs-accordion__trigger' );

			if ( trigger ) {
				trigger.addEventListener( 'click', function () {
					setOpen( section, trigger.getAttribute( 'aria-expanded' ) !== 'true' );
				} );
			}
			setOpen( section, false );
		} );

		function revealTarget( target ) {
			var section = target && target.closest ? target.closest( '[data-bgcs-accordion]' ) : null;
			if ( section ) {
				setOpen( section, true );
			}
		}

		if ( window.location.hash ) {
			var target = document.getElementById( window.location.hash.slice( 1 ) );
			revealTarget( target );
		}

		document.addEventListener( 'click', function ( event ) {
			var link = event.target && event.target.closest ? event.target.closest( 'a[href^="#"]' ) : null;
			if ( ! link ) {
				return;
			}
			var target = document.getElementById( link.getAttribute( 'href' ).slice( 1 ) );
			revealTarget( target );
		} );
	}

	document.addEventListener( 'change', applyShowIf );
	document.addEventListener( 'input', applyShowIf );
	if ( document.readyState !== 'loading' ) {
		applyShowIf();
		bgcsAccordion();
		initSearchableSelects( document );
		initRemoteSelects( document );
		initPricingRules( document );
		initTaskTabs( document );
		initHelpDisclosures( document );
	} else {
		document.addEventListener( 'DOMContentLoaded', applyShowIf );
		document.addEventListener( 'DOMContentLoaded', bgcsAccordion );
		document.addEventListener( 'DOMContentLoaded', function () {
			initSearchableSelects( document );
			initRemoteSelects( document );
			initPricingRules( document );
			initTaskTabs( document );
			initHelpDisclosures( document );
		} );
	}

	document.addEventListener( 'submit', function ( event ) {
		if ( ! event.target.classList.contains( 'bgcs-sender-refresh-form' ) ) {
			return;
		}
		var message = t( 'senderRefreshConfirm', 'This will replace only the data currently provided by the selected courier profile. Manual fields without an API value will be preserved. Continue?' );
		if ( ! window.confirm( message ) ) {
			event.preventDefault();
		}
	} );

	document.addEventListener( 'change', function ( event ) {
		var input = event.target;
		if ( ! input.matches || ! input.matches( '.bgcs-module-toggle input[data-bgcs-module-toggle]' ) ) {
			return;
		}

		var moduleId = String( input.getAttribute( 'data-bgcs-module-id' ) || '' );
		if ( ! moduleId || ( input.closest( '.bgcs-module-toggle' ) && input.closest( '.bgcs-module-toggle' ).classList.contains( 'is-saving' ) ) ) {
			return;
		}

		var previousEnabled = 'yes' === input.getAttribute( 'data-bgcs-enabled' );
		saveModuleToggle( moduleId, !! input.checked, previousEnabled );
	} );

	document.addEventListener( 'click', function ( event ) {
		var toggle = event.target && event.target.closest ? event.target.closest( 'a.bgcs-switch[data-bgcs-module-toggle]' ) : null;
		if ( ! toggle ) {
			return;
		}

		// Keep the signed admin-post URL as a functional no-JS fallback. When the
		// AJAX runtime is available, the same control persists immediately without
		// a page navigation or a separate "Save changes" action.
		if ( ! window.bgcsSettings || ! bgcsSettings.ajaxUrl || ! window.fetch ) {
			return;
		}

		event.preventDefault();
		if ( toggle.classList.contains( 'is-saving' ) ) {
			return;
		}

		var moduleId = String( toggle.getAttribute( 'data-bgcs-module-id' ) || '' );
		if ( ! moduleId ) {
			return;
		}

		var previousEnabled = 'yes' === toggle.getAttribute( 'data-bgcs-enabled' );
		saveModuleToggle( moduleId, ! previousEnabled, previousEnabled );
	} );

	document.addEventListener( 'input', function ( e ) {
		var el = e.target;

		// Swatch changed → update the paired hex text input.
		if ( el.classList && el.classList.contains( 'bgcs-color__swatch' ) ) {
			var targetId = el.getAttribute( 'data-target' );
			var hex = targetId ? document.getElementById( targetId ) : null;
			if ( hex ) {
				hex.value = el.value.toUpperCase();
			}
			return;
		}

		// Hex text changed → update the paired swatch when valid.
		if ( el.classList && el.classList.contains( 'bgcs-color__hex' ) ) {
			var swatch = el.parentNode ? el.parentNode.querySelector( '.bgcs-color__swatch' ) : null;
			if ( swatch && isHex( el.value ) ) {
				swatch.value = el.value;
			}
		}
	} );

	// Media Library picker for 'media' fields (button next to a URL input).
	document.addEventListener( 'click', function ( e ) {
		var btn = e.target && e.target.closest ? e.target.closest( '.bgcs-media__pick' ) : null;
		if ( ! btn || ! window.wp || ! window.wp.media ) {
			return;
		}
		e.preventDefault();

		var targetId = btn.getAttribute( 'data-target' );
		var input = targetId ? document.getElementById( targetId ) : null;
		if ( ! input ) {
			return;
		}

		var frame = window.wp.media( {
			title: t( 'selectImage', 'Select image' ),
			library: { type: 'image' },
			multiple: false,
		} );

		frame.on( 'select', function () {
			var att = frame.state().get( 'selection' ).first();
			if ( att ) {
				input.value = att.get( 'url' ) || '';
				input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
			}
		} );

		frame.open();
	} );
	document.addEventListener( 'click', function ( event ) {
		var btn = event.target && event.target.closest ? event.target.closest( '.bgcs-copy-text' ) : null;
		if ( ! btn ) {
			return;
		}
		event.preventDefault();
		var value = String( btn.getAttribute( 'data-copy' ) || '' );
		if ( ! value ) {
			return;
		}
		var original = btn.textContent;
		function done() {
			btn.textContent = t( 'copied', 'Copied' );
			window.setTimeout( function () { btn.textContent = original; }, 1600 );
		}
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( value ).then( done ).catch( function () {} );
			return;
		}
		var helper = document.createElement( 'textarea' );
		helper.value = value;
		helper.setAttribute( 'readonly', 'readonly' );
		helper.style.position = 'fixed';
		helper.style.opacity = '0';
		document.body.appendChild( helper );
		helper.select();
		try { if ( document.execCommand( 'copy' ) ) { done(); } } catch ( error ) {}
		document.body.removeChild( helper );
	} );

} )();

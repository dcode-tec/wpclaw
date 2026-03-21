/**
 * WP-Claw Admin JavaScript
 *
 * Vanilla JS — no jQuery dependency.
 * Relies on `wpClaw` object injected via wp_localize_script:
 *   {
 *     restUrl:   string  — base REST URL ending with '/'
 *     nonce:     string  — WP REST nonce
 *     ajaxUrl:   string  — admin-ajax.php URL
 *     page:      string  — current admin page identifier
 *   }
 *
 * @package    WPClaw
 * @subpackage WPClaw/admin/js
 * @author     dcode technologies <dev@d-code.lu>
 * @copyright  2026 dcode technologies S.a r.l.
 * @license    GPL-2.0-or-later
 * @since      1.0.0
 */

( function () {
	'use strict';

	/* =========================================================================
	   Helpers
	   ========================================================================= */

	/**
	 * Build fetch options with the required WP REST nonce header.
	 *
	 * @param {string} method  HTTP method.
	 * @param {*}      [body]  Optional request body — will be JSON.stringify'd.
	 * @return {RequestInit}
	 */
	function buildFetchOptions( method, body ) {
		/** @type {HeadersInit} */
		var headers = {
			'Content-Type': 'application/json',
			'X-WP-Nonce': wpClaw.nonce,
		};

		/** @type {RequestInit} */
		var options = {
			method: method,
			headers: headers,
			credentials: 'same-origin',
		};

		if ( body !== undefined ) {
			options.body = JSON.stringify( body );
		}

		return options;
	}

	/**
	 * Show an inline admin notice inside a given container element.
	 *
	 * @param {Element} container   Parent element to prepend notice into.
	 * @param {string}  message     Notice text.
	 * @param {'success'|'warning'|'error'} [type='success']  Notice type.
	 */
	function showNotice( container, message, type ) {
		var noticeType = type || 'success';

		var notice = document.createElement( 'div' );
		notice.className =
			'wp-claw-admin-notice wp-claw-admin-notice--' + noticeType + ' notice notice-' + noticeType;
		notice.setAttribute( 'role', 'alert' );

		var p = document.createElement( 'p' );
		p.textContent = message;
		notice.appendChild( p );

		var dismiss = document.createElement( 'button' );
		dismiss.type = 'button';
		dismiss.className = 'notice-dismiss';
		dismiss.setAttribute( 'aria-label', 'Dismiss this notice' );
		dismiss.textContent = '×';
		dismiss.addEventListener( 'click', function () {
			notice.parentNode && notice.parentNode.removeChild( notice );
		} );
		notice.appendChild( dismiss );

		// Remove any existing notice of the same type first.
		var existing = container.querySelector( '.wp-claw-admin-notice' );
		if ( existing ) {
			existing.parentNode.removeChild( existing );
		}

		container.insertBefore( notice, container.firstChild );

		// Auto-dismiss success notices after 5 s.
		if ( noticeType === 'success' ) {
			setTimeout( function () {
				if ( notice.parentNode ) {
					notice.parentNode.removeChild( notice );
				}
			}, 5000 );
		}
	}

	/**
	 * Set a button into a loading state, returning a restore function.
	 *
	 * @param {HTMLButtonElement} btn
	 * @return {function(): void} Call to restore the button.
	 */
	function setButtonLoading( btn ) {
		var original = btn.textContent;
		btn.disabled = true;
		btn.textContent = '…';
		return function restore() {
			btn.disabled = false;
			btn.textContent = original;
		};
	}

	/**
	 * Update a status pill element's text and class.
	 *
	 * @param {Element} pill    The pill element.
	 * @param {string}  status  New status value (e.g. 'done', 'failed').
	 */
	function updateStatusPill( pill, status ) {
		// Remove existing status classes.
		var classes = Array.from( pill.classList );
		classes.forEach( function ( cls ) {
			if ( cls.indexOf( 'wp-claw-admin-status-' ) === 0 ) {
				pill.classList.remove( cls );
			}
		} );
		pill.classList.add( 'wp-claw-admin-status-pill' );
		pill.classList.add( 'wp-claw-admin-status-' + status );
		pill.textContent = status.replace( '_', ' ' );
	}

	/* =========================================================================
	   1. Proposal Actions (Approve / Reject)
	   ========================================================================= */

	/**
	 * Handle a proposal action button click (approve or reject).
	 *
	 * @param {MouseEvent} event
	 */
	function handleProposalAction( event ) {
		var btn = event.currentTarget;
		var proposalId = btn.getAttribute( 'data-proposal-id' );
		var action = btn.getAttribute( 'data-action' ); // 'approve' or 'reject'

		if ( ! proposalId || ! action ) {
			return;
		}

		var row = btn.closest( 'tr' );
		var wrapEl = document.querySelector( '.wp-claw-admin-wrap' ) || document.body;
		var restore = setButtonLoading( btn );

		// Also disable sibling action buttons for this row.
		if ( row ) {
			var siblings = row.querySelectorAll( '.wp-claw-admin-btn-approve, .wp-claw-admin-btn-reject' );
			siblings.forEach( function ( sib ) {
				sib.disabled = true;
			} );
		}

		fetch(
			wpClaw.restUrl + 'proposals/' + proposalId + '/' + action,
			buildFetchOptions( 'POST' )
		)
			.then( function ( response ) {
				if ( ! response.ok ) {
					return response.json().then( function ( data ) {
						throw new Error( data.message || 'Request failed (' + response.status + ')' );
					} );
				}
				return response.json();
			} )
			.then( function () {
				// Update status pill in the row.
				if ( row ) {
					var pill = row.querySelector( '.wp-claw-admin-status-pill' );
					if ( pill ) {
						updateStatusPill( pill, action === 'approve' ? 'done' : 'failed' );
					}
					// Keep buttons disabled after success.
					var allBtns = row.querySelectorAll( '.wp-claw-admin-btn' );
					allBtns.forEach( function ( b ) {
						b.disabled = true;
					} );
				}

				var label = action === 'approve' ? 'Proposal approved.' : 'Proposal rejected.';
				showNotice( wrapEl, label, 'success' );
			} )
			.catch( function ( err ) {
				restore();
				// Re-enable sibling buttons on failure.
				if ( row ) {
					var siblings = row.querySelectorAll( '.wp-claw-admin-btn-approve, .wp-claw-admin-btn-reject' );
					siblings.forEach( function ( sib ) {
						sib.disabled = false;
					} );
				}
				showNotice( wrapEl, 'Error: ' + err.message, 'error' );
			} );
	}

	/**
	 * Attach proposal action handlers.
	 */
	function initProposalActions() {
		document.querySelectorAll(
			'.wp-claw-admin-btn-approve, .wp-claw-admin-btn-reject'
		).forEach( function ( btn ) {
			btn.addEventListener( 'click', handleProposalAction );
		} );
	}

	/* =========================================================================
	   2. Dashboard Auto-Refresh (every 60 s)
	   ========================================================================= */

	/**
	 * Fetch fresh agent data and update each agent card in the DOM.
	 */
	function refreshAgentCards() {
		fetch( wpClaw.restUrl + 'agents', buildFetchOptions( 'GET' ) )
			.then( function ( response ) {
				if ( ! response.ok ) {
					throw new Error( 'Agent refresh failed (' + response.status + ')' );
				}
				return response.json();
			} )
			.then( function ( agents ) {
				if ( ! Array.isArray( agents ) ) {
					return;
				}
				agents.forEach( function ( agent ) {
					var card = document.querySelector(
						'.wp-claw-admin-agent-card[data-agent="' + agent.name + '"]'
					);
					if ( ! card ) {
						return;
					}

					// Update status dot.
					var dot = card.querySelector( '.wp-claw-admin-status-dot' );
					if ( dot ) {
						dot.className = 'wp-claw-admin-status-dot';
						dot.classList.add( 'wp-claw-admin-status-dot--' + ( agent.health || 'offline' ) );
					}

					// Update current task.
					var taskEl = card.querySelector( '.wp-claw-admin-agent-task' );
					if ( taskEl ) {
						taskEl.textContent = agent.currentTask || 'Idle';
					}
				} );
			} )
			.catch( function () {
				// Silent — don't surface transient refresh errors to the user.
			} );
	}

	/**
	 * Start the dashboard auto-refresh loop.
	 * Only runs if `.wp-claw-admin-dashboard` is present in the DOM.
	 */
	function initDashboardRefresh() {
		var dashboard = document.querySelector( '.wp-claw-admin-dashboard' );
		if ( ! dashboard ) {
			return;
		}
		setInterval( refreshAgentCards, 60000 );
	}

	/* =========================================================================
	   3. Settings: Connection Test Button
	   ========================================================================= */

	/**
	 * Handle the "Test Connection" button click.
	 */
	function handleTestConnection() {
		var btn = document.querySelector( '.wp-claw-admin-test-connection' );
		if ( ! btn ) {
			return;
		}

		btn.addEventListener( 'click', function () {
			var wrapEl = document.querySelector( '.wp-claw-admin-wrap' ) || document.body;
			var restore = setButtonLoading( btn );

			fetch( wpClaw.restUrl + 'health', buildFetchOptions( 'GET' ) )
				.then( function ( response ) {
					restore();
					if ( ! response.ok ) {
						throw new Error( 'Connection failed (' + response.status + ')' );
					}
					return response.json();
				} )
				.then( function ( data ) {
					var msg = data.status === 'ok'
						? 'Connection successful. Klawty instance is reachable.'
						: 'Connected but Klawty reported status: ' + ( data.status || 'unknown' );
					var type = data.status === 'ok' ? 'success' : 'warning';
					showNotice( wrapEl, msg, type );

					// Update connection status dot if present.
					var dot = document.querySelector( '.wp-claw-admin-connection-status .wp-claw-admin-status-dot' );
					if ( dot ) {
						dot.className = 'wp-claw-admin-status-dot';
						dot.classList.add(
							data.status === 'ok'
								? 'wp-claw-admin-status-dot--connected'
								: 'wp-claw-admin-status-dot--warning'
						);
					}
				} )
				.catch( function ( err ) {
					restore();
					showNotice( wrapEl, 'Connection error: ' + err.message, 'error' );

					// Update dot to offline.
					var dot = document.querySelector( '.wp-claw-admin-connection-status .wp-claw-admin-status-dot' );
					if ( dot ) {
						dot.className = 'wp-claw-admin-status-dot wp-claw-admin-status-dot--offline';
					}
				} );
		} );
	}

	/* =========================================================================
	   4. Module Toggles: AJAX Save
	   ========================================================================= */

	/**
	 * Collect the current set of enabled modules from the DOM and POST to the API.
	 */
	function saveModuleSettings() {
		var enabledModules = [];

		document.querySelectorAll( '.wp-claw-admin-toggle input[type="checkbox"]' ).forEach(
			function ( checkbox ) {
				if ( checkbox.checked ) {
					enabledModules.push( checkbox.value );
				}
			}
		);

		var wrapEl = document.querySelector( '.wp-claw-admin-wrap' ) || document.body;

		fetch(
			wpClaw.restUrl + 'settings/modules',
			buildFetchOptions( 'POST', { modules: enabledModules } )
		)
			.then( function ( response ) {
				if ( ! response.ok ) {
					return response.json().then( function ( data ) {
						throw new Error( data.message || 'Save failed (' + response.status + ')' );
					} );
				}
				return response.json();
			} )
			.then( function () {
				showNotice( wrapEl, 'Module settings saved.', 'success' );
			} )
			.catch( function ( err ) {
				showNotice( wrapEl, 'Failed to save modules: ' + err.message, 'error' );
			} );
	}

	/**
	 * Attach change handlers to module toggle checkboxes.
	 */
	function initModuleToggles() {
		var toggles = document.querySelectorAll( '.wp-claw-admin-toggle input[type="checkbox"]' );
		if ( ! toggles.length ) {
			return;
		}

		toggles.forEach( function ( checkbox ) {
			checkbox.addEventListener( 'change', function () {
				// Debounce — wait 400 ms in case the user is toggling several quickly.
				clearTimeout( checkbox._wpClawSaveTimer );
				checkbox._wpClawSaveTimer = setTimeout( saveModuleSettings, 400 );
			} );
		} );
	}

	/* =========================================================================
	   5. Admin Notices: Dismiss
	   ========================================================================= */

	/**
	 * Attach dismiss handlers to all existing .notice elements with .notice-dismiss.
	 * Also uses event delegation for dynamically inserted notices.
	 */
	function initNoticeDismiss() {
		// Delegation on document.body to handle dynamic notices.
		document.body.addEventListener( 'click', function ( event ) {
			var dismissBtn = event.target.closest( '.notice-dismiss' );
			if ( ! dismissBtn ) {
				return;
			}
			var notice = dismissBtn.closest( '.notice, .wp-claw-admin-notice' );
			if ( notice && notice.parentNode ) {
				notice.parentNode.removeChild( notice );
			}
		} );
	}

	/* =========================================================================
	   6. Tab Navigation
	   ========================================================================= */

	/**
	 * Activate a tab by its ID, showing the matching panel and deactivating others.
	 *
	 * @param {string} tabId  The value of the tab's `data-tab` attribute.
	 */
	function activateTab( tabId ) {
		// Update tab buttons.
		document.querySelectorAll( '.wp-claw-admin-tab' ).forEach( function ( tab ) {
			var isActive = tab.getAttribute( 'data-tab' ) === tabId;
			tab.classList.toggle( 'active', isActive );
			tab.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
		} );

		// Update panels.
		document.querySelectorAll( '.wp-claw-admin-tab-panel' ).forEach( function ( panel ) {
			var isActive = panel.getAttribute( 'id' ) === 'wp-claw-tab-' + tabId;
			panel.classList.toggle( 'active', isActive );
			panel.hidden = ! isActive;
		} );

		// Persist active tab to sessionStorage so page reloads keep state.
		try {
			sessionStorage.setItem( 'wpClawActiveTab', tabId );
		} catch ( e ) {
			// sessionStorage unavailable — ignore.
		}
	}

	/**
	 * Initialise the tab navigation component.
	 */
	function initTabs() {
		var tabContainer = document.querySelector( '.wp-claw-admin-tabs' );
		if ( ! tabContainer ) {
			return;
		}

		tabContainer.setAttribute( 'role', 'tablist' );

		tabContainer.querySelectorAll( '.wp-claw-admin-tab' ).forEach( function ( tab, index ) {
			tab.setAttribute( 'role', 'tab' );
			tab.setAttribute( 'tabindex', index === 0 ? '0' : '-1' );

			tab.addEventListener( 'click', function ( event ) {
				event.preventDefault();
				activateTab( tab.getAttribute( 'data-tab' ) );
			} );

			// Keyboard navigation (left/right arrows).
			tab.addEventListener( 'keydown', function ( event ) {
				var tabs = Array.from( tabContainer.querySelectorAll( '.wp-claw-admin-tab' ) );
				var current = tabs.indexOf( tab );
				var next = -1;

				if ( event.key === 'ArrowRight' ) {
					next = ( current + 1 ) % tabs.length;
				} else if ( event.key === 'ArrowLeft' ) {
					next = ( current - 1 + tabs.length ) % tabs.length;
				}

				if ( next >= 0 ) {
					event.preventDefault();
					tabs[ next ].focus();
					activateTab( tabs[ next ].getAttribute( 'data-tab' ) );
				}
			} );
		} );

		// Restore persisted tab or default to first.
		var firstTab = tabContainer.querySelector( '.wp-claw-admin-tab' );
		if ( ! firstTab ) {
			return;
		}

		var savedTab = null;
		try {
			savedTab = sessionStorage.getItem( 'wpClawActiveTab' );
		} catch ( e ) {
			// ignore
		}

		var targetTab = savedTab
			? tabContainer.querySelector( '.wp-claw-admin-tab[data-tab="' + savedTab + '"]' )
			: null;

		activateTab( ( targetTab || firstTab ).getAttribute( 'data-tab' ) );
	}

	/* =========================================================================
	   7. Settings: Connection Mode — Show/Hide Instance URL
	   ========================================================================= */

	/**
	 * Update instance URL row visibility based on the selected connection mode.
	 *
	 * @param {string} mode  'managed' or 'self_hosted'.
	 */
	function updateConnectionModeUI( mode ) {
		var instanceUrlRow = document.querySelector( '.wp-claw-admin-instance-url-row' );
		if ( ! instanceUrlRow ) {
			return;
		}

		var shouldShow = mode === 'self_hosted';
		instanceUrlRow.classList.toggle( 'visible', shouldShow );

		// Also update ARIA attributes.
		var input = instanceUrlRow.querySelector( 'input' );
		if ( input ) {
			input.setAttribute( 'aria-required', shouldShow ? 'true' : 'false' );
		}
	}

	/**
	 * Attach change handlers to the connection mode radio group.
	 */
	function initConnectionMode() {
		var radios = document.querySelectorAll( 'input[name="wp_claw_connection_mode"]' );
		if ( ! radios.length ) {
			return;
		}

		radios.forEach( function ( radio ) {
			radio.addEventListener( 'change', function () {
				if ( radio.checked ) {
					updateConnectionModeUI( radio.value );
				}
			} );
		} );

		// Set initial state based on the currently checked radio.
		var checked = document.querySelector( 'input[name="wp_claw_connection_mode"]:checked' );
		if ( checked ) {
			updateConnectionModeUI( checked.value );
		}
	}

	/* =========================================================================
	   8. Password Field Toggle
	   ========================================================================= */

	/**
	 * Attach show/hide handlers to all password toggle buttons.
	 */
	function initPasswordToggles() {
		document.querySelectorAll( '.wp-claw-admin-toggle-password' ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function () {
				var wrap = btn.closest( '.wp-claw-admin-password-wrap' );
				if ( ! wrap ) {
					return;
				}
				var input = wrap.querySelector( 'input[type="password"], input[type="text"]' );
				if ( ! input ) {
					return;
				}
				var isPassword = input.type === 'password';
				input.type = isPassword ? 'text' : 'password';
				btn.textContent = isPassword ? 'Hide' : 'Show';
				btn.setAttribute( 'aria-pressed', isPassword ? 'true' : 'false' );
			} );
		} );
	}

	/* =========================================================================
	   Boot — initialise all components on DOMContentLoaded
	   ========================================================================= */

	/**
	 * Main initialisation entry point.
	 */
	function init() {
		if ( typeof wpClaw === 'undefined' ) {
			// Safety guard — the localized object must be present.
			return;
		}

		initNoticeDismiss();
		initProposalActions();
		initDashboardRefresh();
		handleTestConnection();
		initModuleToggles();
		initTabs();
		initConnectionMode();
		initPasswordToggles();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		// DOM already ready (script loaded with `defer` or at bottom of body).
		init();
	}
} )();

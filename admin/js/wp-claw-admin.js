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

		var notice       = document.createElement( 'div' );
		notice.className =
			'wpc-notice wpc-notice--' + noticeType + ' notice notice-' + noticeType;
		notice.setAttribute( 'role', 'alert' );

		var p         = document.createElement( 'p' );
		p.textContent = message;
		notice.appendChild( p );

		var dismiss       = document.createElement( 'button' );
		dismiss.type      = 'button';
		dismiss.className = 'notice-dismiss';
		dismiss.setAttribute( 'aria-label', 'Dismiss this notice' );
		dismiss.textContent = '×';
		dismiss.addEventListener(
			'click',
			function () {
				notice.parentNode && notice.parentNode.removeChild( notice );
			}
		);
		notice.appendChild( dismiss );

		// Remove any existing notice of the same type first.
		var existing = container.querySelector( '.wpc-notice' );
		if ( existing ) {
			existing.parentNode.removeChild( existing );
		}

		container.insertBefore( notice, container.firstChild );

		// Auto-dismiss success notices after 5 s.
		if ( noticeType === 'success' ) {
			setTimeout(
				function () {
					if ( notice.parentNode ) {
							notice.parentNode.removeChild( notice );
					}
				},
				5000
			);
		}
	}

	/**
	 * Set a button into a loading state, returning a restore function.
	 *
	 * @param {HTMLButtonElement} btn
	 * @return {function(): void} Call to restore the button.
	 */
	function setButtonLoading( btn ) {
		var original    = btn.textContent;
		btn.disabled    = true;
		btn.textContent = '…';
		return function restore() {
			btn.disabled    = false;
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
		classes.forEach(
			function ( cls ) {
				if ( cls.indexOf( 'wpc-status-' ) === 0 ) {
						pill.classList.remove( cls );
				}
			}
		);
		pill.classList.add( 'wpc-status-pill' );
		pill.classList.add( 'wpc-status-' + status );
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
		var btn        = event.currentTarget;
		var proposalId = btn.getAttribute( 'data-proposal-id' );
		var action     = btn.getAttribute( 'data-action' ); // 'approve' or 'reject'

		if ( ! proposalId || ! action ) {
			return;
		}

		var row     = btn.closest( 'tr' );
		var wrapEl  = document.querySelector( '.wpc-wrap' ) || document.body;
		var restore = setButtonLoading( btn );

		// Also disable sibling action buttons for this row.
		if ( row ) {
			var siblings = row.querySelectorAll( '.wpc-btn-approve, .wpc-btn-reject' );
			siblings.forEach(
				function ( sib ) {
					sib.disabled = true;
				}
			);
		}

		fetch(
			wpClaw.restUrl + 'proposals/' + proposalId + '/' + action,
			buildFetchOptions( 'POST' )
		)
			.then(
				function ( response ) {
					if ( ! response.ok ) {
							return response.json().then(
								function ( data ) {
									throw new Error( data.message || 'Request failed (' + response.status + ')' );
								}
							);
					}
					return response.json();
				}
			)
			.then(
				function () {
					// Update status pill in the row.
					if ( row ) {
							var pill = row.querySelector( '.wpc-status-pill' );
						if ( pill ) {
							updateStatusPill( pill, action === 'approve' ? 'done' : 'failed' );
						}
						// Keep buttons disabled after success.
						var allBtns = row.querySelectorAll( '.wpc-btn' );
						allBtns.forEach(
							function ( b ) {
								b.disabled = true;
							}
						);
					}

					var label = action === 'approve' ? 'Proposal approved.' : 'Proposal rejected.';
					showNotice( wrapEl, label, 'success' );
				}
			)
			.catch(
				function ( err ) {
					restore();
					// Re-enable sibling buttons on failure.
					if ( row ) {
							var siblings = row.querySelectorAll( '.wpc-btn-approve, .wpc-btn-reject' );
							siblings.forEach(
								function ( sib ) {
									sib.disabled = false;
								}
							);
					}
					showNotice( wrapEl, 'Error: ' + err.message, 'error' );
				}
			);
	}

	/**
	 * Attach proposal action handlers.
	 */
	function initProposalActions() {
		document.querySelectorAll(
			'.wpc-btn-approve, .wpc-btn-reject'
		).forEach(
			function ( btn ) {
				btn.addEventListener( 'click', handleProposalAction );
			}
		);
	}

	/* =========================================================================
		2. Dashboard Auto-Refresh (every 60 s)
		========================================================================= */

	/**
	 * Fetch fresh agent data and update each agent card in the DOM.
	 */
	function refreshAgentCards() {
		fetch( wpClaw.restUrl + 'agents', buildFetchOptions( 'GET' ) )
			.then(
				function ( response ) {
					if ( ! response.ok ) {
							throw new Error( 'Agent refresh failed (' + response.status + ')' );
					}
					return response.json();
				}
			)
			.then(
				function ( agents ) {
					if ( ! Array.isArray( agents ) ) {
							return;
					}
					agents.forEach(
						function ( agent ) {
							var card = document.querySelector(
								'.wpc-agent-card[data-agent="' + agent.name + '"]'
							);
							if ( ! card ) {
								return;
							}

							// Update status dot.
							var dot = card.querySelector( '.wpc-status-dot' );
							if ( dot ) {
								dot.className = 'wpc-status-dot';
								dot.classList.add( 'wpc-status-dot--' + ( agent.health || 'offline' ) );
							}

							// Update current task.
							var taskEl = card.querySelector( '.wpc-agent-task' );
							if ( taskEl ) {
								taskEl.textContent = agent.currentTask || 'Idle';
							}
						}
					);
				}
			)
			.catch(
				function () {
					// Silent — don't surface transient refresh errors to the user.
				}
			);
	}

	/**
	 * Start the dashboard auto-refresh loop.
	 * Only runs if `.wpc-dashboard` is present in the DOM.
	 */
	function initDashboardRefresh() {
		var dashboard = document.querySelector( '.wpc-dashboard' );
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
		var btn = document.querySelector( '.wpc-test-connection' );
		if ( ! btn ) {
			return;
		}

		btn.addEventListener(
			'click',
			function () {
				var wrapEl  = document.querySelector( '.wpc-wrap' ) || document.body;
				var restore = setButtonLoading( btn );

				fetch( wpClaw.restUrl + 'health', buildFetchOptions( 'GET' ) )
				.then(
					function ( response ) {
						restore();
						if ( ! response.ok ) {
								throw new Error( 'Connection failed (' + response.status + ')' );
						}
						return response.json();
					}
				)
				.then(
					function ( data ) {
						var msg  = data.status === 'ok'
						? 'Connection successful. Klawty instance is reachable.'
						: 'Connected but Klawty reported status: ' + ( data.status || 'unknown' );
						var type = data.status === 'ok' ? 'success' : 'warning';
						showNotice( wrapEl, msg, type );

						// Update connection status dot if present.
						var dot = document.querySelector( '.wpc-connection-status .wpc-status-dot' );
						if ( dot ) {
								dot.className = 'wpc-status-dot';
								dot.classList.add(
									data.status === 'ok'
									? 'wpc-status-dot--connected'
									: 'wpc-status-dot--warning'
								);
						}
					}
				)
				.catch(
					function ( err ) {
						restore();
						showNotice( wrapEl, 'Connection error: ' + err.message, 'error' );

						// Update dot to offline.
						var dot = document.querySelector( '.wpc-connection-status .wpc-status-dot' );
						if ( dot ) {
								dot.className = 'wpc-status-dot wpc-status-dot--offline';
						}
					}
				);
			}
		);
	}

	/* =========================================================================
		4. Module Toggles: AJAX Save
		========================================================================= */

	/**
	 * Collect the current set of enabled modules from the DOM and POST to the API.
	 */
	function saveModuleSettings() {
		var enabledModules = [];

		document.querySelectorAll( '.wpc-toggle input[type="checkbox"]' ).forEach(
			function ( checkbox ) {
				if ( checkbox.checked ) {
					enabledModules.push( checkbox.value );
				}
			}
		);

		var wrapEl = document.querySelector( '.wpc-wrap' ) || document.body;

		fetch(
			wpClaw.restUrl + 'settings/modules',
			buildFetchOptions( 'POST', { modules: enabledModules } )
		)
			.then(
				function ( response ) {
					if ( ! response.ok ) {
							return response.json().then(
								function ( data ) {
									throw new Error( data.message || 'Save failed (' + response.status + ')' );
								}
							);
					}
					return response.json();
				}
			)
			.then(
				function () {
					showNotice( wrapEl, 'Module settings saved.', 'success' );
				}
			)
			.catch(
				function ( err ) {
					showNotice( wrapEl, 'Failed to save modules: ' + err.message, 'error' );
				}
			);
	}

	/**
	 * Attach change handlers to module toggle checkboxes.
	 */
	function initModuleToggles() {
		var toggles = document.querySelectorAll( '.wpc-toggle input[type="checkbox"]' );
		if ( ! toggles.length ) {
			return;
		}

		toggles.forEach(
			function ( checkbox ) {
				checkbox.addEventListener(
					'change',
					function () {
						// Debounce — wait 400 ms in case the user is toggling several quickly.
						clearTimeout( checkbox._wpClawSaveTimer );
						checkbox._wpClawSaveTimer = setTimeout( saveModuleSettings, 400 );
					}
				);
			}
		);
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
		document.body.addEventListener(
			'click',
			function ( event ) {
				var dismissBtn = event.target.closest( '.notice-dismiss' );
				if ( ! dismissBtn ) {
					return;
				}
				var notice = dismissBtn.closest( '.notice, .wpc-notice' );
				if ( notice && notice.parentNode ) {
					notice.parentNode.removeChild( notice );
				}
			}
		);
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
		document.querySelectorAll( '.wpc-tab' ).forEach(
			function ( tab ) {
				var isActive = tab.getAttribute( 'data-tab' ) === tabId;
				tab.classList.toggle( 'active', isActive );
				tab.setAttribute( 'aria-selected', isActive ? 'true' : 'false' );
			}
		);

		// Update panels.
		document.querySelectorAll( '.wpc-tab-panel' ).forEach(
			function ( panel ) {
				var isActive = panel.getAttribute( 'id' ) === 'wp-claw-tab-' + tabId;
				panel.classList.toggle( 'active', isActive );
				panel.hidden = ! isActive;
			}
		);

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
		var tabContainer = document.querySelector( '.wpc-tabs' );
		if ( ! tabContainer ) {
			return;
		}

		tabContainer.setAttribute( 'role', 'tablist' );

		tabContainer.querySelectorAll( '.wpc-tab' ).forEach(
			function ( tab, index ) {
				tab.setAttribute( 'role', 'tab' );
				tab.setAttribute( 'tabindex', index === 0 ? '0' : '-1' );

				tab.addEventListener(
					'click',
					function ( event ) {
						event.preventDefault();
						activateTab( tab.getAttribute( 'data-tab' ) );
					}
				);

				// Keyboard navigation (left/right arrows).
				tab.addEventListener(
					'keydown',
					function ( event ) {
						var tabs    = Array.from( tabContainer.querySelectorAll( '.wpc-tab' ) );
						var current = tabs.indexOf( tab );
						var next    = -1;

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
					}
				);
			}
		);

		// Restore persisted tab or default to first.
		var firstTab = tabContainer.querySelector( '.wpc-tab' );
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
			? tabContainer.querySelector( '.wpc-tab[data-tab="' + savedTab + '"]' )
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
		var instanceUrlRow = document.querySelector( '.wpc-instance-url-row' );
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

		radios.forEach(
			function ( radio ) {
				radio.addEventListener(
					'change',
					function () {
						if ( radio.checked ) {
							updateConnectionModeUI( radio.value );
						}
					}
				);
			}
		);

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
		document.querySelectorAll( '.wpc-toggle-password' ).forEach(
			function ( btn ) {
				btn.addEventListener(
					'click',
					function () {
						var wrap = btn.closest( '.wpc-password-wrap' );
						if ( ! wrap ) {
							return;
						}
						var input = wrap.querySelector( 'input[type="password"], input[type="text"]' );
						if ( ! input ) {
								return;
						}
						var isPassword  = input.type === 'password';
						input.type      = isPassword ? 'text' : 'password';
						btn.textContent = isPassword ? 'Hide' : 'Show';
						btn.setAttribute( 'aria-pressed', isPassword ? 'true' : 'false' );
					}
				);
			}
		);
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

/* =========================================================================
	Command Center
	========================================================================= */

( function () {
	'use strict';

	/**
	 * Initialise the Command Center interface.
	 * Only runs when the localized wpClaw.page is 'command-center'.
	 */
	function initCommandCenter() {
		if ( typeof wpClaw === 'undefined' || wpClaw.page !== 'command-center' ) {
			return;
		}

		// ----- DOM references -----
		var messagesEl    = document.getElementById( 'wp-claw-cc-messages' );
		var promptEl      = document.getElementById( 'wp-claw-cc-prompt' );
		var pinEl         = document.getElementById( 'wp-claw-cc-pin' );
		var sendBtn       = document.getElementById( 'wp-claw-cc-send' );
		var historyRows   = document.getElementById( 'wp-claw-cc-history-rows' );
		var rateStatus    = document.getElementById( 'wp-claw-cc-rate-status' );
		var historyToggle = document.getElementById( 'wp-claw-cc-history-toggle' );
		var historyBody   = document.getElementById( 'wp-claw-cc-history-body' );

		// ----- PIN setup (setup page only) -----
		var savePinBtn = document.getElementById( 'wp-claw-cc-save-pin' );

		if ( savePinBtn ) {
			savePinBtn.addEventListener(
				'click',
				function () {
					var newPin     = document.getElementById( 'wp-claw-cc-new-pin' );
					var confirmPin = document.getElementById( 'wp-claw-cc-confirm-pin' );
					var errorEl    = document.getElementById( 'wp-claw-cc-pin-error' );
					var pin        = newPin ? newPin.value : '';
					var confirmVal = confirmPin ? confirmPin.value : '';

					if ( ! errorEl ) {
						return;
					}

					errorEl.style.display = 'none';
					errorEl.textContent   = '';

					if ( pin.length < 4 || pin.length > 8 ) {
						errorEl.textContent   = 'PIN must be 4-8 characters.';
						errorEl.style.display = 'block';
						return;
					}

					if ( pin !== confirmVal ) {
						errorEl.textContent   = 'PINs do not match.';
						errorEl.style.display = 'block';
						return;
					}

					var nonceField = document.getElementById( 'wp_claw_pin_nonce' );
					var nonce      = nonceField ? nonceField.value : '';
					var restore    = ccSetButtonLoading( savePinBtn );

					fetch(
						wpClaw.restUrl + 'wp-claw/v1/command/setup-pin',
						{
							method: 'POST',
							headers: {
								'Content-Type': 'application/json',
								'X-WP-Nonce': wpClaw.nonce,
							},
							credentials: 'same-origin',
							body: JSON.stringify( { pin: pin, nonce: nonce } ),
						}
					)
					.then(
						function ( response ) {
							return response.json();
						}
					)
					.then(
						function ( data ) {
							if ( data.success ) {
									window.location.reload();
							} else {
								restore();
								errorEl.textContent   = data.error || 'Failed to save PIN.';
								errorEl.style.display = 'block';
							}
						}
					)
					.catch(
						function ( err ) {
							restore();
							errorEl.textContent   = 'Network error: ' + err.message;
							errorEl.style.display = 'block';
						}
					);
				}
			);
		}

		// ----- Chat interface -----
		if ( ! promptEl || ! sendBtn || ! pinEl ) {
			return;
		}

		// Message ID counter — used to identify and remove loading indicators.
		var msgCounter = 0;

		/**
		 * Enable or disable the send button based on current PIN + prompt values.
		 */
		function updateSendState() {
			var ready        = pinEl.value.length >= 4 && promptEl.value.trim().length >= 3;
			sendBtn.disabled = ! ready;
			sendBtn.setAttribute( 'aria-disabled', ready ? 'false' : 'true' );
		}

		pinEl.addEventListener( 'input', updateSendState );
		promptEl.addEventListener( 'input', updateSendState );

		/**
		 * Append a message bubble to the chat area.
		 *
		 * @param {'user'|'agent'|'error'|'loading'|'system'} type  Bubble type.
		 * @param {string}  text    Message text.
		 * @param {string}  [taskId] Optional task ID to display below the message.
		 * @return {string} The generated element ID (for later removal).
		 */
		function addMessage( type, text, taskId ) {
			var id        = 'wp-claw-cc-msg-' + ( ++msgCounter );
			var div       = document.createElement( 'div' );
			div.className = 'wp-claw-cc-message wp-claw-cc-' + type;
			div.id        = id;

			var badgeLabels = {
				user:    'You',
				agent:   'Atlas',
				error:   'Error',
				loading: 'System',
				system:  'System',
			};

			var badge         = document.createElement( 'span' );
			badge.className   = 'wp-claw-cc-badge';
			badge.textContent = badgeLabels[ type ] || type;
			div.appendChild( badge );

			var p         = document.createElement( 'p' );
			p.textContent = text;
			div.appendChild( p );

			if ( taskId ) {
				var taskSpan         = document.createElement( 'span' );
				taskSpan.className   = 'wp-claw-cc-task-id';
				taskSpan.textContent = 'Task: ' + taskId;
				div.appendChild( taskSpan );
			}

			if ( messagesEl ) {
				messagesEl.appendChild( div );
				messagesEl.scrollTop = messagesEl.scrollHeight;
			}

			return id;
		}

		/**
		 * Remove a message bubble by its ID.
		 *
		 * @param {string} id  The element ID returned by addMessage().
		 */
		function removeMessage( id ) {
			var el = document.getElementById( id );
			if ( el && el.parentNode ) {
				el.parentNode.removeChild( el );
			}
		}

		/**
		 * POST a command to the REST endpoint and display the agent response.
		 */
		function sendCommand() {
			var prompt = promptEl.value.trim();
			var pin    = pinEl.value;

			if ( ! prompt || pin.length < 4 ) {
				return;
			}

			sendBtn.disabled = true;
			sendBtn.setAttribute( 'aria-disabled', 'true' );
			promptEl.value = '';

			addMessage( 'user', prompt );

			var loadingId  = addMessage( 'loading', 'Processing\u2026' );
			var nonceField = document.getElementById( 'wp_claw_command_nonce_field' );
			var nonce      = nonceField ? nonceField.value : '';

			fetch(
				wpClaw.restUrl + 'wp-claw/v1/command',
				{
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': wpClaw.nonce,
					},
					credentials: 'same-origin',
					body: JSON.stringify( { prompt: prompt, pin: pin, nonce: nonce } ),
				}
			)
				.then(
					function ( response ) {
						return response.json();
					}
				)
				.then(
					function ( data ) {
						removeMessage( loadingId );
						if ( data.success ) {
								addMessage( 'agent', data.response || 'Command received.', data.task_id || '' );
						} else {
							addMessage( 'error', data.error || 'Command failed.' );
						}
					}
				)
				.catch(
					function ( err ) {
						removeMessage( loadingId );
						addMessage( 'error', 'Network error: ' + err.message );
					}
				)
				.then(
					function () {
						updateSendState();
						loadHistory();
					}
				);
		}

		sendBtn.addEventListener( 'click', sendCommand );

		promptEl.addEventListener(
			'keydown',
			function ( e ) {
				if ( e.key === 'Enter' && ! sendBtn.disabled ) {
					e.preventDefault();
					sendCommand();
				}
			}
		);

		// ----- History loader -----

		/**
		 * Build a single <tr> element for the history table using DOM methods.
		 *
		 * @param {Object} row     History row data from the API.
		 * @param {Date}   hourAgo Timestamp 1 hour ago for rate-limit counting.
		 * @param {Date}   dayAgo  Timestamp 24 hours ago for rate-limit counting.
		 * @param {{hour: number, day: number}} counts Mutable counters object.
		 * @return {HTMLTableRowElement}
		 */
		function buildHistoryRow( row, hourAgo, dayAgo, counts ) {
			var tr     = document.createElement( 'tr' );
			var isSent = row.status === 'sent';
			var time   = new Date( row.created_at + 'Z' );

			// Time cell.
			var tdTime         = document.createElement( 'td' );
			tdTime.textContent = time.toLocaleTimeString( [], { hour: '2-digit', minute: '2-digit' } );
			tr.appendChild( tdTime );

			// Status cell.
			var tdStatus         = document.createElement( 'td' );
			tdStatus.className   = isSent ? 'wp-claw-cc-status-ok' : 'wp-claw-cc-status-blocked';
			tdStatus.textContent = ( isSent ? '\u2713 ' : '\u2717 ' ) + String( row.status || '' );
			tr.appendChild( tdStatus );

			// Prompt cell (truncated).
			var prompt           = String( row.prompt || '' );
			var tdPrompt         = document.createElement( 'td' );
			tdPrompt.textContent = prompt.length > 60 ? prompt.substring( 0, 60 ) + '\u2026' : prompt;
			tr.appendChild( tdPrompt );

			// Details / reason cell.
			var tdReason         = document.createElement( 'td' );
			tdReason.textContent = String( row.reason || '' );
			tr.appendChild( tdReason );

			// Rate-limit counters.
			if ( isSent ) {
				if ( time > hourAgo ) {
					counts.hour++;
				}
				if ( time > dayAgo ) {
					counts.day++;
				}
			}

			return tr;
		}

		/**
		 * Fetch the command history from the REST endpoint and update the table.
		 */
		function loadHistory() {
			if ( ! historyRows ) {
				return;
			}

			fetch(
				wpClaw.restUrl + 'wp-claw/v1/command/history?limit=20',
				{
					method: 'GET',
					headers: { 'X-WP-Nonce': wpClaw.nonce },
					credentials: 'same-origin',
				}
			)
				.then(
					function ( response ) {
						return response.json();
					}
				)
				.then(
					function ( data ) {
						// Clear existing rows.
						while ( historyRows.firstChild ) {
								historyRows.removeChild( historyRows.firstChild );
						}

						if ( ! data.history || ! data.history.length ) {
							var emptyRow = document.createElement( 'tr' );
							var emptyTd  = document.createElement( 'td' );
							emptyTd.setAttribute( 'colspan', '4' );
							emptyTd.textContent = 'No commands yet.';
							emptyRow.appendChild( emptyTd );
							historyRows.appendChild( emptyRow );

							if ( rateStatus ) {
								rateStatus.textContent = '0/10 hourly \u00b7 0/30 daily';
							}
							return;
						}

						var now     = new Date();
						var hourAgo = new Date( now.getTime() - 3600000 );
						var dayAgo  = new Date( now.getTime() - 86400000 );
						var counts  = { hour: 0, day: 0 };

						data.history.forEach(
							function ( row ) {
								historyRows.appendChild( buildHistoryRow( row, hourAgo, dayAgo, counts ) );
							}
						);

						if ( rateStatus ) {
							rateStatus.textContent = counts.hour + '/10 hourly \u00b7 ' + counts.day + '/30 daily';
						}
					}
				)
				.catch(
					function () {
						// Clear and show error row.
						while ( historyRows.firstChild ) {
								historyRows.removeChild( historyRows.firstChild );
						}
						var errRow = document.createElement( 'tr' );
						var errTd  = document.createElement( 'td' );
						errTd.setAttribute( 'colspan', '4' );
						errTd.textContent = 'Failed to load history.';
						errRow.appendChild( errTd );
						historyRows.appendChild( errRow );
					}
				);
		}

		// ----- History toggle -----
		if ( historyToggle && historyBody ) {
			historyToggle.addEventListener(
				'click',
				function () {
					var isExpanded = historyToggle.getAttribute( 'aria-expanded' ) === 'true';
					var icon       = historyToggle.querySelector( '.wp-claw-cc-toggle-icon' );

					if ( isExpanded ) {
						historyBody.style.display = 'none';
						historyToggle.setAttribute( 'aria-expanded', 'false' );
						if ( icon ) {
							icon.textContent = '\u25bc';
						}
					} else {
						historyBody.style.display = 'block';
						historyToggle.setAttribute( 'aria-expanded', 'true' );
						if ( icon ) {
							icon.textContent = '\u25b2';
						}
					}
				}
			);
		}

		// ----- Initial data load -----
		loadHistory();
	}

	// ----- Local helper: button loading state -----

	/**
	 * Set a button into a loading state, returning a restore function.
	 * Declared separately from the main IIFE to avoid duplicate-identifier issues.
	 *
	 * @param {HTMLButtonElement} btn
	 * @return {function(): void}
	 */
	function ccSetButtonLoading( btn ) {
		var original    = btn.textContent;
		btn.disabled    = true;
		btn.textContent = '\u2026';
		return function restore() {
			btn.disabled    = false;
			btn.textContent = original;
		};
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initCommandCenter );
	} else {
		initCommandCenter();
	}
} )();

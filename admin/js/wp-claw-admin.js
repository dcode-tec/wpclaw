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
		2. Dashboard Auto-Refresh & Module State Polling (every 60 s) — v1.2.0
		========================================================================= */

	/**
	 * Fetch fresh agent data and update each agent card in the DOM.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Improved health status dot mapping.
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
				function ( data ) {
					var agents = Array.isArray( data ) ? data : ( data.agents || [] );
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
								var health   = agent.health || 'offline';
								var dotClass = ( health === 'ok' || health === 'healthy' )
									? 'green'
									: ( health === 'degraded' ? 'yellow' : 'red' );
								dot.classList.add( 'wpc-status-dot--' + dotClass );
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
	 * Fetch module states and update metric cards and constraint banners.
	 *
	 * @since 1.2.0
	 */
	function refreshModuleStates() {
		fetch( wpClaw.restUrl + 'admin/module-states', buildFetchOptions( 'GET' ) )
			.then(
				function ( response ) {
					if ( ! response.ok ) {
						throw new Error( 'Module state refresh failed' );
					}
					return response.json();
				}
			)
			.then(
				function ( data ) {
					if ( ! data.states ) {
						return;
					}

					// Update constitutional constraint banners.
					var constraints = data.states._constraints;
					if ( constraints ) {
						var haltBanner = document.querySelector( '.wpc-alert-banner--danger' );
						if ( ! constraints.operations_halted && haltBanner ) {
							haltBanner.remove();
						}
					}

					// Update metric card values by data-module attribute.
					var cards = document.querySelectorAll( '.wpc-metric-card[data-module]' );
					cards.forEach(
						function ( card ) {
							var mod = card.getAttribute( 'data-module' );
							if ( ! data.states[ mod ] ) {
								return;
							}
							var rows = card.querySelectorAll( '.wpc-metric-card__row' );
							rows.forEach(
								function ( row ) {
									var key = row.getAttribute( 'data-key' );
									if ( key && data.states[ mod ][ key ] !== undefined ) {
										var valueEl = row.querySelector( 'span:last-child' );
										if ( valueEl ) {
											valueEl.textContent = data.states[ mod ][ key ];
										}
									}
								}
							);
						}
					);
				}
			)
			.catch(
				function () {
					// Silent — don't surface transient refresh errors.
				}
			);
	}

	/**
	 * Start the dashboard auto-refresh loop.
	 * Only runs if `.wpc-dashboard` is present in the DOM.
	 * Polls both agent status and module states every 60 s.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Enhanced with module state polling.
	 */
	function initDashboardRefresh() {
		var dashboard = document.querySelector( '.wpc-dashboard' );
		if ( ! dashboard ) {
			return;
		}
		setInterval(
			function () {
				refreshModuleStates();
				refreshAgentCards();
			},
			60000
		);
	}

	/* =========================================================================
		3. Settings: Connection Banner + Health Polling
		========================================================================= */

	/**
	 * Update the connection banner to connected or disconnected state.
	 *
	 * @param {boolean} connected  Whether the instance is reachable.
	 * @param {string}  message    Status text shown after the em-dash.
	 */
	function updateConnectionBanner( connected, message ) {
		var banner = document.querySelector( '.wpc-connection-banner' );
		if ( ! banner ) {
			return;
		}

		banner.className = 'wpc-connection-banner ' +
			( connected ? 'wpc-connection-banner--connected' : 'wpc-connection-banner--disconnected' );

		while ( banner.firstChild ) {
			banner.removeChild( banner.firstChild );
		}

		var dot = document.createElement( 'span' );
		dot.className = 'wpc-status-dot ' + ( connected ? 'wpc-status-dot--green' : 'wpc-status-dot--red' );
		banner.appendChild( dot );

		var strong = document.createElement( 'strong' );
		strong.textContent = connected ? 'Connected' : 'Not connected';
		banner.appendChild( strong );

		banner.appendChild( document.createTextNode( ' \u2014 ' + message ) );
	}

	/**
	 * Fetch health status and update the banner.
	 *
	 * @param {boolean} silent  If true, skip toast notifications (used by auto-poll).
	 */
	function checkHealth( silent ) {
		var wrapEl = document.querySelector( '.wpc-admin-wrap' ) || document.body;

		fetch( wpClaw.restUrl + 'health', buildFetchOptions( 'GET' ) )
			.then(
				function ( response ) {
					if ( ! response.ok ) {
						return response.json().then( function ( body ) {
							throw new Error( body.message || 'Connection failed (' + response.status + ')' );
						} ).catch( function ( e ) {
							if ( e.message ) { throw e; }
							throw new Error( 'Connection failed (' + response.status + ')' );
						} );
					}
					return response.json();
				}
			)
			.then(
				function ( data ) {
					var isOk = data.status === 'ok';
					var msg  = isOk
						? 'WP-Claw is communicating with the Klawty instance.'
						: 'Klawty reported status: ' + ( data.status || 'unknown' );

					updateConnectionBanner( isOk, msg );

					if ( ! silent ) {
						showNotice(
							wrapEl,
							isOk ? 'Connection successful. Klawty instance is reachable.' : msg,
							isOk ? 'success' : 'warning'
						);
					}
				}
			)
			.catch(
				function ( err ) {
					updateConnectionBanner( false, err.message );

					if ( ! silent ) {
						showNotice( wrapEl, 'Connection error: ' + err.message, 'error' );
					}
				}
			);
	}

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
				var restore = setButtonLoading( btn );
				checkHealth( false );
				// Restore button state after a short delay (fetch is async).
				setTimeout( restore, 1500 );
			}
		);

		// Auto-poll every 30 seconds (silent — banner only, no toasts).
		if ( 'settings' === wpClaw.page ) {
			checkHealth( true );
			setInterval( function () { checkHealth( true ); }, 30000 );
		}
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
		9. Email Draft Approve / Reject — Commerce Page (v1.2.0)
		========================================================================= */

	/**
	 * Attach delegated click handlers for email draft approve/reject buttons.
	 * Only active on the commerce admin page.
	 *
	 * @since 1.2.0
	 */
	function initEmailDraftActions() {
		if ( 'commerce' !== wpClaw.page ) {
			return;
		}

		document.addEventListener(
			'click',
			function ( e ) {
				var btn = e.target.closest( '.wpc-admin-email-approve, .wpc-admin-email-reject' );
				if ( ! btn ) {
					return;
				}

				var id     = btn.getAttribute( 'data-id' );
				var action = btn.classList.contains( 'wpc-admin-email-approve' ) ? 'approve' : 'reject';
				var row    = btn.closest( 'tr' );
				var wrapEl = document.querySelector( '.wpc-wrap' ) || document.body;

				if ( 'reject' === action && ! confirm( 'Are you sure you want to reject this email draft?' ) ) {
					return;
				}

				btn.disabled    = true;
				btn.textContent = '\u2026';

				// Disable sibling button too.
				if ( row ) {
					var siblings = row.querySelectorAll( '.wpc-admin-email-approve, .wpc-admin-email-reject' );
					siblings.forEach(
						function ( s ) {
							s.disabled = true;
						}
					);
				}

				fetch(
					wpClaw.restUrl + 'admin/email-drafts/' + id + '/' + action,
					buildFetchOptions( 'POST' )
				)
					.then(
						function ( res ) {
							if ( ! res.ok ) {
								throw new Error( 'Request failed (' + res.status + ')' );
							}
							return res.json();
						}
					)
					.then(
						function ( data ) {
							if ( data.status ) {
								// Update the status cell in the row using safe DOM methods.
								var statusCell = row ? row.querySelector( '.wpc-email-status' ) : null;
								if ( statusCell ) {
									while ( statusCell.firstChild ) {
										statusCell.removeChild( statusCell.firstChild );
									}
									var badge       = document.createElement( 'span' );
									var badgeClass  = action === 'approve' ? 'done' : 'failed';
									badge.className = 'wpc-badge wpc-badge--' + badgeClass;
									badge.textContent = data.status.charAt( 0 ).toUpperCase() + data.status.slice( 1 );
									statusCell.appendChild( badge );
								}
								showNotice( wrapEl, data.message || ( 'Email draft ' + action + 'd.' ), 'success' );
							}
						}
					)
					.catch(
						function () {
							showNotice( wrapEl, wpClaw.i18n.error, 'error' );
							if ( row ) {
								var siblings = row.querySelectorAll( '.wpc-admin-email-approve, .wpc-admin-email-reject' );
								siblings.forEach(
									function ( s ) {
										s.disabled = false;
									}
								);
							}
						}
					);
			}
		);
	}

	/* =========================================================================
		10. Run Scan Trigger — Security Page (v1.2.0)
		========================================================================= */

	/**
	 * Attach delegated click handler for "Run Scan" buttons on the security page.
	 *
	 * @since 1.2.0
	 */
	function initRunScan() {
		if ( 'security' !== wpClaw.page ) {
			return;
		}

		document.addEventListener(
			'click',
			function ( e ) {
				var btn = e.target.closest( '.wpc-admin-run-scan' );
				if ( ! btn ) {
					return;
				}

				var scanType = btn.getAttribute( 'data-scan-type' );
				var wrapEl   = document.querySelector( '.wpc-wrap' ) || document.body;

				btn.classList.add( 'wpc-scan-button--loading' );
				btn.disabled = true;

				fetch(
					wpClaw.restUrl + 'admin/run-scan',
					buildFetchOptions( 'POST', { scan_type: scanType } )
				)
					.then(
						function ( res ) {
							if ( ! res.ok ) {
								throw new Error( 'Scan request failed (' + res.status + ')' );
							}
							return res.json();
						}
					)
					.then(
						function ( data ) {
							btn.classList.remove( 'wpc-scan-button--loading' );
							btn.disabled = false;
							if ( data.message ) {
								showNotice( wrapEl, data.message, 'success' );
							}
						}
					)
					.catch(
						function () {
							btn.classList.remove( 'wpc-scan-button--loading' );
							btn.disabled = false;
							showNotice( wrapEl, wpClaw.i18n.error, 'error' );
						}
					);
			}
		);
	}

	/* =========================================================================
		11. Resume Operations — Dashboard & Settings (v1.2.0)
		========================================================================= */

	/**
	 * Attach delegated click handler for the "Resume Operations" button.
	 * Clears the health-fail halt and removes the danger banner.
	 *
	 * @since 1.2.0
	 */
	function initResumeOperations() {
		if ( 'dashboard' !== wpClaw.page && 'settings' !== wpClaw.page ) {
			return;
		}

		document.addEventListener(
			'click',
			function ( e ) {
				var btn = e.target.closest( '.wpc-admin-resume-ops' );
				if ( ! btn ) {
					return;
				}

				var wrapEl  = document.querySelector( '.wpc-wrap' ) || document.body;
				var restore = setButtonLoading( btn );

				fetch(
					wpClaw.restUrl + 'admin/resume-operations',
					buildFetchOptions( 'POST' )
				)
					.then(
						function ( res ) {
							if ( ! res.ok ) {
								throw new Error( 'Resume failed (' + res.status + ')' );
							}
							return res.json();
						}
					)
					.then(
						function ( data ) {
							// Remove the alert banner with a fade-out (dashboard page).
							var banner = document.querySelector( '.wpc-alert-banner--danger' );
							if ( banner ) {
								banner.style.transition = 'opacity 0.3s';
								banner.style.opacity    = '0';
								setTimeout(
									function () {
										banner.remove();
									},
									300
								);
							}

							// Update the Operations Status row in-place (settings page).
							var row = btn.closest( 'td' );
							if ( row ) {
								while ( row.firstChild ) {
									row.removeChild( row.firstChild );
								}
								var badge = document.createElement( 'span' );
								badge.className = 'wpc-badge wpc-badge--active';
								var dot = document.createElement( 'span' );
								dot.className = 'wpc-status-dot wpc-status-dot--green';
								badge.appendChild( dot );
								badge.appendChild( document.createTextNode( ' Normal' ) );
								row.appendChild( badge );
							}

							showNotice( wrapEl, data.message || 'Operations resumed.', 'success' );
						}
					)
					.catch(
						function () {
							restore();
							showNotice( wrapEl, wpClaw.i18n.error, 'error' );
						}
					);
			}
		);
	}

	/* =========================================================================
		11b. Reset Circuit Breaker — Settings page (v1.2.2)
		========================================================================= */

	if ( 'settings' === wpClaw.page ) {
		document.addEventListener(
			'click',
			function ( e ) {
				var btn = e.target.closest( '.wpc-admin-reset-circuit-breaker' );
				if ( ! btn ) {
					return;
				}

				var wrapEl  = document.querySelector( '.wpc-admin-wrap' ) || document.body;
				var restore = setButtonLoading( btn );

				fetch(
					wpClaw.restUrl + 'admin/reset-circuit-breaker',
					buildFetchOptions( 'POST' )
				)
					.then(
						function ( res ) {
							if ( ! res.ok ) {
								throw new Error( 'Reset failed (' + res.status + ')' );
							}
							return res.json();
						}
					)
					.then(
						function ( data ) {
							var row = btn.closest( 'td' );
							if ( row ) {
								while ( row.firstChild ) {
									row.removeChild( row.firstChild );
								}
								var badge = document.createElement( 'span' );
								badge.className = 'wpc-badge wpc-badge--active';
								var dot = document.createElement( 'span' );
								dot.className = 'wpc-status-dot wpc-status-dot--green';
								badge.appendChild( dot );
								badge.appendChild( document.createTextNode( ' Closed (healthy)' ) );
								row.appendChild( badge );
							}
							showNotice( wrapEl, data.message || 'Circuit breaker reset.', 'success' );
						}
					)
					.catch(
						function () {
							restore();
							showNotice( wrapEl, wpClaw.i18n.error, 'error' );
						}
					);
			}
		);
	}

	/* =========================================================================
		12. Expandable Rows — Commerce & Proposals (v1.2.0)
		========================================================================= */

	/**
	 * Attach delegated click handler for expandable row toggles.
	 * Supports both table-based (commerce email previews) and
	 * block-based (proposal details) expandable content.
	 *
	 * @since 1.2.0
	 */
	function initExpandableRows() {
		document.addEventListener(
			'click',
			function ( e ) {
				var toggle = e.target.closest( '.wpc-expand-toggle' );
				if ( ! toggle ) {
					return;
				}

				// For table-based expandable rows.
				var expandableRow = toggle.closest( 'tr' );
				if ( expandableRow ) {
					var nextRow = expandableRow.nextElementSibling;
					if ( nextRow && nextRow.classList.contains( 'wpc-expandable-row' ) ) {
						nextRow.classList.toggle( 'wpc-expandable-row--open' );
						toggle.textContent = nextRow.classList.contains( 'wpc-expandable-row--open' )
							? 'Hide'
							: 'Preview';
						return;
					}
				}

				// For non-table expandable content (proposals).
				var content = toggle.nextElementSibling;
				if ( content && content.classList.contains( 'wpc-expandable-row__content' ) ) {
					var isOpen          = content.style.display !== 'none';
					content.style.display = isOpen ? 'none' : 'block';
					toggle.textContent    = isOpen ? 'Show full details' : 'Hide details';
				}
			}
		);
	}

	/* =========================================================================
		13. Activity Feed Domain Filter — Dashboard (v1.2.0)
		========================================================================= */

	/**
	 * Attach click handlers to activity feed filter tabs on the dashboard.
	 * Filters `.wpc-activity-item` elements by their `data-module` attribute.
	 *
	 * @since 1.2.0
	 */
	function initActivityFilter() {
		if ( 'dashboard' !== wpClaw.page ) {
			return;
		}

		var feedTabs = document.querySelectorAll( '.wpc-activity-filter' );
		if ( ! feedTabs.length ) {
			return;
		}

		feedTabs.forEach(
			function ( tab ) {
				tab.addEventListener(
					'click',
					function ( e ) {
						e.preventDefault();
						var filter = this.getAttribute( 'data-filter' );

						// Update active tab.
						feedTabs.forEach(
							function ( t ) {
								t.classList.remove( 'wpc-nav-tabs__item--active' );
							}
						);
						this.classList.add( 'wpc-nav-tabs__item--active' );

						// Filter items.
						var items = document.querySelectorAll( '.wpc-activity-item' );
						items.forEach(
							function ( item ) {
								if ( filter === 'all' || item.getAttribute( 'data-module' ) === filter ) {
									item.style.display = '';
								} else {
									item.style.display = 'none';
								}
							}
						);
					}
				);
			}
		);
	}

	/* =========================================================================
		14. Business Profile — Save + Sync (v1.2.2)
		========================================================================= */

	/**
	 * Handle the Business Profile form submission.
	 *
	 * 1. Saves the profile to WP options via admin-ajax.php (local persistence).
	 * 2. Syncs to the Klawty instance via REST POST /profile (USER.md update).
	 * Shows a status message based on the combined outcome.
	 *
	 * @since 1.2.2
	 */
	function initBusinessProfile() {
		var form = document.getElementById( 'wpc-business-profile-form' );
		if ( ! form ) {
			return;
		}

		form.addEventListener(
			'submit',
			function ( e ) {
				e.preventDefault();

				var btn       = document.getElementById( 'wpc-save-profile' );
				var statusEl  = document.getElementById( 'wpc-profile-status' );
				var restore   = setButtonLoading( btn );

				// Collect field values.
				var fields = [ 'business_name', 'industry', 'description', 'owner_role', 'top_goal', 'never_do', 'extra_context' ];
				var data   = {};
				fields.forEach(
					function ( f ) {
						var el = form.elements[ f ];
						data[ f ] = el ? el.value : '';
					}
				);

				// Retrieve the nonce from the hidden field WordPress injected.
				var nonceEl = form.querySelector( '[name="wp_claw_profile_nonce"]' );
				var nonce   = nonceEl ? nonceEl.value : '';

				// Build form body for admin-ajax.php (URLSearchParams for WP AJAX).
				var params = new URLSearchParams();
				params.append( 'action', 'wp_claw_save_profile' );
				params.append( '_wpnonce', nonce );
				fields.forEach(
					function ( f ) {
						params.append( f, data[ f ] );
					}
				);

				// --- Local save (admin-ajax.php) ---
				var localSave = fetch(
					wpClaw.ajaxUrl,
					{
						method: 'POST',
						credentials: 'same-origin',
						headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
						body: params.toString(),
					}
				).then(
					function ( res ) {
						return res.json();
					}
				).then(
					function ( body ) {
						if ( ! body.success ) {
							throw new Error( 'Local save failed' );
						}
					}
				);

				// --- Klawty sync (REST POST /profile) ---
				var syncSave = fetch(
					wpClaw.restUrl + 'profile',
					buildFetchOptions( 'POST', data )
				).then(
					function ( res ) {
						if ( ! res.ok ) {
							throw new Error( 'Sync failed (' + res.status + ')' );
						}
						return res.json();
					}
				);

				// Resolve both and surface combined status.
				Promise.allSettled( [ localSave, syncSave ] ).then(
					function ( results ) {
						restore();
						var localOk = results[ 0 ].status === 'fulfilled';
						var syncOk  = results[ 1 ].status === 'fulfilled';

						statusEl.textContent = '';

						if ( localOk && syncOk ) {
							statusEl.textContent = 'Saved and synced.';
							statusEl.style.color = '#3a8a3a';
						} else if ( localOk ) {
							statusEl.textContent = 'Saved locally, sync failed.';
							statusEl.style.color = '#b45309';
						} else {
							statusEl.textContent = 'Save failed. Please try again.';
							statusEl.style.color = '#b91c1c';
						}

						// Clear status message after 5 seconds.
						setTimeout(
							function () {
								statusEl.textContent = '';
								statusEl.style.color = '';
							},
							5000
						);
					}
				);
			}
		);
	}

	/* =========================================================================
		15. Live Activity Feed — Dashboard (v1.2.3)
		========================================================================= */

	/**
	 * Format an ISO timestamp as a human-readable "X ago" string.
	 * Local copy for use within the main IIFE — mirrors the one in the Reports module.
	 *
	 * @param {string} iso  ISO 8601 timestamp string.
	 * @return {string}
	 * @since 1.2.3
	 */
	function timeAgoLocal( iso ) {
		var diff = Math.floor( ( Date.now() - new Date( iso ).getTime() ) / 1000 );
		if ( diff < 60 ) {
			return diff + 's ago';
		}
		if ( diff < 3600 ) {
			return Math.floor( diff / 60 ) + 'm ago';
		}
		if ( diff < 86400 ) {
			return Math.floor( diff / 3600 ) + 'h ago';
		}
		return Math.floor( diff / 86400 ) + 'd ago';
	}

	/**
	 * Fetch recent agent activity from the REST API and render it into the feed container.
	 *
	 * @since 1.2.3
	 */
	function loadActivity() {
		var feed = document.getElementById( 'wpc-activity-feed' );
		if ( ! feed ) {
			return;
		}
		fetch( wpClaw.restUrl + 'activity?since=6h&limit=30', buildFetchOptions( 'GET' ) )
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				if ( ! data.activity || ! data.activity.length ) {
					while ( feed.firstChild ) { feed.removeChild( feed.firstChild ); }
					var empty = document.createElement( 'p' );
					empty.className = 'wpc-empty-state';
					empty.textContent = 'No recent activity. Agents will start working within 15 minutes.';
					feed.appendChild( empty );
					return;
				}
				while ( feed.firstChild ) { feed.removeChild( feed.firstChild ); }
				data.activity.forEach( function ( item ) {
					var div = document.createElement( 'div' );
					div.style.cssText = 'display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--wpc-border,#e5e7eb);';

					var left = document.createElement( 'div' );
					var badge = document.createElement( 'span' );
					badge.className = 'wpc-badge wpc-badge--' + ( item.type === 'task_completed' ? 'done' : item.type === 'task_failed' ? 'failed' : 'active' );
					badge.textContent = ( item.agent_emoji || '' ) + ' ' + ( item.agent_name || item.agent || '' );
					left.appendChild( badge );

					var title = document.createElement( 'span' );
					title.style.marginLeft = '8px';
					title.textContent = item.title || '';
					left.appendChild( title );
					div.appendChild( left );

					var time = document.createElement( 'span' );
					time.className = 'wpc-kpi-label';
					time.textContent = timeAgoLocal( item.timestamp );
					div.appendChild( time );

					feed.appendChild( div );
				} );
			} )
			.catch( function () {} );
	}

	/**
	 * Initialise the live activity feed on the dashboard page.
	 * Loads immediately and refreshes every 30 seconds.
	 *
	 * @since 1.2.3
	 */
	function initActivityFeed() {
		if ( 'dashboard' !== wpClaw.page ) {
			return;
		}
		loadActivity();
		setInterval( loadActivity, 30000 );
	}

	/* =========================================================================
		16. Module Agent Reports — Security / SEO / Commerce (v1.2.3)
		========================================================================= */

	/**
	 * Fetch and render agent reports for a module page.
	 * Reads `data-agent` and `data-limit` from the `#wpc-module-reports` element.
	 *
	 * @since 1.2.3
	 */
	function initModuleReports() {
		var container = document.getElementById( 'wpc-module-reports' );
		if ( ! container ) {
			return;
		}
		var agent = container.getAttribute( 'data-agent' );
		var limit = container.getAttribute( 'data-limit' ) || '5';
		fetch(
			wpClaw.restUrl + 'reports?agent=' + encodeURIComponent( agent ) + '&limit=' + limit + '&since=30d',
			buildFetchOptions( 'GET' )
		)
			.then( function ( r ) { return r.json(); } )
			.then( function ( data ) {
				if ( ! data.reports || ! data.reports.length ) {
					while ( container.firstChild ) { container.removeChild( container.firstChild ); }
					var empty = document.createElement( 'p' );
					empty.className = 'wpc-empty-state';
					empty.textContent = 'No reports yet. Agent will produce first report within 15 minutes.';
					container.appendChild( empty );
					return;
				}
				while ( container.firstChild ) { container.removeChild( container.firstChild ); }
				data.reports.forEach( function ( r ) {
					var card = document.createElement( 'div' );
					card.style.cssText = 'padding:12px 0;border-bottom:1px solid var(--wpc-border,#e5e7eb);';

					var header = document.createElement( 'div' );
					header.style.cssText = 'display:flex;justify-content:space-between;align-items:center;';
					var titleEl = document.createElement( 'strong' );
					titleEl.textContent = r.title || '';
					header.appendChild( titleEl );
					var timeEl = document.createElement( 'span' );
					timeEl.className = 'wpc-kpi-label';
					timeEl.textContent = timeAgoLocal( r.completed_at );
					header.appendChild( timeEl );
					card.appendChild( header );

					if ( r.evidence ) {
						var pre = document.createElement( 'pre' );
						pre.style.cssText = 'margin:4px 0 0;font-size:0.8125rem;color:var(--wpc-muted,#9ca3af);white-space:pre-wrap;word-break:break-word;max-height:100px;overflow:hidden;';
						var lines = r.evidence.split( '\n' ).filter( function ( l ) { return l.trim() && ! l.trim().match( /^#+\s/ ); } );
						pre.textContent = lines.slice( 0, 4 ).join( '\n' );
						card.appendChild( pre );
					}

					container.appendChild( card );
				} );
			} )
			.catch( function () {
				while ( container.firstChild ) { container.removeChild( container.firstChild ); }
				var err = document.createElement( 'p' );
				err.className = 'wpc-empty-state';
				err.textContent = 'Could not load reports.';
				container.appendChild( err );
			} );
	}

	/* =========================================================================
		Boot — initialise all components on DOMContentLoaded
		========================================================================= */

	/**
	 * Main initialisation entry point.
	 *
	 * @since 1.0.0
	 * @since 1.2.0 Added email draft actions, scan trigger, resume ops,
	 *              expandable rows, and activity filter initialisation.
	 * @since 1.2.2 Added business profile form handler.
	 * @since 1.2.3 Added live activity feed and module report sections.
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

		// v1.2.0 features.
		initEmailDraftActions();
		initRunScan();
		initResumeOperations();
		initExpandableRows();
		initActivityFilter();

		// v1.2.2 features.
		initBusinessProfile();

		// v1.2.3 features.
		initActivityFeed();
		if ( 'security' === wpClaw.page || 'seo' === wpClaw.page || 'commerce' === wpClaw.page ) {
			initModuleReports();
		}
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
						wpClaw.restUrl + 'command/setup-pin',
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
				wpClaw.restUrl + 'command',
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
				wpClaw.restUrl + 'command/history?limit=20',
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

/* =========================================================================
	Reports Page
	========================================================================= */

( function () {
	'use strict';

	/**
	 * Convert an ISO timestamp string to a relative "time ago" label.
	 *
	 * Returns strings like "2m ago", "3h ago", "1d ago".
	 *
	 * @param {string} iso  ISO 8601 timestamp string.
	 * @return {string}
	 */
	function timeAgo( iso ) {
		var diff = Math.floor( ( Date.now() - new Date( iso ).getTime() ) / 1000 );
		if ( diff < 60 ) {
			return diff + 's ago';
		}
		if ( diff < 3600 ) {
			return Math.floor( diff / 60 ) + 'm ago';
		}
		if ( diff < 86400 ) {
			return Math.floor( diff / 3600 ) + 'h ago';
		}
		return Math.floor( diff / 86400 ) + 'd ago';
	}

	/**
	 * Map a priority value to a badge modifier class.
	 *
	 * @param {string} priority  'high', 'medium', or 'low'.
	 * @return {string}  Badge modifier (without the 'wpc-badge--' prefix).
	 */
	function priorityBadgeClass( priority ) {
		var map = {
			high:   'failed',
			medium: 'done',
			low:    'idle',
		};
		var key = String( priority || '' ).toLowerCase();
		return map[ key ] || 'idle';
	}

	/**
	 * Extract the first 3 lines of evidence that do not start with '#'.
	 *
	 * @param {string} evidence  Full evidence/report text.
	 * @return {string}
	 */
	function evidencePreview( evidence ) {
		var lines   = String( evidence || '' ).split( '\n' );
		var preview = [];
		for ( var i = 0; i < lines.length && preview.length < 3; i++ ) {
			var line = lines[ i ].trim();
			if ( line && line.charAt( 0 ) !== '#' ) {
				preview.push( lines[ i ] );
			}
		}
		return preview.join( '\n' );
	}

	/**
	 * Build a single report card element using safe DOM methods.
	 *
	 * @param {Object} report  Report data object from the REST API.
	 * @return {HTMLElement}
	 */
	function buildReportCard( report ) {
		var card       = document.createElement( 'div' );
		card.className = 'wpc-card';
		card.style.cssText = 'margin-bottom: 16px;';

		// ----- Header -----
		var header         = document.createElement( 'div' );
		header.style.cssText = 'display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 10px;';

		// Agent badge.
		var agentBadge       = document.createElement( 'span' );
		agentBadge.className = 'wpc-badge wpc-badge--active';
		agentBadge.textContent = String( report.agent_emoji || '' ) + ' ' + String( report.agent || '' );
		header.appendChild( agentBadge );

		// Title.
		var title       = document.createElement( 'strong' );
		title.textContent = String( report.title || '' );
		header.appendChild( title );

		// Timestamp.
		if ( report.created_at ) {
			var timeSpan       = document.createElement( 'span' );
			timeSpan.className = 'wpc-kpi-label';
			timeSpan.style.cssText = 'margin-left: auto;';
			timeSpan.textContent = timeAgo( report.created_at );
			header.appendChild( timeSpan );
		}

		// Priority badge.
		if ( report.priority ) {
			var prioBadge       = document.createElement( 'span' );
			prioBadge.className = 'wpc-badge wpc-badge--' + priorityBadgeClass( report.priority );
			prioBadge.textContent = String( report.priority );
			header.appendChild( prioBadge );
		}

		// Cost display.
		if ( report.cost !== undefined && report.cost !== null ) {
			var costSpan       = document.createElement( 'span' );
			costSpan.className = 'wpc-kpi-label';
			costSpan.textContent = '$' + Number( report.cost ).toFixed( 3 );
			header.appendChild( costSpan );
		}

		card.appendChild( header );

		// ----- Module badges -----
		var modules = Array.isArray( report.modules ) ? report.modules : [];
		if ( modules.length ) {
			var modulesRow       = document.createElement( 'div' );
			modulesRow.style.cssText = 'display: flex; gap: 6px; flex-wrap: wrap; margin-bottom: 10px;';
			modules.forEach(
				function ( mod ) {
					var badge       = document.createElement( 'span' );
					badge.className = 'wpc-badge wpc-badge--idle';
					badge.textContent = String( mod );
					modulesRow.appendChild( badge );
				}
			);
			card.appendChild( modulesRow );
		}

		// ----- Evidence preview -----
		var preview = evidencePreview( report.evidence );
		if ( preview ) {
			var pre       = document.createElement( 'pre' );
			pre.style.cssText = 'font-size: 12px; background: #f6f7f7; padding: 10px; border-radius: 4px; overflow: auto; max-height: 120px; margin: 0 0 8px;';
			pre.textContent = preview;
			card.appendChild( pre );
		}

		// ----- "Show full report" toggle -----
		var fullEvidence = String( report.evidence || '' );
		if ( fullEvidence && fullEvidence !== preview ) {
			var toggleBtn         = document.createElement( 'button' );
			toggleBtn.type        = 'button';
			toggleBtn.className   = 'wpc-expand-toggle';
			toggleBtn.textContent = 'Show full report';

			var fullPre       = document.createElement( 'pre' );
			fullPre.style.cssText = 'font-size: 12px; background: #f6f7f7; padding: 10px; border-radius: 4px; overflow: auto; max-height: 360px; margin: 8px 0 0; display: none;';
			fullPre.textContent = fullEvidence;

			toggleBtn.addEventListener(
				'click',
				( function ( pre, btn ) {
					return function () {
						var isHidden = pre.style.display === 'none';
						pre.style.display = isHidden ? 'block' : 'none';
						btn.textContent   = isHidden ? 'Hide full report' : 'Show full report';
					};
				} )( fullPre, toggleBtn )
			);

			card.appendChild( toggleBtn );
			card.appendChild( fullPre );
		}

		return card;
	}

	/**
	 * Initialise the Reports page.
	 *
	 * Only runs when wpClaw.page === 'reports'.
	 *
	 * @since 1.2.2
	 */
	function initReportsPage() {
		if ( typeof wpClaw === 'undefined' || wpClaw.page !== 'reports' ) {
			return;
		}

		var agentSelect  = document.getElementById( 'wpc-report-agent' );
		var sinceSelect  = document.getElementById( 'wpc-report-since' );
		var filterBtn    = document.getElementById( 'wpc-report-filter' );
		var reportsList  = document.getElementById( 'wpc-reports-list' );
		var countDisplay = document.getElementById( 'wpc-report-count' );

		if ( ! reportsList ) {
			return;
		}

		/**
		 * Fetch reports from the Klawty REST endpoint and render them.
		 */
		function loadReports() {
			var agent = agentSelect ? agentSelect.value : '';
			var since = sinceSelect ? sinceSelect.value : '7d';
			var url   = wpClaw.restUrl + 'reports?agent=' + encodeURIComponent( agent ) +
				'&since=' + encodeURIComponent( since ) + '&limit=20';

			// Show loading state.
			while ( reportsList.firstChild ) {
				reportsList.removeChild( reportsList.firstChild );
			}
			var loadingP       = document.createElement( 'p' );
			loadingP.className = 'wpc-empty-state';
			loadingP.style.cssText = 'text-align: center; padding: 40px;';
			loadingP.textContent = 'Loading reports\u2026';
			reportsList.appendChild( loadingP );

			if ( countDisplay ) {
				countDisplay.textContent = '';
			}

			fetch(
				url,
				{
					method: 'GET',
					headers: {
						'X-WP-Nonce': wpClaw.nonce,
					},
					credentials: 'same-origin',
				}
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
					function ( data ) {
						// Clear loading state.
						while ( reportsList.firstChild ) {
							reportsList.removeChild( reportsList.firstChild );
						}

						var reports = Array.isArray( data.reports ) ? data.reports : [];
						var total   = typeof data.total === 'number' ? data.total : reports.length;

						if ( ! reports.length ) {
							var emptyP       = document.createElement( 'p' );
							emptyP.className = 'wpc-empty-state';
							emptyP.style.cssText = 'text-align: center; padding: 40px;';
							emptyP.textContent = 'No reports found for the selected filters.';
							reportsList.appendChild( emptyP );

							if ( countDisplay ) {
								countDisplay.textContent = '';
							}
							return;
						}

						reports.forEach(
							function ( report ) {
								reportsList.appendChild( buildReportCard( report ) );
							}
						);

						if ( countDisplay ) {
							countDisplay.textContent = 'Showing ' + reports.length + ' of ' + total + ' reports';
						}
					}
				)
				.catch(
					function ( err ) {
						while ( reportsList.firstChild ) {
							reportsList.removeChild( reportsList.firstChild );
						}
						var errP       = document.createElement( 'p' );
						errP.className = 'wpc-empty-state';
						errP.style.cssText = 'text-align: center; padding: 40px; color: #d63638;';
						errP.textContent = 'Failed to load reports: ' + err.message;
						reportsList.appendChild( errP );

						if ( countDisplay ) {
							countDisplay.textContent = '';
						}
					}
				);
		}

		// Attach filter button.
		if ( filterBtn ) {
			filterBtn.addEventListener( 'click', loadReports );
		}

		// Initial load.
		loadReports();
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', initReportsPage );
	} else {
		initReportsPage();
	}
} )();

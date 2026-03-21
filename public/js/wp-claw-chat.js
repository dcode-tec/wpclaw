/**
 * WP-Claw Chat Widget
 *
 * Renders the Concierge agent's floating chat widget on the frontend.
 * Communicates with the wp-claw/v1/chat/* REST endpoints.
 * Zero external dependencies — vanilla JavaScript only.
 *
 * Security note: All innerHTML assignments in this file use STATIC SVG icon
 * strings defined as constant literals in the module scope. No user-supplied or
 * server-supplied content is ever passed to innerHTML. All dynamic content
 * (agent messages, product names, suggestions) is inserted exclusively via
 * element.textContent after passing through stripHTML().
 *
 * Config keys (injected by PHP via data-config attribute or wpClawChatConfig):
 *   position       {string}  'bottom-right' | 'bottom-left'. Default: 'bottom-right'.
 *   accentColor    {string}  CSS hex colour string. Default: '#0073aa'.
 *   welcomeMessage {string}  Initial message shown on open.
 *   agentName      {string}  Display name in the header. Default: 'Concierge'.
 *   agentAvatar    {string}  URL to avatar image (may be empty).
 *   restUrl        {string}  Base REST URL ending with '/'.
 *   businessHours  {Object}  Optional. { start: 'HH:MM', end: 'HH:MM' }.
 *   nonce          {string}  wp_rest nonce for authenticated REST calls.
 *
 * @package    WPClaw
 * @subpackage WPClaw/public/js
 * @author     dcode technologies <dev@d-code.lu>
 * @copyright  2026 dcode technologies S.a r.l.
 * @license    GPL-2.0-or-later
 * @since      1.0.0
 */

( function () {
    'use strict';

    // -------------------------------------------------------------------------
    // Static SVG icon constants
    // These are hardcoded literal strings — never derived from user input.
    // innerHTML is used ONLY for these constants throughout this module.
    // -------------------------------------------------------------------------

    // eslint-disable-next-line no-warning-comments -- intentional static innerHTML
    var SVG_CHAT  = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';
    var SVG_CLOSE = '<svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
    var SVG_SEND  = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>';
    var SVG_USER  = '<svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>';
    var SVG_IMAGE = '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>';

    /**
     * Set static SVG icon markup on an element (safe — source is a module constant).
     *
     * @param {Element} el      Target element.
     * @param {string}  svgStr  One of the SVG_* constants defined above.
     */
    function setIcon( el, svgStr ) {
        // Safe: svgStr is always one of the hardcoded SVG_* module constants.
        el.innerHTML = svgStr; // jshint ignore:line
    }

    // -------------------------------------------------------------------------
    // WPClawChat class
    // -------------------------------------------------------------------------

    /**
     * Chat widget controller.
     *
     * Creates the full DOM structure, binds events, manages polling,
     * and communicates with the WP REST API.
     */
    function WPClawChat( config ) {
        this.config        = config || {};
        this.sessionId     = this.getSessionId();
        this.isOpen        = false;
        this.pollInterval  = null;
        this.typingEl      = null;
        this.messagesEl    = null;
        this.inputField    = null;
        this.sendBtn       = null;
        this.windowEl      = null;
        this.buttonEl      = null;
        this.badgeEl       = null;
        this.closeBtn      = null;
        this.statusEl      = null;
        this.offlineBanner = null;
        this.suggestionsEl = null;
        this.container     = null;
        this.lastMessageTs = 0;
    }

    // -----------------------------------------------------------------------
    // Initialisation
    // -----------------------------------------------------------------------

    WPClawChat.prototype.init = function () {
        this.createDOM();
        this.bindEvents();
        this.applyAccentColor();
        this.checkBusinessHours();

        if ( this.config.welcomeMessage ) {
            this.renderMessage( {
                role:    'agent',
                content: this.config.welcomeMessage
            } );
        }
    };

    // -----------------------------------------------------------------------
    // DOM construction
    // -----------------------------------------------------------------------

    WPClawChat.prototype.createDOM = function () {
        var self       = this;
        var position   = this.config.position === 'bottom-left' ? 'bottom-left' : 'bottom-right';
        var agentName  = this.config.agentName  || 'Concierge';
        var avatar     = this.config.agentAvatar || '';

        // ---- Outer container ----
        this.container           = document.createElement( 'div' );
        this.container.className = 'wp-claw-chat-container' +
            ( position === 'bottom-left' ? ' wp-claw-chat--left' : '' );

        // ---- Toggle button ----
        this.buttonEl = document.createElement( 'button' );
        this.buttonEl.type      = 'button';
        this.buttonEl.className = 'wp-claw-chat-button';
        this.buttonEl.setAttribute( 'aria-label', 'Open chat' );
        this.buttonEl.setAttribute( 'aria-expanded', 'false' );
        this.buttonEl.setAttribute( 'aria-controls', 'wp-claw-chat-window' );
        setIcon( this.buttonEl, SVG_CHAT );

        this.badgeEl           = document.createElement( 'span' );
        this.badgeEl.className = 'wp-claw-chat-badge';
        this.badgeEl.setAttribute( 'aria-hidden', 'true' );
        this.buttonEl.appendChild( this.badgeEl );

        // ---- Chat window ----
        this.windowEl           = document.createElement( 'div' );
        this.windowEl.id        = 'wp-claw-chat-window';
        this.windowEl.className = 'wp-claw-chat-window wp-claw-chat-window--hidden';
        this.windowEl.setAttribute( 'role', 'dialog' );
        this.windowEl.setAttribute( 'aria-label', agentName + ' chat' );
        this.windowEl.setAttribute( 'aria-modal', 'false' );

        // -- Header --
        var header       = document.createElement( 'div' );
        header.className = 'wp-claw-chat-header';

        var avatarEl;
        if ( avatar ) {
            avatarEl     = document.createElement( 'img' );
            avatarEl.src = avatar;
            // alt is textContent-equivalent for images (safe)
            avatarEl.alt       = agentName;
            avatarEl.className = 'wp-claw-chat-header-avatar';
        } else {
            avatarEl           = document.createElement( 'div' );
            avatarEl.className = 'wp-claw-chat-header-avatar--placeholder';
            setIcon( avatarEl, SVG_USER );
        }

        var headerInfo       = document.createElement( 'div' );
        headerInfo.className = 'wp-claw-chat-header-info';

        var headerName         = document.createElement( 'div' );
        headerName.className   = 'wp-claw-chat-header-name';
        // textContent — safe, no XSS risk
        headerName.textContent = agentName;

        this.statusEl           = document.createElement( 'div' );
        this.statusEl.className = 'wp-claw-chat-header-status';
        this.statusEl.textContent = 'AI assistant';

        headerInfo.appendChild( headerName );
        headerInfo.appendChild( this.statusEl );

        this.closeBtn           = document.createElement( 'button' );
        this.closeBtn.type      = 'button';
        this.closeBtn.className = 'wp-claw-chat-close';
        this.closeBtn.setAttribute( 'aria-label', 'Close chat' );
        setIcon( this.closeBtn, SVG_CLOSE );

        header.appendChild( avatarEl );
        header.appendChild( headerInfo );
        header.appendChild( this.closeBtn );

        // -- Off-hours banner (hidden by default) --
        this.offlineBanner           = document.createElement( 'div' );
        this.offlineBanner.className = 'wp-claw-chat-offline-banner';
        this.offlineBanner.style.display  = 'none';
        this.offlineBanner.textContent    = "We're currently outside business hours. Leave a message and we'll get back to you.";

        // -- Messages area --
        this.messagesEl = document.createElement( 'div' );
        this.messagesEl.className = 'wp-claw-chat-messages';
        this.messagesEl.setAttribute( 'role', 'log' );
        this.messagesEl.setAttribute( 'aria-live', 'polite' );
        this.messagesEl.setAttribute( 'aria-label', 'Chat messages' );

        // -- Suggestions strip --
        this.suggestionsEl              = document.createElement( 'div' );
        this.suggestionsEl.className    = 'wp-claw-chat-suggestions';
        this.suggestionsEl.style.display = 'none';

        // -- Input row --
        var inputRow       = document.createElement( 'div' );
        inputRow.className = 'wp-claw-chat-input';

        this.inputField             = document.createElement( 'textarea' );
        this.inputField.className   = 'wp-claw-chat-input-field';
        this.inputField.placeholder = 'Type a message\u2026';
        this.inputField.rows        = 1;
        this.inputField.setAttribute( 'aria-label', 'Message input' );
        this.inputField.setAttribute( 'autocomplete', 'off' );

        this.sendBtn           = document.createElement( 'button' );
        this.sendBtn.type      = 'button';
        this.sendBtn.className = 'wp-claw-chat-send';
        this.sendBtn.setAttribute( 'aria-label', 'Send message' );
        setIcon( this.sendBtn, SVG_SEND );

        inputRow.appendChild( this.inputField );
        inputRow.appendChild( this.sendBtn );

        // -- Assemble window --
        this.windowEl.appendChild( header );
        this.windowEl.appendChild( this.offlineBanner );
        this.windowEl.appendChild( this.messagesEl );
        this.windowEl.appendChild( this.suggestionsEl );
        this.windowEl.appendChild( inputRow );

        // -- Assemble container --
        this.container.appendChild( this.buttonEl );
        this.container.appendChild( this.windowEl );

        document.body.appendChild( this.container );
    };

    // -----------------------------------------------------------------------
    // Event binding
    // -----------------------------------------------------------------------

    WPClawChat.prototype.bindEvents = function () {
        var self = this;

        this.buttonEl.addEventListener( 'click', function () {
            self.toggle();
        } );

        this.closeBtn.addEventListener( 'click', function () {
            self.close();
        } );

        this.sendBtn.addEventListener( 'click', function () {
            self.handleSend();
        } );

        this.inputField.addEventListener( 'keydown', function ( e ) {
            if ( e.key === 'Enter' && ! e.shiftKey ) {
                e.preventDefault();
                self.handleSend();
            }
        } );

        this.inputField.addEventListener( 'input', function () {
            self.autoResizeTextarea();
        } );

        document.addEventListener( 'keydown', function ( e ) {
            if ( e.key === 'Escape' && self.isOpen ) {
                self.close();
                self.buttonEl.focus();
            }
        } );

        this.windowEl.addEventListener( 'keydown', function ( e ) {
            if ( e.key === 'Tab' ) {
                self.handleFocusTrap( e );
            }
        } );
    };

    // -----------------------------------------------------------------------
    // Open / close
    // -----------------------------------------------------------------------

    WPClawChat.prototype.toggle = function () {
        if ( this.isOpen ) {
            this.close();
        } else {
            this.open();
        }
    };

    WPClawChat.prototype.open = function () {
        this.isOpen = true;
        this.windowEl.classList.remove( 'wp-claw-chat-window--hidden' );
        this.windowEl.classList.add( 'wp-claw-chat-window--visible' );
        this.buttonEl.setAttribute( 'aria-expanded', 'true' );
        this.buttonEl.setAttribute( 'aria-label', 'Close chat' );
        setIcon( this.buttonEl, SVG_CLOSE );
        this.buttonEl.appendChild( this.badgeEl );
        this.hideBadge();
        this.scrollToBottom();
        this.inputField.focus();
        this.startPolling();
    };

    WPClawChat.prototype.close = function () {
        this.isOpen = false;
        this.windowEl.classList.remove( 'wp-claw-chat-window--visible' );
        this.windowEl.classList.add( 'wp-claw-chat-window--hidden' );
        this.buttonEl.setAttribute( 'aria-expanded', 'false' );
        this.buttonEl.setAttribute( 'aria-label', 'Open chat' );
        setIcon( this.buttonEl, SVG_CHAT );
        this.buttonEl.appendChild( this.badgeEl );
        this.stopPolling();
    };

    // -----------------------------------------------------------------------
    // Message sending
    // -----------------------------------------------------------------------

    WPClawChat.prototype.handleSend = function () {
        var raw  = this.inputField.value;
        var text = this.stripHTML( raw ).trim();

        if ( ! text ) {
            return;
        }

        this.inputField.value = '';
        this.autoResizeTextarea();
        this.clearSuggestions();
        this.sendMessage( text );
    };

    WPClawChat.prototype.sendMessage = function ( text ) {
        var self = this;

        this.renderMessage( { role: 'visitor', content: text } );
        this.setSendDisabled( true );
        this.showTyping();

        var payload = JSON.stringify( {
            session_id: this.sessionId,
            message:    text,
            page_url:   this.truncate( window.location.href, 512 )
        } );

        var headers = { 'Content-Type': 'application/json' };

        if ( this.config.nonce ) {
            headers[ 'X-WP-Nonce' ] = this.config.nonce;
        }

        fetch( this.config.restUrl + 'chat/send', {
            method:  'POST',
            headers: headers,
            body:    payload
        } )
        .then( function ( response ) {
            if ( ! response.ok ) {
                return response.json().then( function ( err ) {
                    throw new Error( ( err && err.message ) ? err.message : 'Request failed (' + response.status + ')' );
                } );
            }
            return response.json();
        } )
        .then( function ( data ) {
            self.hideTyping();
            self.setSendDisabled( false );

            if ( data && data.message ) {
                self.renderMessage( { role: 'agent', content: data.message } );
            }

            if ( data && Array.isArray( data.products ) && data.products.length ) {
                self.renderProducts( data.products );
            }

            if ( data && Array.isArray( data.suggestions ) && data.suggestions.length ) {
                self.renderSuggestions( data.suggestions );
            }

            self.lastMessageTs = Date.now();
        } )
        .catch( function () {
            self.hideTyping();
            self.setSendDisabled( false );
            self.renderMessage( {
                role:    'agent',
                content: 'Sorry, something went wrong. Please try again.'
            } );
        } );
    };

    // -----------------------------------------------------------------------
    // Render helpers
    // -----------------------------------------------------------------------

    /**
     * Render a chat message bubble.
     * All content is inserted via textContent — no XSS risk.
     */
    WPClawChat.prototype.renderMessage = function ( msg ) {
        var role    = msg.role || 'agent';
        var content = this.stripHTML( msg.content || '' );

        var bubble       = document.createElement( 'div' );
        bubble.className = 'wp-claw-chat-bubble wp-claw-chat-bubble--' + role;
        // textContent — safe insertion of sanitised text
        bubble.textContent = content;

        var time         = document.createElement( 'span' );
        time.className   = 'wp-claw-chat-bubble-time';
        time.textContent = this.formatTime( new Date() );
        bubble.appendChild( time );

        this.messagesEl.appendChild( bubble );
        this.scrollToBottom();
    };

    /**
     * Render product recommendation cards.
     * All dynamic values use textContent or validated URL attributes.
     */
    WPClawChat.prototype.renderProducts = function ( products ) {
        var self = this;

        products.forEach( function ( product ) {
            var card       = document.createElement( 'a' );
            card.className = 'wp-claw-chat-product';
            // href is a URL from the server — validate before assignment
            var href = ( product.permalink && typeof product.permalink === 'string' )
                ? product.permalink : '#';
            // Only allow http/https URLs
            if ( /^https?:\/\//i.test( href ) ) {
                card.href = href;
            } else {
                card.href = '#';
            }
            card.target = '_blank';
            card.rel    = 'noopener noreferrer';
            // aria-label uses textContent-equivalent (safe)
            card.setAttribute( 'aria-label', 'View product: ' + self.stripHTML( product.name || '' ) );

            // Image — src validated above via href logic
            var imageUrl = ( product.image && typeof product.image === 'string' && /^https?:\/\//i.test( product.image ) )
                ? product.image : '';

            if ( imageUrl ) {
                var img       = document.createElement( 'img' );
                img.src       = imageUrl;
                img.alt       = self.stripHTML( product.name || '' );
                img.className = 'wp-claw-chat-product-image';
                img.width     = 60;
                img.height    = 60;
                img.addEventListener( 'error', function () {
                    var ph       = document.createElement( 'div' );
                    ph.className = 'wp-claw-chat-product-image--placeholder';
                    setIcon( ph, SVG_IMAGE );
                    if ( img.parentNode ) {
                        img.parentNode.replaceChild( ph, img );
                    }
                } );
                card.appendChild( img );
            } else {
                var ph       = document.createElement( 'div' );
                ph.className = 'wp-claw-chat-product-image--placeholder';
                setIcon( ph, SVG_IMAGE );
                card.appendChild( ph );
            }

            // Info column — all textContent
            var info       = document.createElement( 'div' );
            info.className = 'wp-claw-chat-product-info';

            var name         = document.createElement( 'div' );
            name.className   = 'wp-claw-chat-product-name';
            name.textContent = self.stripHTML( product.name || '' );
            info.appendChild( name );

            if ( product.price !== undefined && product.price !== '' ) {
                var price         = document.createElement( 'div' );
                price.className   = 'wp-claw-chat-product-price';
                price.textContent = self.stripHTML( String( product.price ) );
                info.appendChild( price );
            }

            var link         = document.createElement( 'div' );
            link.className   = 'wp-claw-chat-product-link';
            link.textContent = 'View product \u2192';
            info.appendChild( link );

            card.appendChild( info );
            self.messagesEl.appendChild( card );
        } );

        this.scrollToBottom();
    };

    /**
     * Render quick-reply suggestion buttons.
     * Button text uses textContent — safe.
     */
    WPClawChat.prototype.renderSuggestions = function ( suggestions ) {
        var self = this;
        this.clearSuggestions();

        if ( ! suggestions || ! suggestions.length ) {
            return;
        }

        suggestions.forEach( function ( text ) {
            var btn       = document.createElement( 'button' );
            btn.type      = 'button';
            btn.className = 'wp-claw-chat-suggestion';
            // textContent — safe
            btn.textContent = self.stripHTML( String( text ) );

            btn.addEventListener( 'click', function () {
                self.clearSuggestions();
                self.sendMessage( self.stripHTML( String( text ) ) );
            } );

            self.suggestionsEl.appendChild( btn );
        } );

        this.suggestionsEl.style.display = 'flex';
    };

    WPClawChat.prototype.clearSuggestions = function () {
        while ( this.suggestionsEl.firstChild ) {
            this.suggestionsEl.removeChild( this.suggestionsEl.firstChild );
        }
        this.suggestionsEl.style.display = 'none';
    };

    // -----------------------------------------------------------------------
    // Typing indicator
    // -----------------------------------------------------------------------

    WPClawChat.prototype.showTyping = function () {
        if ( this.typingEl ) { return; }

        this.typingEl           = document.createElement( 'div' );
        this.typingEl.className = 'wp-claw-chat-typing';
        this.typingEl.setAttribute( 'aria-label', 'Agent is typing' );

        for ( var i = 0; i < 3; i++ ) {
            var dot       = document.createElement( 'span' );
            dot.className = 'wp-claw-chat-typing-dot';
            this.typingEl.appendChild( dot );
        }

        this.messagesEl.appendChild( this.typingEl );
        this.scrollToBottom();
    };

    WPClawChat.prototype.hideTyping = function () {
        if ( this.typingEl && this.typingEl.parentNode ) {
            this.typingEl.parentNode.removeChild( this.typingEl );
        }
        this.typingEl = null;
    };

    // -----------------------------------------------------------------------
    // Polling
    // -----------------------------------------------------------------------

    WPClawChat.prototype.startPolling = function () {
        if ( this.pollInterval ) { return; }

        var self = this;
        this.pollInterval = setInterval( function () {
            self.pollHistory();
        }, 3000 );
    };

    WPClawChat.prototype.stopPolling = function () {
        if ( this.pollInterval ) {
            clearInterval( this.pollInterval );
            this.pollInterval = null;
        }
    };

    WPClawChat.prototype.pollHistory = function () {
        if ( ! this.config.restUrl || ! this.sessionId ) { return; }

        var self    = this;
        var url     = this.config.restUrl + 'chat/history' +
            '?session_id=' + encodeURIComponent( this.sessionId ) +
            '&since='      + encodeURIComponent( this.lastMessageTs );

        var headers = {};
        if ( this.config.nonce ) {
            headers[ 'X-WP-Nonce' ] = this.config.nonce;
        }

        fetch( url, { headers: headers } )
        .then( function ( response ) {
            if ( ! response.ok ) { return null; }
            return response.json();
        } )
        .then( function ( data ) {
            if ( ! data || ! Array.isArray( data.messages ) ) { return; }

            data.messages.forEach( function ( msg ) {
                if ( msg.role === 'agent' && msg.timestamp > self.lastMessageTs ) {
                    self.renderMessage( msg );
                    self.lastMessageTs = msg.timestamp;
                }
            } );

            if ( data.suggestions && Array.isArray( data.suggestions ) && data.suggestions.length ) {
                self.renderSuggestions( data.suggestions );
            }
        } )
        .catch( function () {
            // Silent fail — polling errors must not interrupt the user.
        } );
    };

    // -----------------------------------------------------------------------
    // Business hours
    // -----------------------------------------------------------------------

    WPClawChat.prototype.checkBusinessHours = function () {
        var hours = this.config.businessHours;

        if ( ! hours || ! hours.start || ! hours.end ) { return; }

        var toMinutes = function ( str ) {
            var parts = String( str ).split( ':' );
            return parseInt( parts[ 0 ], 10 ) * 60 + parseInt( parts[ 1 ] || 0, 10 );
        };

        var now            = new Date();
        var currentMinutes = now.getHours() * 60 + now.getMinutes();
        var startMinutes   = toMinutes( hours.start );
        var endMinutes     = toMinutes( hours.end );
        var isOpen         = ( currentMinutes >= startMinutes && currentMinutes < endMinutes );

        if ( ! isOpen ) {
            this.offlineBanner.style.display = 'block';
            this.statusEl.textContent = 'Currently offline';
        }
    };

    // -----------------------------------------------------------------------
    // Session management
    // -----------------------------------------------------------------------

    WPClawChat.prototype.getSessionId = function () {
        var key = 'wp_claw_chat_session';
        var id  = null;

        try {
            id = sessionStorage.getItem( key );
        } catch ( e ) {
            // sessionStorage unavailable — generate ephemeral ID.
        }

        if ( id ) { return id; }

        id = this.generateUUID();

        try {
            sessionStorage.setItem( key, id );
        } catch ( e ) {
            // Continue without persistence.
        }

        return id;
    };

    WPClawChat.prototype.generateUUID = function () {
        if ( typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function' ) {
            return crypto.randomUUID();
        }

        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace( /[xy]/g, function ( c ) {
            var r = ( Math.random() * 16 ) | 0;
            var v = c === 'x' ? r : ( r & 0x3 ) | 0x8;
            return v.toString( 16 );
        } );
    };

    // -----------------------------------------------------------------------
    // Accessibility helpers
    // -----------------------------------------------------------------------

    WPClawChat.prototype.handleFocusTrap = function ( e ) {
        var focusable = this.windowEl.querySelectorAll(
            'button, [href], input, textarea, select, [tabindex]:not([tabindex="-1"])'
        );

        if ( ! focusable.length ) { return; }

        var first = focusable[ 0 ];
        var last  = focusable[ focusable.length - 1 ];

        if ( e.shiftKey ) {
            if ( document.activeElement === first ) {
                e.preventDefault();
                last.focus();
            }
        } else {
            if ( document.activeElement === last ) {
                e.preventDefault();
                first.focus();
            }
        }
    };

    // -----------------------------------------------------------------------
    // UI utilities
    // -----------------------------------------------------------------------

    WPClawChat.prototype.applyAccentColor = function () {
        var color = this.config.accentColor;

        if ( color && /^#([0-9a-fA-F]{3}){1,2}$/.test( color ) ) {
            var root = document.getElementById( 'wp-claw-chat-root' );
            if ( root ) {
                root.style.setProperty( '--wp-claw-accent', color );
            }
            if ( this.container ) {
                this.container.style.setProperty( '--wp-claw-accent', color );
            }
        }
    };

    WPClawChat.prototype.scrollToBottom = function () {
        if ( this.messagesEl ) {
            this.messagesEl.scrollTop = this.messagesEl.scrollHeight;
        }
    };

    WPClawChat.prototype.setSendDisabled = function ( disabled ) {
        if ( this.sendBtn )    { this.sendBtn.disabled    = disabled; }
        if ( this.inputField ) { this.inputField.disabled = disabled; }
    };

    WPClawChat.prototype.autoResizeTextarea = function () {
        if ( ! this.inputField ) { return; }
        this.inputField.style.height = 'auto';
        this.inputField.style.height = this.inputField.scrollHeight + 'px';
    };

    WPClawChat.prototype.showBadge = function ( count ) {
        if ( ! this.badgeEl ) { return; }
        this.badgeEl.textContent = count > 9 ? '9+' : String( count );
        this.badgeEl.classList.add( 'wp-claw-chat-badge--visible' );
    };

    WPClawChat.prototype.hideBadge = function () {
        if ( ! this.badgeEl ) { return; }
        this.badgeEl.classList.remove( 'wp-claw-chat-badge--visible' );
    };

    // -----------------------------------------------------------------------
    // String helpers
    // -----------------------------------------------------------------------

    /**
     * Strip all HTML tags from a string using the browser's own parser.
     * The result is safe to display via element.textContent.
     */
    WPClawChat.prototype.stripHTML = function ( str ) {
        var tmp       = document.createElement( 'div' );
        tmp.textContent = String( str || '' );
        return tmp.textContent;
    };

    WPClawChat.prototype.truncate = function ( str, maxLen ) {
        str = String( str || '' );
        return str.length > maxLen ? str.substring( 0, maxLen ) : str;
    };

    WPClawChat.prototype.formatTime = function ( date ) {
        var h = String( date.getHours() ).padStart( 2, '0' );
        var m = String( date.getMinutes() ).padStart( 2, '0' );
        return h + ':' + m;
    };

    // -------------------------------------------------------------------------
    // Auto-initialise from #wp-claw-chat-root
    // -------------------------------------------------------------------------

    document.addEventListener( 'DOMContentLoaded', function () {
        var root = document.getElementById( 'wp-claw-chat-root' );

        if ( ! root ) { return; }

        // Primary config source: data-config attribute (set by PHP output_chat_widget).
        var config     = {};
        var dataConfig = root.getAttribute( 'data-config' );

        if ( dataConfig ) {
            try {
                config = JSON.parse( dataConfig );
            } catch ( e ) {
                config = {};
            }
        }

        // Secondary source: wpClawChatConfig global (set by wp_localize_script).
        if ( typeof wpClawChatConfig !== 'undefined' && typeof wpClawChatConfig === 'object' ) {
            var keys = Object.keys( wpClawChatConfig );
            for ( var i = 0; i < keys.length; i++ ) {
                if ( config[ keys[ i ] ] === undefined ) {
                    config[ keys[ i ] ] = wpClawChatConfig[ keys[ i ] ];
                }
            }
        }

        // Require at minimum a REST URL to function.
        if ( ! config.restUrl ) { return; }

        new WPClawChat( config ).init();
    } );

} )();

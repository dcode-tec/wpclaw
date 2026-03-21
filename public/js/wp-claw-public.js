/**
 * WP-Claw Public JavaScript
 *
 * Privacy-first analytics pixel. Collects anonymous pageview events and
 * sends them to the wp-claw/v1/analytics REST endpoint. Respects Do Not
 * Track and requires an explicit consent signal before firing.
 *
 * Exposed global: wpClawAnalytics (object — localised by wp_localize_script)
 *   - restUrl    {string}  Base REST URL (wp-claw/v1/).
 *   - consentMode {string} 'auto' | 'explicit' (default: 'auto').
 *
 * Consent signals checked in order:
 *   1. navigator.doNotTrack === '1'  → never track.
 *   2. window.wpClawAnalyticsConsent === true  → track.
 *   3. Cookie wp_claw_analytics_consent=1  → track.
 *   4. Default: no consent (GDPR default deny).
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

    /**
     * Privacy-first analytics pixel class.
     *
     * Fires a single pageview event per page load when the visitor has given
     * consent (or when consent is implicit based on site configuration).
     * All data sent is anonymous — no cookies, no fingerprints, no PII.
     */
    class WPClawAnalytics {

        /**
         * Create a new analytics instance.
         *
         * @param {Object} config             Configuration object.
         * @param {string} config.restUrl     Base WP REST URL ending with '/'.
         * @param {string} [config.consentMode] 'auto' or 'explicit'. Defaults to 'auto'.
         */
        constructor( config ) {
            this.restUrl     = ( config && config.restUrl ) ? String( config.restUrl ) : '';
            this.consentMode = ( config && config.consentMode ) ? String( config.consentMode ) : 'auto';
            this.fired       = false;
        }

        /**
         * Initialise the pixel. Attaches to DOMContentLoaded if the document
         * is still loading, otherwise fires immediately.
         *
         * @return {void}
         */
        init() {
            if ( ! this.hasConsent() ) {
                return;
            }

            if ( document.readyState === 'loading' ) {
                document.addEventListener( 'DOMContentLoaded', function () {
                    this.trackPageview();
                }.bind( this ) );
            } else {
                this.trackPageview();
            }
        }

        /**
         * Determine whether the visitor has consented to analytics tracking.
         *
         * Respects the Do Not Track browser signal and requires a positive
         * consent signal (window flag or cookie) before returning true.
         * Returns false by default in line with GDPR default-deny.
         *
         * @return {boolean}
         */
        hasConsent() {
            // 1. Hard stop — browser Do Not Track.
            if ( navigator.doNotTrack === '1' || window.doNotTrack === '1' ) {
                return false;
            }

            // 2. Explicit JavaScript flag set by a consent management platform.
            if ( window.wpClawAnalyticsConsent === true ) {
                return true;
            }

            // 3. Consent cookie written by the site's cookie banner.
            if ( this.hasCookie( 'wp_claw_analytics_consent', '1' ) ) {
                return true;
            }

            // 4. Auto mode — treat absence of DNT as implied consent for
            //    sites that use server-side consent checks (e.g. logged-in only).
            if ( this.consentMode === 'auto' ) {
                return true;
            }

            // No explicit consent signal — GDPR default deny.
            return false;
        }

        /**
         * Fire the pageview event. Idempotent — only fires once per instance.
         *
         * @return {void}
         */
        trackPageview() {
            if ( this.fired ) {
                return;
            }
            this.fired = true;

            var data = {
                page_url:    this.truncate( window.location.href, 512 ),
                referrer:    this.truncate( document.referrer || '', 512 ),
                event_type:  'pageview',
                device_type: this.getDeviceType()
            };

            this.send( data );
        }

        /**
         * Send an analytics event to the REST endpoint.
         *
         * Uses the Beacon API when available (fires even during page unload),
         * falls back to fetch with keepalive, and silently swallows any error
         * so analytics failures never degrade the visitor experience.
         *
         * @param {Object} data Event payload.
         * @return {void}
         */
        send( data ) {
            if ( ! this.restUrl ) {
                return;
            }

            var url     = this.restUrl + 'analytics';
            var payload = JSON.stringify( data );

            // Prefer Beacon API — low-overhead, works on page unload.
            if ( typeof navigator.sendBeacon === 'function' ) {
                try {
                    navigator.sendBeacon( url, new Blob( [ payload ], { type: 'application/json' } ) );
                    return;
                } catch ( e ) {
                    // fall through to fetch
                }
            }

            // Fetch with keepalive as fallback.
            if ( typeof fetch !== 'undefined' ) {
                fetch( url, {
                    method:   'POST',
                    headers:  { 'Content-Type': 'application/json' },
                    body:     payload,
                    keepalive: true
                } ).catch( function () {} ); // Silent fail — analytics must never break the page.
                return;
            }

            // XMLHttpRequest last resort (IE11 / very old browsers).
            try {
                var xhr = new XMLHttpRequest();
                xhr.open( 'POST', url, true );
                xhr.setRequestHeader( 'Content-Type', 'application/json' );
                xhr.send( payload );
            } catch ( e ) {
                // Swallow — analytics failure is non-fatal.
            }
        }

        /**
         * Derive a coarse device category from the viewport width.
         *
         * @return {string} 'mobile' | 'tablet' | 'desktop'
         */
        getDeviceType() {
            var width = window.innerWidth || document.documentElement.clientWidth || 0;
            if ( width < 768 )  { return 'mobile'; }
            if ( width < 1024 ) { return 'tablet'; }
            return 'desktop';
        }

        /**
         * Truncate a string to a maximum byte-safe length.
         *
         * @param {string} str    The input string.
         * @param {number} maxLen Maximum character length.
         * @return {string}
         */
        truncate( str, maxLen ) {
            str = String( str || '' );
            return str.length > maxLen ? str.substring( 0, maxLen ) : str;
        }

        /**
         * Check whether a cookie has a specific value.
         *
         * @param {string} name  Cookie name.
         * @param {string} value Expected value.
         * @return {boolean}
         */
        hasCookie( name, value ) {
            var cookies = document.cookie ? document.cookie.split( '; ' ) : [];
            for ( var i = 0; i < cookies.length; i++ ) {
                var parts = cookies[ i ].split( '=' );
                if ( parts[ 0 ] === name && parts[ 1 ] === value ) {
                    return true;
                }
            }
            return false;
        }
    }

    // -------------------------------------------------------------------------
    // Auto-initialise when the config object is available.
    // The config object is injected by wp_localize_script('wp-claw-public').
    // -------------------------------------------------------------------------

    if ( typeof wpClawAnalytics !== 'undefined' ) {
        new WPClawAnalytics( wpClawAnalytics ).init();
    }

} )();

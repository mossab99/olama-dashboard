/**
 * Olama Dashboard — hub.js  v2
 *
 * Action tray UX:
 *  - Desktop/tablet (≥ 600px): a single shared tray is injected as a
 *    full-width grid row-break after the selected card's row.
 *  - Mobile (< 600px): per-card inline accordion (.os-hub-mobile-actions).
 *
 * Data from JSON hydration block — no wp_localize_script.
 */
( function ( $ ) {
    'use strict';

    // ── Bootstrap ─────────────────────────────────────────────────────────────
    var dataEl = document.getElementById( 'os-hub-data' );
    if ( ! dataEl ) { return; }

    var DATA  = JSON.parse( dataEl.textContent );
    var grid  = document.getElementById( 'os-hub-grid' );
    var tray  = document.getElementById( 'os-hub-tray' );
    if ( ! grid || ! tray ) { return; }

    var MOBILE_BP   = 600;   // px — must match CSS media query
    var activeCardId = null; // currently-open module id
    var pinned       = DATA.pinned || []; // dynamic per-user quick actions list

    // ── Helpers ───────────────────────────────────────────────────────────────

    function isMobile() {
        return window.innerWidth < MOBILE_BP;
    }

    /** Return the card element for a given id */
    function getCard( id ) {
        return grid.querySelector( '[data-card-id="' + id + '"]' );
    }

    /** Return the trigger button inside a card */
    function getBtn( id ) {
        return document.getElementById( 'card-btn-' + id );
    }

    /** Check if an action ID is pinned in user's quick actions */
    function isPinned( actionId ) {
        return pinned.indexOf( actionId ) !== -1;
    }

    // ── Desktop tray: row detection ───────────────────────────────────────────

    /**
     * Find the last visible card that is in the same CSS grid row as `targetCard`.
     * Works by comparing offsetTop values — cards in the same row share the same top.
     */
    function findRowLastCard( targetCard ) {
        var cards  = Array.from( grid.querySelectorAll( '.os-hub-card:not(.os-hub-card--hidden-search)' ) );
        var rowTop = targetCard.getBoundingClientRect().top;
        var last   = targetCard;

        cards.forEach( function ( c ) {
            if ( c.classList.contains( 'os-hub-tray' ) ) { return; }
            var top = c.getBoundingClientRect().top;
            // Same row = within 4px (handles sub-pixel rendering)
            if ( Math.abs( top - rowTop ) < 4 ) {
                last = c;
            }
        } );

        return last;
    }

    /**
     * Build the tray's inner content from the cards data for the given module id.
     */
    function buildTrayContent( id ) {
        var data = DATA.cards[ id ];
        if ( ! data ) { return; }

        // Header: icon, label, "Actions" subtitle
        tray.style.setProperty( '--hub-accent',       data.accent );
        tray.style.setProperty( '--hub-accent-light', 'rgba(' + data.accentRgb + ',.10)' );
        tray.style.setProperty( '--hub-accent-rgb',   data.accentRgb );

        var iconEl  = tray.querySelector( '.os-hub-tray-icon' );
        var labelEl = tray.querySelector( '.os-hub-tray-label' );
        if ( iconEl )  { iconEl.className  = 'os-hub-tray-icon dashicons ' + data.icon; }
        if ( labelEl ) { labelEl.textContent = data.label; }

        // Open-module button href
        var openBtn = document.getElementById( 'os-hub-tray-open-btn' );
        if ( openBtn ) { openBtn.href = data.primaryUrl; }

        // Action links
        var linksEl = document.getElementById( 'os-hub-tray-links' );
        if ( ! linksEl ) { return; }
        linksEl.innerHTML = '';

        data.submenus.forEach( function ( link ) {
            var wrapper = document.createElement( 'div' );
            wrapper.className = 'os-hub-tray-link-wrapper';

            var a = document.createElement( 'a' );
            a.className = 'os-hub-tray-link';
            a.href      = link.url;

            var icon = document.createElement( 'span' );
            icon.className   = 'dashicons ' + link.icon;
            icon.setAttribute( 'aria-hidden', 'true' );

            var text = document.createElement( 'span' );
            text.className   = 'os-hub-tray-link-text';
            text.textContent = link.label;

            a.appendChild( icon );
            a.appendChild( text );

            var pinBtn = document.createElement( 'button' );
            var currentlyPinned = isPinned( link.id );
            pinBtn.className = 'os-hub-pin-toggle' + ( currentlyPinned ? ' os-hub-pin-toggle--pinned' : '' );
            pinBtn.setAttribute( 'data-action-id', link.id );
            pinBtn.setAttribute( 'type', 'button' );
            pinBtn.setAttribute( 'aria-label', currentlyPinned ? 'Unpin action' : 'Pin action' );
            pinBtn.title = currentlyPinned ? 'Unpin action' : 'Pin action';

            var pinIcon = document.createElement( 'span' );
            pinIcon.className = 'dashicons ' + ( currentlyPinned ? 'dashicons-star-filled' : 'dashicons-star-empty' );
            pinBtn.appendChild( pinIcon );

            wrapper.appendChild( a );
            wrapper.appendChild( pinBtn );
            linksEl.appendChild( wrapper );
        } );
    }

    /**
     * Sends AJAX request to pin/unpin action. Updates tray and pin indicators.
     */
    function togglePin( actionId, pinBtn ) {
        if ( ! actionId ) { return; }

        if ( pinBtn ) {
            pinBtn.disabled = true;
            pinBtn.style.opacity = '0.5';
        }

        var quickActionsTray = document.getElementById( 'os-hub-quick-actions-list' );
        if ( quickActionsTray ) {
            quickActionsTray.classList.add( 'os-hub-loading' );
        }

        $.ajax( {
            url: DATA.ajaxUrl,
            type: 'POST',
            dataType: 'json',
            data: {
                action: 'olama_dashboard_toggle_quick_action',
                action_id: actionId,
                nonce: DATA.nonce
            },
            success: function ( response ) {
                if ( response.success ) {
                    pinned = response.data.pinned;
                    
                    if ( quickActionsTray ) {
                        quickActionsTray.innerHTML = response.data.html;
                    }

                    // Sync state of all pin toggle buttons for this action ID
                    var buttons = document.querySelectorAll( '.os-hub-pin-toggle[data-action-id="' + actionId + '"]' );
                    buttons.forEach( function ( btn ) {
                        var nowPinned = isPinned( actionId );
                        btn.classList.toggle( 'os-hub-pin-toggle--pinned', nowPinned );
                        btn.setAttribute( 'aria-label', nowPinned ? 'Unpin action' : 'Pin action' );
                        btn.title = nowPinned ? 'Unpin action' : 'Pin action';
                        var star = btn.querySelector( '.dashicons' );
                        if ( star ) {
                            star.className = 'dashicons ' + ( nowPinned ? 'dashicons-star-filled' : 'dashicons-star-empty' );
                        }
                    } );
                } else {
                    console.error( 'AJAX toggle failed:', response.data.message );
                }
            },
            error: function ( xhr, status, error ) {
                console.error( 'AJAX Error:', error );
            },
            complete: function () {
                if ( pinBtn ) {
                    pinBtn.disabled = false;
                    pinBtn.style.opacity = '';
                }
                if ( quickActionsTray ) {
                    quickActionsTray.classList.remove( 'os-hub-loading' );
                }
            }
        } );
    }

    /**
     * Insert the tray into the DOM immediately after the last card in the
     * same row as `targetCard`.  Uses CSS `grid-column: 1 / -1` so it
     * naturally spans the full row width.
     */
    function placeTrayAfterRow( targetCard ) {
        var last = findRowLastCard( targetCard );
        // Insert tray after `last` element
        if ( last.nextSibling !== tray ) {
            last.parentNode.insertBefore( tray, last.nextSibling );
        }
    }

    // ── Open / close (desktop tray) ───────────────────────────────────────────

    function openTray( id ) {
        var card = getCard( id );
        var btn  = getBtn( id );
        if ( ! card || ! btn ) { return; }

        buildTrayContent( id );
        placeTrayAfterRow( card );

        tray.removeAttribute( 'hidden' );
        card.classList.add( 'os-hub-card--selected' );
        btn.setAttribute( 'aria-expanded', 'true' );

        activeCardId = id;

        // Smooth-scroll tray into view
        setTimeout( function () {
            tray.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
        }, 50 );
    }

    function closeTray() {
        if ( ! activeCardId ) { return; }

        var card = getCard( activeCardId );
        var btn  = getBtn( activeCardId );

        tray.setAttribute( 'hidden', '' );
        if ( card ) { card.classList.remove( 'os-hub-card--selected' ); }
        if ( btn )  { btn.setAttribute( 'aria-expanded', 'false' ); }

        activeCardId = null;
    }

    function toggleTray( id ) {
        if ( activeCardId === id ) {
            closeTray();
        } else {
            if ( activeCardId ) {
                // Deselect the old card first
                var oldCard = getCard( activeCardId );
                var oldBtn  = getBtn( activeCardId );
                if ( oldCard ) { oldCard.classList.remove( 'os-hub-card--selected' ); }
                if ( oldBtn )  { oldBtn.setAttribute( 'aria-expanded', 'false' ); }
            }
            openTray( id );
        }
    }

    // ── Open / close (mobile inline accordion) ────────────────────────────────

    function openMobile( id ) {
        var mob = document.getElementById( 'mob-' + id );
        var btn = getBtn( id );
        var card = getCard( id );
        if ( !mob || !btn || !card ) { return; }
        mob.removeAttribute( 'hidden' );
        btn.setAttribute( 'aria-expanded', 'true' );
        card.classList.add( 'os-hub-card--selected' );
        activeCardId = id;
    }

    function closeMobile( id ) {
        var mob  = document.getElementById( 'mob-' + id );
        var btn  = getBtn( id );
        var card = getCard( id );
        if ( mob )  { mob.setAttribute( 'hidden', '' ); }
        if ( btn )  { btn.setAttribute( 'aria-expanded', 'false' ); }
        if ( card ) { card.classList.remove( 'os-hub-card--selected' ); }
        if ( activeCardId === id ) { activeCardId = null; }
    }

    function closeAllMobile() {
        grid.querySelectorAll( '.os-hub-mobile-actions:not([hidden])' ).forEach( function ( mob ) {
            var id = mob.id.replace( 'mob-', '' );
            closeMobile( id );
        } );
    }

    function toggleMobile( id ) {
        if ( activeCardId === id ) {
            closeMobile( id );
        } else {
            closeAllMobile();
            openMobile( id );
        }
    }

    // ── Unified toggle dispatcher ─────────────────────────────────────────────

    function toggleModule( id ) {
        if ( isMobile() ) {
            // Hide any open desktop tray silently
            if ( !tray.hasAttribute( 'hidden' ) ) {
                tray.setAttribute( 'hidden', '' );
            }
            toggleMobile( id );
        } else {
            // Hide any open mobile accordion silently
            closeAllMobile();
            toggleTray( id );
        }
    }

    // ── Click delegation ──────────────────────────────────────────────────────
 
    grid.addEventListener( 'click', function ( e ) {
        // Star pin toggle clicks (mobile inline accordion or card elements)
        var pinToggle = e.target.closest( '.os-hub-pin-toggle' );
        if ( pinToggle ) {
            e.preventDefault();
            e.stopPropagation();
            togglePin( pinToggle.getAttribute( 'data-action-id' ), pinToggle );
            return;
        }

        var btn = e.target.closest( '.os-hub-card-header' );
        if ( ! btn ) { return; }
        if ( btn.disabled || btn.getAttribute( 'aria-disabled' ) === 'true' ) { return; }
 
        var card = btn.closest( '.os-hub-card[data-card-id]' );
        if ( ! card ) { return; }
 
        toggleModule( card.getAttribute( 'data-card-id' ) );
    } );
 
    // Desktop action tray pin toggles
    tray.addEventListener( 'click', function ( e ) {
        var pinToggle = e.target.closest( '.os-hub-pin-toggle' );
        if ( pinToggle ) {
            e.preventDefault();
            e.stopPropagation();
            togglePin( pinToggle.getAttribute( 'data-action-id' ), pinToggle );
        }
    } );

    // Quick Actions list unpin buttons
    var quickActionsList = document.getElementById( 'os-hub-quick-actions-list' );
    if ( quickActionsList ) {
        quickActionsList.addEventListener( 'click', function ( e ) {
            var unpinBtn = e.target.closest( '.os-hub-quick-action-unpin' );
            if ( unpinBtn ) {
                e.preventDefault();
                e.stopPropagation();
                togglePin( unpinBtn.getAttribute( 'data-action-id' ), unpinBtn );
            }
        } );
    }

    // Tray close button
    var closeBtn = document.getElementById( 'os-hub-tray-close' );
    if ( closeBtn ) {
        closeBtn.addEventListener( 'click', function () {
            var id = activeCardId;
            closeTray();
            // Return focus to the card button
            if ( id ) {
                var btn = getBtn( id );
                if ( btn ) { btn.focus(); }
            }
        } );
    }

    // ── Keyboard ──────────────────────────────────────────────────────────────

    document.addEventListener( 'keydown', function ( e ) {
        if ( e.key !== 'Escape' ) { return; }

        var id = activeCardId;
        if ( isMobile() ) {
            closeAllMobile();
        } else {
            closeTray();
        }
        // Return focus
        if ( id ) {
            var btn = getBtn( id );
            if ( btn ) { btn.focus(); }
        }
    } );

    // Arrow key navigation between card buttons
    grid.addEventListener( 'keydown', function ( e ) {
        var isArrow = [ 'ArrowRight', 'ArrowDown', 'ArrowLeft', 'ArrowUp' ].indexOf( e.key ) !== -1;
        if ( ! isArrow ) { return; }

        var focusable = Array.from( grid.querySelectorAll( '.os-hub-card-header:not([disabled])' ) );
        var idx = focusable.indexOf( document.activeElement );
        if ( idx === -1 ) { return; }

        e.preventDefault();

        var next;
        if ( e.key === 'ArrowRight' || e.key === 'ArrowDown' ) {
            next = focusable[ ( idx + 1 ) % focusable.length ];
        } else {
            next = focusable[ ( idx - 1 + focusable.length ) % focusable.length ];
        }
        next.focus();
    } );

    // ── Search / filter ───────────────────────────────────────────────────────

    var searchEl = document.getElementById( 'os-hub-search' );
    if ( searchEl ) {
        searchEl.addEventListener( 'input', function () {
            var q = this.value.toLowerCase().trim();

            grid.querySelectorAll( '.os-hub-card[data-card-id]' ).forEach( function ( card ) {
                var text    = card.getAttribute( 'data-search-text' ) || card.textContent.toLowerCase();
                var matches = q === '' || text.indexOf( q ) !== -1;
                card.classList.toggle( 'os-hub-card--hidden-search', ! matches );
            } );

            // If the selected card is now hidden, close the tray/accordion
            if ( activeCardId ) {
                var selectedCard = getCard( activeCardId );
                if ( selectedCard && selectedCard.classList.contains( 'os-hub-card--hidden-search' ) ) {
                    if ( isMobile() ) { closeMobile( activeCardId ); }
                    else              { closeTray(); }
                }
            }
        } );

        searchEl.addEventListener( 'keydown', function ( e ) {
            if ( e.key === 'Escape' && this.value ) {
                e.stopPropagation();
                this.value = '';
                this.dispatchEvent( new Event( 'input' ) );
            }
        } );
    }

    // ── Resize: switch interaction mode when crossing the breakpoint ───────────

    var prevMobile = isMobile();
    window.addEventListener( 'resize', function () {
        var nowMobile = isMobile();
        if ( nowMobile === prevMobile ) { return; }
        prevMobile = nowMobile;

        // Cleanly close whichever mode was active
        if ( nowMobile ) {
            closeTray();      // desktop → mobile: close tray
        } else {
            closeAllMobile(); // mobile → desktop: close accordion
        }
    } );

    // ── Re-position tray on resize (desktop only) ─────────────────────────────
    var resizeTimer;
    window.addEventListener( 'resize', function () {
        if ( isMobile() || ! activeCardId ) { return; }
        clearTimeout( resizeTimer );
        resizeTimer = setTimeout( function () {
            var card = getCard( activeCardId );
            if ( card ) { placeTrayAfterRow( card ); }
        }, 150 );
    } );

    // ── Live clock ────────────────────────────────────────────────────────────

    var clockEl = document.getElementById( 'os-hub-clock' );
    if ( clockEl ) {
        var locale  = DATA.isRtl ? 'ar-SA' : 'en-US';
        var options = { hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: ! DATA.isRtl };

        ( function tick() {
            try {
                clockEl.textContent = new Date().toLocaleTimeString( locale, options );
            } catch ( err ) {
                clockEl.textContent = new Date().toLocaleTimeString();
            }
            setTimeout( tick, 1000 );
        }() );
    }

} )( jQuery );

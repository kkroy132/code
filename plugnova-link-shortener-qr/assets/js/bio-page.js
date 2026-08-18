/**
 * Public Smart Bio Link page — announcement bar dismissal and countdown timer.
 * Enqueued only when the page actually has an announcement bar and/or an active countdown; see
 * templates/bio-page.php.
 */
( function () {
	"use strict";

	var bar = document.getElementById( "qlqr-bio-announcement" );
	if ( bar ) {
		var key = bar.getAttribute( "data-key" );
		try {
			if ( key && sessionStorage.getItem( key ) ) {
				bar.style.display = "none";
			}
		} catch ( e ) { /* sessionStorage unavailable (privacy mode, etc.) — bar just stays visible */ }

		var closeBtn = document.getElementById( "qlqr-bio-announcement-close" );
		if ( closeBtn ) {
			closeBtn.addEventListener( "click", function () {
				bar.style.display = "none";
				try {
					if ( key ) { sessionStorage.setItem( key, "1" ); }
				} catch ( e ) { /* ignore */ }
			} );
		}
	}

	var countdown = document.getElementById( "qlqr-bio-countdown" );
	if ( countdown ) {
		var target = parseInt( countdown.getAttribute( "data-target" ), 10 );
		var dEl = countdown.querySelector( '[data-unit="d"]' );
		var hEl = countdown.querySelector( '[data-unit="h"]' );
		var mEl = countdown.querySelector( '[data-unit="m"]' );
		var sEl = countdown.querySelector( '[data-unit="s"]' );

		var pad = function ( n ) { return n < 10 ? "0" + n : String( n ); };

		var tick = function () {
			var diff = target - Date.now();
			if ( diff <= 0 ) {
				countdown.style.display = "none";
				clearInterval( timer );
				return;
			}
			var totalSeconds = Math.floor( diff / 1000 );
			dEl.textContent = pad( Math.floor( totalSeconds / 86400 ) );
			hEl.textContent = pad( Math.floor( ( totalSeconds % 86400 ) / 3600 ) );
			mEl.textContent = pad( Math.floor( ( totalSeconds % 3600 ) / 60 ) );
			sEl.textContent = pad( totalSeconds % 60 );
		};

		tick();
		var timer = setInterval( tick, 1000 );
	}
} )();

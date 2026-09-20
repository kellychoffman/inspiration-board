/*
 * Sizes the board to the theme's main column rather than the narrow text
 * column, so it goes wide without running under a sidebar or off screen.
 * board.css has a centered-viewport fallback for when this doesn't run.
 */
( function () {
	var MAX = 1280;

	function fit() {
		var viewport = document.documentElement.clientWidth;
		var gutter = viewport <= 600 ? 16 : 32;

		document.querySelectorAll( '.inspiration-board-wrap' ).forEach( function ( wrap ) {
			var area = wrap.closest( 'main' ) || document.body;
			var areaStyle = getComputedStyle( area );
			var areaRect = area.getBoundingClientRect();

			var left = Math.max( areaRect.left + parseFloat( areaStyle.paddingLeft ), gutter );
			var right = Math.min( areaRect.right - parseFloat( areaStyle.paddingRight ), viewport - gutter );
			var width = Math.min( MAX, right - left );
			if ( ! ( width > 0 ) ) {
				// Hidden or zero-width window: leave the CSS fallback in charge.
				wrap.style.removeProperty( '--ib-fit-width' );
				wrap.style.removeProperty( '--ib-fit-margin' );
				return;
			}
			var target = left + ( right - left - width ) / 2;

			var parent = wrap.parentElement;
			var parentStyle = getComputedStyle( parent );
			var contentLeft = parent.getBoundingClientRect().left + parseFloat( parentStyle.borderLeftWidth ) + parseFloat( parentStyle.paddingLeft );

			wrap.style.setProperty( '--ib-fit-width', width + 'px' );
			wrap.style.setProperty( '--ib-fit-margin', target - contentLeft + 'px' );
		} );
	}

	// GIF and video tiles: load and play only while they're near the
	// viewport, and leave the heaviest files, or visitors who asked for less
	// motion or less data, with the still frame and its play badge.
	var MAX_AUTOPLAY_BYTES = 15 * 1024 * 1024;

	function autoplayWanted( video ) {
		var connection = navigator.connection || {};
		if ( connection.saveData ) {
			return false;
		}
		if ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
			return false;
		}
		return Number( video.dataset.size || 0 ) <= MAX_AUTOPLAY_BYTES;
	}

	// The badge comes off only once the tile is really moving: a phone that
	// refuses autoplay (Low Power Mode, say) keeps its play badge.
	function start( video ) {
		var playing = video.play();
		if ( ! playing || ! playing.then ) {
			video.parentNode.classList.toggle( 'is-playing', ! video.paused );
			return;
		}
		playing.then(
			function () {
				video.parentNode.classList.add( 'is-playing' );
			},
			function () {
				video.parentNode.classList.remove( 'is-playing' );
				// Some browsers refuse until there's data; try once more then.
				video.addEventListener(
					'loadeddata',
					function () {
						if ( video.paused ) {
							start( video );
						}
					},
					{ once: true }
				);
			}
		);
	}

	function playVisibleGifs() {
		var videos = [].filter.call( document.querySelectorAll( '.inspiration-board__video[data-src]' ), autoplayWanted );
		if ( ! videos.length || ! ( 'IntersectionObserver' in window ) ) {
			return;
		}
		var observer = new IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( entry ) {
					var video = entry.target;
					if ( entry.isIntersecting ) {
						if ( ! video.src ) {
							video.src = video.dataset.src;
						}
						start( video );
					} else {
						video.pause();
					}
				} );
			},
			{ rootMargin: '200px' }
		);
		videos.forEach( function ( video ) {
			observer.observe( video );
		} );
	}

	// A pin's own page: start its video muted, so clicking a tile always
	// leads to something moving. Controls are there to unmute.
	function playPinVideo() {
		var video = document.querySelector( '.inspiration-board-video video' );
		if ( ! video || video.autoplay ) {
			return;
		}
		video.muted = true;
		var playing = video.play();
		if ( playing && playing.catch ) {
			playing.catch( function () {} );
		}
	}

	fit();
	playPinVideo();
	playVisibleGifs();
	window.addEventListener( 'resize', fit );
	window.addEventListener( 'load', fit );
} )();

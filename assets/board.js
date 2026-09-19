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

	// GIF tiles: load and play only while they're near the viewport.
	function playVisibleGifs() {
		var videos = document.querySelectorAll( '.inspiration-board__video[data-src]' );
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
						var playing = video.play();
						if ( playing && playing.catch ) {
							playing.catch( function () {} );
						}
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

	fit();
	playVisibleGifs();
	window.addEventListener( 'resize', fit );
	window.addEventListener( 'load', fit );
} )();

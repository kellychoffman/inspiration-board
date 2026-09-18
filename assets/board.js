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
			var target = left + ( right - left - width ) / 2;

			var parent = wrap.parentElement;
			var parentStyle = getComputedStyle( parent );
			var contentLeft = parent.getBoundingClientRect().left + parseFloat( parentStyle.borderLeftWidth ) + parseFloat( parentStyle.paddingLeft );

			wrap.style.setProperty( '--ib-fit-width', width + 'px' );
			wrap.style.setProperty( '--ib-fit-margin', target - contentLeft + 'px' );
		} );
	}

	fit();
	window.addEventListener( 'resize', fit );
	window.addEventListener( 'load', fit );
} )();

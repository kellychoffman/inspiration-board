/*
 * Inspiration Board: bookmark collector.
 *
 * Paste this into the browser console on https://x.com/i/bookmarks (or any
 * X page that lists tweets, like /i/history or a profile). It scrolls to the
 * end of the list, collects every tweet that has photos,
 * and downloads inspiration-bookmarks.json for the WordPress importer.
 * Nothing is sent anywhere; it only reads the page you are looking at.
 */
( async () => {
	const MAX_IDLE_ROUNDS = 6; // Stop after this many scrolls at the bottom with nothing new loading.
	const found = new Map();

	const grab = () => {
		let added = 0;
		document.querySelectorAll( 'article[data-testid="tweet"]' ).forEach( ( article ) => {
			const time = article.querySelector( 'a[href*="/status/"] time' );
			const link = time && time.closest( 'a' );
			if ( ! link ) {
				return;
			}
			const match = new URL( link.getAttribute( 'href' ), location.origin ).pathname.match( /^\/([^/]+)\/status\/(\d+)/ );
			if ( ! match ) {
				return;
			}

			const images = [ ...article.querySelectorAll( '[data-testid="tweetPhoto"] img' ) ]
				.map( ( img ) => img.src )
				.filter( ( src ) => src.includes( 'pbs.twimg.com/media/' ) );
			if ( ! images.length ) {
				return;
			}

			const url = `https://x.com/${ match[ 1 ] }/status/${ match[ 2 ] }`;
			const previous = found.get( url );
			if ( ! previous ) {
				added++;
			}

			const nameEl = article.querySelector( '[data-testid="User-Name"] span' );
			const textEl = article.querySelector( '[data-testid="tweetText"]' );

			found.set( url, {
				url,
				handle: match[ 1 ],
				name: nameEl ? nameEl.textContent.trim() : '',
				text: textEl ? textEl.innerText.trim() : '',
				date: time.getAttribute( 'datetime' ) || '',
				images: [ ...new Set( [ ...( previous ? previous.images : [] ), ...images ] ) ],
			} );
		} );
		return added;
	};

	if ( ! /(^|\.)(x|twitter)\.com$/.test( location.hostname ) ) {
		console.warn( 'Inspiration Board: open https://x.com/i/bookmarks first, then run this again.' );
		return;
	}

	const wait = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );
	window.scrollTo( 0, 0 );
	await wait( 800 );

	let idle = 0;
	while ( idle < MAX_IDLE_ROUNDS ) {
		const added = grab();
		const atBottom = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 50;
		idle = added || ! atBottom ? 0 : idle + 1;
		console.log( `Inspiration Board: ${ found.size } tweets with images so far…` );
		window.scrollBy( 0, window.innerHeight * 0.9 );
		await wait( 1200 );
	}
	grab();

	const tweets = [ ...found.values() ];
	const json = JSON.stringify( { source: 'x-bookmarks', collected: new Date().toISOString(), tweets }, null, 2 );
	window.inspirationBookmarks = tweets;

	const a = document.createElement( 'a' );
	a.href = URL.createObjectURL( new Blob( [ json ], { type: 'application/json' } ) );
	a.download = 'inspiration-bookmarks.json';
	document.body.appendChild( a );
	a.click();
	a.remove();

	console.log( `Inspiration Board: done. ${ tweets.length } tweets saved to inspiration-bookmarks.json. Upload it under Tools → Inspiration Board.` );
} )();

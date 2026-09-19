/*
 * Inspiration Board: bookmark collector.
 *
 * Paste this into the browser console on https://x.com/i/bookmarks (or any
 * X page that lists tweets, like /i/history or a profile). It scrolls to the
 * end of the list, collects every tweet that has photos, and downloads
 * inspiration-bookmarks.json for the WordPress importer, in list order.
 * Keep the window visible while it runs: X stops loading when it's hidden.
 * Nothing is sent anywhere; it only reads the page you are looking at.
 */
( async () => {
	const MAX_IDLE_ROUNDS = 6; // Stop after this many scrolls at the bottom with nothing new loading.
	const wait = ( ms ) => new Promise( ( resolve ) => setTimeout( resolve, ms ) );

	if ( ! /(^|\.)(x|twitter)\.com$/.test( location.hostname ) ) {
		console.warn( 'Inspiration Board: open https://x.com/i/bookmarks first, then run this again.' );
		return;
	}

	// Every tweet seen, photos or not, in list order. Tweets without photos
	// aren't exported but still help place their neighbours correctly.
	const order = [];
	const tweets = new Map();
	const yOf = new Map(); // Last seen list position, for placing screenfuls that share no tweets with what's known.

	// X renders a virtual list: each row is positioned with translateY.
	const rowY = ( article ) => {
		const row = article.closest( '[data-testid="cellInnerDiv"]' );
		const match = row && row.style.transform.match( /translateY\(([-\d.]+)px\)/ );
		return match ? parseFloat( match[ 1 ] ) : null;
	};

	const read = ( article ) => {
		const time = article.querySelector( 'a[href*="/status/"] time' );
		const link = time && time.closest( 'a' );
		if ( ! link ) {
			return null;
		}
		const match = new URL( link.getAttribute( 'href' ), location.origin ).pathname.match( /^\/([^/]+)\/status\/(\d+)/ );
		if ( ! match ) {
			return null;
		}
		const nameEl = article.querySelector( '[data-testid="User-Name"] span' );
		const textEl = article.querySelector( '[data-testid="tweetText"]' );
		return {
			url: `https://x.com/${ match[ 1 ] }/status/${ match[ 2 ] }`,
			handle: match[ 1 ],
			name: nameEl ? nameEl.textContent.trim() : '',
			text: textEl ? textEl.innerText.trim() : '',
			date: time.getAttribute( 'datetime' ) || '',
			images: [ ...article.querySelectorAll( '[data-testid="tweetPhoto"] img' ) ]
				.map( ( img ) => img.src )
				.filter( ( src ) => src.includes( 'pbs.twimg.com/media/' ) ),
		};
	};

	const remember = ( tweet ) => {
		const previous = tweets.get( tweet.url );
		tweets.set( tweet.url, {
			...tweet,
			images: [ ...new Set( [ ...( previous ? previous.images : [] ), ...tweet.images ] ) ],
		} );
	};

	// Reads what's on screen and stitches it into `order`, using tweets
	// already in the list as reference points. Returns how many were new.
	const grab = () => {
		const visible = [ ...document.querySelectorAll( 'article[data-testid="tweet"]' ) ].map( ( article ) => ( { article, y: rowY( article ) } ) );
		if ( visible.every( ( v ) => v.y !== null ) ) {
			visible.sort( ( a, b ) => a.y - b.y );
		}
		const ys = new Map();
		const seen = visible
			.map( ( v ) => {
				const t = read( v.article );
				if ( t ) {
					ys.set( t.url, v.y );
				}
				return t;
			} )
			.filter( Boolean );
		const before = order.length;

		const firstKnown = seen.findIndex( ( t ) => tweets.has( t.url ) );
		if ( firstKnown === -1 ) {
			// Nothing familiar on screen: place by list position instead.
			for ( const t of seen ) {
				const y = ys.get( t.url );
				let at = order.length;
				if ( y !== null && y !== undefined ) {
					const later = order.findIndex( ( url ) => yOf.has( url ) && yOf.get( url ) > y );
					at = later === -1 ? order.length : later;
				}
				order.splice( at, 0, t.url );
			}
		} else {
			// Unknown tweets above the first familiar one go just before it.
			const at = order.indexOf( seen[ firstKnown ].url );
			order.splice( at, 0, ...seen.slice( 0, firstKnown ).map( ( t ) => t.url ) );
			// The rest follow their nearest familiar tweet above them.
			let cursor = order.indexOf( seen[ firstKnown ].url );
			for ( const t of seen.slice( firstKnown + 1 ) ) {
				const index = order.indexOf( t.url );
				if ( index >= 0 ) {
					cursor = index;
				} else {
					order.splice( cursor + 1, 0, t.url );
					cursor++;
				}
			}
		}

		seen.forEach( remember );
		ys.forEach( ( y, url ) => y !== null && yOf.set( url, y ) );
		return order.length - before;
	};

	// Start from the real top: X can briefly show rows from wherever the
	// page was last scrolled, which would otherwise be recorded as "first".
	window.scrollTo( 0, 1 ); // A real scroll change makes X redraw.
	await wait( 100 );
	window.scrollTo( 0, 0 );
	for ( let i = 0; i < 25; i++ ) {
		await wait( 200 );
		const ys = [ ...document.querySelectorAll( 'article[data-testid="tweet"]' ) ].map( rowY ).filter( ( y ) => y !== null );
		if ( ys.length && Math.min( ...ys ) === 0 ) {
			break;
		}
	}
	await wait( 500 );

	let idle = 0;
	while ( idle < MAX_IDLE_ROUNDS ) {
		const added = grab();
		const atBottom = window.innerHeight + window.scrollY >= document.documentElement.scrollHeight - 50;
		idle = added || ! atBottom ? 0 : idle + 1;
		const withPhotos = order.filter( ( url ) => tweets.get( url ).images.length ).length;
		console.log( `Inspiration Board: ${ withPhotos } tweets with images so far…` );
		window.scrollBy( 0, window.innerHeight * 0.7 );
		await wait( 1200 );
	}
	grab();

	const result = order.map( ( url ) => tweets.get( url ) ).filter( ( t ) => t.images.length );
	const json = JSON.stringify( { source: 'x-bookmarks', collected: new Date().toISOString(), tweets: result }, null, 2 );
	window.inspirationBookmarks = result;

	const a = document.createElement( 'a' );
	a.href = URL.createObjectURL( new Blob( [ json ], { type: 'application/json' } ) );
	a.download = 'inspiration-bookmarks.json';
	document.body.appendChild( a );
	a.click();
	a.remove();

	console.log( `Inspiration Board: done. ${ result.length } tweets saved to inspiration-bookmarks.json. Upload it under Tools → Inspiration Board.` );
} )();

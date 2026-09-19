( function () {
	const BATCH_SIZE = 3; // Tweets per request; each image is downloaded server-side.

	const $ = ( id ) => document.getElementById( id );

	$( 'ib-copy' ).addEventListener( 'click', async ( event ) => {
		const button = event.currentTarget;
		const text = $( 'ib-snippet' ).value;
		try {
			await navigator.clipboard.writeText( text );
		} catch ( e ) {
			$( 'ib-snippet' ).select();
			document.execCommand( 'copy' );
		}
		const label = button.textContent;
		button.textContent = 'Copied';
		setTimeout( () => ( button.textContent = label ), 1500 );
	} );

	const readInput = async () => {
		const file = $( 'ib-file' ).files[ 0 ];
		const raw = file ? await file.text() : $( 'ib-json' ).value.trim();
		if ( ! raw ) {
			throw new Error( 'Choose the inspiration-bookmarks.json file or paste its contents first.' );
		}
		let data;
		try {
			data = JSON.parse( raw );
		} catch ( e ) {
			throw new Error( 'That does not look like valid JSON.' );
		}
		const tweets = Array.isArray( data ) ? data : data.tweets;
		if ( ! Array.isArray( tweets ) || ! tweets.length ) {
			throw new Error( 'No tweets found in that file.' );
		}
		// Keep the file's order (newest bookmark first): the importer uses it
		// to slot each new pin into the right place on the board.
		return tweets;
	};

	const setStatus = ( text ) => ( $( 'ib-status' ).textContent = text );

	const addError = ( text ) => {
		const li = document.createElement( 'li' );
		li.textContent = text;
		$( 'ib-errors' ).appendChild( li );
	};

	const backfill = $( 'ib-backfill' );
	if ( backfill ) {
		backfill.addEventListener( 'click', async ( event ) => {
			const button = event.currentTarget;
			const total = Number( button.dataset.remaining );
			button.disabled = true;
			$( 'ib-backfill-progress' ).hidden = false;

			let remaining = total;
			let failed = 0;
			while ( remaining > 0 ) {
				$( 'ib-backfill-status' ).textContent = `Downloading… ${ total - remaining } of ${ total } done.`;
				let result;
				try {
					result = await wp.apiFetch( {
						path: '/inspiration-board/v1/backfill-videos',
						method: 'POST',
						data: { limit: 2 },
					} );
				} catch ( e ) {
					$( 'ib-backfill-status' ).textContent = `Stopped: ${ e.message || 'the request failed' }. Click again to carry on.`;
					button.disabled = false;
					return;
				}
				failed += result.failed;
				remaining = result.remaining;
				$( 'ib-backfill-bar' ).value = total - remaining;
			}

			$( 'ib-backfill-status' ).textContent =
				`Done. ${ total - failed } now play on the site` +
				( failed ? `, ${ failed } had no downloadable file and still link out to X.` : '.' );
			button.disabled = false;
		} );
	}

	$( 'ib-import' ).addEventListener( 'click', async ( event ) => {
		const button = event.currentTarget;
		$( 'ib-progress' ).hidden = false;
		$( 'ib-errors' ).innerHTML = '';

		let tweets;
		try {
			tweets = await readInput();
		} catch ( e ) {
			setStatus( e.message );
			return;
		}

		button.disabled = true;
		const totals = { created: 0, skipped: 0, errors: 0 };
		let cursor = 0;
		$( 'ib-bar' ).max = tweets.length;

		for ( let i = 0; i < tweets.length; i += BATCH_SIZE ) {
			const batch = tweets.slice( i, i + BATCH_SIZE );
			setStatus( `Importing tweets ${ i + 1 }–${ i + batch.length } of ${ tweets.length }…` );
			try {
				const result = await wp.apiFetch( {
					path: '/inspiration-board/v1/import',
					method: 'POST',
					data: { tweets: batch, cursor },
				} );
				cursor = result.cursor;
				totals.created += result.created;
				totals.skipped += result.skipped;
				totals.errors += result.errors.length;
				result.errors.forEach( addError );
			} catch ( e ) {
				totals.errors += batch.length;
				addError( e.message || 'A batch failed to import.' );
			}
			$( 'ib-bar' ).value = i + batch.length;
		}

		setStatus(
			`Done. ${ totals.created } new posts, ${ totals.skipped } already on the board` +
				( totals.errors ? `, ${ totals.errors } ${ totals.errors === 1 ? 'problem' : 'problems' } (listed below).` : '.' )
		);
		button.disabled = false;
	} );
} )();

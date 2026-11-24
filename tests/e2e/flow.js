/**
 * End-to-end check of the applicant journey on the live site, as a guest and then a new account.
 *
 * Usage: node flow.js [chromium|webkit|firefox] [width] [base URL] [screenshot dir]
 *   node wp-content/plugins/mmi-teach/tests/e2e/flow.js webkit 390
 *
 * Creates one applicant account with an @example.invalid address (mail to .invalid is recorded,
 * not sent) and deletes it at the end through the site's own "Delete my account". Exits 1 on any
 * failed step, JavaScript error or horizontal overflow.
 */
const pw = require( process.env.PLAYWRIGHT || '/home/runcloud/node_modules/playwright' );

const [ engine = 'chromium', width = '1440', base = 'https://teach.mannmade.us', shots = '' ] = process.argv.slice( 2 );
const results = [];
let failed = 0;

function check( ok, what ) {
	results.push( ( ok ? 'PASS ' : 'FAIL ' ) + what );
	if ( ! ok ) {
		failed++;
	}
}

( async () => {
	const browser = await pw[ engine ].launch();
	const context = await browser.newContext( { viewport: { width: +width, height: +width < 600 ? 844 : 900 } } );
	const page = await context.newPage();
	const errors = [];
	page.on( 'pageerror', ( e ) => errors.push( 'JS ' + e.message.slice( 0, 160 ) ) );
	page.on( 'response', ( r ) => {
		// Cloudflare answers speculative prefetches of uncacheable pages with 503; browsers ignore them.
		const prefetch = /prefetch|prerender/i.test( r.request().headers()[ 'sec-purpose' ] || r.request().headers().purpose || '' );
		if ( r.status() >= 500 && r.url().startsWith( base ) && ! prefetch ) {
			errors.push( r.status() + ' ' + r.url().slice( 0, 120 ) );
		}
	} );

	const go = async ( path ) => {
		await page.goto( base + path, { waitUntil: 'load', timeout: 60000 } );
		const m = await page.evaluate( () => ( { w: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth } ) );
		check( m.w <= m.cw + 1, `no horizontal overflow on ${ path } (${ m.w } > ${ m.cw }?)` );
		if ( shots ) {
			const name = ( path.replace( /[^a-z0-9]+/gi, '-' ).replace( /^-|-$/g, '' ) || 'home' ) + `-${ width }-${ engine }.png`;
			await page.screenshot( { path: `${ shots }/${ name }`, fullPage: true } );
		}
	};
	const text = async () => page.evaluate( () => document.body.innerText );
	const submit = async ( formSelector ) => {
		await Promise.all( [
			page.waitForResponse( ( r ) => r.url().includes( 'admin-ajax.php' ) || r.url().includes( '/wp-json/bricks' ), { timeout: 30000 } ).catch( () => null ),
			page.locator( formSelector + ' button[type=submit]' ).first().click(),
		] );
		await page.waitForTimeout( 2500 );
		await page.waitForLoadState( 'load' );
	};

	try {
		/* 1. Guest answers the six questions. */
		await go( '/check/' );
		check( ( await text() ).includes( 'What can I apply for?' ), 'check page loads' );
		const form = '#answers form';
		const pick = ( field, value ) => page.locator( `${ form } input[name="form-field-${ field }[]"][value="${ value }"]` ).check();
		await pick( 'mtedu0', 'associate' );
		await pick( 'mtcred', 'none' );
		await pick( 'mtepp0', 'no' );
		await pick( 'mtage0', '20' );
		await pick( 'mtoutw', 'no' );
		await submit( form );
		await page.goto( base + '/check/', { waitUntil: 'load' } );
		let body = await text();
		check( body.includes( 'Your options' ), 'answers saved: "Your options" shows' );
		check( /Substitute teacher[\s\S]{0,80}You can qualify in weeks/.test( body ), 'associate degree, no permit → substitute: can qualify in weeks' );
		check( /Aide \/ paraprofessional[\s\S]{0,120}(You qualify|You probably qualify)/.test( body ), 'associate degree → aide jobs open now' );
		check( body.includes( 'Substitute training' ) && body.includes( 'Substitute permit' ), 'substitute route lists training and permit' );

		/* 2. Jobs filtered to "can apply now", with fit on every card. */
		await go( '/jobs/?fit=now' );
		body = await text();
		check( body.includes( 'Showing only jobs you can apply to now' ), 'fit filter on' );
		const cards = await page.locator( '.mt-job' ).count();
		check( cards > 0, `jobs listed with the fit filter (${ cards })` );
		check( ( await page.locator( '.mt-job .mt-badge' ).count() ) >= cards, 'every card shows a fit badge' );
		check( ! /Not this one yet/.test( body ), 'no "not this one yet" jobs in the apply-now list' );
		await go( '/jobs/?fit=all' );

		/* 3. A substitute job: what it needs, for this person. */
		await go( '/jobs/?role=substitute-teacher' );
		const href = await page.locator( '.mt-job h3 a' ).first().getAttribute( 'href' );
		check( !! href, 'role filter by URL lists substitute jobs' );
		const jobPath = new URL( href, base ).pathname;
		await go( jobPath );
		body = await text();
		check( body.includes( 'What this job needs' ), 'job page: what this job needs' );
		check( /You need this|You have this/.test( body ), 'job page: have/need per requirement' );
		check( body.includes( 'Create an account to apply' ), 'job page: guest sees create-account to apply' );

		/* 4. Checklist: mark one item done with a date. */
		await go( '/checklist/' );
		body = await text();
		const before = ( body.match( /(\d+) of (\d+) done/ ) || [] ).slice( 1 ).map( Number );
		check( before.length === 2, `checklist shows progress (${ before.join( ' of ' ) })` );
		check( body.includes( 'TB' ) && body.includes( 'Form I-9' ), 'checklist includes TB screening and I-9' );
		const item = page.locator( 'details.mt-task' ).filter( { hasText: 'Order an official transcript' } ).first();
		await item.locator( 'summary' ).click();
		await item.locator( 'input[type=radio][value=done]' ).check();
		await Promise.all( [ page.waitForEvent( 'load', { timeout: 30000 } ).catch( () => null ), item.locator( 'button[type=submit]' ).click() ] );
		await page.waitForTimeout( 1500 );
		await page.goto( base + '/checklist/', { waitUntil: 'load' } );
		const after = ( ( await text() ).match( /(\d+) of (\d+) done/ ) || [] ).slice( 1 ).map( Number );
		check( after[ 0 ] === before[ 0 ] + 1, `ticking an item moves progress (${ before[ 0 ] } → ${ after[ 0 ] })` );

		/* 5. Join from the job and save the application. */
		await go( jobPath );
		await page.locator( 'a', { hasText: 'Create an account to apply' } ).first().click();
		await page.waitForLoadState( 'load' );
		const email = `e2e-${ Date.now() }@example.invalid`;
		const password = 'Test-' + Math.random().toString( 36 ).slice( 2 ) + '-9';
		await page.fill( 'input[name="form-field-mtfnam"]', 'Tester' );
		await page.fill( 'input[name="form-field-mtemai"]', email );
		await page.fill( 'input[name="form-field-mtpass"]', password );
		await Promise.all( [ page.waitForURL( '**' + jobPath + '**', { timeout: 30000 } ).catch( () => null ), page.locator( 'form button[type=submit]' ).first().click() ] );
		await page.waitForLoadState( 'load' );
		check( page.url().includes( jobPath ), 'after joining, back on the job' );
		body = await text();
		check( body.includes( 'Save my application' ), 'signed in: apply form shows' );
		await page.locator( '#apply input[type=checkbox][value=yes]' ).check();
		await page.locator( '#apply input[type=checkbox][value=on_call]' ).check();
		await Promise.all( [ page.waitForEvent( 'load', { timeout: 30000 } ).catch( () => null ), page.locator( '#apply button[type=submit]' ).click() ] );
		await page.waitForTimeout( 1500 );
		await page.goto( base + jobPath, { waitUntil: 'load' } );
		body = await text();
		check( body.includes( 'Continue to the employer’s application' ), 'application saved: hand-off button shows' );
		const handoff = await page.locator( '#apply a', { hasText: 'Continue to the employer’s application' } ).getAttribute( 'href' );
		check( /^https?:\/\//.test( handoff || '' ) && ! ( handoff || '' ).includes( 'teach.mannmade.us' ), `hand-off goes to the employer (${ ( handoff || '' ).slice( 0, 60 ) })` );

		/* 6. My applications: listed, status can be updated. */
		await go( '/my-applications/' );
		body = await text();
		check( body.includes( 'Saved here' ), 'my applications lists it as saved here' );
		await page.locator( 'details.mt-details summary' ).first().click();
		await page.locator( 'select[name="form-field-mtasta"]' ).first().selectOption( 'finished' );
		await Promise.all( [ page.waitForEvent( 'load', { timeout: 30000 } ).catch( () => null ), page.locator( 'details.mt-details button[type=submit]' ).first().click() ] );
		await page.waitForTimeout( 1500 );
		await page.goto( base + '/my-applications/', { waitUntil: 'load' } );
		check( ( await text() ).includes( 'Finished on the employer’s site' ), 'status updated by the applicant' );
		check( ( await text() ).includes( 'Checklist for this job' ), 'per-job checklist link' );

		/* 7. Sample persona overlay and back. */
		await go( '/check/?mt_persona=permit' );
		check( ( await text() ).includes( 'You’re seeing the site as Priya' ), 'persona banner shows' );
		await page.goto( base + '/check/', { waitUntil: 'load' } );
		check( /Substitute teacher[\s\S]{0,80}You qualify/.test( await text() ), 'permit persona qualifies for substitute jobs' );
		await page.goto( base + '/check/?mt_persona=off', { waitUntil: 'load' } );
		check( ! ( await text() ).includes( 'You’re seeing the site as' ), 'back to own answers' );

		/* 8. Delete the account. */
		await go( '/account/' );
		await page.locator( 'details summary', { hasText: 'Delete my account' } ).click();
		await page.locator( 'input[type=checkbox][value=yes]' ).last().check();
		await page.fill( 'input[name="form-field-mtdpwd"]', password );
		await Promise.all( [ page.waitForURL( '**deleted=1**', { timeout: 30000 } ).catch( () => null ), page.locator( 'details button[type=submit]' ).last().click() ] );
		check( page.url().includes( 'deleted=1' ), 'account deleted and signed out' );
		await page.goto( base + '/my-applications/', { waitUntil: 'load' } );
		check( ( await text() ).includes( 'Sign in to see your applications' ), 'signed out after deleting' );
	} catch ( e ) {
		check( false, 'unexpected: ' + e.message.split( '\n' )[ 0 ] );
	}

	for ( const e of [ ...new Set( errors ) ] ) {
		check( false, e );
	}
	console.log( `${ engine } ${ width }px` );
	console.log( results.join( '\n' ) );
	console.log( failed ? `${ failed } failed` : 'all passed' );
	await browser.close();
	process.exit( failed ? 1 : 0 );
} )();

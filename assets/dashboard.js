/* MMI Teach — teaching-vacancy dashboard. Plain DOM, no build step; all data text goes through textContent. */
( function () {
	'use strict';

	const cfg = window.mmiTeach || {};
	const root = document.getElementById( 'mt-root' );
	if ( ! root || ! cfg.endpoint ) {
		return;
	}

	const DAY = 864e5;
	const today = startOfDay( new Date() );
	const COLUMNS = [
		{ key: 'id', label: 'ID', num: true },
		{ key: 'posted', label: 'Posted', date: true },
		{ key: 'title', label: 'Position' },
		{ key: 'subject', label: 'Subject' },
		{ key: 'reqRank', label: 'Requirements', num: true },
		{ key: 'employer', label: 'Employer' },
		{ key: 'agencyLabel', label: 'Staffing agency' },
		{ key: 'school', label: 'School / building' },
		{ key: 'county', label: 'County' },
		{ key: 'appt', label: 'Type' },
		{ key: 'payDay', label: 'Pay', num: true, nullsLast: true },
		{ key: 'start', label: 'Starts' },
		{ key: 'closeSort', label: 'Closes' },
	];
	// kind: jobs = regular teaching jobs, subs = every substitute kind, all, or one sub kind.
	// Lowest to highest; a posting's required degree is compared against the reader's own.
	const DEGREE_ORDER = [ 'none', 'high_school', 'associate', 'bachelor', 'master', 'doctorate' ];
	const DEFAULTS = { county: '', subject: '', band: '', appt: '', posted: '', q: '', employer: '', agency: '', mydegree: '', license: '', fit: '', kind: 'jobs', pay: '', minday: '', sort: 'posted', dir: 'desc' };
	// Filters folded under "More filters".
	const MORE_KEYS = [ 'agency', 'license', 'subject', 'band', 'appt', 'posted', 'pay' ];
	const OVERVIEW_KEY = 'mmiTeachOverview';
	// A source that hasn't refreshed in this long (three missed runs) gets a note in the footer.
	const STALE_MS = 9 * 36e5;
	// The reader's answers from the "Become a substitute" page (pathway.js), if they gave any.
	const profile = readProfile();
	const FIT_LABELS = [ 'Not yet', 'Might fit', 'You qualify' ];
	if ( profile ) {
		COLUMNS.splice( 3, 0, { key: 'fitRank', label: 'Your fit', num: true } );
	}

	let data = null;
	let state = readHash();
	let openKey = '';
	const el = {};
	syncView();
	// The nav's two job tabs only change the hash on this page; back and forward do too.
	window.addEventListener( 'hashchange', () => {
		state = readHash();
		if ( data ) {
			syncControls();
			render();
		} else {
			syncView();
		}
	} );

	fetch( cfg.endpoint, { headers: { 'X-WP-Nonce': cfg.nonce }, credentials: 'same-origin' } )
		.then( ( r ) => {
			if ( ! r.ok ) {
				throw new Error( r.status === 401 || r.status === 403 ? 'Your session has expired. Reload the page or sign in again.' : 'The server answered ' + r.status + '.' );
			}
			return r.json();
		} )
		.then( ( snapshot ) => {
			data = prepare( snapshot );
			build();
			render();
		} )
		.catch( ( err ) => {
			root.replaceChildren( h( 'p', { class: 'mt-card mt-error' }, 'Couldn’t load vacancies. ' + err.message ) );
		} );

	/* ---------- data ---------- */

	function prepare( s ) {
		s.vacancies.forEach( ( v ) => {
			// An undated posting first seen when tracking began could be any age: don't call it new.
			v.postedTime = v.posted && ! ( v.postedSeen && v.posted <= s.since ) ? parseDate( v.posted ).getTime() : 0;
			v.closeTime = v.closeDate ? parseDate( v.closeDate ).getTime() : 0;
			// Dated closings sort first by date; "until filled" after them.
			v.closeSort = v.closeTime || Number.MAX_SAFE_INTEGER;
			v.isSub = !! v.subKind;
			v.req = v.req || { degree: '', licenses: [] };
			// Expand the snapshot's shared dictionaries: license keys, note and route text ids.
			v.req.licenses = v.req.licenses.map( ( l ) => ( typeof l === 'string' ? Object.assign( { key: l }, s.licenseInfo[ l ] ) : Object.assign( { key: '' }, l ) ) );
			v.req.degreeNote = v.req.degreeNote === null || v.req.degreeNote === undefined ? '' : s.texts[ v.req.degreeNote ];
			v.req.routes = ( v.req.routes || [] ).map( ( i ) => s.texts[ i ] );
			v.reqRank = DEGREE_ORDER.indexOf( v.req.degree );
			v.degreeLabel = s.degrees[ v.req.degree ] || '';
			v.expYears = v.req.experience === null || v.req.experience === undefined ? '' : v.req.experience;
			v.reqBasis = v.req.licenseBasis || '';
			v.licenseKeys = v.req.licenses.map( ( l ) => l.key ).filter( Boolean );
			v.licenseText = v.req.licenses.map( ( l ) => l.short || l.title ).join( '; ' );
			v.reqSummary = [ s.degrees[ v.req.degree ] || '', v.req.licenses.length ? ( v.req.licenses[ 0 ].short || v.req.licenses[ 0 ].title ) + ( v.req.licenses.length > 1 ? ' +' + ( v.req.licenses.length - 1 ) : '' ) : '' ].filter( Boolean ).join( ' · ' );
			v.subKindLabel = v.isSub ? s.subKinds[ v.subKind ] : '';
			// Short form for the table; the filter uses the full labels.
			v.agencyLabel = ( v.agency === 'direct' ? 'None' : ( s.agencies[ v.agency ] || '' ) ) + ( v.agencyBasis === 'likely' ? ' (likely)' : '' );
			v.payDay = v.payDay === null || v.payDay === undefined ? null : Number( v.payDay );
			v.fit = profile ? fitFor( v, s ) : null;
			v.fitRank = v.fit ? v.fit.rank : -1;
			v.haystack = [ v.title, v.subject, v.employer, v.agencyLabel, v.licenseText, v.school, v.county, v.band, v.payText, ( v.licenses || [] ).join( ' ' ), v.id ].join( ' ' ).toLowerCase();
		} );
		return s;
	}

	/** Postings matching every filter except the ones named in `skip` (for cross-filter counts). */
	function filtered( skip ) {
		const q = state.q.trim().toLowerCase();
		const words = q ? q.split( /\s+/ ) : [];
		const postedSince = state.posted ? today.getTime() - ( Number( state.posted ) - 1 ) * DAY : 0;
		const minDay = Number( state.minday ) || 0;
		return data.vacancies.filter( ( v ) =>
			( skip === 'kind' || kindMatch( v ) ) &&
			( ! state.pay || payMatch( v ) ) &&
			( ! minDay || ( v.payDay !== null && v.payDay >= minDay ) ) &&
			( skip === 'county' || ! state.county || v.county === state.county ) &&
			( skip === 'subject' || ! state.subject || v.subject === state.subject ) &&
			( skip === 'employer' || ! state.employer || v.employer === state.employer ) &&
			( ! state.agency || v.agency === state.agency ) &&
			( ! state.mydegree || v.reqRank <= DEGREE_ORDER.indexOf( state.mydegree ) ) &&
			( ! state.license || v.licenseKeys.includes( state.license ) ) &&
			( ! state.fit || ! v.fit || v.fit.rank >= ( state.fit === 'yes' ? 2 : 1 ) ) &&
			( ! state.band || v.band === state.band ) &&
			( ! state.appt || v.appt === state.appt ) &&
			( ! postedSince || v.postedTime >= postedSince ) &&
			words.every( ( w ) => v.haystack.includes( w ) )
		);
	}

	function kindMatch( v ) {
		switch ( state.kind ) {
			case 'all':
				return true;
			case 'jobs':
				return ! v.isSub;
			case 'subs':
				return v.isSub;
			default:
				return v.subKind === state.kind;
		}
	}

	/**
	 * Whether the reader's license and degree fit a posting: 2 qualifies, 1 might (a condition to
	 * check), 0 not yet. A permit's own degree rule is already met, so for permit holders only a
	 * degree the posting itself states counts (or the rule, outside long-term jobs, which follow
	 * the license's rule a permit holder is exempt from for 45 days).
	 */
	function fitFor( v, s ) {
		const r = v.req;
		const permit = profile.license !== 'long_term';
		if ( ! v.isSub ) {
			return permit ? { rank: 0, text: 'Needs a Wisconsin teaching license' } : { rank: 1, text: 'If your license covers ' + ( ( r.licenses[ 0 ] && ( r.licenses[ 0 ].short || r.licenses[ 0 ].title ) ) || v.subject ) };
		}
		const stated = r.degreeBasis === 'posted' || r.degreeBasis === 'text';
		const counts = profile.license === 'permit_b' ? stated : ( profile.license === 'permit_a' ? stated || v.subKind !== 'long_term' : true );
		if ( counts && v.reqRank > DEGREE_ORDER.indexOf( profile.degree ) ) {
			const need = ( s.degrees[ r.degree ] || 'degree' ).toLowerCase();
			// An associate degree is DPI's permit minimum, which the educator-program permit meets another way.
			if ( profile.license === 'permit_b' && r.degree === 'associate' ) {
				return { rank: 1, text: 'Asks for an associate degree; ask whether your educator-program permit is accepted' };
			}
			return { rank: 0, text: 'Needs ' + ( /^[aeiou]/.test( need ) ? 'an ' : 'a ' ) + need };
		}
		if ( v.subKind === 'long_term' ) {
			const special = r.licenses.some( ( l ) => l.category === 'special_education' ) && r.licenses.every( ( l ) => l.category === 'special_education' || l.category === 'substitute' );
			if ( special ) {
				return permit ? { rank: 0, text: 'Over 45 days needs a special-education license' } : { rank: 1, text: 'If your license is in special education' };
			}
			return permit ? { rank: 1, text: 'A permit covers 45 consecutive days; longer needs a license in this subject' } : { rank: 1, text: 'If your license covers this subject' };
		}
		return { rank: 2, text: 'Your ' + profile.licenseName.toLowerCase() + ' covers it' };
	}

	function payMatch( v ) {
		if ( state.pay === 'listed' ) {
			return v.payBasis === 'posted';
		}
		if ( state.pay === 'daily' ) {
			return v.payUnit === 'day' || v.payUnit === 'hour';
		}
		return state.pay === 'salary' ? v.payUnit === 'year' : true;
	}

	function sorted( rows ) {
		const col = COLUMNS.find( ( c ) => c.key === state.sort ) || COLUMNS[ 1 ];
		const key = col.date ? 'postedTime' : col.key;
		const dir = state.dir === 'asc' ? 1 : -1;
		return rows.slice().sort( ( a, b ) => {
			let x = a[ key ];
			let y = b[ key ];
			if ( col.nullsLast && ( x === null || y === null ) ) {
				// No posted pay sorts last in both directions.
				return ( x === null ) - ( y === null ) || ( b.postedTime - a.postedTime );
			}
			if ( col.num ) {
				x = Number( x );
				y = Number( y );
			}
			const c = typeof x === 'number' ? x - y : String( x || '' ).localeCompare( String( y || '' ), undefined, { sensitivity: 'base' } );
			return c * dir || ( b.postedTime - a.postedTime );
		} );
	}

	/* ---------- layout ---------- */

	function build() {
		el.freshness = document.getElementById( 'mt-freshness' );
		if ( cfg.settings ) {
			document.getElementById( 'mt-head-actions' ).replaceChildren( h( 'a', { class: 'mt-btn', href: cfg.settings }, 'Settings' ) );
		}

		el.intro = h( 'section', { class: 'mt-card mt-intro', 'aria-label': 'How substitute jobs work' } );
		el.kpis = h( 'section', { class: 'mt-kpis', 'aria-label': 'Summary' } );

		el.q = h( 'input', { type: 'search', class: 'mt-input mt-search', placeholder: 'Search title, school, employer, license…', 'aria-label': 'Search' } );
		el.q.addEventListener( 'input', debounce( () => set( { q: el.q.value } ), 250 ) );
		el.county = select( 'County', [ [ '', 'All counties' ] ].concat( data.counties.map( ( c ) => [ c, c + ' County' ] ) ), 'county' );
		el.subject = select( 'Subject', [ [ '', 'All subjects' ] ].concat( data.subjects.map( ( s ) => [ s, s ] ) ), 'subject' );
		el.band = select( 'Grade level', [ [ '', 'All grade levels' ], [ 'Early Childhood', 'Early childhood' ], [ 'Elementary', 'Elementary' ], [ 'Middle', 'Middle' ], [ 'High School', 'High school' ], [ 'K-12', 'K-12 / district-wide' ], [ 'Unspecified', 'Not stated' ] ], 'band' );
		el.appt = select( 'Type', [ [ '', 'Full & part time' ] ].concat( unique( data.vacancies.map( ( v ) => v.appt ) ).map( ( a ) => [ a, a ] ) ), 'appt' );
		el.posted = select( 'Posted', [ [ '', 'Posted any time' ], [ '7', 'Posted in last 7 days' ], [ '30', 'Posted in last 30 days' ] ], 'posted' );
		el.kind = select( 'Job type', [ [ 'jobs', 'Teaching jobs' ], [ 'subs', 'All substitute jobs' ] ].concat( Object.entries( data.subKinds ).map( ( [ k, label ] ) => [ k, label ] ) ).concat( [ [ 'all', 'Teaching + substitute' ] ] ), 'kind' );
		el.agency = select( 'Staffing agency', [ [ '', 'Any staffing agency' ] ].concat( Object.entries( data.agencies ) ), 'agency' );
		el.mydegree = select( 'My highest degree', [ [ '', 'Any degree requirement' ], [ 'high_school', 'I have a high school diploma' ], [ 'associate', 'I have an associate degree' ], [ 'bachelor', 'I have a bachelor’s degree' ], [ 'master', 'I have a master’s degree' ] ], 'mydegree' );
		el.license = licenseSelect();
		el.pay = select( 'Pay', [ [ '', 'Any pay' ], [ 'listed', 'Pay amount listed' ], [ 'daily', 'Daily or hourly rate' ], [ 'salary', 'Annual salary' ] ], 'pay' );
		el.fit = profile ? select( 'Your fit', [ [ '', 'Any fit' ], [ 'maybe', 'Fits me or might' ], [ 'yes', 'Only jobs I qualify for' ] ], 'fit' ) : null;
		el.minday = h( 'input', { type: 'number', min: '0', step: '5', inputmode: 'numeric', class: 'mt-input mt-minday', placeholder: 'Min $/day', 'aria-label': 'Minimum pay per day (daily equivalent)' } );
		el.minday.addEventListener( 'input', debounce( () => set( { minday: el.minday.value } ), 400 ) );
		el.reset = h( 'button', { class: 'mt-btn mt-btn-ghost', type: 'button' }, 'Clear filters' );
		el.reset.addEventListener( 'click', () => set( Object.assign( {}, DEFAULTS, { kind: state.kind, sort: state.sort, dir: state.dir } ) ) );
		el.employerChip = h( 'span', { class: 'mt-chips' } );

		// The filters most people use stay in view; the rest fold away, with a count of the ones set.
		el.moreBody = h( 'div', { class: 'mt-more-body', id: 'mt-more-filters' }, el.agency, el.license, el.subject, el.band, el.appt, el.posted, el.pay );
		el.moreBody.hidden = ! MORE_KEYS.some( ( k ) => state[ k ] );
		el.moreBtn = h( 'button', { type: 'button', class: 'mt-btn mt-more', 'aria-controls': 'mt-more-filters' } );
		el.moreBtn.addEventListener( 'click', () => {
			el.moreBody.hidden = ! el.moreBody.hidden;
			syncControls();
		} );
		const filters = h( 'section', { class: 'mt-filters', 'aria-label': 'Filters' },
			el.q, el.kind, el.fit, el.county, profile ? '' : el.mydegree, el.minday, el.moreBtn, el.employerChip, el.reset, el.moreBody );
		if ( profile ) {
			el.moreBody.append( el.mydegree ); // The profile's degree already drives "Your fit".
		}

		el.subjectChart = h( 'div', { class: 'mt-chart' } );
		el.employerChart = h( 'div', { class: 'mt-chart' } );
		el.weekChart = h( 'div', { class: 'mt-chart' } );
		el.overview = h( 'details', { class: 'mt-overview' },
			h( 'summary', {}, 'Market overview', h( 'span', { class: 'mt-muted' }, ' — postings by subject, employer and week posted' ) ),
			h( 'div', { class: 'mt-charts' },
				card( 'By subject', 'Click a bar to filter', el.subjectChart ),
				card( 'Top employers', 'Click a bar to filter', el.employerChart ),
				card( 'Open postings by week posted', 'Last 12 weeks; older postings that are still open sit in the first column', el.weekChart ) ) );
		el.overview.open = remembered( OVERVIEW_KEY ) === '1';
		el.overview.addEventListener( 'toggle', () => {
			remember( OVERVIEW_KEY, el.overview.open ? '1' : '0' );
			if ( el.overview.open ) {
				renderCharts( filtered() );
			}
		} );

		el.count = h( 'p', { class: 'mt-count' } );
		el.thead = h( 'tr' );
		COLUMNS.forEach( ( c ) => {
			const btn = h( 'button', { type: 'button', class: 'mt-sort' }, c.label );
			btn.addEventListener( 'click', () => set( { sort: c.key, dir: state.sort === c.key && state.dir === 'desc' ? 'asc' : 'desc' } ) );
			el.thead.append( h( 'th', { scope: 'col', 'data-key': c.key }, btn ) );
		} );
		el.tbody = h( 'tbody' );
		const table = h( 'div', { class: 'mt-card mt-table-card' },
			el.count,
			h( 'div', { class: 'mt-table-wrap' }, h( 'table', { class: 'mt-table' }, h( 'thead', {}, el.thead ), el.tbody ) ) );

		el.csv = h( 'button', { class: 'mt-btn mt-btn-ghost', type: 'button' }, 'Download these postings (CSV)' );
		el.csv.addEventListener( 'click', downloadCsv );
		el.foot = h( 'footer', { class: 'mt-foot' } );
		root.replaceChildren( el.intro, el.kpis, filters, el.overview, table, el.foot );
		syncControls();
	}

	/** License areas grouped by category (regular education, special education, …). */
	function licenseSelect() {
		const names = { regular: 'Regular education', subject: 'Subject areas', special_education: 'Special education', bilingual: 'Bilingual & ESL', specialist: 'Specialists', pupil_services: 'Pupil services', substitute: 'Substitute licenses', other: 'Other' };
		const sel = h( 'select', { class: 'mt-input', 'aria-label': 'License area' }, h( 'option', { value: '' }, 'Any license area' ) );
		const groups = {};
		Object.entries( data.licenseAreas || {} ).forEach( ( [ key, a ] ) => {
			const cat = names[ a.category ] ? a.category : 'other';
			groups[ cat ] = groups[ cat ] || h( 'optgroup', { label: names[ cat ] } );
			groups[ cat ].append( h( 'option', { value: key }, a.label ) );
		} );
		Object.keys( names ).forEach( ( cat ) => groups[ cat ] && sel.append( groups[ cat ] ) );
		sel.addEventListener( 'change', () => set( { license: sel.value } ) );
		return sel;
	}

	function select( label, options, key ) {
		const s = h( 'select', { class: 'mt-input', 'aria-label': label } );
		options.forEach( ( [ value, text ] ) => s.append( h( 'option', { value }, text ) ) );
		s.addEventListener( 'change', () => set( { [ key ]: s.value } ) );
		return s;
	}

	function syncControls() {
		if ( ! el.q ) {
			return;
		}
		if ( document.activeElement !== el.q ) {
			el.q.value = state.q;
		}
		[ 'kind', 'agency', 'mydegree', 'license', 'county', 'subject', 'band', 'appt', 'posted', 'pay', 'fit' ].forEach( ( k ) => {
			if ( el[ k ] ) {
				el[ k ].value = state[ k ];
			}
		} );
		if ( document.activeElement !== el.minday ) {
			el.minday.value = state.minday;
		}
		const more = MORE_KEYS.concat( profile ? [ 'mydegree' ] : [] ).filter( ( k ) => state[ k ] ).length;
		el.moreBtn.textContent = 'More filters' + ( more ? ' (' + more + ')' : '' ) + ( el.moreBody.hidden ? ' ▾' : ' ▴' );
		el.moreBtn.setAttribute( 'aria-expanded', String( ! el.moreBody.hidden ) );
		syncView();
	}

	/** Which nav tab is current, and the heading, follow the job type in the hash. */
	function syncView() {
		const view = state.kind === 'jobs' ? 'jobs' : ( state.kind === 'all' ? 'all' : 'subs' );
		document.querySelectorAll( '.mt-nav a[data-view]' ).forEach( ( a ) => {
			if ( a.dataset.view === view ) {
				a.setAttribute( 'aria-current', 'page' );
			} else {
				a.removeAttribute( 'aria-current' );
			}
		} );
		const title = document.getElementById( 'mt-title' );
		const scope = document.getElementById( 'mt-scope' );
		const counties = scope ? scope.dataset.counties : '';
		const text = {
			jobs: [ 'Teaching jobs', 'Open K-12 teaching positions in ' + counties + ' counties, Wisconsin.' ],
			subs: [ 'Substitute teaching jobs', 'Open substitute postings in ' + counties + ' counties, Wisconsin.' ],
			all: [ 'Teaching and substitute jobs', 'Every open K-12 teaching and substitute posting in ' + counties + ' counties, Wisconsin.' ],
		}[ view ];
		if ( title ) {
			title.textContent = text[ 0 ];
		}
		if ( scope ) {
			scope.textContent = text[ 1 ];
		}
	}

	/* ---------- render ---------- */

	function render() {
		const rows = filtered();
		renderFreshness();
		renderIntro();
		renderKpis( rows );
		renderEmployerChip();
		if ( el.overview.open ) {
			renderCharts( rows );
		}
		renderTable( rows );
		renderFoot();
	}

	/** Charts are drawn only while the overview is open. */
	function renderCharts( rows ) {
		barChart( el.subjectChart, countBy( filtered( 'subject' ), 'subject' ), state.subject, ( v ) => set( { subject: state.subject === v ? '' : v } ), 'subject' );
		barChart( el.employerChart, countBy( filtered( 'employer' ), 'employer' ).slice( 0, 10 ), state.employer, ( v ) => set( { employer: state.employer === v ? '' : v } ), 'employer' );
		weekChart( el.weekChart, rows );
	}

	/**
	 * In the substitute views: what the three kinds of postings are, and that daily sub work itself
	 * is never posted publicly, so a reader doesn't take this list for all the work there is.
	 */
	function renderIntro() {
		el.intro.hidden = state.kind === 'jobs' || state.kind === 'all';
		if ( el.intro.hidden ) {
			return;
		}
		const base = filtered( 'kind' );
		const about = {
			daily: 'Join a district’s or agency’s sub pool; the daily jobs come after you’re onboarded.',
			building: 'One school, every day, usually all year.',
			long_term: 'One classroom for weeks or months. A permit covers 45 consecutive days; longer needs a license in the subject.',
		};
		const kinds = Object.keys( about ).map( ( k ) => {
			const n = base.filter( ( v ) => v.subKind === k ).length;
			const pick = h( 'button', { type: 'button', class: 'mt-link-btn', 'aria-pressed': String( state.kind === k ) }, state.kind === k ? 'show all subs' : 'show ' + n );
			pick.addEventListener( 'click', () => set( { kind: state.kind === k ? 'subs' : k } ) );
			return h( 'div', { class: 'mt-intro-kind' + ( state.kind === k ? ' is-active' : '' ) },
				h( 'h2', {}, data.subKinds[ k ], ' ', pick ), h( 'p', {}, about[ k ] ) );
		} );
		const lead = h( 'p', { class: 'mt-intro-lead' }, h( 'strong', {}, 'Daily sub work is never posted publicly.' ), ' Once a district or agency hires you, it offers daily jobs in its absence system (Frontline or Red Rover). What you’ll find here are the ways in: ' );
		let next = '';
		if ( profile ) {
			next = h( 'p', { class: 'mt-intro-next' }, '“Your fit” uses your answers: ' + profile.licenseName + '. ', cfg.pathway ? h( 'a', { href: cfg.pathway + '#license' }, 'Change them' ) : '', '.' );
		} else if ( cfg.pathway ) {
			next = h( 'p', { class: 'mt-intro-next' }, 'New to subbing? ', h( 'a', { href: cfg.pathway }, 'Answer five questions' ), ' to see the license you need, where to apply and which of these postings fit you.' );
		}
		el.intro.replaceChildren( lead, h( 'div', { class: 'mt-intro-kinds' }, ...kinds ), next );
	}

	function renderFreshness() {
		const ok = data.sources.map( ( s ) => s.lastOk ).filter( Boolean ).sort().pop();
		el.freshness.textContent = ok ? ' Updated ' + ago( new Date( ok ) ) + '.' : '';
	}

	function renderKpis( rows ) {
		const weekAgo = today.getTime() - 6 * DAY;
		const soon = today.getTime() + 14 * DAY;
		const tiles = [
			{ label: 'Open postings', value: rows.length, hero: true },
			{ label: 'Posted in the last 7 days', value: rows.filter( ( v ) => v.postedTime >= weekAgo ).length },
			{ label: 'Closing within 14 days', value: rows.filter( ( v ) => v.closeTime && v.closeTime >= today.getTime() && v.closeTime <= soon ).length },
		];
		if ( state.kind === 'jobs' ) {
			tiles.push( { label: 'List their pay', value: rows.filter( ( v ) => v.payBasis === 'posted' ).length, note: 'the rest point to a salary schedule or say nothing' } );
		} else {
			// Median daily-equivalent pay of substitute postings that state an amount, under the other filters.
			const subPay = rows.filter( ( v ) => v.isSub && v.payDay !== null ).map( ( v ) => v.payDay ).sort( ( a, b ) => a - b );
			tiles.push( {
				label: 'Median sub pay per day',
				value: subPay.length ? '$' + Math.round( subPay.length % 2 ? subPay[ ( subPay.length - 1 ) / 2 ] : ( subPay[ subPay.length / 2 - 1 ] + subPay[ subPay.length / 2 ] ) / 2 ) : '—',
				note: subPay.length ? 'from ' + subPay.length + ' posting' + ( subPay.length === 1 ? '' : 's' ) + ' that list pay; each district sets its own rate' : 'none of these postings list pay',
			} );
		}
		el.kpis.replaceChildren( ...tiles.map( ( t ) => h( 'div', { class: 'mt-kpi' + ( t.hero ? ' mt-kpi-hero' : '' ) },
			h( 'span', { class: 'mt-kpi-label' }, t.label ),
			h( 'span', { class: 'mt-kpi-value' }, t.value.toLocaleString() ),
			t.note ? h( 'span', { class: 'mt-kpi-note' }, t.note ) : '' ) ) );
	}

	function renderEmployerChip() {
		if ( ! state.employer ) {
			el.employerChip.replaceChildren();
			return;
		}
		const x = h( 'button', { type: 'button', class: 'mt-chip', 'aria-label': 'Remove employer filter' }, 'Employer: ' + state.employer + ' ×' );
		x.addEventListener( 'click', () => set( { employer: '' } ) );
		el.employerChip.replaceChildren( x );
	}

	function countBy( rows, key ) {
		const m = new Map();
		rows.forEach( ( v ) => m.set( v[ key ] || '—', ( m.get( v[ key ] || '—' ) || 0 ) + 1 ) );
		return [ ...m.entries() ].sort( ( a, b ) => b[ 1 ] - a[ 1 ] || a[ 0 ].localeCompare( b[ 0 ] ) );
	}

	/** Horizontal bars; the selected bar keeps the accent and the rest step back to gray. */
	function barChart( host, entries, selected, onPick, noun ) {
		if ( ! entries.length ) {
			host.replaceChildren( h( 'p', { class: 'mt-empty' }, 'No postings match.' ) );
			return;
		}
		const max = Math.max( ...entries.map( ( e ) => e[ 1 ] ) );
		const list = h( 'ol', { class: 'mt-bars' + ( selected ? ' has-selection' : '' ) } );
		entries.forEach( ( [ name, n ] ) => {
			const pct = Math.max( 2, ( n / max ) * 100 );
			const b = h( 'button', {
				type: 'button',
				class: 'mt-bar' + ( name === selected ? ' is-selected' : '' ),
				'aria-pressed': String( name === selected ),
				title: name + ': ' + n + ' posting' + ( n === 1 ? '' : 's' ) + ' — ' + ( name === selected ? 'click to clear' : 'click to filter by this ' + noun ),
			},
			h( 'span', { class: 'mt-bar-name' }, name ),
			h( 'span', { class: 'mt-bar-track' }, h( 'span', { class: 'mt-bar-fill', style: 'width:' + pct.toFixed( 1 ) + '%' } ) ),
			h( 'span', { class: 'mt-bar-value' }, String( n ) ) );
			b.addEventListener( 'click', () => onPick( name ) );
			list.append( h( 'li', {}, b ) );
		} );
		host.replaceChildren( list );
	}

	/** Columns per week (Monday start) for the last 12 weeks; anything older folds into the first. */
	function weekChart( host, rows ) {
		const monday = new Date( today );
		monday.setDate( monday.getDate() - ( ( monday.getDay() + 6 ) % 7 ) );
		const weeks = [];
		for ( let i = 11; i >= 0; i-- ) {
			const start = new Date( monday );
			start.setDate( start.getDate() - i * 7 );
			weeks.push( { start: start.getTime(), n: 0 } );
		}
		rows.forEach( ( v ) => {
			if ( ! v.postedTime ) {
				return;
			}
			let idx = weeks.findIndex( ( w, i ) => v.postedTime >= w.start && ( i === weeks.length - 1 || v.postedTime < weeks[ i + 1 ].start ) );
			if ( idx < 0 ) {
				idx = 0;
			}
			weeks[ idx ].n++;
		} );
		const max = Math.max( 1, ...weeks.map( ( w ) => w.n ) );
		const W = 360;
		const H = 150;
		const padL = 28;
		const padB = 22;
		const padT = 14;
		const slot = ( W - padL ) / weeks.length;
		const bw = Math.min( 18, slot - 6 );
		const plotH = H - padB - padT;
		const ticks = niceTicks( max );
		const svg = s( 'svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'mt-svg', role: 'img', 'aria-label': 'Open postings by week posted' } );
		ticks.forEach( ( t ) => {
			const y = padT + plotH - ( t / ticks[ ticks.length - 1 ] ) * plotH;
			svg.append( s( 'line', { x1: padL, x2: W, y1: y, y2: y, class: t === 0 ? 'mt-axis' : 'mt-grid' } ) );
			svg.append( s( 'text', { x: padL - 6, y: y + 3, class: 'mt-tick', 'text-anchor': 'end' }, String( t ) ) );
		} );
		const top = ticks[ ticks.length - 1 ];
		weeks.forEach( ( w, i ) => {
			const x = padL + i * slot + ( slot - bw ) / 2;
			const bh = ( w.n / top ) * plotH;
			const y = padT + plotH - bh;
			const g = s( 'g', { class: 'mt-col', tabindex: '0' } );
			const label = ( i === 0 ? 'Through week of ' : 'Week of ' ) + fmtDate( new Date( w.start ) ) + ': ' + w.n + ' open posting' + ( w.n === 1 ? '' : 's' );
			g.append( s( 'title', {}, label ) );
			g.append( s( 'rect', { x: padL + i * slot, y: padT, width: slot, height: plotH, class: 'mt-hit' } ) );
			if ( w.n ) {
				g.append( s( 'path', { d: roundedTop( x, y, bw, bh, Math.min( 4, bh ) ), class: 'mt-col-fill' + ( i === weeks.length - 1 ? ' is-current' : '' ) } ) );
			}
			if ( i % 3 === 2 || i === weeks.length - 1 ) {
				const d = new Date( w.start );
				svg.append( s( 'text', { x: x + bw / 2, y: H - 6, class: 'mt-tick', 'text-anchor': 'middle' }, ( d.getMonth() + 1 ) + '/' + d.getDate() ) );
			}
			svg.append( g );
		} );
		host.replaceChildren( svg );
	}

	function renderTable( rows ) {
		const total = data.vacancies.filter( kindMatch ).length;
		el.count.textContent = 'Showing ' + rows.length.toLocaleString() + ' of ' + total.toLocaleString() + ' postings';
		[ ...el.thead.children ].forEach( ( th ) => {
			const on = th.dataset.key === state.sort;
			th.setAttribute( 'aria-sort', on ? ( state.dir === 'asc' ? 'ascending' : 'descending' ) : 'none' );
		} );
		if ( ! rows.length ) {
			el.tbody.replaceChildren( h( 'tr', {}, h( 'td', { colspan: COLUMNS.length, class: 'mt-empty' }, 'No postings match these filters.' ) ) );
			return;
		}
		const frag = document.createDocumentFragment();
		sorted( rows ).forEach( ( v ) => {
			const isNew = v.postedTime >= today.getTime() - 6 * DAY;
			const closes = v.closeDate ? 'Closes ' + fmtDate( parseDate( v.closeDate ) ) : ( v.close ? 'Open ' + v.close.toLowerCase() : '' );
			const tr = h( 'tr', { class: 'mt-row' + ( openKey === v.key ? ' is-open' : '' ), tabindex: '0', 'aria-expanded': String( openKey === v.key ) },
				h( 'td', { 'data-label': 'ID', class: 'mt-num' }, v.id || '—' ),
				h( 'td', { 'data-label': 'Posted', class: 'mt-num', title: v.postedSeen ? 'The board shows no posting date; this is when the dashboard first saw it' : '' }, v.postedTime ? fmtDate( parseDate( v.posted ) ) + ( v.postedSeen ? '*' : '' ) : '—' ),
				h( 'td', { 'data-label': 'Position', class: 'mt-title' }, h( 'span', {}, v.title, v.isSub ? h( 'span', { class: 'mt-badge mt-badge-sub' }, data.subKinds[ v.subKind ] ) : '', isNew ? h( 'span', { class: 'mt-badge' }, 'New' ) : '' ),
					// Phones show the row as a card: this summary stands in for the other cells, which are hidden there.
					h( 'span', { class: 'mt-row-meta' },
						v.fit ? h( 'span', {}, fitBadge( v ), ' ', v.fit.rank < 2 ? v.fit.text : '' ) : '',
						h( 'span', {}, [ v.employer, v.county ? v.county + ' County' : '' ].filter( Boolean ).join( ' · ' ) ),
						v.pay || closes ? h( 'span', {}, [ v.pay ? 'Pay ' + v.pay : '', closes ].filter( Boolean ).join( ' · ' ) ) : '',
						v.reqSummary ? h( 'span', {}, 'Needs ' + v.reqSummary ) : '',
						h( 'span', { class: 'mt-muted' }, [ v.id ? 'ID ' + v.id : '', v.postedTime ? 'Posted ' + fmtDate( parseDate( v.posted ) ) : '', v.agency !== 'direct' ? 'Agency: ' + v.agencyLabel : '' ].filter( Boolean ).join( ' · ' ) ) ) ),
				v.fit ? h( 'td', { 'data-label': 'Your fit' }, h( 'span', {}, fitBadge( v ), v.fit.rank < 2 ? h( 'span', { class: 'mt-fit-why' }, v.fit.text ) : '' ) ) : '',
				h( 'td', { 'data-label': 'Subject' }, v.subject ),
				h( 'td', { 'data-label': 'Requirements', title: v.licenseText }, v.reqSummary || '—' ),
				h( 'td', { 'data-label': 'Employer' }, v.employer ),
				h( 'td', { 'data-label': 'Staffing agency', title: v.agencyNote || '', class: v.agency === 'unknown' ? 'mt-muted' : '' }, v.agencyLabel ),
				h( 'td', { 'data-label': 'School' }, v.school || '—' ),
				h( 'td', { 'data-label': 'County' }, v.county ),
				h( 'td', { 'data-label': 'Type' }, v.appt || '—' ),
				h( 'td', { 'data-label': 'Pay', class: 'mt-num', title: v.payText || '' }, v.pay || '—' ),
				h( 'td', { 'data-label': 'Starts' }, v.start || '—' ),
				h( 'td', { 'data-label': 'Closes', class: closingClass( v ) }, v.closeDate ? fmtDate( parseDate( v.closeDate ) ) : ( v.close || '—' ) ) );
			const toggle = () => {
				openKey = openKey === v.key ? '' : v.key;
				renderTable( filtered() );
			};
			tr.addEventListener( 'click', ( e ) => {
				if ( ! e.target.closest( 'a' ) ) {
					toggle();
				}
			} );
			tr.addEventListener( 'keydown', ( e ) => {
				if ( e.key === 'Enter' || e.key === ' ' ) {
					e.preventDefault();
					toggle();
				}
			} );
			frag.append( tr );
			if ( openKey === v.key ) {
				frag.append( h( 'tr', { class: 'mt-detail-row' }, h( 'td', { colspan: COLUMNS.length }, detail( v ) ) ) );
			}
		} );
		el.tbody.replaceChildren( frag );
	}

	function detail( v ) {
		const facts = h( 'dl', { class: 'mt-facts' } );
		const add = ( k, val ) => {
			if ( val ) {
				facts.append( h( 'dt', {}, k ), h( 'dd', {}, val ) );
			}
		};
		if ( v.fit ) {
			facts.append( h( 'dt', {}, 'Your fit' ), h( 'dd', {}, fitBadge( v ), ' ', v.fit.text, '. ', h( 'span', { class: 'mt-muted' }, 'From your answers on the Become a substitute page; check the posting before applying.' ) ) );
		}
		// Shown on phones only, where these table cells are folded into the card.
		[ [ 'School', v.school ], [ 'Subject', v.subject ], [ 'Type', v.appt ], [ 'Starts', v.start ] ].forEach( ( [ k, val ] ) => {
			if ( val ) {
				facts.append( h( 'dt', { class: 'mt-phone-only' }, k ), h( 'dd', { class: 'mt-phone-only' }, val ) );
			}
		} );
		add( 'Staffing agency', v.agencyBasis === 'direct' ? '' : v.agencyLabel + ( v.agencyNote ? ' — ' + v.agencyNote : '' ) );
		add( 'Pay as posted', v.payText );
		qualify( v, facts );
		add( 'Daily equivalent', v.payDay !== null && v.payUnit !== 'day' ? '≈ $' + Math.round( v.payDay ) + '/day (' + ( v.payUnit === 'hour' ? '7-hour day' : '180-day year' ) + ')' : '' );
		add( 'Openings', v.openings );
		add( 'Grade level', v.band !== 'Unspecified' ? v.band : '' );
		const c = v.contact || {};
		const contact = [ c.name, c.title ].filter( Boolean ).join( ', ' );
		if ( contact || c.email || c.phone ) {
			const dd = h( 'dd', {}, contact );
			if ( c.email ) {
				dd.append( contact ? ' · ' : '', h( 'a', { href: 'mailto:' + c.email }, c.email ) );
			}
			if ( c.phone ) {
				dd.append( ' · ' + c.phone );
			}
			facts.append( h( 'dt', {}, 'Contact' ), dd );
		}
		add( 'Posted', v.postedSeen ? ( v.postedTime ? 'Not stated on the board; first seen here ' + fmtDate( parseDate( v.posted ) ) : 'Not stated on the board' ) : '' );
		add( 'Listed on', [ sourceLabel( v.source ) ].concat( ( v.alsoAt || [] ).map( ( a ) => sourceLabel( a.source ) ) ).join( ', ' ) );
		const links = h( 'p', { class: 'mt-links' } );
		if ( safeUrl( v.apply ) ) {
			links.append( h( 'a', { class: 'mt-btn mt-btn-primary', href: v.apply, target: '_blank', rel: 'noopener' }, 'Apply on ' + sourceLabel( v.source ) ) );
		}
		if ( safeUrl( v.url ) && v.url !== v.apply ) {
			links.append( h( 'a', { class: 'mt-btn', href: v.url, target: '_blank', rel: 'noopener' }, 'View full posting' ) );
		}
		( v.alsoAt || [] ).forEach( ( a ) => {
			if ( safeUrl( a.url ) ) {
				links.append( h( 'a', { class: 'mt-btn', href: a.url, target: '_blank', rel: 'noopener' }, 'Also on ' + sourceLabel( a.source ) ) );
			}
		} );
		return h( 'div', { class: 'mt-detail' },
			facts,
			v.source === 'wecan' && ! v.licenses.length && ! v.payText ? h( 'p', { class: 'mt-muted' }, 'Pay and license details are read from WECAN on the next refresh.' ) : '',
			v.summary ? h( 'p', { class: 'mt-summary' }, v.summary ) : '',
			links );
	}

	/** "To qualify": degree, licenses (any one of), experience, specializations, what to submit, other routes. */
	function qualify( v, facts ) {
		const r = v.req;
		if ( ! r || ( ! r.degree && ! r.licenses.length ) ) {
			return;
		}
		const basis = { posted: 'as posted', text: 'stated in the posting', rule: 'Wisconsin rule for this kind of job', inferred: 'typical for this job — the posting doesn’t list licenses' };
		const row = ( label, node ) => facts.append( h( 'dt', {}, label ), h( 'dd', {}, node ) );
		row( 'Degree required', h( 'span', {}, data.degrees[ r.degree ] || '—', h( 'span', { class: 'mt-muted' }, ' (' + ( basis[ r.degreeBasis ] || '' ) + ')' ), r.degreeNote ? h( 'span', { class: 'mt-muted' }, ' ' + r.degreeNote ) : '' ) );
		if ( r.licenses.length ) {
			const list = h( 'ul', { class: 'mt-lic-list' } );
			r.licenses.forEach( ( l ) => list.append( h( 'li', {}, l.title, l.levels ? h( 'span', { class: 'mt-muted' }, ' · ' + l.levels ) : '', l.codes && l.codes.length ? h( 'span', { class: 'mt-muted' }, ' · DPI ' + l.codes.join( ', ' ) ) : '' ) ) );
			row( r.licenses.length > 1 ? 'Licenses (any one)' : 'License', h( 'span', {}, list, h( 'span', { class: 'mt-muted' }, basis[ r.licenseBasis ] || '' ) ) );
		}
		if ( r.experience !== null && r.experience !== undefined ) {
			row( 'Experience', r.experience ? r.experience + '+ year' + ( r.experience === 1 ? '' : 's' ) : 'None required' );
		}
		if ( r.specializations && r.specializations.length ) {
			row( 'Specialization', r.specializations.join( ', ' ) );
		}
		if ( r.materials && r.materials.length ) {
			row( 'Submit', r.materials.join( ', ' ) );
		}
		if ( r.routes && r.routes.length ) {
			row( 'Other ways in', h( 'span', {}, ...r.routes.map( ( t, i ) => ( i ? ' ' : '' ) + t ) ) );
		}
		if ( r.quote ) {
			row( 'Posting says', '“' + r.quote + '”' );
		}
	}

	/**
	 * Sources and how fresh they are. Readers see a plain note when a board hasn't updated in a
	 * while; the error itself is for the settings screen (and signed-in managers here).
	 */
	function fitBadge( v ) {
		return h( 'span', { class: 'mt-fit mt-fit-' + v.fit.rank }, FIT_LABELS[ v.fit.rank ] );
	}

	function renderFoot() {
		const parts = [ 'Source: ' ];
		const stale = [];
		data.sources.forEach( ( src, i ) => {
			if ( i ) {
				parts.push( ', ' );
			}
			parts.push( safeUrl( src.url ) ? h( 'a', { href: src.url, target: '_blank', rel: 'noopener' }, src.label ) : src.label );
			if ( src.lastError && cfg.settings ) {
				parts.push( h( 'span', { class: 'mt-warn' }, ' (last refresh failed: ' + src.lastError + ' — showing the last good list)' ) );
			}
			if ( ! src.lastOk || Date.now() - new Date( src.lastOk ).getTime() > STALE_MS ) {
				stale.push( src.label + ( src.lastOk ? ' last updated ' + ago( new Date( src.lastOk ) ) : ' not read yet' ) );
			}
		} );
		parts.push( '. Counties are matched from NCES district and school directories. Always confirm details on the original posting before applying.' );
		if ( cfg.settings && data.unplaced ) {
			parts.push( ' ', h( 'a', { href: cfg.settings }, data.unplaced + ' employer' + ( data.unplaced === 1 ? '' : 's' ) + ' statewide couldn’t be placed in a county' ), '.' );
		}
		el.foot.replaceChildren(
			stale.length ? h( 'p', { class: 'mt-stale' }, 'Some listings may be out of date: ' + stale.join( '; ' ) + '. Open the posting to check it’s still listed.' ) : '',
			h( 'p', {}, ...parts ),
			el.csv );
	}

	/* ---------- CSV ---------- */

	function downloadCsv() {
		const cols = [ [ 'ID', 'id' ], [ 'Posted', 'posted' ], [ 'Position', 'title' ], [ 'Subject', 'subject' ], [ 'Grade level', 'band' ], [ 'Employer', 'employer' ], [ 'Staffing agency', 'agencyLabel' ], [ 'School', 'school' ], [ 'County', 'county' ], [ 'Type', 'appt' ], [ 'Substitute kind', 'subKindLabel' ], [ 'Starts', 'start' ], [ 'Closes', 'close' ], [ 'Pay', 'pay' ], [ 'Pay per day (approx.)', 'payDay' ], [ 'Pay as posted', 'payText' ], [ 'Degree required', 'degreeLabel' ], [ 'Licenses (any one)', 'licenseText' ], [ 'Experience (years)', 'expYears' ], [ 'Requirements source', 'reqBasis' ], [ 'Posting URL', 'url' ] ];
		const esc = ( x ) => {
			let t = String( x ?? '' );
			if ( /^[=+\-@\t\r]/.test( t ) ) {
				t = "'" + t; // Keep spreadsheets from running cell text as a formula.
			}
			return /[",\n]/.test( t ) ? '"' + t.replace( /"/g, '""' ) + '"' : t;
		};
		const lines = [ cols.map( ( c ) => c[ 0 ] ).join( ',' ) ].concat( sorted( filtered() ).map( ( v ) => cols.map( ( c ) => esc( v[ c[ 1 ] ] ) ).join( ',' ) ) );
		const a = h( 'a', { href: URL.createObjectURL( new Blob( [ '﻿' + lines.join( '\r\n' ) ], { type: 'text/csv;charset=utf-8' } ) ), download: 'teaching-vacancies-' + isoDay( today ) + '.csv' } );
		document.body.append( a );
		a.click();
		setTimeout( () => {
			URL.revokeObjectURL( a.href );
			a.remove();
		}, 0 );
	}

	/* ---------- state in the URL hash, so a filtered view can be bookmarked or shared ---------- */

	function readHash() {
		const p = new URLSearchParams( location.hash.slice( 1 ) );
		const out = Object.assign( {}, DEFAULTS );
		Object.keys( DEFAULTS ).forEach( ( k ) => {
			if ( p.has( k ) ) {
				out[ k ] = p.get( k );
			}
		} );
		if ( p.get( 'subs' ) === '1' && ! p.has( 'kind' ) ) {
			out.kind = 'all'; // Links saved before 1.1.0 used subs=1.
		}
		return out;
	}

	function set( changes ) {
		if ( changes.agency && changes.agency !== 'direct' && state.kind === 'jobs' && ! ( 'kind' in changes ) ) {
			changes.kind = 'subs'; // Agencies only fill substitute jobs; don't show an empty list.
		}
		state = Object.assign( {}, state, changes );
		const p = new URLSearchParams();
		Object.keys( DEFAULTS ).forEach( ( k ) => {
			if ( state[ k ] !== DEFAULTS[ k ] ) {
				p.set( k, state[ k ] );
			}
		} );
		const hash = p.toString();
		history.replaceState( null, '', hash ? '#' + hash : location.pathname + location.search );
		syncControls();
		render();
	}

	/* ---------- helpers ---------- */

	function h( tag, attrs, ...kids ) {
		const n = document.createElement( tag );
		Object.entries( attrs || {} ).forEach( ( [ k, v ] ) => n.setAttribute( k, v ) );
		kids.forEach( ( k ) => {
			if ( k !== '' && k !== null && k !== undefined && k !== false ) {
				n.append( k );
			}
		} );
		return n;
	}

	function s( tag, attrs, text ) {
		const n = document.createElementNS( 'http://www.w3.org/2000/svg', tag );
		Object.entries( attrs || {} ).forEach( ( [ k, v ] ) => n.setAttribute( k, v ) );
		if ( text !== undefined ) {
			n.textContent = text;
		}
		return n;
	}

	function card( title, sub, body ) {
		return h( 'div', { class: 'mt-card' }, h( 'h2', { class: 'mt-card-title' }, title ), h( 'p', { class: 'mt-card-sub' }, sub ), body );
	}

	function roundedTop( x, y, w, hgt, r ) {
		return 'M' + x + ',' + ( y + hgt ) + 'V' + ( y + r ) + 'Q' + x + ',' + y + ' ' + ( x + r ) + ',' + y + 'H' + ( x + w - r ) + 'Q' + ( x + w ) + ',' + y + ' ' + ( x + w ) + ',' + ( y + r ) + 'V' + ( y + hgt ) + 'Z';
	}

	function niceTicks( max ) {
		const step = [ 1, 2, 5, 10, 20, 25, 50, 100, 200, 250, 500 ].find( ( st ) => max / st <= 4 ) || Math.ceil( max / 4 );
		const out = [];
		for ( let t = 0; t <= max + step - 1 && out.length < 6; t += step ) {
			out.push( t );
		}
		if ( out[ out.length - 1 ] < max ) {
			out.push( out[ out.length - 1 ] + step );
		}
		return out;
	}

	function closingClass( v ) {
		if ( ! v.closeTime ) {
			return '';
		}
		return v.closeTime - today.getTime() <= 7 * DAY ? 'mt-soon' : '';
	}

	function sourceLabel( id ) {
		const src = data.sources.find( ( x ) => x.id === id );
		return src ? src.label : 'the job board';
	}

	function safeUrl( u ) {
		return typeof u === 'string' && /^https?:\/\//i.test( u );
	}

	function unique( list ) {
		return [ ...new Set( list.filter( Boolean ) ) ].sort();
	}

	function parseDate( ymd ) {
		const [ y, m, d ] = ymd.split( '-' ).map( Number );
		return new Date( y, m - 1, d );
	}

	function startOfDay( d ) {
		return new Date( d.getFullYear(), d.getMonth(), d.getDate() );
	}

	function isoDay( d ) {
		return d.getFullYear() + '-' + String( d.getMonth() + 1 ).padStart( 2, '0' ) + '-' + String( d.getDate() ).padStart( 2, '0' );
	}

	function fmtDate( d ) {
		return d.toLocaleDateString( undefined, { month: 'short', day: 'numeric', year: d.getFullYear() === today.getFullYear() ? undefined : 'numeric' } );
	}

	function ago( d ) {
		const mins = Math.round( ( Date.now() - d.getTime() ) / 6e4 );
		if ( mins < 2 ) {
			return 'just now';
		}
		if ( mins < 90 ) {
			return mins + ' minutes ago';
		}
		const hours = Math.round( mins / 60 );
		return hours < 36 ? hours + ' hours ago' : Math.round( hours / 24 ) + ' days ago';
	}

	/** The saved answers, if they name a license the board knows. */
	function readProfile() {
		try {
			const p = JSON.parse( window.localStorage.getItem( 'mmiTeachProfile' ) || 'null' );
			return p && [ 'permit_a', 'permit_b', 'long_term' ].includes( p.license ) && typeof p.licenseName === 'string' ? p : null;
		} catch ( e ) {
			return null;
		}
	}

	/** Browser storage can be off (private mode, blocked site data); these never throw. */
	function remembered( key ) {
		try {
			return window.localStorage.getItem( key );
		} catch ( e ) {
			return null;
		}
	}

	function remember( key, value ) {
		try {
			window.localStorage.setItem( key, value );
		} catch ( e ) {
			// Nothing to do: the page works the same, it just won't remember.
		}
	}

	function debounce( fn, ms ) {
		let t;
		return () => {
			clearTimeout( t );
			t = setTimeout( fn, ms );
		};
	}
}() );

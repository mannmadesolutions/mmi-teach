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
		{ key: 'employer', label: 'Employer' },
		{ key: 'school', label: 'School / building' },
		{ key: 'county', label: 'County' },
		{ key: 'appt', label: 'Type' },
		{ key: 'start', label: 'Starts' },
		{ key: 'closeSort', label: 'Closes' },
	];
	const DEFAULTS = { county: '', subject: '', band: '', appt: '', posted: '', q: '', employer: '', subs: '0', sort: 'posted', dir: 'desc' };

	let data = null;
	let state = readHash();
	let openKey = '';
	const el = {};

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
			window.addEventListener( 'hashchange', () => {
				state = readHash();
				syncControls();
				render();
			} );
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
			v.isSub = v.subject === 'Substitute';
			v.haystack = [ v.title, v.subject, v.employer, v.school, v.county, v.band, ( v.licenses || [] ).join( ' ' ), v.id ].join( ' ' ).toLowerCase();
		} );
		return s;
	}

	/** Postings matching every filter except the ones named in `skip` (for cross-filter counts). */
	function filtered( skip ) {
		const q = state.q.trim().toLowerCase();
		const words = q ? q.split( /\s+/ ) : [];
		const postedSince = state.posted ? today.getTime() - ( Number( state.posted ) - 1 ) * DAY : 0;
		return data.vacancies.filter( ( v ) =>
			( state.subs === '1' || v.isSub === false || state.subject === 'Substitute' ) &&
			( skip === 'county' || ! state.county || v.county === state.county ) &&
			( skip === 'subject' || ! state.subject || v.subject === state.subject ) &&
			( skip === 'employer' || ! state.employer || v.employer === state.employer ) &&
			( ! state.band || v.band === state.band ) &&
			( ! state.appt || v.appt === state.appt ) &&
			( ! postedSince || v.postedTime >= postedSince ) &&
			words.every( ( w ) => v.haystack.includes( w ) )
		);
	}

	function sorted( rows ) {
		const col = COLUMNS.find( ( c ) => c.key === state.sort ) || COLUMNS[ 1 ];
		const key = col.date ? 'postedTime' : col.key;
		const dir = state.dir === 'asc' ? 1 : -1;
		return rows.slice().sort( ( a, b ) => {
			let x = a[ key ];
			let y = b[ key ];
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
		const actions = document.getElementById( 'mt-head-actions' );
		el.csv = h( 'button', { class: 'mt-btn', type: 'button' }, 'Download CSV' );
		el.csv.addEventListener( 'click', downloadCsv );
		actions.replaceChildren( el.csv );
		if ( cfg.settings ) {
			actions.append( h( 'a', { class: 'mt-btn', href: cfg.settings }, 'Settings' ) );
		}

		el.kpis = h( 'section', { class: 'mt-kpis', 'aria-label': 'Summary' } );

		el.q = h( 'input', { type: 'search', class: 'mt-input mt-search', placeholder: 'Search title, school, employer, license…', 'aria-label': 'Search' } );
		el.q.addEventListener( 'input', debounce( () => set( { q: el.q.value } ), 250 ) );
		el.county = select( 'County', [ [ '', 'All counties' ] ].concat( data.counties.map( ( c ) => [ c, c ] ) ), 'county' );
		el.subject = select( 'Subject', [ [ '', 'All subjects' ] ].concat( data.subjects.map( ( s ) => [ s, s ] ) ), 'subject' );
		el.band = select( 'Grade level', [ [ '', 'All grade levels' ], [ 'Early Childhood', 'Early childhood' ], [ 'Elementary', 'Elementary' ], [ 'Middle', 'Middle' ], [ 'High School', 'High school' ], [ 'K-12', 'K-12 / district-wide' ], [ 'Unspecified', 'Not stated' ] ], 'band' );
		el.appt = select( 'Type', [ [ '', 'Full & part time' ] ].concat( unique( data.vacancies.map( ( v ) => v.appt ) ).map( ( a ) => [ a, a ] ) ), 'appt' );
		el.posted = select( 'Posted', [ [ '', 'Posted any time' ], [ '7', 'Posted in last 7 days' ], [ '30', 'Posted in last 30 days' ] ], 'posted' );
		el.subs = h( 'input', { type: 'checkbox', id: 'mt-subs' } );
		el.subs.addEventListener( 'change', () => set( { subs: el.subs.checked ? '1' : '0' } ) );
		el.reset = h( 'button', { class: 'mt-btn mt-btn-ghost', type: 'button' }, 'Clear filters' );
		el.reset.addEventListener( 'click', () => set( Object.assign( {}, DEFAULTS, { sort: state.sort, dir: state.dir } ) ) );
		el.employerChip = h( 'span', { class: 'mt-chips' } );

		const filters = h( 'section', { class: 'mt-filters', 'aria-label': 'Filters' },
			el.q, el.county, el.subject, el.band, el.appt, el.posted,
			h( 'label', { class: 'mt-check', for: 'mt-subs' }, el.subs, 'Include substitute postings' ),
			el.employerChip, el.reset );

		el.subjectChart = h( 'div', { class: 'mt-chart' } );
		el.employerChart = h( 'div', { class: 'mt-chart' } );
		el.weekChart = h( 'div', { class: 'mt-chart' } );
		const charts = h( 'section', { class: 'mt-charts' },
			card( 'By subject', 'Click a bar to filter', el.subjectChart ),
			card( 'Top employers', 'Click a bar to filter', el.employerChart ),
			card( 'Open postings by week posted', 'Last 12 weeks; older postings that are still open sit in the first column', el.weekChart ) );

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

		el.foot = h( 'footer', { class: 'mt-foot' } );
		root.replaceChildren( el.kpis, filters, charts, table, el.foot );
		syncControls();
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
		[ 'county', 'subject', 'band', 'appt', 'posted' ].forEach( ( k ) => {
			el[ k ].value = state[ k ];
		} );
		el.subs.checked = state.subs === '1';
	}

	/* ---------- render ---------- */

	function render() {
		const rows = filtered();
		renderFreshness();
		renderKpis();
		renderEmployerChip();
		barChart( el.subjectChart, countBy( filtered( 'subject' ), 'subject' ), state.subject, ( v ) => set( { subject: state.subject === v ? '' : v } ), 'subject' );
		barChart( el.employerChart, countBy( filtered( 'employer' ), 'employer' ).slice( 0, 10 ), state.employer, ( v ) => set( { employer: state.employer === v ? '' : v } ), 'employer' );
		weekChart( el.weekChart, rows );
		renderTable( rows );
		renderFoot();
	}

	function renderFreshness() {
		const ok = data.sources.map( ( s ) => s.lastOk ).filter( Boolean ).sort().pop();
		el.freshness.textContent = ok ? ' Updated ' + ago( new Date( ok ) ) + '.' : '';
	}

	function renderKpis() {
		const base = filtered( 'county' );
		const weekAgo = today.getTime() - 6 * DAY;
		const soon = today.getTime() + 14 * DAY;
		const tiles = [
			{ label: state.county ? 'Open positions, ' + state.county + ' County' : 'Open positions', value: filtered().length, hero: true },
		];
		data.counties.forEach( ( c ) => {
			tiles.push( { label: c + ' County', value: base.filter( ( v ) => v.county === c ).length, county: c } );
		} );
		const rows = filtered();
		tiles.push( { label: 'Posted in last 7 days', value: rows.filter( ( v ) => v.postedTime >= weekAgo ).length } );
		tiles.push( { label: 'Closing within 14 days', value: rows.filter( ( v ) => v.closeTime && v.closeTime >= today.getTime() && v.closeTime <= soon ).length } );

		el.kpis.replaceChildren( ...tiles.map( ( t ) => {
			const attrs = { class: 'mt-kpi' + ( t.hero ? ' mt-kpi-hero' : '' ) + ( t.county && state.county === t.county ? ' is-active' : '' ) };
			const inner = [ h( 'span', { class: 'mt-kpi-label' }, t.label ), h( 'span', { class: 'mt-kpi-value' }, t.value.toLocaleString() ) ];
			if ( ! t.county ) {
				return h( 'div', attrs, ...inner );
			}
			attrs.type = 'button';
			attrs[ 'aria-pressed' ] = String( state.county === t.county );
			attrs.title = state.county === t.county ? 'Show all counties' : 'Show only ' + t.county + ' County';
			const b = h( 'button', attrs, ...inner );
			b.addEventListener( 'click', () => set( { county: state.county === t.county ? '' : t.county } ) );
			return b;
		} ) );
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
		const total = data.vacancies.filter( ( v ) => state.subs === '1' || ! v.isSub ).length;
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
			const tr = h( 'tr', { class: 'mt-row' + ( openKey === v.key ? ' is-open' : '' ), tabindex: '0', 'aria-expanded': String( openKey === v.key ) },
				h( 'td', { 'data-label': 'ID', class: 'mt-num' }, v.id || '—' ),
				h( 'td', { 'data-label': 'Posted', class: 'mt-num', title: v.postedSeen ? 'The board shows no posting date; this is when the dashboard first saw it' : '' }, v.postedTime ? fmtDate( parseDate( v.posted ) ) + ( v.postedSeen ? '*' : '' ) : '—' ),
				h( 'td', { 'data-label': 'Position', class: 'mt-title' }, h( 'span', {}, v.title, isNew ? h( 'span', { class: 'mt-badge' }, 'New' ) : '' ) ),
				h( 'td', { 'data-label': 'Subject' }, v.subject ),
				h( 'td', { 'data-label': 'Employer' }, v.employer ),
				h( 'td', { 'data-label': 'School' }, v.school || '—' ),
				h( 'td', { 'data-label': 'County' }, v.county ),
				h( 'td', { 'data-label': 'Type' }, v.appt || '—' ),
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
		add( 'Salary', v.salary );
		add( 'Openings', v.openings );
		add( 'Grade level', v.band !== 'Unspecified' ? v.band : '' );
		add( 'Degree', v.degree );
		add( 'Licenses', ( v.licenses || [] ).join( ', ' ) );
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
			v.source === 'wecan' && ! v.licenses.length && ! v.salary ? h( 'p', { class: 'mt-muted' }, 'Salary and license details are read from WECAN on the next refresh.' ) : '',
			v.summary ? h( 'p', { class: 'mt-summary' }, v.summary ) : '',
			links );
	}

	function renderFoot() {
		const parts = [ 'Source: ' ];
		data.sources.forEach( ( src, i ) => {
			if ( i ) {
				parts.push( ', ' );
			}
			parts.push( safeUrl( src.url ) ? h( 'a', { href: src.url, target: '_blank', rel: 'noopener' }, src.label ) : src.label );
			if ( src.lastError ) {
				parts.push( h( 'span', { class: 'mt-warn' }, ' (last refresh failed: ' + src.lastError + ' — showing the last good list)' ) );
			}
		} );
		parts.push( '. Counties are matched from NCES district and school directories. Always confirm details on the original posting before applying.' );
		if ( cfg.settings && data.unplaced ) {
			parts.push( ' ', h( 'a', { href: cfg.settings }, data.unplaced + ' employer' + ( data.unplaced === 1 ? '' : 's' ) + ' statewide couldn’t be placed in a county' ), '.' );
		}
		el.foot.replaceChildren( h( 'p', {}, ...parts ) );
	}

	/* ---------- CSV ---------- */

	function downloadCsv() {
		const cols = [ [ 'ID', 'id' ], [ 'Posted', 'posted' ], [ 'Position', 'title' ], [ 'Subject', 'subject' ], [ 'Grade level', 'band' ], [ 'Employer', 'employer' ], [ 'School', 'school' ], [ 'County', 'county' ], [ 'Type', 'appt' ], [ 'Starts', 'start' ], [ 'Closes', 'close' ], [ 'Salary', 'salary' ], [ 'Posting URL', 'url' ] ];
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
		return out;
	}

	function set( changes ) {
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

	function debounce( fn, ms ) {
		let t;
		return () => {
			clearTimeout( t );
			t = setTimeout( fn, ms );
		};
	}
}() );

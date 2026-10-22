/*
 * MMI Teach — "Become a substitute teacher" page: license finder, cost and timeline, the "where to
 * apply" planner, the dated checklist and table sorting. Everything the reader enters stays in
 * their browser (localStorage); the job board reads the same profile to mark postings that fit.
 */
( function () {
	'use strict';

	const P = window.mmiTeachPathway;
	if ( ! P ) {
		return;
	}
	// This page took over the front page in 1.5.0; job-board links saved before that ("/#kind=subs")
	// carry board filters in the hash, so send them on to the board.
	if ( /(^|&)(kind|county|subject|band|appt|posted|q|employer|agency|mydegree|license|fit|pay|minday|sort|dir|subs)=/.test( location.hash.slice( 1 ) ) ) {
		location.replace( P.jobsUrl + location.hash );
		return;
	}
	const WEEK = 7 * 864e5;
	const today = new Date();
	const CHECKS_KEY = 'mmiTeachSubChecklist';
	// Shared with dashboard.js, which reads license, licenseName and degree.
	const PROFILE_KEY = 'mmiTeachProfile';
	const profile = Object.assign( { answers: {}, picks: [], counties: [], others: false, plan: {} }, load( PROFILE_KEY ) );
	const answers = profile.answers;
	const checks = load( CHECKS_KEY );
	const el = {};
	let licenseKey = '';
	let timeline = null;

	/* ---------- 1. Your situation: license finder ---------- */

	const form = document.getElementById( 'mt-wizard' );
	const result = document.getElementById( 'mt-result' );
	const saved = document.getElementById( 'mt-saved' );
	form.hidden = false;
	Object.keys( answers ).forEach( ( name ) => {
		const box = form.querySelector( '[name="' + CSS.escape( name ) + '"][value="' + CSS.escape( String( answers[ name ] ) ) + '"]' );
		if ( box ) {
			box.checked = true;
		}
	} );
	showQuestions();
	licenseKey = pickLicense();
	form.addEventListener( 'change', ( e ) => {
		answers[ e.target.name ] = e.target.value;
		if ( e.target.name === 'licensed' ) {
			delete answers.epp;
			delete answers.degree;
			clear( 'epp' );
			clear( 'degree' );
		}
		if ( e.target.name === 'epp' ) {
			delete answers.degree;
			clear( 'degree' );
		}
		showQuestions();
		licenseKey = pickLicense();
		saveProfile();
		renderResult();
		renderPlan();
	} );
	document.getElementById( 'mt-forget' ).addEventListener( 'click', () => {
		try {
			window.localStorage.removeItem( PROFILE_KEY );
			window.localStorage.removeItem( CHECKS_KEY );
		} catch ( e ) {
			// Storage off: nothing was saved.
		}
		location.reload();
	} );

	function showQuestions() {
		show( 'epp', answers.licensed === 'no' );
		show( 'degree', answers.licensed === 'no' && answers.epp === 'no' );
	}

	function pickLicense() {
		if ( answers.licensed === 'yes' ) {
			return 'long_term';
		}
		if ( answers.epp === 'yes' ) {
			return 'permit_b';
		}
		if ( answers.degree === 'associate' || answers.degree === 'bachelor' ) {
			return 'permit_a';
		}
		return answers.degree === 'none' ? 'none' : '';
	}

	/** The highest degree, in the job board's terms (a posting's required degree is compared with it). */
	function haveDegree() {
		if ( answers.licensed === 'yes' ) {
			return 'bachelor';
		}
		if ( answers.degree === 'associate' || answers.degree === 'bachelor' ) {
			return answers.degree;
		}
		return answers.epp === 'yes' || answers.degree === 'none' ? 'high_school' : '';
	}

	function saveProfile() {
		const usable = licenseKey && licenseKey !== 'none';
		profile.license = usable ? licenseKey : '';
		profile.licenseName = usable ? P.licenses[ licenseKey ].short : '';
		profile.degree = haveDegree();
		store( PROFILE_KEY, profile );
		saved.hidden = false;
	}

	function renderResult() {
		saved.hidden = ! Object.keys( answers ).length;
		if ( ! licenseKey ) {
			result.replaceChildren();
			return;
		}
		const lic = P.licenses[ licenseKey ];
		const card = h( 'div', { class: 'mt-result-card' + ( licenseKey === 'none' ? ' is-none' : '' ) },
			h( 'p', { class: 'mt-result-kicker' }, licenseKey === 'none' ? 'Not yet' : 'Your license' ),
			h( 'h3', {}, lic.name ),
			h( 'p', {}, lic.allows ) );
		if ( licenseKey !== 'none' ) {
			const facts = h( 'ul', { class: 'mt-result-facts' } );
			facts.append( h( 'li', {}, lic.training ? 'Training: one DPI-approved substitute course (see your checklist).' : 'Training: not required for this license.' ) );
			if ( answers.outside ) {
				facts.append( h( 'li', {}, answers.outside === 'yes' ? 'Fingerprints: required — book Fieldprint (code FPWIDPITeach), about $' + P.dpi.fingerprint_fee_estimate + '.' : 'Fingerprints: not required.' ) );
			}
			facts.append( h( 'li', {}, 'DPI fee: $' + P.dpi.fee + ' (credit card only).' ) );
			if ( answers.longterm === 'yes' && licenseKey !== 'long_term' ) {
				facts.append( h( 'li', {}, P.longTerm ) );
			}
			if ( answers.degree === 'associate' ) {
				const needBachelor = Object.values( P.districts ).filter( ( d ) => /bachelor/i.test( d.degree || '' ) ).map( ( d ) => d.name );
				if ( needBachelor.length ) {
					facts.append( h( 'li', {}, 'Heads-up: ' + needBachelor.join( ', ' ) + ' ' + ( needBachelor.length === 1 ? 'requires' : 'require' ) + ' a bachelor’s degree for subs, even though DPI doesn’t. EDUStaff and Kelly Education follow DPI’s associate-degree rule.' ) );
				}
			}
			card.append( facts, h( 'p', { class: 'mt-links' },
				h( 'a', { class: 'mt-btn mt-btn-primary', href: P.jobsUrl + '#kind=subs&fit=maybe' }, 'See substitute jobs that fit you' ),
				h( 'a', { class: 'mt-btn', href: '#plan' }, 'Your cost and timeline' ) ) );
		} else {
			card.append( h( 'p', {}, lic.who ) );
		}
		card.append( h( 'p', { class: 'mt-src' }, h( 'a', { href: lic.source, target: '_blank', rel: 'noopener' }, 'DPI source' ), ' · checked ' + fmtDate( parseDate( lic.verified_on ) ) ) );
		result.replaceChildren( card );
	}

	function show( q, on ) {
		form.querySelector( '[data-q="' + q + '"]' ).hidden = ! on;
	}

	function clear( q ) {
		form.querySelectorAll( '[name="' + q + '"]' ).forEach( ( r ) => {
			r.checked = false;
		} );
	}

	/* ---------- 2. Cost and timeline ---------- */

	const plan = document.getElementById( 'mt-plan' );
	buildPlan();

	function buildPlan() {
		el.path = h( 'select', { class: 'mt-input', id: 'mt-plan-path' },
			h( 'option', { value: 'permit_a' }, 'Permit — degree + training' ),
			h( 'option', { value: 'permit_b' }, 'Permit — educator program (no training)' ),
			h( 'option', { value: 'long_term' }, 'Five-year long-term license' ) );
		el.training = h( 'select', { class: 'mt-input', id: 'mt-plan-training' } );
		P.training.forEach( ( t ) => el.training.append( h( 'option', { value: String( t.id ) }, t.provider + ' — ' + t.cost_label ) ) );
		el.training.value = P.training.some( ( t ) => String( t.id ) === profile.plan.training ) ? profile.plan.training : '2';
		el.prints = h( 'input', { type: 'checkbox', id: 'mt-plan-prints' } );
		el.tb = h( 'input', { type: 'number', min: '0', step: '1', class: 'mt-input mt-plan-tb', id: 'mt-plan-tb', placeholder: '0' } );
		el.tb.value = profile.plan.tb || '';
		[ el.path, el.training, el.prints, el.tb ].forEach( ( c ) => c.addEventListener( 'input', () => {
			profile.plan = { training: el.training.value, tb: el.tb.value };
			store( PROFILE_KEY, profile );
			renderPlan( true );
		} ) );

		el.costs = h( 'div', { class: 'mt-plan-costs' } );
		el.timeline = h( 'div', { class: 'mt-plan-timeline' } );
		const controls = h( 'div', { class: 'mt-plan-controls' },
			field( 'License', 'mt-plan-path', el.path ),
			field( 'Training course', 'mt-plan-training', el.training ),
			field( 'TB test cost, if your employer asks ($)', 'mt-plan-tb', el.tb ),
			h( 'label', { class: 'mt-check', for: 'mt-plan-prints' }, el.prints, 'I need fingerprints (lived outside Wisconsin in the last 20 years)' ) );
		plan.replaceChildren( controls,
			h( 'div', { class: 'mt-plan-grid' },
				h( 'div', {}, h( 'h3', {}, 'What it costs you' ), el.costs ),
				h( 'div', {}, h( 'h3', {}, 'How long until your first day' ), el.timeline ) ) );
	}

	function field( label, id, control ) {
		return h( 'div', { class: 'mt-field' }, h( 'label', { for: id }, label ), control );
	}

	/** @param {boolean} fromControls true when the plan's own controls changed (keep their values). */
	function renderPlan( fromControls ) {
		if ( ! fromControls ) {
			if ( licenseKey && licenseKey !== 'none' ) {
				el.path.value = licenseKey;
			}
			if ( answers.outside ) {
				el.prints.checked = answers.outside === 'yes';
			}
		}
		const path = el.path.value;
		const needsTraining = P.licenses[ path ].training;
		el.training.disabled = ! needsTraining;
		const course = P.training.find( ( t ) => String( t.id ) === el.training.value );
		const prints = el.prints.checked;
		const tb = Math.max( 0, Number( el.tb.value ) || 0 );

		// Costs.
		const lines = [ [ 'DPI license fee', P.dpi.fee ] ];
		let unknown = false;
		if ( needsTraining ) {
			if ( course.cost === null ) {
				unknown = true;
			}
			lines.push( [ 'Training: ' + course.provider, course.cost, course.cost === null ? 'price not posted' : course.cost_label ] );
		}
		if ( prints ) {
			lines.push( [ 'Fingerprints (Fieldprint, estimate)', P.dpi.fingerprint_fee_estimate ] );
		}
		if ( tb ) {
			lines.push( [ 'TB test', tb ] );
		}
		const total = lines.reduce( ( sum, l ) => sum + ( l[ 1 ] || 0 ), 0 );
		const table = h( 'table', { class: 'mt-table mt-cost-table' }, h( 'tbody', {},
			...lines.map( ( l ) => h( 'tr', {}, h( 'td', {}, l[ 0 ] ), h( 'td', { class: 'mt-num' }, l[ 2 ] && l[ 1 ] !== 0 ? ( l[ 1 ] === null ? l[ 2 ] : money( l[ 1 ] ) ) : money( l[ 1 ] ) ) ) ),
			h( 'tr', { class: 'mt-total' }, h( 'td', {}, 'Total' ), h( 'td', { class: 'mt-num' }, money( total ) + ( unknown ? ' + course' : '' ) ) ) ) );
		el.costs.replaceChildren( table, h( 'p', { class: 'mt-muted' }, 'Employers don’t charge to apply. The DPI fee is paid by credit card only; there are no refunds.' ) );

		// Timeline in weeks.
		const train = needsTraining ? trainingWeeks( course ) : 0;
		const range = prints ? P.dpi.processing_weeks_out_of_state : P.dpi.processing_weeks;
		const dpi = range[ 1 ];
		const onboard = P.dpi.employer_onboarding_weeks;
		const serial = [ [ 0, train, 0 ], [ train, train + dpi, 1 ], [ train + dpi, train + dpi + onboard, 2 ] ];
		const parallel = [ [ 0, train, 0 ], [ train, train + dpi, 1 ], [ 0, onboard, 2 ] ];
		const serialEnd = train + dpi + onboard;
		const parallelEnd = Math.max( train + dpi, onboard );
		el.timeline.replaceChildren(
			gantt( [ [ 'One step at a time', serial ], [ 'Employer steps during the DPI wait', parallel ] ], Math.ceil( serialEnd ) ),
			h( 'p', {}, 'About ', h( 'strong', {}, weeks( parallelEnd ) ), ' to your first day if you start employer applications now, versus ', weeks( serialEnd ), ' doing one step at a time (DPI’s upper estimate of ' + dpi + ' weeks).' ),
			h( 'p', { class: 'mt-muted' }, P.dpi.employer_onboarding_note ) );

		timeline = { path, needsTraining, prints, train, dpiMin: range[ 0 ], dpiMax: range[ 1 ] };
		renderChecklist();
	}

	function trainingWeeks( course ) {
		if ( course.dates ) {
			const next = course.dates.map( parseDate ).find( ( d ) => d >= today );
			return next ? Math.max( 0.5, Math.ceil( ( next - today ) / WEEK ) ) : 4;
		}
		return course.weeks || 1;
	}

	/** Horizontal timeline: one row per plan, segments by step, labels on every segment. */
	function gantt( rows, maxWeeks ) {
		const colors = [ 'var(--mt-series-1)', 'var(--mt-series-2)', 'var(--mt-series-3)' ];
		const names = [ 'Training', 'DPI processing', 'Employer onboarding' ];
		const W = 520;
		const labelW = 0;
		const rowH = 44;
		const barH = 16;
		const top = 8;
		const axisH = 22;
		const H = top + rows.length * rowH + axisH;
		const x = ( w ) => labelW + ( w / maxWeeks ) * ( W - labelW - 4 );
		const svg = s( 'svg', { viewBox: '0 0 ' + W + ' ' + H, class: 'mt-svg mt-gantt', role: 'img', 'aria-label': 'Timeline in weeks for two plans' } );
		for ( let w = 0; w <= maxWeeks; w += maxWeeks > 12 ? 2 : 1 ) {
			svg.append( s( 'line', { x1: x( w ), x2: x( w ), y1: top, y2: H - axisH + 4, class: w === 0 ? 'mt-axis' : 'mt-grid' } ) );
			svg.append( s( 'text', { x: x( w ), y: H - 6, class: 'mt-tick', 'text-anchor': 'middle' }, String( w ) ) );
		}
		rows.forEach( ( [ title, segs ], r ) => {
			const y = top + r * rowH + 16;
			svg.append( s( 'text', { x: 0, y: y - 4, class: 'mt-gantt-title' }, title ) );
			// Overlapping steps go on a second lane; a row with two lanes draws both at half height.
			const shown = segs.filter( ( [ a, b ] ) => b > a );
			const lanes = shown.map( ( seg, i ) => ( shown.slice( 0, i ).some( ( o ) => seg[ 0 ] < o[ 1 ] && seg[ 1 ] > o[ 0 ] ) ? 1 : 0 ) );
			const split = lanes.includes( 1 );
			shown.forEach( ( [ a, b, step ], i ) => {
				const hh = split ? barH / 2 - 1 : barH;
				const yy = y + lanes[ i ] * ( barH / 2 + 1 );
				const g = s( 'g', {} );
				g.append( s( 'title', {}, names[ step ] + ': week ' + round( a ) + ' to ' + round( b ) ) );
				g.append( s( 'rect', { x: x( a ) + 1, y: yy, width: Math.max( 2, x( b ) - x( a ) - 2 ), height: hh, rx: 3, fill: colors[ step ] } ) );
				svg.append( g );
			} );
		} );
		const legend = h( 'ul', { class: 'mt-legend' }, ...names.map( ( n, i ) => h( 'li', {}, h( 'span', { class: 'mt-swatch', style: 'background:' + colors[ i ] } ), n ) ) );
		const fig = h( 'figure', { class: 'mt-figure' }, svg, legend, h( 'figcaption', { class: 'mt-muted' }, 'Weeks from today. Overlapping steps are drawn as two thin lines.' ) );
		return fig;
	}


	/* ---------- 3. Where to apply: the fewest applications that cover the reader's picks ---------- */

	const planner = document.getElementById( 'mt-planner' );
	const COUNTIES = [ ...new Set( P.planner.map( ( r ) => r.county ) ) ];
	buildPlanner();

	function buildPlanner() {
		el.countyPick = h( 'fieldset', { class: 'mt-county-pick' }, h( 'legend', {}, 'Where would you sub?' ) );
		COUNTIES.forEach( ( c ) => {
			const box = h( 'input', { type: 'checkbox', value: c } );
			box.checked = profile.counties.includes( c );
			box.addEventListener( 'change', () => {
				profile.counties = COUNTIES.filter( ( x ) => x === c ? box.checked : profile.counties.includes( x ) );
				changed();
			} );
			el.countyPick.append( h( 'label', { class: 'mt-check' }, box, c + ' County' ) );
		} );
		el.others = h( 'input', { type: 'checkbox', id: 'mt-plan-others' } );
		el.others.checked = !! profile.others;
		el.others.addEventListener( 'change', () => {
			profile.others = el.others.checked;
			changed();
		} );
		el.list = h( 'div', { class: 'mt-employer-list' } );
		el.apps = h( 'div', { class: 'mt-planner-plan', 'aria-live': 'polite' } );
		planner.replaceChildren(
			h( 'div', { class: 'mt-planner-pick' }, el.countyPick,
				h( 'label', { class: 'mt-check', for: 'mt-plan-others' }, el.others, 'Also list private and charter schools with open sub postings' ),
				el.list ),
			el.apps );
	}

	function changed() {
		store( PROFILE_KEY, profile );
		renderPlanner();
		renderChecklist();
	}

	function visible( r ) {
		return profile.counties.includes( r.county ) && ( r.public || profile.others );
	}

	function renderPlanner() {
		if ( ! profile.counties.length ) {
			el.list.replaceChildren( h( 'p', { class: 'mt-muted' }, 'Choose a county to list its school districts and how each hires subs.' ) );
		} else {
			el.list.replaceChildren( ...profile.counties.map( ( c ) => {
				const rows = P.planner.filter( ( r ) => r.county === c && visible( r ) );
				const all = h( 'button', { type: 'button', class: 'mt-link-btn' }, rows.every( picked ) ? 'Clear all' : 'Pick all' );
				all.addEventListener( 'click', () => {
					const on = ! rows.every( picked );
					const names = rows.map( ( r ) => r.name );
					profile.picks = profile.picks.filter( ( n ) => ! names.includes( n ) ).concat( on ? names : [] );
					changed();
				} );
				return h( 'div', { class: 'mt-county-group' },
					h( 'h4', {}, c + ' County ', all ),
					h( 'ul', { class: 'mt-employers' }, ...rows.map( employerRow ) ) );
			} ) );
		}
		renderApplications();
	}

	function picked( r ) {
		return profile.picks.includes( r.name );
	}

	function employerRow( r ) {
		const id = 'mt-pick-' + slug( r.name );
		const box = h( 'input', { type: 'checkbox', id } );
		box.checked = picked( r );
		box.addEventListener( 'change', () => {
			profile.picks = profile.picks.filter( ( n ) => n !== r.name ).concat( box.checked ? [ r.name ] : [] );
			changed();
		} );
		const meta = [];
		if ( r.payMin !== null ) {
			meta.push( payRange( [ r ] ) + ' posted' );
		}
		const posts = r.open ? h( 'a', { href: boardLink( r ) }, r.open + ' open sub posting' + ( r.open === 1 ? '' : 's' ) ) : '';
		return h( 'li', {}, box,
			h( 'label', { for: id }, h( 'span', { class: 'mt-emp-name' }, r.name ), ' ', routeBadge( r ) ),
			meta.length || posts ? h( 'span', { class: 'mt-emp-meta' }, meta.join( ' · ' ), meta.length && posts ? ' · ' : '', posts ) : '' );
	}

	function routeBadge( r ) {
		const name = P.agencies[ r.route ] ? P.agencies[ r.route ].name : ( r.route === 'direct' ? 'Hires directly' : 'Route not confirmed' );
		return h( 'span', { class: 'mt-route mt-route-' + ( P.agencies[ r.route ] ? 'agency' : r.route ) }, name + ( r.likely ? ' (likely)' : '' ) );
	}

	/** Picks grouped by how each hires: one application per agency, one per direct employer, the rest to ask about. */
	function groups() {
		const out = { picks: [], agencies: {}, direct: [], unknown: [] };
		P.planner.filter( ( r ) => picked( r ) && visible( r ) ).forEach( ( r ) => {
			out.picks.push( r );
			if ( P.agencies[ r.route ] ) {
				( out.agencies[ r.route ] = out.agencies[ r.route ] || [] ).push( r );
			} else if ( r.route === 'direct' ) {
				out.direct.push( r );
			} else {
				out.unknown.push( r );
			}
		} );
		return out;
	}

	function renderApplications() {
		const g = groups();
		const head = h( 'h3', {}, 'Your applications' );
		if ( ! g.picks.length ) {
			el.apps.replaceChildren( head, h( 'p', { class: 'mt-muted' }, 'Pick the districts and schools you’d sub for, and this lists the fewest applications that cover them.' ) );
			return;
		}
		const n = Object.keys( g.agencies ).length + g.direct.length;
		const covered = g.picks.length - g.unknown.length;
		const lead = h( 'p', {}, h( 'strong', {}, n + ' application' + ( n === 1 ? '' : 's' ) ), ' cover ' + covered + ' of your ' + g.picks.length + ' pick' + ( g.picks.length === 1 ? '' : 's' ) + '.' + ( g.unknown.length ? ' Ask about the other ' + g.unknown.length + ' first (below).' : '' ) );
		const cards = [];
		Object.entries( g.agencies ).forEach( ( [ key, rows ] ) => {
			const a = P.agencies[ key ];
			const more = P.planner.filter( ( r ) => r.route === key && profile.counties.includes( r.county ) && r.public && ! picked( r ) );
			const card = h( 'div', { class: 'mt-app-card' },
				h( 'h4', {}, a.name ),
				h( 'p', {}, h( 'strong', {}, 'One application covers: ' ), rows.map( ( r ) => r.name + ( r.likely ? ' (likely)' : '' ) ).join( ', ' ) ),
				a.process ? h( 'p', { class: 'mt-muted' }, a.process ) : '',
				h( 'p', {}, a.degree ? 'Degree: ' + a.degree + '. ' : '', a.retirement ? 'Retirement: ' + a.retirement + '.' : '' ),
				payLine( rows ) );
			if ( more.length ) {
				const add = h( 'button', { type: 'button', class: 'mt-link-btn' }, 'add ' + ( more.length === 1 ? 'it' : 'them' ) );
				add.addEventListener( 'click', () => {
					profile.picks = profile.picks.concat( more.map( ( r ) => r.name ) );
					changed();
				} );
				card.append( h( 'p', {}, 'The same application also covers ' + more.map( ( r ) => r.name ).join( ', ' ) + ' — ', add, '.' ) );
			}
			card.append( links( [ [ a.apply_url, 'Apply with ' + a.name, true ] ], a.sources, a.verified_on ) );
			cards.push( card );
		} );
		g.direct.forEach( ( r ) => {
			cards.push( h( 'div', { class: 'mt-app-card' },
				h( 'h4', {}, r.name ),
				h( 'p', {}, 'Hires its own subs: apply to the district.' + ( r.degree ? ' ' + r.degree + '.' : '' ) + ' You join WRS, Wisconsin’s public pension, if you’re expected to work 880 or more hours a year.' ),
				r.extras ? h( 'p', { class: 'mt-muted' }, r.extras ) : '',
				payLine( [ r ] ),
				links( [ [ r.apply, 'Apply directly', true ], [ r.open ? boardLink( r ) : '', 'Its sub postings' ], [ ! r.apply && ! r.open ? 'https://wecan.waspa.org/' : '', 'Look for its sub pool on WECAN' ] ], r.source ? [ r.source ] : [], '' ) ) );
		} );
		if ( g.unknown.length ) {
			cards.push( h( 'div', { class: 'mt-app-card is-ask' },
				h( 'h4', {}, 'Ask first' ),
				h( 'p', {}, 'We haven’t confirmed who employs subs here. Call or email each one’s HR office and ask whether to apply to them directly, through EDUStaff or through Kelly Education.' ),
				h( 'ul', {}, ...g.unknown.map( ( r ) => h( 'li', {}, r.name, r.open ? h( 'span', { class: 'mt-muted' }, ' · ', h( 'a', { href: boardLink( r ) }, r.open + ' open sub posting' + ( r.open === 1 ? '' : 's' ) ) ) : '' ) ) ) ) );
		}
		el.apps.replaceChildren( head, lead, ...cards, h( 'p', { class: 'mt-muted' }, 'Your applications are added to your checklist below.' ) );
	}

	function payLine( rows ) {
		const paid = rows.filter( ( r ) => r.payMin !== null );
		return paid.length ? h( 'p', {}, 'Posted pay: ' + payRange( paid ) + ' (each district sets its own rate).' ) : '';
	}

	function payRange( rows ) {
		const lo = Math.min( ...rows.map( ( r ) => r.payMin ) );
		const hi = Math.max( ...rows.map( ( r ) => r.payMax ) );
		return money( Math.round( lo ) ) + ( hi > lo ? '–' + money( Math.round( hi ) ) : '' ) + '/day';
	}

	function links( buttons, sources, verified ) {
		const p = h( 'p', { class: 'mt-links' } );
		buttons.forEach( ( [ url, text, primary ] ) => {
			if ( safeUrl( url ) ) {
				p.append( h( 'a', { class: 'mt-btn' + ( primary ? ' mt-btn-primary' : '' ), href: url, target: url.startsWith( P.jobsUrl ) ? '_self' : '_blank', rel: 'noopener' }, text ) );
			}
		} );
		const src = h( 'span', { class: 'mt-src' } );
		( sources || [] ).forEach( ( u, i ) => safeUrl( u ) && src.append( i ? ' ' : '', h( 'a', { href: u, target: '_blank', rel: 'noopener' }, 'source' + ( sources.length > 1 ? ' ' + ( i + 1 ) : '' ) ) ) );
		if ( verified ) {
			src.append( ' · checked ' + fmtDate( parseDate( verified ) ) );
		}
		p.append( src );
		return p;
	}

	function boardLink( r ) {
		return P.jobsUrl + '#kind=subs&employer=' + encodeURIComponent( r.board || r.name );
	}

	function shortName( name ) {
		return name.replace( /^School District of /, '' ).replace( / (School District|Public Schools|Schools)$/, '' );
	}

	/* ---------- 4. The dated checklist ---------- */

	const tasks = [ ...document.querySelectorAll( '.mt-task' ) ];
	const phase = ( id ) => document.querySelector( '.mt-phase[data-phase="' + id + '"]' );
	const appTasks = document.getElementById( 'mt-app-tasks' );
	const eloTask = document.querySelector( '.mt-task[data-id="elo"]' );
	const eloHome = eloTask.parentNode;
	tasks.forEach( wireTask );
	document.getElementById( 'mt-print' ).addEventListener( 'click', () => window.print() );

	function wireTask( li ) {
		const box = li.querySelector( 'input' );
		box.checked = !! checks[ li.dataset.id ];
		box.addEventListener( 'change', () => {
			checks[ li.dataset.id ] = box.checked;
			store( CHECKS_KEY, checks );
		} );
	}

	function renderChecklist() {
		if ( ! timeline ) {
			return;
		}
		const t = timeline;
		const when = { training: t.needsTraining, prints: t.prints, permit: t.path !== 'long_term' };
		tasks.forEach( ( li ) => {
			li.hidden = !! li.dataset.if && ! when[ li.dataset.if ];
		} );
		// With no course to wait for, applying to DPI happens this week.
		( t.needsTraining ? eloHome : phase( 'now' ).querySelector( '.mt-tasks' ) ).append( eloTask );

		// One task per application from the planner, replacing the generic "apply" task.
		const g = groups();
		const items = [];
		Object.entries( g.agencies ).forEach( ( [ key, rows ] ) => items.push( [ 'app-' + key, 'Apply to ' + P.agencies[ key ].name + ' (covers ' + rows.map( ( r ) => shortName( r.name ) ).join( ', ' ) + ').', P.agencies[ key ].apply_url ] ) );
		g.direct.forEach( ( r ) => items.push( [ 'app-' + slug( r.name ), 'Apply to ' + r.name + '.', r.apply || ( r.open ? boardLink( r ) : '' ) ] ) );
		g.unknown.forEach( ( r ) => items.push( [ 'ask-' + slug( r.name ), 'Ask ' + r.name + '’s HR office who employs its subs, then apply there.', '' ] ) );
		appTasks.replaceChildren( ...items.map( ( [ id, text, url ] ) => {
			const li = h( 'li', { class: 'mt-task', 'data-id': id },
				h( 'input', { type: 'checkbox', id: 'mt-ck-' + id } ),
				h( 'div', {}, h( 'label', { for: 'mt-ck-' + id }, text ), safeUrl( url ) ? h( 'a', { class: 'mt-task-link', href: url, target: url.startsWith( P.jobsUrl ) ? '_self' : '_blank', rel: 'noopener' }, 'Apply →' ) : '' ) );
			wireTask( li );
			return li;
		} ) );
		document.querySelector( '.mt-task[data-id="apply"]' ).hidden = items.length > 0;

		// Phase headings and dates from the reader's timeline.
		const at = ( w ) => new Date( today.getTime() + w * WEEK );
		const renew = at( t.train + t.dpiMax );
		renew.setFullYear( renew.getFullYear() + ( t.path === 'long_term' ? 5 : 3 ) );
		phase( 'now' ).querySelector( 'h3' ).firstChild.textContent = ( t.needsTraining ? 'While you train' : 'This week' ) + ' ';
		phase( 'permit' ).querySelector( 'h3' ).firstChild.textContent = ( t.path === 'long_term' ? 'When your license arrives' : 'When your permit arrives' ) + ' ';
		const dates = {
			today: fmtDate( today ),
			now: t.needsTraining ? 'now to about ' + fmtDate( at( t.train ) ) : 'by ' + fmtDate( at( 1 ) ),
			dpi: 'about ' + fmtDate( at( t.train ) ),
			wait: 'about ' + fmtDate( at( t.train ) ) + ' to ' + fmtDate( at( t.train + t.dpiMax ) ),
			permit: 'expected ' + fmtDate( at( t.train + t.dpiMin ) ) + '–' + fmtDate( at( t.train + t.dpiMax ) ),
			later: 'renew by about ' + fmtDate( renew ),
		};
		document.querySelectorAll( '.mt-phase' ).forEach( ( ph ) => {
			ph.querySelector( '.mt-phase-date' ).textContent = dates[ ph.dataset.phase ] || '';
			ph.hidden = ! ph.querySelector( '.mt-task:not([hidden])' );
		} );
	}

	/* ---------- Links into folded how-tos (#training, #dpi) open them ---------- */

	function openFromHash() {
		const target = location.hash.length > 1 && document.getElementById( location.hash.slice( 1 ) );
		if ( target && target.tagName === 'DETAILS' ) {
			target.open = true;
			target.scrollIntoView();
		}
	}
	window.addEventListener( 'hashchange', openFromHash );
	openFromHash();

	renderResult();
	renderPlan();
	renderPlanner();
	saved.hidden = ! Object.keys( answers ).length;

	/* ---------- Sortable tables ---------- */

	document.querySelectorAll( 'table.mt-sortable' ).forEach( ( table ) => {
		const heads = [ ...table.tHead.rows[ 0 ].cells ];
		heads.forEach( ( th, col ) => {
			const btn = h( 'button', { type: 'button', class: 'mt-sort' }, th.textContent );
			th.replaceChildren( btn );
			th.setAttribute( 'aria-sort', 'none' );
			btn.addEventListener( 'click', () => {
				const dir = th.getAttribute( 'aria-sort' ) === 'ascending' ? 'descending' : 'ascending';
				heads.forEach( ( o ) => o.setAttribute( 'aria-sort', 'none' ) );
				th.setAttribute( 'aria-sort', dir );
				const num = th.dataset.sort === 'num';
				const val = ( tr ) => {
					const cell = tr.cells[ col ];
					const raw = cell.dataset.value !== undefined ? cell.dataset.value : cell.textContent.trim();
					return num ? ( raw === '' ? null : parseFloat( raw ) ) : raw;
				};
				const rows = [ ...table.tBodies[ 0 ].rows ];
				rows.sort( ( a, b ) => {
					const x = val( a );
					const y = val( b );
					if ( x === null || y === null ) {
						return ( x === null ) - ( y === null ); // Blanks last either way.
					}
					const c = num ? x - y : String( x ).localeCompare( String( y ), undefined, { sensitivity: 'base' } );
					return dir === 'ascending' ? c : -c;
				} );
				table.tBodies[ 0 ].append( ...rows );
			} );
		} );
	} );

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

	function money( n ) {
		return '$' + Number( n || 0 ).toLocaleString( undefined, { minimumFractionDigits: n % 1 ? 2 : 0, maximumFractionDigits: 2 } );
	}

	function weeks( n ) {
		const r = round( n );
		return r + ' week' + ( r === 1 ? '' : 's' );
	}

	function round( n ) {
		return Math.round( n * 10 ) / 10;
	}

	function parseDate( ymd ) {
		const [ y, m, d ] = ymd.split( '-' ).map( Number );
		return new Date( y, m - 1, d );
	}

	function fmtDate( d ) {
		return d.toLocaleDateString( undefined, { month: 'short', day: 'numeric', year: 'numeric' } );
	}

	/** Browser storage can be off (private mode, blocked site data); these never throw. */
	function load( key ) {
		try {
			const v = JSON.parse( window.localStorage.getItem( key ) || '{}' );
			return v && typeof v === 'object' ? v : {};
		} catch ( e ) {
			return {};
		}
	}

	function store( key, value ) {
		try {
			window.localStorage.setItem( key, JSON.stringify( value ) );
		} catch ( e ) {
			// The page works the same; it just won't remember.
		}
	}

	function slug( s ) {
		return String( s ).toLowerCase().replace( /[^a-z0-9]+/g, '-' ).replace( /^-|-$/g, '' );
	}

	function safeUrl( u ) {
		return typeof u === 'string' && /^https?:\/\//i.test( u );
	}
}() );

/* MMI Teach — "Become a substitute teacher" page: license finder, plan calculator, saved checklist, table sorting. */
( function () {
	'use strict';

	const P = window.mmiTeachPathway;
	if ( ! P ) {
		return;
	}
	const WEEK = 7 * 864e5;
	const today = new Date();
	const STORE_KEY = 'mmiTeachSubChecklist';
	const answers = {};
	let licenseKey = '';

	/* ---------- 1. License finder ---------- */

	const form = document.getElementById( 'mt-wizard' );
	const result = document.getElementById( 'mt-result' );
	form.hidden = false;
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
		show( 'epp', answers.licensed === 'no' );
		show( 'degree', answers.licensed === 'no' && answers.epp === 'no' );
		licenseKey = pickLicense();
		renderResult();
		renderPlan();
	} );

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

	function renderResult() {
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
			facts.append( h( 'li', {}, lic.training ? 'Training: one DPI-approved substitute course (step 2).' : 'Training: not required for this license.' ) );
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
			// The job board filters by the degree a posting requires, so hand over what the reader has.
			const have = answers.degree || ( licenseKey === 'long_term' ? 'bachelor' : '' );
			const jobs = P.jobsUrl + '#kind=subs' + ( have ? '&mydegree=' + have : '' );
			card.append( facts, h( 'p', { class: 'mt-links' },
				h( 'a', { class: 'mt-btn mt-btn-primary', href: jobs }, 'See substitute jobs you qualify for' ),
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

	/* ---------- 5. Plan: cost, timeline, checklist ---------- */

	const plan = document.getElementById( 'mt-plan' );
	const el = {};
	buildPlan();
	renderPlan();

	function buildPlan() {
		el.path = h( 'select', { class: 'mt-input', id: 'mt-plan-path' },
			h( 'option', { value: 'permit_a' }, 'Permit — degree + training' ),
			h( 'option', { value: 'permit_b' }, 'Permit — educator program (no training)' ),
			h( 'option', { value: 'long_term' }, 'Five-year long-term license' ) );
		el.training = h( 'select', { class: 'mt-input', id: 'mt-plan-training' } );
		P.training.forEach( ( t ) => el.training.append( h( 'option', { value: String( t.id ) }, t.provider + ' — ' + t.cost_label ) ) );
		el.training.value = '2';
		el.prints = h( 'input', { type: 'checkbox', id: 'mt-plan-prints' } );
		el.tb = h( 'input', { type: 'number', min: '0', step: '1', class: 'mt-input mt-plan-tb', id: 'mt-plan-tb', placeholder: '0' } );
		[ el.path, el.training, el.prints, el.tb ].forEach( ( c ) => c.addEventListener( 'input', () => {
			licenseKey = el.path.value;
			renderPlan( true );
		} ) );

		el.costs = h( 'div', { class: 'mt-plan-costs' } );
		el.timeline = h( 'div', { class: 'mt-plan-timeline' } );
		el.checklist = h( 'div', { class: 'mt-checklist' } );
		const controls = h( 'div', { class: 'mt-plan-controls' },
			field( 'License', 'mt-plan-path', el.path ),
			field( 'Training course', 'mt-plan-training', el.training ),
			field( 'TB test cost, if your employer asks ($)', 'mt-plan-tb', el.tb ),
			h( 'label', { class: 'mt-check', for: 'mt-plan-prints' }, el.prints, 'I need fingerprints (lived outside Wisconsin in the last 20 years)' ) );
		plan.replaceChildren( controls,
			h( 'div', { class: 'mt-plan-grid' },
				h( 'div', {}, h( 'h3', {}, 'What it costs you' ), el.costs ),
				h( 'div', {}, h( 'h3', {}, 'How long until your first day' ), el.timeline ) ),
			h( 'h3', {}, 'Your checklist' ),
			h( 'p', { class: 'mt-muted' }, 'Gather each item once and reuse it for every employer. Ticks are saved in this browser only.' ),
			el.checklist );
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
		const dpi = prints ? P.dpi.processing_weeks_out_of_state[ 1 ] : P.dpi.processing_weeks[ 1 ];
		const onboard = P.dpi.employer_onboarding_weeks;
		const serial = [ [ 0, train, 0 ], [ train, train + dpi, 1 ], [ train + dpi, train + dpi + onboard, 2 ] ];
		const parallel = [ [ 0, train, 0 ], [ train, train + dpi, 1 ], [ 0, onboard, 2 ] ];
		const serialEnd = train + dpi + onboard;
		const parallelEnd = Math.max( train + dpi, onboard );
		el.timeline.replaceChildren(
			gantt( [ [ 'One step at a time', serial ], [ 'Employer steps during the DPI wait', parallel ] ], Math.ceil( serialEnd ) ),
			h( 'p', {}, 'About ', h( 'strong', {}, weeks( parallelEnd ) ), ' to your first day if you start employer applications now, versus ', weeks( serialEnd ), ' doing one step at a time (DPI’s upper estimate of ' + dpi + ' weeks).' ),
			h( 'p', { class: 'mt-muted' }, P.dpi.employer_onboarding_note ) );

		renderChecklist( needsTraining, prints );
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

	function renderChecklist( needsTraining, prints ) {
		let saved = {};
		try {
			saved = JSON.parse( window.localStorage.getItem( STORE_KEY ) || '{}' ) || {};
		} catch ( e ) {
			saved = {};
		}
		const items = [
			[ 'transcript', 'Order an official transcript (DPI and most employers ask for it).' ],
			needsTraining ? [ 'training', 'Finish a DPI-approved substitute training course and save the certificate.' ] : null,
			prints ? [ 'prints', 'Get fingerprinted at Fieldprint (code FPWIDPITeach).' ] : null,
			[ 'elo', 'Apply in ELO and pay the $' + P.dpi.fee + ' fee by credit card.' ],
			[ 'references', 'Ask three people now to be references; MPS wants three letters under 3 years old.' ],
			[ 'resume', 'Update your resume.' ],
			[ 'apply', 'Apply to the employers you want, now, while DPI processes your permit.' ],
			[ 'tb', 'Get a TB test if an employer asks (keep the receipt; some reimburse it).' ],
			[ 'i9', 'Bring I-9 identity documents to onboarding in person. Don’t email or upload them.' ],
			[ 'absence', 'Once hired, set up Frontline or Red Rover and enter your availability.' ],
			[ 'renew', 'Put your permit’s expiry date in your calendar; it lasts 3 years.' ],
		].filter( Boolean );
		const list = h( 'ul', { class: 'mt-checks' } );
		items.forEach( ( [ id, text ] ) => {
			const box = h( 'input', { type: 'checkbox', id: 'mt-ck-' + id } );
			box.checked = !! saved[ id ];
			box.addEventListener( 'change', () => {
				saved[ id ] = box.checked;
				try {
					window.localStorage.setItem( STORE_KEY, JSON.stringify( saved ) );
				} catch ( e ) {
					// Private mode or storage off: the checklist still works for this visit.
				}
			} );
			list.append( h( 'li', {}, box, h( 'label', { for: 'mt-ck-' + id }, text ) ) );
		} );
		const print = h( 'button', { type: 'button', class: 'mt-btn' }, 'Print checklist' );
		print.addEventListener( 'click', () => window.print() );
		el.checklist.replaceChildren( list, print );
	}

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
}() );

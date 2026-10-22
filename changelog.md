# Changelog

## 1.6.0 — 2026-10-22

The substitute page now runs in the order an applicant acts, remembers their answers, and
plans their applications.
- **Page order:** your situation → your cost and timeline → where to apply → your checklist,
  with the reference tables (license types, how each kind of employer works, every agency) folded
  at the end.
- **What slows this down, and how to get around it:** eight delays, each with what it costs the
  applicant, the workaround, what can't be avoided, and a source: the DPI wait, onboarding
  repeated per employer, daily jobs that are never posted, training prices, the card-only
  non-refundable fee, stricter degree rules, the 45-day cap, and pay and retirement. They live in
  data/pathway.json `delays`.
- **Where to apply:** pick counties, then the districts and schools you'd sub for, and the page
  lists the fewest applications that cover them. Each agency application names every pick it
  covers (and offers to add the agency's other districts); direct employers show their degree
  rule, WRS and an apply link; unconfirmed routes get an "Ask first" card. Built from the 45
  researched routes, every other public district in NCES's directory, and employers with open
  sub postings, with posted pay and posting counts (`Pathway::planner()`).
- **Dated checklist:** phases Today → While you train → When the certificate arrives → While DPI
  processes → When the permit arrives → Later, dated from the reader's timeline and filtered to
  their path. One task per planned application. The training comparison and the ELO steps open
  inline. Tasks and phases live in data/pathway.json `checklist`, and printing prints just the
  checklist.
- **Remembered in the browser only:** answers, picks and ticks (`mmiTeachProfile`), with a
  "Forget them" button.
- **Job board — Your fit:** with saved answers, each posting is marked You qualify, Might fit
  (with the condition: the 45-day permit cap, a license in the subject) or Not yet (with what's
  missing: a stated degree, a special-education license, a teaching license). It's a sortable
  column, a filter, a line on phone cards and a row in the detail. The license finder links
  straight to substitute jobs that fit.
- **No-store on HEAD too:** both pages send no-store on `template_redirect` instead of
  `template_include`. WordPress ends HEAD requests between those two hooks, so HEAD responses
  went out cacheable and Cloudflare's "Cache Everything" could keep them.

## 1.5.0 — 2026-10-22

Built around the applicant instead of the analyst.
- **Front page and nav:** "Become a substitute" is now the front page, and the job board moves to
  /vacancies/. The nav reads Become a substitute · Substitute jobs · Teaching jobs, and the job
  board's two tabs follow the Job type filter, including back and forward. Before, "Substitute
  jobs" never showed as current. The duplicate header toggle is gone. Old `/#kind=…` links are
  forwarded to the board.
- **Listings first:** four summary tiles replace seven, and the charts fold into a closed "Market
  overview". Seven less-used filters sit under "More filters" with a count of those set. Desktop
  listings now start above the fold.
- **Substitute tabs:** each opens by saying daily sub work is never posted publicly, and
  explains the three kinds of postings with one-click filters.
- **Phone cards:** each posting is a short card instead of 14 labeled fields. Cards drop from
  476 px to 178 px, and the substitute tab is half as long.
- **Refresh errors:** readers no longer see raw refresh errors. A plain note appears only when a
  source is more than 9 hours old.
- **CSV:** download moves to the footer.
- **WECAN:** a posting opening or closing mid-read no longer fails a refresh. Rows are
  de-duplicated by vacancy ID, and a count mismatch triggers one fresh read before giving up.

## 1.4.4 — 2026-10-21

Qualification requirements for every posting. Each posting now carries the minimum degree, the
Wisconsin DPI license areas that qualify (official title, ELO code, current and legacy grade
levels), years of experience, specializations (special-education area, bilingual language), what
to submit, and other ways in (One-Year License with Stipulations, Choice-school and charter
rules). Requirements come from the posting's own fields (WECAN Degree/Licenses/Additional
Requirements), its description (quoted), or — labeled as such — the Wisconsin rule and the
licenses a job like it normally needs. New data/licenses.json maps all 87 WECAN license names
to 66 DPI license areas with inference rules. Job board: Requirements column, "My highest
degree" and "License area" filters, a "To qualify" block in row details, CSV fields. The
substitute page's license finder links to the substitute jobs the reader qualifies for.
1.4.0–1.4.3 were same-day steps (snapshot compaction, duplicate detail rows, a WECAN description-list bug). Detail-page failures now show in the refresh log and settings screen.

## 1.3.0 — 2026-10-21

Staffing agencies. Every posting now shows which staffing agency, if any, fills it, with a
Staffing agency column, filter, row detail and CSV field on the job board. The agency comes from
the posting's own words ("Employer: Edustaff", "employed through Kelly Education") when it names
one, otherwise from the employer's researched route for that kind of sub job (marked "likely" when
it rests on an agency ad or board record); regular teaching jobs are hired directly. New
data/agencies.json lists 13 agencies active in Wisconsin K-12 (EDUStaff, Kelly Education, ESS,
Goodwill TalentBridge, Parallel Employment, Teachers On Demand, Amergis, Soliant, ProCare,
Sunbelt, Stepping Stones, Elevate K12, Proximity Learning) and the routes of 45 employers in the
three counties, each sourced. The substitute page gains an all-agencies table, and WECAN detail
reads keep a short quote wherever a description names an agency.

## 1.2.6 — 2026-10-20

New public **Become a substitute teacher** page (/substitute/): a license finder (which Wisconsin
DPI permit or license fits, training and fingerprint needs, the 45-day limit, employers that
require a bachelor's degree), a training-course comparison, the exact ELO application steps and
fees, the three ways districts hire subs (directly, EDUStaff, Kelly Education) with retirement and
process differences, a live "who's hiring subs right now" table with posted day rates, and a plan
calculator with costs, a timeline of doing employer steps during the DPI wait, and a checklist
saved in the browser. Every fact carries its source and check date (data/pathway.json); the
settings screen lists facts older than 120 days. Shared site navigation on both pages.
1.2.0–1.2.5 were same-day layout fixes (phone overflow, hidden-attribute rule).

## 1.1.0 — 2026-10-20

Pay and substitute jobs. Pay text from every board (WECAN detail pages, Breezy, Lever, iSolved) is
parsed into amounts, units and a daily equivalent (hourly × 7, annual ÷ 180), shown in a sortable Pay
column with filters (pay listed, daily/hourly, salary, minimum $/day) and a median sub pay tile.
Substitute postings get a kind — daily/on-call, building or long-term — replacing the old
"include substitutes" checkbox with a Job type filter and a Substitute jobs button. Old `#subs=1`
links still work.

## 1.0.0 — 2026-10-20

First release. Dashboard of open K-12 teaching vacancies in Milwaukee, Waukesha and Racine
counties from WECAN, Milwaukee Public Schools, the Archdiocese of Milwaukee, Paycom (Milwaukee
College Prep, UCC, Next Door, Penfield, Messmer), Paycor (HOPE), Lever (Rocketship), Breezy (Carmen)
and iSolved (Cristo Rey); NCES-based county matching; cross-board de-duplication; settings screen
for visibility and county placements; WP-CLI commands.

# Changelog

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

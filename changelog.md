# Changelog

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

# Changelog

## 2.1.0 — 2026-11-26

Clearer Wisconsin license choices and how to get each one, checked against DPI's pages on 2026-11-26.

- **Check, license question:** options run from the quickest credential to the fullest. "Wisconsin
  substitute permit, now expired" is new; the long-term license now uses DPI's name ("five-year
  substitute license"); "any tier" is added to the teaching license. The hint says what to pick if
  you hold several, and links DPI's public license lookup and the licenses guide. The subjects
  question now covers expired and out-of-state licenses too.
- **New `rules.json` items:** `license_reissue` (expired Wisconsin license → reissued three-year
  provisional license, $125, ELO 3020, no coursework) and `oos_license` (out-of-state application,
  $190; reciprocity with a year or more of teaching on form PI-1613, otherwise transcripts and a
  completed program).
- **Corrected items:** an expired permit is applied for again with the same proof and no new course.
  The five-year substitute license term is confirmed on DPI's page, so its conflict note is gone, and
  the out-of-state version is listed. Stipulations list DPI's conditions: the employer couldn't find a
  licensed teacher, and the holder needs 6 credits by August 31 to renew. The teaching license now
  lists the in-state routes, including American Board.
- **Eligibility:** expired permit → `sub_permit` only. Expired license, teaching jobs →
  `license_reissue`, not "renew"; substitute jobs keep the five-year substitute license and mention the
  reissue. Out-of-state, teaching jobs → `oos_license`. Fingerprints are added to both new applications.
- **Your options cards:** each missing step links to where to start it; the full steps stay on the checklist.
- **Guide "Licenses and permits"** (was "Teaching licenses", same URL): one "you have → get → lets
  you → cost" table with anchors to each credential's details, plus the license lookup.
- **Tests:** out-of-state, lapsed-license and expired-permit cases.

## 2.0.0 — 2026-11-24

Rebuilds the site as a job search, eligibility check, checklist and application flow on Bricks,
for licensed teachers, substitutes and aides, and for people with no license or degree yet. Grounded
in ten staffing agencies' applicant flows (none shows a public checklist), a real agency
application's paper trail, and Wisconsin DPI, DHS and statute requirements.

**Phase 1 — data (no visible change yet):**
- **Aides:** WECAN's Assistant/Aide position type (5) is read too. `Board::role()` sorts every
  board's titles into teacher, aide or neither, keeping classroom aides and paraprofessionals and
  dropping bus, health-room, recess, clerical and interpreter roles. The table has a `role` column
  (DB version 5). Aide subs get an agency only when the posting names one. The old dashboard stays
  teaching-only.
- **Aide requirements:** `licenses.json` adds DPI's Special Education Program Aide license and the
  ESSA Title I paraprofessional standard, with an aide degree rule and routes.
- **`data/rules.json`:** every Wisconsin requirement between a person and a school job, each with
  why (statute or rule), time, cost and payer, where, proof, validity, what it blocks (applying vs
  the first day), a public source, check date and confidence; disputed facts carry a `conflict`.
  Nothing is collected beyond done/date/expiry; ID, tax, bank and medical items carry a warning.
- **`Profile`:** answers and checklist state in whitelisted cookies for guests, user meta for
  accounts, merged on sign-in; sample personas (`data/personas.json`) are a read-only overlay.
- **`Eligibility`:** per job, now / check / steps / later with reasons, missing items, and the
  combined time (critical path) and cost; per person, the fastest route into each role.
- **`Checklist`:** one ordered list per person or per job, including employer-only steps.
- **Tests:** `tests/eligibility.php` (fixed cases plus every open posting × every persona).

**Phase 2 — jobs as posts:**
- **`mt_job` post type** (`/job/{slug}/`) with seven filter taxonomies: role, job type, county,
  employer, subject, grade level and minimum degree. Meta carries pay (label with unit, and a
  numeric daily figure only when pay is posted), dates, apply and posting links, source, agency,
  sector, requirements and a short credential label for job cards.
- **`Jobs::sync()`** mirrors the open, in-area postings after every refresh (cross-board duplicates
  folded into one job, as on the dashboard), writing only changed posts by row hash and setting
  terms with `wp_set_object_terms` so cron runs work. Closed postings keep their post with
  `_mt_open = 0`. WP-Cron's fallback writes at most 50 per run; `wp mmi-teach sync-jobs
  [--dry-run] [--limit=<n>]` runs it by hand. First run: 482 jobs in 17 s; a second run writes 0.

**Phases 3–5 — the Bricks site (live):**
- **Theme:** Bricks 2.4.6 with a near-empty child theme (outside this repo). `wp --user=1 mmi-teach
  build-site` writes everything from `Build\Site`: palette, theme style, 87 global classes in an
  "MMI Teach" category, header, footer, job and 404 templates, 15 pages and the menu. Settings go
  first in their own process (`--step=settings`), because Bricks attaches filter hooks only when
  query filters were already on. Pages edited in the builder are skipped unless `--force`d.
  `--step=live` makes the home page the front page and drafts the old dashboard and substitute
  pages; `/vacancies/` and `/substitute/` 301 to `/jobs/` and the substitute guide.
- **Pages:** home (three steps, role cards, sample people, newest jobs); jobs (native Bricks filters
  on the job taxonomies and posted pay, fit badges, and a "jobs I can apply to now" switch applied
  through `bricks/posts/query_vars` and a cookie so filter requests keep it); job template (what
  this job needs as have/need, your fit, apply); check (six questions, routes by role); checklist
  (sections and items, each a native `<details>` with why, time, cost, payer, where, proof,
  validity, warnings and sources, and a form to mark it done with a date and expiry); join, sign
  in, reset password, my applications, account (delete everything), four guides and a privacy notice.
- **Bricks integration:** `Bricks\Tags` (`{mt_*}`, `{mt_job_*}`, `{mt_row_<field>}`),
  `Bricks\Queries` (six loop types scoped to the visitor in PHP), `Bricks\Actions` (six named form
  actions, listed in the form's Actions dropdown). Visibility uses native Bricks conditions on tags.
- **Accounts and applications:** `mt_applicant` role (no capabilities, no wp-admin, no admin bar),
  created by Bricks' registration action; guest answers merge in on sign-up and sign-in.
  `mt_application` (private, author = applicant) with statuses, a staff list in wp-admin (ID first,
  every column sorts), a status box with history, and a privacy exporter and eraser. One email to
  the applicant and one to staff per application via `wp_mail`; mail to `.invalid` addresses is
  recorded, not sent (`Mail`). Every page sends `no-store` (`Front`).

**Phase 6 — retired:** the old dashboard and substitute page (`Dashboard`, `Pathway`, `Snapshot`,
their templates, `dashboard.js`, `dashboard.css`, `pathway.js`, `data/pathway.json`, the REST
snapshot route and the visibility setting), plus helpers only they used. Settings → Teaching
Vacancies now counts open jobs and lists stale `rules.json` and agency facts. The Jobs list in
wp-admin has ID-first sortable columns. Aide titles for residential, AODA and attendance roles are
left out. README and AGENTS.md rewritten.

**Fix (same day):** the header menu showed every link twice. An interrupted build had added four
menu items pointing at no page; `wp_get_nav_menu_items()` hides such items, so the rebuild's
cleanup missed them while the menu still printed them. The builder now clears every item in the
menu (`get_objects_in_term`) before adding the links.

**Tests:** `tests/eligibility.php` (fixed cases plus all 503 open postings × 7 sample people) and
`tests/e2e/flow.js` (guest → check → jobs → checklist → join → apply → hand-off → status → persona
→ delete), passing in Chromium at 1440 px and WebKit at 390 px.

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

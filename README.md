# MMI Teach — School Jobs, Eligibility and Applications

A WordPress plugin, built for Bricks Builder, that collects every open K-12 **teacher, substitute
teacher and aide** job in **Milwaukee, Waukesha and Racine counties, Wisconsin**, tells each
visitor which jobs they qualify for, lists every Wisconsin requirement between them and their
first day, and lets them save applications before finishing each one on the employer's own site.

Live demo: teach.mannmade.us. Applications are saved on the site and **not sent to employers**.

## What a visitor gets

Primary flow, one action per screen:

| Page | What it does |
|---|---|
| `/` | What the site is, three steps, sample people to try it as, newest jobs |
| `/check/` | Six questions (education, license held, license areas, teacher-prep program, age band, lived outside Wisconsin), then the quickest route into each role: open now, probably (one thing to confirm), reachable in weeks (with time and cost), or not yet (with the route, never a bare "no") |
| `/jobs/` | Every open job with Bricks filters (search, role, job type, county, subject, grade, minimum degree, employer, posted pay) and, once the check is done, a fit badge on every card and a "jobs I can apply to now" switch |
| `/job/{slug}/` | The job, **what this job needs** (have / need for this person, with how each requirement is known), your fit, and Apply |
| `/checklist/` | One list of everything between you and your first day (`?job=ID` for one job): what, why (the law), time, cost and who pays, where, proof to keep, how long it lasts, and whether it blocks applying or only the first day. Mark each done (with date and expiry), not needed, or to do |
| `/my-applications/` | Saved applications with status; the applicant updates it as they hear back |
| `/join/`, `/sign-in/`, `/reset-password/`, `/account/` | Accounts, asked for only when saving an application; account deletion removes everything |
| `/guides/…`, `/privacy/` | Substitute permits, aide jobs, teaching licenses, every requirement at a glance; privacy notice |

Old URLs redirect: `/vacancies/` → `/jobs/`, `/substitute/` → `/guides/substitute-teaching/`.

**Guest first.** Answers and checklist ticks live in first-party cookies (whitelisted values,
no free text) until the visitor creates an account; then they move into user meta. **Sample
people** (`data/personas.json`) are a read-only overlay: `?mt_persona=<key>` shows the whole site
as that person, `?mt_persona=off` goes back.

**Apply here, then hand off.** Saving an application records the job, availability, start date
and the checklist items still missing; the applicant then continues to the employer's own
application link from the job board. Applicant and staff each get one email (`wp_mail`).

**Never collected:** Social Security numbers, ID or I-9 documents, bank or tax details, medical
or TB results, criminal history (Wis. Stat. 111.335), uploaded files.

## Data

- **`data/rules.json`** — every requirement (licenses and degrees, application, background and
  health checks, trainings, hiring paperwork, after starting), each with its statute or rule,
  time, cost and payer, where to do it, proof, validity, what it blocks, a public source, the date
  it was checked and a confidence. Facts sources disagree on carry a `conflict` and show as
  "confirm with the employer". Settings → Teaching Vacancies lists entries older than 120 days.
- **`data/licenses.json`** — 69 DPI license areas (including the Special Education Program Aide
  license and the Title I paraprofessional standard) with codes, levels, degree, aliases and the
  WECAN license names mapped to each; `infer` rules, degree rules and routes.
- **`data/agencies.json`** — staffing agencies placing school staff in Wisconsin and how each
  employer fills substitute jobs.
- **`data/places.json`, `data/employers.json`** — county matching (NCES directories).

## How it works

- **`Refresher`** reads every source (cron every 3 hours), **`Classifier`** works out role
  (teacher, substitute, aide), county, subject, grade, sub kind, pay and requirements, and
  **`Jobs::sync()`** mirrors the open, in-area, de-duplicated postings into `mt_job` posts with
  seven taxonomies, writing only what changed. Closed jobs keep their post (`_mt_open = 0`).
- **`Eligibility`** judges a person against a job (now / check / steps / later, with reasons,
  missing items and the combined time and cost); **`Checklist`** turns that and the onboarding
  steps into one list; **`Profile`** keeps answers and ticks.
- **Bricks integration** (`includes/bricks/`): dynamic tags (`{mt_*}`, `{mt_job_*}`,
  `{mt_row_<field>}`), query loop types for non-post lists (`mt_task_groups`, `mt_tasks`,
  `mt_needs`, `mt_paths`, `mt_my_apps`, `mt_personas`, all scoped to the current visitor in PHP)
  and named form actions (`mt_profile`, `mt_task`, `mt_apply`, `mt_app_status`, `mt_delete_me`,
  `mt_redirect`). Visibility uses Bricks' native conditions on those tags. No custom elements.
- **`Build\Site`** writes the whole Bricks site: palette, theme style, 87 global classes in the
  "MMI Teach" category, header, footer, job and 404 templates, pages and menu. A page or template
  edited in the builder is skipped on a rebuild unless forced.

## Setting it up

```sh
# Bricks 2.4.6 + a near-empty bricks-child theme must be active (enter the license key in wp-admin to use the builder).
wp plugin activate mmi-teach
wp mmi-teach refresh                                # read every source and create the job posts
wp --user=1 mmi-teach build-site --step=settings    # Bricks settings: query filters, palette, theme style (own process)
wp --user=1 mmi-teach build-site                    # classes, templates, pages, menu
wp --user=1 mmi-teach build-site --step=live        # front page; old pages drafted, their URLs redirect
```

Other commands:

```sh
wp mmi-teach status                   # open jobs by county and role, source health
wp mmi-teach sync-jobs [--dry-run]    # mirror postings into job posts (runs after every refresh)
wp mmi-teach reclassify               # re-run classification after editing data/*.json
wp mmi-teach unplaced                 # employers not placed in any county
wp mmi-teach build-places             # rebuild data/places.json from NCES
wp --user=1 mmi-teach build-site --force=jobs,header   # overwrite pages edited in the builder
```

Every page sends `no-store`: pages are personalised and the zone caches HTML.

## Tests

```sh
wp eval-file wp-content/plugins/mmi-teach/tests/eligibility.php        # fixed cases + every open job × every sample person
node wp-content/plugins/mmi-teach/tests/e2e/flow.js chromium 1440       # guest → check → jobs → checklist → join → apply → hand-off → status → persona → delete
node wp-content/plugins/mmi-teach/tests/e2e/flow.js webkit 390
```

The browser test creates one `@example.invalid` account (its mail is recorded, not sent) and
deletes it through the site's own Delete my account.

## Where the jobs come from

| Source | What it covers | How it's read |
|---|---|---|
| [WECAN](https://wecan.waspa.org/) | The statewide K-12 application system: nearly every public district in the three counties, plus many charter and private schools | Public search grid (Teacher, Teacher Leader, Substitute Teachers and Assistant/Aide types), plus each in-area posting's detail page |
| Milwaukee Public Schools careers | MPS's full external list | PeopleSoft Candidate Gateway list page |
| Archdiocese of Milwaukee careers | Catholic school openings | Public careers page |
| Paycom | Milwaukee College Prep, UCC/Bruce-Guadalupe, Next Door, Penfield, Messmer | Public job page → search API |
| Paycor | HOPE Christian Schools | Public career page |
| Lever | Rocketship (Milwaukee schools only) | Public postings API |
| Breezy HR | Carmen Schools of Science & Technology | Public JSON feed |
| iSolved Hire | Cristo Rey Jesuit High School | Public jobs API |

None needs an account or key. Requests identify the plugin and wait 2 seconds between requests
to the same host. Aide postings keep classroom aides and paraprofessionals only (`Board::role()`);
bus, health-room, recess, clerical, interpreter and residential-program roles are left out.

## Safety

- A source that fails, or lists fewer than half the postings it had last time, keeps its last
  good list. Postings are never deleted: one that leaves its board gets a `removed_at` date.
- Applications are a private post type; applicants see only their own through a PHP-scoped loop,
  never a Bricks post loop. IDs that arrive from forms are validated against the visitor.
- Applicants have no capabilities, no admin bar and no access to wp-admin.

## Adding a source

Implement `MMI_Teach\Sources\Source` (or extend `Sources\Board` for a single-employer ATS) and add
it to `Refresher::sources()` or the `mmi_teach_sources` filter. `fetch()` must throw rather than
return a partial list.

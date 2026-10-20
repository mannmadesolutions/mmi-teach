# MMI Teach — Teaching Vacancies Dashboard

A WordPress plugin that collects every open K-12 teaching vacancy in **Milwaukee, Waukesha and
Racine counties, Wisconsin** and shows them on one filterable dashboard.

## What it shows

- Open teaching postings: totals per county, new in the last 7 days, closing within 14 days.
- Charts: postings by subject, top employers, and open postings by week posted. Click a bar to filter.
- A sortable table of every posting. Click a row for salary, openings, required licenses,
  contact, a summary and links to apply.
- Filters for county, subject, grade level, full or part time, posting date, keyword, and
  substitute postings (hidden by default). The filters live in the URL, so a filtered view can be
  bookmarked or shared.
- CSV download of the current view.

## Where the data comes from

| Source | What it covers | How it's read |
|---|---|---|
| [WECAN](https://wecan.waspa.org/) | The statewide K-12 application system. Nearly every public district in the three counties posts here, plus many charter and private schools | Public vacancy search grid (Teacher, Teacher Leader and Substitute types), plus each in-area posting's detail page |
| Milwaukee Public Schools careers | MPS's full external list (only some MPS jobs reach WECAN) | PeopleSoft Candidate Gateway list page |
| Archdiocese of Milwaukee careers | Catholic school openings across the archdiocese | Public careers page, placed by the city in each card |
| Paycom | Milwaukee College Prep, UCC/Bruce-Guadalupe, Next Door, Penfield, Messmer | Public job page → search API |
| Paycor | HOPE Christian Schools | Public career page |
| Lever | Rocketship (Milwaukee schools only) | Public postings API |
| Breezy HR | Carmen Schools of Science & Technology | Public JSON feed |
| iSolved Hire | Cristo Rey Jesuit High School | Public jobs API |

None of these needs an account or key. Every request identifies the plugin in its User-Agent
and waits at least 2 seconds between requests to the same host.

When a school lists the same job on WECAN and its own board, the dashboard shows it once (the
WECAN copy, which has the most detail) and links the other listing.

**County matching.** WECAN covers the whole state, so each posting is placed in a county from
its employer and school names, using `data/places.json`. That file holds every Wisconsin public
district and school (NCES Common Core of Data) and every private school (NCES Private School
Survey) with its county. Employers that match nothing there are listed under **Settings →
Teaching Vacancies**, where you can place them; `data/employers.json` holds the checked answers
that ship with the plugin.

## Running it

```sh
wp plugin activate mmi-teach            # creates the table and the dashboard page (front page on an empty site)
wp mmi-teach refresh                    # read every source; --details=<n> caps detail-page reads (default 200)
wp mmi-teach status                     # counts per county, source health
wp mmi-teach unplaced                   # employers not placed in any county
wp mmi-teach reclassify                 # re-run county/subject matching after editing data/*.json
wp mmi-teach build-places               # rebuild data/places.json from NCES (when a new year is published)
```

On the server, cron runs `wp mmi-teach refresh` every 3 hours. A twice-daily WP-Cron event and
the **Refresh now** button are fallbacks; they cap detail-page reads to stay inside WP-Cron's
time budget.

## Safety

- A source that fails, or lists fewer than half the postings it had last time, keeps its last
  good list, and the dashboard footer says so. Postings are never deleted: one that leaves its
  board gets a `removed_at` date, so history survives.
- The dashboard defaults to **signed-in users only** and is hidden from search engines until
  you switch it to public in Settings.
- Data reaches the page through one REST route (`/wp-json/mmi-teach/v1/snapshot`) that checks
  the same visibility rule. All posting text is inserted with `textContent`; links must be http(s).
- Page views read a stored snapshot built at refresh time; nothing is fetched or classified per view.

## Adding a source

Implement `MMI_Teach\Sources\Source` (or extend `Sources\Board` for a single-employer ATS), and
add it to `Refresher::sources()` or the `mmi_teach_sources` filter. `fetch()` must throw rather
than return a partial list.

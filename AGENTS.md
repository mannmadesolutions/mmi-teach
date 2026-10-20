# mmi-teach — notes for AI agents

- Site-only plugin for the MannMade teaching site. Not part of the distributed MMI suite:
  no mmi-suite-common, MMI_Settings, MMI_Logger or Throttler here. Http.php is the throttle (2 s per host).
- Zero cost: every source is a public listing. Never add a paid API, a scraping service or an account-based board.
- A source's `fetch()` throws instead of returning a partial list — a short list marks postings removed.
- Page views read `Snapshot::get()` only. Anything slow belongs in `Refresher` (cron/CLI).
- County matching lives in `Places`; add checked employer answers to `data/employers.json`, then `wp mmi-teach reclassify`.
- Every heading in the table sorts; ID is the first column (owner rules).
- Bump `Version` + `MMI_TEACH_VERSION` and add a `changelog.md` entry for every change.

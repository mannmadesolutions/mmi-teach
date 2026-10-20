# Security Policy

## Reporting a vulnerability

Please report security issues privately through GitHub: open the **Security** tab of this
repository and choose **Report a vulnerability**. Do not open a public issue or pull request for a
suspected vulnerability.

Include the affected version, the steps to reproduce, and the impact you observed. We acknowledge
reports within 3 business days and aim to ship a fix for confirmed High or Critical issues within
30 days.

## Supported versions

Only the latest released version receives security fixes.

## Security model

- **Access control.** The settings screen, manual refresh and county placements need
  `manage_options` (filterable through `mmi_teach_manage_capability`) and a nonce. The dashboard
  and its REST route are signed-in only unless the site owner makes them public.
- **Outbound requests** go only to the fixed job-board and NCES URLs in the source classes; no
  user input ever becomes a URL. Responses are parsed as data (HTML with libxml network access
  off, JSON with `json_decode`), never executed or unserialized.
- **Output.** The dashboard inserts all posting text with `textContent` and only links http(s)
  URLs. CSV exports neutralize spreadsheet formulas.
- **No credentials.** Every source is a public listing; the plugin stores no keys or passwords.

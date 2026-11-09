# Never deploy to prod

Files in `smartstaff/` that go to the **test** box only
(`/home/smartst/test.smartstaffsolutions.com/ajax/crew/`), and never to
`/home/smartst/public_html/ajax/crew/`. Check this list before every prod upload.

Each one also refuses to run unless the connected database is `smartst_test`.
That guard is a backstop. This list is the rule.

| File | Why | Added |
|---|---|---|
| `smoke-link-button.php` | Writes and then restores supervision links and No Crew Boss markers on a booking you name. BRIEF-default-link-self-submit-off B7 | 9 Nov 2026 |
| `test-call-tiers.php` | Prints contacts' names and mobiles for any calls. BRIEF-crew-self-times §3.8 | slice B |

If a prod copy of either is ever found, delete it from `public_html/ajax/crew/`.

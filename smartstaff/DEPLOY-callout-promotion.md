# Deploy runbook — Backup call-out and link-safe promotion

**Brief:** `claude/BRIEF-callout-and-promotion-crewfinder.md` · **Branch:** `feat/callout-promotion`
**PHP commit:** `c45c71e` · **GOAT commit:** `a7f82b7` · **Target:** 5.65.0 (confirm with goat-release)

Test phase first, completely, including tests 1–23. Prod is a separate, later
section. The Crew Hub brief is built and deployed **between** the two (brief §3
step 4).

## Files

| File | State | Needs first |
|---|---|---|
| `MIGRATION-callout.sql` | **new** — `call_callout`, `call_callout_member` | — |
| `callout.php` | **new** include — lock, close, fill check, `callout` flag | — |
| `promo-group.php` | **new** include — promotion group helpers | `call-graph.php` (live, unchanged) |
| `respond-to-call.php` | modified — lock, call-out bookkeeping, fill check | `callout.php` |
| `update-crew-status.php` | modified — link-safe Promote, ops fill check | `callout.php` |
| `respond-to-promotion.php` | modified — answers the group | `promo-group.php` |
| `dismiss-promo-ack.php` | modified — dismisses the group | `promo-group.php` |
| `my-shifts.php` | modified — `promo_declining_withdraws` | `promo-group.php` |
| `open-callout.php` | **new** endpoint | `callout.php` |
| `close-callout.php` | **new** endpoint | `callout.php` |
| `callout-sweep.php` | **new** CLI script (cron) | `callout.php` |
| `my-call-offers.php` | modified — `callout` | migration |
| `get-calls-bulk.php` | modified — `promo_pending`, `backups`, `callout_open` | migration (fails soft) |
| `get-booking.php` | modified — `callout_member`, `callout`, `promo_group` | migration (fails soft) |
| `get-crew-shifts.php` | modified — `promo_pending` | — |

`call-graph.php` and `cohort.php` are **not** changed. Step 0.2 confirmed them
identical on main, test and prod.

## Expected hashes (sha256, as committed in `c45c71e`)

```
c7175e52153a41f250d24e9fe5882747d4c916c34d36dcabc0f424a5b3f53399  callout.php
f274ffe140590286f8880cf356696b2a2436e7a14f8f77864ce639e8cf3e27a2  promo-group.php
fda219fa62502d2e34e11a12bcc9ccc2d37886071bc4017cc77ed730cd729dd6  respond-to-call.php
b23d633ff54f9575c2656c08b2da4b46c299ab7cc0c9371c90f274fe22bce7dd  update-crew-status.php
a0c1551025472c988853220d4b9eca9f1bca92e22b0a889f5c1ad32ca9517eb7  respond-to-promotion.php
16ef0de1f85400f9570186e38a2b80d2cfde2949e5b93907caebe010e966becb  dismiss-promo-ack.php
ae158cbaa91f72484fd7a9d6ab0120e0264e645291dc5e568ae48ded543bd764  my-shifts.php
a09f2fcbbd7207813afa0b474786f5ab7eda0f3660751c207b10a6ee15897fdb  open-callout.php
27520d1083321f12a66dfb1bdde147ae3198ddfa691d00b18fa43b5ce773aef2  close-callout.php
5476243a7cbd614609e7092d56550ee3da0fe66ed289cebf0f8bd1e882743749  callout-sweep.php
8cfe7d88485df7051fd7cf86df4072123c000dee162f7bf2312bfc8c0b56be10  my-call-offers.php
0542b41ff728b009edc4540058bd062e5bdf61fbfe738873012c742a1a49c79e  get-calls-bulk.php
c9895361dba99d6c4116cb5631b381e0f4aafb074c639cc5299d00416d98a093  get-booking.php
6f30cdbae508e25e91634e62d8a52acdb1c58d2df11ca02144162277ba7c5e2c  get-crew-shifts.php
c902b244222b6a6c9bd83087c6d0f5fe154868b97c0cac3f9980fa556d69a09d  MIGRATION-callout.sql
```

---

## TEST PHASE — `smartst_test`, `/home/smartst/test.smartstaffsolutions.com/ajax/crew`

### T0. Before you start

- [ ] On the Mac, confirm the working copy is what was committed:
      ```bash
      cd ~/dev/gigpower && git status --short smartstaff/ && git --no-pager log --oneline -1 c45c71e
      ```
      Nothing under `smartstaff/` for these 15 files should show as modified.

### T1. Migration (test)

- [ ] phpMyAdmin → `smartst_test` → SQL. Paste the `CREATE TABLE` statements from
      `MIGRATION-callout.sql` and run.
- [ ] Verify:
      ```sql
      SHOW CREATE TABLE `call_callout`;
      SHOW CREATE TABLE `call_callout_member`;
      SELECT COUNT(*) FROM `call_callout`;          -- 0
      SELECT COUNT(*) FROM `call_callout_member`;   -- 0
      ```
      Both MyISAM; `idx_call_open (callID, closed_at)`, `uq_member`, `idx_user_call`.

### T2. Includes first (test)

Upload with cPanel → File Manager into the test `ajax/crew` folder, **in this order**.
An endpoint uploaded before its include fails with `Call to undefined function`.

- [ ] `callout.php`
- [ ] `promo-group.php`

### T3. Endpoints (test)

- [ ] `respond-to-call.php`
- [ ] `update-crew-status.php`
- [ ] `respond-to-promotion.php`
- [ ] `dismiss-promo-ack.php`
- [ ] `my-shifts.php`
- [ ] `open-callout.php`
- [ ] `close-callout.php`
- [ ] `callout-sweep.php`
- [ ] `my-call-offers.php`
- [ ] `get-calls-bulk.php`
- [ ] `get-booking.php`
- [ ] `get-crew-shifts.php`

### T4. Lint and hash (test)

- [ ] cPanel → Terminal, paste:
      ```bash
      cd /home/smartst/test.smartstaffsolutions.com/ajax/crew
      for f in callout.php promo-group.php respond-to-call.php update-crew-status.php \
               respond-to-promotion.php dismiss-promo-ack.php my-shifts.php open-callout.php \
               close-callout.php callout-sweep.php my-call-offers.php get-calls-bulk.php \
               get-booking.php get-crew-shifts.php; do
        php -l "$f" | grep -v '^No syntax errors' ; sha256sum "$f"
      done
      ```
- [ ] Every hash matches the list above. **Any mismatch: stop** — the upload is not
      the committed file.
- [ ] No `php -l` output other than the hashes. (A `Deprecated` line is not a
      failure, but paste it back.)

### T5. The sweep runs from the CLI (test)

The sweep is the one file run by the command-line PHP, so it is pinned to
**`/opt/alt/php56/usr/bin/php`** here and in the cron line (P7). Confirmed on
the server: installed, with `mysql`, `mysqli`, `mysqlnd` and `pdo_mysql`.

**Why the full path:** cron's search path may not include `/usr/local/bin`,
and plain `php` follows the account's PHP Selector — it would break silently if
the site's PHP version changes.

- [ ] ```bash
      /opt/alt/php56/usr/bin/php -v
      /opt/alt/php56/usr/bin/php /home/smartst/test.smartstaffsolutions.com/ajax/crew/callout-sweep.php; echo "exit $?"
      ```
      Expected: PHP 5.6 from the first line; no output and `exit 0` from the
      second (nothing open yet).
- [ ] Open the sweep from a browser:
      `https://test.smartstaffsolutions.com/ajax/crew/callout-sweep.php` → `Forbidden`.

### T6. GOAT from source

- [ ] On the Mac, run THE GOAT from source on `feat/callout-promotion` (`a7f82b7`),
      pointed at test.

### T7. Tests 1–23 (brief §2)

Fixtures by SQL, every value recorded for restore. Requests with
`curl -s -o /tmp/r.out -w "HTTP %{http_code}\n" …`, then `wc -c /tmp/r.out`.
Check results with a SELECT, never phpMyAdmin's affected-row count.

- [ ] 1 Lock: two accepts together, one place
- [ ] 2 Lock held elsewhere: 503 after ~3 s; prod-named lock does not block test
- [ ] 3 Unlinked call-out, 1 place, 3 backups
- [ ] 4 2 places, 3 backups
- [ ] 5 Linked load-in → load-out, call out on the load-in
- [ ] 6 Call out on the load-out, backup on the load-in
- [ ] 7 Decline during a call-out
- [ ] 8 Ops close, twice
- [ ] 9 Sweep on a back-dated call, twice
- [ ] 10 Open refused ×4
- [ ] 11 Promote on a load-in, backup on both
- [ ] 12 Decline that promotion as 5925
- [ ] 13 Promote refused, downstream at 6
- [ ] 14 Promotion decline withdraws upstream
- [ ] 15 Dismiss on a two-call promotion
- [ ] 16 Promotion brief tests 3–9
- [ ] 17 Peel regression (`TESTRUN-package-peel-2026-09-11.md`)
- [ ] 18 Old client 5.64.0 against the new PHP
- [ ] 19 Loser after fill, sequential
- [ ] 20 Loser in the same instant
- [ ] 21 Filled by a non-member accept
- [ ] 22 Filled by ops
- [ ] 23 Sweep closes a full call

### T8. Teardown (test)

- [ ] Restore every captured value.
- [ ] Zero leftover `call_callout` / `call_callout_member` rows for the fixture
      calls; zero leftover map and calendar rows for fixture users.
- [ ] `SELECT * FROM call_callout WHERE closed_at IS NULL;` → empty.

---

## PROD PHASE — later, after the Crew Hub brief is deployed

`smartst_smartstaff`, `/home/smartst/public_html/ajax/crew`.

**No functional test on prod.** As with the peel, the accept path is write-only
there; its first exercise is a real crew member. **Old installs change too:**
link-safe Promote takes effect for every installed GOAT the moment
`update-crew-status.php` lands — say so in the patch note.

- [ ] **P1 Migration (prod):** same SQL and verification as T1, on `smartst_smartstaff`.
- [ ] **P2 Includes (prod):** `callout.php`, then `promo-group.php`.
- [ ] **P3 Endpoints (prod):** the T3 list, same order.
- [ ] **P4 Lint and hash (prod):** the T4 block with
      `cd /home/smartst/public_html/ajax/crew`.
- [ ] **P5 Four-point check:** every file's hash agrees across the Mac working copy,
      test, prod and the GitHub blob at the merge commit.
- [ ] **P6 Sweep from the CLI (prod):**
      ```bash
      /opt/alt/php56/usr/bin/php /home/smartst/public_html/ajax/crew/callout-sweep.php; echo "exit $?"
      ```
      No output, `exit 0`.
- [ ] **P7 Cron:** cPanel → Cron Jobs → add:
      ```
      */15 * * * * /opt/alt/php56/usr/bin/php /home/smartst/public_html/ajax/crew/callout-sweep.php
      ```
      Full binary path, never plain `php`: cron's search path may not include
      `/usr/local/bin`, and plain `php` follows the account's PHP Selector, so it
      would break silently if the site's PHP version changes. No
      `cd` is needed: the script changes into its own folder before including
      `global.php`. It prints only when it closes something or fails, so cPanel's
      cron mail stays quiet.
- [ ] **P8 GOAT release:** goat-release — commit, DMG on the iMac, notarise,
      release, `version.json` last.
- [ ] **P9 Guide:** update `GUIDE-call-feeds.md` (§5's all-or-nothing table is
      wrong; add Promote and the call-out); circulate with the patch note to Joe,
      Monty and Rich.

## Rollback

- **PHP:** re-upload the `ec81a4a` copies of the nine modified files. Remove
  `open-callout.php`, `close-callout.php` and `callout-sweep.php`; remove the cron
  job first. The two includes can stay; nothing else calls them.
- **Close open call-outs before rolling back** (Close call-out in THE GOAT, or
  `close-callout.php`), or members are left at offered with nothing to revert them.
- **Tables:** `DROP TABLE call_callout_member; DROP TABLE call_callout;` only after
  the PHP is rolled back.

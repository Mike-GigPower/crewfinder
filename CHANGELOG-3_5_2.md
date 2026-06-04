# THE GOAT — v3.5.2

A targeted fix on top of 3.5.1. One change only.

## Fixed — Crew Finder timeline horizontal scroll in Safari

Safari users couldn't scroll the schedule timeline properly: dragging left/right
moved only the row under the pointer while the time-axis at the top stayed put.
Chrome was unaffected.

**Cause.** The timeline was built as N independent horizontal scroll containers —
the header time-axis plus one per crew row — kept in sync by copying `scrollLeft`
between them on every `scroll` event, behind a re-entrancy guard. Chrome fires the
programmatic-scroll events synchronously so the guard holds; Safari/WebKit fires
them asynchronously, so the guard was already reset when they arrived and the
cross-row updates never took effect.

**Fix.** The timeline now has a single real scroller — the header time-axis, which
carries a visible styled scrollbar (the "bar at the top"). Every crew row follows
it via a CSS `transform`, so there are no longer multiple scroll containers to keep
in sync. A trackpad swipe or wheel over any row forwards its horizontal delta to
that one scroller; horizontal intent is `preventDefault`ed (which also stops
Safari's two-finger swipe-to-go-back from stealing the gesture), while vertical
scrolling of the crew list is untouched.

Behaviour in Chrome is unchanged; Safari now matches it.

### Files
- `templates/index.html` — timeline scroll rework (CSS + `renderTimeline`).
- `app.py` — `APP_VERSION` → 3.5.2.

No SmartStaff PHP, config, or database changes. The 3.5.1 deploy items
(`list-crew-bulk.php` notes column, glossary) are unaffected and remain as shipped.

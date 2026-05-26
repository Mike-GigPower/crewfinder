# Gig Power — Crew Availability

A local Mac app for finding available crew for calls in SmartStaff Solutions.

## Files

```
gigpower/
  app.py          — Flask backend (API + scraping logic)
  menubar.py      — macOS menu bar app
  setup.sh        — One-time setup script
  requirements.txt
  crew_cache.json — Auto-generated crew profile cache (daily refresh)
  config.json     — Auto-generated credentials store
  templates/
    login.html    — Login page
    index.html    — Main app UI
```

## Setup (run once per Mac)

```bash
chmod +x setup.sh
./setup.sh
```

## Running

```bash
python3 menubar.py
```

A 🎵 icon will appear in the menu bar. The app opens automatically in your browser at http://localhost:5000.

## Menu bar options

- **Open Gig Power** — opens the browser
- **Start/Stop Server** — control the Flask server
- **Quit** — exits the app

## First run

1. Enter your SmartStaff username and password
2. Check "Remember me" to save credentials locally
3. The crew cache will be built on first search (takes a few minutes)
4. Subsequent searches use the cache (fast)

## Workflow

1. Select an open call from the sidebar
2. Choose required crew groups and minimum rating
3. Click Search
4. Select available crew using checkboxes
5. Click **Add**, **Add & Confirm**, or **Send SMS**

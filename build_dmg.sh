#!/bin/bash
set -e

APP_NAME="The GOAT"
BUNDLE="dist/${APP_NAME}.app"
DMG_OUT="dist/TheGOAT.dmg"
BACKGROUND="static/dmg-background.png"
VENV="venv"

echo "▶ Crew Finder DMG Builder"

if ! command -v create-dmg &>/dev/null; then
  echo "✗ create-dmg not found. Run: brew install create-dmg"
  exit 1
fi

if [ ! -d "$VENV" ]; then
  echo "✗ venv not found. Run from gigpower/ directory."
  exit 1
fi

echo "▶ Activating venv..."
source "${VENV}/bin/activate"

echo "▶ Building app with PyInstaller..."
rm -rf "dist/${APP_NAME}.app" "dist/${APP_NAME}" build

pyinstaller \
  --windowed \
  --onedir \
  --name "${APP_NAME}" \
  --icon goat.icns \
  --add-data "templates:templates" \
  --add-data "static:static" \
  --hidden-import AppKit \
  --hidden-import PyObjCTools \
  dock_launcher.py

if [ ! -d "$BUNDLE" ]; then
  echo "✗ Build failed — ${BUNDLE} not found."
  exit 1
fi
echo "✓ App built: ${BUNDLE}"

if [ ! -f "$BACKGROUND" ]; then
  echo "✗ Background not found at ${BACKGROUND}"
  exit 1
fi

rm -f "$DMG_OUT"

# ─── Compose bundle config from template + build secrets ─────────────────
# Template is committed (safe to be public). Secrets are gitignored.
# Both must exist or the build aborts — we never want to ship a DMG
# with an empty Anthropic key.
if [ ! -f "config.template.json" ]; then
  echo "✗ config.template.json not found. This file is committed to the repo and should be present."
  exit 1
fi
if [ ! -f "build_secrets.json" ]; then
  echo "✗ build_secrets.json not found. Create it locally with your Anthropic key:"
  echo "    { \"anthropic_api_key\": \"sk-ant-...\" }"
  echo "  (gitignored, never committed.)"
  exit 1
fi

echo "▶ Composing bundle config (template + build secrets)..."
python3 -c "
import json
with open('config.template.json') as f: cfg = json.load(f)
with open('build_secrets.json') as f: cfg.update(json.load(f))
with open('dist/${APP_NAME}.app/Contents/MacOS/config.json', 'w') as f:
    json.dump(cfg, f, indent=2)
    f.write('\n')
"
# Bundle the AU postcode centroid table beside the executable (BASE_DIR) —
# same place config.json lives. Distance/radius search reads it from here.
if [ ! -f "au_postcodes.json" ]; then
  echo "✗ au_postcodes.json not found in repo root — distance search would break. Aborting."
  exit 1
fi
echo "▶ Bundling au_postcodes.json..."
cp au_postcodes.json "dist/${APP_NAME}.app/Contents/MacOS/au_postcodes.json"
# ─── Code signing (Developer ID + hardened runtime) ──────────────────────
# Must run AFTER config.json and au_postcodes.json are copied in — signing
# seals the bundle, so adding files afterward would break the signature.
SIGN_ID="Developer ID Application: Gig Power Pty ltd (96W2KAK46G)"
echo "▶ Stripping extended attributes (codesign rejects resource forks / Finder info)..."
xattr -cr "dist/${APP_NAME}.app"
echo "▶ Signing the app bundle..."
codesign --force --deep --options runtime --timestamp \
  --entitlements entitlements.plist \
  --sign "$SIGN_ID" \
  "dist/${APP_NAME}.app"
echo "▶ Verifying signature..."
codesign --verify --deep --strict --verbose=2 "dist/${APP_NAME}.app"
echo "▶ Creating DMG..."
create-dmg \
  --volname "${APP_NAME}" \
  --volicon "goat.icns" \
  --background "${BACKGROUND}" \
  --window-pos 200 150 \
  --window-size 660 420 \
  --icon-size 120 \
  --icon "${APP_NAME}.app" 180 210 \
  --app-drop-link 480 210 \
  --no-internet-enable \
  "$DMG_OUT" \
  "$BUNDLE"

echo ""
echo "▶ Notarizing the DMG (uploads to Apple — can take a few minutes)..."
xcrun notarytool submit "$DMG_OUT" --keychain-profile "GOAT-notary" --wait
echo "▶ Stapling the notarization ticket..."
xcrun stapler staple "$DMG_OUT"
echo "✓ Done: ${DMG_OUT}"
echo "  Send to users: double-click → drag to Applications → right-click → Open"

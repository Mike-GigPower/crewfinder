#!/bin/bash
set -e

APP_NAME="Crew Finder"
BUNDLE="dist/${APP_NAME}.app"
DMG_OUT="dist/CrewFinder.dmg"
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
echo "✓ Done: ${DMG_OUT}"
echo "  Send to users: double-click → drag to Applications → right-click → Open"

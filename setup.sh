#!/bin/bash
# Gig Power — Setup Script
# Run once per machine to create the venv and install dependencies.
set -e

echo "🎵 Setting up Gig Power..."

# Check Python3
if ! command -v python3 &> /dev/null; then
    echo "❌ Python3 not found. Please install it from python.org"
    exit 1
fi
echo "✅ Python3 found: $(python3 --version)"

# Create the venv (build_dmg.sh expects ./venv by name)
if [ ! -d "venv" ]; then
    echo "📦 Creating virtual environment (venv)..."
    python3 -m venv venv
else
    echo "📦 venv already exists — reusing it."
fi

source venv/bin/activate

# Upgrade pip FIRST — old pip can't fetch prebuilt pyobjc-core wheels
# and falls back to a source build that fails on the CLT Python.
echo "⬆️  Upgrading pip..."
pip install --upgrade pip

echo "📦 Installing dependencies from requirements.txt..."
pip install -r requirements.txt

echo ""
echo "✅ Setup complete!"
echo ""
echo "To start Gig Power:"
echo "  source venv/bin/activate"
echo "  python3 menubar.py"
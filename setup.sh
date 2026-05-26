#!/bin/bash
# Gig Power — Setup Script
# Run once to install dependencies

echo "🎵 Setting up Gig Power..."

# Check Python3
if ! command -v python3 &> /dev/null; then
    echo "❌ Python3 not found. Please install it from python.org"
    exit 1
fi

echo "✅ Python3 found: $(python3 --version)"

# Install pip packages
echo "📦 Installing dependencies..."
pip3 install flask requests beautifulsoup4 rumps

echo ""
echo "✅ Setup complete!"
echo ""
echo "To start Gig Power:"
echo "  python3 menubar.py"
echo ""
echo "Or add it to Login Items in System Preferences to start automatically."

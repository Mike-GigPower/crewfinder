"""
Crew Finder Menu Bar App
========================
Runs the Flask server in a separate process and provides a macOS menu bar
icon to open the app in the browser.

Requirements:
    pip3 install rumps flask requests beautifulsoup4

Usage:
    python3 menubar.py
"""

import rumps
import multiprocessing
import webbrowser
import os
import sys
import time
import socket

APP_URL  = "http://127.0.0.1:5001"
APP_NAME = "Crew Finder"


def run_flask():
    """Flask server — runs in a separate process."""
    # Suppress startup output
    import logging
    log = logging.getLogger("werkzeug")
    log.setLevel(logging.ERROR)

    os.chdir(os.path.dirname(os.path.abspath(__file__)))
    sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))

    from app import app
    app.run(host="127.0.0.1", port=5001, debug=False, use_reloader=False)


def wait_for_server(host="127.0.0.1", port=5001, timeout=10):
    """Wait until Flask is accepting connections."""
    start = time.time()
    while time.time() - start < timeout:
        try:
            with socket.create_connection((host, port), timeout=1):
                return True
        except OSError:
            time.sleep(0.2)
    return False


class GigPowerApp(rumps.App):
    def __init__(self, flask_process):
        icon_path = os.path.join(
            os.path.dirname(__file__),
            "static",
            "crewfinder-icon.png"
        )

        super().__init__(
            APP_NAME,
            icon=icon_path,
            quit_button=None
        )

        self.flask_process = flask_process
        self.menu = [
            rumps.MenuItem("Open Crew Finder", callback=self.open_browser),
            None,
            rumps.MenuItem("Quit Crew Finder", callback=self.quit_app),
        ]

    @rumps.clicked("Open Crew Finder")
    def open_browser(self, _):
        webbrowser.open(APP_URL)

    def quit_app(self, _):
        if self.flask_process and self.flask_process.is_alive():
            self.flask_process.terminate()
            self.flask_process.join(timeout=3)
        rumps.quit_application()


if __name__ == "__main__":
    # Required for multiprocessing on macOS
    multiprocessing.freeze_support()

    # Start Flask in a separate process
    flask_proc = multiprocessing.Process(target=run_flask, daemon=True)
    flask_proc.start()

    # Wait for Flask to be ready (up to 10 seconds)
    print("Starting Crew Finder...")
    if wait_for_server():
        print("Server ready.")
        webbrowser.open(APP_URL)
    else:
        print("Server took too long to start — open http://127.0.0.1:5001 manually")

    # Run rumps on the main thread (required by macOS)
    GigPowerApp(flask_proc).run()
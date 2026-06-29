#!/usr/bin/env python3
"""
One-time Google authorization for THE GOAT's Google Sheets timesheet generation.

Run this once from the gigpower folder:

    source venv/bin/activate
    python3 gsheet_authorize.py

It opens your browser, asks you to approve access to your Google Sheets/Drive, and
writes the cached token to google_token.json. After that, the app generates sheets
in your own Drive with no further prompts (the token refreshes itself).

Re-run it only if you revoke access or the token is deleted.

Requires:
  - google_oauth_client.json  (the OAuth *Desktop* client you downloaded from the
    Google Cloud Console) in this same folder
  - pip install google-auth-oauthlib
"""

import os
import sys

SCOPES = [
    "https://www.googleapis.com/auth/spreadsheets",
    "https://www.googleapis.com/auth/drive",
]

HERE         = os.path.dirname(os.path.abspath(__file__))
CLIENT_FILE  = os.path.join(HERE, "google_oauth_client.json")
TOKEN_FILE   = os.path.join(HERE, "google_token.json")


def main():
    if not os.path.exists(CLIENT_FILE):
        print("ERROR: google_oauth_client.json not found in this folder.")
        print("Download the OAuth *Desktop app* client JSON from the Google Cloud")
        print("Console (APIs & Services -> Credentials) and save it as:")
        print("   " + CLIENT_FILE)
        sys.exit(1)

    try:
        from google_auth_oauthlib.flow import InstalledAppFlow
    except ImportError:
        print("ERROR: missing dependency. Run:  python3 -m pip install google-auth-oauthlib")
        sys.exit(1)

    flow = InstalledAppFlow.from_client_secrets_file(CLIENT_FILE, SCOPES)
    creds = flow.run_local_server(port=0)  # opens browser, catches the redirect

    with open(TOKEN_FILE, "w") as f:
        f.write(creds.to_json())

    print("\nAuthorized. Token written to:")
    print("   " + TOKEN_FILE)
    print("You can now generate Google Sheets from THE GOAT.")


if __name__ == "__main__":
    main()

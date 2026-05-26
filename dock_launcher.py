import multiprocessing
import webbrowser

from AppKit import NSApplication, NSObject, NSApplicationActivationPolicyRegular
from PyObjCTools import AppHelper

from menubar import run_flask, wait_for_server

APP_URL = "http://127.0.0.1:5001"


class CrewFinderDelegate(NSObject):
    flask_process = None

    def applicationDidFinishLaunching_(self, notification):
        webbrowser.open(APP_URL)

    def applicationShouldHandleReopen_hasVisibleWindows_(self, app, has_visible_windows):
        webbrowser.open(APP_URL)
        return True

    def applicationWillTerminate_(self, notification):
        if self.flask_process and self.flask_process.is_alive():
            self.flask_process.terminate()
            self.flask_process.join(timeout=3)


if __name__ == "__main__":
    multiprocessing.freeze_support()

    flask_proc = multiprocessing.Process(target=run_flask, daemon=True)
    flask_proc.start()
    wait_for_server()

    app = NSApplication.sharedApplication()
    app.setActivationPolicy_(NSApplicationActivationPolicyRegular)

    delegate = CrewFinderDelegate.alloc().init()
    delegate.flask_process = flask_proc
    app.setDelegate_(delegate)

    AppHelper.runEventLoop()

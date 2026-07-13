# -*- mode: python ; coding: utf-8 -*-
from PyInstaller.utils.hooks import collect_submodules
from PyInstaller.utils.hooks import copy_metadata

datas = [('templates', 'templates'), ('static', 'static')]
hiddenimports = ['AppKit', 'PyObjCTools', 'openpyxl', 'googleapiclient', 'googleapiclient.discovery', 'google.auth', 'google.auth.transport.requests', 'google.oauth2', 'google.oauth2.credentials', 'timesheet_common', 'timesheet_import', 'timesheet_generate', 'timesheet_gsheet', 'timesheet_gsheet_read']
datas += copy_metadata('google-api-python-client')
hiddenimports += collect_submodules('googleapiclient')
hiddenimports += collect_submodules('google.auth')


a = Analysis(
    ['dock_launcher.py'],
    pathex=[],
    binaries=[],
    datas=datas,
    hiddenimports=hiddenimports,
    hookspath=[],
    hooksconfig={},
    runtime_hooks=[],
    excludes=[],
    noarchive=False,
    optimize=0,
)
pyz = PYZ(a.pure)

exe = EXE(
    pyz,
    a.scripts,
    [],
    exclude_binaries=True,
    name='The GOAT',
    debug=False,
    bootloader_ignore_signals=False,
    strip=False,
    upx=True,
    console=False,
    disable_windowed_traceback=False,
    argv_emulation=False,
    target_arch=None,
    codesign_identity=None,
    entitlements_file=None,
    icon=['goat.icns'],
)
coll = COLLECT(
    exe,
    a.binaries,
    a.datas,
    strip=False,
    upx=True,
    upx_exclude=[],
    name='The GOAT',
)
app = BUNDLE(
    coll,
    name='The GOAT.app',
    icon='goat.icns',
    bundle_identifier=None,
)

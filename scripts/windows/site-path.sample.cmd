@echo off
rem Copy this file to site-path.local.cmd and replace __SITE_SOURCE__ with this
rem machine's path to the Laravel site checkout that copy-site-to-external.ps1
rem reads. The real file is gitignored, because a path names a machine while the
rem token names nobody.
set "SITE_SOURCE=__SITE_SOURCE__"

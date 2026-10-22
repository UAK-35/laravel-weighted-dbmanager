@echo off
rem ---------------------------------------------------------------------------
rem The two directories this machine's test servers live in.
rem
rem This is the sample, not the record: copy it beside itself as
rem servers.local.cmd — the name .gitignore holds, so the real paths never reach
rem a commit — and replace each __TOKEN__ with this machine's own directory.
rem
rem   PG_ROOT      the directory holding pgsql179\ (a portable PostgreSQL tree:
rem                bin\, data\ and the server log beside them) and standby179\
rem                (the standby's own data directory)
rem   PGCAT_ROOT   the pgcat checkout: the directory holding Cargo.toml and the
rem                target\debug\pgcat.exe a build leaves behind
rem
rem Every script in this folder refuses to run, naming the missing file, when
rem servers.local.cmd is not there.
rem ---------------------------------------------------------------------------

set "PG_ROOT=__PG_ROOT__"
set "PGCAT_ROOT=__PGCAT_ROOT__"

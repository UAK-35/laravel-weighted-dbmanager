# Composer on Windows behind a TLS scanner

A local antivirus or proxy that scans TLS — Avast Web Shield is the one this was
found with — can make every `composer install` / `composer update` in this package
fail, while `git fetch`, `git push` and `curl` keep working. This note is here so
that failure is recognised in a minute instead of diagnosed from scratch.

## The symptom

```
curl error 60 while downloading https://packagist.org/providers/ext-pcntl.json:
SSL certificate problem: unable to get local issuer certificate

The following exception indicates a possible issue with the Avast Firewall
Check https://getcomposer.org/local-issuer for details
```

The second line is Avast's own hint, and it is the whole diagnosis: the certificate
Composer was handed was signed by the scanner, and PHP does not trust the scanner's
root. It is **not** a network outage, not a proxy misconfiguration, and not a
Composer bug — do not spend time on connectivity, credentials or `http_proxy`.

## Why only Composer fails

TLS scanning is applied **per process**, and the two stacks this repository uses
disagree about where trust comes from:

| Tool                                | TLS backend | Trust store                        | Intercepted? |
|-------------------------------------|-------------|------------------------------------|--------------|
| `git`, Git Bash's `curl`            | Schannel    | the Windows certificate store      | keeps working — the scanner's root is in the store |
| `php` (and therefore Composer)      | OpenSSL     | `curl.cainfo` / `openssl.cafile`, i.e. `C:/Program Files/Common Files/SSL/cacert.pem` | fails — that file has the public roots, not the scanner's |

So the same URL verifies under `git` and under PHP's `openssl` CLI, and fails under
`php -r 'curl_init(...)'`. That asymmetry *is* the fingerprint.

## The fix

Give Composer a CA bundle that contains the scanner's root as well as the public
roots. The quickest source of the public roots is Git's own bundle — it is already
on the machine and already current. Append the intercepting root from the Windows
trust store:

```powershell
# 1. the public roots, from Git for Windows
$bundle = Join-Path $env:TEMP 'composer-ca.pem'
Copy-Item 'C:\Program Files\Git\mingw64\etc\ssl\certs\ca-bundle.crt' $bundle -Force

# 2. every intercepting root installed by a TLS scanner
Get-ChildItem Cert:\LocalMachine\Root, Cert:\CurrentUser\Root |
    Where-Object { $_.Subject -match 'Web/Mail Shield|Kaspersky|ESET|Bitdefender|Sophos|Zscaler|Netskope' } |
    ForEach-Object {
        $b64 = [Convert]::ToBase64String($_.RawData, 'InsertLineBreaks')
        Add-Content $bundle "`n-----BEGIN CERTIFICATE-----`n$b64`n-----END CERTIFICATE-----"
    }

# 3. point Composer at it — this alone is enough
$env:COMPOSER_CAFILE = $bundle
```

Composer reads `COMPOSER_CAFILE` itself, so no PHP ini override is needed: setting it
in the environment is sufficient for both `composer` on PATH and a `php composer.phar`
invocation. (`curl.cainfo` / `openssl.cafile` overrides work too, but they only help a
`php` process you started yourself, not a `composer.bat` shim.)

`bin/composer-ca.ps1` performs all three steps and then runs Composer, so the common
case is one command:

```powershell
pwsh -File .\bin\composer-ca.ps1 install
pwsh -File .\bin\composer-ca.ps1 update
pwsh -File .\bin\composer-ca.ps1 test
```

The bundle is written to `%TEMP%` and is **machine-local** — never commit it, and
never hard-code its path in `composer.json`.

### If you cannot run the helper

Set `COMPOSER_CAFILE` for one shell and use Composer normally:

```powershell
$env:COMPOSER_CAFILE = "$env:TEMP\composer-ca.pem"
composer install
```

To make it permanent for your account, set the same variable with
`setx COMPOSER_CAFILE "$env:TEMP\composer-ca.pem"` — but understand what you are
pinning: the bundle is a local file, and it goes stale as public roots rotate.
Rebuild it (or re-run the helper) when a *new* TLS error appears; do not delete the
variable and call it fixed.

## Why not just disable TLS

`composer config --global disable-tls true` switches Composer to plain HTTP and
Packagist rejects it; `--no-secure-http` does the same. Disabling verification
(`COMPOSER_DISABLE_TLS_VERIFICATION`, or an empty CA file) trades a local trust
problem for a silent one on every download, and it hides a genuine certificate
failure behind the same setting later. Appending one known root is the narrow fix.

## The second, unrelated Windows wrinkle: platform requirements

Once TLS works, an `install` / `update` can still stop on a locked dependency's
Linux-only extension:

```
spatie/fork 1.2.7 requires ext-pcntl * -> it is missing from your system.
```

That is not caused by the scanner, and it is why the helper passes
`--ignore-platform-req` for the extensions this project does not have on Windows —
`ext-grpc`, `ext-pcntl`, `ext-posix`, `ext-swoole`, `ext-uv` — for
`install` / `update` / `require` / `remove`. They are no-ops where nothing requires
them. **Never commit a version of `composer.json` / `composer.lock` with these
extensions stripped**: CI runs on Linux and does have them, and a stripped lock is a
lockfile that no longer describes the package.

## Verifying the fix

```powershell
# the bundle must contain the scanner's root
Select-String -Path "$env:TEMP\composer-ca.pem" -Pattern 'BEGIN CERTIFICATE' | Measure-Object

# PHP must now trust the hosts Composer uses
pwsh -File .\bin\composer-ca.ps1 validate --no-check-publish
pwsh -File .\bin\composer-ca.ps1 install --dry-run
```

`composer validate` proves the CA is accepted; `install --dry-run` additionally proves
the lock file is installable on this platform, which is the same check a deploy
performs.

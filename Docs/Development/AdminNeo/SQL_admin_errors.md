# SQL admin error logging

The IDE sends unexpected AdminNeo PHP diagnostics to the workbench logger.
This works with PHP's `display_errors` disabled and does not require
`DEBUG.PRETTIFY_ERRORS`.

- Unexpected PHP warnings are logged at **ERROR**, with a Log-ID, the original
  file and line, and an exception chain for the workbench debug widget. Rendering
  can continue if the warning is not fatal.
- Notices use **WARNING**, and reported deprecations use **NOTICE**. These levels
  follow the workbench's normal buffering and persistence settings.
- Exceptions, PHP `Error` objects and terminating user errors propagate to the
  facade or calling action. The existing error handling logs them and, for HTTP
  requests, builds the normal error response. Errors escaping the AdminNeo launcher
  are labelled `AdminNeo failed`.
- Native fatal errors, such as incompatible method declarations, use Core's
  existing fatal-error shutdown logger. This does not guarantee an error page:
  PHP has already terminated normal execution.

AdminNeo still ignores its expected missing-array-key warnings and notices.
Diagnostics suppressed with `@` or excluded by `error_reporting()` are not logged.
Ordinary SQL errors that AdminNeo handles and displays itself are not PHP crashes
and are not automatically copied to the workbench log.

The bridge is installed before bootstrap for both captured pages and streamed
responses (assets, AJAX and downloads). When AdminNeo returns or throws, the IDE
restores the host PHP error handler, reporting mask, working directory and outer
session. Intentionally streamed responses can call `exit`; they do not return
through this cleanup. Once a stream has sent data, it cannot be replaced reliably
with a complete error page.

An explicit `exit` or `die` without a PHP error is not a crash notification and
does not create a log entry. A blank page with HTTP 200 and no error can still be
an embedded-mode early-exit problem; see the fork's
[troubleshooting notes](../../../../adminneo/docs/Changes_in_this_fork.md#recurring-issues).
Process termination outside PHP, such as a killed worker, cannot be logged by
PHP's shutdown handler.

## Developer validation

From the IDE app root, using the host's PHPUnit binary:

```powershell
php ..\..\bin\phpunit --testsuite unit
php ..\..\bin\phpunit --testsuite integration
```

The unit tests cover diagnostic logging, original error details, suppression,
terminating user errors and restoration of the host handler and reporting mask.
The test bootstrap registers the IDE namespace locally because PackageManager
installations may expose it only through Workbench's live loader.

The integration suite boots the real fork in subprocesses with `display_errors=0`.
A test-only driver allows authentication and settings-page rendering without a
database server. Tests cover forwarded warnings, TypeError, terminating user
errors, native compile fatals, suppression and logging before an early exit.
They use the real Core logger with a recording output handler, not a mock Workbench.

The fake driver is loaded explicitly by the subprocess fixture after AdminNeo
loads its base classes. It is not registered in production, cannot appear in the
normal connection picker and rejects SQL queries. A deliberate incompatible
declaration is evaluated only in the native-fatal scenario.

This verifies the logging bridge and bootstrap, not database-driver correctness,
the complete facade error response, or persistence through the installation's
configured log handlers. Real database operations and full HTTP error workflows
still need application-level checks.

# Validation

Production: https://folio-library-demo.vercel.app

- Production URL returned HTTP 200.
- All 58 demo database checks passed against the deployed app.
- All 25 SQL examples and 6 reports execute.
- Member registration, book issue and return were verified through real forms locally.
- A saved loan persisted after page reload.
- Backup downloaded as a valid SQLite file; the connection remained usable.
- Mobile navigation works. No page overflow at widths 390, 820 and 1440.
- No browser JavaScript errors during final verification.
- Each new visitor receives the original demo dataset without test fixtures.

This verifies the browser demo, not a shared MySQL deployment.

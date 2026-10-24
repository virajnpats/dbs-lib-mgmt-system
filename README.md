# Folio library workspace

A refined, deployable demo of the supplied Library Management System.

**Live demo:** https://folio-library-demo.vercel.app

## Run locally

Run `npm install` then `npm start`, and open http://127.0.0.1:4173. The bundled local server supports the same clean routes as Vercel.

## Separate admin and reader pages

| Route | Page | Capabilities |
| --- | --- | --- |
| `/` | Portal chooser | Open the admin workspace or choose a sample reader profile |
| `/admin` | Admin workspace | Manage books, copies, members, loans, returns, reservations, fines, payments, reports, SQL examples, tests, backups and reset |
| `/user` | Reader portal | Search/filter books, reserve titles, cancel own pending reservations, renew eligible own loans, and view own profile, loans, reservations and fines |

`public/admin.html` and `public/user.html` are separate documents. Vercel rewrites the clean routes to those files. Reader navigation uses `#catalogue`, `#loans`, `#reservations` and `#account`; admin navigation retains its original sections. Old root URLs with an admin hash redirect to `/admin` with that hash preserved.

These are **page routes**, not separate HTTP backend endpoints. Both pages share the same browser SQLite database. `reader.js` supplies the dedicated reader interface; `demo.js` supplies shared data operations with separate action allowlists.

Reader actions are `reader_accounts`, `reader_catalogue`, `reader_profile`, `reader_reserve`, `reader_cancel` and `reader_renew`. Profile and reservation writes derive the member ID from the selected demo profile. Cancel and renewal operations verify that the reservation or loan belongs to that member. Reader calls cannot run admin operations, query all members, record payments, issue/return books, run arbitrary management queries, or export the library database. Staff still record book collection, returns and payments.

**Demo access model:** the admin page is intentionally open, and the reader selector chooses a sample identity. These browser checks separate the workflows but are not authentication or a production security boundary. Secure deployment requires server-side login, roles and ownership checks. The portal chooser and reader page state this explicitly. Switching profiles is for presentation only.

The selected reader is remembered per browser tab. Both portals share local data; another open tab reloads when the database is changed to avoid stale copies overwriting updates.

## Browser regression tests

Run `npm test` after `npm install`. The tests start their own local server and use installed Google Chrome through Playwright. Set `PLAYWRIGHT_CHANNEL` to use another installed browser channel. Screenshots and the result report are written to ignored `test-results/`.

The 24 portal checks cover separate routes, reader ownership, blocked admin actions, search, reservations, admin issue/return, reader renewal, fine visibility, reload persistence and layouts at 390/820/1440px. They also rerun the 58 database tests. To check an existing deployment, set `PORTAL_URL` before running the same tests; this uses an isolated browser context with a fresh demo database.

## Functionality

- Books, author relationships, physical copies, branches and shelves.
- Members, profiles, membership plans and borrowing limits.
- Issue, renew and return; automatic availability and late fines at ₹5/day.
- Reservation priority, expiry, cancellation, extension and fulfilment.
- Manual fines, partial/full payments, recalculated balances and protected deletion.
- Six reports and 25 executable SQL examples.
- Browser persistence, SQLite backup export, reset and 58 demo checks.
- Responsive interface, keyboard focus states and printable reports.

## Demo storage and scope

The hosted site is a single-browser demo. It uses SQLite through sql.js, with all 14 relational tables, foreign keys, unique indexes and selected triggers. Every visitor receives a separate sample database. Changes stay in that browser’s local storage and are not shared across devices or visitors. Clearing browser data removes local changes. Export downloads a SQLite backup; importing backups is not implemented.

Sample transaction dates shift relative to the first visit to keep the demonstration useful. Birth and membership dates retain supplied values. The incorrect availability for copy 101-2 in the sample is corrected using its open loan. Fine statuses are calculated from current balances.

The hosted **58 checks are a new SQLite demo suite**, covering SQL execution, integrity, validation, borrowing, reservations, returns, renewals and payments. Temporary test changes are rolled back. They do not execute the original PHP/MySQL suite.

## Original system

`original/` preserves the uploaded PHP/MySQL project, its schema, upgrades, stored procedures, triggers and tests. It is excluded from static deployment. The demo does not run PHP or MySQL stored procedures. A shared production service still requires a hosted database, server API, authentication and access controls; no production database was connected for this demo.

## Deploy

`vercel.json` serves only `public/`. The SQL engine is bundled locally and does not need a third-party CDN. `.vercelignore` excludes the original PHP source and local dependencies. Deploy with `vercel --prod` after linking a Vercel project.

sql.js license: `public/vendor/LICENSE.sql.js`.

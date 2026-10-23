# Folio library workspace

A refined, deployable demo of the supplied Library Management System.

**Live demo:** https://folio-library-demo.vercel.app

## Run locally

Serve `public/` with a static web server: `python3 -m http.server 4173 --directory public`, then open http://localhost:4173.

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

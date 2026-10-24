# Validation

Production: https://folio-library-demo.vercel.app

## Admin and reader split

- Added `/` chooser, `/admin` management workspace and `/user` reader portal.
- 24 browser regression checks pass, including blocked management calls from the reader page and ownership checks on reader actions.
- Reader searches, reserves, cancels an own reservation, sees a staff-issued loan, renews it and sees an own fine.
- Staff issue and return continue to work against the same local database.
- Identity and data persist after reload; switching a demo identity scopes the account to that member.
- Both workspaces fit 390px, 820px and 1440px viewports.
- No JavaScript page errors in the cross-portal flow.
- All 58 existing demo database tests pass after the cross-portal mutations, including the 25 SQL examples and six reports.

These checks verify demonstration workflow separation. The open admin page and selectable sample reader profiles are not production authentication; no hosted shared database was connected.

## Earlier validation retained

- Book issue and return were verified through real forms.
- A saved loan persisted after page reload.
- Backup downloaded as a valid SQLite database containing 14 tables; integrity check passed.
- Every new visitor receives original sample data without test fixtures.

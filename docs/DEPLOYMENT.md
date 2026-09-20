# TourSync — Deployment Guide

Tampakan Municipal Tourism Office · Tourism Management System
Written for whoever puts this system on the live server. Last audited **2026-09-20**.

The system is Composer-free PHP 8.2 + MySQL/MariaDB. It deploys by uploading
files and importing one SQL file; there is nothing to build.

---

## 1. Before you upload — the five things that must change

These are in `app/config/config.php`, which is **not** in version control. Copy
`app/config/config.sample.php` on the server and fill it in.

| Setting | Development value | Production value | Why it matters |
|---|---|---|---|
| `env` | `development` | **`production`** | Development prints PHP errors, with file paths, to whoever is looking. Production logs them instead and switches on HSTS. |
| `base_url` | `auto` | **the real address**, e.g. `https://tourism.tampakan.gov.ph` | Every QR code on the signage is built from this. A poster printed with the wrong address is a reprint, not a config change. |
| `database.user` / `password` | `root` / empty | **a dedicated user with a password** | `root` with no password is an open database if the server is ever reachable. Grant only `SELECT, INSERT, UPDATE, DELETE` on the `toursync` schema. |
| `sms.driver` | `philsms` (live) | decide deliberately | Live means real texts and real credits on every alert. Use `log` for rehearsals. |
| `security.device_salt` | as shipped | **a fresh 64-character random string** | Per installation. Changing it only resets duplicate detection. |

The Gemini key is optional: leave it blank and the chatbot still answers from
the database; only the advisory questions go quiet.

## 2. Server requirements

- PHP **8.2+** with `pdo_mysql`, `gd`, `fileinfo`, `mbstring`, `curl`, `zip`
- MySQL 8.0+ or MariaDB 10.4+
- Apache with `mod_rewrite`, `mod_headers` and **`AllowOverride All`**
  (the `.htaccess` files are load-bearing: they block `/storage`, stop PHP
  executing inside `/uploads`, and refuse the include-only `_*.php` fragments)
- `upload_max_filesize` and `post_max_size` at **40M** or more, matching each
  other — the event gallery and the video uploader check against them
- HTTPS. The session cookie sets `secure` automatically when the request is
  HTTPS, and `Strict-Transport-Security` is sent in production.

## 3. What to upload — and what to leave behind

Upload the whole project **except** these. None of them is needed for the
system to run, and each is either private or simply noise on a public server:

| Leave behind | Why |
|---|---|
| `tests/` | The automated suites. They run from the command line during development; the website never calls them. They are denied to browsers and refuse to run except on the CLI, so leaving them is not dangerous — but the server has no use for them. |
| `docs/` | This guide. It describes the server layout and the backup commands. |
| `.git/` | The whole history, including anything ever committed by mistake. |
| `.claude/`, `*.log`, editor files | Local working state. |
| `database/*.sql` backups | Old dumps hold real visitor names. Keep them off the server; `schema.sql` is the exception and must be uploaded. |

`reset.php` and `seed-demo.php` were **removed from the project** on 2026-09-20,
once the system had been cleared for launch: one wipes the records and the other
writes sample data, and neither has a use on a system in service. They are kept
in `C:\xampp\TampakanTourism-removed-20260919\files\database\` and in git
history if they are ever wanted again.

**What the `database/` folder is for, file by file:**

| File | Needed on the server? |
|---|---|
| `schema.sql` | **Yes.** The structure `install.php` builds a fresh database from — all 42 tables. |
| `migrate.php` | **Yes.** Upgrades an existing database. Safe to run repeatedly; every step checks before acting. Run it after every deployment. |
| `install.php` | **Once**, for a brand-new database, then it has no further use. It applies `schema.sql`, which DROPS every table first — so it now refuses to run when the database already holds records, and names them. `--force` overrides, deliberately. |
| `prepare-launch.php` | **No**, unless the live server is ever loaded with test data. It clears what testing produced and keeps what the office authored; a dry run unless `--yes`, and it backs up first. |
| `.htaccess` | **Yes.** It denies the whole folder to browsers. |

**`app/config/config.php` is never copied from the development machine.** Create
it on the server from `config.sample.php` and fill in the production values in
section 1 — the development copy holds the local database credentials and the
`development` environment setting.

**Keep `tests/` in the project and in version control.** It is what lets anyone
re-check the whole system after a change: `php tests/run.php` on a development
copy answers "is anything broken?" in about four minutes.

## 3b. The database was prepared for launch on 2026-09-20

`database/prepare-launch.php` cleared everything produced while testing and kept
everything the office authored. What the database holds now:

| Kept | |
|---|---|
| Destinations | **15** (14 active + Mt. Matutum Viewpoint archived) with **39** photographs and their routes |
| Tour guides | **19**, with **21** certificates and **26** credentials |
| Compliance requirements | **5** |
| Office settings | **83** · About photographs **5** · hero slides **3** · categories **9** |
| Officer sign-in | **1** — unchanged, sign in as you always have |

Cleared: 9,907 rows — arrivals, reports and logbook pages, inspections, alerts,
inbound texts, messages, reviews, guide bookings, change requests, notifications,
the activity log, the 8 demo destinations, all announcements and events, the
test videos, and the 5 test manager sign-ins. Roughly 87 MB of uploads went with
them.

A backup was written first to `C:\xampp\TourSync-backups\`. **That file is the
only way back** — keep it until the office is satisfied.

**After deploying, the office adds:** the real destination managers (each gets a
temporary password they must change at first sign-in) and their own
announcements and events. Until then the homepage shows "No events scheduled at
the moment", which is true rather than a leftover from a rehearsal.

The script can be run again on the live server if it is ever loaded with test
data: it is a dry run unless you pass `--yes`, and it backs up first.

## 4. Installing

**A fresh database:**

```bash
php database/install.php      # creates the schema, seeds categories + settings,
                              # and prints the first officer password ONCE
php database/migrate.php      # safe to run; it will report everything as "skip"
```

**Moving this office's database to the host (z.com or any cPanel host) —
this is the path being used.**

Only `toursync` is needed; it is the live, current database. Export it fresh —
not an older backup, which will be missing whatever changed since:

```bash
"C:\xampp\mysql\bin\mysqldump.exe" -u root ^
    --single-transaction --default-character-set=utf8mb4 --no-tablespaces ^
    toursync > toursync-for-upload.sql
```

Why those flags: `--single-transaction` takes a consistent copy without
locking; `--no-tablespaces` avoids the PROCESS-privilege error that stops an
import on managed hosting; and leaving out `--databases` means the file
contains **no CREATE DATABASE line**, so it imports into whatever name the host
gives you — shared hosts usually prefix it, e.g. `tampakan_toursync`.

A current export is already in the project at **`database/toursync.sql`** (about
180 KB, taken 2026-09-20 after the launch clean-up). Refresh it with the command
above whenever the database changes; the folder's `.htaccess` denies it to
browsers, and `.gitignore` keeps it out of version control because it holds the
guides' names, numbers and photographs.

Then, in the host's control panel:

1. **MySQL Databases** → create a database and a user, and grant that user all
   privileges on it. Write down all three values.
2. **phpMyAdmin** → select the new database → **Import** → choose the `.sql`
   file → Go. (The file is about 160 KB, far under any upload limit.)
3. Put those three values into `app/config/config.php` on the server. On shared
   hosting `host` is almost always `localhost`, not the site's domain.
4. `php database/migrate.php` if the host offers SSH. If it does not, skip it —
   a fresh export already carries every change.

**Verified on 2026-09-20:** the exported file was imported into a differently
named database and compared against the live one — 42 tables and all 582 lines
of columns, types and foreign keys identical, with 15 destinations, 19 guides,
21 certificates, 39 photographs, 83 settings and the officer account present.

Upload `uploads/` and `storage/` with the code: the database stores the file
NAMES, so without those folders every photograph and certificate is a broken
link.

`database/schema.sql` is generated from the live structure (42 tables) and is
what `install.php` applies. `migrate.php` is idempotent — every step checks
before it acts, so running it twice changes nothing the second time.

**Files to copy with the code:** `uploads/` (photographs, event galleries,
videos) and `storage/` (scanned certificates, inspection photos, report
documents). Both must stay writable by the web server. `storage/` must NOT be
reachable from the web — the shipped `.htaccess` denies it; confirm after
deploying.

## 5. After deploying — verify, in this order

1. Open the public homepage. The loader appears and clears by itself.
2. Sign in at `/admin/login.php`. Change the installer password immediately.
3. `/admin/settings/index.php` → check the office name, address and phone; these
   print on every report.
4. **Check a QR code**: `/admin/qrcodes/index.php`. If the page warns that the
   address is a local one, `base_url` is still wrong — fix it before printing.
5. Print one report (`/admin/reports/` → Print) and confirm the letterhead and
   page breaks look right on the office's own printer.
6. Confirm `https://<site>/storage/certificates/` returns **403**, and that a
   `.php` file inside `uploads/` is not executed.

## 6. Backup and rollback

**Before every deployment:**

```bash
mysqldump -u <user> -p --single-transaction toursync > backup-YYYYMMDD.sql
tar -czf files-YYYYMMDD.tar.gz uploads storage app/config/config.php
```

The office can also take a database backup from the admin: **Settings → System
→ Backup**, which writes a full SQL dump.

**Rollback:**

1. Put the site in maintenance mode (Settings → System → Maintenance) or upload
   the previous release.
2. Restore the code: replace the folder with the previous release.
3. Restore the database: `mysql -u <user> -p toursync < backup-YYYYMMDD.sql`.
4. Restore `uploads/` and `storage/` from the tarball.

Nothing in `migrate.php` drops a table that holds data; the one table it does
drop (`destination_heritage`, retired 2026-09-19) was exported first to
`C:\xampp\TampakanTourism-removed-20260919\database\`.

## 7. Routine care

- **Stop MySQL before shutting the machine down.** Every start since August has
  run InnoDB crash recovery, which means it is being powered off while running.
- Old read notifications clear themselves daily (six months, read by everyone).
  The activity log is never trimmed: it is the audit trail.
- The demo rows can be removed with `php database/seed-demo.php --undo`. It
  deletes by recorded id, so never rename a demo record into a real one.

## 8. Known non-blocking items

- **`'unsafe-inline'` is still in the Content-Security-Policy.** About forty
  pages carry an inline `<script>`; removing it needs a nonce on each. The
  policy still blocks foreign script hosts, framing and form hijacking.
- **Two 39 MB videos** sit in `uploads/videos/` and appear to be the same file
  twice. They are `preload="metadata"`, so a visitor downloads only a few
  hundred KB until they press play, but they are worth compressing.
- **Several 2–3 MB PNG photographs.** Re-saving them as JPEG or WebP would cut
  the homepage weight substantially.
- **Analytics (`/admin/analytics/`) is not linked from the sidebar.** It still
  works at its address and holds the forecast and recommendations.
- **138 historical logbook entries have no gender recorded.** They predate the
  requirement; the DOT form shows them as unspecified.

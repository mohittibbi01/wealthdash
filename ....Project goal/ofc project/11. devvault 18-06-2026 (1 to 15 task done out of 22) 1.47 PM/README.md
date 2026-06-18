# 🔐 DevVault Pro v2.5
### Team Credential & Project Infrastructure Manager

---

## ✅ Requirements
- **PHP 7.4+** with `pdo_sqlite` and `openssl` extensions (usually built-in)

```bash
php -m | grep -E "pdo_sqlite|openssl"
```

## 🚀 Quick Start

```bash
cd devvault2
php -S 0.0.0.0:8080
```

| Access | URL |
|---|---|
| This PC | `http://localhost:8080` |
| LAN (team) | `http://<your-ip>:8080` |

Default login: **admin / admin123** — change immediately after first login.

---

## ✨ Features (v2.5)

### Core
- Login/Logout, session-based auth, CSRF protection
- Roles: **Admin**, **Member**, **Viewer** (read-only) + Active/Inactive toggle
- Live dashboard stats (auto-refresh every 30s, no page reload)
- Theme customizer: accent color, background color, dark/light mode, font

### Project Data
- Project Info: technology, website/app type, status (Live, Under Dev, Redevelopment,
  **Hold by Department**, **Content Updation in Progress**, Closed), visitor counters, audit dates
- Department Info: **Parent/Admin Department**, primary nodal officer
- **Multiple additional contact persons** per project (add/remove dynamically)
- Environments: Local, Staging, Production, Audit, Other — each with URL, Admin URL,
  Login ID, encrypted Password, Remark
- App Infrastructure: IP, LB IP, OS, Core/RAM/Storage, hosting type
- DB Infrastructure: IP, Name, **DB Technology** (MySQL/MongoDB/PostgreSQL/NoSQL/etc),
  DB Version, OS, hosting type
- General remarks / notes

### New in v2.5
- 📎 **Document Uploads** — attach SOE, UAT, Audit, or Other docs per project
  (pdf/doc/xlsx/jpg/zip/etc, max 10MB each), download/delete from edit form
- ✅ **Website Compliance Checklist** — per-project checklist (logos, minister photo,
  SSL, accessibility, sitemap, etc.) with notes; admin can add/remove checklist items
- 📥 **Import Projects** — bulk import from JSON or CSV (add/skip/update modes)
- 📋 **One-Page Report** — live Excel-style filterable/sortable table of all projects,
  export visible rows to CSV
- 📊 Dashboard stats: **Total Unique App Servers** & **Total Unique DB Servers**
  (distinct IPs across projects)

---

## 📁 Folder Structure
```
devvault2/
├── index.php          ← Dashboard
├── project_form.php   ← Add/Edit project (all sections)
├── admin.php          ← Users, roles, dropdown options, checklist items, logs
├── import.php         ← Bulk import (JSON/CSV)
├── report.php         ← One-page live-filter report
├── export.php         ← JSON / CSV / printable report
├── api.php            ← AJAX endpoints (passwords, stats, docs, prefs)
├── config.php / auth.php
└── data/
    ├── vault.db        ← SQLite — everything lives here
    ├── vault_backup.json
    └── uploads/         ← uploaded documents
```

---

## 💾 Portable — PC Change Karna Ho
1. Copy the whole `devvault2/` folder (incl. `data/`)
2. Paste on new PC
3. `php -S 0.0.0.0:8080`
4. ✅ Done — same data, users, documents, everything

---

## 🔒 Security Notes
- Passwords stored AES-256 encrypted
- Change `ENCRYPT_KEY` in `config.php` before production use
- Uploaded file types restricted to: pdf, doc, docx, xls, xlsx, jpg, jpeg, png, zip, txt, csv

---

## 🛠 Troubleshooting

**SQLite missing:** `sudo apt install php-sqlite3`
**Port busy:** `php -S 0.0.0.0:9090`
**Permission error on data/:** `chmod -R 755 data/`

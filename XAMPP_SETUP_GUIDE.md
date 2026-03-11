# WealthDash — XAMPP Setup Guide

## Prerequisites
- Windows 10/11 (or macOS/Linux with XAMPP)
- XAMPP 8.2+ (includes PHP 8.2 + MySQL 8.0)

---

## Step 1: Install XAMPP

1. Download from: **https://www.apachefriends.org/download.html**
   - Choose **XAMPP 8.2.x** for Windows (installer ~170 MB)
2. Run installer → Install to `C:\xampp` (default)
3. At startup screen: check **Apache** + **MySQL** only

---

## Step 2: Start Services

1. Open **XAMPP Control Panel** (from Start Menu or `C:\xampp\xampp-control.exe`)
2. Click **Start** next to **Apache** → should turn green
3. Click **Start** next to **MySQL** → should turn green
4. Visit `http://localhost` in browser → should show XAMPP welcome page ✅

---

## Step 3: Create Database

1. Open **phpMyAdmin**: `http://localhost/phpmyadmin`
2. Click **New** (left sidebar)
3. Database name: `wealthdash`
4. Collation: `utf8mb4_unicode_ci`
5. Click **Create**

---

## Step 4: Import Schema

1. In phpMyAdmin, click on the `wealthdash` database
2. Click **Import** tab
3. Click **Browse** → select `wealthdash/database/schema.sql`
4. Click **Import** → should show "Import has been successfully finished" ✅
5. Repeat for `database/seed.sql` (optional seed data for testing)
6. Repeat for `database/migrations/003_phase5_advanced.sql`

---

## Step 5: Copy Project Files

1. Copy the `wealthdash` folder to:
   ```
   C:\xampp\htdocs\wealthdash\
   ```
2. Final path should be: `C:\xampp\htdocs\wealthdash\index.php`

---

## Step 6: Configure Environment

1. Copy `.env.example` → `.env` in the project root
2. Edit `.env` with your settings:

```env
# Database (XAMPP defaults)
DB_HOST=localhost
DB_NAME=wealthdash
DB_USER=root
DB_PASS=

# App
APP_ENV=local
APP_URL=http://localhost/wealthdash
APP_SECRET=change_this_to_any_random_32_char_string

# Google reCAPTCHA (get free keys from google.com/recaptcha)
RECAPTCHA_SITE_KEY=your_site_key
RECAPTCHA_SECRET_KEY=your_secret_key

# Google OAuth (optional — for Gmail login)
GOOGLE_CLIENT_ID=xxxx.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=xxxx
GOOGLE_REDIRECT_URI=http://localhost/wealthdash/auth/google_callback.php

# Email (Gmail SMTP — for password reset emails)
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USERNAME=yourgmail@gmail.com
MAIL_PASSWORD=your_gmail_app_password
MAIL_FROM_NAME=WealthDash

# SMS OTP (optional — MSG91 or Fast2SMS)
SMS_PROVIDER=msg91
MSG91_AUTH_KEY=
MSG91_TEMPLATE_ID=
```

> **Note:** For local testing, reCAPTCHA and SMS are optional — you can disable them in `config/constants.php` by setting `RECAPTCHA_ENABLED=false`.

---

## Step 7: Install Composer Dependencies

1. Download Composer: **https://getcomposer.org/Composer-Setup.exe**
2. Install Composer (it auto-detects PHP from XAMPP)
3. Open Command Prompt:
   ```cmd
   cd C:\xampp\htdocs\wealthdash
   composer install
   ```
4. This installs: `google/apiclient` + `phpmailer/phpmailer`

> If Composer is not found: add `C:\xampp\php` to Windows PATH.

---

## Step 8: First Run

1. Open browser: **`http://localhost/wealthdash`**
2. You'll be redirected to the login page
3. Click **Register** to create first account
4. The first registered user automatically becomes **Admin**
5. Login with your new credentials

---

## Step 9: Import AMFI Fund List (One-Time)

This imports ~16,000 mutual fund schemes from AMFI India. Required for MF features.

1. Login as Admin
2. Go to **Admin Panel** → **NAV & Data** tab
3. Click **Import Full Fund List**
4. Wait 3–5 minutes (progress shown on screen)
5. You'll see confirmation: "Imported X funds" ✅

---

## Step 10: Set Up Daily NAV Updates

### Option A: Windows Task Scheduler (Recommended)

1. Open **Task Scheduler** (search in Start Menu)
2. Click **Create Basic Task**
3. Name: `WealthDash NAV Update`
4. Trigger: **Daily** at **10:15 PM**
5. Action: **Start a program**
   - Program: `C:\xampp\php\php.exe`
   - Arguments: `C:\xampp\htdocs\wealthdash\cron\update_nav_daily.php`
6. Finish → Task created ✅

### Option B: Manual Update

- Login → Admin Panel → NAV & Data → **Update Today's NAV**

---

## Troubleshooting

| Problem | Fix |
|---------|-----|
| Apache not starting | Port 80 conflict — change Apache port to 8080 in XAMPP config |
| MySQL not starting | Port 3306 conflict — check if another MySQL is running |
| "Access denied" for DB | In `.env`, `DB_USER=root` and `DB_PASS=` (blank, no quotes) |
| Blank page / 500 error | Enable PHP errors: add `display_errors=On` in `C:\xampp\php\php.ini` |
| Composer not found | Run `C:\xampp\php\php.exe composer.phar install` from project folder |
| Google OAuth not working | Add `http://localhost/wealthdash/auth/google_callback.php` as authorized redirect URI in Google Console |

---

## Switching to cPanel (Production)

1. Create MySQL DB in cPanel → import `schema.sql` + migrations
2. Upload all files via File Manager or FTP to `public_html/wealthdash/`
3. Update `.env`: change `APP_URL`, `DB_*` credentials
4. In cPanel Cron Jobs: add `php /home/user/public_html/wealthdash/cron/update_nav_daily.php` at 10:15 PM
5. Ensure PHP 8.2+ is selected in cPanel PHP version selector

---

*WealthDash XAMPP Guide — Phase 5*

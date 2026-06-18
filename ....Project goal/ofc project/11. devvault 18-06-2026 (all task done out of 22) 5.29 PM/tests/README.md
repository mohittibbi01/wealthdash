# DevVault Pro — Test Suite

## Setup
```bash
# 1. Install Composer (if not already installed)
php -r "copy('https://getcomposer.org/installer', 'composer-setup.php');"
php composer-setup.php
php -r "unlink('composer-setup.php');"

# 2. Install PHPUnit
php composer.phar install

# 3. Run all tests
./vendor/bin/phpunit

# 4. Run specific test file
./vendor/bin/phpunit tests/EncryptionTest.php
./vendor/bin/phpunit tests/AuthTest.php
./vendor/bin/phpunit tests/ValidationTest.php

# 5. Run with coverage (requires Xdebug)
./vendor/bin/phpunit --coverage-text
```

## Test Files

| File | Tests | What it covers |
|---|---|---|
| `EncryptionTest.php` | 9 tests | encrypt/decrypt roundtrip, PBKDF2 v2 format, legacy backward compat |
| `AuthTest.php` | 10 tests | can_edit(), CSRF generation, verify_csrf(), user_pref() |
| `ValidationTest.php` | 14 tests | IP, email, phone, date, URL validation + CSS injection sanitization |

## Expected Output
```
DevVault Pro Test Suite
.........................................

33 tests, 33 assertions
OK (33 tests, 33 assertions)
```

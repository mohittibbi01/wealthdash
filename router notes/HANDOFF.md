# WealthDash — Handoff Summary
Worker: ID-W4 | Tasks: t139, t355, t380, t381

---

## t139 — Goal-Based Buckets ✅

**Files:**
- `api/goals/goal_buckets.php` — No changes (already complete)
- `database/migrations/t139_migration.sql` — Complete

**Router routes to add (api/router.php):**
```php
// ── t139: Goal-Based Buckets ──────────────────────────
case 'bucket_list':
case 'bucket_summary':
case 'bucket_progress':
case 'bucket_add':
case 'bucket_edit':
case 'bucket_delete':
case 'bucket_contribute':
case 'bucket_link_asset':
case 'bucket_unlink_asset':
case 'bucket_mark_achieved':
    require APP_ROOT . '/api/goals/goal_buckets.php'; exit;
```

**New tables:** `goal_bucket_contributions`
**Altered tables:** `goal_buckets` (added: bucket_type, monthly_target, current_amount, risk_profile, priority)

---

## t355 — Custom Bucket Strategy UI ✅

**Files:**
- `api/goals/bucket_strategy.php` — Patched: `user_settings` → `user_kv_settings`
- `database/migrations/t355_migration.sql` — Complete

**Router routes (already exist in router.php — no change needed)**

**New tables:** `user_kv_settings`, `bucket_strategy_presets`

---

## t380 — AI Portfolio Review ✅

**Files:**
- `api/reports/ai_portfolio_review.php` — New file
- `database/migrations/t380_migration.sql` — Complete

**Router routes to add (api/router.php):**
```php
// ── t380: AI Portfolio Review ────────────────────────
case 'ai_review_generate':
case 'ai_review_get':
case 'ai_review_history':
case 'ai_review_status':
case 'ai_review_prefs_get':
case 'ai_review_prefs_save':
case 'ai_review_delete':
    require APP_ROOT . '/api/reports/ai_portfolio_review.php'; exit;
```

**Config required:** `define('ANTHROPIC_API_KEY', 'sk-ant-...')` in config.php

**New tables:** `ai_portfolio_reviews`, `ai_review_prefs`, `ai_usage_log`

**Daily limit:** 3 on-demand reviews/user/day

---

## t381 — AI Chat Assistant ✅

**Files:**
- `api/ai/ai_chat.php` — New file
- `database/migrations/t381_migration.sql` — Complete

**Router routes to add (api/router.php):**
```php
// ── t381: AI Chat Assistant ──────────────────────────
case 'ai_chat_message':
case 'ai_chat_session_new':
case 'ai_chat_session_get':
case 'ai_chat_session_delete':
case 'ai_chat_history':
case 'ai_chat_usage':
case 'ai_chat_clear_all':
    require APP_ROOT . '/api/ai/ai_chat.php'; exit;
```

**Config required:** Same `ANTHROPIC_API_KEY` as t380

**New tables:** `ai_chat_sessions`, `ai_chat_messages` (uses `ai_usage_log` from t380 migration)

**Daily limit:** 30 chat messages/user/day

---

## Overwrite Policy

| File | Overwrite? |
|------|-----------|
| `api/goals/goal_buckets.php` | Yes — same content, no changes |
| `api/goals/bucket_strategy.php` | Yes — minor patch (user_kv_settings) |
| `database/migrations/t139_migration.sql` | Yes — same content as existing |
| Other new files | N/A — new files |


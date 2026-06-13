# WealthDash API Reference

**Version:** 1.0 · **Base URL:** `/api/router.php?action=` · **Auth:** Session cookie (`wd_session`)

---

## Authentication

All endpoints require an active session. Login via `POST /auth/login.php`.

```
Cookie: wd_session=<session_id>
X-Requested-With: XMLHttpRequest
```

Write operations require a CSRF token in the JSON body:
```json
{ "_csrf_token": "<token_from_csrf_token()>" }
```

---

## Response Format

All endpoints return JSON:
```json
{
  "success": true,
  "message": "Optional message",
  "data": { ... }
}
```

---

## Admin: Audit Log (t307)

| Action | Method | Auth | Description |
|--------|--------|------|-------------|
| `al_list` | GET | Admin | Paginated audit log with filters |
| `al_stats` | GET | Admin | Stats, trends, top actions |
| `al_get` | GET | Admin | Single entry by `?id=` |
| `al_export` | GET | Admin | CSV export `?days=30` |
| `al_purge` | POST | Admin | Purge entries older than N days |
| `al_retention_save` | POST | Admin | Update retention config |
| `al_retention_get` | GET | Admin | Get retention config |
| `al_filters` | GET | Admin | Distinct actions/entity types |

**al_list params:** `?page=1&per_page=50&user_id=&entity_type=&action=&severity=info|warning|critical&date_from=YYYY-MM-DD&date_to=YYYY-MM-DD&search=`

---

## Admin: Performance Monitor (t308)

| Action | Method | Auth | Description |
|--------|--------|------|-------------|
| `perf_live` | GET | Admin | Live stats (last 5 min + by minute) |
| `perf_history` | GET | Admin | Historical `?days=7&action=` |
| `perf_slow_alerts` | GET | Admin | Slow request alerts |
| `perf_percentiles` | GET | Admin | P50/P95/P99 `?days=1&action=` |
| `perf_purge` | POST | Admin | Purge old perf data |
| `perf_actions` | GET | Admin | Distinct action names (last 7d) |

---

## Admin: Multi-user Management (t50)

| Action | Method | Auth | Description |
|--------|--------|------|-------------|
| `mu_list` | GET | Admin | List users with filters/search/page |
| `mu_get` | GET | Admin | User detail + sessions + activity |
| `mu_add` | POST | Admin | Create user |
| `mu_edit` | POST | Admin | Update user |
| `mu_delete` | POST | Admin | Delete user |
| `mu_toggle_status` | POST | Admin | Active ↔ Suspended |
| `mu_change_role` | POST | Admin | Change role |
| `mu_reset_password` | POST | Admin | Reset password + kill sessions |
| `mu_kill_sessions` | POST | Admin | Terminate all sessions |
| `mu_invite` | POST | Admin | Generate invite URL |
| `mu_invitations` | GET | Admin | List pending invitations |
| `mu_activity` | GET | Admin | Activity log |

---

## Admin: System Health (t51)

| Action | Method | Auth | Description |
|--------|--------|------|-------------|
| `health_full` | GET | Admin | Full health report (PHP, DB, disk, cache) |
| `health_ping` | GET | Admin | Quick DB + PHP ping |
| `health_history` | GET | Admin | Snapshots trend |
| `health_clear_log` | POST | Admin | Clear PHP error log |
| `health_phpinfo` | GET | Admin | PHP extensions + ini |

---

## Admin: Global Settings (t52)

| Action | Method | Auth | Description |
|--------|--------|------|-------------|
| `gs_list` | GET | Admin | All settings grouped `?group=auth` |
| `gs_get` | GET | Admin | Single setting `?key=` |
| `gs_save` | POST | Admin | Save single `{key, value}` |
| `gs_save_bulk` | POST | Admin | Save group `{settings:{key:val,...}}` |
| `gs_reset` | POST | Admin | Reset to default `{key}` |
| `gs_maintenance_toggle` | POST | Admin | Toggle maintenance mode |
| `gs_audit` | GET | Admin | Settings change history |
| `gs_test_email` | POST | Admin | Send test email `{to}` |

---

## Admin: DB Manager (t53)

| Action | Method | Auth | Description |
|--------|--------|------|-------------|
| `dbm_tables` | GET | Admin | Table list + sizes |
| `dbm_describe` | GET | Admin | Table structure `?table=` |
| `dbm_preview` | GET | Admin | First N rows `?table=&limit=50` |
| `dbm_query` | POST | Admin | SELECT/SHOW only |
| `dbm_execute` | POST | Admin | DML (requires confirm_token) |
| `dbm_confirm_token` | POST | Admin | Get today's write token |
| `dbm_history` | GET | Admin | Query log |
| `dbm_backup` | POST | Admin | mysqldump backup |
| `dbm_backup_list` | GET | Admin | Backup history |
| `dbm_optimize` | POST | Admin | OPTIMIZE TABLE `{table}` |
| `dbm_index_report` | GET | Admin | Tables missing indexes |
| `dbm_variables` | GET | Admin | MySQL performance vars |

---

## External API Key Manager (t335)

| Action | Method | Auth | Description |
|--------|--------|------|-------------|
| `extapi_list` | GET | User | List my API keys |
| `extapi_create` | POST | User | Create key `{name, scopes[], rate_limit, expires_at}` |
| `extapi_toggle` | POST | User | Activate/deactivate `{id}` |
| `extapi_delete` | POST | User | Delete key `{id}` |
| `extapi_regenerate` | POST | User | Regen key `{id}` |
| `extapi_log` | GET | User | Usage log `?key_id=` |
| `extapi_scopes` | GET | User | Available scopes |

---

## External REST API (t335)

**Base:** `/api/external/v1/`  
**Auth:** `Authorization: Bearer wdx_<key>` or `?api_key=wdx_<key>`

| Endpoint | Scope Required | Description |
|----------|---------------|-------------|
| `GET /portfolio` | `portfolio:read` | Net worth summary |
| `GET /networth` | `networth:read` | Net worth + history |
| `GET /mf/holdings` | `mf:read` | MF holdings |
| `GET /stocks/holdings` | `stocks:read` | Stock holdings |
| `GET /fd/accounts` | `fd:read` | FD accounts |
| `GET /savings/accounts` | `savings:read` | Savings accounts |
| `GET /banks/accounts` | `banks:read` | Bank accounts |
| `GET /nps/holdings` | `nps:read` | NPS holdings |
| `GET /tax/summary` | `tax:read` | Tax summary |
| `GET /goals` | `goals:read` | Investment goals |

---

## Data Versioning (t336)

| Action | Method | Auth | Description |
|--------|--------|------|-------------|
| `dv_list` | GET | User | Import history `?type=&status=` |
| `dv_get` | GET | User | Version detail + row changes `?id=` |
| `dv_undo` | POST | User | Undo import `{id}` |
| `dv_delete` | POST | User | Delete version record `{id}` |
| `dv_purge` | POST | Admin | Purge old undone records `{days}` |
| `dv_stats` | GET | User | Stats by import type |

---

## Error Monitoring (t414)

| Action | Method | Auth | Description |
|--------|--------|------|-------------|
| `err_list` | GET | Admin | Error events `?resolved=0&type=&search=&page=` |
| `err_resolve` | POST | Admin | Mark resolved `{id, notes}` |
| `err_unresolve` | POST | Admin | Reopen `{id}` |
| `err_delete` | POST | Admin | Delete event `{id}` |
| `err_purge_resolved` | POST | Admin | Purge all resolved |
| `err_types` | GET | Admin | Error type breakdown |
| `err_trend` | GET | Admin | Daily trend `?days=7` |
| `err_capture` | POST | User | Capture JS error `{type, message, file, line, trace}` |

---

## Bank Accounts (t43)

| Action | Method | Auth | Description |
|--------|--------|------|-------------|
| `bank_list` | GET | User | List accounts `?status=active` |
| `bank_get` | GET | User | Account detail + txns + history `?id=` |
| `bank_add` | POST | User | Add account |
| `bank_edit` | POST | User | Edit account |
| `bank_delete` | POST | User | Delete account `{id}` |
| `bank_update_balance` | POST | User | Manual balance update `{id, balance, date}` |
| `bank_summary` | GET | User | Total balance + cashflow |
| `bank_balance_history` | GET | User | Chart data `?account_id=` |
| `bank_txn_list` | GET | User | Transactions `?account_id=&limit=&offset=` |
| `bank_txn_add` | POST | User | Add transaction |
| `bank_txn_delete` | POST | User | Delete transaction `{txn_id}` |
| `banks_list` | GET | User | Bank name list for autocomplete |

---

## Common Error Codes

| Code | Meaning |
|------|---------|
| 400 | Bad request / unknown action |
| 401 | Not authenticated |
| 403 | Forbidden (wrong role) |
| 404 | Record not found |
| 429 | Rate limit exceeded (external API) |
| 500 | Internal server error |

---

## Rate Limits

| Tier | Limit |
|------|-------|
| Internal API (session) | No hard limit (admin-controlled) |
| External API (key) | Configurable per key (default 60 req/min) |

---

*Generated: <?= date('Y-m-d') ?> · WealthDash v1.0 · Worker: ID-M*

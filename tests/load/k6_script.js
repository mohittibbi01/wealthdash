/**
 * WealthDash — k6 Load Test Script [t415]
 * File: tests/load/k6_script.js
 * Worker: ID-M
 *
 * Install k6: https://k6.io/docs/get-started/installation/
 * Run:
 *   k6 run tests/load/k6_script.js
 *   k6 run --vus 20 --duration 30s tests/load/k6_script.js
 *   k6 run -e BASE_URL=http://localhost/wealthdash tests/load/k6_script.js
 */

import http from 'k6/http';
import { check, sleep } from 'k6';
import { Rate, Trend, Counter } from 'k6/metrics';

const BASE_URL = __ENV.BASE_URL || 'http://localhost/wealthdash';
const API_URL  = `${BASE_URL}/api/router.php`;
const SESSION  = __ENV.SESSION_COOKIE || '';

// Custom metrics
const errorRate   = new Rate('wd_error_rate');
const apiDuration = new Trend('wd_api_duration', true);
const apiCalls    = new Counter('wd_api_calls');

// ── Test scenarios ────────────────────────────────────────────────────────────
export const options = {
    scenarios: {
        // Smoke test — minimal load to check things work
        smoke: {
            executor: 'constant-vus',
            vus: 2, duration: '10s',
            tags: { scenario: 'smoke' },
        },
        // Average load
        average: {
            executor: 'ramping-vus',
            startVUs: 0,
            stages: [
                { duration: '10s', target: 10 },
                { duration: '30s', target: 10 },
                { duration: '10s', target: 0  },
            ],
            tags: { scenario: 'average' },
            startTime: '15s',
        },
        // Spike test
        spike: {
            executor: 'ramping-vus',
            startVUs: 0,
            stages: [
                { duration: '5s',  target: 50 },
                { duration: '10s', target: 50 },
                { duration: '5s',  target: 0  },
            ],
            tags: { scenario: 'spike' },
            startTime: '70s',
        },
    },
    thresholds: {
        'http_req_failed':   ['rate<0.05'],          // <5% errors
        'http_req_duration': ['p(95)<1000'],          // 95% of reqs < 1s
        'wd_error_rate':     ['rate<0.05'],
        'wd_api_duration':   ['p(95)<1000', 'avg<400'],
    },
};

// ── Headers ───────────────────────────────────────────────────────────────────
function headers() {
    const h = { 'X-Requested-With': 'XMLHttpRequest' };
    if (SESSION) h['Cookie'] = SESSION;
    return h;
}

// ── Read-only endpoints to test ───────────────────────────────────────────────
const READ_ENDPOINTS = [
    '?action=health_ping',
    '?action=fd_list',
    '?action=savings_list',
    '?action=bank_list',
    '?action=bank_summary',
    '?action=al_list',
    '?action=gs_list',
    '?action=dbm_tables',
    '?action=perf_live',
    '?action=extapi_scopes',
    '?action=dv_stats',
    '?action=err_types',
];

// ── Main test function ────────────────────────────────────────────────────────
export default function () {
    // Pick a random endpoint
    const ep  = READ_ENDPOINTS[Math.floor(Math.random() * READ_ENDPOINTS.length)];
    const url = API_URL + ep;

    const start = Date.now();
    const res   = http.get(url, { headers: headers(), timeout: '10s' });
    const ms    = Date.now() - start;

    apiCalls.add(1);
    apiDuration.add(ms);

    const isJson = res.headers['Content-Type']?.includes('application/json');
    let   data   = null;
    let   ok     = false;

    try {
        if (isJson || res.body?.startsWith('{')) {
            data = JSON.parse(res.body);
            ok   = data?.success === true || data?.success === false; // must have 'success' key
        }
    } catch (_) {}

    const passed = check(res, {
        'status is 200':          (r) => r.status === 200,
        'response is JSON':       ()  => isJson || res.body?.startsWith('{'),
        'has success key':        ()  => ok,
        'response time < 1000ms': ()  => ms < 1000,
    });

    errorRate.add(!passed);
    sleep(0.1 + Math.random() * 0.4); // 100-500ms think time
}

// ── Setup (runs once) ─────────────────────────────────────────────────────────
export function setup() {
    const res = http.get(`${API_URL}?action=health_ping`, { headers: headers() });
    if (res.status !== 200) {
        throw new Error(`Health check failed! Status: ${res.status}. Is ${BASE_URL} running?`);
    }
    console.log(`✓ WealthDash is up at ${BASE_URL}`);
    return { base_url: BASE_URL };
}

// ── Teardown (runs once) ──────────────────────────────────────────────────────
export function teardown(data) {
    console.log(`\n✓ Load test complete against ${data.base_url}`);
}

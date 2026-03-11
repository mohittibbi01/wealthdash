<?php
/**
 * WealthDash — Stock Search Autocomplete
 * GET /api/?action=stocks_search&q=RELI
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not permitted.');

$q = trim(clean($_GET['q'] ?? ''));
if (strlen($q) < 1) json_response(true, '', []);

$results = DB::fetchAll(
    "SELECT id, symbol, company_name, exchange, sector, latest_price, latest_price_date
     FROM stock_master
     WHERE symbol LIKE ? OR company_name LIKE ?
     ORDER BY symbol ASC
     LIMIT 15",
    [$q . '%', '%' . $q . '%']
);

json_response(true, '', $results);


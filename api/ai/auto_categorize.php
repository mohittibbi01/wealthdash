<?php
/**
 * WealthDash — t59: AI Auto Categorization
 * File: api/ai/auto_categorize.php
 * Actions: ai_categorize_transaction, ai_categorize_bulk, ai_category_rules_list,
 *          ai_category_rule_add, ai_category_rule_delete
 *
 * Auto-categorizes budget transactions (t471) and fund holdings by name
 * pattern matching + optional Claude AI fallback for ambiguous cases.
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId = (int)$_SESSION['user_id'];

// Built-in keyword rules (checked before user custom rules)
function _builtin_rules(): array {
    return [
        'Groceries'       => ['bigbasket','grofers','blinkit','zepto','dmart','grocery','supermarket','more retail'],
        'Transport'       => ['uber','ola','rapido','petrol','diesel','fuel','metro','irctc','fastag'],
        'Dining Out'      => ['zomato','swiggy','restaurant','cafe','dominos','mcdonald','kfc','starbucks'],
        'Utilities'       => ['electricity','water bill','gas bill','broadband','wifi','airtel','jio','recharge'],
        'Entertainment'   => ['netflix','prime video','hotstar','spotify','bookmyshow','pvr','inox'],
        'Shopping'        => ['amazon','flipkart','myntra','ajio','nykaa'],
        'Healthcare'      => ['pharmacy','hospital','clinic','apollo','medplus','doctor','medical'],
        'Insurance EMI'   => ['lic','hdfc life','icici prudential','term plan','health insurance'],
        'Loan EMI'        => ['emi','home loan','car loan','personal loan'],
        'Investments/SIP' => ['sip','mutual fund','zerodha','groww','upstox','coin'],
        'Education'       => ['school fee','tuition','course','udemy','coursera','byju'],
        'Housing/Rent'    => ['rent','maintenance','society','landlord'],
    ];
}

switch ($action) {

    // ── Categorize a single transaction description ─────────────────
    case 'ai_categorize_transaction': {
        $desc = clean($_POST['description'] ?? '');
        if (!$desc) json_response(false, 'Description required.');

        $category = _rule_match($userId, $desc);

        if ($category) {
            json_response(true,'ok',['category'=>$category,'confidence'=>'high','method'=>'rule']);
        }

        // Fallback to AI for ambiguous descriptions
        $apiKey = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : ($_ENV['ANTHROPIC_API_KEY'] ?? '');
        if (!$apiKey) {
            json_response(true,'ok',['category'=>'Other Expense','confidence'=>'low','method'=>'default']);
        }

        $categories = ['Salary','Business Income','Rental Income','Other Income','Housing/Rent','Groceries','Transport','Utilities','Insurance EMI','Loan EMI','Investments/SIP','Entertainment','Healthcare','Education','Shopping','Dining Out','Savings','Other Expense'];
        $prompt = "Categorize this Indian financial transaction description into EXACTLY ONE of these categories: " . implode(', ', $categories) . "\n\nDescription: \"{$desc}\"\n\nRespond with ONLY the category name, nothing else.";

        $resp = @file_get_contents('https://api.anthropic.com/v1/messages', false,
            stream_context_create(['http'=>['method'=>'POST','header'=>"Content-Type: application/json\r\nX-API-Key: {$apiKey}\r\nanthropic-version: 2023-06-01\r\n",'content'=>json_encode(['model'=>'claude-sonnet-4-20250514','max_tokens'=>20,'messages'=>[['role'=>'user','content'=>$prompt]]]),'timeout'=>10]]));

        $aiCat = $resp ? trim(json_decode($resp,true)['content'][0]['text'] ?? '') : '';
        $finalCat = in_array($aiCat, $categories) ? $aiCat : 'Other Expense';

        json_response(true,'ok',['category'=>$finalCat,'confidence'=>$aiCat?'medium':'low','method'=>$aiCat?'ai':'default']);
        break;
    }

    // ── Bulk categorize uncategorized budget_actuals ────────────────
    case 'ai_categorize_bulk': {
        csrf_verify();
        RateLimit::check('ai_chat', $userId);

        $rows = DB::fetchAll(
            "SELECT id, description FROM budget_actuals WHERE user_id=? AND (category='' OR category IS NULL OR category='Uncategorized') LIMIT 50",
            [$userId]
        );

        $updated = 0;
        foreach ($rows as $r) {
            $cat = _rule_match($userId, $r['description'] ?? '');
            if ($cat) {
                DB::execute("UPDATE budget_actuals SET category=? WHERE id=?", [$cat, $r['id']]);
                $updated++;
            }
        }

        json_response(true,'ok',['total'=>count($rows),'categorized'=>$updated,'remaining'=>count($rows)-$updated]);
        break;
    }

    // ── Custom rules: list ────────────────────────────────────────────
    case 'ai_category_rules_list': {
        $rows = DB::fetchAll("SELECT * FROM category_rules WHERE user_id=? ORDER BY created_at DESC", [$userId]);
        json_response(true,'ok',['rules'=>$rows,'builtin'=>_builtin_rules()]);
        break;
    }

    // ── Custom rules: add ─────────────────────────────────────────────
    case 'ai_category_rule_add': {
        csrf_verify();
        $keyword  = strtolower(trim(clean($_POST['keyword']  ?? '')));
        $category = clean($_POST['category'] ?? '');
        if (!$keyword || !$category) json_response(false, 'Keyword and category required.');

        $exists = DB::fetchVal("SELECT id FROM category_rules WHERE user_id=? AND keyword=?", [$userId, $keyword]);
        if ($exists) json_response(false, 'Rule already exists for this keyword.');

        DB::execute("INSERT INTO category_rules(user_id,keyword,category,created_at) VALUES(?,?,?,NOW())", [$userId, $keyword, $category]);
        json_response(true, 'Rule added.', ['id' => DB::lastInsertId()]);
        break;
    }

    // ── Custom rules: delete ──────────────────────────────────────────
    case 'ai_category_rule_delete': {
        csrf_verify();
        $id = (int)($_POST['id'] ?? 0);
        $own = DB::fetchVal("SELECT id FROM category_rules WHERE id=? AND user_id=?", [$id, $userId]);
        if (!$own) json_response(false, 'Not found.');
        DB::execute("DELETE FROM category_rules WHERE id=?", [$id]);
        json_response(true, 'Rule deleted.');
        break;
    }

    default: json_response(false,'Unknown action.',[],400);
}

// ── Helper: match description against rules (custom first, then builtin) ──
function _rule_match(int $userId, string $desc): ?string {
    $descLower = strtolower($desc);
    if (!$descLower) return null;

    // User custom rules first (higher priority)
    $customRules = DB::fetchAll("SELECT keyword, category FROM category_rules WHERE user_id=?", [$userId]);
    foreach ($customRules as $r) {
        if (str_contains($descLower, $r['keyword'])) return $r['category'];
    }

    // Built-in rules
    foreach (_builtin_rules() as $category => $keywords) {
        foreach ($keywords as $kw) {
            if (str_contains($descLower, $kw)) return $category;
        }
    }

    return null;
}

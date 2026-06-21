<?php
/**
 * WealthDash — t42: Crypto Tax 30% Flat Calculator
 * File: api/crypto/tax_calculator.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$action = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId = (int)$_SESSION['user_id'];
switch ($action) {
    case 'crypto_tax_calculate': {
        $tradesJson = $_POST['trades'] ?? '[]';
        $fy         = clean($_POST['fy'] ?? date('Y').'-'.substr(date('Y')+1,-2));
        $trades = json_decode($tradesJson, true);
        if (!is_array($trades)) json_response(false, 'Invalid trades JSON.');
        $totalGains = 0; $totalLosses = 0; $totalTDS = 0; $processedTrades = [];
        foreach ($trades as $i => $t) {
            $buyPrice  = (float)($t['buy_price']  ?? 0);
            $sellPrice = (float)($t['sell_price'] ?? 0);
            $quantity  = (float)($t['quantity']   ?? 0);
            $coin      = clean($t['coin']         ?? 'UNKNOWN');
            $sellDate  = clean($t['sell_date']    ?? date('Y-m-d'));
            $buyDate   = clean($t['buy_date']     ?? $sellDate);
            $fees      = (float)($t['fees']       ?? 0);
            if ($quantity <= 0 || $sellPrice <= 0) continue;
            $costBasis   = ($buyPrice * $quantity) + $fees;
            $saleValue   = $sellPrice * $quantity;
            $pnl         = $saleValue - $costBasis;
            $tds         = $saleValue * 0.01;
            $taxableGain = max(0, $pnl);
            $taxPayable  = $taxableGain * 0.30;
            if ($pnl >= 0) $totalGains  += $pnl;
            else           $totalLosses += abs($pnl);
            $totalTDS += $tds;
            $processedTrades[] = ['idx'=>$i+1,'coin'=>$coin,'buy_date'=>$buyDate,'sell_date'=>$sellDate,
                'quantity'=>$quantity,'buy_price'=>$buyPrice,'sell_price'=>$sellPrice,
                'cost_basis'=>round($costBasis,2),'sale_value'=>round($saleValue,2),'pnl'=>round($pnl,2),
                'taxable_gain'=>round($taxableGain,2),'tax_payable'=>round($taxPayable,2),'tds'=>round($tds,2)];
        }
        $grossTax = $totalGains * 0.30;
        $cess     = $grossTax * 0.04;
        $totalTax = $grossTax + $cess;
        $netTax   = max(0, $totalTax - $totalTDS);
        json_response(true,'ok',['trades'=>$processedTrades,'summary'=>[
            'total_gains'=>round($totalGains,2),'total_losses'=>round($totalLosses,2),
            'taxable_gain'=>round($totalGains,2),'gross_tax_30pct'=>round($grossTax,2),
            'cess_4pct'=>round($cess,2),'total_tax_payable'=>round($totalTax,2),
            'total_tds_1pct'=>round($totalTDS,2),'net_tax_after_tds'=>round($netTax,2),
            'tax_rate'=>30,'fy'=>$fy,'note'=>'Losses cannot be set off (Section 115BBH).']]);
        break;
    }
    case 'crypto_tax_save': {
        csrf_verify();
        $fy=$clean=clean($_POST['fy']??''); $tradesJson=$_POST['trades']??'[]';
        $summaryJson=$_POST['summary']??'{}'; $label=clean($_POST['label']??'Crypto Tax '.$fy);
        $existing=DB::fetchVal("SELECT id FROM crypto_tax_reports WHERE user_id=? AND fy=? AND label=?",[$userId,$fy,$label]);
        if($existing){DB::execute("UPDATE crypto_tax_reports SET trades=?,summary=?,updated_at=NOW() WHERE id=?",[$tradesJson,$summaryJson,$existing]);json_response(true,'Report updated.',['id'=>$existing]);}
        else{DB::execute("INSERT INTO crypto_tax_reports(user_id,fy,label,trades,summary,created_at,updated_at)VALUES(?,?,?,?,?,NOW(),NOW())",[$userId,$fy,$label,$tradesJson,$summaryJson]);json_response(true,'Report saved.',['id'=>DB::lastInsertId()]);}
        break;
    }
    case 'crypto_tax_load': {
        $rid=(int)($_GET['report_id']??0);
        if($rid){$row=DB::fetchRow("SELECT * FROM crypto_tax_reports WHERE id=? AND user_id=?",[$rid,$userId]);if(!$row)json_response(false,'Not found.');$row['trades']=json_decode($row['trades'],true);$row['summary']=json_decode($row['summary'],true);json_response(true,'ok',['report'=>$row]);}
        else{$rows=DB::fetchAll("SELECT id,fy,label,created_at,updated_at FROM crypto_tax_reports WHERE user_id=? ORDER BY fy DESC",[$userId]);json_response(true,'ok',['reports'=>$rows]);}
        break;
    }
    case 'crypto_tax_delete': {
        csrf_verify();
        $rid=(int)($_POST['report_id']??0);
        $own=DB::fetchVal("SELECT id FROM crypto_tax_reports WHERE id=? AND user_id=?",[$rid,$userId]);
        if(!$own)json_response(false,'Not found.');
        DB::execute("DELETE FROM crypto_tax_reports WHERE id=?",[$rid]);json_response(true,'Deleted.');
        break;
    }
    default: json_response(false,'Unknown action.',[],400);
}

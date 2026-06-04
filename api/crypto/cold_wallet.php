<?php
/**
 * WealthDash — tc006: Cold Wallet Tracker
 * File: api/crypto/cold_wallet.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$action = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId = (int)$_SESSION['user_id'];
switch ($action) {
    case 'cold_wallet_list': {
        $wallets=DB::fetchAll("SELECT w.*,COUNT(h.id) AS coin_count,COALESCE(SUM(h.value_inr),0) AS total_value_inr FROM cold_wallets w LEFT JOIN cold_wallet_holdings h ON h.wallet_id=w.id WHERE w.user_id=? GROUP BY w.id ORDER BY w.created_at DESC",[$userId]);
        json_response(true,'ok',['wallets'=>$wallets]); break;
    }
    case 'cold_wallet_add': {
        csrf_verify();
        $name=clean($_POST['name']??'');
        if(!$name)json_response(false,'Name required.');
        DB::execute("INSERT INTO cold_wallets(user_id,name,type,device,address,network,notes,created_at)VALUES(?,?,?,?,?,?,?,NOW())",[$userId,$name,clean($_POST['type']??'hardware'),clean($_POST['device']??''),clean($_POST['address']??''),clean($_POST['network']??''),clean($_POST['notes']??'')]);
        json_response(true,'Wallet added.',['id'=>DB::lastInsertId()]); break;
    }
    case 'cold_wallet_delete': {
        csrf_verify();
        $wid=(int)($_POST['wallet_id']??0);
        $own=DB::fetchVal("SELECT id FROM cold_wallets WHERE id=? AND user_id=?",[$wid,$userId]);
        if(!$own)json_response(false,'Not found.');
        DB::execute("DELETE FROM cold_wallet_holdings WHERE wallet_id=?",[$wid]);
        DB::execute("DELETE FROM cold_wallets WHERE id=?",[$wid]);
        json_response(true,'Deleted.'); break;
    }
    case 'cold_wallet_holdings_add': {
        csrf_verify();
        $wid=(int)($_POST['wallet_id']??0);
        $coin=strtoupper(clean($_POST['coin']??'')); $qty=(float)($_POST['quantity']??0);
        $bp=(float)($_POST['buy_price']??0); $val=(float)($_POST['value_inr']??($qty*$bp));
        $own=DB::fetchVal("SELECT id FROM cold_wallets WHERE id=? AND user_id=?",[$wid,$userId]);
        if(!$own)json_response(false,'Wallet not found.');
        if(!$coin||$qty<=0)json_response(false,'Coin and quantity required.');
        $existing=DB::fetchVal("SELECT id FROM cold_wallet_holdings WHERE wallet_id=? AND coin=?",[$wid,$coin]);
        if($existing){DB::execute("UPDATE cold_wallet_holdings SET quantity=?,buy_price=?,value_inr=?,notes=?,updated_at=NOW() WHERE id=?",[$qty,$bp,$val,clean($_POST['notes']??''),$existing]);json_response(true,'Updated.',['id'=>$existing]);}
        else{DB::execute("INSERT INTO cold_wallet_holdings(wallet_id,coin,quantity,buy_price,value_inr,notes,created_at,updated_at)VALUES(?,?,?,?,?,?,NOW(),NOW())",[$wid,$coin,$qty,$bp,$val,clean($_POST['notes']??'')]);json_response(true,'Added.',['id'=>DB::lastInsertId()]);}
        break;
    }
    case 'cold_wallet_holdings_list': {
        $wid=(int)($_GET['wallet_id']??$_POST['wallet_id']??0);
        $own=DB::fetchVal("SELECT id FROM cold_wallets WHERE id=? AND user_id=?",[$wid,$userId]);
        if(!$own)json_response(false,'Not found.');
        $h=DB::fetchAll("SELECT * FROM cold_wallet_holdings WHERE wallet_id=? ORDER BY value_inr DESC",[$wid]);
        json_response(true,'ok',['holdings'=>$h]); break;
    }
    case 'cold_wallet_holdings_delete': {
        csrf_verify();
        $hid=(int)($_POST['holding_id']??0);
        $own=DB::fetchRow("SELECT h.id FROM cold_wallet_holdings h JOIN cold_wallets w ON w.id=h.wallet_id WHERE h.id=? AND w.user_id=?",[$hid,$userId]);
        if(!$own)json_response(false,'Not found.');
        DB::execute("DELETE FROM cold_wallet_holdings WHERE id=?",[$hid]); json_response(true,'Deleted.'); break;
    }
    case 'cold_wallet_summary': {
        $rows=DB::fetchAll("SELECT w.id,w.name,w.type,w.device,COUNT(h.id) AS coin_count,COALESCE(SUM(h.value_inr),0) AS total_value_inr,COALESCE(SUM(h.quantity*h.buy_price),0) AS total_cost FROM cold_wallets w LEFT JOIN cold_wallet_holdings h ON h.wallet_id=w.id WHERE w.user_id=? GROUP BY w.id",[$userId]);
        $grand=array_sum(array_column($rows,'total_value_inr')); $cost=array_sum(array_column($rows,'total_cost'));
        json_response(true,'ok',['wallets'=>$rows,'grand_total_inr'=>round($grand,2),'total_cost'=>round($cost,2),'unrealised_pnl'=>round($grand-$cost,2)]); break;
    }
    default: json_response(false,'Unknown action.',[],400);
}

<?php
/**
 * WealthDash — t138: Indexation Benefit Calculator — LTCG Property
 * Cost Inflation Index (CII) based LTCG calculation for real estate.
 * Budget 2024: Option to choose between indexed (20%) or non-indexed (12.5%) for property.
 *
 * Actions: indexation_calculate | indexation_property_list | indexation_property_save | indexation_property_delete
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$currentUser = require_auth();
$userId = (int)$currentUser['id'];
$action = $_GET['action'] ?? $_POST['action'] ?? 'indexation_calculate';

// CII Table — CBDT notified (base year 2001-02 = 100)
const CII_TABLE = [
    '2001-02'=>100,'2002-03'=>105,'2003-04'=>109,'2004-05'=>113,'2005-06'=>117,
    '2006-07'=>122,'2007-08'=>129,'2008-09'=>137,'2009-10'=>148,'2010-11'=>167,
    '2011-12'=>184,'2012-13'=>200,'2013-14'=>220,'2014-15'=>240,'2015-16'=>254,
    '2016-17'=>264,'2017-18'=>272,'2018-19'=>280,'2019-20'=>289,'2020-21'=>301,
    '2021-22'=>317,'2022-23'=>331,'2023-24'=>348,'2024-25'=>363,'2025-26'=>380,
];

function _idx_fy_from_year(int $yr): string { return $yr.'-'.($yr+1); }
function _idx_fy_from_date(string $date): string {
    $mo=(int)date('n',strtotime($date)); $yr=(int)date('Y',strtotime($date));
    $fys=$mo>=4?$yr:$yr-1; return $fys.'-'.($fys+1);
}
function _idx_cii(string $fy): ?int { return CII_TABLE[$fy]??null; }

switch ($action) {
    case 'indexation_calculate': {
        $purchaseDate = clean($_GET['purchase_date']??$_POST['purchase_date']??'');
        $saleDate     = clean($_GET['sale_date']??$_POST['sale_date']??date('Y-m-d'));
        $purchasePrice= (float)($_GET['purchase_price']??$_POST['purchase_price']??0);
        $salePrice    = (float)($_GET['sale_price']??$_POST['sale_price']??0);
        $improvements = (float)($_GET['improvement_cost']??$_POST['improvement_cost']??0);
        $improvYear   = clean($_GET['improvement_fy']??$_POST['improvement_fy']??'');
        $transferCost = (float)($_GET['transfer_cost']??$_POST['transfer_cost']??0); // brokerage, stamp duty
        $propertyType = clean($_GET['property_type']??$_POST['property_type']??'residential');

        if (!$purchaseDate||!$purchasePrice||!$salePrice) json_response(false,'purchase_date, purchase_price, sale_price required.');

        $purchaseFY = _idx_fy_from_date($purchaseDate);
        $saleFY     = _idx_fy_from_date($saleDate);
        $ciiPurchase= _idx_cii($purchaseFY);
        $ciSale     = _idx_cii($saleFY);

        // If pre-2001, use FMV as of 2001 (user must provide)
        $fmv2001 = (float)($_GET['fmv_2001']??$_POST['fmv_2001']??0);
        $costBase = ($purchaseDate<'2001-04-01'&&$fmv2001>0) ? max($purchasePrice,$fmv2001) : $purchasePrice;

        if (!$ciiPurchase||!$ciSale) json_response(false,'CII data not available for given years. Check dates.');

        // Indexed cost of acquisition
        $indexedCost = round($costBase * $ciSale / $ciiPurchase, 2);

        // Indexed improvement cost
        $indexedImprovement = 0;
        if ($improvements>0&&$improvYear&&isset(CII_TABLE[$improvYear])) {
            $indexedImprovement = round($improvements * $ciSale / CII_TABLE[$improvYear], 2);
        }

        $netSaleValue   = $salePrice - $transferCost;
        $totalIndexedCost = $indexedCost + $indexedImprovement;

        // Option A: With indexation (20% tax)
        $ltcgWithIndex   = max(0, $netSaleValue - $totalIndexedCost);
        $taxWithIndex    = round($ltcgWithIndex * 0.20 * 1.04, 2); // 20% + 4% cess

        // Option B: Without indexation (12.5% tax) — Budget 2024
        $ltcgNoIndex     = max(0, $netSaleValue - $costBase - $improvements - $transferCost);
        $taxNoIndex      = round($ltcgNoIndex * 0.125 * 1.04, 2); // 12.5% + 4% cess

        $betterOption = $taxWithIndex <= $taxNoIndex ? 'with_indexation' : 'without_indexation';
        $taxSaving    = abs($taxWithIndex - $taxNoIndex);

        // Section 54 exemption check
        $section54 = $propertyType==='residential';

        json_response(true,'',['inputs'=>['purchase_date'=>$purchaseDate,'sale_date'=>$saleDate,'purchase_price'=>$purchasePrice,'sale_price'=>$salePrice,'improvement_cost'=>$improvements,'transfer_cost'=>$transferCost,'fmv_2001'=>$fmv2001?:null],'cii'=>['purchase_fy'=>$purchaseFY,'purchase_cii'=>$ciiPurchase,'sale_fy'=>$saleFY,'sale_cii'=>$ciSale],'with_indexation'=>['indexed_cost'=>$indexedCost,'indexed_improvement'=>$indexedImprovement,'total_indexed_cost'=>$totalIndexedCost,'net_sale_value'=>round($netSaleValue,2),'ltcg'=>round($ltcgWithIndex,2),'tax_rate'=>'20%','tax_payable'=>$taxWithIndex],'without_indexation'=>['cost'=>$costBase,'improvement'=>$improvements,'ltcg'=>round($ltcgNoIndex,2),'tax_rate'=>'12.5%','tax_payable'=>$taxNoIndex],'recommendation'=>['better_option'=>$betterOption,'tax_saving'=>round($taxSaving,2),'note'=>"Budget 2024: Property ke liye {$betterOption} choose karo — ₹".number_format($taxSaving,0)." bachega"],'section_54'=>['applicable'=>$section54,'note'=>$section54?'Section 54: Residential property sale pe capital gain reinvest karo naye residential property mein — full exemption possible':'Section 54F applicable hai — consult CA'],'holding_years'=>round((strtotime($saleDate)-strtotime($purchaseDate))/(86400*365.25),1),'qualifies_ltcg'=>(strtotime($saleDate)-strtotime($purchaseDate))>=(2*365*86400)]);
    }
    case 'indexation_property_list': {
        $rows=DB::fetchAll("SELECT * FROM property_assets WHERE user_id=? AND is_active=1 ORDER BY purchase_date DESC",[$userId]);
        json_response(true,'',['properties'=>$rows,'cii_table'=>CII_TABLE]);
    }
    case 'indexation_property_save': {
        require_csrf();
        $id=(int)($_POST['id']??0);
        $fields=['property_name'=>substr(clean($_POST['property_name']??''),0,200),'property_type'=>clean($_POST['property_type']??'residential'),'address'=>substr(clean($_POST['address']??''),0,500),'purchase_date'=>clean($_POST['purchase_date']??''),'purchase_price'=>(float)($_POST['purchase_price']??0),'improvement_cost'=>(float)($_POST['improvement_cost']??0),'improvement_fy'=>clean($_POST['improvement_fy']??''),'sale_date'=>clean($_POST['sale_date']??'')?:null,'sale_price'=>(float)($_POST['sale_price']??0)?:(float)0,'fmv_2001'=>(float)($_POST['fmv_2001']??0)?:(float)0,'notes'=>substr(clean($_POST['notes']??''),0,255)];
        if ($id) {
            $sets=implode(',',array_map(fn($k)=>"{$k}=?",array_keys($fields)));
            DB::run("UPDATE property_assets SET {$sets} WHERE id=? AND user_id=?",array_merge(array_values($fields),[$id,$userId]));
        } else {
            $cols=implode(',',array_keys($fields));
            $phs=implode(',',array_fill(0,count($fields),'?'));
            $id=DB::insert("INSERT INTO property_assets (user_id,{$cols}) VALUES (?,{$phs})",array_merge([$userId],array_values($fields)));
        }
        json_response(true,'Property saved.',['id'=>(int)$id]);
    }
    case 'indexation_property_delete': {
        require_csrf();
        $id=(int)($_POST['id']??0);
        $ok=DB::run("UPDATE property_assets SET is_active=0 WHERE id=? AND user_id=?",[$id,$userId]);
        json_response((bool)$ok,$ok?'Deleted.':'Not found.');
    }
    default: json_response(false,'Unknown action: '.htmlspecialchars($action),[],400);
}

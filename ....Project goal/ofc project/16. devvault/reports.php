<?php
require_once __DIR__ . '/auth.php';
require_login();
$db = get_db();

$accent = user_pref('accent','#00d4ff');
$theme  = user_pref('theme','dark');
$font   = user_pref('font_family','Rajdhani');
$fs     = user_pref('font_size','14');
// ── Sanitize user preferences at read time (CSS injection prevention) ─────────
$accent = preg_replace('/[^#a-fA-F0-9]/', '', $accent);
if (empty($accent)) $accent = '#00d4ff';
$theme  = in_array($theme, ['dark', 'light']) ? $theme : 'dark';
$fs     = max(11, min(18, (int)$fs));
$font   = in_array($font, ['Rajdhani', 'Share Tech Mono', 'Orbitron']) ? $font : 'Rajdhani';
$today  = date('Y-m-d');

$tab = $_GET['tab'] ?? 'financial';

// ── Report 1: Financial/Status date-range ─────────────────────────────────────
$r1_from = $_GET['r1_from'] ?? '';
$r1_to   = $_GET['r1_to'] ?? '';
$r1_data = []; $r1_summary = [];
if ($r1_from && $r1_to && $r1_from <= $r1_to && $r1_to <= $today && $tab === 'financial') {
    $st = $db->prepare("SELECT p.*, u.username as creator FROM projects p LEFT JOIN users u ON p.created_by=u.id
        WHERE p.live_date BETWEEN ? AND ? ORDER BY p.live_date");
    $st->execute([$r1_from, $r1_to]); $r1_data = $st->fetchAll();

    $total_live  = count($r1_data);
    $amc_paid    = 0; $cnt_paid = 0; $cnt_exempt = 0; $cnt_free = 0;
    foreach ($r1_data as $row) {
        if ($row['amc_type'] === 'Paid')      { $amc_paid += (float)($row['amc_amount']??0); $cnt_paid++; }
        if ($row['amc_type'] === 'Exemption') $cnt_exempt++;
        if ($row['amc_type'] === 'Free')      $cnt_free++;
    }
    $r1_summary = compact('total_live','amc_paid','cnt_paid','cnt_exempt','cnt_free');

    // CSV Export
    if (isset($_GET['export_r1'])) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="financial_report_'.$r1_from.'_'.$r1_to.'.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Project Name','Department','Technology','Sub-type','Live Date','Closed Date','AMC Type','AMC Amount','AMC Start','AMC End','Status']);
        foreach ($r1_data as $row) {
            fputcsv($out, [
                $row['project_name'], $row['department_name'], $row['technology']??$row['technology_other'],
                $row['tech_subtype'], $row['live_date'], $row['closed_date']??'',
                $row['amc_type'], $row['amc_amount'], $row['amc_start_date'], $row['amc_end_date'], $row['current_status']
            ]);
        }
        fputcsv($out, []);
        fputcsv($out, ['Summary','','','','','','','Total Live',$total_live,'Paid AMC Total',number_format($amc_paid,2)]);
        fclose($out); exit;
    }
}

// ── Report 2: Visitor delta ───────────────────────────────────────────────────
$r2_project  = intval($_GET['r2_project'] ?? 0);
$r2_from     = $_GET['r2_from'] ?? '';
$r2_to       = $_GET['r2_to'] ?? '';
$r2_data     = [];
if ($r2_from && $r2_to && $r2_to <= $today && $tab === 'visitors') {
    $proj_where = $r2_project ? "AND pvl.project_id=$r2_project" : '';
    $rows = $db->query("SELECT DISTINCT pvl.project_id, p.project_name FROM project_visitor_log pvl
        JOIN projects p ON pvl.project_id=p.id $proj_where ORDER BY p.project_name")->fetchAll();
    foreach ($rows as $prow) {
        $pid = $prow['project_id'];
        // Baseline: latest entry on or before from_date (fallback: earliest ever)
        $bs = $db->prepare("SELECT * FROM project_visitor_log WHERE project_id=? AND entry_date <= ? ORDER BY entry_date DESC, id DESC LIMIT 1");
        $bs->execute([$pid, $r2_from]); $bs = $bs->fetch();
        if (!$bs) {
            $bs = $db->prepare("SELECT * FROM project_visitor_log WHERE project_id=? ORDER BY entry_date ASC, id ASC LIMIT 1");
            $bs->execute([$pid]); $bs = $bs->fetch();
        }
        // Final: latest entry on or before to_date
        $fn = $db->prepare("SELECT * FROM project_visitor_log WHERE project_id=? AND entry_date <= ? ORDER BY entry_date DESC, id DESC LIMIT 1");
        $fn->execute([$pid, $r2_to]); $fn = $fn->fetch();
        if (!$bs || !$fn || $bs['id'] === $fn['id']) continue;

        $net_gain = (int)$fn['visitor_count'] - (int)$bs['visitor_count'];
        // Count distinct site update dates between baseline and final (excluding baseline)
        $upd = $db->prepare("SELECT COUNT(DISTINCT site_last_update_date) FROM project_visitor_log
            WHERE project_id=? AND entry_date > ? AND entry_date <= ? AND site_last_update_date IS NOT NULL
            AND site_last_update_date != ?");
        $upd->execute([$pid, $bs['entry_date'], $r2_to, $bs['site_last_update_date']??'']);
        $updates_count = (int)$upd->fetchColumn();

        $r2_data[] = [
            'project_name'   => $prow['project_name'],
            'baseline_count' => $bs['visitor_count'],
            'baseline_date'  => $bs['entry_date'],
            'final_count'    => $fn['visitor_count'],
            'final_date'     => $fn['entry_date'],
            'net_gain'       => $net_gain,
            'updates_count'  => $updates_count,
        ];
    }

    if (isset($_GET['export_r2'])) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="visitor_report_'.$r2_from.'_'.$r2_to.'.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Project','Baseline Count','Baseline Date','Final Count','Final Date','Net Gain','Site Updates in Period']);
        foreach ($r2_data as $row) fputcsv($out, array_values($row));
        fclose($out); exit;
    }
}

// ── Report 3: Technology migration ───────────────────────────────────────────
$r3_from  = $_GET['r3_from'] ?? '';
$r3_to    = $_GET['r3_to'] ?? '';
$r3_from_t = $_GET['r3_from_tech'] ?? '';
$r3_to_t   = $_GET['r3_to_tech'] ?? '';
$r3_data   = [];
if ($tab === 'migration') {
    $where = ['1=1']; $params = [];
    if ($r3_from) { $where[] = 'tcl.change_date >= ?'; $params[] = $r3_from; }
    if ($r3_to)   { $where[] = 'tcl.change_date <= ?'; $params[] = $r3_to; }
    if ($r3_from_t) { $where[] = 'tcl.from_technology=?'; $params[] = $r3_from_t; }
    if ($r3_to_t)   { $where[] = 'tcl.to_technology=?';   $params[] = $r3_to_t; }
    $st = $db->prepare("SELECT tcl.*, p.project_name, u.username as changer FROM technology_change_log tcl
        JOIN projects p ON tcl.project_id=p.id LEFT JOIN users u ON tcl.changed_by=u.id
        WHERE ".implode(' AND ',$where)." ORDER BY tcl.change_date DESC");
    $st->execute($params); $r3_data = $st->fetchAll();

    if (isset($_GET['export_r3'])) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="migration_report.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Project','From Technology','From Sub-type','To Technology','To Sub-type','Change Date','Reason','Changed By']);
        foreach ($r3_data as $row) {
            fputcsv($out, [$row['project_name'],$row['from_technology'],$row['from_subtype'],$row['to_technology'],$row['to_subtype'],$row['change_date'],$row['reason'],$row['changer']]);
        }
        fclose($out); exit;
    }
}

// ── Report 4: Work order completion ──────────────────────────────────────────
$r4_from = $_GET['r4_from'] ?? '';
$r4_to   = $_GET['r4_to'] ?? '';
$r4_tech = $_GET['r4_tech'] ?? '';
$r4_data = [];
if ($tab === 'workorders') {
    $where = ['1=1']; $params = [];
    if ($r4_from) { $where[] = 'wo.created_at >= ?'; $params[] = $r4_from.' 00:00:00'; }
    if ($r4_to)   { $where[] = 'wo.created_at <= ?'; $params[] = $r4_to.' 23:59:59'; }
    if ($r4_tech) { $where[] = "wo.applicable_tech LIKE ?"; $params[] = "%$r4_tech%"; }
    $st = $db->prepare("SELECT wo.*,
        (SELECT COUNT(*) FROM work_order_sites WHERE work_order_id=wo.id) as total_sites,
        (SELECT COUNT(*) FROM work_order_sites WHERE work_order_id=wo.id AND site_status='Done') as done_sites
        FROM work_orders wo WHERE ".implode(' AND ',$where)." ORDER BY wo.created_at DESC");
    $st->execute($params); $r4_data = $st->fetchAll();

    if (isset($_GET['export_r4'])) {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="workorder_report.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['Title','Applicable Tech','Priority','Deadline','Created','Total Sites','Done','Pending','% Done','Status']);
        foreach ($r4_data as $row) {
            $pct = $row['total_sites'] ? round($row['done_sites']/$row['total_sites']*100) : 0;
            fputcsv($out, [$row['title'],json_decode($row['applicable_tech']??'[]',true),$row['priority'],$row['deadline']??'',substr($row['created_at'],0,10),$row['total_sites'],$row['done_sites'],$row['total_sites']-$row['done_sites'],$pct.'%',$row['status']]);
        }
        fclose($out); exit;
    }
}

// Projects list for dropdown
$all_projects = $db->query("SELECT id, project_name FROM projects ORDER BY project_name")->fetchAll();
$all_techs    = $db->query("SELECT DISTINCT technology FROM projects WHERE technology IS NOT NULL AND technology != '' ORDER BY technology")->fetchAll();
?>
<?php
$page_title = 'Reports';
$nav_active = 'reports';
require_once __DIR__ . '/includes/sidebar.php';
?>
<div class="dv-content">

<div style="margin-bottom:16px">
  <div class="dv-tabs">
    <a href="?tab=financial" class="dv-tab <?=$tab==='financial'?'active':''?>">💰 Financial / Status</a>
    <a href="?tab=visitors"  class="dv-tab <?=$tab==='visitors'?'active':''?>">📈 Visitor Delta</a>
    <a href="?tab=migration" class="dv-tab <?=$tab==='migration'?'active':''?>">🔄 Tech Migration</a>
    <a href="?tab=workorders"class="dv-tab <?=$tab==='workorders'?'active':''?>">📝 Work Order Summary</a>
  </div>

<?php if ($tab === 'financial'): ?>
<!-- ═══════════ REPORT 1: FINANCIAL ═══════════ -->
<div class="card card-sm" style="margin-bottom:14px">
  <form method="get">
    <input type="hidden" name="tab" value="financial">
    <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
      <div style="display:flex;flex-direction:column;gap:3px">
        <label style="font-family:'JetBrains Mono',monospace;font-size:9.5px;text-transform:uppercase;letter-spacing:1px;color:var(--tx2)">From Date</label>
        <input type="date" name="r1_from" value="<?=htmlspecialchars($r1_from)?>" max="<?=$today?>" style="width:150px">
      </div>
      <div style="display:flex;flex-direction:column;gap:3px">
        <label style="font-family:'JetBrains Mono',monospace;font-size:9.5px;text-transform:uppercase;letter-spacing:1px;color:var(--tx2)">To Date</label>
        <input type="date" name="r1_to" value="<?=htmlspecialchars($r1_to)?>" max="<?=$today?>" style="width:150px">
      </div>
      <div style="display:flex;gap:6px;align-items:flex-end">
        <button type="submit" class="btn btn-primary btn-sm">🔍 Run Report</button>
        <?php if ($r1_data): ?>
        <a href="?tab=financial&r1_from=<?=$r1_from?>&r1_to=<?=$r1_to?>&export_r1=1" class="btn btn-ghost btn-sm">⬇ CSV</a>
        <button type="button" data-action="print" class="btn btn-ghost btn-sm">🖨 Print</button>
        <?php endif; ?>
      </div>
    </div>
  </form>
</div>

<?php if ($r1_data): ?>
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px">
  <div class="stat-card" style="min-width:110px"><div class="stat-label">Sites Went Live</div><div class="stat-value" style="color:var(--ok)"><?=$r1_summary['total_live']?></div></div>
  <div class="stat-card" style="min-width:130px"><div class="stat-label">Paid AMC Value</div><div class="stat-value" style="color:var(--warn);font-size:18px">₹<?=number_format($r1_summary['amc_paid'],0)?></div></div>
  <div class="stat-card" style="min-width:100px"><div class="stat-label">Paid AMC Sites</div><div class="stat-value" style="color:var(--ok)"><?=$r1_summary['cnt_paid']?></div></div>
  <div class="stat-card" style="min-width:100px"><div class="stat-label">AMC Exemption</div><div class="stat-value" style="color:var(--info)"><?=$r1_summary['cnt_exempt']?></div></div>
  <div class="stat-card" style="min-width:100px"><div class="stat-label">Free AMC Sites</div><div class="stat-value" style="color:var(--tx2)"><?=$r1_summary['cnt_free']?></div></div>
</div>
<div class="card" style="padding:0;overflow:hidden;">
  <table class="dv-table">
    <thead>
      <tr>
        <th>Project</th><th>Department</th><th>Technology</th><th>Sub-type</th>
        <th>Live Date</th><th>Status</th><th>AMC Type</th><th>AMC (₹)</th><th>Period</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($r1_data as $row): ?>
      <?php
        $amc_status = '';
        if ($row['amc_end_date'] && $row['amc_end_date'] < $today) $amc_status = 'expired';
        elseif ($row['amc_end_date'] && $row['amc_end_date'] <= date('Y-m-d',strtotime('+30 days'))) $amc_status = 'expiring';
      ?>
      <tr>
        <td><a href="project_form.php?id=<?=$row['id']?>" style="color:var(--acc);text-decoration:none;font-weight:500"><?=htmlspecialchars($row['project_name'])?></a></td>
        <td class="td-mono"><?=htmlspecialchars($row['department_name']??'')?></td>
        <td class="td-mono"><?=htmlspecialchars($row['technology']??$row['technology_other']??'')?></td>
        <td style="font-size:11px;color:var(--tx3)"><?=htmlspecialchars($row['tech_subtype']??'')?></td>
        <td class="td-mono"><?=htmlspecialchars($row['live_date']??'')?></td>
        <td><span class="badge"><?=ucfirst(str_replace('_',' ',$row['current_status']??''))?></span></td>
        <td>
          <?php $at=$row['amc_type']??''; if($at): ?>
          <?php $amc_colors=['Paid'=>'var(--ok)','Exemption'=>'var(--info)','Free'=>'var(--tx2)','NA'=>'var(--tx3)'];
          $amc_c=$amc_colors[$at]??'var(--tx2)'; ?>
          <span class="badge" style="color:<?=$amc_c?>;border-color:<?=$amc_c?>40;background:<?=$amc_c?>14"><?=$at?></span>
          <?php endif; ?>
        </td>
        <td class="td-mono" style="text-align:right"><?=$row['amc_amount']>0?'₹'.number_format((float)$row['amc_amount'],0):'—'?></td>
        <td style="font-size:11px;color:var(--tx2)">
          <?php if ($row['amc_start_date']): ?>
          <?=htmlspecialchars($row['amc_start_date'])?> – <?=htmlspecialchars($row['amc_end_date']??'')?>
          <?php if ($amc_status === 'expired'): ?>
          <br><span style="color:var(--err);font-size:10px">⚠ Expired</span>
          <?php elseif ($amc_status === 'expiring'): ?>
          <br><span style="color:var(--warn);font-size:10px">⚡ Expiring soon</span>
          <?php endif; ?>
          <?php else: echo '—'; endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr style="font-weight:600;background:var(--sur2)">
        <td colspan="7" style="padding:9px 13px">Total (<?=$r1_summary['total_live']?> sites)</td>
        <td style="padding:9px 13px;text-align:right">₹<?=number_format($r1_summary['amc_paid'],0)?></td>
        <td></td>
      </tr>
    </tfoot>
  </table>
</div>
<?php elseif ($r1_from): ?>
<div class="card"><div class="no-data">📭 No projects went live between <?=htmlspecialchars($r1_from)?> and <?=htmlspecialchars($r1_to)?>.</div></div>
<?php endif; ?>

<?php elseif ($tab === 'visitors'): ?>
<!-- ═══════════ REPORT 2: VISITOR DELTA ═══════════ -->
<div class="card card-sm" style="margin-bottom:14px">
  <form method="get">
    <input type="hidden" name="tab" value="visitors">
    <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
      <div style="display:flex;flex-direction:column;gap:3px">
        <label>Project (optional)</label>
        <select name="r2_project" style="min-width:200px;">
          <option value="">All Projects</option>
          <?php foreach ($all_projects as $pr): ?>
          <option value="<?=$pr['id']?>" <?=$r2_project==$pr['id']?'selected':''?>><?=htmlspecialchars($pr['project_name'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="display:flex;flex-direction:column;gap:3px"><label style="font-family:'JetBrains Mono',monospace;font-size:9.5px;text-transform:uppercase;letter-spacing:1px;color:var(--tx2)">From Date (baseline)</label><input type="date" name="r2_from" value="<?=htmlspecialchars($r2_from)?>" max="<?=$today?>"></div>
      <div style="display:flex;flex-direction:column;gap:3px"><label style="font-family:'JetBrains Mono',monospace;font-size:9.5px;text-transform:uppercase;letter-spacing:1px;color:var(--tx2)">To Date</label><input type="date" name="r2_to" value="<?=htmlspecialchars($r2_to)?>" max="<?=$today?>"></div>
      <button type="submit" class="btn btn-primary btn-sm">🔍 Run Report</button>
      <?php if ($r2_data): ?>
      <a href="?tab=visitors&r2_from=<?=$r2_from?>&r2_to=<?=$r2_to?>&r2_project=<?=$r2_project?>&export_r2=1" class="btn btn-ghost btn-sm" style="margin-top:auto;">⬇ CSV</a>
      <button type="button" data-action="print" class="btn btn-ghost btn-sm">🖨 Print</button>
      <?php endif; ?>
    </div>
  </form>
  <p style="font-size:12px;color:var(--tx2);margin-top:-8px;">
    <strong style="color:var(--tx)">How it works:</strong>
    <em>Baseline</em> = visitor count from the latest log entry on or before the <strong>From Date</strong>.
    <em>Final</em> = visitor count from the latest log entry on or before the <strong>To Date</strong>.
    <em>Net Gain</em> = Final − Baseline (visitors gained in this period).
  </p>
</div>

<?php if ($r2_data): ?>
<div class="card" style="padding:0;overflow:hidden;">
  <table class="dv-table">
    <thead>
      <tr>
        <th>Project</th><th>Baseline Count</th><th>Baseline Date</th>
        <th>Final Count</th><th>Final Date</th><th>Net Gain</th><th>Site Updates in Period</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($r2_data as $row): ?>
      <tr>
        <td><?=htmlspecialchars($row['project_name'])?></td>
        <td style="text-align:right;"><?=number_format($row['baseline_count'])?></td>
        <td><?=htmlspecialchars($row['baseline_date'])?></td>
        <td style="text-align:right;"><?=number_format($row['final_count'])?></td>
        <td><?=htmlspecialchars($row['final_date'])?></td>
        <td style="text-align:right;<?=$row['net_gain']>=0?'color:#10b981':'color:#ef4444'?>;font-weight:600;">
          <?=$row['net_gain']>=0?'+':''?><?=number_format($row['net_gain'])?>
        </td>
        <td style="text-align:center;"><?=$row['updates_count']?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td>Total (<?=count($r2_data)?> projects)</td>
        <td style="text-align:right;"><?=number_format(array_sum(array_column($r2_data,'baseline_count')))?></td>
        <td></td>
        <td style="text-align:right;"><?=number_format(array_sum(array_column($r2_data,'final_count')))?></td>
        <td></td>
        <td style="text-align:right;font-weight:700;">+<?=number_format(array_sum(array_column($r2_data,'net_gain')))?></td>
        <td style="text-align:center;"><?=array_sum(array_column($r2_data,'updates_count'))?></td>
      </tr>
    </tfoot>
  </table>
</div>
<?php elseif ($r2_from): ?>
<div class="card"><div class="no-data">📭 No visitor log entries found for selected range.</div></div>
<?php endif; ?>

<?php elseif ($tab === 'migration'): ?>
<!-- ═══════════ REPORT 3: TECH MIGRATION ═══════════ -->
<div class="card card-sm" style="margin-bottom:14px">
  <form method="get">
    <input type="hidden" name="tab" value="migration">
    <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
      <div style="display:flex;flex-direction:column;gap:3px">
        <label>From Technology</label>
        <select name="r3_from_tech">
          <option value="">Any</option>
          <?php foreach ($all_techs as $t): ?>
          <option value="<?=htmlspecialchars($t['technology'])?>" <?=$r3_from_t===$t['technology']?'selected':''?>><?=htmlspecialchars($t['technology'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="display:flex;flex-direction:column;gap:3px">
        <label>To Technology</label>
        <select name="r3_to_tech">
          <option value="">Any</option>
          <?php foreach ($all_techs as $t): ?>
          <option value="<?=htmlspecialchars($t['technology'])?>" <?=$r3_to_t===$t['technology']?'selected':''?>><?=htmlspecialchars($t['technology'])?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="display:flex;flex-direction:column;gap:3px"><label style="font-family:'JetBrains Mono',monospace;font-size:9.5px;text-transform:uppercase;letter-spacing:1px;color:var(--tx2)">From Date</label><input type="date" name="r3_from" value="<?=htmlspecialchars($r3_from)?>" max="<?=$today?>"></div>
      <div style="display:flex;flex-direction:column;gap:3px"><label style="font-family:'JetBrains Mono',monospace;font-size:9.5px;text-transform:uppercase;letter-spacing:1px;color:var(--tx2)">To Date</label><input type="date" name="r3_to" value="<?=htmlspecialchars($r3_to)?>" max="<?=$today?>"></div>
      <button type="submit" class="btn btn-primary btn-sm">🔍 Filter</button>
      <?php if ($r3_data): ?>
      <a href="?tab=migration&r3_from=<?=$r3_from?>&r3_to=<?=$r3_to?>&r3_from_tech=<?=$r3_from_t?>&r3_to_tech=<?=$r3_to_t?>&export_r3=1" class="btn btn-ghost btn-sm" style="margin-top:auto;">⬇ CSV</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<?php if ($r3_data):
  // Summary: count by from_tech and to_tech
  $from_counts = []; $to_counts = [];
  foreach ($r3_data as $r) {
      $from_counts[$r['from_technology']] = ($from_counts[$r['from_technology']]??0) + 1;
      $to_counts[$r['to_technology']] = ($to_counts[$r['to_technology']]??0) + 1;
  }
?>
<div class="card card-sm" style="margin-bottom:14px">
  <strong style="font-size:13px;">Migration Summary (<?=count($r3_data)?> changes):</strong>
  <div style="display:flex;gap:20px;margin-top:10px;flex-wrap:wrap;font-size:12px;">
    <?php foreach ($from_counts as $t => $n): ?>
    <span style="color:var(--tx2);">Migrated away from <strong style="color:var(--tx);"><?=htmlspecialchars($t)?></strong>: <?=$n?></span>
    <?php endforeach; ?>
    <?php foreach ($to_counts as $t => $n): ?>
    <span style="color:var(--tx2);">Migrated to <strong style="color:#10b981;"><?=htmlspecialchars($t)?></strong>: <?=$n?></span>
    <?php endforeach; ?>
  </div>
</div>
<div class="card" style="padding:0;overflow:hidden;">
  <table class="dv-table">
    <thead>
      <tr><th>Project</th><th>From</th><th>From Sub-type</th><th>To</th><th>To Sub-type</th><th>Date</th><th>Reason</th><th>Changed By</th></tr>
    </thead>
    <tbody>
      <?php foreach ($r3_data as $row): ?>
      <tr>
        <td><a href="project_form.php?id=<?=$row['project_id']?>" style="color:var(--acc);text-decoration:none;font-weight:500"><?=htmlspecialchars($row['project_name'])?></a></td>
        <td><?=htmlspecialchars($row['from_technology']??'')?></td>
        <td style="font-size:11px;color:var(--tx2);"><?=htmlspecialchars($row['from_subtype']??'')?></td>
        <td style="color:#10b981;"><?=htmlspecialchars($row['to_technology'])?></td>
        <td style="font-size:11px;color:var(--tx2);"><?=htmlspecialchars($row['to_subtype']??'')?></td>
        <td><?=htmlspecialchars($row['change_date'])?></td>
        <td style="font-size:12px;color:var(--tx2);"><?=htmlspecialchars($row['reason']??'')?></td>
        <td style="font-size:12px;color:var(--tx2);"><?=htmlspecialchars($row['changer']??'')?></td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>
<?php elseif ($r3_from || $r3_from_t): ?>
<div class="card"><div class="no-data">📭 No technology migrations found for selected filters.</div></div>
<?php else: ?>
<div class="card"><div class="no-data">Select filters above and click "Filter" to see migration history.</div></div>
<?php endif; ?>

<?php elseif ($tab === 'workorders'): ?>
<!-- ═══════════ REPORT 4: WORK ORDER SUMMARY ═══════════ -->
<div class="card card-sm" style="margin-bottom:14px">
  <form method="get">
    <input type="hidden" name="tab" value="workorders">
    <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap">
      <div style="display:flex;flex-direction:column;gap:3px">
        <label>Technology Filter</label>
        <select name="r4_tech">
          <option value="">All</option>
          <option value="NET" <?=$r4_tech==='NET'?'selected':''?>>.NET</option>
          <option value="WebMyWay" <?=$r4_tech==='WebMyWay'?'selected':''?>>WebMyWay</option>
          <option value="AEM" <?=$r4_tech==='AEM'?'selected':''?>>AEM</option>
          <option value="ALL" <?=$r4_tech==='ALL'?'selected':''?>>All Technologies</option>
        </select>
      </div>
      <div style="display:flex;flex-direction:column;gap:3px"><label style="font-family:'JetBrains Mono',monospace;font-size:9.5px;text-transform:uppercase;letter-spacing:1px;color:var(--tx2)">Created From</label><input type="date" name="r4_from" value="<?=htmlspecialchars($r4_from)?>" max="<?=$today?>"></div>
      <div style="display:flex;flex-direction:column;gap:3px"><label style="font-family:'JetBrains Mono',monospace;font-size:9.5px;text-transform:uppercase;letter-spacing:1px;color:var(--tx2)">Created To</label><input type="date" name="r4_to" value="<?=htmlspecialchars($r4_to)?>" max="<?=$today?>"></div>
      <button type="submit" class="btn btn-primary btn-sm">🔍 Run</button>
      <?php if ($r4_data): ?>
      <a href="?tab=workorders&r4_from=<?=$r4_from?>&r4_to=<?=$r4_to?>&r4_tech=<?=$r4_tech?>&export_r4=1" class="btn btn-ghost btn-sm" style="margin-top:auto;">⬇ CSV</a>
      <?php endif; ?>
    </div>
  </form>
</div>

<?php if ($r4_data): ?>
<div class="card" style="padding:0;overflow:hidden;">
  <table class="dv-table">
    <thead>
      <tr>
        <th>Work Order Title</th><th>Applicable Tech</th><th>Priority</th><th>Deadline</th>
        <th>Created</th><th>Total Sites</th><th>Completed</th><th>Pending</th><th>% Done</th><th>Status</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($r4_data as $wo):
        $pct = $wo['total_sites'] ? round($wo['done_sites']/$wo['total_sites']*100) : 0;
        $techs = json_decode($wo['applicable_tech']??'[]',true);
      ?>
      <tr>
        <td><a href="workorders.php?view=<?=$wo['id']?>" style="color:var(--acc);text-decoration:none;font-weight:500"><?=htmlspecialchars($wo['title'])?></a></td>
        <td style="font-size:12px;"><?=implode(', ',$techs)?></td>
        <td style="font-size:12px;"><?=htmlspecialchars($wo['priority'])?></td>
        <td style="font-size:12px;"><?=htmlspecialchars($wo['deadline']??'-')?></td>
        <td style="font-size:11px;color:var(--tx2);"><?=substr($wo['created_at'],0,10)?></td>
        <td style="text-align:center;"><?=$wo['total_sites']?></td>
        <td style="text-align:center;color:#10b981;font-weight:600;"><?=$wo['done_sites']?></td>
        <td style="text-align:center;color:<?=$wo['total_sites']-$wo['done_sites']>0?'#f59e0b':'#6b7280'?>;"><?=$wo['total_sites']-$wo['done_sites']?></td>
        <td style="text-align:center;font-weight:600;"><?=$pct?>%</td>
        <td>
          <?php $sc2=['Active'=>'#00e676','Completed'=>'#40c4ff','Cancelled'=>'#5a7a9a']; $c2=$sc2[$wo['status']]??'#5a7a9a'; ?>
          <span style="background:<?=$c2?>22;color:<?=$c2?>;font-size:9px;padding:2px 7px;border-radius:20px;font-weight:700;border:1px solid <?=$c2?>40;font-family:'Courier New',Consolas,monospace"><?=$wo['status']?></span>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
    <tfoot>
      <tr>
        <td colspan="5">Total (<?=count($r4_data)?> work orders)</td>
        <td style="text-align:center;"><?=array_sum(array_column($r4_data,'total_sites'))?></td>
        <td style="text-align:center;color:#10b981;"><?=array_sum(array_column($r4_data,'done_sites'))?></td>
        <td style="text-align:center;"><?=array_sum(array_column($r4_data,'total_sites'))-array_sum(array_column($r4_data,'done_sites'))?></td>
        <td colspan="2"></td>
      </tr>
    </tfoot>
  </table>
</div>
<?php else: ?>
<div class="card"><div class="no-data">📭 No work orders found for selected filters.</div></div>
<?php endif; ?>
<?php endif; ?>

</div>
<script src="session_timer.js"></script>
<script nonce="<?= csp_nonce() ?>">
document.addEventListener('click', function(e) {
  if (e.target.closest('[data-action="print"]')) window.print();
});

</div><!-- /.dv-content -->
<?php require_once __DIR__ . '/includes/sidebar_footer.php'; ?>

<?php
/**
 * WealthDash — FIRE Calculator
 * Task t293: FI number, current progress, time to FIRE, SWP sustainability
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = 'FIRE Calculator';
$activePage  = 'fire_calc';
$activeSection = 'reports';

// Pre-fill current portfolio value
$db = DB::conn();
$pid = get_user_portfolio_id((int)$currentUser['id']);
$currentPortfolio = 0;
if ($pid) {
    $s = $db->prepare("SELECT COALESCE(SUM(latest_value),0) FROM mf_holdings WHERE portfolio_id=?");
    $s->execute([$pid]);
    $currentPortfolio = (float)$s->fetchColumn();
}
// Add NPS + FD + savings
$s2 = $db->prepare("
    SELECT
      COALESCE((SELECT SUM(latest_value) FROM nps_holdings nh JOIN portfolios p ON p.id=nh.portfolio_id WHERE p.user_id=?),0)
    + COALESCE((SELECT SUM(principal_amount) FROM fd_holdings WHERE user_id=? AND status='active'),0)
    + COALESCE((SELECT SUM(balance) FROM savings_accounts WHERE user_id=?),0)
    AS other_assets
");
$s2->execute([(int)$currentUser['id'],(int)$currentUser['id'],(int)$currentUser['id']]);
$currentPortfolio += (float)$s2->fetchColumn();

ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">🔥 FIRE Calculator</h1>
    <p class="page-subtitle">Financial Independence, Retire Early — your number & timeline</p>
  </div>
</div>

<div style="display:grid;grid-template-columns:400px 1fr;gap:20px;align-items:start;" id="fireLayout">

  <!-- ── Input Panel ── -->
  <div style="display:flex;flex-direction:column;gap:16px;">

    <div class="card">
      <div class="card-header"><span style="font-weight:600;">💰 Your Numbers</span></div>
      <div class="card-body" style="display:flex;flex-direction:column;gap:14px;">

        <div>
          <label style="font-size:12px;color:var(--text-secondary);display:block;margin-bottom:4px;font-weight:600;">Monthly Expenses (₹) *</label>
          <input type="number" id="fireExpense" placeholder="e.g. 60000" min="1" step="1000"
            style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;background:var(--bg-secondary);color:var(--text);font-size:15px;box-sizing:border-box;"
            oninput="fireCalc()">
          <div style="font-size:11px;color:var(--text-secondary);margin-top:2px;">All monthly costs at today's value</div>
        </div>

        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div>
            <label style="font-size:12px;color:var(--text-secondary);display:block;margin-bottom:4px;font-weight:600;">Current Age</label>
            <input type="number" id="fireAge" value="30" min="18" max="70"
              style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;background:var(--bg-secondary);color:var(--text);font-size:14px;box-sizing:border-box;"
              oninput="fireCalc()">
          </div>
          <div>
            <label style="font-size:12px;color:var(--text-secondary);display:block;margin-bottom:4px;font-weight:600;">Target FI Age</label>
            <input type="number" id="fireTargetAge" value="45" min="25" max="70"
              style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;background:var(--bg-secondary);color:var(--text);font-size:14px;box-sizing:border-box;"
              oninput="fireCalc()">
          </div>
        </div>

        <div>
          <label style="font-size:12px;color:var(--text-secondary);display:block;margin-bottom:4px;font-weight:600;">
            Current Portfolio (₹)
            <span style="font-size:11px;color:var(--accent);margin-left:6px;">Auto-filled from WealthDash</span>
          </label>
          <input type="number" id="firePortfolio" value="<?= round($currentPortfolio) ?>" min="0" step="10000"
            style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;background:var(--bg-secondary);color:var(--text);font-size:14px;box-sizing:border-box;"
            oninput="fireCalc()">
        </div>

        <div>
          <label style="font-size:12px;color:var(--text-secondary);display:block;margin-bottom:4px;font-weight:600;">Monthly Savings / SIP (₹)</label>
          <input type="number" id="fireSavings" placeholder="e.g. 30000" min="0" step="1000"
            style="width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:8px;background:var(--bg-secondary);color:var(--text);font-size:14px;box-sizing:border-box;"
            oninput="fireCalc()">
        </div>

        <div style="background:var(--bg-secondary);border-radius:8px;padding:12px;">
          <div style="font-size:11px;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">Assumptions</div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
            <div>
              <label style="font-size:11px;color:var(--text-secondary);display:block;margin-bottom:3px;">Expected Return (%/yr)</label>
              <input type="number" id="fireReturn" value="12" min="1" max="30" step="0.5"
                style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg);color:var(--text);font-size:13px;box-sizing:border-box;"
                oninput="fireCalc()">
            </div>
            <div>
              <label style="font-size:11px;color:var(--text-secondary);display:block;margin-bottom:3px;">Inflation (%/yr)</label>
              <input type="number" id="fireInflation" value="6" min="1" max="15" step="0.5"
                style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg);color:var(--text);font-size:13px;box-sizing:border-box;"
                oninput="fireCalc()">
            </div>
            <div>
              <label style="font-size:11px;color:var(--text-secondary);display:block;margin-bottom:3px;">Safe Withdrawal Rate (%)</label>
              <input type="number" id="fireSwr" value="3.5" min="1" max="6" step="0.1"
                style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg);color:var(--text);font-size:13px;box-sizing:border-box;"
                oninput="fireCalc()">
            </div>
            <div>
              <label style="font-size:11px;color:var(--text-secondary);display:block;margin-bottom:3px;">Life Expectancy</label>
              <input type="number" id="fireLifeExp" value="85" min="60" max="100" step="1"
                style="width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg);color:var(--text);font-size:13px;box-sizing:border-box;"
                oninput="fireCalc()">
            </div>
          </div>
        </div>

        <div style="display:flex;gap:8px;">
          <button onclick="fireCalc()"
            style="flex:1;padding:12px;background:var(--accent);color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:700;cursor:pointer;">
            Calculate FIRE 🔥
          </button>
          <button onclick="fireReset()"
            style="padding:12px 16px;background:var(--bg-secondary);border:1px solid var(--border);border-radius:8px;font-size:14px;cursor:pointer;color:var(--text);">
            Reset
          </button>
        </div>
      </div>
    </div>

    <!-- FIRE Types Info -->
    <div class="card">
      <div class="card-body" style="padding:14px 16px;">
        <div style="font-size:12px;font-weight:700;color:var(--text-secondary);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">FIRE Variants</div>
        <?php foreach([
          ['🧘','Lean FIRE','Minimalist lifestyle — lower expenses, smaller corpus'],
          ['🔥','Regular FIRE','Standard 4% rule — 25× annual expenses'],
          ['💪','Fat FIRE','Comfortable retirement — 33× expenses (3% SWR)'],
          ['🌙','Barista FIRE','Part-time work + investments — hybrid approach'],
        ] as [$emoji,$name,$desc]): ?>
        <div style="display:flex;gap:10px;margin-bottom:8px;align-items:flex-start;">
          <span style="font-size:16px;"><?= $emoji ?></span>
          <div>
            <div style="font-size:12px;font-weight:600;"><?= $name ?></div>
            <div style="font-size:11px;color:var(--text-secondary);"><?= $desc ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <!-- ── Results Panel ── -->
  <div id="fireResults" style="display:none;flex-direction:column;gap:16px;">

    <!-- Hero: FI Number -->
    <div class="card" style="background:linear-gradient(135deg,var(--accent),#8b5cf6);color:#fff;overflow:hidden;position:relative;">
      <div style="padding:24px 28px;position:relative;z-index:1;">
        <div style="font-size:13px;opacity:.85;margin-bottom:4px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;">Your FIRE Number</div>
        <div id="fireNumber" style="font-size:38px;font-weight:800;line-height:1.1;"></div>
        <div id="fireNumberSub" style="font-size:13px;opacity:.8;margin-top:6px;"></div>
      </div>
      <div style="position:absolute;right:-30px;top:-30px;width:180px;height:180px;background:rgba(255,255,255,.06);border-radius:50%;"></div>
    </div>

    <!-- Progress bar -->
    <div class="card">
      <div class="card-body" style="padding:20px;">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
          <span style="font-size:13px;font-weight:600;">Progress to FIRE</span>
          <span id="fireProgressPct" style="font-size:15px;font-weight:800;color:var(--accent);"></span>
        </div>
        <div style="height:12px;background:var(--border);border-radius:6px;overflow:hidden;margin-bottom:8px;">
          <div id="fireProgressBar" style="height:100%;border-radius:6px;transition:width .8s ease;background:linear-gradient(90deg,var(--accent),#8b5cf6);"></div>
        </div>
        <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--text-secondary);">
          <span id="fireProgressCurrent">Current: ₹0</span>
          <span id="fireProgressNeeded">Target: ₹0</span>
        </div>
      </div>
    </div>

    <!-- Key metrics grid -->
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:12px;">
      <?php foreach([
        ['fireTimeToFI','⏳','Years to FIRE',''],
        ['fireAgeAtFI','🎯','FI Age',''],
        ['fireMonthlySWP','💸','Monthly SWP','at retirement'],
        ['fireCorpusAtFI','🏦','Projected Corpus','at FI age'],
        ['fireSurplusYears','📅','Corpus Lasts','years'],
        ['fireGap','📊','Monthly Gap','to reach target SIP'],
      ] as [$id,$icon,$label,$sub]): ?>
      <div style="background:var(--bg-secondary);border-radius:10px;padding:14px 12px;text-align:center;">
        <div style="font-size:20px;margin-bottom:4px;"><?= $icon ?></div>
        <div id="<?= $id ?>" style="font-size:16px;font-weight:700;color:var(--accent);margin-bottom:2px;">—</div>
        <div style="font-size:11px;color:var(--text-secondary);font-weight:600;"><?= $label ?></div>
        <?php if($sub): ?><div style="font-size:10px;color:var(--text-secondary);"><?= $sub ?></div><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>

    <!-- Projection chart -->
    <div class="card">
      <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <span style="font-weight:600;">📈 Portfolio Growth Projection</span>
        <span id="fireChartNote" style="font-size:11px;color:var(--text-secondary);"></span>
      </div>
      <div class="card-body" style="padding:12px 16px;">
        <canvas id="fireChart" height="200"></canvas>
      </div>
    </div>

    <!-- Scenarios -->
    <div class="card">
      <div class="card-header"><span style="font-weight:600;">🎲 Scenarios</span></div>
      <div class="card-body" style="padding:0;overflow-x:auto;">
        <table class="table" style="margin:0;">
          <thead>
            <tr>
              <th>Scenario</th>
              <th class="text-right">FIRE Number</th>
              <th class="text-right">Time to FI</th>
              <th class="text-right">Monthly SWP</th>
            </tr>
          </thead>
          <tbody id="fireScenariosBody"></tbody>
        </table>
      </div>
    </div>

  </div>

  <!-- Empty state -->
  <div id="fireEmpty" style="display:flex;flex-direction:column;align-items:center;justify-content:center;padding:80px 20px;color:var(--text-secondary);">
    <div style="font-size:56px;margin-bottom:12px;">🔥</div>
    <div style="font-size:16px;font-weight:600;margin-bottom:6px;">Enter your monthly expenses to start</div>
    <div style="font-size:13px;">Calculate your Financial Independence number</div>
  </div>
</div>

<script src="<?= APP_URL ?>/public/js/charts.js?v=<?= ASSET_VERSION ?>"></script>
<script>
let _fireChart = null;
const inr  = v => '₹' + Number(v||0).toLocaleString('en-IN',{maximumFractionDigits:0});
const inrC = v => { const n=Number(v||0); return n>=1e7?(n/1e7).toFixed(2)+' Cr':n>=1e5?(n/1e5).toFixed(2)+' L':inr(n); };

function fireReset() {
  ['fireExpense','fireSavings'].forEach(id => { const e=document.getElementById(id); if(e) e.value=''; });
  document.getElementById('fireResults').style.display = 'none';
  document.getElementById('fireEmpty').style.display   = '';
}

let _fireTimer = null;
function fireCalc() {
  clearTimeout(_fireTimer);
  _fireTimer = setTimeout(_doFireCalc, 300);
}

function _doFireCalc() {
  const expense   = parseFloat(document.getElementById('fireExpense')?.value   || 0);
  const age       = parseInt(document.getElementById('fireAge')?.value         || 30);
  const targetAge = parseInt(document.getElementById('fireTargetAge')?.value   || 45);
  const portfolio = parseFloat(document.getElementById('firePortfolio')?.value || 0);
  const savings   = parseFloat(document.getElementById('fireSavings')?.value   || 0);
  const retPct    = parseFloat(document.getElementById('fireReturn')?.value    || 12) / 100;
  const inflPct   = parseFloat(document.getElementById('fireInflation')?.value || 6)  / 100;
  const swr       = parseFloat(document.getElementById('fireSwr')?.value       || 3.5) / 100;
  const lifeExp   = parseInt(document.getElementById('fireLifeExp')?.value     || 85);

  if (!expense || expense <= 0) {
    document.getElementById('fireResults').style.display = 'none';
    document.getElementById('fireEmpty').style.display   = '';
    return;
  }

  document.getElementById('fireResults').style.display = 'flex';
  document.getElementById('fireEmpty').style.display   = 'none';

  const annualExp  = expense * 12;
  const fireNumber = annualExp / swr;            // e.g. 60K*12/0.035 = ~2.06Cr
  const realReturn = (1 + retPct) / (1 + inflPct) - 1;  // inflation-adjusted

  // Monthly SIP FV formula: FV = PV*(1+r)^n + PMT*((1+r)^n-1)/r
  const rMonthly = retPct / 12;
  let corpusAtTargetAge = 0;
  const yearsToTarget = Math.max(0, targetAge - age);
  const months = yearsToTarget * 12;

  if (months > 0) {
    corpusAtTargetAge = portfolio * Math.pow(1 + rMonthly, months)
      + (savings > 0 ? savings * (Math.pow(1 + rMonthly, months) - 1) / rMonthly : 0);
  } else {
    corpusAtTargetAge = portfolio;
  }

  // Time to reach FIRE number (binary search)
  let timeToFI = null;
  let fiAge = null;
  for (let y = 0; y <= 50; y++) {
    const m = y * 12;
    const proj = portfolio * Math.pow(1+rMonthly,m)
      + (savings>0 ? savings*(Math.pow(1+rMonthly,m)-1)/rMonthly : 0);
    if (proj >= fireNumber) { timeToFI = y; fiAge = age + y; break; }
  }

  // Monthly SWP sustainable at FIRE number
  const monthlySWP = (fireNumber * swr) / 12;
  // Inflation-adjusted monthly expense at FI age
  const expAtFI = annualExp * Math.pow(1+inflPct, yearsToTarget) / 12;

  // How long corpus lasts
  const retirementYears = lifeExp - (fiAge || targetAge);
  const corpusSustainYears = corpusAtTargetAge > 0 ? Math.min(retirementYears + 20,
    Math.log(1 - (corpusAtTargetAge * swr / (annualExp * Math.pow(1+inflPct,yearsToTarget)))) /
    Math.log(1 + realReturn) * -1 ) : 0;

  const progress = fireNumber > 0 ? Math.min(100, portfolio / fireNumber * 100) : 0;
  const gap = savings > 0 && timeToFI === null ? 
    (fireNumber * rMonthly / (Math.pow(1+rMonthly,600)-1) - portfolio*rMonthly) : 0;

  // Update UI
  document.getElementById('fireNumber').textContent = inrC(fireNumber);
  document.getElementById('fireNumberSub').textContent =
    `${annualExp>0?inrC(annualExp):''} annual expenses ÷ ${(swr*100).toFixed(1)}% SWR`;
  document.getElementById('fireProgressPct').textContent = progress.toFixed(1) + '%';
  document.getElementById('fireProgressBar').style.width = progress + '%';
  document.getElementById('fireProgressCurrent').textContent = 'Current: ' + inrC(portfolio);
  document.getElementById('fireProgressNeeded').textContent  = 'Target: ' + inrC(fireNumber);
  document.getElementById('fireTimeToFI').textContent = timeToFI !== null ? timeToFI + ' yrs' : '50+ yrs';
  document.getElementById('fireAgeAtFI').textContent  = fiAge || targetAge;
  document.getElementById('fireMonthlySWP').textContent = inrC(monthlySWP) + '/mo';
  document.getElementById('fireCorpusAtFI').textContent = inrC(corpusAtTargetAge);
  document.getElementById('fireSurplusYears').textContent = Math.max(0,Math.round(corpusSustainYears)) + ' yrs';
  document.getElementById('fireGap').textContent = gap > 0 ? '₹'+inrC(gap)+'/mo more' : '✅ On track';

  // Chart
  const chartYears = Math.max(yearsToTarget + 5, (timeToFI||0) + 5, 20);
  const labels = [], portfolioLine = [], fireLine = [];
  for (let y = 0; y <= Math.min(chartYears, 40); y++) {
    labels.push(age + y);
    const m = y * 12;
    const val = portfolio * Math.pow(1+rMonthly,m)
      + (savings>0 ? savings*(Math.pow(1+rMonthly,m)-1)/rMonthly : 0);
    portfolioLine.push(Math.round(val));
    fireLine.push(Math.round(fireNumber));
  }

  const accent = getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() || '#6366f1';
  if (_fireChart) _fireChart.destroy();
  _fireChart = new Chart(document.getElementById('fireChart'), {
    type: 'line',
    data: {
      labels,
      datasets: [
        { label:'Portfolio', data:portfolioLine, borderColor:accent, backgroundColor:accent+'18',
          fill:true, tension:.4, borderWidth:2, pointRadius:0 },
        { label:'FIRE Target', data:fireLine, borderColor:'#f59e0b', borderDash:[6,3],
          fill:false, tension:0, borderWidth:2, pointRadius:0 },
      ]
    },
    options: {
      responsive:true, maintainAspectRatio:true,
      plugins:{ tooltip:{ callbacks:{
        label: ctx => ctx.dataset.label+': '+inrC(ctx.raw)
      }}},
      scales:{
        x:{ title:{display:true,text:'Age',font:{size:11}}, ticks:{font:{size:10}} },
        y:{ ticks:{ callback: v => inrC(v), font:{size:10} }, grid:{color:'rgba(0,0,0,.05)'} }
      }
    }
  });
  document.getElementById('fireChartNote').textContent = `at ${(retPct*100).toFixed(0)}% return, ${(inflPct*100).toFixed(0)}% inflation`;

  // Scenarios table
  const scenarios = [
    { name:'🧘 Lean FIRE (4% SWR)',     swr:0.04, expMult:0.8 },
    { name:'🔥 Regular FIRE (3.5% SWR)', swr:0.035, expMult:1.0 },
    { name:'💪 Fat FIRE (3% SWR)',       swr:0.03, expMult:1.3 },
  ];
  document.getElementById('fireScenariosBody').innerHTML = scenarios.map(sc => {
    const fn = (annualExp * sc.expMult) / sc.swr;
    const swpM = fn * sc.swr / 12;
    let ttfi = null;
    for (let y=0;y<=50;y++){
      const m=y*12;
      const p=portfolio*Math.pow(1+rMonthly,m)+(savings>0?savings*(Math.pow(1+rMonthly,m)-1)/rMonthly:0);
      if(p>=fn){ttfi=y;break;}
    }
    return `<tr>
      <td>${sc.name}</td>
      <td class="text-right">${inrC(fn)}</td>
      <td class="text-right">${ttfi!==null?ttfi+' yrs':'50+ yrs'}</td>
      <td class="text-right">${inrC(swpM)}/mo</td>
    </tr>`;
  }).join('');
}

document.addEventListener('DOMContentLoaded', () => {
  <?php if($currentPortfolio > 0): ?>
  // Pre-fill and auto-calculate if portfolio available
  setTimeout(fireCalc, 300);
  <?php endif; ?>
});
</script>

<?php
$pageContent = ob_get_clean();
$extraScripts = '<script src="'.APP_URL.'/public/js/charts.js?v='.ASSET_VERSION.'"></script>';
require_once APP_ROOT . '/templates/layout.php';

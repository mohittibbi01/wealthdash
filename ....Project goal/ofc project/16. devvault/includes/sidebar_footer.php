<?php /* sidebar_footer.php — closes layout and adds JS */ ?>
</div><!-- /.dv-main -->
</div><!-- /.dv-layout -->

<script src="session_timer.js"></script>
<script nonce="<?= csp_nonce() ?>">
(function(){
  var SB=document.getElementById('dv-sidebar'),
      LO=document.getElementById('dv-layout'),
      OV=document.getElementById('sb-overlay'),
      BT=document.getElementById('sb-toggle'),
      MB=document.getElementById('sb-mobile-btn'),
      TP=document.getElementById('theme-panel'),
      TH=document.getElementById('sb-theme-btn'),
      TC=document.getElementById('theme-close'),
      TS=document.getElementById('theme-save'),
      FS=document.getElementById('fs-slider'),
      FV=document.getElementById('fs-val');

  var mob=()=>window.innerWidth<=768;

  function collapse(){SB.classList.add('collapsed');LO.classList.add('sb-collapsed');localStorage.setItem('dv_sb','0');if(TP)TP.style.left='68px';}
  function expand(){SB.classList.remove('collapsed');LO.classList.remove('sb-collapsed');localStorage.setItem('dv_sb','1');if(TP)TP.style.left='238px';}

  function toggleSB(){
    if(mob()){
      var o=SB.classList.contains('mobile-open');
      SB.classList.toggle('mobile-open',!o);
      OV.style.display=o?'none':'block';
    } else {
      SB.classList.contains('collapsed')?expand():collapse();
    }
  }

  // Restore state
  if(!mob() && localStorage.getItem('dv_sb')==='0') collapse();

  if(BT) BT.addEventListener('click',toggleSB);
  if(MB) MB.addEventListener('click',toggleSB);
  if(OV) OV.addEventListener('click',function(){SB.classList.remove('mobile-open');OV.style.display='none';});

  function checkMob(){
    MB&&(MB.style.display=mob()?'flex':'none');
    if(!mob()){SB.classList.remove('mobile-open');OV.style.display='none';}
  }
  checkMob(); window.addEventListener('resize',checkMob);

  // Theme state
  var _t=document.documentElement.getAttribute('data-theme')||'teal-dark';
  var _cb=document.documentElement.getAttribute('data-colorblind')||'none';
  var _fs=parseInt(document.documentElement.style.fontSize)||14;

  function syncSwatches(){document.querySelectorAll('.theme-swatch').forEach(function(s){s.style.outline=s.dataset.t===_t?'2px solid var(--tx)':'2px solid transparent';s.style.transform=s.dataset.t===_t?'scale(1.12)':'scale(1)';})}
  function syncCb(){document.querySelectorAll('.cb-opt').forEach(function(b){b.classList.toggle('active',b.dataset.cb===_cb);})}
  syncSwatches(); syncCb();

  // Open/close theme panel
  if(TH) TH.addEventListener('click',function(e){
    e.stopPropagation();
    var open=TP.style.display==='block';
    TP.style.display=open?'none':'block';
    TP.style.left=SB.classList.contains('collapsed')?'68px':'238px';
  });
  if(TC) TC.addEventListener('click',function(){TP.style.display='none';});
  document.addEventListener('click',function(e){
    if(TP&&TP.style.display==='block'&&!TP.contains(e.target)&&e.target!==TH)TP.style.display='none';
  });

  // Theme swatches
  document.querySelectorAll('.theme-swatch').forEach(function(s){
    s.addEventListener('click',function(){
      _t=s.dataset.t;
      document.documentElement.setAttribute('data-theme',_t);
      syncSwatches();
    });
  });

  // Colorblind
  document.querySelectorAll('.cb-opt').forEach(function(b){
    b.addEventListener('click',function(){
      _cb=b.dataset.cb;
      if(_cb==='none') document.documentElement.removeAttribute('data-colorblind');
      else document.documentElement.setAttribute('data-colorblind',_cb);
      syncCb();
    });
  });

  // Font size — LIVE update
  if(FS) FS.addEventListener('input',function(){
    _fs=parseInt(this.value);
    document.documentElement.style.fontSize=_fs+'px';
    if(FV) FV.textContent=_fs+'px';
  });

  // Save
  if(TS) TS.addEventListener('click',function(){
    var csrf=window.DEVVAULT_CSRF||'';
    if(!csrf){TP.style.display='none';return;}
    var fd=new FormData();
    fd.append('action','save_prefs');fd.append('csrf',csrf);
    fd.append('theme',_t);fd.append('font','Inter');
    fd.append('fs',String(_fs));fd.append('colorblind',_cb);
    fd.append('accent',getComputedStyle(document.documentElement).getPropertyValue('--acc').trim()||'#00d4aa');
    fetch('api.php',{method:'POST',body:fd})
      .then(function(r){return r.json();})
      .then(function(){TP.style.display='none';})
      .catch(function(){TP.style.display='none';});
  });
// ── Global confirm handler for all data-confirm buttons ──────────────────────
document.addEventListener('click', function(e) {
  var dc = e.target.closest('[data-confirm]');
  if (dc && dc.type === 'submit') {
    if (!confirm(dc.dataset.confirm)) e.preventDefault();
  }
});

// ── Global modal dispatcher ───────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('[data-action]').forEach(function(btn) {
    btn.style.pointerEvents = 'auto';
  });
});
})();
</script>
</body></html>

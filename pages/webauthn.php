<?php
/**
 * WealthDash — t371: Biometric Login Settings Page
 * File: pages/security/webauthn.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='Biometric Login'; $activePage='settings'; $activeSection='security';
ob_start();
?>
<div class="page-header"><h1 class="page-title">🔐 Biometric Login</h1><p class="page-subtitle">Use Face ID, Touch ID, or Windows Hello to sign in.</p></div>
<div class="card" style="max-width:560px;">
  <div class="card-body">
    <div class="alert alert-info" style="font-size:13px;margin-bottom:20px;">WebAuthn uses your device's built-in biometric authentication. Your fingerprint/face data never leaves your device.</div>
    <div id="wa-support-check" class="alert alert-warning" style="display:none;font-size:13px;">⚠️ Your browser does not support WebAuthn. Use Chrome, Safari, Firefox (latest) on a device with biometric support.</div>
    <div id="wa-register-section">
      <h3 style="font-size:15px;font-weight:700;margin-bottom:12px;">Register New Device</h3>
      <div class="form-group"><label class="form-label">Device Name</label><input type="text" id="wa-device-name" class="form-control" placeholder="My iPhone / Work Laptop" value="My Device"></div>
      <button class="btn btn-primary" id="wa-register-btn" onclick="WA.register()">🔐 Register Biometric</button>
    </div>
    <div id="wa-credentials" style="margin-top:24px;border-top:1px solid var(--border);padding-top:16px;"></div>
  </div>
</div>

<script>
const WA={
  init(){
    if(!window.PublicKeyCredential){document.getElementById('wa-support-check').style.display='';document.getElementById('wa-register-section').style.opacity='.4';document.getElementById('wa-register-btn').disabled=true;}
    this.loadCreds();
  },
  async register(){
    const name=document.getElementById('wa-device-name').value.trim()||'My Device';
    document.getElementById('wa-register-btn').disabled=true;
    try{
      // Get options from server
      const optRes=await apiPost({action:'webauthn_register_options'});
      if(!optRes.ok){showToast(optRes.message,'error');document.getElementById('wa-register-btn').disabled=false;return;}
      const opts=optRes.data;

      // Create credential
      const cred=await navigator.credentials.create({publicKey:{
        challenge:_b64urlToBuffer(opts.challenge),
        rp:{id:opts.rp.id,name:opts.rp.name},
        user:{id:_b64urlToBuffer(opts.user.id),name:opts.user.name,displayName:opts.user.displayName},
        pubKeyCredParams:opts.pubKeyCredParams,
        timeout:opts.timeout,
        excludeCredentials:(opts.excludeCredentials||[]).map(c=>({id:_b64urlToBuffer(c.id),type:c.type})),
        authenticatorSelection:opts.authenticatorSelection,
        attestation:opts.attestation,
      }});

      // Verify with server
      const verRes=await apiPost({
        action:'webauthn_register_verify',
        credential_id:_bufferToB64url(cred.rawId),
        client_data_json:_bufferToB64url(cred.response.clientDataJSON),
        attestation_object:_bufferToB64url(cred.response.attestationObject),
        device_name:name,
      });
      showToast(verRes.message,verRes.ok?'success':'error');
      if(verRes.ok)this.loadCreds();
    }catch(e){
      showToast('Biometric registration failed: '+(e.message||e),'error');
    }
    document.getElementById('wa-register-btn').disabled=false;
  },
  loadCreds(){
    apiPost({action:'webauthn_credentials_list'}).then(r=>{
      const wrap=document.getElementById('wa-credentials');
      const creds=r.data?.credentials||[];
      if(!creds.length){wrap.innerHTML='<div style="font-size:13px;color:var(--text-muted);">No biometric devices registered yet.</div>';return;}
      let html=`<h3 style="font-size:14px;font-weight:700;margin-bottom:12px;">Registered Devices (${creds.length})</h3>`;
      for(const c of creds){
        html+=`<div style="display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid var(--border);">
          <div><div style="font-weight:600;font-size:14px;">🔑 ${esc(c.device_name)}</div><div style="font-size:12px;color:var(--text-muted);">Registered: ${esc(c.created_at?.substring(0,10))} · Last used: ${esc(c.last_used_at?.substring(0,10)||'Never')}</div></div>
          <button class="btn btn-danger btn-sm" onclick="WA.del(${c.id})">Remove</button>
        </div>`;
      }
      wrap.innerHTML=html;
    });
  },
  del(id){if(!confirm('Remove this biometric device?'))return;apiPost({action:'webauthn_credential_delete',id}).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok)this.loadCreds();});}
};
function _b64urlToBuffer(b64){const bin=atob(b64.replace(/-/g,'+').replace(/_/g,'/'));return Uint8Array.from(bin,c=>c.charCodeAt(0)).buffer;}
function _bufferToB64url(buf){return btoa(String.fromCharCode(...new Uint8Array(buf))).replace(/\+/g,'-').replace(/\//g,'_').replace(/=/g,'');}
document.addEventListener('DOMContentLoaded',()=>WA.init());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';

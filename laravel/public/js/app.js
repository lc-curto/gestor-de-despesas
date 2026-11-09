(()=> {
 const root=document.documentElement,button=document.getElementById('themeToggle'),key='meu-bolso-theme',saved=localStorage.getItem(key);
 if(saved==='light'||saved==='dark')root.setAttribute('data-bs-theme',saved);
 function updateTheme(){if(!button)return;const dark=root.getAttribute('data-bs-theme')==='dark';button.innerHTML=dark?'<i class="bi bi-sun"></i>':'<i class="bi bi-moon-stars"></i>';}
 button?.addEventListener('click',()=>{root.setAttribute('data-bs-theme',root.getAttribute('data-bs-theme')==='dark'?'light':'dark');localStorage.setItem(key,root.getAttribute('data-bs-theme'));updateTheme();});
 updateTheme();
 const modalElement=document.getElementById('inactivityModal');
 if(modalElement&&window.bootstrap){
  const modal=new bootstrap.Modal(modalElement),logout=document.querySelector('form[action$="/logout"]'),continueButton=document.getElementById('continueSessionButton'),counter=document.getElementById('inactivityCountdown');
  const warningMs=14*60*1000;let idleTimer=null,countdownTimer=null,remaining=60;
  function reset(){clearTimeout(idleTimer);clearInterval(countdownTimer);modal.hide();idleTimer=setTimeout(showWarning,warningMs);}
  function logoutNow(){if(logout)logout.requestSubmit();else window.location.href='/login';}
  function showWarning(){remaining=60;counter.textContent='01:00';modal.show();countdownTimer=setInterval(()=>{remaining--;counter.textContent='00:'+String(remaining).padStart(2,'0');if(remaining<=0){clearInterval(countdownTimer);logoutNow();}},1000);}
  ['pointerdown','keydown','scroll','touchstart','click'].forEach(name=>document.addEventListener(name,()=>{if(!modalElement.classList.contains('show'))reset();},{passive:true}));
  continueButton?.addEventListener('click',async()=>{try{const csrf=document.querySelector('meta[name="csrf-token"]')?.content;const response=await fetch('/session/activity',{method:'POST',headers:{'X-CSRF-TOKEN':csrf,'Accept':'application/json'}});if(!response.ok)throw new Error();reset();}catch{logoutNow();}});
  reset();
 }
 if('serviceWorker'in navigator)window.addEventListener('load',()=>navigator.serviceWorker.register('/service-worker.js').catch(()=>{}));
})();
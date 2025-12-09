document.addEventListener('DOMContentLoaded',function(){
  if(window.feather){try{feather.replace();}catch(e){}}
  if(window.AOS){try{AOS.init({duration:700,easing:'ease-out',once:true});}catch(e){}}
  // Mobile menu toggles with ARIA
  document.querySelectorAll('[data-toggle="mobile-menu"]').forEach(function(btn){
    var targetId=btn.getAttribute('aria-controls');
    var target=document.getElementById(targetId);
    if(!target) return;
    btn.setAttribute('aria-expanded','false');
    btn.addEventListener('click',function(){
      var expanded=btn.getAttribute('aria-expanded')==='true';
      btn.setAttribute('aria-expanded',String(!expanded));
      target.classList.toggle('hidden');
      target.classList.toggle('show');
    });
  });
  // ESC to close any .modal.active
  document.addEventListener('keydown',function(e){
    if(e.key==='Escape'){
      document.querySelectorAll('.modal.active').forEach(function(m){m.classList.remove('active');});
    }
  });
});
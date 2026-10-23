'use strict';
(()=>{
 const root=document.querySelector('[data-moderation]');if(!root)return;
 const boot=JSON.parse(document.querySelector('#app-state').textContent);
 const find=s=>root.querySelector(s),form=find('[data-moderation-filter]'),status=find('[data-moderation-status]');
 const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 let page=1,busy=false,currentType='post';
 async function api(action,data){const response=await fetch('?api=1',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':boot.csrf},body:JSON.stringify({action,...data})});const result=await response.json();if(!response.ok)throw new Error(result.error||result.message||'Não foi possível concluir.');return result;}
 async function load(){
  if(busy)return;busy=true;status.textContent='Carregando…';find('[data-moderation-prev]').disabled=true;find('[data-moderation-next]').disabled=true;
  try{const filters=Object.fromEntries(new FormData(form));const result=await api('moderation-list',{...filters,page});currentType=filters.type;
   find('[data-moderation-list]').innerHTML=result.entries.map(r=>`<article class="card moderation-entry"><h2>${esc(r.target_name||'Perfil removido')}</h2>${r.target_username?`<a class="text-link" href="?page=public-profile&user=${encodeURIComponent(r.target_username)}">@${esc(r.target_username)}</a>`:''}<p class="muted">Denúncia #${r.id} · ${esc(r.status)} · ${esc(r.created_at)}</p>${currentType==='post'?`<h3>Conteúdo denunciado</h3><p class="moderation-copy">${esc(r.conteudo)}</p>`:''}<h3>Motivo</h3><p class="moderation-copy">${esc(r.motivo)}</p>${r.moderator_name?`<p class="muted">Última análise: ${esc(r.moderator_name)}</p>`:''}<form data-resolve="${r.id}"><label class="form-label">Situação<select class="input" name="status">${[['nova','Nova'],['em_analise','Em análise'],['resolvida','Resolvida'],['arquivada','Arquivada']].map(([v,l])=>`<option value="${v}" ${r.status===v?'selected':''}>${l}</option>`).join('')}</select></label><label class="form-label">Decisão da moderação<textarea class="input" name="resolution" rows="3" minlength="3" maxlength="2000" required>${esc(r.resolucao)}</textarea></label>${currentType==='post'&&r.post_id?'<label class="moderation-hide"><input type="checkbox" name="hide_post"> Ocultar esta publicação da comunidade</label>':''}<button class="btn btn-primary" type="submit">Salvar decisão</button><p role="status" data-result></p></form></article>`).join('');
   status.textContent=result.total?`${result.total} denúncia(s) encontrada(s).`:'Nenhuma denúncia neste filtro.';find('[data-moderation-page]').textContent=`Página ${page}`;find('[data-moderation-prev]').disabled=page<=1;find('[data-moderation-next]').disabled=!result.more;
  }catch(e){status.textContent=e.message;}finally{busy=false;}
 }
 form.addEventListener('submit',e=>{e.preventDefault();if(busy)return;page=1;load();});
 find('[data-moderation-prev]').onclick=()=>{if(!busy){page--;load();}};find('[data-moderation-next]').onclick=()=>{if(!busy){page++;load();}};
 root.addEventListener('submit',async e=>{const f=e.target;if(!f.matches('[data-resolve]'))return;e.preventDefault();if(f.dataset.busy)return;f.dataset.busy='1';const btn=f.querySelector('button'),result=f.querySelector('[data-result]');btn.disabled=true;
  try{const data=Object.fromEntries(new FormData(f));const response=await api('moderation-update',{...data,type:currentType,id:f.dataset.resolve,hide_post:!!data.hide_post});result.textContent=response.message;await load();}catch(err){result.textContent=err.message;}finally{btn.disabled=false;delete f.dataset.busy;}
 });load();
})();

'use strict';
(()=>{
 const root=document.querySelector('[data-admin]');if(!root)return;
 const boot=JSON.parse(document.querySelector('#app-state').textContent),find=s=>root.querySelector(s),filter=find('[data-admin-filter]');
 const esc=v=>String(v??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
 let page=1,kind='users',busy=false;
 const input=(label,name,value,type='text')=>`<label class="form-label">${label}<input class="input" name="${name}" type="${type}" value="${esc(value)}" required></label>`;
 const select=(label,name,value,options)=>`<label class="form-label">${label}<select class="input" name="${name}">${options.map(([v,l])=>`<option value="${v}" ${value===v?'selected':''}>${l}</option>`).join('')}</select></label>`;
 async function api(action,data){const r=await fetch('?api=1',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':boot.csrf},body:JSON.stringify({action,...data})});const j=await r.json();if(!r.ok)throw Error(j.error||Object.values(j.errors||{}).flat().join(' ')||j.message||'Não foi possível salvar.');return j;}
 function entry(u){let fields='';
  if(kind==='users')fields=`<h2>${esc(u.name)}</h2><a class="text-link" href="?page=public-profile&user=${encodeURIComponent(u.username)}">@${esc(u.username)}</a><div class="admin-fields">${input('Nome','name',u.name)}${input('E-mail','email',u.email,'email')}${select('Permissão','role',u.role,[['aluno','Aluno'],['professor','Professor'],['moderador','Moderador'],['admin','Administrador']])}<label class="form-label">Nova senha (opcional)<input class="input" name="password" type="password" minlength="12" maxlength="128" autocomplete="new-password" placeholder="Deixe vazio para manter"></label></div><label class="admin-check"><input type="checkbox" name="verified" ${u.verificado_em?'checked':''}> Professor verificado</label><label class="admin-check"><input type="checkbox" name="suspended" ${u.suspended_at?'checked':''}> Suspender acesso à conta</label><p class="muted">Suspender ou alterar a senha encerra as sessões dessa conta. Reativar preserva o conteúdo.</p>`;
  if(kind==='posts')fields=`<p class="muted">Publicação #${u.id} · ${esc(u.user?.name)} · ${esc(u.created_at)}</p>${input('Título','title',u.title)}<label class="form-label">Conteúdo<textarea class="input" name="content" maxlength="20000" required>${esc(u.content)}</textarea></label>${select('Visibilidade','status',u.status,[['aprovado','Publicada'],['pendente','Pendente'],['reprovado','Oculta']])}`;
  if(kind==='subjects')fields=input('Nome do tópico','name',u.name);
  return `<form class="card admin-entry" data-admin-edit="${u.id}">${fields}<button class="btn btn-primary">Salvar alterações</button><p role="status" data-result></p></form>`;
 }
 async function load(){if(busy)return;busy=true;find('[data-admin-prev]').disabled=true;find('[data-admin-next]').disabled=true;find('[data-admin-status]').textContent='Carregando…';find('[data-admin-list]').innerHTML='';
  try{const f=Object.fromEntries(new FormData(filter));kind=f.kind;const r=await api('admin-list',{...f,page});find('[data-admin-list]').innerHTML=r.entries.map(entry).join('');find('[data-admin-status]').textContent=`${r.total} registro(s) encontrado(s).`;find('[data-admin-create]').hidden=kind!=='subjects';find('[data-admin-page]').textContent=`Página ${page}`;find('[data-admin-prev]').disabled=page===1;find('[data-admin-next]').disabled=!r.more;}catch(e){find('[data-admin-status]').textContent=e.message;}finally{busy=false;}
 }
 filter.addEventListener('submit',e=>{e.preventDefault();if(!busy){page=1;load();}});
 find('[data-admin-prev]').onclick=()=>{if(!busy){page--;load();}};find('[data-admin-next]').onclick=()=>{if(!busy){page++;load();}};
 root.addEventListener('submit',async e=>{const f=e.target;if(!f.matches('[data-admin-edit],[data-admin-create]'))return;e.preventDefault();if(f.dataset.busy)return;f.dataset.busy='1';const btn=f.querySelector('button'),out=f.querySelector('[data-result]');btn.disabled=true;
  try{const data=Object.fromEntries(new FormData(f));const result=await api('admin-save',{...data,kind:f.matches('[data-admin-create]')?'subjects':kind,id:f.dataset.adminEdit||0,verified:!!data.verified,suspended:!!data.suspended});out.textContent=result.message;const password=f.querySelector('[name=password]');if(password)password.value='';if(f.matches('[data-admin-create]')){f.reset();await load();}}catch(err){out.textContent=err.message;}finally{btn.disabled=false;delete f.dataset.busy;}
 });load();
})();

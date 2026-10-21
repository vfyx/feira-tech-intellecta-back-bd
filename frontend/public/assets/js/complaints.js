'use strict';
(() => {
  const root = document.querySelector('[data-complaints-app]');
  if (!root) return;
  const boot = JSON.parse(document.querySelector('#app-state').textContent), account = boot.data.account;
  const find = (s, parent = root) => parent.querySelector(s);
  const esc = value => String(value ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const form = find('[data-complaint-form]'), status = find('[data-complaint-load-status]');
  let requestKey = crypto.randomUUID(), loading = false;
  async function api(action, data = {}) {
    const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 20000);
    try {
      const response = await fetch('?api=1', {method:'POST', headers:{'Content-Type':'application/json','X-CSRF-Token':boot.csrf}, body:JSON.stringify({action,...data}), signal:controller.signal});
      const result = await response.json();
      if (!response.ok || result.error) throw new Error(result.error || result.message || 'Não foi possível concluir.');
      return result;
    } catch (error) { if (error.name === 'AbortError') throw new Error('O servidor demorou a responder. Atualize a lista para conferir o envio ou tente novamente.'); throw error; }
    finally { clearTimeout(timer); }
  }
  function date(value) { if (!value) return ''; const d = new Date(value.replace(' ', 'T')+'Z'); return Number.isNaN(d.getTime()) ? value : d.toLocaleString('pt-BR'); }
  function draw(entries) {
    find('[data-complaint-list]').innerHTML = entries.map(item => `<details class="card complaint-entry"><summary><span><strong>${esc(item.subject)}</strong><small>${esc(item.protocol)} · ${esc(date(item.created))} · ${esc(String(item.teacherId) === String(account.id) ? item.name : item.teacher)}</small></span><span class="complaint-entry__status">${item.reply ? 'Respondida' : 'Aguardando resposta'}</span></summary><div class="complaint-entry__body"><p class="muted">${esc(item.name)} · ${esc(item.course)} · ${esc(item.class)}${item.rm ? ' · RM '+esc(item.rm) : ''}</p><h3>Relato</h3><p>${esc(item.description)}</p>${item.request ? `<h3>Solicitação</h3><p>${esc(item.request)}</p>` : ''}${item.reply ? `<div class="complaint-entry__reply"><h3>Resposta de ${esc(item.teacher)}</h3><p>${esc(item.reply)}</p><small class="muted">${esc(date(item.replied))}</small></div>` : ''}${String(item.teacherId) === String(account.id) && account.role === 'professor' ? `<form data-complaint-reply="${esc(item.id)}"><label class="form-label">${item.reply ? 'Atualizar resposta' : 'Responder ao aluno'}<textarea class="input" name="reply" rows="4" minlength="3" maxlength="3000" required>${esc(item.reply || '')}</textarea></label><p class="complaint-feedback" role="status"></p><button type="submit" class="btn btn-primary">Enviar resposta</button></form>` : ''}</div></details>`).join('');
    status.textContent = entries.length ? '' : 'Nenhuma reclamação por aqui ainda.';
  }
  async function load() {
    if (loading) return; loading = true; find('[data-complaint-refresh]').disabled = true;
    status.textContent = 'Atualizando reclamações…';
    try {
      const result = await api('complaints');
      if (form) {
        const select = find('[name="teacher"]', form), selected = select.value;
        select.innerHTML = '<option value="">Selecione um professor</option>'+result.teachers.map(p => `<option value="${esc(p.id)}">${esc(p.name)}</option>`).join('');
        select.value = selected; select.disabled = !result.teachers.length;
        find('[type="submit"]', form).disabled = !result.teachers.length;
        if (!result.teachers.length) { select.innerHTML = '<option value="">Nenhum professor cadastrado</option>'; }
      }
      draw(result.entries);
    } catch (error) { status.textContent = error.message+' Use Atualizar para tentar novamente.'; }
    finally { loading = false; find('[data-complaint-refresh]').disabled = false; }
  }
  find('[data-complaint-refresh]').onclick = load;
  root.addEventListener('submit', async event => {
    const target = event.target;
    if (!target.matches('[data-complaint-form],[data-complaint-reply]')) return;
    event.preventDefault(); event.stopPropagation();
    if (target.dataset.sending) return;
    for (const input of target.querySelectorAll('[required]')) {
      input.setCustomValidity(input.value.trim() ? '' : 'Preencha este campo.');
      if (!input.reportValidity()) return;
    }
    const button = find('[type="submit"]', target), feedback = find('.complaint-feedback', target), values = Object.fromEntries(new FormData(target));
    target.dataset.sending = '1'; button.disabled = true; const label = button.textContent; button.textContent = 'Enviando…'; feedback.textContent = ''; feedback.dataset.error = 'false';
    try {
      const isReply = target.hasAttribute('data-complaint-reply');
      const result = await api(isReply ? 'complaint-reply' : 'complaint-create', isReply ? {id:target.dataset.complaintReply,reply:values.reply} : {...values,request_key:requestKey});
      if (isReply) {
        await load(); status.textContent = result.message;
      } else {
        requestKey = crypto.randomUUID();
        ['subject','description','request'].forEach(name => find(`[name="${name}"]`, target).value = '');
        feedback.textContent = `${result.message} Protocolo: ${result.protocol}.`; feedback.focus(); await load();
      }
    } catch (error) { feedback.dataset.error = 'true'; feedback.textContent = error.message; }
    finally { delete target.dataset.sending; button.disabled = false; button.textContent = label; }
  });
  root.addEventListener('input', event => { if (event.target.setCustomValidity) event.target.setCustomValidity(''); });
  load();
})();

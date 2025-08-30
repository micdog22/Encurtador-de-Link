let CSRF = null;
let chartRef = null;

const $ = sel => document.querySelector(sel);
const $$ = sel => Array.from(document.querySelectorAll(sel));

const fmtInt = n => Number(n).toLocaleString('pt-BR');
const originBase = () => location.origin + '/api/go/';

async function getCSRF() {
  const r = await fetch('../api/csrf');
  const j = await r.json();
  CSRF = j.token;
}

async function loadStats() {
  const r = await fetch('../api/stats');
  const j = await r.json();
  $('#totalLinks').textContent = fmtInt(j.totalLinks);
  $('#totalClicks').textContent = fmtInt(j.totalClicks);
  $('#topCode').textContent = j.top?.[0]?.code ?? '—';

  const labels = j.series.map(s => s.day);
  const data = j.series.map(s => Number(s.clicks));

  if (chartRef) chartRef.destroy();
  const ctx = $('#chart').getContext('2d');
  chartRef = new Chart(ctx, {
    type: 'line',
    data: { labels, datasets: [{ label: 'Cliques/dia (30d)', data }] },
    options: { responsive: true, maintainAspectRatio: false }
  });
}

function rowTemplate(item) {
  const tpl = $('#row').content.cloneNode(true);
  const urlShort = originBase() + item.code;

  tpl.querySelector('[data-k="code"]').textContent = item.code;
  tpl.querySelector('[data-k="url"]').textContent = item.url;
  tpl.querySelector('[data-k="title"]').textContent = item.title ?? '';
  tpl.querySelector('[data-k="clicks_count"]').textContent = item.clicks_count;

  const btnCopy = tpl.querySelector('[data-act="copy"]');
  const btnStats = tpl.querySelector('[data-act="stats"]');
  const btnEdit = tpl.querySelector('[data-act="edit"]');
  const btnDel = tpl.querySelector('[data-act="del"]');

  btnCopy.onclick = async () => {
    await navigator.clipboard.writeText(urlShort);
    alert('Copiado: ' + urlShort);
  };

  btnStats.onclick = async () => {
    const r = await fetch(`../api/stats/${item.id}`);
    const j = await r.json();
    $('#stTitle').textContent = `${item.code} → ${item.title ?? ''}`;
    $('#stInfo').textContent = `URL: ${item.url} • Cliques: ${item.clicks_count}`;

    const tbody = $('#tblRecent tbody');
    tbody.innerHTML = '';
    (j.recent || []).forEach(row => {
      const tr = document.createElement('tr');
      tr.innerHTML = `<td>${row.at}</td><td>${row.ip ?? ''}</td><td>${row.ref ?? ''}</td><td>${(row.ua ?? '').slice(0,80)}</td>`;
      tbody.appendChild(tr);
    });
    $('#dlgStats').showModal();
  };

  btnEdit.onclick = () => openEdit(item);
  btnDel.onclick = () => delItem(item.id);

  return tpl;
}

async function loadTable() {
  const q = $('#q').value.trim();
  const r = await fetch('../api/links' + (q ? ('?q=' + encodeURIComponent(q)) : ''));
  const j = await r.json();

  const tbody = $('#tbody');
  tbody.innerHTML = '';
  j.items.forEach(it => tbody.appendChild(rowTemplate(it)));
}

async function addItem(ev) {
  ev.preventDefault();
  const fd = new FormData(ev.currentTarget);
  const data = Object.fromEntries(fd.entries());

  const r = await fetch('../api/links', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: JSON.stringify(data)
  });
  const j = await r.json();
  if (!r.ok) {
    alert('Erro:\n' + JSON.stringify(j, null, 2));
    return;
  }
  ev.currentTarget.reset();
  await Promise.all([loadStats(), loadTable()]);
}

function openEdit(item) {
  const dlg = $('#dlgEdit');
  const f = $('#formEdit');
  f.id.value = item.id;
  f.url.value = item.url;
  f.title.value = item.title ?? '';
  f.code.value = item.code;
  dlg.showModal();
}

async function saveEdit(ev) {
  ev.preventDefault();
  const fd = new FormData(ev.currentTarget);
  const id = fd.get('id');
  const data = Object.fromEntries(fd.entries());
  delete data.id;

  const r = await fetch(`../api/links/${id}`, {
    method: 'PUT',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
    body: JSON.stringify(data)
  });
  const j = await r.json();
  if (!r.ok) {
    alert('Erro:\n' + JSON.stringify(j, null, 2));
    return;
  }
  $('#dlgEdit').close();
  await Promise.all([loadStats(), loadTable()]);
}

async function delItem(id) {
  if (!confirm('Excluir este link?')) return;
  const r = await fetch(`../api/links/${id}`, {
    method: 'DELETE',
    headers: { 'X-CSRF-Token': CSRF }
  });
  if (!r.ok) {
    const j = await r.json();
    alert('Erro:\n' + JSON.stringify(j, null, 2));
    return;
  }
  await Promise.all([loadStats(), loadTable()]);
}

function bindUI() {
  $('#btnFilter').onclick = () => loadTable();
  $('#btnClear').onclick = () => { $('#q').value=''; loadTable(); };
  $('#btnExportLinks').onclick = () => window.open('../api/export/links', '_blank');
  $('#btnCancelEdit').onclick = () => $('#dlgEdit').close();

  $('#formNew').addEventListener('submit', addItem);
  $('#formEdit').addEventListener('submit', saveEdit);
}

(async function init() {
  await getCSRF();
  bindUI();
  await Promise.all([loadStats(), loadTable()]);
})();

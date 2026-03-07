<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_login();
$pageTitle = 'Reativação de Clientes';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $pageTitle ?> — Formen CRM</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
:root {
  --bg:       #0f0f11;
  --bg2:      #17171a;
  --bg3:      #1e1e23;
  --border:   #2a2a32;
  --border2:  #35353f;
  --text:     #e8e8ec;
  --text2:    #9090a0;
  --text3:    #55555f;
  --accent:   #7c6af5;
  --accent2:  #5b52d6;
  --green:    #34d17a;
  --green2:   #1a6b3e;
  --yellow:   #f5c842;
  --yellow2:  #7a6010;
  --red:      #f55252;
  --red2:     #6b1a1a;
  --orange:   #f59b42;
  --radius:   10px;
  --radius-sm:6px;
  --shadow:   0 4px 24px rgba(0,0,0,.45);
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

body {
  font-family: 'Inter', sans-serif;
  background: var(--bg);
  color: var(--text);
  min-height: 100vh;
  display: flex;
}

/* ── SIDEBAR ── */
.sidebar {
  width: 220px;
  min-height: 100vh;
  background: var(--bg2);
  border-right: 1px solid var(--border);
  padding: 24px 0;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
}
.sidebar-logo {
  font-size: 18px;
  font-weight: 700;
  letter-spacing: -.5px;
  color: var(--text);
  padding: 0 20px 24px;
  border-bottom: 1px solid var(--border);
  margin-bottom: 12px;
}
.sidebar-logo span { color: var(--accent); }
.sidebar-nav a {
  display: flex; align-items: center; gap: 10px;
  padding: 10px 20px;
  color: var(--text2);
  text-decoration: none;
  font-size: 13.5px;
  transition: all .15s;
  border-left: 3px solid transparent;
}
.sidebar-nav a:hover { color: var(--text); background: var(--bg3); }
.sidebar-nav a.active { color: var(--accent); border-left-color: var(--accent); background: rgba(124,106,245,.08); }

/* ── MAIN ── */
.main {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}
.topbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 18px 32px;
  border-bottom: 1px solid var(--border);
  background: var(--bg2);
}
.topbar-title { font-size: 17px; font-weight: 600; }
.topbar-sub { font-size: 12px; color: var(--text2); margin-top: 2px; }
.badge-evo { display: flex; align-items: center; gap: 6px; font-size: 12px; color: var(--text2); }
.dot-green { width: 7px; height: 7px; border-radius: 50%; background: var(--green); box-shadow: 0 0 6px var(--green); }

/* ── TABS ── */
.tabs-bar {
  display: flex;
  gap: 2px;
  padding: 0 32px;
  background: var(--bg2);
  border-bottom: 1px solid var(--border);
}
.tab-btn {
  padding: 12px 20px;
  font-size: 13px;
  font-weight: 500;
  color: var(--text2);
  background: none;
  border: none;
  border-bottom: 2px solid transparent;
  cursor: pointer;
  transition: all .15s;
}
.tab-btn:hover { color: var(--text); }
.tab-btn.active { color: var(--accent); border-bottom-color: var(--accent); }

/* ── CONTENT ── */
.content { padding: 28px 32px; flex: 1; overflow-y: auto; }
.tab-panel { display: none; }
.tab-panel.active { display: block; }

/* ── KPI STRIP ── */
.kpi-strip {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 14px;
  margin-bottom: 28px;
}
.kpi-card {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 18px 20px;
  position: relative;
  overflow: hidden;
}
.kpi-card::before {
  content: '';
  position: absolute;
  top: 0; left: 0; right: 0;
  height: 2px;
  background: var(--color, var(--accent));
}
.kpi-label { font-size: 11px; color: var(--text2); text-transform: uppercase; letter-spacing: .5px; margin-bottom: 8px; }
.kpi-value { font-size: 28px; font-weight: 700; font-family: 'JetBrains Mono', monospace; line-height: 1; }
.kpi-sub { font-size: 11px; color: var(--text3); margin-top: 4px; }

/* ── FUNIL ── */
.funil {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 20px;
  margin-bottom: 28px;
}
.funil-title { font-size: 13px; font-weight: 600; color: var(--text2); margin-bottom: 16px; text-transform: uppercase; letter-spacing: .5px; }
.funil-row {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 10px;
}
.funil-label { font-size: 12.5px; color: var(--text2); width: 150px; flex-shrink: 0; }
.funil-bar-wrap { flex: 1; height: 8px; background: var(--bg3); border-radius: 4px; overflow: hidden; }
.funil-bar { height: 100%; border-radius: 4px; transition: width .6s ease; }
.funil-count { font-size: 12px; font-family: 'JetBrains Mono'; color: var(--text); width: 40px; text-align: right; }

/* ── LOTE ATIVO BANNER ── */
.lote-banner {
  background: linear-gradient(135deg, rgba(124,106,245,.12), rgba(124,106,245,.04));
  border: 1px solid rgba(124,106,245,.3);
  border-radius: var(--radius);
  padding: 18px 24px;
  margin-bottom: 28px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
}
.lote-banner-info { flex: 1; }
.lote-banner-title { font-size: 14px; font-weight: 600; color: var(--accent); margin-bottom: 4px; }
.lote-banner-sub { font-size: 12px; color: var(--text2); }
.progress-bar-wrap { flex: 1; height: 6px; background: var(--bg3); border-radius: 3px; overflow: hidden; }
.progress-bar { height: 100%; border-radius: 3px; background: var(--accent); transition: width .3s; }

/* ── COOLDOWN ── */
.cooldown-banner {
  background: rgba(245,200,66,.06);
  border: 1px solid rgba(245,200,66,.2);
  border-radius: var(--radius);
  padding: 14px 20px;
  font-size: 13px;
  color: var(--yellow);
  margin-bottom: 20px;
  display: flex;
  align-items: center;
  gap: 10px;
}

/* ── CARDS / TABLES ── */
.section-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 16px;
}
.section-title { font-size: 15px; font-weight: 600; }
.section-sub { font-size: 12px; color: var(--text2); margin-top: 2px; }

.filters-row {
  display: flex;
  gap: 10px;
  flex-wrap: wrap;
  align-items: flex-end;
  margin-bottom: 18px;
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 16px 18px;
}
.filter-group { display: flex; flex-direction: column; gap: 5px; }
.filter-label { font-size: 11px; color: var(--text2); text-transform: uppercase; letter-spacing: .5px; }
.filter-select, .filter-input {
  background: var(--bg3);
  border: 1px solid var(--border2);
  border-radius: var(--radius-sm);
  color: var(--text);
  font-size: 13px;
  padding: 7px 10px;
  font-family: 'Inter', sans-serif;
  min-width: 120px;
}
.filter-select:focus, .filter-input:focus { outline: none; border-color: var(--accent); }

/* Tabela de clientes elegíveis */
.clients-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.clients-table th {
  text-align: left;
  font-size: 11px;
  color: var(--text3);
  text-transform: uppercase;
  letter-spacing: .5px;
  padding: 8px 12px;
  border-bottom: 1px solid var(--border);
  font-weight: 500;
}
.clients-table td {
  padding: 10px 12px;
  border-bottom: 1px solid var(--border);
  vertical-align: middle;
}
.clients-table tr:last-child td { border-bottom: none; }
.clients-table tr:hover td { background: rgba(255,255,255,.025); }
.clients-table input[type="checkbox"] { accent-color: var(--accent); width: 15px; height: 15px; }

.ctx-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 10.5px;
  padding: 2px 8px;
  border-radius: 20px;
  font-weight: 500;
}
.ctx-pdv       { background: rgba(52,209,122,.12); color: var(--green); }
.ctx-barbearia { background: rgba(124,106,245,.12); color: var(--accent); }
.ctx-whatsapp  { background: rgba(245,200,66,.12); color: var(--yellow); }

.dias-badge {
  font-family: 'JetBrains Mono';
  font-size: 12px;
  padding: 2px 8px;
  border-radius: 4px;
}
.dias-hot  { background: rgba(245,82,82,.12); color: var(--red); }
.dias-warm { background: rgba(245,155,66,.12); color: var(--orange); }
.dias-cold { background: rgba(144,144,160,.1); color: var(--text2); }

/* Preview msg */
.msg-preview {
  font-size: 11.5px;
  color: var(--text2);
  max-width: 260px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-style: italic;
}

/* ── BOTÕES ── */
.btn {
  display: inline-flex; align-items: center; gap: 7px;
  padding: 9px 18px;
  border-radius: var(--radius-sm);
  font-size: 13px; font-weight: 500;
  font-family: 'Inter', sans-serif;
  cursor: pointer;
  border: none;
  transition: all .15s;
}
.btn-primary { background: var(--accent); color: #fff; }
.btn-primary:hover { background: var(--accent2); }
.btn-primary:disabled { opacity: .4; cursor: not-allowed; }
.btn-danger  { background: rgba(245,82,82,.12); color: var(--red); border: 1px solid rgba(245,82,82,.25); }
.btn-danger:hover  { background: rgba(245,82,82,.2); }
.btn-ghost   { background: var(--bg3); color: var(--text2); border: 1px solid var(--border2); }
.btn-ghost:hover   { color: var(--text); border-color: var(--border2); background: var(--bg2); }
.btn-green   { background: rgba(52,209,122,.12); color: var(--green); border: 1px solid rgba(52,209,122,.25); }
.btn-green:hover   { background: rgba(52,209,122,.2); }
.btn-sm { padding: 6px 12px; font-size: 12px; }

/* ── ENVIO EM PROGRESSO ── */
.send-console {
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 0;
  font-family: 'JetBrains Mono', monospace;
  font-size: 12.5px;
  overflow: hidden;
}
.send-console-header {
  background: var(--bg2);
  border-bottom: 1px solid var(--border);
  padding: 10px 16px;
  display: flex;
  align-items: center;
  gap: 8px;
  color: var(--text2);
  font-size: 12px;
}
.dot-run { width: 8px; height: 8px; border-radius: 50%; animation: pulse 1.2s infinite; }
.dot-run.green { background: var(--green); }
.dot-run.gray  { background: var(--text3); animation: none; }
@keyframes pulse { 0%,100%{opacity:1} 50%{opacity:.3} }
.send-console-body {
  padding: 14px 16px;
  max-height: 280px;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.log-line {
  display: flex; align-items: flex-start; gap: 8px;
  padding: 4px 0;
  border-bottom: 1px solid rgba(255,255,255,.03);
}
.log-time { color: var(--text3); flex-shrink: 0; }
.log-ok   { color: var(--green); flex-shrink: 0; }
.log-err  { color: var(--red); flex-shrink: 0; }
.log-info { color: var(--text2); flex-shrink: 0; }
.log-text { color: var(--text2); flex: 1; }
.log-name { color: var(--text); font-weight: 500; }

/* ── LOTES HISTÓRICO ── */
.lote-row {
  display: grid;
  grid-template-columns: 60px 1fr auto auto auto 120px;
  align-items: center;
  gap: 12px;
  padding: 13px 16px;
  border-bottom: 1px solid var(--border);
  font-size: 13px;
  transition: background .1s;
}
.lote-row:hover { background: rgba(255,255,255,.02); }
.lote-row:last-child { border-bottom: none; }
.lote-status-badge {
  display: inline-flex; align-items: center; gap: 5px;
  font-size: 11px; padding: 3px 9px;
  border-radius: 20px; font-weight: 500;
}
.status-aguardando { background: rgba(245,200,66,.1); color: var(--yellow); }
.status-andamento  { background: rgba(124,106,245,.1); color: var(--accent); }
.status-concluido  { background: rgba(52,209,122,.1);  color: var(--green); }
.status-cancelado  { background: rgba(144,144,160,.1); color: var(--text2); }

/* ── SEGMENTOS ── */
.segment-tabs {
  display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px;
}
.seg-btn {
  padding: 6px 14px;
  border-radius: 20px;
  font-size: 12px; font-weight: 500;
  border: 1px solid var(--border2);
  background: var(--bg2);
  color: var(--text2);
  cursor: pointer;
  transition: all .15s;
}
.seg-btn:hover { color: var(--text); }
.seg-btn.active { background: rgba(124,106,245,.12); border-color: var(--accent); color: var(--accent); }

/* ── MODAL ── */
.modal-overlay {
  position: fixed; inset: 0;
  background: rgba(0,0,0,.7);
  display: flex; align-items: center; justify-content: center;
  z-index: 1000;
  backdrop-filter: blur(4px);
  opacity: 0; pointer-events: none;
  transition: opacity .2s;
}
.modal-overlay.open { opacity: 1; pointer-events: all; }
.modal {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 14px;
  width: min(560px, 95vw);
  max-height: 85vh;
  overflow-y: auto;
  box-shadow: var(--shadow);
  transform: translateY(12px);
  transition: transform .2s;
}
.modal-overlay.open .modal { transform: translateY(0); }
.modal-header {
  padding: 20px 24px 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.modal-title { font-size: 16px; font-weight: 600; }
.modal-close {
  width: 28px; height: 28px; border-radius: 6px;
  background: var(--bg3); border: none; color: var(--text2);
  cursor: pointer; font-size: 16px;
  display: flex; align-items: center; justify-content: center;
}
.modal-body { padding: 20px 24px 24px; }
.msg-preview-card {
  background: var(--bg3);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  padding: 14px 16px;
  font-size: 13px;
  line-height: 1.6;
  color: var(--text2);
  white-space: pre-line;
  margin-bottom: 16px;
  font-style: italic;
  border-left: 3px solid var(--accent);
}
.lote-config-row {
  display: flex; align-items: center; gap: 12px;
  padding: 12px 0;
  border-bottom: 1px solid var(--border);
}
.lote-config-row:last-child { border-bottom: none; }
.lote-config-label { font-size: 13px; color: var(--text2); flex: 1; }
.lote-config-value { font-size: 13px; font-weight: 500; font-family: 'JetBrains Mono'; }

/* ── SLIDER DELAY ── */
input[type="range"] {
  accent-color: var(--accent);
  width: 120px;
}

/* ── TABLE WRAPPER ── */
.table-wrap {
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: var(--radius);
  overflow: hidden;
}

/* ── EMPTY STATE ── */
.empty-state {
  text-align: center;
  padding: 48px 24px;
  color: var(--text2);
}
.empty-state .icon { font-size: 40px; margin-bottom: 12px; }
.empty-state p { font-size: 14px; }

/* ── SELEÇÃO ── */
.selection-bar {
  background: rgba(124,106,245,.1);
  border: 1px solid rgba(124,106,245,.25);
  border-radius: var(--radius-sm);
  padding: 10px 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
  font-size: 13px;
  color: var(--accent);
}

/* scrollbar */
::-webkit-scrollbar { width: 5px; height: 5px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb { background: var(--border2); border-radius: 3px; }
</style>
</head>
<body>

<!-- SIDEBAR -->
<nav class="sidebar">
  <div class="sidebar-logo">For<span>men</span> CRM</div>
  <div class="sidebar-nav">
    <a href="/index.php">🏠 Dashboard</a>
    <a href="/clients.php">👥 Clientes</a>
    <a href="/calendar_barbearia.php">✂️ Barbearia</a>
    <a href="/reativacao.php" class="active">🔁 Reativação</a>
  </div>
</nav>

<!-- MAIN -->
<div class="main">

  <!-- TOPBAR -->
  <div class="topbar">
    <div>
      <div class="topbar-title">🔁 Reativação de Clientes</div>
      <div class="topbar-sub">Reconecte clientes inativos com cadência e controle</div>
    </div>
    <div class="badge-evo">
      <div class="dot-green"></div>
      Evolution API conectada
    </div>
  </div>

  <!-- TABS -->
  <div class="tabs-bar">
    <button class="tab-btn active" data-tab="dashboard">📊 Dashboard</button>
    <button class="tab-btn" data-tab="criar">📤 Criar Lote</button>
    <button class="tab-btn" data-tab="lotes">📋 Histórico</button>
    <button class="tab-btn" data-tab="segmentos">🗂️ Segmentos</button>
  </div>

  <!-- CONTENT -->
  <div class="content">

    <!-- ══════════════ TAB: DASHBOARD ══════════════ -->
    <div id="tab-dashboard" class="tab-panel active">

      <div id="cooldown-banner" class="cooldown-banner" style="display:none">
        ⏱️ <span id="cooldown-msg"></span>
      </div>

      <div id="lote-ativo-banner" class="lote-banner" style="display:none">
        <div class="lote-banner-info">
          <div class="lote-banner-title">Lote em andamento</div>
          <div class="lote-banner-sub" id="lote-ativo-sub">carregando...</div>
        </div>
        <div style="flex:1; max-width:200px">
          <div class="progress-bar-wrap">
            <div class="progress-bar" id="lote-ativo-progress" style="width:0%"></div>
          </div>
          <div style="font-size:11px;color:var(--text2);margin-top:5px;text-align:right" id="lote-ativo-counts"></div>
        </div>
        <a href="#" onclick="switchTab('lotes'); return false" class="btn btn-ghost btn-sm">Ver lote</a>
      </div>

      <!-- KPIs -->
      <div class="kpi-strip" id="kpi-strip">
        <div class="kpi-card" style="--color:var(--text3)">
          <div class="kpi-label">Base total</div>
          <div class="kpi-value" id="kpi-total">—</div>
          <div class="kpi-sub">c/ WhatsApp cadastrado</div>
        </div>
        <div class="kpi-card" style="--color:var(--accent)">
          <div class="kpi-label">Elegíveis</div>
          <div class="kpi-value" id="kpi-elegiveis">—</div>
          <div class="kpi-sub">prontos para contato</div>
        </div>
        <div class="kpi-card" style="--color:var(--green)">
          <div class="kpi-label">Responderam</div>
          <div class="kpi-value" id="kpi-responderam">—</div>
          <div class="kpi-sub">1ª ou 2ª tentativa</div>
        </div>
        <div class="kpi-card" style="--color:var(--yellow)">
          <div class="kpi-label">Aguardando</div>
          <div class="kpi-value" id="kpi-aguardando">—</div>
          <div class="kpi-sub">em espera para 2ª tentativa</div>
        </div>
        <div class="kpi-card" style="--color:var(--red)">
          <div class="kpi-label">Standby</div>
          <div class="kpi-value" id="kpi-standby">—</div>
          <div class="kpi-sub">não pertuba mais</div>
        </div>
      </div>

      <!-- Funil -->
      <div class="funil">
        <div class="funil-title">Funil de Reativação</div>
        <div id="funil-body">
          <div style="color:var(--text3);font-size:13px">Carregando...</div>
        </div>
      </div>

      <!-- Ações rápidas -->
      <div style="display:flex;gap:12px;flex-wrap:wrap">
        <button class="btn btn-primary" onclick="switchTab('criar')">
          ＋ Criar novo lote
        </button>
        <button class="btn btn-ghost" onclick="switchTab('segmentos')">
          🗂️ Ver segmentos
        </button>
        <button class="btn btn-ghost btn-sm" onclick="loadDashboard()" style="margin-left:auto">
          ↻ Atualizar
        </button>
      </div>
    </div>

    <!-- ══════════════ TAB: CRIAR LOTE ══════════════ -->
    <div id="tab-criar" class="tab-panel">

      <div class="section-header">
        <div>
          <div class="section-title">Selecionar clientes para o lote</div>
          <div class="section-sub">Filtre, revise as mensagens e crie o lote para envio</div>
        </div>
      </div>

      <!-- Filtros -->
      <div class="filters-row">
        <div class="filter-group">
          <div class="filter-label">Dias sem visita</div>
          <select class="filter-select" id="f-dias">
            <option value="30">Mais de 30 dias</option>
            <option value="60" selected>Mais de 60 dias</option>
            <option value="90">Mais de 90 dias</option>
            <option value="180">Mais de 180 dias</option>
          </select>
        </div>
        <div class="filter-group">
          <div class="filter-label">Contexto</div>
          <select class="filter-select" id="f-contexto">
            <option value="todos">Todos</option>
            <option value="pdv">Loja física (PDV)</option>
            <option value="barbearia">Barbearia</option>
            <option value="whatsapp">Só WhatsApp</option>
          </select>
        </div>
        <div class="filter-group">
          <div class="filter-label">Tentativa</div>
          <select class="filter-select" id="f-tentativa">
            <option value="1">1ª mensagem</option>
            <option value="2">2ª mensagem (aguardando)</option>
          </select>
        </div>
        <div class="filter-group">
          <div class="filter-label">Tamanho do lote</div>
          <select class="filter-select" id="f-limite">
            <option value="15">15 clientes</option>
            <option value="20" selected>20 clientes</option>
            <option value="30">30 clientes</option>
          </select>
        </div>
        <div class="filter-group" style="justify-content:flex-end;padding-top:16px">
          <button class="btn btn-ghost" onclick="loadEligible()">🔍 Buscar</button>
        </div>
      </div>

      <!-- Seleção -->
      <div id="selection-bar" class="selection-bar" style="display:none">
        <span><strong id="sel-count">0</strong> clientes selecionados</span>
        <div style="display:flex;gap:8px">
          <button class="btn btn-ghost btn-sm" onclick="selectAll(true)">Selecionar todos</button>
          <button class="btn btn-ghost btn-sm" onclick="selectAll(false)">Limpar</button>
          <button class="btn btn-primary btn-sm" onclick="openCreateModal()">Criar lote →</button>
        </div>
      </div>

      <!-- Tabela de elegíveis -->
      <div class="table-wrap">
        <div id="eligible-loading" style="padding:40px;text-align:center;color:var(--text2)">
          Clique em <strong>Buscar</strong> para carregar os clientes elegíveis
        </div>
        <table class="clients-table" id="eligible-table" style="display:none">
          <thead>
            <tr>
              <th style="width:36px"><input type="checkbox" id="check-all" onchange="toggleAll(this)"></th>
              <th>Nome</th>
              <th>WhatsApp</th>
              <th>Contexto</th>
              <th>Sem visita</th>
              <th>Mensagem (prévia)</th>
            </tr>
          </thead>
          <tbody id="eligible-tbody"></tbody>
        </table>
      </div>
    </div>

    <!-- ══════════════ TAB: HISTÓRICO ══════════════ -->
    <div id="tab-lotes" class="tab-panel">

      <div class="section-header">
        <div>
          <div class="section-title">Histórico de Lotes</div>
          <div class="section-sub">Todos os lotes de reativação enviados</div>
        </div>
        <button class="btn btn-ghost btn-sm" onclick="loadLotes()">↻ Atualizar</button>
      </div>

      <!-- Envio progressivo (aparece quando há lote ativo) -->
      <div id="send-panel" style="display:none;margin-bottom:24px">
        <div class="section-header" style="margin-bottom:12px">
          <div class="section-title">📡 Envio em andamento</div>
          <div style="display:flex;gap:8px">
            <div class="filter-group">
              <div class="filter-label">Intervalo entre msgs</div>
              <div style="display:flex;align-items:center;gap:8px">
                <input type="range" id="delay-range" min="30" max="180" value="60" oninput="document.getElementById('delay-val').textContent=this.value+'s'">
                <span id="delay-val" style="font-size:12px;color:var(--accent);font-family:'JetBrains Mono';width:35px">60s</span>
              </div>
            </div>
            <button class="btn btn-green btn-sm" id="btn-start-send" onclick="startSending()">▶ Iniciar envio</button>
            <button class="btn btn-danger btn-sm" id="btn-stop-send" onclick="stopSending()" style="display:none">⏸ Pausar</button>
            <button class="btn btn-danger btn-sm" id="btn-cancel-lote" onclick="cancelLote()">✕ Cancelar lote</button>
          </div>
        </div>

        <div class="send-console">
          <div class="send-console-header">
            <div class="dot-run gray" id="console-dot"></div>
            <span id="console-status">Aguardando início</span>
            <span style="margin-left:auto;font-size:11px" id="console-progress"></span>
          </div>
          <div class="send-console-body" id="console-log">
            <div class="log-line">
              <span class="log-info">›</span>
              <span class="log-text">Console de envio inicializado. Configure o intervalo e clique em Iniciar.</span>
            </div>
          </div>
        </div>
      </div>

      <!-- Lista de lotes -->
      <div class="table-wrap" id="lotes-wrap">
        <div style="padding:40px;text-align:center;color:var(--text2)">Carregando lotes...</div>
      </div>
    </div>

    <!-- ══════════════ TAB: SEGMENTOS ══════════════ -->
    <div id="tab-segmentos" class="tab-panel">

      <div class="section-header">
        <div>
          <div class="section-title">Segmentos de Reativação</div>
          <div class="section-sub">Gerencie clientes por estágio no funil</div>
        </div>
      </div>

      <div class="segment-tabs">
        <button class="seg-btn active" data-seg="respondeu_1" onclick="loadSegment('respondeu_1',this)">✅ Responderam (1ª)</button>
        <button class="seg-btn" data-seg="respondeu_2" onclick="loadSegment('respondeu_2',this)">✅ Responderam (2ª)</button>
        <button class="seg-btn" data-seg="aguardando_2" onclick="loadSegment('aguardando_2',this)">⏳ Aguardando 2ª</button>
        <button class="seg-btn" data-seg="sem_resposta" onclick="loadSegment('sem_resposta',this)">⚠️ Sem resposta</button>
        <button class="seg-btn" data-seg="standby" onclick="loadSegment('standby',this)">🔕 Standby</button>
        <button class="seg-btn" data-seg="numero_invalido" onclick="loadSegment('numero_invalido',this)">❌ Nº inválido</button>
      </div>

      <div id="seg-selection-bar" class="selection-bar" style="display:none">
        <span><strong id="seg-sel-count">0</strong> selecionados</span>
        <div style="display:flex;gap:8px;align-items:center">
          <span style="font-size:12px;color:var(--text2)">Mover para:</span>
          <select class="filter-select" id="seg-move-to" style="min-width:150px">
            <option value="elegivel">Elegível</option>
            <option value="aguardando_2">Aguardando 2ª</option>
            <option value="standby">Standby</option>
            <option value="numero_invalido">Nº inválido</option>
          </select>
          <button class="btn btn-primary btn-sm" onclick="moveSelected()">Mover</button>
          <button class="btn btn-ghost btn-sm" onclick="selectSegAll(false)">Limpar</button>
        </div>
      </div>

      <div class="table-wrap" id="seg-table-wrap">
        <div style="padding:40px;text-align:center;color:var(--text2)">Selecione um segmento acima</div>
      </div>
    </div>

  </div><!-- /content -->
</div><!-- /main -->

<!-- MODAL: Confirmar criação do lote -->
<div class="modal-overlay" id="modal-overlay" onclick="if(event.target===this)closeModal()">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Confirmar lote</div>
      <button class="modal-close" onclick="closeModal()">✕</button>
    </div>
    <div class="modal-body">
      <div id="modal-summary" style="font-size:13px;color:var(--text2);margin-bottom:16px"></div>

      <div style="font-size:12px;color:var(--text2);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px">Prévia da mensagem (1º cliente)</div>
      <div class="msg-preview-card" id="modal-msg-preview"></div>

      <div style="font-size:12px;color:var(--text2);margin-bottom:10px;text-transform:uppercase;letter-spacing:.5px">Configuração</div>
      <div style="background:var(--bg3);border-radius:var(--radius);padding:12px 16px;margin-bottom:20px">
        <div class="lote-config-row">
          <span class="lote-config-label">Clientes selecionados</span>
          <span class="lote-config-value" id="cfg-total">—</span>
        </div>
        <div class="lote-config-row">
          <span class="lote-config-label">Tentativa</span>
          <span class="lote-config-value" id="cfg-tentativa">—</span>
        </div>
        <div class="lote-config-row">
          <span class="lote-config-label">Intervalo (defina no painel de envio)</span>
          <span class="lote-config-value">45–180s randomizado</span>
        </div>
        <div class="lote-config-row" style="border:none">
          <span class="lote-config-label">Horário permitido</span>
          <span class="lote-config-value">09:00 – 20:00</span>
        </div>
      </div>

      <div style="font-size:12px;color:var(--text2);margin-bottom:6px">Observação (opcional)</div>
      <textarea id="lote-obs" rows="2" placeholder="Ex: Campanha março, clientes barbearia..." style="width:100%;background:var(--bg3);border:1px solid var(--border2);border-radius:var(--radius-sm);color:var(--text);font-size:13px;padding:9px 12px;font-family:Inter;resize:none;margin-bottom:16px"></textarea>

      <div style="display:flex;gap:10px;justify-content:flex-end">
        <button class="btn btn-ghost" onclick="closeModal()">Cancelar</button>
        <button class="btn btn-primary" id="btn-confirm-lote" onclick="confirmCreateLote()">Criar lote</button>
      </div>
    </div>
  </div>
</div>

<script>
/* ═══════════════════════════════════════
   ESTADO GLOBAL
═══════════════════════════════════════ */
let state = {
  eligibleClients: [],
  selectedIds: new Set(),
  activeLoteId: null,
  sending: false,
  sendTimer: null,
  currentSegment: 'respondeu_1',
  segSelected: new Set(),
};

const API = 'reativacao_api.php';

/* ═══════════════════════════════════════
   TABS
═══════════════════════════════════════ */
document.querySelectorAll('.tab-btn').forEach(btn => {
  btn.addEventListener('click', () => switchTab(btn.dataset.tab));
});
function switchTab(tab) {
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
  document.querySelectorAll('.tab-panel').forEach(p => p.classList.toggle('active', p.id === 'tab-' + tab));
  if (tab === 'lotes')     loadLotes();
  if (tab === 'segmentos') loadSegment(state.currentSegment);
}

/* ═══════════════════════════════════════
   SETUP + DASHBOARD
═══════════════════════════════════════ */
async function setup() {
  await fetch(`${API}?action=setup_tables`);
  loadDashboard();
}

async function loadDashboard() {
  const data = await fetch(`${API}?action=get_stats`).then(r=>r.json()).catch(()=>({}));

  const s = (id, val) => { const el = document.getElementById(id); if(el) el.textContent = val ?? '—'; };
  s('kpi-total',       data.total_base);
  s('kpi-elegiveis',   data.elegiveis);
  s('kpi-responderam', data.responderam);
  s('kpi-aguardando',  (data.aguardando_2 || 0) + (data.lote_1_enviado || 0) + (data.lote_2_enviado || 0));
  s('kpi-standby',     data.standby);

  // Cooldown
  const cb = document.getElementById('cooldown-banner');
  if (data.pode_enviar_em) {
    const dt = new Date(data.pode_enviar_em.replace(' ','T') + '-04:00');
    cb.style.display = 'flex';
    document.getElementById('cooldown-msg').textContent =
      `Próximo lote disponível em: ${dt.toLocaleString('pt-BR', {timeZone:'America/Cuiaba'})}`;
  } else { cb.style.display = 'none'; }

  // Lote ativo banner
  const lab = document.getElementById('lote-ativo-banner');
  if (data.lote_ativo) {
    const l = data.lote_ativo;
    state.activeLoteId = l.id;
    lab.style.display = 'flex';
    document.getElementById('lote-ativo-sub').textContent =
      `${l.enviados}/${l.total_clientes} enviados · ${l.erros} erros`;
    const pct = l.total_clientes > 0 ? (l.enviados / l.total_clientes * 100) : 0;
    document.getElementById('lote-ativo-progress').style.width = pct + '%';
    document.getElementById('lote-ativo-counts').textContent = `${pct.toFixed(0)}%`;
  } else {
    lab.style.display = 'none';
    state.activeLoteId = null;
  }

  // Funil
  const total = data.total_base || 1;
  const funilRows = [
    { label: 'Elegíveis',       val: data.elegiveis    || 0, color: 'var(--accent)' },
    { label: '1ª msg enviada',  val: (data.lote_1_enviado||0), color: 'var(--yellow)' },
    { label: 'Aguardando 2ª',  val: data.aguardando_2  || 0, color: 'var(--orange)' },
    { label: '2ª msg enviada',  val: (data.lote_2_enviado||0), color: 'var(--yellow)' },
    { label: 'Responderam ✅', val: data.responderam   || 0, color: 'var(--green)' },
    { label: 'Sem resposta',    val: data.sem_resposta  || 0, color: 'var(--red)' },
    { label: 'Standby',         val: data.standby       || 0, color: 'var(--text3)' },
  ];
  document.getElementById('funil-body').innerHTML = funilRows.map(r => `
    <div class="funil-row">
      <div class="funil-label">${r.label}</div>
      <div class="funil-bar-wrap">
        <div class="funil-bar" style="width:${(r.val/total*100).toFixed(1)}%;background:${r.color}"></div>
      </div>
      <div class="funil-count">${r.val}</div>
    </div>
  `).join('');
}

/* ═══════════════════════════════════════
   CRIAR LOTE — buscar elegíveis
═══════════════════════════════════════ */
async function loadEligible() {
  const dias      = document.getElementById('f-dias').value;
  const contexto  = document.getElementById('f-contexto').value;
  const tentativa = document.getElementById('f-tentativa').value;
  const limite    = document.getElementById('f-limite').value;

  document.getElementById('eligible-loading').textContent = 'Buscando...';
  document.getElementById('eligible-table').style.display = 'none';
  document.getElementById('eligible-loading').style.display = 'block';

  const data = await fetch(`${API}?action=get_eligible&dias=${dias}&contexto=${contexto}&tentativa=${tentativa}&limite=${limite}`)
    .then(r=>r.json()).catch(()=>({ok:false}));

  if (!data.ok) {
    document.getElementById('eligible-loading').textContent = 'Erro ao buscar clientes.';
    return;
  }

  state.eligibleClients = data.clients || [];
  state.selectedIds     = new Set(state.eligibleClients.map(c => c.id));
  renderEligibleTable();
}

function renderEligibleTable() {
  const tbody = document.getElementById('eligible-tbody');
  if (!state.eligibleClients.length) {
    document.getElementById('eligible-loading').textContent = 'Nenhum cliente elegível com os filtros selecionados.';
    document.getElementById('eligible-table').style.display = 'none';
    document.getElementById('eligible-loading').style.display = 'block';
    return;
  }

  document.getElementById('eligible-loading').style.display = 'none';
  document.getElementById('eligible-table').style.display = 'table';

  tbody.innerHTML = state.eligibleClients.map(c => {
    const ctx = c.contexto_detectado || 'whatsapp';
    const ctxLabel = {pdv:'PDV',barbearia:'Barbearia',whatsapp:'WhatsApp'}[ctx] || ctx;
    const diasCls = c.dias_ausente >= 180 ? 'dias-hot' : c.dias_ausente >= 90 ? 'dias-warm' : 'dias-cold';
    const wa = (c.whatsapp||'').substring(0,4) + '****' + (c.whatsapp||'').slice(-4);
    const msgPrev = (c.msg_preview||'').replace(/\n/g,' ');
    return `<tr>
      <td><input type="checkbox" class="row-check" data-id="${c.id}" ${state.selectedIds.has(parseInt(c.id))?'checked':''} onchange="toggleRow(${c.id},this)"></td>
      <td style="font-weight:500">${esc(c.nome)}</td>
      <td style="font-family:'JetBrains Mono';font-size:12px;color:var(--text2)">${wa}</td>
      <td><span class="ctx-badge ctx-${ctx}">${ctxLabel}</span></td>
      <td><span class="dias-badge ${diasCls}">${c.dias_ausente >= 999 ? 'nunca' : c.dias_ausente+'d'}</span></td>
      <td><div class="msg-preview" title="${esc(c.msg_preview)}">${esc(msgPrev)}</div></td>
    </tr>`;
  }).join('');

  updateSelectionBar();
}

function toggleRow(id, cb) {
  if (cb.checked) state.selectedIds.add(parseInt(id));
  else state.selectedIds.delete(parseInt(id));
  updateSelectionBar();
}
function toggleAll(cb) {
  state.eligibleClients.forEach(c => {
    if (cb.checked) state.selectedIds.add(parseInt(c.id));
    else state.selectedIds.delete(parseInt(c.id));
  });
  document.querySelectorAll('.row-check').forEach(c => c.checked = cb.checked);
  updateSelectionBar();
}
function selectAll(val) {
  document.getElementById('check-all').checked = val;
  toggleAll({checked:val});
}
function updateSelectionBar() {
  const bar = document.getElementById('selection-bar');
  document.getElementById('sel-count').textContent = state.selectedIds.size;
  bar.style.display = state.selectedIds.size > 0 ? 'flex' : 'none';
}

/* ═══════════════════════════════════════
   MODAL CRIAR LOTE
═══════════════════════════════════════ */
function openCreateModal() {
  if (!state.selectedIds.size) return;
  const tentativa = parseInt(document.getElementById('f-tentativa').value);
  const firstClient = state.eligibleClients.find(c => state.selectedIds.has(parseInt(c.id)));

  document.getElementById('cfg-total').textContent = state.selectedIds.size + ' clientes';
  document.getElementById('cfg-tentativa').textContent = tentativa === 1 ? '1ª mensagem' : '2ª mensagem';
  document.getElementById('modal-summary').textContent =
    `Você está criando um lote com ${state.selectedIds.size} clientes para receber a ${tentativa}ª mensagem de reativação.`;
  document.getElementById('modal-msg-preview').textContent = firstClient ? firstClient.msg_preview : '';
  document.getElementById('modal-overlay').classList.add('open');
}
function closeModal() {
  document.getElementById('modal-overlay').classList.remove('open');
}

async function confirmCreateLote() {
  const btn = document.getElementById('btn-confirm-lote');
  btn.disabled = true;
  btn.textContent = 'Criando...';

  const tentativa = parseInt(document.getElementById('f-tentativa').value);
  const obs = document.getElementById('lote-obs').value;

  const body = {
    client_ids: [...state.selectedIds],
    tentativa,
    observacoes: obs,
    contexto: document.getElementById('f-contexto').value,
  };

  const data = await fetch(`${API}?action=create_lote`, {
    method: 'POST',
    headers: {'Content-Type':'application/json'},
    body: JSON.stringify(body),
  }).then(r=>r.json()).catch(()=>({ok:false,error:'Erro de rede'}));

  btn.disabled = false;
  btn.textContent = 'Criar lote';
  closeModal();

  if (data.ok) {
    state.activeLoteId = data.lote_id;
    alert(`✅ Lote criado com ${data.total} clientes!\n\nVá para a aba Histórico para iniciar o envio.`);
    switchTab('lotes');
  } else {
    alert('Erro: ' + (data.error || 'Falha ao criar lote'));
  }
}

/* ═══════════════════════════════════════
   HISTÓRICO DE LOTES
═══════════════════════════════════════ */
async function loadLotes() {
  const data = await fetch(`${API}?action=get_lotes`).then(r=>r.json()).catch(()=>({ok:false,lotes:[]}));
  const wrap = document.getElementById('lotes-wrap');

  // Verifica lote ativo para mostrar painel de envio
  const loteAtivo = (data.lotes||[]).find(l => l.status === 'aguardando' || l.status === 'em_andamento');
  const sendPanel = document.getElementById('send-panel');
  if (loteAtivo) {
    state.activeLoteId = loteAtivo.id;
    sendPanel.style.display = 'block';
    document.getElementById('btn-cancel-lote').dataset.id = loteAtivo.id;
  } else {
    sendPanel.style.display = 'none';
  }

  if (!data.lotes?.length) {
    wrap.innerHTML = '<div class="empty-state"><div class="icon">📭</div><p>Nenhum lote ainda. Crie um na aba "Criar Lote".</p></div>';
    return;
  }

  wrap.innerHTML = data.lotes.map(l => {
    const pct = l.total_clientes > 0 ? Math.round(l.enviados/l.total_clientes*100) : 0;
    const dataFmt = new Date(l.criado_em.replace(' ','T')+'Z').toLocaleString('pt-BR',{timeZone:'America/Cuiaba',day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'});
    return `<div class="lote-row">
      <div style="font-size:12px;font-family:'JetBrains Mono';color:var(--text3)">#${l.id}</div>
      <div>
        <div style="font-size:13px;font-weight:500">${esc(l.observacoes||'Lote #'+l.id)}</div>
        <div style="font-size:11.5px;color:var(--text2);margin-top:2px">${dataFmt} · ${l.total_clientes} clientes · ${l.erros} erros · ${l.responderam||0} respostas</div>
      </div>
      <div>${pct}%</div>
      <div>
        <div style="width:80px;height:4px;background:var(--bg3);border-radius:2px;overflow:hidden">
          <div style="height:100%;background:var(--accent);width:${pct}%;transition:width .3s"></div>
        </div>
      </div>
      <div><span class="lote-status-badge status-${l.status}">${l.status}</span></div>
      <div style="text-align:right">
        ${(l.status==='aguardando'||l.status==='em_andamento') ?
          `<button class="btn btn-ghost btn-sm" onclick="loadLoteDetail(${l.id})">Detalhes</button>` :
          `<button class="btn btn-ghost btn-sm" onclick="loadLoteDetail(${l.id})">Ver</button>`}
      </div>
    </div>`;
  }).join('');
}

async function loadLoteDetail(loteId) {
  const data = await fetch(`${API}?action=get_lote&id=${loteId}`).then(r=>r.json()).catch(()=>({ok:false}));
  if (!data.ok) return alert('Erro ao carregar detalhes.');
  // mostra no console
  const log = document.getElementById('console-log');
  if (log && data.envios) {
    log.innerHTML = data.envios.slice(0,50).map(e => {
      const icon = e.status==='enviado' ? '✓' : e.status==='erro' ? '✗' : e.status==='respondeu' ? '↩' : '○';
      const cls  = e.status==='enviado'?'log-ok':e.status==='erro'?'log-err':'log-info';
      return `<div class="log-line">
        <span class="${cls}">${icon}</span>
        <span class="log-name">${esc(e.nome)}</span>
        <span class="log-text" style="margin-left:8px">${esc(e.msg_preview||'')}...</span>
      </div>`;
    }).join('');
  }
}

/* ═══════════════════════════════════════
   ENVIO PROGRESSIVO
═══════════════════════════════════════ */
function startSending() {
  if (!state.activeLoteId) return alert('Nenhum lote ativo.');
  const h = new Date().getHours();
  if (h < 9 || h >= 20) return alert('⚠️ Envio permitido apenas entre 09:00 e 20:00.');

  state.sending = true;
  document.getElementById('btn-start-send').style.display = 'none';
  document.getElementById('btn-stop-send').style.display  = 'inline-flex';
  document.getElementById('console-dot').className = 'dot-run green';
  document.getElementById('console-status').textContent = 'Enviando...';
  logLine('info', '▶', 'Envio iniciado');
  scheduleNext();
}

function stopSending() {
  state.sending = false;
  clearTimeout(state.sendTimer);
  document.getElementById('btn-start-send').style.display = 'inline-flex';
  document.getElementById('btn-stop-send').style.display  = 'none';
  document.getElementById('console-dot').className = 'dot-run gray';
  document.getElementById('console-status').textContent = 'Pausado';
  logLine('info', '⏸', 'Envio pausado pelo usuário');
}

function scheduleNext() {
  if (!state.sending) return;
  const base  = parseInt(document.getElementById('delay-range').value);
  const jitter = Math.floor(Math.random() * 60) - 30; // ±30s
  const delay = Math.max(20, base + jitter) * 1000;
  state.sendTimer = setTimeout(sendOne, delay);
}

async function sendOne() {
  if (!state.sending || !state.activeLoteId) return;

  const h = new Date().getHours();
  if (h < 9 || h >= 20) {
    stopSending();
    logLine('info', '⏰', 'Fora do horário permitido (09:00–20:00). Envio pausado.');
    return;
  }

  const fd = new FormData();
  fd.append('lote_id', state.activeLoteId);
  const data = await fetch(`${API}?action=send_next`, { method:'POST', body:fd })
    .then(r=>r.json()).catch(()=>({ok:false,error:'Erro de rede'}));

  if (!data.ok) {
    if (data.paused) { stopSending(); logLine('info','⏰',data.error||'Pausado'); return; }
    logLine('err','✗', data.error||'Erro desconhecido');
    scheduleNext();
    return;
  }

  if (data.done) {
    state.sending = false;
    document.getElementById('console-dot').className = 'dot-run gray';
    document.getElementById('console-status').textContent = 'Concluído ✅';
    document.getElementById('btn-start-send').style.display = 'inline-flex';
    document.getElementById('btn-stop-send').style.display  = 'none';
    logLine('ok','✓','Lote concluído! Todas as mensagens enviadas.');
    document.getElementById('send-panel').style.display = 'none';
    loadDashboard();
    return;
  }

  const icon = data.enviado ? '✓' : '✗';
  const cls  = data.enviado ? 'log-ok' : 'log-err';
  const restTxt = `(${data.restantes} restantes)`;
  logLine(data.enviado?'ok':'err', icon,
    `${data.nome} ${data.whatsapp} — ${(data.msg_preview||'').substring(0,50)}... ${restTxt}`);

  document.getElementById('console-progress').textContent = `${data.restantes} pendentes`;
  scheduleNext();
}

async function cancelLote() {
  if (!state.activeLoteId) return;
  if (!confirm('Cancelar o lote ativo? Os envios já realizados não serão desfeitos.')) return;
  stopSending();
  const fd = new FormData();
  fd.append('lote_id', state.activeLoteId);
  await fetch(`${API}?action=cancel_lote`, {method:'POST',body:fd});
  state.activeLoteId = null;
  loadLotes();
  loadDashboard();
}

function logLine(type, icon, text) {
  const log = document.getElementById('console-log');
  const now = new Date().toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
  const cls = type==='ok'?'log-ok':type==='err'?'log-err':'log-info';
  const div = document.createElement('div');
  div.className = 'log-line';
  div.innerHTML = `<span class="log-time">${now}</span><span class="${cls}">${icon}</span><span class="log-text">${esc(text)}</span>`;
  log.appendChild(div);
  log.scrollTop = log.scrollHeight;
}

/* ═══════════════════════════════════════
   SEGMENTOS
═══════════════════════════════════════ */
async function loadSegment(status, btn) {
  state.currentSegment = status;
  state.segSelected.clear();
  document.getElementById('seg-selection-bar').style.display = 'none';

  if (btn) {
    document.querySelectorAll('.seg-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
  }

  const wrap = document.getElementById('seg-table-wrap');
  wrap.innerHTML = '<div style="padding:30px;text-align:center;color:var(--text2)">Carregando...</div>';

  const data = await fetch(`${API}?action=get_clients_by_status&status=${status}`)
    .then(r=>r.json()).catch(()=>({ok:false,clients:[]}));

  if (!data.clients?.length) {
    wrap.innerHTML = '<div class="empty-state"><div class="icon">✨</div><p>Nenhum cliente neste segmento.</p></div>';
    return;
  }

  wrap.innerHTML = `<table class="clients-table">
    <thead><tr>
      <th style="width:36px"><input type="checkbox" onchange="segToggleAll(this)"></th>
      <th>Nome</th><th>WhatsApp</th><th>Tentativas</th><th>Último envio</th><th>Sem visita</th>
    </tr></thead>
    <tbody>
      ${data.clients.map(c => {
        const envioFmt = c.reativ_ultimo_envio ?
          new Date(c.reativ_ultimo_envio.replace(' ','T')+'Z').toLocaleDateString('pt-BR',{timeZone:'America/Cuiaba'}) : '—';
        const diasAus = c.ultimo_atendimento_em ?
          Math.floor((Date.now()-new Date(c.ultimo_atendimento_em.replace(' ','T')+'Z'))/86400000) : 999;
        return `<tr>
          <td><input type="checkbox" class="seg-check" data-id="${c.id}" onchange="segToggleRow(${c.id},this)"></td>
          <td style="font-weight:500">${esc(c.nome)}</td>
          <td style="font-family:'JetBrains Mono';font-size:12px;color:var(--text2)">${(c.whatsapp||'').substring(0,4)}****${(c.whatsapp||'').slice(-4)}</td>
          <td style="font-family:'JetBrains Mono';font-size:12px">${c.reativ_tentativas||0}×</td>
          <td style="font-size:12px;color:var(--text2)">${envioFmt}</td>
          <td><span class="dias-badge ${diasAus>=180?'dias-hot':diasAus>=90?'dias-warm':'dias-cold'}">${diasAus>=999?'nunca':diasAus+'d'}</span></td>
        </tr>`;
      }).join('')}
    </tbody>
  </table>`;
}

function segToggleRow(id, cb) {
  if (cb.checked) state.segSelected.add(parseInt(id));
  else state.segSelected.delete(parseInt(id));
  const bar = document.getElementById('seg-selection-bar');
  bar.style.display = state.segSelected.size > 0 ? 'flex' : 'none';
  document.getElementById('seg-sel-count').textContent = state.segSelected.size;
}
function segToggleAll(cb) {
  document.querySelectorAll('.seg-check').forEach(c => { c.checked = cb.checked; segToggleRow(parseInt(c.dataset.id), c); });
}
function selectSegAll(val) { document.querySelectorAll('.seg-check').forEach(c => { c.checked = val; segToggleRow(parseInt(c.dataset.id),c); }); }

async function moveSelected() {
  if (!state.segSelected.size) return;
  const novoStatus = document.getElementById('seg-move-to').value;
  const data = await fetch(`${API}?action=update_client_status`, {
    method:'POST',
    headers:{'Content-Type':'application/json'},
    body: JSON.stringify({client_ids:[...state.segSelected], status:novoStatus}),
  }).then(r=>r.json()).catch(()=>({ok:false}));

  if (data.ok) {
    loadSegment(state.currentSegment);
    loadDashboard();
  } else {
    alert('Erro: ' + (data.error||'Falha'));
  }
}

/* ═══════════════════════════════════════
   UTILS
═══════════════════════════════════════ */
function esc(str) {
  return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ═══════════════════════════════════════
   INIT
═══════════════════════════════════════ */
setup();
</script>
</body>
</html>
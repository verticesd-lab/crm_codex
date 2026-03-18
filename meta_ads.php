<?php
/**
 * meta_ads.php — Central de Tráfego Pago v2
 * ✅ Dashboard Meta Ads (Marketing API v25.0)
 * ✅ Análise de campanhas com IA (Claude / GPT-4 / Gemini)
 * ✅ Criador de campanhas com IA
 * ✅ Gerador de copies
 * ✅ Análise de nicho e concorrentes
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_login();

$pdo       = get_pdo();
$companyId = current_company_id();

/* ─── Garante tabela company_settings ──────────────────────── */
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS company_settings (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        company_id    INT NOT NULL,
        setting_key   VARCHAR(100) NOT NULL,
        setting_value TEXT,
        updated_at    DATETIME DEFAULT NOW(),
        UNIQUE KEY uq_co_key (company_id, setting_key)
    )");
} catch(Throwable $e) {}

/* ─── Settings helpers ──────────────────────────────────────── */
function ma_get(PDO $pdo, int $cid, string $key): string {
    $s = $pdo->prepare("SELECT setting_value FROM company_settings WHERE company_id=? AND setting_key=?");
    $s->execute([$cid, $key]);
    return (string)($s->fetchColumn() ?: '');
}
function ma_set(PDO $pdo, int $cid, string $key, string $val): void {
    $pdo->prepare("INSERT INTO company_settings (company_id,setting_key,setting_value,updated_at)
                   VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_at=NOW()")
        ->execute([$cid, $key, $val]);
}

/* ─── Meta API helper ───────────────────────────────────────── */
function meta_api(string $ep, string $tok, array $p=[], string $v='v25.0'): array {
    $p['access_token']=$tok;
    $ch=curl_init("https://graph.facebook.com/{$v}/{$ep}?".http_build_query($p));
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20]);
    $r=curl_exec($ch); curl_close($ch);
    return json_decode($r,true)??[];
}
function ma_action(array $ins, string $type): float {
    foreach($ins['actions']??[] as $a) if($a['action_type']===$type) return(float)($a['value']??0);
    return 0.0;
}
function ma_fc(float $v): string { return 'R$ '.number_format($v,2,',','.'); }
function ma_fn(float $v): string { return number_format($v,0,',','.'); }
function ma_fp(float $v): string { return number_format($v,2,',','.').'%'; }
function ma_sbadge(string $s): string {
    $m=['ACTIVE'=>['Ativa','#dcfce7','#15803d'],'PAUSED'=>['Pausada','#f1f5f9','#64748b'],
        'ARCHIVED'=>['Arquivada','#f1f5f9','#94a3b8'],'DELETED'=>['Deletada','#fee2e2','#dc2626'],
        'WITH_ISSUES'=>['Problema','#fef9c3','#a16207']];
    [$l,$bg,$c]=$m[$s]??[$s,'#f1f5f9','#64748b'];
    return "<span style='font-size:.62rem;font-weight:800;padding:.18rem .5rem;border-radius:20px;background:{$bg};color:{$c};text-transform:uppercase;white-space:nowrap;'>{$l}</span>";
}
function ma_obj(string $o): string {
    return ['OUTCOME_TRAFFIC'=>'🚦 Tráfego','OUTCOME_AWARENESS'=>'📢 Alcance','OUTCOME_ENGAGEMENT'=>'❤️ Engajamento',
            'OUTCOME_LEADS'=>'🎯 Leads','OUTCOME_SALES'=>'🛒 Vendas','LINK_CLICKS'=>'🖱️ Cliques',
            'MESSAGES'=>'💬 Mensagens','CONVERSIONS'=>'🛒 Conversões'][$o]??$o;
}

/* ─── AI caller ─────────────────────────────────────────────── */
function ai_call(string $prov, string $key, string $model, string $sys, string $msg): string {
    if($prov==='claude'){
        $pl=json_encode(['model'=>$model?:'claude-sonnet-4-20250514','max_tokens'=>2048,
            'system'=>$sys,'messages'=>[['role'=>'user','content'=>$msg]]]);
        $ch=curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$pl,
            CURLOPT_TIMEOUT=>45,CURLOPT_HTTPHEADER=>['Content-Type: application/json',
            'x-api-key: '.$key,'anthropic-version: 2023-06-01']]);
        $r=curl_exec($ch); curl_close($ch);
        $d=json_decode($r,true)??[];
        if(isset($d['error'])) return '❌ Claude: '.($d['error']['message']??'erro');
        return $d['content'][0]['text']??'';
    }
    if($prov==='openai'){
        $pl=json_encode(['model'=>$model?:'gpt-4o','max_tokens'=>2048,
            'messages'=>[['role'=>'system','content'=>$sys],['role'=>'user','content'=>$msg]]]);
        $ch=curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$pl,
            CURLOPT_TIMEOUT=>45,CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$key]]);
        $r=curl_exec($ch); curl_close($ch);
        $d=json_decode($r,true)??[];
        if(isset($d['error'])) return '❌ OpenAI: '.($d['error']['message']??'erro');
        return $d['choices'][0]['message']['content']??'';
    }
    if($prov==='gemini'){
        $model=$model?:'gemini-1.5-pro';
        $pl=json_encode(['system_instruction'=>['parts'=>[['text'=>$sys]]],
            'contents'=>[['parts'=>[['text'=>$msg]]]],'generationConfig'=>['maxOutputTokens'=>2048]]);
        $ch=curl_init("https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$key}");
        curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$pl,
            CURLOPT_TIMEOUT=>45,CURLOPT_HTTPHEADER=>['Content-Type: application/json']]);
        $r=curl_exec($ch); curl_close($ch);
        $d=json_decode($r,true)??[];
        if(isset($d['error'])) return '❌ Gemini: '.($d['error']['message']??'erro');
        return $d['candidates'][0]['content']['parts'][0]['text']??'';
    }
    return '❌ Provedor não reconhecido.';
}

/* ─── Load credentials ──────────────────────────────────────── */
$metaTok = ma_get($pdo,$companyId,'meta_access_token');
$metaAcc = ma_get($pdo,$companyId,'meta_ad_account_id');
$cfgd    = !empty($metaTok)&&!empty($metaAcc);
$aiProv  = ma_get($pdo,$companyId,'ai_provider')?:'claude';
$aiKey   = ma_get($pdo,$companyId,'ai_api_key');
$aiMdl   = ma_get($pdo,$companyId,'ai_model');
$aiOn    = !empty($aiKey);
$bizNiche= ma_get($pdo,$companyId,'biz_niche')    ?:'Loja de roupas, calçados e acessórios masculinos';
$bizTgt  = ma_get($pdo,$companyId,'biz_target')   ?:'Homens 18-45 anos, classes B e C';
$bizComp = ma_get($pdo,$companyId,'biz_competitors')?:'';
$bizLoc  = ma_get($pdo,$companyId,'biz_location') ?:'Brasil';
$bizGoal = ma_get($pdo,$companyId,'biz_goals')    ?:'Aumentar vendas e mensagens no WhatsApp';
$flash   = '';

/* ─── AJAX: AI actions ──────────────────────────────────────── */
if(isset($_GET['ai_action'])&&$aiOn){
    header('Content-Type: application/json; charset=utf-8');
    $act = $_GET['ai_action'];
    $ctx = $_POST['campaign_context']??'';
    $sys = "Você é especialista em marketing digital e tráfego pago no Meta Ads para o mercado brasileiro.
Negócio: {$bizNiche} | Público: {$bizTgt} | Localização: {$bizLoc}
Objetivos: {$bizGoal}".($bizComp?"\nConcorrentes: {$bizComp}":'')."
Responda em português brasileiro. Seja direto e prático. Use emojis como marcadores de seção.";

    if($act==='analyze'){
        $u="Analise os dados de campanhas e forneça:\n1. 📊 Diagnóstico geral\n2. 🚨 Alertas críticos\n3. 💡 Top 3 oportunidades\n4. 💰 Eficiência de investimento\n5. 🎯 Próximos 3 passos concretos\n\nDADOS:\n{$ctx}";
    }elseif($act==='optimize'){
        $u="Crie plano de otimização detalhado:\n1. 🎯 Público mais preciso\n2. 🖼️ Criativos para testar\n3. 💲 Distribuição de orçamento\n4. 📅 Melhores horários\n5. 🔄 3 Testes A/B para fazer agora\n\nDADOS:\n{$ctx}";
    }elseif($act==='suggest'){
        $u="Baseado nos dados, sugira:\n1. 🚀 3 novas campanhas (briefing resumido)\n2. 🔄 O que pausar/ajustar\n3. 📅 Calendário próximas 4 semanas\n4. 🎯 Funil recomendado (topo/meio/fundo) com orçamento\n5. ⚡ Quick wins para hoje\n\nDADOS:\n{$ctx}";
    }elseif($act==='create'){
        $obj=$_POST['objective']??''; $bud=$_POST['budget']??''; $prod=$_POST['product']??''; $ext=$_POST['extra']??'';
        $u="Crie briefing completo para campanha Meta Ads:\nObjetivo: {$obj}\nOrçamento: {$bud}\nProduto: {$prod}\nExtras: {$ext}\n\nInclua:\n1. 🎯 Nome sugerido\n2. 👥 Público detalhado (interesses, comportamentos, dados demográficos)\n3. 📍 Segmentação geográfica\n4. 📱 Posicionamentos recomendados\n5. 💬 3 Copies completos (texto + headline + descrição + CTA)\n6. 🖼️ Brief de criativos\n7. ⚙️ Configurações técnicas\n8. 📊 KPIs esperados para este nicho\n9. 🗓️ Cronograma (aprendizado → escalonamento)\n10. ⚠️ Políticas Meta a observar";
    }elseif($act==='copy'){
        $prod=$_POST['product']??''; $tone=$_POST['tone']??''; $fmt=$_POST['format']??'';
        $u="Gere 5 variações de copy para anúncio Meta:\nProduto: {$prod}\nTom: {$tone}\nFormato: {$fmt}\n\nCada variação deve ter:\n• Texto principal (máx 125 caracteres)\n• Headline (máx 40 caracteres)\n• Descrição (máx 30 caracteres)\n• CTA (botão)\nVarie: urgência, prova social, benefício, problema/solução, curiosidade.";
    }elseif($act==='niche'){
        $topic=$_POST['topic']??'completo';
        $u="Análise de nicho/mercado ({$topic}):\n1. 📈 Cenário atual do mercado de moda/varejo masculino no Brasil\n2. 🏆 Como concorrentes provavelmente anunciam\n3. 🎯 Como se diferenciar nos anúncios\n4. 📅 Sazonalidade e melhores épocas\n5. 💡 Ângulos criativos únicos\n6. 🔍 Melhores interesses e comportamentos para segmentação\n7. 📱 Formatos que mais convertem neste nicho\n8. 💰 Benchmarks: CPM, CPC, CTR esperados para moda masculina no Brasil\n\nConcorrentes mencionados: {$bizComp}";
    } else {
        echo json_encode(['result'=>'Ação não reconhecida.']); exit;
    }
    echo json_encode(['result'=>ai_call($aiProv,$aiKey,$aiMdl,$sys,$u??'')]);
    exit;
}

/* ─── POST: save configs ────────────────────────────────────── */
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(isset($_POST['save_meta'])){
        $t=trim($_POST['meta_access_token']??'');
        $a=trim($_POST['meta_ad_account_id']??'');
        if(!str_starts_with($a,'act_')) $a='act_'.preg_replace('/\D/','',$a);
        if(!$t||!$a){ $flash='error:Preencha o Access Token e o ID da conta.'; }
        else{
            $me=meta_api('me',$t,['fields'=>'id,name']);
            if(isset($me['error'])){ $flash='error:Token inválido: '.($me['error']['message']??''); }
            else{
                ma_set($pdo,$companyId,'meta_access_token',$t);
                ma_set($pdo,$companyId,'meta_ad_account_id',$a);
                $metaTok=$t; $metaAcc=$a; $cfgd=true;
                $flash='success:Conectado! Bem-vindo, '.($me['name']??'').'.';
            }
        }
    }
    if(isset($_POST['save_ai'])){
        ma_set($pdo,$companyId,'ai_provider',$_POST['ai_provider']??'claude');
        ma_set($pdo,$companyId,'ai_api_key', trim($_POST['ai_api_key']??''));
        ma_set($pdo,$companyId,'ai_model',   trim($_POST['ai_model']??''));
        $aiProv=$_POST['ai_provider']??'claude'; $aiKey=trim($_POST['ai_api_key']??'');
        $aiMdl=trim($_POST['ai_model']??''); $aiOn=!empty($aiKey);
        $flash='success:IA configurada!';
    }
    if(isset($_POST['save_biz'])){
        foreach(['biz_niche','biz_target','biz_competitors','biz_location','biz_goals'] as $k)
            ma_set($pdo,$companyId,$k,trim($_POST[$k]??''));
        $bizNiche=trim($_POST['biz_niche']??$bizNiche); $bizTgt=trim($_POST['biz_target']??$bizTgt);
        $bizComp=trim($_POST['biz_competitors']??$bizComp); $bizLoc=trim($_POST['biz_location']??$bizLoc);
        $bizGoal=trim($_POST['biz_goals']??$bizGoal);
        $flash='success:Contexto do negócio salvo! A IA usará nas análises.';
    }
}

/* ─── Date preset ───────────────────────────────────────────── */
$dp      = $_GET['date_preset']??'last_30d';
$dpValid = ['today','yesterday','last_7d','last_14d','last_30d','last_90d','this_month','last_month'];
if(!in_array($dp,$dpValid)) $dp='last_30d';
$dpLbls  = ['today'=>'Hoje','yesterday'=>'Ontem','last_7d'=>'7 dias','last_14d'=>'14 dias',
            'last_30d'=>'30 dias','last_90d'=>'90 dias','this_month'=>'Este mês','last_month'=>'Mês passado'];

$tab    = $_GET['tab']     ?? 'dashboard';
$campId = $_GET['campaign']?? '';
$asId   = $_GET['adset']   ?? '';

/* ─── Fetch Meta data ───────────────────────────────────────── */
$camps=[]; $adsets=[]; $ads=[]; $acIns=[]; $acInfo=[]; $apiErr='';
$iFields='campaign_name,campaign_id,adset_name,adset_id,ad_name,ad_id,impressions,reach,clicks,spend,cpm,cpc,ctr,frequency,actions,cost_per_action_type,date_start,date_stop';

if($cfgd){
    $acInfo=meta_api($metaAcc,$metaTok,['fields'=>'name,currency,timezone_name,account_status']);
    if(isset($acInfo['error'])){ $apiErr=$acInfo['error']['message']??'Erro na API'; }
    else{
        $ov=meta_api("{$metaAcc}/insights",$metaTok,['date_preset'=>$dp,'fields'=>'impressions,reach,clicks,spend,cpm,cpc,ctr,frequency,actions,cost_per_action_type','level'=>'account']);
        $acIns=$ov['data'][0]??[];
        $cr=meta_api("{$metaAcc}/campaigns",$metaTok,['fields'=>'id,name,status,objective,daily_budget','limit'=>50]);
        $camps=$cr['data']??[];
        $ci=meta_api("{$metaAcc}/insights",$metaTok,['date_preset'=>$dp,'level'=>'campaign','fields'=>$iFields,'limit'=>50]);
        $ciMap=[]; foreach($ci['data']??[] as $c) $ciMap[$c['campaign_id']]=$c;
        foreach($camps as &$c) $c['insights']=$ciMap[$c['id']]??[]; unset($c);
        if($campId){
            $ar=meta_api("{$campId}/adsets",$metaTok,['fields'=>'id,name,status,optimization_goal','limit'=>50]);
            $adsets=$ar['data']??[];
            $ai2=meta_api("{$campId}/insights",$metaTok,['date_preset'=>$dp,'level'=>'adset','fields'=>$iFields,'limit'=>50]);
            $aiMap=[]; foreach($ai2['data']??[] as $a) $aiMap[$a['adset_id']]=$a;
            foreach($adsets as &$a) $a['insights']=$aiMap[$a['id']]??[]; unset($a);
        }
        if($asId){
            $ar2=meta_api("{$asId}/ads",$metaTok,['fields'=>'id,name,status,creative{name,thumbnail_url}','limit'=>50]);
            $ads=$ar2['data']??[];
            $ai3=meta_api("{$asId}/insights",$metaTok,['date_preset'=>$dp,'level'=>'ad','fields'=>$iFields,'limit'=>50]);
            $ai3Map=[]; foreach($ai3['data']??[] as $a) $ai3Map[$a['ad_id']]=$a;
            foreach($ads as &$a) $a['insights']=$ai3Map[$a['id']]??[]; unset($a);
        }
    }
}

$totSp  =(float)($acIns['spend']??0);       $totIm=(int)($acIns['impressions']??0);
$totCl  =(int)($acIns['clicks']??0);        $totRe=(int)($acIns['reach']??0);
$avgCPM =(float)($acIns['cpm']??0);         $avgCPC=(float)($acIns['cpc']??0);
$avgCTR =(float)($acIns['ctr']??0);
$totMsg =ma_action($acIns,'onsite_conversion.messaging_conversation_started_7d');
$totLd  =ma_action($acIns,'lead');           $totLk=ma_action($acIns,'link_click');

function build_ctx(array $camps, array $acIns, string $dpLbl): string {
    $l=["PERÍODO: {$dpLbl}","TOTAIS:"];
    $l[]="Investido: R$ ".number_format((float)($acIns['spend']??0),2,',','.');
    $l[]="Impressões: ".number_format((int)($acIns['impressions']??0),0,',','.');
    $l[]="Cliques: ".number_format((int)($acIns['clicks']??0),0,',','.');
    $l[]="CTR: ".number_format((float)($acIns['ctr']??0),2,',','.')."% | CPM: R$ ".number_format((float)($acIns['cpm']??0),2,',','.');
    $l[]="\nCAMPANHAS:";
    foreach($camps as $i=>$c){
        $ins=$c['insights']; $sp=(float)($ins['spend']??0);
        $l[]="\n[".($i+1)."] {$c['name']} | Status: {$c['status']} | Obj: ".($c['objective']??'');
        $l[]="  Investido: R$ ".number_format($sp,2,',','.'); 
        $l[]="  CTR: ".number_format((float)($ins['ctr']??0),2,',','.')."% | CPM: R$ ".number_format((float)($ins['cpm']??0),2,',','.') ." | CPC: R$ ".number_format((float)($ins['cpc']??0),2,',','.');
        $msgs=ma_action($ins,'onsite_conversion.messaging_conversation_started_7d');
        $leads=ma_action($ins,'lead');
        if($msgs>0) $l[]="  Mensagens: ".number_format($msgs,0,',','.');
        if($leads>0) $l[]="  Leads: ".number_format($leads,0,',','.');
    }
    return implode("\n",$l);
}
$ctxJson=$cfgd&&!$apiErr ? build_ctx($camps,$acIns,$dpLbls[$dp]??$dp) : 'Sem dados.';

include __DIR__ . '/views/partials/header.php';
?>
<style>
.ma{font-family:'DM Sans',system-ui,sans-serif;max-width:1380px}
.fl-ok{padding:.7rem 1rem;border-radius:9px;background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;font-size:.83rem;margin-bottom:1rem}
.fl-er{padding:.7rem 1rem;border-radius:9px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;font-size:.83rem;margin-bottom:1rem}
/* Header */
.ma-hd{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin-bottom:1.25rem}
.ma-hd h1{font-size:1.4rem;font-weight:800;color:#0f172a}
.ma-actag{display:inline-flex;align-items:center;gap:.4rem;padding:.28rem .75rem;border-radius:20px;background:#eff6ff;border:1.5px solid #bfdbfe;font-size:.73rem;font-weight:700;color:#1d4ed8;margin-top:.3rem}
/* Tabs */
.ma-tabs{display:flex;border-bottom:2px solid #e2e8f0;margin-bottom:1.5rem;overflow-x:auto;gap:0}
.ma-tab{padding:.65rem 1.1rem;font-size:.81rem;font-weight:600;color:#64748b;cursor:pointer;white-space:nowrap;border:none;background:none;border-bottom:3px solid transparent;margin-bottom:-2px;transition:all .15s;text-decoration:none;display:flex;align-items:center;gap:.35rem}
.ma-tab:hover{color:#6366f1}.ma-tab.on{color:#6366f1;border-bottom-color:#6366f1;font-weight:700}
.tb-badge{background:#6366f1;color:#fff;font-size:.58rem;font-weight:800;padding:.1rem .38rem;border-radius:10px}
/* KPIs */
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:.75rem;margin-bottom:1.5rem}
.kpi{background:#fff;border:1px solid #e2e8f0;border-radius:13px;padding:.9rem 1rem;position:relative;overflow:hidden;transition:box-shadow .15s,transform .15s}
.kpi:hover{box-shadow:0 4px 16px rgba(0,0,0,.07);transform:translateY(-1px)}
.kpi::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--c,#6366f1)}
.kpi-ic{font-size:1.2rem;margin-bottom:.28rem}.kpi-lb{font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#94a3b8;margin-bottom:.18rem}
.kpi-vl{font-size:1.28rem;font-weight:800;color:#0f172a;line-height:1}.kpi-sb{font-size:.67rem;color:#94a3b8;margin-top:.18rem}
/* Cards */
.card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;margin-bottom:1.25rem}
.card-hd{padding:.8rem 1.15rem;background:#f8fafc;border-bottom:1px solid #f1f5f9;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.5rem}
.card-hd h2{font-size:.93rem;font-weight:700;color:#0f172a}
/* Table */
.mt{width:100%;border-collapse:collapse;font-size:.78rem}
.mt thead th{padding:.58rem .88rem;text-align:left;font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#94a3b8;background:#f8fafc;border-bottom:1px solid #e2e8f0;white-space:nowrap}
.mt thead th.r{text-align:right}.mt tbody tr{border-bottom:1px solid #f8fafc;transition:background .1s;cursor:pointer}
.mt tbody tr:hover{background:#f5f3ff}.mt td{padding:.62rem .88rem;vertical-align:middle}.mt td.r{text-align:right;font-variant-numeric:tabular-nums}
.cn{font-weight:700;color:#0f172a;max-width:210px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cs{font-size:.66rem;color:#64748b;margin-top:.1rem}
.cg{color:#16a34a;font-weight:700}.cy{color:#d97706;font-weight:700}.cr{color:#dc2626;font-weight:700}
.sbar{height:3px;background:#e2e8f0;border-radius:2px;margin-top:.28rem}
.sbar-f{height:100%;background:linear-gradient(90deg,#6366f1,#8b5cf6);border-radius:2px}
/* AI panel */
.aip{background:linear-gradient(135deg,#1e1b4b,#312e81 50%,#1e1b4b);border-radius:16px;padding:1.5rem;margin-bottom:1.25rem}
.aip-ttl{font-size:1.08rem;font-weight:800;color:#fff}.aip-sub{font-size:.76rem;color:#a5b4fc;margin-top:.18rem}
.ai-tag{display:inline-flex;align-items:center;gap:.3rem;padding:.28rem .7rem;border-radius:20px;background:rgba(255,255,255,.1);font-size:.7rem;font-weight:700;color:#c7d2fe;border:1px solid rgba(255,255,255,.14)}
.ai-btns{display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:.6rem;margin:1.1rem 0}
.ai-btn{padding:.82rem .95rem;border-radius:11px;border:1.5px solid rgba(255,255,255,.14);background:rgba(255,255,255,.07);color:#e0e7ff;font-size:.79rem;font-weight:600;cursor:pointer;text-align:left;transition:all .2s}
.ai-btn:hover{background:rgba(255,255,255,.15);border-color:rgba(255,255,255,.28);transform:translateY(-1px)}
.ai-ic{font-size:1.35rem;display:block;margin-bottom:.3rem}
.ai-bt{font-weight:700;color:#fff;display:block;margin-bottom:.12rem}
.ai-bd{font-size:.69rem;color:#a5b4fc}
.ai-res{background:rgba(0,0,0,.25);border:1px solid rgba(255,255,255,.11);border-radius:11px;padding:1.15rem;min-height:70px;color:#e0e7ff;font-size:.82rem;line-height:1.72;white-space:pre-wrap;font-family:'DM Sans',system-ui}
@keyframes sp{to{transform:rotate(360deg)}}
.spinner{width:22px;height:22px;border:3px solid rgba(255,255,255,.18);border-top-color:#818cf8;border-radius:50%;animation:sp .7s linear infinite;margin-right:.65rem}
/* Create/copy forms */
.fg2{display:grid;grid-template-columns:1fr 1fr;gap:.9rem;margin-bottom:.9rem}
.fg3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:.9rem;margin-bottom:.9rem}
.cfi{width:100%;padding:.52rem .8rem;border:1.5px solid rgba(255,255,255,.14);border-radius:8px;font-size:.82rem;background:rgba(255,255,255,.07);color:#fff;outline:none;transition:border-color .15s;font-family:inherit;box-sizing:border-box}
.cfi::placeholder{color:rgba(165,180,252,.55)}.cfi:focus{border-color:#818cf8;background:rgba(255,255,255,.12)}
.csel{width:100%;padding:.52rem .7rem;border:1.5px solid rgba(255,255,255,.14);border-radius:8px;font-size:.82rem;background:rgba(30,27,75,.85);color:#fff;outline:none;cursor:pointer}
.clb{display:block;font-size:.67rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#a5b4fc;margin-bottom:.28rem}
.ai-do{padding:.7rem 1.4rem;background:linear-gradient(135deg,#6366f1,#8b5cf6);color:#fff;border:none;border-radius:9px;font-size:.83rem;font-weight:700;cursor:pointer;transition:opacity .15s;display:inline-flex;align-items:center;gap:.5rem}
.ai-do:hover{opacity:.88}
/* Config */
.cfg-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:1.4rem;margin-bottom:1.2rem}
.cfg-card h3{font-size:.93rem;font-weight:700;color:#0f172a;margin-bottom:.95rem;display:flex;align-items:center;gap:.45rem}
.cfi2{width:100%;padding:.5rem .75rem;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.82rem;background:#f8fafc;color:#0f172a;outline:none;transition:border-color .15s;font-family:monospace;box-sizing:border-box}
.cfi2:focus{border-color:#6366f1;background:#fff}
.clb2{display:block;font-size:.69rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:#64748b;margin-bottom:.28rem}
.cfg2{display:grid;grid-template-columns:1fr 1fr;gap:.8rem}
.prv-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:.6rem;margin-bottom:.95rem}
.prv-opt input{display:none}
.prv-card{border:2px solid #e2e8f0;border-radius:10px;padding:.7rem;text-align:center;cursor:pointer;transition:all .15s}
.prv-opt input:checked + .prv-card{border-color:#6366f1;background:#f5f3ff}
.prv-card:hover{border-color:#a5b4fc}
.btn-sv{padding:.58rem 1.35rem;background:#6366f1;color:#fff;border:none;border-radius:8px;font-size:.81rem;font-weight:700;cursor:pointer}
.btn-sv:hover{background:#4f46e5}
.btn-meta{padding:.58rem 1.35rem;background:#0866FF;color:#fff;border:none;border-radius:8px;font-size:.81rem;font-weight:700;cursor:pointer}
.btn-meta:hover{background:#0758d4}
/* breadcrumb */
.bc{display:flex;align-items:center;gap:.38rem;font-size:.74rem;color:#94a3b8;margin-bottom:.95rem;flex-wrap:wrap}
.bc a{color:#6366f1;text-decoration:none;font-weight:600}.bc a:hover{text-decoration:underline}
/* empty */
.empty{text-align:center;padding:2.5rem;color:#94a3b8}
.empty .ic{font-size:2.25rem;margin-bottom:.6rem}
.alert-bar{padding:.75rem 1.1rem;border-radius:9px;background:#fffbeb;border:1px solid #fde68a;color:#92400e;font-size:.81rem;margin-bottom:1rem}
.datesel{padding:.4rem .68rem;border:1.5px solid #e2e8f0;border-radius:8px;font-size:.78rem;background:#fff;color:#374151;outline:none;cursor:pointer}
@media(max-width:768px){
    .kpi-grid{grid-template-columns:repeat(2,1fr)}.fg2,.fg3,.cfg2,.prv-grid{grid-template-columns:1fr}
    .ai-btns{grid-template-columns:1fr 1fr}.mt{font-size:.71rem}.mt td,.mt th{padding:.48rem .5rem}
}
</style>

<?php if($flash): [$ft,$fm]=explode(':',$flash,2); ?>
<div class="fl-<?= $ft==='success'?'ok':'er' ?>"><?= $ft==='success'?'✅':'⚠️' ?> <?= sanitize($fm) ?></div>
<?php endif; ?>

<div class="ma">

<!-- HEADER -->
<div class="ma-hd">
    <div>
        <h1>📊 Meta Ads<?php if($aiOn): ?> <span style="font-size:.72rem;font-weight:600;color:#6366f1;background:#f0f9ff;border:1px solid #bae6fd;padding:.13rem .5rem;border-radius:12px;margin-left:.45rem;">+ IA</span><?php endif; ?></h1>
        <?php if($cfgd&&!empty($acInfo['name'])): ?>
        <div class="ma-actag"><svg width="12" height="12" viewBox="0 0 24 24" fill="#0866FF"><path d="M24 12.073c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.99 4.388 10.954 10.125 11.854v-8.385H7.078v-3.47h3.047V9.43c0-3.007 1.792-4.669 4.533-4.669 1.312 0 2.686.235 2.686.235v2.953H15.83c-1.491 0-1.956.925-1.956 1.874v2.25h3.328l-.532 3.47h-2.796v8.385C19.612 23.027 24 18.062 24 12.073z"/></svg><?= sanitize($acInfo['name']) ?><?php if(!empty($acInfo['timezone_name'])): ?><span style="font-weight:400;color:#3b82f6;">· <?= sanitize($acInfo['timezone_name']) ?></span><?php endif; ?></div>
        <?php endif; ?>
    </div>
    <?php if($cfgd&&!$apiErr): ?>
    <form method="GET"><input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>"><?php if($campId): ?><input type="hidden" name="campaign" value="<?= htmlspecialchars($campId) ?>"><?php endif; ?>
    <select name="date_preset" class="datesel" onchange="this.form.submit()"><?php foreach($dpLbls as $v=>$l): ?><option value="<?= $v ?>"<?= $dp===$v?' selected':'' ?>><?= $l ?></option><?php endforeach; ?></select></form>
    <?php endif; ?>
</div>

<!-- TABS -->
<div class="ma-tabs">
<?php
$tbDefs=[['dashboard','📊','Dashboard',$cfgd],['ai','🤖','Análise IA',$cfgd&&$aiOn],
         ['create','✨','Criar Campanha',$aiOn],['copy','✍️','Gerar Copies',$aiOn],
         ['niche','🔭','Nicho & Concorrência',$aiOn],['config','⚙️','Configurações',true]];
foreach($tbDefs as [$tid,$tic,$tnm,$tshow]):
    if(!$tshow) continue; $ion=$tab===$tid;
?>
<a href="?tab=<?= $tid ?>&date_preset=<?= $dp ?><?= $campId?"&campaign=".urlencode($campId):'' ?>" class="ma-tab <?= $ion?'on':'' ?>"><?= $tic ?> <?= $tnm ?><?php if($tid==='ai'&&$aiOn): ?><span class="tb-badge">IA</span><?php endif; ?></a>
<?php endforeach; ?>
</div>

<?php if(!$cfgd&&$tab!=='config'): ?>
<div style="max-width:500px;margin:2.5rem auto;text-align:center;">
    <div style="font-size:3rem;margin-bottom:1rem;">📡</div>
    <h2 style="font-size:1.1rem;font-weight:700;margin-bottom:.5rem;">Meta Ads não conectado</h2>
    <p style="color:#64748b;font-size:.84rem;margin-bottom:1.25rem;">Configure as credenciais na aba Configurações para começar.</p>
    <a href="?tab=config" style="display:inline-flex;align-items:center;gap:.5rem;padding:.68rem 1.4rem;background:#0866FF;color:#fff;border-radius:9px;font-weight:700;text-decoration:none;font-size:.84rem;">⚙️ Ir para Configurações</a>
</div>

<?php elseif($tab==='dashboard'): ?>
<?php if($apiErr): ?><div class="alert-bar">⚠️ <?= sanitize($apiErr) ?> — Verifique em <a href="?tab=config" style="color:#0866FF;font-weight:600;">Configurações</a>.</div>
<?php else: ?>

<!-- KPIs -->
<?php $kpis=[['💸','Investido',ma_fc($totSp),'','#0866FF'],['👁️','Impressões',ma_fn($totIm),'Freq: '.number_format((float)($acIns['frequency']??0),1,',','.'),'#8b5cf6'],['🎯','Alcance',ma_fn($totRe),'','#6366f1'],['🖱️','Cliques',ma_fn($totLk?:$totCl),'CTR: '.ma_fp($avgCTR),'#0891b2'],['💰','CPM','R$ '.number_format($avgCPM,2,',','.'),'por mil imp','#7c3aed'],['💲','CPC','R$ '.number_format($avgCPC,2,',','.'),'por clique','#059669'],['💬','Mensagens',ma_fn($totMsg),'Conversas','#d97706'],['🎯','Leads',ma_fn($totLd),$totLd>0?'R$ '.number_format($totSp/max(1,$totLd),2,',','.').' /lead':'','#16a34a']]; ?>
<div class="kpi-grid"><?php foreach($kpis as [$ic,$lb,$vl,$sb,$c]): ?><div class="kpi" style="--c:<?= $c ?>"><div class="kpi-ic"><?= $ic ?></div><div class="kpi-lb"><?= $lb ?></div><div class="kpi-vl"><?= $vl ?></div><?php if($sb): ?><div class="kpi-sb"><?= sanitize($sb) ?></div><?php endif; ?></div><?php endforeach; ?></div>

<!-- Quick AI buttons -->
<?php if($aiOn&&!$campId): ?>
<div style="display:flex;gap:.5rem;margin-bottom:1rem;flex-wrap:wrap;">
    <button onclick="quickAI('analyze')" style="display:inline-flex;align-items:center;gap:.38rem;padding:.48rem .95rem;background:#f5f3ff;border:1.5px solid #c4b5fd;border-radius:8px;font-size:.77rem;font-weight:700;color:#5b21b6;cursor:pointer;">🤖 Analisar com IA</button>
    <button onclick="quickAI('suggest')" style="display:inline-flex;align-items:center;gap:.38rem;padding:.48rem .95rem;background:#f0fdf4;border:1.5px solid #bbf7d0;border-radius:8px;font-size:.77rem;font-weight:700;color:#15803d;cursor:pointer;">💡 Sugestões IA</button>
</div>
<div id="qai-wrap" style="display:none;background:linear-gradient(135deg,#1e1b4b,#312e81);border-radius:13px;padding:1.15rem;margin-bottom:1rem;">
    <div id="qai-res" class="ai-res"></div>
</div>
<?php endif; ?>

<!-- Breadcrumb -->
<div class="bc"><a href="?tab=dashboard&date_preset=<?= $dp ?>">🏠 Conta</a><?php if($campId): $cn=''; foreach($camps as $c){if($c['id']===$campId){$cn=$c['name'];break;}} ?><span>›</span><a href="?tab=dashboard&date_preset=<?= $dp ?>&campaign=<?= urlencode($campId) ?>"><?= sanitize(mb_substr($cn,0,32)) ?></a><?php endif; ?><?php if($asId): $an=''; foreach($adsets as $a){if($a['id']===$asId){$an=$a['name'];break;}} ?><span>›</span><span><?= sanitize(mb_substr($an,0,32)) ?></span><?php endif; ?></div>

<?php if(!$campId): ?>
<!-- Campaigns table -->
<div class="card"><div class="card-hd"><h2>🚀 Campanhas — <?= $dpLbls[$dp] ?></h2><span style="font-size:.73rem;color:#94a3b8;"><?= count($camps) ?> campanha(s)</span></div>
<?php if(empty($camps)): ?><div class="empty"><div class="ic">📭</div><p>Nenhuma campanha encontrada.</p></div>
<?php else: $mxSp=0; foreach($camps as $c){$s=(float)($c['insights']['spend']??0); if($s>$mxSp) $mxSp=$s;} ?>
<div style="overflow-x:auto;"><table class="mt"><thead><tr><th>Campanha</th><th>Status</th><th class="r">Investido</th><th class="r">Impressões</th><th class="r">Cliques</th><th class="r">CTR</th><th class="r">CPM</th><th class="r">CPC</th><th class="r">Msgs</th><th class="r">Leads</th></tr></thead><tbody>
<?php foreach($camps as $camp): $ins=$camp['insights']; $sp=(float)($ins['spend']??0); $im=(int)($ins['impressions']??0); $cl=(int)($ins['clicks']??0); $ctr=(float)($ins['ctr']??0); $cpm=(float)($ins['cpm']??0); $cpc=(float)($ins['cpc']??0); $msgs=ma_action($ins,'onsite_conversion.messaging_conversation_started_7d'); $leads=ma_action($ins,'lead'); $pct=$mxSp>0?round($sp/$mxSp*100):0; $url="?tab=dashboard&date_preset={$dp}&campaign=".urlencode($camp['id']); $cc=$ctr>=1?'cg':($ctr>=0.5?'cy':'cr'); ?>
<tr onclick="location.href='<?= $url ?>'"><td><div class="cn"><?= sanitize(mb_substr($camp['name'],0,38)) ?></div><div class="cs"><?= ma_obj($camp['objective']??'') ?></div><?php if($sp>0): ?><div class="sbar"><div class="sbar-f" style="width:<?= $pct ?>%"></div></div><?php endif; ?></td><td><?= ma_sbadge($camp['status']??'') ?></td><td class="r" style="font-weight:700;"><?= $sp>0?ma_fc($sp):'—' ?></td><td class="r"><?= $im>0?ma_fn($im):'—' ?></td><td class="r"><?= $cl>0?ma_fn($cl):'—' ?></td><td class="r"><?= $ctr>0?"<span class='{$cc}>".ma_fp($ctr)."</span>":'—' ?></td><td class="r"><?= $cpm>0?'R$ '.number_format($cpm,2,',','.'):'—' ?></td><td class="r"><?= $cpc>0?'R$ '.number_format($cpc,2,',','.'):'—' ?></td><td class="r"><?= $msgs>0?"<span class='cg'>".ma_fn($msgs)."</span>":'—' ?></td><td class="r"><?= $leads>0?"<span class='cg'>".ma_fn($leads)."</span>":'—' ?></td></tr>
<?php endforeach; ?></tbody></table></div><?php endif; ?></div>

<?php elseif($campId&&!$asId): ?>
<!-- Ad sets -->
<div class="card"><div class="card-hd"><h2>📦 Conjuntos de Anúncios</h2><span style="font-size:.73rem;color:#94a3b8;"><?= count($adsets) ?> conjunto(s)</span></div>
<?php if(empty($adsets)): ?><div class="empty"><div class="ic">📭</div><p>Nenhum conjunto encontrado.</p></div>
<?php else: ?><div style="overflow-x:auto;"><table class="mt"><thead><tr><th>Conjunto</th><th>Status</th><th class="r">Investido</th><th class="r">Impressões</th><th class="r">Cliques</th><th class="r">CTR</th><th class="r">CPM</th><th class="r">CPC</th><th class="r">Msgs</th><th class="r">Leads</th></tr></thead><tbody>
<?php foreach($adsets as $as): $ins=$as['insights']; $sp=(float)($ins['spend']??0); $im=(int)($ins['impressions']??0); $cl=(int)($ins['clicks']??0); $ctr=(float)($ins['ctr']??0); $cpm=(float)($ins['cpm']??0); $cpc=(float)($ins['cpc']??0); $msgs=ma_action($ins,'onsite_conversion.messaging_conversation_started_7d'); $leads=ma_action($ins,'lead'); $url="?tab=dashboard&date_preset={$dp}&campaign=".urlencode($campId)."&adset=".urlencode($as['id']); $cc=$ctr>=1?'cg':($ctr>=0.5?'cy':'cr'); ?>
<tr onclick="location.href='<?= $url ?>'"><td><div class="cn"><?= sanitize(mb_substr($as['name'],0,38)) ?></div><div class="cs"><?= sanitize(str_replace('_',' ',$as['optimization_goal']??'')) ?></div></td><td><?= ma_sbadge($as['status']??'') ?></td><td class="r" style="font-weight:700;"><?= $sp>0?ma_fc($sp):'—' ?></td><td class="r"><?= $im>0?ma_fn($im):'—' ?></td><td class="r"><?= $cl>0?ma_fn($cl):'—' ?></td><td class="r"><?= $ctr>0?"<span class='{$cc}'>".ma_fp($ctr)."</span>":'—' ?></td><td class="r"><?= $cpm>0?'R$ '.number_format($cpm,2,',','.'):'—' ?></td><td class="r"><?= $cpc>0?'R$ '.number_format($cpc,2,',','.'):'—' ?></td><td class="r"><?= $msgs>0?"<span class='cg'>".ma_fn($msgs)."</span>":'—' ?></td><td class="r"><?= $leads>0?"<span class='cg'>".ma_fn($leads)."</span>":'—' ?></td></tr>
<?php endforeach; ?></tbody></table></div><?php endif; ?></div>

<?php elseif($asId): ?>
<!-- Ads -->
<div class="card"><div class="card-hd"><h2>🖼️ Anúncios</h2><span style="font-size:.73rem;color:#94a3b8;"><?= count($ads) ?> anúncio(s)</span></div>
<?php if(empty($ads)): ?><div class="empty"><div class="ic">📭</div><p>Nenhum anúncio.</p></div>
<?php else: ?><div style="overflow-x:auto;"><table class="mt"><thead><tr><th>Anúncio</th><th>Status</th><th class="r">Investido</th><th class="r">Impressões</th><th class="r">Cliques</th><th class="r">CTR</th><th class="r">CPM</th><th class="r">CPC</th><th class="r">Msgs/Leads</th></tr></thead><tbody>
<?php foreach($ads as $ad): $ins=$ad['insights']; $sp=(float)($ins['spend']??0); $im=(int)($ins['impressions']??0); $cl=(int)($ins['clicks']??0); $ctr=(float)($ins['ctr']??0); $cpm=(float)($ins['cpm']??0); $cpc=(float)($ins['cpc']??0); $msgs=ma_action($ins,'onsite_conversion.messaging_conversation_started_7d'); $leads=ma_action($ins,'lead'); $thumb=$ad['creative']['thumbnail_url']??''; $cc=$ctr>=1?'cg':($ctr>=0.5?'cy':'cr'); ?>
<tr><td><div style="display:flex;align-items:center;gap:.55rem;"><?php if($thumb): ?><img src="<?= htmlspecialchars($thumb) ?>" style="width:40px;height:40px;border-radius:7px;object-fit:cover;border:1px solid #e2e8f0;flex-shrink:0;"><?php else: ?><div style="width:40px;height:40px;border-radius:7px;background:#f1f5f9;display:flex;align-items:center;justify-content:center;flex-shrink:0;">🖼️</div><?php endif; ?><div><div class="cn"><?= sanitize(mb_substr($ad['name'],0,32)) ?></div><div class="cs"><?= sanitize($ad['id']) ?></div></div></div></td><td><?= ma_sbadge($ad['status']??'') ?></td><td class="r" style="font-weight:700;"><?= $sp>0?ma_fc($sp):'—' ?></td><td class="r"><?= $im>0?ma_fn($im):'—' ?></td><td class="r"><?= $cl>0?ma_fn($cl):'—' ?></td><td class="r"><?= $ctr>0?"<span class='{$cc}'>".ma_fp($ctr)."</span>":'—' ?></td><td class="r"><?= $cpm>0?'R$ '.number_format($cpm,2,',','.'):'—' ?></td><td class="r"><?= $cpc>0?'R$ '.number_format($cpc,2,',','.'):'—' ?></td><td class="r"><?php if($msgs>0): ?><span class="cg">💬 <?= ma_fn($msgs) ?></span><br><?php endif; ?><?php if($leads>0): ?><span class="cg">🎯 <?= ma_fn($leads) ?></span><?php endif; ?><?php if(!$msgs&&!$leads): ?>—<?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div><?php endif; ?></div>
<?php endif; ?>
<?php endif; // !apiErr ?>

<?php elseif($tab==='ai'): ?>
<?php if(!$aiOn): ?><div class="alert-bar">🤖 Configure a IA em <a href="?tab=config" style="color:#0866FF;font-weight:600;">Configurações → IA</a>.</div>
<?php else: ?>
<div class="aip">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:.75rem;margin-bottom:1.1rem;">
        <div><div class="aip-ttl">🤖 Análise Inteligente de Campanhas</div><div class="aip-sub">IA analisa seus dados e gera insights acionáveis baseados no seu nicho</div></div>
        <div style="display:flex;gap:.4rem;flex-wrap:wrap;"><span class="ai-tag"><?= ['claude'=>'🟠 Claude','openai'=>'🟢 GPT-4','gemini'=>'🔵 Gemini'][$aiProv]??$aiProv ?></span><span class="ai-tag">📅 <?= $dpLbls[$dp] ?></span></div>
    </div>
    <div class="ai-btns">
        <button class="ai-btn" onclick="doAI('analyze')"><span class="ai-ic">📊</span><span class="ai-bt">Diagnóstico completo</span><span class="ai-bd">Alertas, oportunidades e próximos passos para todas as campanhas</span></button>
        <button class="ai-btn" onclick="doAI('optimize')"><span class="ai-ic">🎯</span><span class="ai-bt">Plano de otimização</span><span class="ai-bd">Público, criativos, orçamento e testes A/B recomendados</span></button>
        <button class="ai-btn" onclick="doAI('suggest')"><span class="ai-ic">💡</span><span class="ai-bt">Sugerir campanhas</span><span class="ai-bd">Novas campanhas faltantes no funil + calendário 4 semanas</span></button>
    </div>
    <div id="ai-main" style="display:none;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.55rem;"><span style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#a5b4fc;">Análise gerada</span><button onclick="cpTxt('ai-res')" style="padding:.22rem .6rem;border-radius:6px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.07);color:#a5b4fc;font-size:.69rem;cursor:pointer;">📋 Copiar</button></div>
        <div id="ai-res" class="ai-res"></div>
    </div>
</div>
<div class="card"><div class="card-hd"><h2>📋 Dados enviados à IA</h2></div><div style="padding:1rem 1.2rem;"><pre style="font-size:.72rem;color:#475569;background:#f8fafc;border-radius:8px;padding:.8rem;overflow-x:auto;max-height:200px;border:1px solid #e2e8f0;white-space:pre-wrap;"><?= htmlspecialchars($ctxJson) ?></pre></div></div>
<?php endif; ?>

<?php elseif($tab==='create'): ?>
<div class="aip">
    <div style="margin-bottom:1.1rem;"><div class="aip-ttl">✨ Criador de Campanhas com IA</div><div class="aip-sub">Descreva o que quer anunciar — a IA gera o briefing completo em 10 seções</div></div>
    <div class="fg2"><div><label class="clb">Objetivo da campanha</label><select id="c-obj" class="csel"><option value="Gerar mensagens no WhatsApp">💬 Mensagens no WhatsApp</option><option value="Tráfego para a loja virtual">🚦 Tráfego para loja</option><option value="Gerar leads (cadastros)">🎯 Leads / Cadastros</option><option value="Aumentar reconhecimento da marca">📢 Reconhecimento de marca</option><option value="Remarketing para visitantes">🔄 Remarketing</option><option value="Vendas diretas (conversão)">🛒 Vendas / Conversão</option><option value="Engajamento com publicações">❤️ Engajamento</option></select></div><div><label class="clb">Orçamento diário</label><select id="c-bud" class="csel"><option value="R$ 20/dia">R$ 20/dia (básico)</option><option value="R$ 50/dia" selected>R$ 50/dia (recomendado)</option><option value="R$ 100/dia">R$ 100/dia (acelerado)</option><option value="R$ 200/dia">R$ 200/dia (escalonado)</option><option value="R$ 500/dia">R$ 500/dia (agressivo)</option></select></div></div>
    <div style="margin-bottom:.9rem;"><label class="clb">Produto / Coleção a anunciar *</label><input type="text" id="c-prod" class="cfi" placeholder="Ex: Nova coleção de tênis masculinos, destaque para o Nike Air Force 1"></div>
    <div style="margin-bottom:1.2rem;"><label class="clb">Informações extras (promoções, prazo, diferenciais)</label><textarea id="c-ext" class="cfi" rows="2" placeholder="Ex: 30% off, parcelamento 10x, estoque limitado, lançamento de coleção inverno..."></textarea></div>
    <button class="ai-do" onclick="doCreate()">✨ Gerar Briefing Completo</button>
    <div id="c-wrap" style="display:none;margin-top:1.2rem;"><div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.55rem;"><span style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#a5b4fc;">Briefing gerado</span><button onclick="cpTxt('c-res')" style="padding:.22rem .6rem;border-radius:6px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.07);color:#a5b4fc;font-size:.69rem;cursor:pointer;">📋 Copiar</button></div><div id="c-res" class="ai-res"></div></div>
</div>

<?php elseif($tab==='copy'): ?>
<div class="aip">
    <div style="margin-bottom:1.1rem;"><div class="aip-ttl">✍️ Gerador de Copies para Anúncios</div><div class="aip-sub">5 variações com diferentes ângulos persuasivos — pronto para usar no Ads Manager</div></div>
    <div class="fg3"><div><label class="clb">Produto / Oferta</label><input type="text" id="cp-prod" class="cfi" placeholder="Ex: Jaqueta de couro R$ 299"></div><div><label class="clb">Tom da comunicação</label><select id="cp-tone" class="csel"><option value="persuasivo e direto">Persuasivo e direto</option><option value="urgente e escasso">Urgência e escassez</option><option value="emocional e aspiracional">Emocional e aspiracional</option><option value="divertido e informal">Divertido e informal</option><option value="premium e sofisticado">Premium e sofisticado</option><option value="prova social">Prova social</option></select></div><div><label class="clb">Formato</label><select id="cp-fmt" class="csel"><option value="Feed Instagram/Facebook">Feed Instagram/Facebook</option><option value="Stories do Instagram">Stories</option><option value="Reels do Instagram">Reels</option><option value="Carrossel">Carrossel</option><option value="Mensagem patrocinada">Msg patrocinada</option></select></div></div>
    <button class="ai-do" style="background:linear-gradient(135deg,#0891b2,#0e7490);" onclick="doCopy()">✍️ Gerar 5 Variações</button>
    <div id="cp-wrap" style="display:none;margin-top:1.2rem;"><div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.55rem;"><span style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#a5b4fc;">Copies gerados</span><button onclick="cpTxt('cp-res')" style="padding:.22rem .6rem;border-radius:6px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.07);color:#a5b4fc;font-size:.69rem;cursor:pointer;">📋 Copiar todos</button></div><div id="cp-res" class="ai-res"></div></div>
</div>

<?php elseif($tab==='niche'): ?>
<div class="aip">
    <div style="margin-bottom:1.1rem;"><div class="aip-ttl">🔭 Nicho & Concorrência</div><div class="aip-sub">Benchmarks, análise competitiva e estratégias para o seu segmento</div></div>
    <div style="background:rgba(255,255,255,.07);border:1px solid rgba(255,255,255,.11);border-radius:9px;padding:.9rem;margin-bottom:1.1rem;font-size:.75rem;color:#e0e7ff;">
        <p style="color:#a5b4fc;font-weight:700;margin-bottom:.4rem;font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;">Contexto enviado à IA</p>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:.3rem;">
            <div>🏪 <b>Nicho:</b> <?= sanitize($bizNiche) ?></div><div>🎯 <b>Público:</b> <?= sanitize($bizTgt) ?></div>
            <div>📍 <b>Mercado:</b> <?= sanitize($bizLoc) ?></div><div>🏆 <b>Concorrentes:</b> <?= $bizComp?sanitize($bizComp):'Não informado' ?></div>
        </div>
    </div>
    <div class="ai-btns" style="grid-template-columns:1fr 1fr;">
        <button class="ai-btn" onclick="doNiche('completo')"><span class="ai-ic">🔭</span><span class="ai-bt">Análise completa de nicho</span><span class="ai-bd">Mercado, concorrentes, sazonalidade e benchmarks</span></button>
        <button class="ai-btn" onclick="doNiche('sazonalidade')"><span class="ai-ic">📅</span><span class="ai-bt">Calendário de campanhas</span><span class="ai-bd">Melhores épocas e datas para moda/varejo</span></button>
        <button class="ai-btn" onclick="doNiche('interesses')"><span class="ai-ic">🎯</span><span class="ai-bt">Interesses para segmentação</span><span class="ai-bd">Top interesses e comportamentos no Meta Ads</span></button>
        <button class="ai-btn" onclick="doNiche('criativos')"><span class="ai-ic">🖼️</span><span class="ai-bt">Criativos que convertem</span><span class="ai-bd">Formatos e estilos para moda masculina</span></button>
    </div>
    <div id="ni-wrap" style="display:none;"><div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.55rem;"><span style="font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;color:#a5b4fc;">Análise gerada</span><button onclick="cpTxt('ni-res')" style="padding:.22rem .6rem;border-radius:6px;border:1px solid rgba(255,255,255,.18);background:rgba(255,255,255,.07);color:#a5b4fc;font-size:.69rem;cursor:pointer;">📋 Copiar</button></div><div id="ni-res" class="ai-res"></div></div>
</div>

<?php elseif($tab==='config'): ?>
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.2rem;align-items:start;">
<div>
<div class="cfg-card"><h3>📡 Meta Ads API</h3>
<form method="POST"><input type="hidden" name="save_meta" value="1">
<div style="margin-bottom:.8rem;"><label class="clb2">Access Token *</label><input type="text" name="meta_access_token" class="cfi2" required value="<?= htmlspecialchars($metaTok) ?>" placeholder="EAABwzLixnjYBO..."><p style="font-size:.67rem;color:#94a3b8;margin-top:.22rem;">Permissão necessária: <strong>ads_read</strong></p></div>
<div style="margin-bottom:1.2rem;"><label class="clb2">ID da conta de anúncios *</label><input type="text" name="meta_ad_account_id" class="cfi2" required value="<?= htmlspecialchars($metaAcc) ?>" placeholder="act_123456789"></div>
<button type="submit" class="btn-meta">🔗 Salvar e validar</button></form></div>

<div class="cfg-card"><h3>🤖 Provedor de IA</h3>
<form method="POST"><input type="hidden" name="save_ai" value="1">
<div style="margin-bottom:.95rem;"><label class="clb2">Provedor</label>
<div class="prv-grid"><?php foreach([['claude','🟠','Claude','Anthropic — análises profundas'],['openai','🟢','GPT-4','OpenAI — versátil e rápido'],['gemini','🔵','Gemini','Google — dados e benchmarks']] as [$v,$ic,$nm,$ds]): ?><label class="prv-opt"><input type="radio" name="ai_provider" value="<?= $v ?>" <?= $aiProv===$v?'checked':'' ?>><div class="prv-card"><span style="font-size:1.4rem;display:block;margin-bottom:.25rem;"><?= $ic ?></span><p style="font-size:.78rem;font-weight:700;color:#0f172a;"><?= $nm ?></p><p style="font-size:.65rem;color:#94a3b8;margin-top:.1rem;"><?= $ds ?></p></div></label><?php endforeach; ?>
</div></div>
<div style="margin-bottom:.8rem;"><label class="clb2">API Key *</label><input type="text" name="ai_api_key" class="cfi2" required value="<?= htmlspecialchars($aiKey) ?>" placeholder="sk-... / AIza... / sk-ant-..."></div>
<div style="margin-bottom:1.2rem;"><label class="clb2">Modelo (deixe em branco para padrão)</label><input type="text" name="ai_model" class="cfi2" value="<?= htmlspecialchars($aiMdl) ?>" placeholder="claude-sonnet-4-20250514 / gpt-4o / gemini-1.5-pro"></div>
<button type="submit" class="btn-sv">💾 Salvar IA</button></form></div>
</div>

<div>
<div class="cfg-card"><h3>🏪 Contexto do Negócio</h3>
<p style="font-size:.77rem;color:#64748b;margin-bottom:.95rem;">Informações enviadas à IA em todas as análises — quanto mais detalhado, melhores os resultados.</p>
<form method="POST"><input type="hidden" name="save_biz" value="1">
<div style="margin-bottom:.8rem;"><label class="clb2">Nicho / Tipo de negócio</label><input type="text" name="biz_niche" class="cfi2" style="font-family:inherit;" value="<?= htmlspecialchars($bizNiche) ?>" placeholder="Ex: Loja de roupas e calçados masculinos"></div>
<div style="margin-bottom:.8rem;"><label class="clb2">Público-alvo</label><input type="text" name="biz_target" class="cfi2" style="font-family:inherit;" value="<?= htmlspecialchars($bizTgt) ?>" placeholder="Ex: Homens 20-45 anos, classes B e C"></div>
<div style="margin-bottom:.8rem;"><label class="clb2">Concorrentes (opcional)</label><input type="text" name="biz_competitors" class="cfi2" style="font-family:inherit;" value="<?= htmlspecialchars($bizComp) ?>" placeholder="Ex: Zara, Renner, C&A, lojas locais"></div>
<div class="cfg2" style="margin-bottom:.8rem;">
<div><label class="clb2">Localização / Mercado</label><input type="text" name="biz_location" class="cfi2" style="font-family:inherit;" value="<?= htmlspecialchars($bizLoc) ?>" placeholder="Ex: Cuiabá - MT, Brasil"></div>
<div><label class="clb2">Objetivos de marketing</label><input type="text" name="biz_goals" class="cfi2" style="font-family:inherit;" value="<?= htmlspecialchars($bizGoal) ?>" placeholder="Ex: Mais vendas e WhatsApp"></div>
</div>
<button type="submit" class="btn-sv">💾 Salvar contexto</button></form></div>

<div class="cfg-card" style="border-color:#e0e7ff;">
<h3 style="color:#3730a3;">🔧 Como obter as credenciais</h3>
<div style="font-size:.76rem;color:#475569;display:flex;flex-direction:column;gap:.5rem;">
<div style="padding:.55rem .75rem;background:#eff6ff;border-radius:8px;"><p style="font-weight:700;color:#1d4ed8;margin-bottom:.2rem;">📡 Meta Ads Token</p><p>business.facebook.com → Configurações → Acesso à API → Gerar token com <code style="background:#dbeafe;padding:.1rem .3rem;border-radius:3px;font-size:.7rem;">ads_read</code></p></div>
<div style="padding:.55rem .75rem;background:#f0fdf4;border-radius:8px;"><p style="font-weight:700;color:#166534;margin-bottom:.2rem;">🟠 Claude (Anthropic)</p><p>console.anthropic.com → API Keys</p></div>
<div style="padding:.55rem .75rem;background:#f0fdf4;border-radius:8px;"><p style="font-weight:700;color:#166534;margin-bottom:.2rem;">🟢 GPT-4 (OpenAI)</p><p>platform.openai.com → API Keys</p></div>
<div style="padding:.55rem .75rem;background:#eff6ff;border-radius:8px;"><p style="font-weight:700;color:#1d4ed8;margin-bottom:.2rem;">🔵 Gemini (Google)</p><p>aistudio.google.com → Get API Key</p></div>
</div></div>
</div>
</div>
<?php endif; ?>

</div><!-- /.ma -->

<script>
const CTX=<?= json_encode($ctxJson) ?>;
const AIURL='?ai_action=';

function md2h(t){
    return t.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/\*\*(.+?)\*\*/g,'<strong>$1</strong>')
        .replace(/^#{1,3}\s+(.+)$/gm,'<h3 style="color:#c7d2fe;font-size:.88rem;margin:.75rem 0 .25rem;">$1</h3>')
        .replace(/\n/g,'<br>');
}
function setLoad(el){el.innerHTML='<div style="display:flex;align-items:center;"><div class="spinner"></div><span style="color:#a5b4fc;font-style:italic;">Consultando IA...</span></div>';}
function setRes(el,t){el.innerHTML=md2h(t);}
async function aiPost(act,extra={}){
    const b=new URLSearchParams({campaign_context:CTX,...extra});
    const r=await fetch(AIURL+act,{method:'POST',body:b});
    const d=await r.json(); return d.result||'❌ Erro.';
}

async function doAI(act){
    const m=document.getElementById('ai-main'),r=document.getElementById('ai-res');
    if(!m||!r)return; m.style.display='block'; setLoad(r);
    setRes(r,await aiPost(act));
}
async function quickAI(act){
    const w=document.getElementById('qai-wrap'),r=document.getElementById('qai-res');
    if(!w||!r)return; w.style.display='block'; setLoad(r);
    setRes(r,await aiPost(act));
    w.scrollIntoView({behavior:'smooth',block:'start'});
}
async function doCreate(){
    const w=document.getElementById('c-wrap'),r=document.getElementById('c-res');
    if(!w||!r)return; w.style.display='block'; setLoad(r);
    setRes(r,await aiPost('create',{
        objective:document.getElementById('c-obj')?.value||'',
        budget:document.getElementById('c-bud')?.value||'',
        product:document.getElementById('c-prod')?.value||'',
        extra:document.getElementById('c-ext')?.value||'',
    }));
    w.scrollIntoView({behavior:'smooth',block:'start'});
}
async function doCopy(){
    const w=document.getElementById('cp-wrap'),r=document.getElementById('cp-res');
    if(!w||!r)return; w.style.display='block'; setLoad(r);
    setRes(r,await aiPost('copy',{
        product:document.getElementById('cp-prod')?.value||'',
        tone:document.getElementById('cp-tone')?.value||'',
        format:document.getElementById('cp-fmt')?.value||'',
    }));
    w.scrollIntoView({behavior:'smooth',block:'start'});
}
async function doNiche(topic){
    const w=document.getElementById('ni-wrap'),r=document.getElementById('ni-res');
    if(!w||!r)return; w.style.display='block'; setLoad(r);
    setRes(r,await aiPost('niche',{topic}));
    w.scrollIntoView({behavior:'smooth',block:'start'});
}
function cpTxt(id){
    const t=document.getElementById(id)?.innerText||'';
    navigator.clipboard.writeText(t).then(()=>{
        const el=document.createElement('div');
        el.textContent='📋 Copiado!';
        el.style='position:fixed;bottom:1.5rem;right:1.5rem;background:#1e1b4b;color:#c7d2fe;padding:.6rem 1.1rem;border-radius:9px;font-size:.81rem;font-weight:700;z-index:9999;';
        document.body.appendChild(el); setTimeout(()=>el.remove(),2200);
    });
}
</script>

<?php include __DIR__ . '/views/partials/footer.php'; ?>
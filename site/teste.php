<?php
/* ─────────────────────────────────────────────
   VIP Turismo Paris — verificação da instalação

   Envie este ficheiro para a mesma pasta dos outros
   e abra: https://vipturismoparis.com/teste.php

   Apague-o quando terminar.
   ───────────────────────────────────────────── */

$dir = __DIR__;

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$host  = $_SERVER['HTTP_HOST'] ?? '';
$pasta = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
$base  = ($https ? 'https://' : 'http://') . $host . $pasta;

/* ── Verificações ── */
$itens = [];

$itens[] = [
  'nome' => 'PHP a funcionar',
  'ok'   => true,
  'nota' => 'Versão ' . PHP_VERSION,
];

foreach ([
  'r.php'            => 'Gera a pré-visualização do link no WhatsApp',
  'api.php'          => 'Guarda os roteiros e devolve o código curto',
  'roteiro.html'     => 'Página que o cliente abre',
  'confirmado.html'  => 'Página após a confirmação',
  'gerador.html'     => 'Painel interno',
] as $f => $para) {
  $existe = file_exists("$dir/$f");
  $itens[] = [
    'nome' => $f,
    'ok'   => $existe,
    'nota' => $existe ? $para : "FALTA — envie o $f para esta pasta",
  ];
}

/* imagem da pré-visualização: existe, é um JPEG válido e tem o tamanho certo? */
$fimg = "$dir/img/preview.jpg";
if (!file_exists($fimg)) {
  $itens[] = [
    'nome' => 'img/preview.jpg',
    'ok'   => false,
    'nota' => 'FALTA — crie a pasta img/ e coloque lá o preview.jpg',
  ];
} else {
  $bytes = filesize($fimg);
  $info  = @getimagesize($fimg);
  if (!$info || ($info[2] ?? 0) !== IMAGETYPE_JPEG) {
    $itens[] = [
      'nome' => 'img/preview.jpg',
      'ok'   => false,
      'nota' => 'FICHEIRO DANIFICADO — reenvie por FTP em modo binário, '
              . 'ou pelo Gestor de Ficheiros do cPanel',
    ];
  } else {
    [$lg, $al] = $info;
    $bom = $lg >= 600 && $al >= 315 && $bytes < 5000000;
    $itens[] = [
      'nome' => 'img/preview.jpg',
      'ok'   => $bom,
      'nota' => $bom
        ? "JPEG válido · {$lg}×{$al} px · " . round($bytes / 1024) . ' KB'
        : "JPEG {$lg}×{$al} px — o WhatsApp precisa de pelo menos 600×315 px "
        . 'e menos de 5 MB',
    ];
  }
}

/* pasta de dados */
$dados = "$dir/dados";
if (is_dir($dados)) {
  $grava = is_writable($dados);
  $n = count(glob("$dados/*.json") ?: []);
  $itens[] = [
    'nome' => 'pasta dados/',
    'ok'   => $grava,
    'nota' => $grava
      ? "Gravável · $n roteiro(s) guardado(s)"
      : 'SEM PERMISSÃO DE ESCRITA — no cPanel, defina a permissão da pasta para 755',
  ];
  $itens[] = [
    'nome' => 'dados/.htaccess',
    'ok'   => file_exists("$dados/.htaccess"),
    'nota' => file_exists("$dados/.htaccess")
      ? 'Pasta protegida contra acesso pelo navegador'
      : 'Sem protecção — será criado quando gerar o próximo roteiro',
  ];
} else {
  $podeCriar = is_writable($dir);
  $itens[] = [
    'nome' => 'pasta dados/',
    'ok'   => $podeCriar,
    'nota' => $podeCriar
      ? 'Ainda não existe — será criada quando gerar o primeiro roteiro'
      : 'A pasta do site não permite escrita. No cPanel, defina a permissão para 755',
  ];
}

$itens[] = [
  'nome' => 'HTTPS',
  'ok'   => $https,
  'nota' => $https
    ? 'Activo — a pré-visualização pode aparecer'
    : 'Sem HTTPS: o WhatsApp não mostra pré-visualização. Active o AutoSSL no cPanel',
];

/* configuração do aviso — lida do api.php sem o executar */
$cfg = @file_get_contents("$dir/api.php") ?: '';
function constDo($src, $nome) {
  return preg_match("/const\s+$nome\s*=\s*'([^']*)'/", $src, $m) ? $m[1] : null;
}
$metaOk  = constDo($cfg, 'META_TOKEN') && constDo($cfg, 'META_PHONE_ID');
$zapiOk  = constDo($cfg, 'ZAPI_INSTANCIA') && constDo($cfg, 'ZAPI_TOKEN');
$emailOk = (bool) constDo($cfg, 'AVISO_EMAIL');
$numAviso = constDo($cfg, 'AVISO_WHATSAPP');
$modelo   = constDo($cfg, 'META_MODELO');

if ($metaOk) {
  $via = 'WhatsApp pelo Meta para ' . $numAviso
       . ($modelo ? ' · modelo "' . $modelo . '"' : ' · sem modelo, só dentro das 24h');
} elseif ($zapiOk) {
  $via = 'WhatsApp pela Z-API para ' . $numAviso;
} elseif ($emailOk) {
  $via = 'Apenas por e-mail. Para o WhatsApp, preencha META_TOKEN e META_PHONE_ID no api.php';
} else {
  $via = 'DESLIGADO — preencha META_TOKEN/META_PHONE_ID, ZAPI_*, ou AVISO_EMAIL no api.php';
}
if (($metaOk || $zapiOk) && $emailOk) $via .= ' + e-mail';

$itens[] = [
  'nome' => 'Aviso de confirmação',
  'ok'   => $metaOk || $zapiOk || $emailOk,
  'nota' => $via,
];

$falhas = count(array_filter($itens, fn($i) => !$i['ok']));

/* últimos roteiros, para testar o link */
$ultimos = [];
foreach (array_slice(array_reverse(glob("$dados/*.json") ?: []), 0, 5) as $f) {
  $reg = json_decode(@file_get_contents($f), true);
  if (is_array($reg) && !empty($reg['roteiro'])) {
    $ultimos[] = [
      'id'      => basename($f, '.json'),
      'cliente' => $reg['roteiro']['cliente']['nome'] ?? '—',
      'status'  => $reg['status'] ?? 'pendente',
    ];
  }
}

function e($t) { return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8'); }
?><!DOCTYPE html>
<html lang="pt-PT">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Verificação — VIP Turismo Paris</title>
<meta name="robots" content="noindex,nofollow">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#F0EDE6;--surface:#fff;--ink:#1C1C28;--ink-2:#6B6660;--gilt:#B89448;
      --border:#DDD9D0;--ok:#2a8a4a;--erro:#c44}
@media(prefers-color-scheme:dark){:root{--bg:#151921;--surface:#1C2232;--ink:#E4E0D8;
      --ink-2:#8A8480;--gilt:#C9A96E;--border:#252D42;--ok:#3aaa5a;--erro:#e06060}}
body{font-family:system-ui,-apple-system,sans-serif;background:var(--bg);color:var(--ink);
     line-height:1.6;padding:32px 20px 60px}
.wrap{max-width:660px;margin:0 auto}
.marca{font-size:10px;font-weight:700;letter-spacing:3px;text-transform:uppercase;
       color:var(--gilt);margin-bottom:6px}
h1{font-size:24px;font-weight:600;margin-bottom:20px;letter-spacing:-.2px}
.resumo{padding:16px 18px;border-radius:8px;margin-bottom:24px;font-size:15px;
        border:1px solid var(--border);background:var(--surface)}
.resumo.bom{border-color:color-mix(in srgb,var(--ok) 40%,transparent)}
.resumo.mau{border-color:color-mix(in srgb,var(--erro) 40%,transparent)}
.resumo b{display:block;margin-bottom:3px}
.resumo.bom b{color:var(--ok)}
.resumo.mau b{color:var(--erro)}
.lista{background:var(--surface);border:1px solid var(--border);border-radius:8px;
       overflow:hidden;margin-bottom:24px}
.item{display:flex;gap:13px;padding:13px 16px;border-bottom:1px solid var(--border);
      align-items:flex-start}
.item:last-child{border-bottom:none}
.sinal{flex-shrink:0;width:20px;height:20px;border-radius:50%;display:flex;
       align-items:center;justify-content:center;font-size:12px;font-weight:700;
       color:#fff;margin-top:2px}
.sinal.s{background:var(--ok)}
.sinal.n{background:var(--erro)}
.txt{min-width:0}
.txt b{font-size:14px;font-weight:700;display:block}
.txt span{font-size:13px;color:var(--ink-2)}
h2{font-size:11px;font-weight:700;letter-spacing:2px;text-transform:uppercase;
   color:var(--ink-2);margin:0 0 12px}
.caixa{background:var(--surface);border:1px solid var(--border);border-radius:8px;
       padding:16px;margin-bottom:24px;font-size:13px}
.caixa p{margin-bottom:8px;color:var(--ink-2)}
.caixa p:last-child{margin-bottom:0}
code{font-family:ui-monospace,Menlo,monospace;font-size:12px;background:var(--bg);
     padding:2px 6px;border-radius:4px;border:1px solid var(--border);
     word-break:break-all;color:var(--ink)}
a{color:var(--gilt)}
.rodape{font-size:12px;color:var(--ink-2);border-top:1px solid var(--border);
        padding-top:16px;line-height:1.8}
</style>
</head>
<body>
<div class="wrap">
  <div class="marca">VIP Turismo Paris</div>
  <h1>Verificação da instalação</h1>

  <?php if ($falhas === 0): ?>
    <div class="resumo bom">
      <b>Está tudo no sítio certo.</b>
      Se um link ainda der 404, confirme que está a usar o endereço indicado abaixo.
    </div>
  <?php else: ?>
    <div class="resumo mau">
      <b><?= $falhas ?> <?= $falhas === 1 ? 'problema encontrado' : 'problemas encontrados' ?></b>
      Veja abaixo o que falta — cada linha a vermelho diz o que fazer.
    </div>
  <?php endif; ?>

  <h2>Ficheiros e permissões</h2>
  <div class="lista">
    <?php foreach ($itens as $i): ?>
      <div class="item">
        <span class="sinal <?= $i['ok'] ? 's' : 'n' ?>"><?= $i['ok'] ? '&check;' : '!' ?></span>
        <span class="txt">
          <b><?= e($i['nome']) ?></b>
          <span><?= e($i['nota']) ?></span>
        </span>
      </div>
    <?php endforeach; ?>
  </div>

  <h2>Endereços deste servidor</h2>
  <div class="caixa">
    <p>Pasta no servidor:<br><code><?= e($dir) ?></code></p>
    <p>Endereço desta pasta:<br><code><?= e($base) ?></code></p>
    <p>Os links dos roteiros devem começar por:<br><code><?= e($base) ?>/r.php?r=CODIGO</code></p>
    <?php if ($pasta !== ''): ?>
      <p><strong>Atenção:</strong> os ficheiros não estão na raiz do site, mas em
      <code><?= e($pasta) ?></code>. No <code>gerador.html</code>, o
      <code>BASE_URL</code> tem de ser <code><?= e($base) ?></code>.</p>
    <?php endif; ?>
  </div>

  <?php $v = file_exists($fimg) ? '?v=' . filemtime($fimg) : ''; ?>
  <h2>A imagem como o WhatsApp a vai buscar</h2>
  <div class="caixa">
    <p>Se o quadro abaixo aparecer vazio ou partido, o WhatsApp também não a consegue ler.</p>
    <img src="<?= e($base . '/img/preview.jpg' . $v) ?>" alt="Pré-visualização"
         style="width:100%;border-radius:6px;border:1px solid var(--border);display:block;margin-top:10px">
    <p style="margin-top:10px">Endereço declarado ao WhatsApp:<br>
      <code><?= e($base . '/img/preview.jpg' . $v) ?></code></p>
    <p>O número no fim muda sempre que substituir a imagem, para o WhatsApp
      não continuar a mostrar a versão antiga guardada em cache.</p>
  </div>

  <h2>Testar o aviso de confirmação</h2>
  <div class="caixa">
    <p>Envia um aviso de exemplo, para confirmar que chega antes de um cliente real confirmar.</p>
    <?php if (!$metaOk && !$zapiOk): ?>
      <details style="margin:12px 0;padding:12px;background:var(--bg);border:1px solid var(--border);border-radius:6px">
        <summary style="cursor:pointer;font-weight:700;color:var(--ink)">Como ligar o WhatsApp oficial do Meta</summary>
        <p style="margin-top:10px">1. Em <a href="https://business.facebook.com" target="_blank" rel="noopener">business.facebook.com</a>,
          abra <b>Ferramentas para programadores</b> → a sua app → <b>WhatsApp → Configuração da API</b>.</p>
        <p>2. Copie o <b>ID do número de telefone</b> para <code>META_PHONE_ID</code> e gere um
          <b>token de acesso permanente</b> para <code>META_TOKEN</code>. O token temporário
          da página expira em 24 horas — não serve.</p>
        <p>3. Em <b>Gestor do WhatsApp → Modelos de mensagens</b>, crie um modelo:</p>
        <p style="margin-left:12px">Nome: <code>roteiro_confirmado</code><br>
          Categoria: <b>Utilidade</b><br>
          Idioma: Português (Portugal) → <code>pt_PT</code>, ou Brasil → <code>pt_BR</code></p>
        <p style="margin-left:12px">Corpo, exactamente com as quatro variáveis:</p>
        <p style="margin-left:12px"><code style="display:block;padding:10px;line-height:1.9;white-space:pre-wrap">Roteiro confirmado.
Cliente: {{1}}
Viagem: {{2}}
Referência: {{3}}
Ver: {{4}}</code></p>
        <p>4. A aprovação costuma demorar minutos. Depois preencha <code>META_MODELO</code>
          com o nome do modelo e <code>META_IDIOMA</code> com o código do idioma.</p>
        <p style="color:var(--ink-2)">O Meta só permite texto livre nas 24 horas seguintes a uma
          mensagem do destinatário. Como este aviso é automático, o modelo é obrigatório.</p>
      </details>
    <?php endif; ?>
    <div style="display:flex;gap:8px;margin-top:10px;flex-wrap:wrap">
      <input id="senhaTeste" type="password" placeholder="Senha do painel"
             style="flex:1;min-width:160px;padding:9px 12px;border:1px solid var(--border);
                    border-radius:6px;background:var(--bg);color:var(--ink);font:inherit;font-size:13px">
      <button onclick="testarAviso()"
              style="padding:9px 18px;border:none;border-radius:6px;background:var(--gilt);
                     color:#fff;font:inherit;font-size:13px;font-weight:700;cursor:pointer">Enviar teste</button>
    </div>
    <p id="resTeste" style="margin-top:10px;min-height:20px"></p>
  </div>

  <script>
  async function testarAviso(){
    const el=document.getElementById('resTeste');
    const senha=document.getElementById('senhaTeste').value;
    if(!senha){el.textContent='Escreva a senha do painel.';return;}
    el.textContent='A enviar...';
    try{
      const r=await fetch('api.php?acao=testar-aviso',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({senha}),
      });
      const j=await r.json();
      if(j.erro){el.textContent=j.erro;return;}
      const w=j.resultado.whatsapp, m=j.resultado.email;
      el.innerHTML=
        'WhatsApp: '+(w.ok?'<b>enviado</b>':'não enviado — '+w.erro)+'<br>'+
        'E-mail: '+(m.ok?'<b>enviado</b>':'não enviado — '+m.erro);
    }catch(e){el.textContent='Falha ao contactar o api.php: '+e.message;}
  }
  </script>

  <?php if ($ultimos): ?>
    <h2>Últimos roteiros — clique para testar</h2>
    <div class="lista">
      <?php foreach ($ultimos as $u): ?>
        <div class="item">
          <span class="sinal <?= $u['status'] === 'confirmado' ? 's' : 'n' ?>"><?= $u['status'] === 'confirmado' ? '&check;' : '·' ?></span>
          <span class="txt">
            <b><?= e($u['cliente']) ?></b>
            <span><a href="<?= e($base) ?>/r.php?r=<?= e($u['id']) ?>" target="_blank"><?= e($base) ?>/r.php?r=<?= e($u['id']) ?></a></span>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <p class="rodape">
    Depois de resolver, apague este ficheiro do servidor.<br>
    Para ver a pré-visualização actualizada no WhatsApp, teste o link no
    <a href="https://developers.facebook.com/tools/debug/" target="_blank" rel="noopener">depurador do Facebook</a>.
  </p>
</div>
</body>
</html>

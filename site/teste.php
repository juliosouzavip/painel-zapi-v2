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

$img = file_exists("$dir/img/preview.jpg");
$itens[] = [
  'nome' => 'img/preview.jpg',
  'ok'   => $img,
  'nota' => $img
    ? 'Imagem da pré-visualização encontrada'
    : 'FALTA — crie a pasta img/ e coloque lá o preview.jpg',
];

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

<?php
/**
 * TESTE DE MODELO — mostra o que sai e o que a Meta responde.
 *
 * A mensagem de erro da Meta (132012) não diz onde está o problema.
 * Aqui vemos os dois lados: o pacote exato que enviamos e a resposta
 * crua. Sem isto, corrigir vira adivinhação.
 *
 * APAGUE ESTE ARQUIVO quando os modelos estiverem a funcionar.
 */

require_once __DIR__ . '/lib.php';

header('Cache-Control: no-store');
$eu = exigirLoginPagina();

$modelo   = trim((string) ($_POST['modelo'] ?? 'voucher_pronto'));
$telefone = trim((string) ($_POST['telefone'] ?? ''));
$v1       = trim((string) ($_POST['v1'] ?? 'Julio'));
$v2       = trim((string) ($_POST['v2'] ?? 'Paris'));

$enviado = null;
$resposta = null;
$estrutura = null;

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $telefone !== '') {

    // 1. o que a Meta diz sobre este modelo
    $r = chamarMeta(WA_WABA_ID . '/message_templates?name=' . urlencode($modelo) . '&limit=10',
                    null, 'GET');
    $estrutura = $r['ok'] ? ($r['dados']['data'] ?? []) : ['erro' => $r['erro'] ?? '?'];

    // 2. monta o mesmo pacote que o sistema monta
    $formato = formatoDoModelo($modelo, 'pt_BR');
    $variaveis = [$v1, $v2];
    $componentes = [];

    $tipoCab = $formato['cabecalhoTipo'] ?? '';
    if ($tipoCab === 'IMAGE' && defined('MODELO_IMAGEM') && MODELO_IMAGEM !== '') {
        $componentes[] = ['type' => 'header', 'parameters' => [
            ['type' => 'image', 'image' => ['link' => MODELO_IMAGEM]],
        ]];
    }

    $parametros = [];
    if ($formato && $formato['nomeadas']) {
        foreach ($formato['nomeadas'] as $i => $nome) {
            $parametros[] = ['type' => 'text', 'parameter_name' => $nome,
                             'text' => (string) ($variaveis[$i] ?? '')];
        }
    } else {
        foreach ($variaveis as $v) {
            $parametros[] = ['type' => 'text', 'text' => (string) $v];
        }
    }
    $componentes[] = ['type' => 'body', 'parameters' => $parametros];

    $corpo = [
        'messaging_product' => 'whatsapp',
        'to'                => normalizarTelefone($telefone),
        'type'              => 'template',
        'template'          => [
            'name'       => $modelo,
            'language'   => ['code' => 'pt_BR'],
            'components' => $componentes,
        ],
    ];

    $enviado = $corpo;

    // 3. envia de verdade e guarda a resposta crua
    $resposta = chamarMeta(WA_PHONE_NUMBER_ID . '/messages', $corpo);
}
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Teste de modelo</title>
<style>
body{font-family:system-ui,sans-serif;max-width:900px;margin:26px auto;padding:0 16px;
  color:#0F1B2D;line-height:1.55;font-size:14px}
h1{font-size:21px;margin-bottom:3px}
.sub{color:#7E899B;margin-top:0;font-size:13px}
form{background:#fff;border:1px solid #D8DDE6;border-radius:11px;padding:16px 18px;
  margin:18px 0;display:grid;gap:12px}
label{font-size:10.5px;font-weight:700;letter-spacing:.07em;text-transform:uppercase;
  color:#7E899B;display:block;margin-bottom:4px}
input,select{width:100%;padding:10px 12px;border:1px solid #D8DDE6;border-radius:8px;
  font-family:inherit;font-size:14px}
.dois{display:grid;grid-template-columns:1fr 1fr;gap:12px}
button{background:#0F1B2D;color:#fff;border:0;border-radius:8px;padding:11px 20px;
  font-family:inherit;font-size:14.5px;font-weight:600;cursor:pointer;justify-self:start}
.cx{background:#fff;border:1px solid #D8DDE6;border-radius:11px;padding:15px 17px;
  margin:14px 0}
.cx.ok{border-color:#A9D3C1;background:#F2F9F6}
.cx.mal{border-color:#E3B6B0;background:#FDF4F3}
.cx b{display:block;font-size:10.5px;letter-spacing:.08em;text-transform:uppercase;
  color:#7E899B;margin-bottom:8px}
pre{margin:0;font-family:ui-monospace,monospace;font-size:12px;line-height:1.5;
  white-space:pre-wrap;word-break:break-word;color:#48566E;max-height:420px;overflow:auto}
.res{font-size:15px;font-weight:600}
.ok .res{color:#136047}
.mal .res{color:#A0261C}
a{color:#93691F}
</style>
</head>
<body>

<h1>Teste de modelo</h1>
<p class="sub">Mostra o pacote exato que sai daqui e a resposta crua da Meta.</p>

<form method="post">
  <div>
    <label for="modelo">Modelo</label>
    <input id="modelo" name="modelo" value="<?= escapar($modelo) ?>">
  </div>
  <div>
    <label for="telefone">Enviar para (com código do país)</label>
    <input id="telefone" name="telefone" value="<?= escapar($telefone) ?>"
           placeholder="351910824661" required>
  </div>
  <div class="dois">
    <div>
      <label for="v1">Campo 1</label>
      <input id="v1" name="v1" value="<?= escapar($v1) ?>">
    </div>
    <div>
      <label for="v2">Campo 2</label>
      <input id="v2" name="v2" value="<?= escapar($v2) ?>">
    </div>
  </div>
  <button type="submit">Enviar e mostrar tudo</button>
</form>

<?php if ($resposta !== null): ?>

  <div class="cx <?= $resposta['ok'] ? 'ok' : 'mal' ?>">
    <b>Resultado</b>
    <span class="res"><?= $resposta['ok'] ? 'Enviado' : 'Recusado' ?></span>
    <?php if (!$resposta['ok']): ?>
      <pre><?= escapar((string) ($resposta['erro'] ?? '')) ?></pre>
    <?php endif; ?>
  </div>

  <div class="cx">
    <b>Resposta crua da Meta</b>
    <pre><?= escapar(json_encode($resposta['dados'] ?? $resposta,
              JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
  </div>

  <div class="cx">
    <b>O que enviámos</b>
    <pre><?= escapar(json_encode($enviado,
              JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
  </div>

  <div class="cx">
    <b>Como o modelo está aprovado na Meta</b>
    <pre><?= escapar(json_encode($estrutura,
              JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></pre>
  </div>

<?php endif; ?>

<p><a href="modelos.php">Ver todos os modelos</a> · <a href="conversas.php">Voltar ao painel</a></p>

</body>
</html>

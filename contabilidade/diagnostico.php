<?php
// =====================================================================
// DIAGNÓSTICO — tabela `movimentos`   ·  VIP Turismo Paris Lda
// =====================================================================
//
// ESTE FICHEIRO NÃO ALTERA NADA. Só lê e mostra.
// Não apaga, não insere, não corrige. Podes correr à vontade.
//
// PARA QUE SERVE
//   Responder a uma pergunta que nunca foi verificada: a coluna `id` da
//   tabela `movimentos` tem mesmo uma chave ÚNICA?
//
//   Isto é decisivo porque a app, sempre que gravas o que quer que seja
//   (categoria, ligar fatura, "sem fatura"), reenvia os ~1500 movimentos
//   TODOS para o servidor de uma vez, através de saveMovimentos.
//   Isso só é seguro se o MySQL conseguir dizer "este id já existe, é
//   uma atualização". Sem chave única, cada gravação insere tudo de novo.
//
//   Se for este o caso, os duplicados não vêm de importares o extrato.
//   Vêm de USARES a aplicação.
//
// COMO USAR
//   1. Muda a CHAVE aqui em baixo.
//   2. Põe em public_html/contabilidade/
//   3. https://vipturismoparis.com/contabilidade/diagnostico.php?chave=ATUA
//   4. Manda-me o que aparece (um screenshot chega).
// =====================================================================

const CHAVE = 'vipturismo';   // <<<<<< MUDA ESTA PALAVRA

require_once __DIR__ . '/../../config/config.php';

if (($_GET['chave'] ?? '') !== CHAVE) {
    http_response_code(403);
    exit('Acesso negado. Falta ?chave=... no endereço.');
}

$pdo = db();

// ---------------------------------------------------------------------
// 1. Estrutura da tabela
// ---------------------------------------------------------------------
$createSql = '';
try {
    $r = $pdo->query("SHOW CREATE TABLE movimentos")->fetch(PDO::FETCH_NUM);
    $createSql = $r[1] ?? '';
} catch (Throwable $e) { $createSql = 'ERRO: ' . $e->getMessage(); }

$indices = [];
try {
    $indices = $pdo->query("SHOW INDEX FROM movimentos")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// A pergunta que interessa: existe índice ÚNICO que cubra só a coluna `id`?
$idUnico = false;
$porNome = [];
foreach ($indices as $ix) $porNome[$ix['Key_name']][] = $ix;
foreach ($porNome as $nome => $cols) {
    $unico = ((string)$cols[0]['Non_unique'] === '0');
    $nomes = array_column($cols, 'Column_name');
    if ($unico && count($nomes) === 1 && strtolower($nomes[0]) === 'id') $idUnico = true;
}

// ---------------------------------------------------------------------
// 2. Formato dos IDs — diz-me se o index.html novo já está a ser usado
// ---------------------------------------------------------------------
$formatos = ['conta_'=>0, 'cartao_'=>0, 'mov_'=>0, 'outro'=>0];
$total = 0;
$st = $pdo->query("SELECT id FROM movimentos");
while ($row = $st->fetch(PDO::FETCH_NUM)) {
    $id = (string)$row[0]; $total++;
    if (strpos($id,'conta_')===0)       $formatos['conta_']++;
    elseif (strpos($id,'cartao_')===0)  $formatos['cartao_']++;
    elseif (strpos($id,'mov_')===0)     $formatos['mov_']++;
    else                                $formatos['outro']++;
}

// IDs repetidos na própria coluna (só possível se NÃO houver chave única)
$idsRepetidos = $pdo->query(
    "SELECT id, COUNT(*) n FROM movimentos GROUP BY id HAVING n > 1 ORDER BY n DESC LIMIT 20"
)->fetchAll(PDO::FETCH_ASSOC);
$totalIdsRepetidos = (int)$pdo->query(
    "SELECT COUNT(*) FROM (SELECT id FROM movimentos GROUP BY id HAVING COUNT(*)>1) t"
)->fetchColumn();

// ---------------------------------------------------------------------
// 3. Mês a mês
// ---------------------------------------------------------------------
$meses = $pdo->query(
    "SELECT DATE_FORMAT(data,'%Y-%m') mes, conta, COUNT(*) n,
            SUM(status='conciliado') conc
       FROM movimentos GROUP BY mes, conta ORDER BY mes DESC, conta"
)->fetchAll(PDO::FETCH_ASSOC);

// ---------------------------------------------------------------------
// 4. Grupos repetidos por conteúdo
// ---------------------------------------------------------------------
$dups = $pdo->query(
    "SELECT DATE(data) d, descricao, valor, conta, tipo, COUNT(*) n
       FROM movimentos
      GROUP BY d, descricao, valor, conta, tipo
     HAVING n > 1
     ORDER BY n DESC, d DESC
     LIMIT 25"
)->fetchAll(PDO::FETCH_ASSOC);

$distribuicao = $pdo->query(
    "SELECT n, COUNT(*) grupos FROM (
        SELECT COUNT(*) n FROM movimentos
         GROUP BY DATE(data), descricao, valor, conta, tipo
     ) t GROUP BY n ORDER BY n"
)->fetchAll(PDO::FETCH_ASSOC);

function h($s){ return htmlspecialchars((string)$s); }
?>
<!DOCTYPE html><html lang="pt"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Diagnóstico · VIP Turismo Paris</title>
<style>
 body{font-family:system-ui,-apple-system,sans-serif;max-width:1050px;margin:36px auto;
      padding:0 20px;color:#0f1a28;background:#fafaf9}
 h1{color:#0a1f3d;margin-bottom:4px} h2{color:#0a1f3d;font-size:15px;margin-top:36px;
   text-transform:uppercase;letter-spacing:.5px}
 .sub{color:#78716c;font-size:14px;margin-bottom:26px}
 table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;
   overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08);margin-bottom:14px}
 th{background:#0a1f3d;color:#c8971b;padding:10px;text-align:left;font-size:11px;
    letter-spacing:.4px;text-transform:uppercase}
 td{padding:8px 10px;border-bottom:1px solid #f0efed;font-size:13px}
 .num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
 pre{background:#0a1f3d;color:#e8eef7;padding:18px;border-radius:10px;overflow-x:auto;
   font-size:12px;line-height:1.55}
 .veredicto{padding:20px 24px;border-radius:10px;margin:20px 0;line-height:1.75;font-size:15px}
 .mau{background:#fdecea;border-left:5px solid #b42318}
 .bom{background:#d8f5e8;border-left:5px solid #046a4e}
 .cards{display:flex;gap:14px;margin:20px 0;flex-wrap:wrap}
 .card{flex:1;min-width:130px;background:#fff;padding:16px;border-radius:10px;
   box-shadow:0 1px 3px rgba(0,0,0,.08)}
 .card .lbl{font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#78716c}
 .card .val{font-size:23px;font-weight:700;margin-top:5px;color:#0a1f3d}
 code{background:#eef1f5;padding:2px 6px;border-radius:4px;font-size:12px}
 small{color:#5b6879}
</style></head><body>

<h1>Diagnóstico da tabela <code>movimentos</code></h1>
<div class="sub">Só leitura — este ficheiro não altera nada · VIP Turismo Paris Lda</div>

<h2>1. A pergunta decisiva</h2>
<?php if ($idUnico): ?>
  <div class="veredicto bom">
    <b>A coluna <code>id</code> TEM chave única.</b><br>
    Então a causa dos duplicados não é esta — cada gravação está mesmo a
    atualizar em vez de inserir. Nesse caso o problema está nos IDs serem
    diferentes a cada vez, ou na <code>api.php</code>.
  </div>
<?php else: ?>
  <div class="veredicto mau">
    <b>A coluna <code>id</code> NÃO tem chave única.</b><br><br>
    É esta a causa. Sem chave única, o <code>INSERT ... ON DUPLICATE KEY UPDATE</code>
    nunca dispara: o MySQL não tem como saber que aquele id já lá está, por isso
    <b>insere uma linha nova</b>.<br><br>
    E como a app reenvia os <?=$total?> movimentos todos de cada vez que gravas
    qualquer coisa, os duplicados não vêm de importares o extrato —
    <b>vêm de usares a aplicação</b>. Cada categoria que mudas, cada fatura que
    ligas, volta a inserir tudo.<br><br>
    É por isso que apagar e reenviar nunca resolveu.
  </div>
<?php endif; ?>

<?php if ($totalIdsRepetidos > 0): ?>
  <div class="veredicto mau" style="font-size:14px">
    <b>Prova directa:</b> há <?=$totalIdsRepetidos?> IDs que aparecem mais do que
    uma vez na tabela. Com uma chave única isto seria impossível.
  </div>
  <table><thead><tr><th>ID repetido</th><th class="num">Vezes</th></tr></thead><tbody>
  <?php foreach ($idsRepetidos as $r): ?>
    <tr><td><code><?=h($r['id'])?></code></td><td class="num"><?=$r['n']?></td></tr>
  <?php endforeach; ?>
  </tbody></table>
<?php endif; ?>

<h2>2. Estrutura da tabela</h2>
<pre><?=h($createSql)?></pre>

<h2>3. Índices</h2>
<table><thead><tr><th>Nome</th><th>Coluna</th><th>Único?</th><th>Tipo</th></tr></thead><tbody>
<?php foreach ($indices as $ix): ?>
  <tr><td><code><?=h($ix['Key_name'])?></code></td>
      <td><?=h($ix['Column_name'])?></td>
      <td><?=((string)$ix['Non_unique']==='0') ? '<b style="color:#046a4e">SIM</b>' : 'não'?></td>
      <td><small><?=h($ix['Index_type'])?></small></td></tr>
<?php endforeach; ?>
<?php if (!$indices): ?><tr><td colspan="4">Nenhum índice. Isto explica tudo.</td></tr><?php endif; ?>
</tbody></table>

<h2>4. Formato dos IDs</h2>
<div class="cards">
  <div class="card"><div class="lbl">Total</div><div class="val"><?=$total?></div></div>
  <div class="card"><div class="lbl">conta_ (novo)</div><div class="val"><?=$formatos['conta_']?></div></div>
  <div class="card"><div class="lbl">cartao_ (novo)</div><div class="val"><?=$formatos['cartao_']?></div></div>
  <div class="card"><div class="lbl">mov_ (antigo)</div><div class="val"><?=$formatos['mov_']?></div></div>
  <div class="card"><div class="lbl">outro</div><div class="val"><?=$formatos['outro']?></div></div>
</div>
<p><small>Se aqui só houver <code>mov_</code>, o <code>index.html</code> novo ainda não
está a ser usado — ou o browser está a servir a versão em cache.</small></p>

<h2>5. Quantas cópias por grupo</h2>
<table><thead><tr><th>Linhas iguais no grupo</th><th class="num">Nº de grupos</th></tr></thead><tbody>
<?php foreach ($distribuicao as $d): ?>
  <tr><td><?=$d['n']?>×</td><td class="num"><?=$d['grupos']?></td></tr>
<?php endforeach; ?>
</tbody></table>
<p><small>Se quase todos os grupos forem exactamente 2×, houve uma duplicação
global. Se os números forem irregulares (2, 3, 5…), foram várias gravações
sucessivas — o que aponta para a falta de chave única.</small></p>

<h2>6. Mês a mês</h2>
<table><thead><tr><th>Mês</th><th>Conta</th><th class="num">Linhas</th>
<th class="num">Conciliados</th></tr></thead><tbody>
<?php foreach ($meses as $m): ?>
  <tr><td><?=h($m['mes'])?></td><td><?=h($m['conta'] ?: '(vazio)')?></td>
      <td class="num"><?=$m['n']?></td><td class="num"><small><?=$m['conc']?></small></td></tr>
<?php endforeach; ?>
</tbody></table>

<h2>7. Os 25 grupos mais repetidos</h2>
<table><thead><tr><th>Data</th><th>Descrição</th><th class="num">Valor</th>
<th>Conta</th><th class="num">Cópias</th></tr></thead><tbody>
<?php foreach ($dups as $d): ?>
  <tr><td><?=h($d['d'])?></td>
      <td><?=h(mb_substr($d['descricao'],0,44))?></td>
      <td class="num"><?=number_format((float)$d['valor'],2,',','.')?> €</td>
      <td><small><?=h($d['conta'])?></small></td>
      <td class="num"><b><?=$d['n']?></b></td></tr>
<?php endforeach; ?>
</tbody></table>

</body></html>
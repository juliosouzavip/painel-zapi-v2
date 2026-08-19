<?php
// =====================================================================
// DUPLICADOS — v2  ·  VIP Turismo Paris Lda
// =====================================================================
//
// O QUE MUDOU EM RELAÇÃO À v1
//   A v1 tentava distinguir a cópia do original pelo ID. Isso não
//   funciona: TODOS os movimentos da base têm ID `mov_` aleatório,
//   não há originais "bons" para comparar.
//
//   Como duas compras legítimas iguais no mesmo dia são impossíveis de
//   distinguir de um duplicado só pelo conteúdo, esta versão faz outra
//   coisa: mostra o estado mês a mês e deixa-te apagar UM MÊS INTEIRO,
//   para depois reimportares esse mês uma única vez a partir do extrato
//   do banco — que é a única fonte de verdade fiável.
//
// ⚠️ ORDEM OBRIGATÓRIA
//   1º corrige os IDs no index.html (ficheiro CORRECOES_index_html.md)
//   2º só depois apaga o mês aqui
//   3º importa o extrato desse mês UMA vez
//   Se apagares antes de corrigir os IDs, os duplicados voltam.
//
// COMO USAR
//   1. Muda a CHAVE aqui em baixo.
//   2. Põe em public_html/contabilidade/
//   3. https://vipturismoparis.com/contabilidade/duplicados.php?chave=ATUA
//   4. Apaga o ficheiro do servidor quando acabares.
// =====================================================================

const CHAVE = 'vipturismo';   // <<<<<< MUDA ESTA PALAVRA

require_once __DIR__ . '/../../config/config.php';

if (($_GET['chave'] ?? '') !== CHAVE) {
    http_response_code(403);
    exit('Acesso negado. Falta ?chave=... no endereço.');
}

$pdo  = db();
$acao = $_GET['acao'] ?? '';
$mes  = $_GET['mes']  ?? '';        // formato AAAA-MM

// ---------------------------------------------------------------------
// Backup — sempre antes de apagar
// ---------------------------------------------------------------------
$msgBackup = '';
if ($acao === 'backup' || $acao === 'apagarmes') {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS movimentos_backup LIKE movimentos");
        $pdo->exec("TRUNCATE TABLE movimentos_backup");
        $n = $pdo->exec("INSERT INTO movimentos_backup SELECT * FROM movimentos");
        $msgBackup = "Backup feito: $n linhas guardadas na tabela movimentos_backup.";
    } catch (Throwable $e) {
        exit('<p style="font-family:sans-serif;color:#b42318">Não consegui fazer o backup, '
           . 'por isso parei e não apaguei nada: ' . htmlspecialchars($e->getMessage()) . '</p>');
    }
}

// ---------------------------------------------------------------------
// Apagar um mês inteiro
// ---------------------------------------------------------------------
$apagados = 0;
if ($acao === 'apagarmes' && preg_match('/^\d{4}-\d{2}$/', $mes)) {
    $st = $pdo->prepare("DELETE FROM movimentos WHERE DATE_FORMAT(data, '%Y-%m') = ?");
    $st->execute([$mes]);
    $apagados = $st->rowCount();
}

// ---------------------------------------------------------------------
// Ler tudo
// ---------------------------------------------------------------------
$movs = $pdo->query(
    "SELECT id, data, descricao, tipo, valor, conta, status, categoria, fat_id
       FROM movimentos ORDER BY data DESC, descricao"
)->fetchAll(PDO::FETCH_ASSOC);

function chave($m) {
    return substr($m['data'], 0, 10) . '|' . mb_strtolower(trim($m['descricao'])) . '|'
         . number_format((float)$m['valor'], 2, '.', '') . '|'
         . ($m['conta'] ?: 'principal') . '|' . $m['tipo'];
}

$grupos = $porMes = [];
foreach ($movs as $m) {
    $grupos[chave($m)][] = $m;
    $mm = substr($m['data'], 0, 7);
    if (!isset($porMes[$mm])) $porMes[$mm] = ['linhas'=>0,'grupos'=>0,'copias'=>0,'conciliados'=>0];
    $porMes[$mm]['linhas']++;
    if ($m['status'] === 'conciliado') $porMes[$mm]['conciliados']++;
}
foreach ($grupos as $g) {
    if (count($g) < 2) continue;
    $mm = substr($g[0]['data'], 0, 7);
    $porMes[$mm]['grupos']++;
    $porMes[$mm]['copias'] += count($g) - 1;
}
krsort($porMes);

$totalLinhas = count($movs);
$totalCopias = 0; $totalGrupos = 0;
foreach ($grupos as $g) if (count($g) > 1) { $totalGrupos++; $totalCopias += count($g) - 1; }

$qs = 'chave=' . urlencode(CHAVE);
function eur($v){ return number_format((float)$v, 2, ',', '.') . ' €'; }
function nomeMes($m){
  $n = ['01'=>'janeiro','02'=>'fevereiro','03'=>'março','04'=>'abril','05'=>'maio','06'=>'junho',
        '07'=>'julho','08'=>'agosto','09'=>'setembro','10'=>'outubro','11'=>'novembro','12'=>'dezembro'];
  $p = explode('-', $m);
  return ($n[$p[1] ?? ''] ?? $m) . ' de ' . ($p[0] ?? '');
}
?>
<!DOCTYPE html><html lang="pt"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Duplicados · VIP Turismo Paris</title>
<style>
 body{font-family:system-ui,-apple-system,sans-serif;max-width:1100px;margin:40px auto;
      padding:0 20px;color:#0f1a28;background:#fafaf9}
 h1{color:#0a1f3d;margin-bottom:4px} h2{color:#0a1f3d;font-size:16px;margin-top:34px}
 .sub{color:#78716c;font-size:14px;margin-bottom:24px}
 table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;
   overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08);margin-bottom:14px}
 th{background:#0a1f3d;color:#c8971b;padding:10px;text-align:left;font-size:11px;
    letter-spacing:.4px;text-transform:uppercase}
 td{padding:9px 10px;border-bottom:1px solid #f0efed;font-size:13px;vertical-align:middle}
 .num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}
 .btn{display:inline-block;background:#b42318;color:#fff;padding:9px 15px;border-radius:8px;
   text-decoration:none;font-weight:600;font-size:13px}
 .btn.sec{background:#fff;color:#0a1f3d;border:1px solid #dde3ec;font-weight:500}
 .ok{background:#d8f5e8;border-left:4px solid #046a4e;padding:16px 20px;border-radius:6px;margin-bottom:22px;line-height:1.7}
 .aviso{background:#fdf6e3;border-left:4px solid #c8971b;padding:16px 20px;border-radius:6px;margin-bottom:22px;line-height:1.7}
 .perigo{background:#fdecea;border-left:4px solid #b42318;padding:16px 20px;border-radius:6px;margin-bottom:22px;line-height:1.7}
 .cards{display:flex;gap:14px;margin:22px 0;flex-wrap:wrap}
 .card{flex:1;min-width:150px;background:#fff;padding:18px;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
 .card .lbl{font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#78716c}
 .card .val{font-size:24px;font-weight:700;margin-top:6px;color:#0a1f3d}
 code{background:#eef1f5;padding:2px 6px;border-radius:4px;font-size:11.5px}
 small{color:#5b6879} .limpo{color:#046a4e;font-weight:600}
</style></head><body>

<h1>Duplicados nos movimentos</h1>
<div class="sub">Estado mês a mês · VIP Turismo Paris Lda</div>

<?php if ($msgBackup): ?><div class="ok"><?=htmlspecialchars($msgBackup)?></div><?php endif; ?>

<?php if ($acao === 'apagarmes'): ?>
  <div class="ok"><b>✓ <?=$apagados?> movimentos de <?=htmlspecialchars(nomeMes($mes))?> apagados.</b><br>
  Agora, na app: <b>Cmd+Shift+R</b>, e se for preciso limpa a cache na consola com<br>
  <code>localStorage.removeItem('vip_mov'); localStorage.removeItem('vip_movs_eliminados'); location.reload();</code><br>
  Depois importa o extrato desse mês <b>uma única vez</b>.<br><br>
  O estado anterior está guardado na tabela <code>movimentos_backup</code>.</div>
<?php endif; ?>

<div class="perigo">
  <b>Antes de apagares seja o que for:</b> confirma que já aplicaste a correção dos IDs
  no <code>index.html</code>. Sem essa correção, a importação seguinte volta a criar
  IDs aleatórios e os duplicados regressam na próxima vez que reimportares.
</div>

<div class="cards">
  <div class="card"><div class="lbl">Movimentos na base</div><div class="val"><?=$totalLinhas?></div></div>
  <div class="card"><div class="lbl">Grupos repetidos</div><div class="val"><?=$totalGrupos?></div></div>
  <div class="card"><div class="lbl">Cópias a mais</div><div class="val" style="color:#b42318"><?=$totalCopias?></div></div>
</div>

<h2>Mês a mês</h2>
<div class="aviso" style="font-size:13px">
  Apaga só os meses que têm cópias a mais. Os meses limpos ficam como estão —
  <b>não apagues janeiro a junho se estiverem a zero</b>, esses números já foram
  validados contra os extratos.
</div>
<table>
<thead><tr><th>Mês</th><th class="num">Linhas</th><th class="num">Conciliados</th>
<th class="num">Grupos repetidos</th><th class="num">Cópias a mais</th><th>Ação</th></tr></thead><tbody>
<?php foreach ($porMes as $mm => $d): ?>
  <tr>
    <td><?=htmlspecialchars(nomeMes($mm))?></td>
    <td class="num"><?=$d['linhas']?></td>
    <td class="num"><small><?=$d['conciliados']?></small></td>
    <td class="num"><?=$d['grupos'] ?: '<span class="limpo">—</span>'?></td>
    <td class="num"><?=$d['copias'] ? '<b style="color:#b42318">'.$d['copias'].'</b>' : '<span class="limpo">0</span>'?></td>
    <td><?php if ($d['copias']): ?>
      <a class="btn" href="?<?=$qs?>&acao=apagarmes&mes=<?=urlencode($mm)?>"
         onclick="return confirm('Apagar os <?=$d['linhas']?> movimentos de <?=nomeMes($mm)?>?\n\nFaço backup primeiro. Depois tens de reimportar o extrato deste mês.')">
         Apagar o mês inteiro</a>
      <?php else: ?><small>sem duplicados</small><?php endif; ?></td>
  </tr>
<?php endforeach; ?>
</tbody></table>

<h2>Os grupos repetidos <small>(primeiros 60)</small></h2>
<table>
<thead><tr><th>Data</th><th>Descrição</th><th class="num">Valor</th>
<th class="num">Cópias</th><th>IDs</th></tr></thead><tbody>
<?php $i = 0;
foreach ($grupos as $g) {
    if (count($g) < 2) continue;
    if (++$i > 60) break;
    $ref = $g[0]; ?>
  <tr>
    <td><?=htmlspecialchars(substr($ref['data'], 0, 10))?></td>
    <td><?=htmlspecialchars(mb_substr($ref['descricao'], 0, 46))?></td>
    <td class="num"><?=eur($ref['valor'])?></td>
    <td class="num"><?=count($g)?></td>
    <td><?php foreach ($g as $b): ?><div><code><?=htmlspecialchars($b['id'])?></code>
        <small><?=htmlspecialchars($b['status'])?><?=$b['fat_id'] ? ' · com fatura' : ''?></small></div>
        <?php endforeach; ?></td>
  </tr>
<?php } ?>
</tbody></table>

<?php if (!$totalGrupos): ?>
  <div class="ok"><b>Não há duplicados.</b> Cada movimento aparece uma única vez.</div>
<?php endif; ?>

</body></html>
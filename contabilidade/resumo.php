<?php
// =====================================================================
// RESUMO DE CAIXA — VIP Turismo Paris Lda
// Mostra o resultado real, com as regras corretas.
//
// Onde: public_html/contabilidade/resumo.php
// Abre: https://vipturismoparis.com/contabilidade/resumo.php
// =====================================================================

require_once __DIR__ . '/../../config/config.php';
$pdo = db();

$ano = (int)($_GET['ano'] ?? 2026);

// REGRA: as transferências internas (pagamento do cartão) NÃO contam.
// Excluem-se por status E por descrição, para bater sempre com o Painel.
//
// ATENÇÃO às VARIANTES — o Santander escreve isto de várias formas:
//   "PAG.CTA.CART.31005123018090011"     (na conta à ordem)
//   "PAGAMENTO CARTÃO APP/NETBANCO"      (no extrato do cartão)
//   "PAGAMENTO CTA_CARTAO"               (esta escapou e contava como RECEITA)
$FILTRO_INTERNAS = "
    status <> 'ignorado'
    AND descricao NOT LIKE '%PAG.CTA.CART%'
    AND descricao NOT LIKE '%PAG CTA CART%'
    AND descricao NOT LIKE '%PAGAMENTO CART%'
    AND descricao NOT LIKE '%PAGAMENTO CTA%'
    AND descricao NOT LIKE '%PAG. CARTAO%'
";

$sql = "SELECT
          DATE_FORMAT(data,'%Y-%m') AS mes,
          SUM(CASE WHEN tipo='credito' THEN valor ELSE 0 END) AS entradas,
          SUM(CASE WHEN tipo='debito' AND conta='principal'  THEN valor ELSE 0 END) AS sai_conta,
          SUM(CASE WHEN tipo='debito' AND conta='cartao' THEN valor ELSE 0 END) AS sai_cartao,
          COUNT(*) AS n
        FROM movimentos
        WHERE YEAR(data)=? AND $FILTRO_INTERNAS
        GROUP BY mes ORDER BY mes";
$stmt = $pdo->prepare($sql);
$stmt->execute([$ano]);
$meses = $stmt->fetchAll();

// Quanto foi excluído (transferências internas) — mesma regra, invertida
$st2 = $pdo->prepare("SELECT COUNT(*) n, COALESCE(SUM(valor),0) t
                      FROM movimentos
                      WHERE YEAR(data)=? AND NOT ($FILTRO_INTERNAS)");
$st2->execute([$ano]);
$interno = $st2->fetch();

$nomes = [1=>'Janeiro',2=>'Fevereiro',3=>'Março',4=>'Abril',5=>'Maio',6=>'Junho',
          7=>'Julho',8=>'Agosto',9=>'Setembro',10=>'Outubro',11=>'Novembro',12=>'Dezembro'];
function eur($v){ return number_format((float)$v, 2, ',', '.') . ' €'; }
?>
<!DOCTYPE html>
<html lang="pt">
<head>
<meta charset="utf-8">
<title>Resumo de Caixa <?=$ano?> · VIP Turismo Paris</title>
<style>
  body{font-family:system-ui,-apple-system,sans-serif;max-width:1000px;margin:40px auto;
       padding:0 20px;color:#1a1a1a;background:#fafaf9}
  h1{color:#0a1f3d;margin-bottom:4px}
  .sub{color:#78716c;margin-bottom:30px;font-size:15px}
  table{width:100%;border-collapse:collapse;background:#fff;border-radius:10px;
        overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.08)}
  th{background:#0a1f3d;color:#c8971b;padding:13px 14px;text-align:right;
     font-size:12px;letter-spacing:.5px;text-transform:uppercase}
  th:first-child{text-align:left}
  td{padding:12px 14px;text-align:right;border-bottom:1px solid #f0efed;
     font-variant-numeric:tabular-nums}
  td:first-child{text-align:left;font-weight:600}
  tr:hover td{background:#fdfcfa}
  tfoot td{background:#0a1f3d;color:#fff;font-weight:700;border:none}
  .pos{color:#16794a} .neg{color:#b42318}
  tfoot .pos{color:#7ee2b8} tfoot .neg{color:#ffb4a8}
  .cards{display:flex;gap:16px;margin:26px 0}
  .card{flex:1;background:#fff;padding:20px;border-radius:10px;
        box-shadow:0 1px 3px rgba(0,0,0,.08)}
  .card .lbl{font-size:11px;text-transform:uppercase;letter-spacing:.6px;color:#78716c}
  .card .val{font-size:26px;font-weight:700;margin-top:6px}
  .nota{background:#fdf6e3;border-left:4px solid #c8971b;padding:14px 18px;
        margin-top:26px;border-radius:6px;font-size:14px;line-height:1.7}
  .barra{display:flex;justify-content:space-between;align-items:flex-start;
         margin-bottom:30px;gap:20px;flex-wrap:wrap}
  .acoes{display:flex;gap:10px;align-items:center}
  .acoes a,.acoes button,.acoes select{
        font-family:inherit;font-size:13px;padding:8px 14px;border-radius:8px;
        border:1px solid #dde3ec;background:#fff;color:#0a1f3d;cursor:pointer;
        text-decoration:none;display:inline-flex;align-items:center;gap:6px}
  .acoes a:hover,.acoes button:hover{background:#f2f4f8}
  @media print{
    body{margin:0;background:#fff;max-width:100%}
    .acoes{display:none}
    table{box-shadow:none;border:1px solid #ccc}
    .card{box-shadow:none;border:1px solid #ccc}
  }
</style>
</head>
<body>

<div class="barra">
  <div>
    <h1>Resumo de Caixa <?=$ano?></h1>
    <div class="sub">VIP Turismo Paris Lda · Conta Santander + Cartão de Crédito</div>
  </div>
  <div class="acoes">
    <select onchange="location.href='resumo.php?ano='+this.value">
      <?php for($a = (int)date('Y'); $a >= 2024; $a--): ?>
        <option value="<?=$a?>" <?=($a===$ano?'selected':'')?>><?=$a?></option>
      <?php endfor; ?>
    </select>
    <button onclick="window.print()">🖨 Imprimir</button>
    <a href="./">← Voltar</a>
  </div>
</div>

<?php
$TE=$TC=$TK=0;
foreach($meses as $m){ $TE+=$m['entradas']; $TC+=$m['sai_conta']; $TK+=$m['sai_cartao']; }
$TS = $TC + $TK;
$RES = $TE - $TS;
?>

<div class="cards">
  <div class="card"><div class="lbl">Entradas</div>
    <div class="val pos"><?=eur($TE)?></div></div>
  <div class="card"><div class="lbl">Saídas</div>
    <div class="val neg"><?=eur($TS)?></div></div>
  <div class="card"><div class="lbl">Resultado</div>
    <div class="val <?=$RES>=0?'pos':'neg'?>"><?=eur($RES)?></div></div>
</div>

<table>
<thead><tr>
  <th>Mês</th><th>Entradas</th><th>Saídas conta</th>
  <th>Saídas cartão</th><th>Saídas total</th><th>Resultado</th>
</tr></thead>
<tbody>
<?php foreach($meses as $m):
  $sai = $m['sai_conta'] + $m['sai_cartao'];
  $liq = $m['entradas'] - $sai;
  $nm  = (int)substr($m['mes'],5,2);
?>
  <tr>
    <td><?=$nomes[$nm] ?? $m['mes']?></td>
    <td class="pos"><?=eur($m['entradas'])?></td>
    <td><?=eur($m['sai_conta'])?></td>
    <td><?=eur($m['sai_cartao'])?></td>
    <td class="neg"><?=eur($sai)?></td>
    <td class="<?=$liq>=0?'pos':'neg'?>"><b><?=eur($liq)?></b></td>
  </tr>
<?php endforeach; ?>
</tbody>
<tfoot><tr>
  <td>TOTAL</td>
  <td class="pos"><?=eur($TE)?></td>
  <td><?=eur($TC)?></td>
  <td><?=eur($TK)?></td>
  <td class="neg"><?=eur($TS)?></td>
  <td class="<?=$RES>=0?'pos':'neg'?>"><?=eur($RES)?></td>
</tr></tfoot>
</table>

<div class="nota">
  <b>Como este resultado é calculado</b><br>
  • Datas: a do movimento no extrato, sempre.<br>
  • Conta bancária: entradas e saídas.<br>
  • Cartão de crédito: as compras, com os encargos (câmbio, imposto de selo) somados a cada compra.<br>
  • <b><?=$interno['n']?> pagamentos de cartão</b> (<?=eur($interno['t'])?>) foram <b>excluídos</b> —
  são transferências internas, não despesa. Contá-los seria pagar a mesma despesa duas vezes.
</div>

</body>
</html>
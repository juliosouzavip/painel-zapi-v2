<?php
// =====================================================================
// API DA CONTABILIDADE — v2 (compatível com o index.html existente)
// =====================================================================
//
// Responde às MESMAS acções que o Google Apps Script respondia,
// mas lê/escreve no MySQL. Assim o index.html quase não muda.
//
// Onde: public_html/contabilidade/api.php
// =====================================================================

require_once __DIR__ . '/../../config/config.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: ' . CORS_ORIGIN);
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') exit;

function responder($d) { echo json_encode($d, JSON_UNESCAPED_UNICODE); exit; }

// Normaliza qualquer data para AAAA-MM-DD (mata o bug do timezone)
function normData($d) {
    $d = trim((string)$d);
    if ($d === '') return null;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m)) return "$m[1]-$m[2]-$m[3]";
    if (preg_match('#^(\d{1,2})[/\-.](\d{1,2})[/\-.](\d{4})#', $d, $m))
        return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
    return null;
}

// Devolve a data como DD/MM/AAAA (o formato que o index.html mostra)
function paraBR($d) {
    if (!$d) return '';
    $p = explode('-', substr($d, 0, 10));
    return count($p) === 3 ? "$p[2]/$p[1]/$p[0]" : $d;
}

$in     = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $in['action'] ?? $_GET['action'] ?? '';
$pdo    = db();

try {
switch ($action) {

    case 'init':
        responder(['ok' => true]);
        break;

    // ---- LER MOVIMENTOS ----
    case 'getMovimentos':
        $rows = $pdo->query("SELECT * FROM movimentos ORDER BY data DESC, id")->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'        => $r['id'],
                'data'      => paraBR($r['data']),
                'descricao' => $r['descricao'],
                'tipo'      => $r['tipo'],
                'valor'     => (float)$r['valor'],
                'entidade'  => $r['entidade'],
                'categoria' => $r['categoria'],
                'conta'     => $r['conta'],
                'status'    => $r['status'],
                'fat_id'    => $r['fat_id'],
            ];
        }
        responder(['ok' => true, 'movimentos' => $out]);
        break;

    // ---- LER FATURAS ----
    case 'getFaturas':
        $rows = $pdo->query("SELECT * FROM faturas ORDER BY data DESC, id")->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'        => $r['id'],
                'numero'    => $r['numero'],
                'data'      => paraBR($r['data']),
                'entidade'  => $r['entidade'],
                'descricao' => $r['descricao'],
                'tipo'      => $r['tipo'],
                'total'     => (float)$r['total'],
                'iva'       => (float)$r['iva'],
                'nif'       => $r['nif'],
                'categoria' => $r['categoria'],
                'estado'    => $r['estado'],
                'pdfUrl'    => $r['pdf_url'],
            ];
        }
        responder(['ok' => true, 'faturas' => $out]);
        break;

    // ---- GRAVAR MOVIMENTOS ----
    // ON DUPLICATE KEY UPDATE: se o ID já existe, ATUALIZA (nunca duplica)
    case 'guardarMovimentos':
    case 'saveMovimentos':
    case 'saveMovimento':   // singular: {movimento: {...}}
        $movs = $in['movimentos'] ?? [];
        if (isset($in['movimento'])) $movs = [$in['movimento']];
        $sql = "INSERT INTO movimentos
                  (id,data,descricao,tipo,valor,entidade,categoria,conta,status,fat_id)
                VALUES (:id,:data,:descr,:tipo,:valor,:ent,:cat,:conta,:status,:fat)
                ON DUPLICATE KEY UPDATE
                  data=VALUES(data), descricao=VALUES(descricao), tipo=VALUES(tipo),
                  valor=VALUES(valor), entidade=VALUES(entidade), categoria=VALUES(categoria),
                  conta=VALUES(conta), status=VALUES(status), fat_id=VALUES(fat_id)";
        $st = $pdo->prepare($sql);
        $pdo->beginTransaction();
        $n = 0; $ign = 0;
        foreach ($movs as $m) {
            $id = trim((string)($m['id'] ?? ''));
            $dt = normData($m['data'] ?? '');
            if ($id === '' || !$dt) { $ign++; continue; }

            $status = strtolower(trim((string)($m['status'] ?? 'pendente')));
            if (!in_array($status, ['pendente','conciliado','ignorado'])) $status = 'pendente';

            $conta = strtolower(trim((string)($m['conta'] ?? 'conta')));
            if ($conta === 'principal' || $conta === '') $conta = 'conta';

            $st->execute([
                ':id'     => $id,
                ':data'   => $dt,
                ':descr'  => mb_substr((string)($m['descricao'] ?? ''), 0, 490),
                ':tipo'   => (($m['tipo'] ?? '') === 'credito') ? 'credito' : 'debito',
                ':valor'  => (float)str_replace(',', '.', (string)($m['valor'] ?? 0)),
                ':ent'    => $m['entidade']  ?? null,
                ':cat'    => $m['categoria'] ?? null,
                ':conta'  => $conta,
                ':status' => $status,
                ':fat'    => ($m['fat_id'] ?? '') ?: null,
            ]);
            $n++;
        }
        $pdo->commit();
        responder(['ok'=>true,'guardados'=>$n,'ignorados'=>$ign,
                   'total'=>(int)$pdo->query("SELECT COUNT(*) FROM movimentos")->fetchColumn()]);
        break;

    // ---- GRAVAR FATURAS ----
    case 'guardarFaturas':
    case 'saveFaturas':
    case 'saveFatura':   // singular: {fatura: {...}}
        $fats = $in['faturas'] ?? [];
        if (isset($in['fatura'])) $fats = [$in['fatura']];
        $sql = "INSERT INTO faturas
                  (id,numero,data,entidade,descricao,tipo,total,iva,nif,categoria,estado,pdf_url)
                VALUES (:id,:num,:data,:ent,:descr,:tipo,:total,:iva,:nif,:cat,:estado,:pdf)
                ON DUPLICATE KEY UPDATE
                  numero=VALUES(numero), data=VALUES(data), entidade=VALUES(entidade),
                  descricao=VALUES(descricao), tipo=VALUES(tipo), total=VALUES(total),
                  iva=VALUES(iva), nif=VALUES(nif), categoria=VALUES(categoria),
                  estado=VALUES(estado), pdf_url=VALUES(pdf_url)";
        $st = $pdo->prepare($sql);
        $pdo->beginTransaction();
        $n = 0; $ign = 0;
        foreach ($fats as $f) {
            $id = trim((string)($f['id'] ?? ''));
            $dt = normData($f['data'] ?? '');
            if ($id === '' || !$dt) { $ign++; continue; }

            $st->execute([
                ':id'     => $id,
                ':num'    => ($f['numero'] ?? '') ?: null,
                ':data'   => $dt,
                ':ent'    => mb_substr((string)($f['entidade'] ?? ''), 0, 250),
                ':descr'  => mb_substr((string)($f['descricao'] ?? ''), 0, 490),
                ':tipo'   => (($f['tipo'] ?? '') === 'receita') ? 'receita' : 'despesa',
                ':total'  => (float)str_replace(',', '.', (string)($f['total'] ?? 0)),
                ':iva'    => (float)str_replace(',', '.', (string)($f['iva'] ?? 0)),
                ':nif'    => ($f['nif'] ?? '') ?: null,
                ':cat'    => $f['categoria'] ?? null,
                ':estado' => (($f['estado'] ?? '') === 'pago') ? 'pago' : 'pendente',
                ':pdf'    => ($f['pdfUrl'] ?? $f['pdf_url'] ?? '') ?: null,
            ]);
            $n++;
        }
        $pdo->commit();
        responder(['ok'=>true,'guardados'=>$n,'ignorados'=>$ign,
                   'total'=>(int)$pdo->query("SELECT COUNT(*) FROM faturas")->fetchColumn()]);
        break;

    // ---- ELIMINAR (a sério: desaparece da BD e não volta) ----
    case 'deleteFatura':
        $st = $pdo->prepare("DELETE FROM faturas WHERE id=?");
        $st->execute([$in['id'] ?? '']);
        responder(['ok'=>true,'eliminados'=>$st->rowCount()]);
        break;

    case 'deleteMovimento':
        $st = $pdo->prepare("DELETE FROM movimentos WHERE id=?");
        $st->execute([$in['id'] ?? '']);
        responder(['ok'=>true,'eliminados'=>$st->rowCount()]);
        break;

    // ---- CONCILIAR fatura <-> movimento ----
    case 'conciliar':
        $mv = $in['movimento_id'] ?? $in['mov_id'] ?? '';
        $ft = $in['fatura_id'] ?? $in['fat_id'] ?? '';
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO conciliacao (movimento_id,fatura_id) VALUES (?,?)
                       ON DUPLICATE KEY UPDATE fatura_id=VALUES(fatura_id)")
            ->execute([$mv, $ft]);
        $pdo->prepare("UPDATE movimentos SET status='conciliado', fat_id=? WHERE id=?")
            ->execute([$ft, $mv]);
        $pdo->prepare("UPDATE faturas SET estado='pago' WHERE id=?")->execute([$ft]);
        $pdo->commit();
        responder(['ok'=>true]);
        break;

    // ---- RESUMO DE CAIXA (regras corretas, sem dupla contagem) ----
    case 'resumoCaixa':
        $ano = (int)($in['ano'] ?? $_GET['ano'] ?? date('Y'));
        $st = $pdo->prepare(
           "SELECT DATE_FORMAT(data,'%Y-%m') mes,
                   SUM(CASE WHEN tipo='credito' THEN valor ELSE 0 END) entradas,
                   SUM(CASE WHEN tipo='debito' AND conta='conta'  THEN valor ELSE 0 END) sai_conta,
                   SUM(CASE WHEN tipo='debito' AND conta='cartao' THEN valor ELSE 0 END) sai_cartao
            FROM movimentos
            WHERE YEAR(data)=? AND status<>'ignorado'
            GROUP BY mes ORDER BY mes");
        $st->execute([$ano]);
        $meses = $st->fetchAll();
        $te=$tc=$tk=0;
        foreach ($meses as &$m) {
            $m['entradas']   = (float)$m['entradas'];
            $m['sai_conta']  = (float)$m['sai_conta'];
            $m['sai_cartao'] = (float)$m['sai_cartao'];
            $m['liquido']    = round($m['entradas'] - $m['sai_conta'] - $m['sai_cartao'], 2);
            $te += $m['entradas']; $tc += $m['sai_conta']; $tk += $m['sai_cartao'];
        }
        responder(['ok'=>true,'ano'=>$ano,'meses'=>$meses,'total'=>[
            'entradas'      => round($te, 2),
            'saidas'        => round($tc + $tk, 2),
            'saidas_conta'  => round($tc, 2),
            'saidas_cartao' => round($tk, 2),
            'resultado'     => round($te - $tc - $tk, 2),
        ]]);
        break;

    default:
        responder(['ok'=>false,'error'=>'Acção desconhecida: '.$action]);
}

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    http_response_code(500);
    responder(['ok'=>false,'error'=>$e->getMessage()]);
}
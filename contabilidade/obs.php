<?php
// =====================================================================
// OBS.PHP — observações dos movimentos   ·  VIP Turismo Paris Lda
// =====================================================================
//
// PARA QUE SERVE
//   Guardar no MySQL as notas que escreves em cada movimento
//   ("Solicitei fatura ao fornecedor", "Não encontrei a fatura"...),
//   para ficarem disponíveis em qualquer computador e para a Uane.
//
// PORQUE É UM FICHEIRO SEPARADO
//   Assim não é preciso mexer na api.php nem na tabela `movimentos`.
//   As notas vivem numa tabela própria, `movimento_obs`, ligada ao
//   movimento pelo id. Se um dia isto for apagado, a contabilidade
//   continua a funcionar exactamente na mesma.
//
// A TABELA É CRIADA SOZINHA na primeira utilização. Não tens de fazer nada.
//
// COMO INSTALAR
//   1. Põe este ficheiro em public_html/contabilidade/
//      (ao lado do index.html e da api.php)
//   2. É tudo. O index.html novo já sabe falar com ele.
//
// COMO CONFIRMAR QUE ESTÁ A FUNCIONAR
//   Abre https://vipturismoparis.com/contabilidade/obs.php?teste=1
//   Deve responder: {"ok":true,"tabela":"criada","notas":0}
//
// NOTA DE SEGURANÇA (honesta)
//   Este ficheiro não pede palavra-passe, tal como a api.php que já lá
//   está. Quem souber o endereço consegue ler e escrever notas. Como as
//   notas não têm dados bancários nem pessoais, o risco é baixo — mas se
//   um dia puseres autenticação na api.php, põe também aqui.
// =====================================================================

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/config.php';

function responder($dados, $codigo = 200) {
    http_response_code($codigo);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = db();

    // Cria a tabela na primeira vez. `mov_id` é o id do movimento
    // (conta_20260601_427893_d_1), que é chave primária — cada movimento
    // tem no máximo uma nota.
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS movimento_obs (
            mov_id VARCHAR(64) NOT NULL,
            obs VARCHAR(300) NOT NULL DEFAULT '',
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (mov_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    // Teste rápido pelo browser
    if (isset($_GET['teste'])) {
        $n = (int)$pdo->query("SELECT COUNT(*) FROM movimento_obs")->fetchColumn();
        responder(['ok' => true, 'tabela' => 'criada', 'notas' => $n]);
    }

    $entrada = json_decode(file_get_contents('php://input'), true);
    if (!is_array($entrada)) $entrada = [];
    $accao = $entrada['action'] ?? ($_GET['action'] ?? 'listar');

    // -----------------------------------------------------------------
    // listar — devolve todas as notas: {"conta_2026...": "texto", ...}
    // -----------------------------------------------------------------
    if ($accao === 'listar') {
        $linhas = $pdo->query("SELECT mov_id, obs FROM movimento_obs")->fetchAll(PDO::FETCH_ASSOC);
        $mapa = [];
        foreach ($linhas as $l) {
            if (trim($l['obs']) !== '') $mapa[$l['mov_id']] = $l['obs'];
        }
        responder(['ok' => true, 'obs' => $mapa]);
    }

    // -----------------------------------------------------------------
    // guardar — uma nota. Texto vazio apaga.
    // -----------------------------------------------------------------
    if ($accao === 'guardar') {
        $id  = trim((string)($entrada['id'] ?? ''));
        $obs = trim((string)($entrada['obs'] ?? ''));
        if ($id === '') responder(['error' => 'Falta o id do movimento'], 400);
        $obs = mb_substr($obs, 0, 300);

        if ($obs === '') {
            $st = $pdo->prepare("DELETE FROM movimento_obs WHERE mov_id = ?");
            $st->execute([$id]);
            responder(['ok' => true, 'apagada' => true]);
        }

        $st = $pdo->prepare(
            "INSERT INTO movimento_obs (mov_id, obs) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE obs = VALUES(obs)"
        );
        $st->execute([$id, $obs]);
        responder(['ok' => true]);
    }

    // -----------------------------------------------------------------
    // guardarVarias — envia várias de uma vez. Usado para levar para o
    // servidor as notas que ficaram guardadas só no browser.
    // -----------------------------------------------------------------
    if ($accao === 'guardarVarias') {
        $mapa = $entrada['obs'] ?? [];
        if (!is_array($mapa)) responder(['error' => 'Formato inválido'], 400);

        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare(
                "INSERT INTO movimento_obs (mov_id, obs) VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE obs = VALUES(obs)"
            );
            $n = 0;
            foreach ($mapa as $id => $obs) {
                $id  = trim((string)$id);
                $obs = mb_substr(trim((string)$obs), 0, 300);
                if ($id === '' || $obs === '') continue;
                $st->execute([$id, $obs]);
                $n++;
            }
            $pdo->commit();
            responder(['ok' => true, 'guardadas' => $n]);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    responder(['error' => 'Acção desconhecida: ' . $accao], 400);

} catch (Throwable $e) {
    responder(['error' => $e->getMessage()], 500);
}
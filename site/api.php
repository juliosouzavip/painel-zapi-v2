<?php
/* ─────────────────────────────────────────────
   VIP Turismo Paris — armazenamento de roteiros
   Gera links curtos e regista confirmações.

   CONFIGURE A SENHA ABAIXO (a mesma do gerador.html)
   ───────────────────────────────────────────── */

const SENHA   = 'vip2024';              // senha do painel — ALTERE
const DIR     = __DIR__ . '/dados';     // pasta onde os roteiros ficam
const MAX     = 300000;                 // tamanho máximo por roteiro (300 KB)
const VALIDADE = 400;                   // dias que um roteiro fica guardado

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function responde($dados, $codigo = 200) {
  http_response_code($codigo);
  echo json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

/* Cria a pasta de dados e bloqueia o acesso directo por URL */
function preparaPasta() {
  if (!is_dir(DIR) && !@mkdir(DIR, 0755, true)) {
    responde(['erro' => 'Não foi possível criar a pasta de dados. Verifique as permissões.'], 500);
  }
  $ht = DIR . '/.htaccess';
  if (!file_exists($ht)) {
    @file_put_contents($ht,
      "<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>\n" .
      "<IfModule !mod_authz_core.c>\n  Order allow,deny\n  Deny from all\n</IfModule>\n"
    );
  }
}

function idValido($id) {
  return is_string($id) && preg_match('/^[A-Z0-9]{6}$/', $id) === 1;
}

/* Código de 6 caracteres, sem letras que se confundem (I, O, 0, 1) */
function geraId() {
  $abc = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
  for ($tentativa = 0; $tentativa < 20; $tentativa++) {
    $id = '';
    for ($i = 0; $i < 6; $i++) $id .= $abc[random_int(0, strlen($abc) - 1)];
    if (!file_exists(caminho($id))) return $id;
  }
  responde(['erro' => 'Não foi possível gerar um código único.'], 500);
}

function caminho($id) { return DIR . '/' . $id . '.json'; }

function le($id) {
  $f = caminho($id);
  if (!file_exists($f)) return null;
  $c = json_decode(file_get_contents($f), true);
  return is_array($c) ? $c : null;
}

function grava($id, $dados) {
  $ok = file_put_contents(caminho($id), json_encode($dados, JSON_UNESCAPED_UNICODE), LOCK_EX);
  if ($ok === false) responde(['erro' => 'Falha ao gravar o roteiro.'], 500);
}

function exigeSenha($enviada) {
  if (!is_string($enviada) || !hash_equals(SENHA, $enviada)) {
    responde(['erro' => 'Senha incorreta.'], 403);
  }
}

/* Remove roteiros mais antigos que VALIDADE dias */
function limpaAntigos() {
  $limite = time() - (VALIDADE * 86400);
  foreach (glob(DIR . '/*.json') ?: [] as $f) {
    if (filemtime($f) < $limite) @unlink($f);
  }
}

preparaPasta();

$acao = $_GET['acao'] ?? '';
$body = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $raw = file_get_contents('php://input');
  if (strlen($raw) > MAX) responde(['erro' => 'Roteiro demasiado grande.'], 413);
  $body = json_decode($raw, true);
  if (!is_array($body)) $body = [];
}

switch ($acao) {

  /* ── Cria um roteiro e devolve o código curto ── */
  case 'criar':
    exigeSenha($body['senha'] ?? '');
    $roteiro = $body['roteiro'] ?? null;
    if (!is_array($roteiro) || empty($roteiro['titulo'])) {
      responde(['erro' => 'Roteiro inválido.'], 400);
    }
    if (mt_rand(1, 20) === 1) limpaAntigos();

    $id = geraId();
    $roteiro['id'] = $id;
    grava($id, [
      'roteiro'    => $roteiro,
      'status'     => 'pendente',
      'criadoEm'   => date('c'),
      'confirmado' => null,
    ]);
    responde(['id' => $id]);

  /* ── Devolve o roteiro para o cliente ── */
  case 'ler':
    $id = $_GET['id'] ?? '';
    if (!idValido($id)) responde(['erro' => 'Código inválido.'], 400);
    $reg = le($id);
    if (!$reg) responde(['erro' => 'Roteiro não encontrado.'], 404);
    responde([
      'roteiro'    => $reg['roteiro'],
      'status'     => $reg['status'],
      'confirmado' => $reg['confirmado'],
    ]);

  /* ── O cliente confirma as reservas ── */
  case 'confirmar':
    $id = $body['id'] ?? '';
    if (!idValido($id)) responde(['erro' => 'Código inválido.'], 400);
    $reg = le($id);
    if (!$reg) responde(['erro' => 'Roteiro não encontrado.'], 404);
    if ($reg['status'] !== 'confirmado') {
      $reg['status']     = 'confirmado';
      $reg['confirmado'] = date('c');
      grava($id, $reg);
    }
    responde(['status' => 'confirmado', 'confirmado' => $reg['confirmado']]);

  /* ── Lista os roteiros para o painel ── */
  case 'listar':
    exigeSenha($body['senha'] ?? '');
    $lista = [];
    foreach (glob(DIR . '/*.json') ?: [] as $f) {
      $reg = json_decode(file_get_contents($f), true);
      if (!is_array($reg) || empty($reg['roteiro'])) continue;
      $lista[] = [
        'id'         => $reg['roteiro']['id'] ?? basename($f, '.json'),
        'titulo'     => $reg['roteiro']['titulo'] ?? '',
        'cliente'    => $reg['roteiro']['cliente']['nome'] ?? '',
        'status'     => $reg['status'] ?? 'pendente',
        'criadoEm'   => $reg['criadoEm'] ?? null,
        'confirmado' => $reg['confirmado'] ?? null,
      ];
    }
    usort($lista, fn($a, $b) => strcmp($b['criadoEm'] ?? '', $a['criadoEm'] ?? ''));
    responde(['roteiros' => array_slice($lista, 0, 200)]);

  /* ── Apaga um roteiro ── */
  case 'apagar':
    exigeSenha($body['senha'] ?? '');
    $id = $body['id'] ?? '';
    if (!idValido($id)) responde(['erro' => 'Código inválido.'], 400);
    if (file_exists(caminho($id))) @unlink(caminho($id));
    responde(['ok' => true]);

  default:
    responde(['erro' => 'Ação desconhecida.'], 400);
}

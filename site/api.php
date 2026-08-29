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

/* ── AVISO QUANDO O CLIENTE CONFIRMA ──────────────────
   Basta preencher um dos dois. Pode usar os dois ao mesmo tempo.

   A) WhatsApp pela Z-API — copie os três valores do painel da Z-API
      em https://app.z-api.io (Instância → Segurança).
   B) E-mail — funciona sem configurar nada além do endereço.
   ───────────────────────────────────────────────────── */

const AVISO_WHATSAPP = '351963765679';  // número que recebe o aviso, sem + nem espaços

const ZAPI_INSTANCIA    = '';   // ex: 3ED5449C491BB12A2910D66739CEE648
const ZAPI_TOKEN        = '';   // ex: 99865543D794C144ECA83BC3
const ZAPI_CLIENT_TOKEN = '';   // "Client-Token" na aba Segurança da Z-API

const AVISO_EMAIL = 'info@vipturismoparis.com';  // vazio desliga o e-mail

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

/* ─────────────────────────────────────────────
   Aviso de confirmação
   ───────────────────────────────────────────── */

/* Texto do aviso, com os dados do roteiro */
function textoAviso($roteiro, $id) {
  $nome   = trim($roteiro['cliente']['nome'] ?? 'Cliente');
  $tel    = trim($roteiro['cliente']['telefone'] ?? '');
  $viagem = trim($roteiro['titulo'] ?? '');
  $svcs   = is_array($roteiro['svcs'] ?? null) ? $roteiro['svcs'] : [];

  if ($nome !== '' && mb_strtoupper($nome, 'UTF-8') === $nome) {
    $nome = mb_convert_case($nome, MB_CASE_TITLE, 'UTF-8');
  }

  $linhas = [];
  $linhas[] = 'ROTEIRO CONFIRMADO';
  $linhas[] = '';
  $linhas[] = 'Cliente: ' . $nome;
  if ($tel !== '')    $linhas[] = 'Telefone: ' . $tel;
  if ($viagem !== '') $linhas[] = 'Viagem: ' . $viagem;
  $linhas[] = 'Referência: ' . $id;
  $linhas[] = 'Confirmado em: ' . date('d/m/Y H:i');
  $linhas[] = '';

  if ($svcs) {
    $linhas[] = 'Serviços aceites (' . count($svcs) . '):';
    foreach ($svcs as $sv) {
      $data = $sv['data'] ?? '';
      if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) === 1) $data = date('d/m', strtotime($data));
      $partes = array_filter([$data, $sv['hora'] ?? '', $sv['nome'] ?? '', $sv['valor'] ?? '']);
      $linhas[] = '• ' . implode(' · ', $partes);
    }
    $linhas[] = '';
  }

  $linhas[] = 'Ver o roteiro:';
  $linhas[] = baseUrl() . '/r.php?r=' . $id;

  return implode("\n", $linhas);
}

/* Endereço do site, detectado a partir do pedido */
function baseUrl() {
  $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  $host  = $_SERVER['HTTP_HOST'] ?? 'vipturismoparis.com';
  $pasta = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
  return ($https ? 'https://' : 'http://') . $host . $pasta;
}

/* Envia pelo WhatsApp através da Z-API */
function avisaWhatsApp($texto) {
  if (ZAPI_INSTANCIA === '' || ZAPI_TOKEN === '' || AVISO_WHATSAPP === '') {
    return ['ok' => false, 'erro' => 'Z-API não configurada'];
  }

  $url = 'https://api.z-api.io/instances/' . ZAPI_INSTANCIA
       . '/token/' . ZAPI_TOKEN . '/send-text';
  $corpo = json_encode(['phone' => AVISO_WHATSAPP, 'message' => $texto], JSON_UNESCAPED_UNICODE);

  $cabecalhos = ['Content-Type: application/json'];
  if (ZAPI_CLIENT_TOKEN !== '') $cabecalhos[] = 'Client-Token: ' . ZAPI_CLIENT_TOKEN;

  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_POST           => true,
      CURLOPT_POSTFIELDS     => $corpo,
      CURLOPT_HTTPHEADER     => $cabecalhos,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_TIMEOUT        => 8,
      CURLOPT_CONNECTTIMEOUT => 5,
    ]);
    $resposta = curl_exec($ch);
    $codigo   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erroCurl = curl_error($ch);
    curl_close($ch);

    if ($resposta === false) return ['ok' => false, 'erro' => $erroCurl ?: 'falha na ligação'];
    if ($codigo >= 200 && $codigo < 300) return ['ok' => true];
    return ['ok' => false, 'erro' => "HTTP $codigo: " . substr((string)$resposta, 0, 200)];
  }

  /* sem cURL, tenta pelo fluxo de ficheiros */
  $ctx = stream_context_create(['http' => [
    'method'        => 'POST',
    'header'        => implode("\r\n", $cabecalhos),
    'content'       => $corpo,
    'timeout'       => 8,
    'ignore_errors' => true,
  ]]);
  $resposta = @file_get_contents($url, false, $ctx);
  if ($resposta === false) return ['ok' => false, 'erro' => 'sem cURL e o pedido falhou'];
  return ['ok' => true];
}

/* Envia por e-mail */
function avisaEmail($texto, $assunto) {
  if (AVISO_EMAIL === '' || !function_exists('mail')) {
    return ['ok' => false, 'erro' => 'e-mail não configurado'];
  }
  $de = 'noreply@' . preg_replace('/^www\./', '', $_SERVER['HTTP_HOST'] ?? 'vipturismoparis.com');
  $cabecalhos = implode("\r\n", [
    'From: VIP Turismo Paris <' . $de . '>',
    'Content-Type: text/plain; charset=UTF-8',
    'Content-Transfer-Encoding: 8bit',
  ]);
  $enviado = @mail(AVISO_EMAIL, $assunto, $texto, $cabecalhos);
  return $enviado ? ['ok' => true] : ['ok' => false, 'erro' => 'o servidor recusou o envio'];
}

/* Dispara os avisos configurados e devolve o que aconteceu */
function avisaEquipa($roteiro, $id) {
  $texto   = textoAviso($roteiro, $id);
  $nome    = trim($roteiro['cliente']['nome'] ?? 'Cliente');
  $assunto = 'Roteiro confirmado — ' . $nome . ' (' . $id . ')';

  return [
    'whatsapp' => avisaWhatsApp($texto),
    'email'    => avisaEmail($texto, $assunto),
  ];
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

      /* avisa a equipa — uma única vez, e sem impedir a confirmação se falhar */
      try {
        $reg['aviso'] = avisaEquipa($reg['roteiro'], $id);
      } catch (Throwable $e) {
        $reg['aviso'] = ['erro' => $e->getMessage()];
      }
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

  /* ── Envia um aviso de teste, para conferir a configuração ── */
  case 'testar-aviso':
    exigeSenha($body['senha'] ?? '');
    $exemplo = [
      'cliente' => ['nome' => 'Cliente de Teste', 'telefone' => '+351 963 765 679'],
      'titulo'  => 'Teste de aviso — pode ignorar',
      'svcs'    => [['tipo' => 'transfer', 'nome' => 'Transfer de exemplo',
                     'data' => date('Y-m-d'), 'hora' => '10:00', 'valor' => '€0,00']],
    ];
    responde(['resultado' => avisaEquipa($exemplo, 'TESTE1')]);

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

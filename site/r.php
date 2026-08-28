<?php
/* ─────────────────────────────────────────────
   VIP Turismo Paris — link do roteiro
   Serve o roteiro.html com a pré-visualização
   (WhatsApp, Facebook, Telegram) já preenchida
   com o nome do cliente e o título da viagem.

   O WhatsApp não executa JavaScript: por isso o
   link enviado ao cliente aponta para este PHP,
   que escreve as etiquetas no HTML antes de servir.
   ───────────────────────────────────────────── */

const DIR     = __DIR__ . '/dados';
const IMAGEM  = 'img/preview.jpg';   // 1200×630 — troque pela sua imagem
const EMPRESA = 'VIP Turismo Paris';

/* URL absoluto do site (as redes sociais exigem endereço completo) */
function base() {
  $https  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
         || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
  $host   = $_SERVER['HTTP_HOST'] ?? 'vipturismoparis.com';
  $pasta  = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
  return ($https ? 'https://' : 'http://') . $host . $pasta;
}

function e($t) { return htmlspecialchars((string)$t, ENT_QUOTES, 'UTF-8'); }

/* ── Lê o roteiro pelo código ── */
$codigo  = $_GET['r'] ?? '';
$roteiro = null;

if (preg_match('/^[A-Z0-9]{6}$/', $codigo) === 1) {
  $f = DIR . '/' . $codigo . '.json';
  if (file_exists($f)) {
    $reg = json_decode(file_get_contents($f), true);
    if (is_array($reg) && !empty($reg['roteiro'])) $roteiro = $reg['roteiro'];
  }
}

/* ── Monta o texto da pré-visualização ── */
$titulo   = 'Seu Roteiro de Viagem — ' . EMPRESA;
$descricao = 'Veja todos os detalhes das suas reservas e confirme o seu roteiro.';

if ($roteiro) {
  $nome  = trim($roteiro['cliente']['nome'] ?? '');
  $viagem = trim($roteiro['titulo'] ?? '');
  $svcs  = is_array($roteiro['svcs'] ?? null) ? $roteiro['svcs'] : [];

  /* "LUCIANA FALCÃO" fica "Luciana Falcão" */
  if ($nome !== '' && mb_strtoupper($nome, 'UTF-8') === $nome) {
    $nome = mb_convert_case($nome, MB_CASE_TITLE, 'UTF-8');
  }

  $titulo = $nome !== ''
    ? "Roteiro de $nome — " . EMPRESA
    : ($viagem !== '' ? "$viagem — " . EMPRESA : $titulo);

  /* Descrição: viagem, nº de serviços e período */
  $partes = [];
  if ($viagem !== '') $partes[] = $viagem;

  $n = count($svcs);
  if ($n > 0) $partes[] = $n . ($n === 1 ? ' serviço reservado' : ' serviços reservados');

  $datas = array_values(array_filter(array_map(
    fn($s) => $s['data'] ?? '',
    $svcs
  ), fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) === 1));

  if ($datas) {
    sort($datas);
    $ini = date('d/m/Y', strtotime($datas[0]));
    $fim = date('d/m/Y', strtotime(end($datas)));
    $partes[] = ($ini === $fim) ? $ini : "$ini a $fim";
  }

  $partes[] = 'Toque para revisar e confirmar.';
  $descricao = implode(' · ', $partes);
}

/* ── Injeta as etiquetas no roteiro.html ── */
$html = @file_get_contents(__DIR__ . '/roteiro.html');
if ($html === false) {
  http_response_code(500);
  exit('roteiro.html não encontrado.');
}

$url = base() . '/r.php?r=' . rawurlencode($codigo);
$img = base() . '/' . IMAGEM;

$tags = implode("\n", [
  '<meta property="og:type" content="website">',
  '<meta property="og:site_name" content="' . e(EMPRESA) . '">',
  '<meta property="og:locale" content="pt_BR">',
  '<meta property="og:title" content="' . e($titulo) . '">',
  '<meta property="og:description" content="' . e($descricao) . '">',
  '<meta property="og:url" content="' . e($url) . '">',
  '<meta property="og:image" content="' . e($img) . '">',
  '<meta property="og:image:secure_url" content="' . e($img) . '">',
  '<meta property="og:image:type" content="image/jpeg">',
  '<meta property="og:image:width" content="1200">',
  '<meta property="og:image:height" content="630">',
  '<meta property="og:image:alt" content="' . e($titulo) . '">',
  '<meta name="twitter:card" content="summary_large_image">',
  '<meta name="twitter:title" content="' . e($titulo) . '">',
  '<meta name="twitter:description" content="' . e($descricao) . '">',
  '<meta name="twitter:image" content="' . e($img) . '">',
  '<meta name="description" content="' . e($descricao) . '">',
  '<meta name="robots" content="noindex,nofollow">',
]);

/* remove as etiquetas de reserva do HTML para não ficarem duplicadas */
$html = preg_replace('/<!--OG-RESERVA-INICIO-->.*?<!--OG-RESERVA-FIM-->/s', '', $html, 1);

/* substitui o marcador; se não existir, insere logo após o <head> */
if (strpos($html, '<!--OG-->') !== false) {
  $html = str_replace('<!--OG-->', $tags, $html);
} else {
  $html = preg_replace('/<head[^>]*>/i', '$0' . "\n" . $tags, $html, 1);
}

/* o título da aba também acompanha o cliente */
$html = preg_replace('#<title>.*?</title>#is', '<title>' . e($titulo) . '</title>', $html, 1);

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: public, max-age=300');
echo $html;

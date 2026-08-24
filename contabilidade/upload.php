<?php
// ============================================================
// upload.php — Proxy VIP Turismo (versão revista e segura)
// vipturismoparis.com/contabilidade/upload.php
// ============================================================

// Uma leitura de PDF pela IA pode demorar mais de 2 minutos.
// Se o PHP desligar antes do cURL, o pedido morre sem resposta.
set_time_limit(240);
ini_set('max_execution_time', 240);


// ------------------------------------------------------------
// SEGREDOS — ficam em config.local.php, que NÃO vai para o Git.
// Copia o config.local.exemplo.php para config.local.php e preenche lá
// a chave da Anthropic, o URL do Apps Script e as senhas.
// (A chave que estava escrita aqui dentro ficou exposta — gera uma nova
//  em console.anthropic.com e apaga a antiga.)
// ------------------------------------------------------------
// Procura primeiro fora da pasta pública (mais seguro), depois ao lado.
foreach ([__DIR__ . '/../../config/contabilidade.config.php',
          __DIR__ . '/config.local.php'] as $__cfg) {
    if (is_file($__cfg)) { require_once $__cfg; break; }
}

if (!defined('ANTHROPIC_KEY')) define('ANTHROPIC_KEY', 'FALTA_CONFIGURAR');
if (!defined('GAS_URL'))       define('GAS_URL', '');
if (!defined('SECRET_TOKEN'))  define('SECRET_TOKEN', 'FALTA_CONFIGURAR');
if (!defined('USERS_SERVER'))  define('USERS_SERVER', []);
if (!defined('READONLY_USERS')) define('READONLY_USERS', ['view']);

// Sessão válida por 12 horas
define('TOKEN_VALIDADE', 12 * 3600);

// Só o seu site pode chamar este proxy a partir do navegador
define('ORIGENS_PERMITIDAS', [
    'https://vipturismoparis.com',
    'https://www.vipturismoparis.com',
]);

// A IA só aceita estes modelos (evita abuso com modelos caros)
define('MODELOS_PERMITIDOS', [
    'claude-sonnet-4-6',
    'claude-haiku-4-5-20251001',
]);
define('MAX_TOKENS_LIMITE', 8192);

// Ficheiro onde os erros ficam registados (consulte por FTP)
define('LOG_FICHEIRO', __DIR__ . '/erros_proxy.log');

// ------------------------------------------------------------
// 2) CORS restrito (antes era * = qualquer site do mundo)
// ------------------------------------------------------------
$origem = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origem && in_array($origem, ORIGENS_PERMITIDAS, true)) {
    header('Access-Control-Allow-Origin: ' . $origem);
    header('Vary: Origin');
}
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Auth-Token');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Método não permitido']); exit;
}

// ------------------------------------------------------------
// 3) Funções auxiliares
// ------------------------------------------------------------
function registarErro($msg) {
    @error_log('[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", 3, LOG_FICHEIRO);
}

// Cria um "crachá" assinado: utilizador|validade|assinatura
function gerarToken($user) {
    $exp = time() + TOKEN_VALIDADE;
    $payload = $user . '|' . $exp;
    $sig = hash_hmac('sha256', $payload, SECRET_TOKEN);
    return base64_encode($payload . '|' . $sig);
}

// Verifica o crachá. Devolve o nome do utilizador ou false.
function validarToken($tok) {
    if (!$tok) return false;
    $dec = base64_decode($tok, true);
    if (!$dec) return false;
    $partes = explode('|', $dec);
    if (count($partes) !== 3) return false;
    [$user, $exp, $sig] = $partes;
    if (time() > (int)$exp) return false; // expirou
    $esperado = hash_hmac('sha256', $user . '|' . $exp, SECRET_TOKEN);
    if (!hash_equals($esperado, $sig)) return false;
    return $user;
}

// Procura o token no cabeçalho, no formulário ou no JSON
function obterToken($dadosJson = null) {
    if (!empty($_SERVER['HTTP_X_AUTH_TOKEN'])) return $_SERVER['HTTP_X_AUTH_TOKEN'];
    if (!empty($_POST['token'])) return $_POST['token'];
    if (is_array($dadosJson) && !empty($dadosJson['token'])) return $dadosJson['token'];
    return null;
}

function exigirLogin($dadosJson = null) {
    $user = validarToken(obterToken($dadosJson));
    if (!$user) {
        http_response_code(401);
        echo json_encode(['error' => 'Sessão inválida ou expirada. Faça login novamente.']);
        exit;
    }
    return $user;
}

$action = $_POST['action'] ?? $_GET['action'] ?? 'upload';

// ------------------------------------------------------------
// 4) LOGIN — agora devolve também um token de sessão
// ------------------------------------------------------------
if ($action === 'login') {
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    $u = strtolower(trim($data['u'] ?? ''));
    $p = $data['p'] ?? '';
    if (!$u || !$p) { echo json_encode(['ok' => false, 'error' => 'Credenciais em falta']); exit; }

    $pHash = hash('sha256', $p);
    if (isset(USERS_SERVER[$u]) && hash_equals(USERS_SERVER[$u], $pHash)) {
        echo json_encode([
            'ok'       => true,
            'user'     => $u,
            'readonly' => in_array($u, READONLY_USERS, true),
            'token'    => gerarToken($u),   // ← NOVO: guardar no localStorage
        ]);
    } else {
        sleep(1); // dificulta ataques de força bruta
        registarErro("Login falhado para utilizador '$u' (IP " . ($_SERVER['REMOTE_ADDR'] ?? '?') . ')');
        echo json_encode(['ok' => false, 'error' => 'Credenciais incorretas']);
    }
    exit;
}

// ------------------------------------------------------------
// 5) UPLOAD DE FICHEIRO — agora exige login
//    (dica: pode eliminar esta etapa fazendo o base64 no navegador)
// ------------------------------------------------------------
if ($action === 'upload') {
    exigirLogin();

    $allowed = ['application/pdf','image/jpeg','image/png','text/csv','text/plain','application/csv'];
    $file = $_FILES['arquivo'] ?? null;
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) { echo json_encode(['error' => 'Ficheiro não recebido']); exit; }

    $mime = mime_content_type($file['tmp_name']);
    if (!in_array($mime, $allowed, true)) { echo json_encode(['error' => 'Tipo não permitido: ' . $mime]); exit; }

    // 20 MB: acima disto, o base64 (+33%) ultrapassa o limite da API Anthropic
    if ($file['size'] > 20 * 1024 * 1024) {
        echo json_encode(['error' => 'Ficheiro demasiado grande (máx. 20 MB)']); exit;
    }

    $base64 = base64_encode(file_get_contents($file['tmp_name']));
    echo json_encode(['base64' => $base64, 'mime' => $mime, 'nome' => $file['name']]);
    exit;
}

// ------------------------------------------------------------
// 6) PROXY ANTHROPIC (IA) — com login, limites e retry
// ------------------------------------------------------------
if ($action === 'ia') {
    $raw = file_get_contents('php://input');
    if (!$raw) { echo json_encode(['error' => 'Body vazio']); exit; }
    $data = json_decode($raw, true);
    if (!$data) { echo json_encode(['error' => 'JSON inválido']); exit; }

    exigirLogin($data);
    unset($data['_key'], $data['token']);

    // Só modelos aprovados — impede abuso com modelos caros
    $modelo = $data['model'] ?? '';
    if (!in_array($modelo, MODELOS_PERMITIDOS, true)) {
        $data['model'] = MODELOS_PERMITIDOS[0]; // força o modelo padrão
    }
    // Teto de tokens de resposta
    if (!isset($data['max_tokens']) || (int)$data['max_tokens'] > MAX_TOKENS_LIMITE) {
        $data['max_tokens'] = MAX_TOKENS_LIMITE;
    }

    $chamarIA = function ($payload) {
        $ch = curl_init('https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: ' . ANTHROPIC_KEY,
                'anthropic-version: 2023-06-01',
            ],
            // ANTES: 50s. O erros_proxy.log estava cheio de
            // "Operation timed out after 50002 milliseconds": a leitura de um
            // extrato em PDF demora mais do que isso e era cortada a meio.
            CURLOPT_TIMEOUT        => 110,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING       => '', // aceita gzip → respostas mais rápidas
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        return ['resp' => $resp, 'code' => $code, 'err' => $err];
    };

    if (ANTHROPIC_KEY === 'FALTA_CONFIGURAR') {
        registarErro('Chamada à IA sem chave configurada (falta o config.local.php)');
        http_response_code(500);
        echo json_encode(['error' => 'Falta a chave da Anthropic no ficheiro config.local.php do servidor.']); exit;
    }

    $r = $chamarIA($data);

    // Repetir só nos erros rápidos e temporários (429/500/529).
    // Um timeout NÃO é repetido: a 1ª tentativa já gastou 110s e a segunda
    // só iria estourar o tempo do PHP e devolver uma página em branco.
    if (in_array($r['code'], [429, 500, 529], true)) {
        registarErro('IA 1ª tentativa falhou (HTTP ' . $r['code'] . ') — a repetir');
        sleep(2);
        $r = $chamarIA($data);
    }

    if ($r['err']) {
        registarErro('IA falhou definitivamente: ' . $r['err']);
        $timeout = stripos($r['err'], 'timed out') !== false;
        echo json_encode(['error' => $timeout
            ? 'A IA demorou demasiado tempo a responder. Se for um extrato grande, importa-o em partes ou usa o ficheiro CSV do banco.'
            : 'Erro cURL IA: ' . $r['err']]); exit;
    }

    http_response_code($r['code']);
    echo $r['resp'];
    exit;
}

// ------------------------------------------------------------
// 7) PROXY GOOGLE APPS SCRIPT — retry seguro e validação
// ------------------------------------------------------------
if ($action === 'gas') {
    $raw = file_get_contents('php://input');
    if (!$raw) { echo json_encode(['error' => 'Body vazio']); exit; }
    $data = json_decode($raw, true);
    if (!$data) { echo json_encode(['error' => 'JSON inválido']); exit; }

    exigirLogin($data);
    unset($data['_gasUrl'], $data['token']);

    $chamarGAS = function ($payload, $timeout) {
        $ch = curl_init(GAS_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => ['Content-Type: text/plain'],
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_ENCODING       => '',
        ]);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        curl_close($ch);
        return ['resp' => $resp, 'err' => $err];
    };

    // O Apps Script às vezes devolve HTML de erro com código 200.
    // Só aceitamos respostas que sejam JSON válido.
    $respostaValida = function ($resp) {
        if (empty($resp)) return false;
        json_decode($resp);
        return json_last_error() === JSON_ERROR_NONE;
    };

    // Ações pesadas (varrem o Drive inteiro) precisam de mais tempo.
    // set_time_limit extra garante que o PHP não corta antes do cURL.
    $acoesPesadas = ['listarTodasFaturasDrive', 'sincronizarDrive', 'listarFaturasDrive', 'listarLixeira', 'restaurarLixeira'];
    $acaoGas = $data['action'] ?? '';

    if (in_array($acaoGas, $acoesPesadas, true)) {
        set_time_limit(360); // deixa o PHP aguardar até 6 min pela resposta do GAS
        $r = $chamarGAS($data, 300); // 5 minutos — tempo máximo do Apps Script
        if ($r['err'] || !$respostaValida($r['resp'])) {
            registarErro('GAS ação pesada (' . $acaoGas . ') falhou: ' . $r['err']);
        }
    } else {
        // 2 tentativas de 45s cada + pausa de 2s = 92s < limite de 120s ✓
        $r = $chamarGAS($data, 45);
        if ($r['err'] || !$respostaValida($r['resp'])) {
            registarErro('GAS 1ª tentativa falhou (' . $r['err'] . ') — a repetir');
            sleep(2);
            $r = $chamarGAS($data, 45);
        }
    }

    if ($r['err']) {
        registarErro('GAS falhou definitivamente: ' . $r['err']);
        echo json_encode(['error' => 'Erro cURL GAS: ' . $r['err']]); exit;
    }
    if (!$respostaValida($r['resp'])) {
        registarErro('GAS devolveu resposta inválida: ' . substr((string)$r['resp'], 0, 300));
        echo json_encode(['error' => 'Resposta inválida do Apps Script']); exit;
    }

    http_response_code(200);
    echo $r['resp'];
    exit;
}

echo json_encode(['error' => 'Acção desconhecida: ' . $action]);
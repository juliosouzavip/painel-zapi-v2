<?php
/**
 * FUNÇÕES COMPARTILHADAS
 * Todos os outros arquivos incluem este.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/push.php';

date_default_timezone_set(FUSO);

if (MODO_TESTE) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED);
}

/* ==================== banco ==================== */

function bd(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $dsn = 'mysql:host=' . BD_HOST . ';dbname=' . BD_NOME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, BD_USER, BD_SENHA, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    return $pdo;
}

function registrar(string $tipo, $detalhe = null): void
{
    try {
        $sql = 'INSERT INTO eventos (tipo, detalhe) VALUES (?, ?)';
        bd()->prepare($sql)->execute([
            $tipo,
            is_string($detalhe) ? $detalhe : json_encode($detalhe, JSON_UNESCAPED_UNICODE),
        ]);
    } catch (Throwable $e) {
        // Registrar não pode derrubar o pedido principal.
    }
}

/* ==================== respostas JSON ==================== */

function responder(array $dados, int $codigo = 200): void
{
    http_response_code($codigo);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

function corpoJson(): array
{
    $bruto = file_get_contents('php://input');
    if ($bruto === false || $bruto === '') {
        return [];
    }
    $dados = json_decode($bruto, true);
    return is_array($dados) ? $dados : [];
}

/* ==================== login ==================== */

/** Quanto tempo a lembrança do login vale. Um ano. */
const LEMBRANCA_DIAS = 365;
const LEMBRANCA_COOKIE = 'vip_lembra';

function iniciarSessao(): void
{
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    // A sessão do PHP some quando o servidor faz limpeza — por isso ela
    // sozinha não basta para "ficar logado como no WhatsApp". Ainda assim,
    // esticamos o prazo dela: evita ida ao banco a cada página.
    $ano = 60 * 60 * 24 * LEMBRANCA_DIAS;
    @ini_set('session.gc_maxlifetime', (string) $ano);

    session_set_cookie_params([
        'lifetime' => $ano,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
    session_start();
}

/**
 * Guarda no aparelho uma lembrança do login, para não pedir senha de novo.
 *
 * O que vai no cookie é um código aleatório; no banco fica só o hash dele.
 * Assim, quem conseguir ler o banco não consegue entrar com o que achar.
 */
function lembrarLogin(string $nome): void
{
    // Se a página já começou a sair para o navegador, o cookie é
    // recusado em silêncio. Melhor saber do que falhar sem aviso.
    if (headers_sent($arquivo, $linha)) {
        registrar('lembranca_tarde_demais', ['arquivo' => $arquivo, 'linha' => $linha]);
        return;
    }

    try {
        $chave = bin2hex(random_bytes(32));
        $hash  = hash('sha256', $chave);

        $aparelho = substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

        $sql = 'INSERT INTO sessoes (nome, chave_hash, aparelho) VALUES (?, ?, ?)';
        bd()->prepare($sql)->execute([$nome, $hash, $aparelho]);

        setcookie(LEMBRANCA_COOKIE, $chave, [
            'expires'  => time() + 60 * 60 * 24 * LEMBRANCA_DIAS,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure'   => !empty($_SERVER['HTTPS']),
        ]);
    } catch (Throwable $e) {
        // sem a tabela ainda: segue só com a sessão normal
    }
}

/** Tenta reconhecer o aparelho pela lembrança guardada. */
function reconhecerPelaLembranca(): ?string
{
    $chave = (string) ($_COOKIE[LEMBRANCA_COOKIE] ?? '');
    if ($chave === '') {
        return null;
    }

    try {
        $hash = hash('sha256', $chave);
        $st = bd()->prepare('SELECT nome FROM sessoes WHERE chave_hash = ?');
        $st->execute([$hash]);
        $nome = $st->fetchColumn();

        if (!$nome) {
            return null;
        }

        // marca uso, para saber quais aparelhos estão vivos
        bd()->prepare('UPDATE sessoes SET ultimo_uso = NOW() WHERE chave_hash = ?')
            ->execute([$hash]);

        return (string) $nome;
    } catch (Throwable $e) {
        return null;
    }
}

/** Apaga a lembrança deste aparelho. Só ao clicar em "sair". */
function esquecerLogin(): void
{
    $chave = (string) ($_COOKIE[LEMBRANCA_COOKIE] ?? '');
    if ($chave !== '') {
        try {
            bd()->prepare('DELETE FROM sessoes WHERE chave_hash = ?')
                ->execute([hash('sha256', $chave)]);
        } catch (Throwable $e) { /* já não existe */ }
    }

    setcookie(LEMBRANCA_COOKIE, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']),
    ]);
}

function usuarioAtual(): ?string
{
    iniciarSessao();

    if (!empty($_SESSION['nome'])) {
        // Está logado mas sem lembrança guardada? Cria agora.
        //
        // Antes isto só acontecia no momento do login, e quem já estava
        // dentro nunca ganhava uma — continuava a depender da sessão do
        // PHP, que o servidor apaga sozinho depois de um tempo.
        if (empty($_COOKIE[LEMBRANCA_COOKIE])) {
            lembrarLogin($_SESSION['nome']);
        }
        return $_SESSION['nome'];
    }

    // A sessão caiu, mas o aparelho pode estar lembrado: volta sozinho.
    $nome = reconhecerPelaLembranca();
    if ($nome !== null) {
        $_SESSION['nome'] = $nome;
        return $nome;
    }

    return null;
}

/** Para páginas: manda para o login. */
function exigirLoginPagina(): string
{
    $nome = usuarioAtual();
    if ($nome === null) {
        header('Location: entrar.php');
        exit;
    }
    return $nome;
}

/** Para chamadas do painel: devolve erro em JSON. */
function exigirLoginJson(): string
{
    $nome = usuarioAtual();
    if ($nome === null) {
        responder(['ok' => false, 'erro' => 'Sessão expirada. Faça login outra vez.', 'login' => true], 401);
    }
    return $nome;
}

/**
 * Texto do aviso que o webhook põe em pedido novo sem dono.
 * Fica numa constante para o sistema saber distinguir este aviso
 * automático de um aviso que uma pessoa escreveu à mão — só o
 * automático é apagado sozinho ao assumir o pedido.
 */
const AVISO_SEM_DONO = 'Pedido novo pelo WhatsApp — ninguém assumiu ainda';

/**
 * Assume o pedido: põe o dono, apaga o aviso automático e marca
 * contato de hoje. Chamada quando alguém responde ao cliente ou
 * clica em "Eu assumo".
 *
 * Só mexe no dono se ainda não houver um — não rouba pedido de colega.
 */
function assumirPedido(string $pedidoId, string $quem): bool
{
    if ($pedidoId === '' || $quem === '') {
        return false;
    }

    $st = bd()->prepare('SELECT dono, alerta FROM pedidos WHERE id = ?');
    $st->execute([$pedidoId]);
    $p = $st->fetch();
    if ($p === false) {
        return false;
    }

    $mudou = false;
    $dono = trim((string) $p['dono']);
    $alerta = trim((string) $p['alerta']);

    if ($dono === '') {
        bd()->prepare('UPDATE pedidos SET dono = ? WHERE id = ?')->execute([$quem, $pedidoId]);
        $mudou = true;
    }

    // Só apaga o aviso automático. Se alguém escreveu outro aviso à mão,
    // esse fica — foi posto de propósito.
    if ($alerta === AVISO_SEM_DONO) {
        bd()->prepare("UPDATE pedidos SET alerta = '' WHERE id = ?")->execute([$pedidoId]);
        $mudou = true;
    }

    bd()->prepare('UPDATE pedidos SET ultimo_contato = CURDATE() WHERE id = ?')->execute([$pedidoId]);

    return $mudou;
}

/** Dados de quem está logado: nome, função e se assina as mensagens. */
function perfilDe(string $nome): array
{
    static $cache = [];
    if (isset($cache[$nome])) {
        return $cache[$nome];
    }

    try {
        $st = bd()->prepare('SELECT nome, email, funcao, assina FROM usuarios WHERE nome = ?');
        $st->execute([$nome]);
        $u = $st->fetch();
    } catch (Throwable $e) {
        $u = false;
    }

    $cache[$nome] = $u === false
        ? ['nome' => $nome, 'email' => '', 'funcao' => '', 'assina' => 1]
        : $u;

    return $cache[$nome];
}

/**
 * A assinatura que vai no fim da mensagem.
 * Com quatro pessoas num número só, é o que diz ao cliente com quem fala.
 */
function assinaturaDe(string $nome, string $telefone = ''): string
{
    $p = perfilDe($nome);
    if (empty($p['assina'])) {
        return '';
    }

    $funcao = trim((string) ($p['funcao'] ?? ''));

    // Uma linha só. O WhatsApp mostra em itálico o que vem entre
    // _sublinhados_, e o travessão separa melhor que vírgula ou ponto.
    return "\n\n_" . $p['nome'] . ($funcao !== '' ? ' — ' . $funcao : '') . '_';
}


function escapar(?string $t): string
{
    return htmlspecialchars((string) $t, ENT_QUOTES, 'UTF-8');
}

/* ==================== telefone ==================== */

/**
 * Deixa o telefone só com dígitos e com código do país.
 * É isto que faz a mensagem da Meta encontrar o pedido certo:
 * "(11) 98888-7777" e "5511988887777" passam a ser a mesma coisa.
 */
function normalizarTelefone(?string $bruto): string
{
    $digitos = preg_replace('/\D+/', '', (string) $bruto);
    if ($digitos === '') {
        return '';
    }

    // 10 ou 11 dígitos: número brasileiro sem o país
    if (strlen($digitos) <= 11) {
        $digitos = DDI_PADRAO . $digitos;
    }

    return $digitos;
}

/* ==================== pedidos ==================== */

const CAMPOS_PEDIDO = [
    'id', 'data_entrada', 'cliente', 'cpf', 'whatsapp', 'email', 'idioma',
    'telefone', 'origem', 'destino', 'pax', 'data_ida', 'data_volta', 'valor',
    'dono', 'estado', 'alerta', 'ultimo_contato', 'proximo_followup',
    'checklist_briefing', 'checklist', 'motivo_perda', 'notas', 'notas_cliente',
    'cliente_id',
];

const CAMPOS_DATA = ['data_entrada', 'data_ida', 'data_volta', 'ultimo_contato', 'proximo_followup'];

function dataOuNulo($valor): ?string
{
    $v = trim((string) $valor);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : null;
}

/**
 * Devolve todos os pedidos no mesmo formato que o painel já espera,
 * com os anexos e um resumo da conversa embutidos.
 */
function listarPedidos(): array
{
    $pedidos = bd()->query('SELECT * FROM pedidos ORDER BY atualizado_em DESC')->fetchAll();
    if (!$pedidos) {
        return [];
    }

    // anexos agrupados por pedido
    $anexos = [];
    foreach (bd()->query('SELECT id, pedido_id, nome, tipo, criado_em FROM anexos ORDER BY id')->fetchAll() as $a) {
        $anexos[$a['pedido_id']][] = [
            'nome'   => $a['nome'],
            'tipo'   => $a['tipo'],
            'fileId' => (string) $a['id'],
            'data'   => substr((string) $a['criado_em'], 0, 10),
        ];
    }

    // contagem de mensagens e não lidas por telefone
    $sql = 'SELECT telefone,
                   COUNT(*) AS total,
                   MAX(CASE WHEN direcao = \'entrada\' THEN criado_em END) AS ultima_entrada,
                   MAX(criado_em) AS ultima
            FROM mensagens
            GROUP BY telefone';
    $conversas = [];
    foreach (bd()->query($sql)->fetchAll() as $c) {
        $conversas[$c['telefone']] = $c;
    }

    $agora = time();
    $saida = [];

    foreach ($pedidos as $p) {
        $item = [];
        foreach (CAMPOS_PEDIDO as $campo) {
            $item[$campo] = (string) ($p[$campo] ?? '');
        }
        $item['anexos'] = json_encode($anexos[$p['id']] ?? [], JSON_UNESCAPED_UNICODE);

        $c = $conversas[$p['telefone']] ?? null;
        $item['msgs'] = (string) (int) ($c['total'] ?? 0);

        // Janela de 24h: quantas horas ainda dá para escrever texto livre.
        $horas = 0;
        if ($c && !empty($c['ultima_entrada'])) {
            $passou = ($agora - strtotime($c['ultima_entrada'])) / 3600;
            $horas = max(0, 24 - $passou);
        }
        $item['janela'] = (string) round($horas, 1);

        $saida[] = $item;
    }

    return $saida;
}

function salvarPedido(array $dados): string
{
    $id = trim((string) ($dados['id'] ?? ''));
    if ($id === '') {
        $id = 'p' . round(microtime(true) * 1000);
    }

    $valores = ['id' => $id];
    foreach (CAMPOS_PEDIDO as $campo) {
        if ($campo === 'id' || $campo === 'telefone') {
            continue;
        }
        $bruto = $dados[$campo] ?? '';
        $valores[$campo] = in_array($campo, CAMPOS_DATA, true)
            ? dataOuNulo($bruto)
            : trim((string) $bruto);
    }
    $valores['telefone'] = normalizarTelefone($dados['whatsapp'] ?? '');

    $colunas = array_keys($valores);
    $marcas  = implode(', ', array_fill(0, count($colunas), '?'));
    $atualiza = [];
    foreach ($colunas as $col) {
        if ($col !== 'id') {
            $atualiza[] = "$col = VALUES($col)";
        }
    }

    $sql = 'INSERT INTO pedidos (' . implode(', ', $colunas) . ") VALUES ($marcas) "
         . 'ON DUPLICATE KEY UPDATE ' . implode(', ', $atualiza);

    bd()->prepare($sql)->execute(array_values($valores));

    // Amarra as mensagens que chegaram antes deste pedido existir.
    if ($valores['telefone'] !== '') {
        $sql = 'UPDATE mensagens SET pedido_id = ? WHERE telefone = ? AND pedido_id IS NULL';
        bd()->prepare($sql)->execute([$id, $valores['telefone']]);
    }

    return $id;
}

/**
 * Acha o pedido de um telefone. Prefere o que ainda está vivo
 * comercialmente; se não houver nenhum, o mais recente.
 */
function pedidoDoTelefone(string $telefone): ?array
{
    if ($telefone === '') {
        return null;
    }

    $sql = "SELECT * FROM pedidos
             WHERE telefone = ?
             ORDER BY (estado IN ('Novo','Briefing feito','Orçamento enviado','Em follow-up')) DESC,
                      atualizado_em DESC
             LIMIT 1";
    $st = bd()->prepare($sql);
    $st->execute([$telefone]);
    $achado = $st->fetch();

    return $achado === false ? null : $achado;
}

/* ==================== mensagens ==================== */

/**
 * Guarda uma mensagem. Chamada tanto pelo webhook (entrada)
 * como pelo envio (saída). O wamid impede duplicar quando a
 * Meta reenvia o mesmo aviso.
 */
function guardarMensagem(array $m): ?int
{
    $telefone = normalizarTelefone($m['telefone'] ?? '');
    if ($telefone === '') {
        return null;
    }

    $pedido = $m['pedido_id'] ?? null;
    if ($pedido === null) {
        $achado = pedidoDoTelefone($telefone);
        $pedido = $achado['id'] ?? null;
    }

    $sql = 'INSERT INTO mensagens
              (pedido_id, telefone, direcao, tipo, texto, anexo_id,
               midia_id, midia_nome, midia_tipo,
               wamid, estado_envio, erro, autor, criado_em)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              estado_envio = VALUES(estado_envio),
              erro = VALUES(erro)';

    bd()->prepare($sql)->execute([
        $pedido,
        $telefone,
        ($m['direcao'] ?? 'entrada') === 'saida' ? 'saida' : 'entrada',
        substr((string) ($m['tipo'] ?? 'text'), 0, 20),
        $m['texto'] ?? null,
        $m['anexo_id'] ?? null,
        substr((string) ($m['midia_id'] ?? ''), 0, 160),
        substr((string) ($m['midia_nome'] ?? ''), 0, 255),
        substr((string) ($m['midia_tipo'] ?? ''), 0, 120),
        !empty($m['wamid']) ? $m['wamid'] : null,
        (string) ($m['estado_envio'] ?? ''),
        substr((string) ($m['erro'] ?? ''), 0, 255),
        (string) ($m['autor'] ?? ''),
        $m['criado_em'] ?? date('Y-m-d H:i:s'),
    ]);

    $id = (int) bd()->lastInsertId();

    // Mensagem do cliente = contato de hoje. Zera o contador do painel.
    if ($pedido !== null && ($m['direcao'] ?? 'entrada') === 'entrada') {
        bd()->prepare('UPDATE pedidos SET ultimo_contato = CURDATE() WHERE id = ?')->execute([$pedido]);
    }

    return $id ?: null;
}

function conversa(string $telefone, int $limite = 200): array
{
    $telefone = normalizarTelefone($telefone);
    if ($telefone === '') {
        return [];
    }

    $sql = 'SELECT m.*, a.nome AS anexo_nome, a.tipo AS anexo_tipo,
                   u.funcao AS autor_funcao
              FROM mensagens m
         LEFT JOIN anexos a ON a.id = m.anexo_id
         LEFT JOIN usuarios u ON u.nome = m.autor
             WHERE m.telefone = ?
          ORDER BY m.id DESC
             LIMIT ' . (int) $limite;

    $st = bd()->prepare($sql);
    $st->execute([$telefone]);

    return array_reverse($st->fetchAll());
}

/** Horas restantes da janela de 24h. 0 = fechada, só modelo aprovado. */
function janelaAberta(string $telefone): float
{
    $telefone = normalizarTelefone($telefone);
    $sql = "SELECT MAX(criado_em) FROM mensagens WHERE telefone = ? AND direcao = 'entrada'";
    $st = bd()->prepare($sql);
    $st->execute([$telefone]);
    $ultima = $st->fetchColumn();

    if (!$ultima) {
        return 0.0;
    }

    $horas = 24 - ((time() - strtotime($ultima)) / 3600);
    return $horas > 0 ? round($horas, 1) : 0.0;
}

/* ==================== anexos ==================== */

function pastaAnexos(): string
{
    $pasta = rtrim(PASTA_ANEXOS, '/');
    if (!is_dir($pasta)) {
        @mkdir($pasta, 0750, true);
    }
    return $pasta;
}

/** Grava bytes em disco e registra na tabela. Devolve o id. */
function guardarAnexo(?string $pedidoId, string $nome, string $tipo, string $bytes, string $origem = 'painel'): int
{
    if (strlen($bytes) > TAMANHO_MAXIMO_MB * 1024 * 1024) {
        throw new RuntimeException('Arquivo maior que ' . TAMANHO_MAXIMO_MB . ' MB.');
    }

    $nome = trim($nome) !== '' ? basename(trim($nome)) : 'documento';
    $extensao = strtolower((string) pathinfo($nome, PATHINFO_EXTENSION));

    // Áudio e imagem do WhatsApp chegam sem nome de arquivo. Sem extensão,
    // o navegador não sabe tocar. Deduzimos pelo tipo informado pela Meta.
    if ($extensao === '') {
        $base = strtolower(trim(explode(';', $tipo)[0]));   // "audio/ogg; codecs=opus" -> "audio/ogg"
        $porTipo = [
            'audio/ogg'  => 'ogg',  'audio/opus' => 'ogg',
            'audio/mpeg' => 'mp3',  'audio/mp4'  => 'mp4',
            'audio/aac'  => 'aac',  'audio/amr'  => 'amr',
            'audio/webm' => 'webm',
            'image/jpeg' => 'jpg',  'image/png'  => 'png',
            'image/webp' => 'webp', 'image/heic' => 'heic',
            'video/mp4'  => 'mp4',
            'application/pdf' => 'pdf',
        ];
        if (isset($porTipo[$base])) {
            $extensao = $porTipo[$base];
            $nome .= '.' . $extensao;
        }
    }
    $permitidas = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'heic', 'txt',
                   'ogg', 'opus', 'mp3', 'mp4', 'm4a', 'aac', 'amr', 'webm', 'wav'];
    if ($extensao !== '' && !in_array($extensao, $permitidas, true)) {
        throw new RuntimeException('Tipo de arquivo não permitido: .' . $extensao);
    }

    $arquivo = bin2hex(random_bytes(16)) . ($extensao !== '' ? '.' . $extensao : '');
    $caminho = pastaAnexos() . '/' . $arquivo;

    if (file_put_contents($caminho, $bytes) === false) {
        throw new RuntimeException('Não consegui gravar o arquivo. Verifique a permissão da pasta de anexos.');
    }

    $sql = 'INSERT INTO anexos (pedido_id, nome, tipo, tamanho, arquivo, origem)
            VALUES (?, ?, ?, ?, ?, ?)';
    bd()->prepare($sql)->execute([
        $pedidoId ?: null, $nome, $tipo, strlen($bytes), $arquivo, $origem,
    ]);

    return (int) bd()->lastInsertId();
}

/* ==================== WhatsApp Cloud API ==================== */

/**
 * Encontra o cliente pelo telefone, ou cria um novo.
 *
 * Chamado quando chega mensagem de um número desconhecido: assim a
 * agenda cresce sozinha, sem ninguém ter de cadastrar à mão.
 */
function acharOuCriarCliente(string $telefone, string $nome = ''): ?int
{
    $telefone = normalizarTelefone($telefone);
    if ($telefone === '') {
        return null;
    }

    try {
        $st = bd()->prepare('SELECT id, nome FROM clientes WHERE whatsapp = ?');
        $st->execute([$telefone]);
        $c = $st->fetch();

        if ($c) {
            // o cliente existia sem nome e agora sabemos o nome dele
            if ($nome !== '' && trim((string) $c['nome']) === '') {
                bd()->prepare('UPDATE clientes SET nome = ? WHERE id = ?')
                    ->execute([$nome, $c['id']]);
            }
            return (int) $c['id'];
        }

        bd()->prepare('INSERT INTO clientes (nome, whatsapp, origem) VALUES (?, ?, ?)')
            ->execute([$nome, $telefone, 'WhatsApp direto']);

        return (int) bd()->lastInsertId();

    } catch (Throwable $e) {
        return null;   // tabela ainda não existe: o pedido é criado sem ligação
    }
}

/* ==================== token do WhatsApp ==================== */

/**
 * O token vem do banco, não do config.php.
 *
 * A Meta só nos deixa gerar tokens de 24h, e editar o config.php todo
 * dia é arriscado — uma aspa trocada derruba o site inteiro. Guardar no
 * banco deixa a troca ser feita pela tela token.php, com teste antes.
 *
 * Se não houver nada no banco, cai para o config.php: assim nada quebra
 * enquanto a tela nova não for usada pela primeira vez.
 */
function tokenAtual(): string
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    try {
        $v = bd()->query("SELECT valor FROM ajustes WHERE chave = 'wa_token'")->fetchColumn();
        if ($v) {
            return $cache = (string) $v;
        }
    } catch (Throwable $e) {
        // tabela ainda não existe: usa o config.php
    }

    return $cache = (defined('WA_TOKEN') ? WA_TOKEN : '');
}

function guardarToken(string $token, string $quem): void
{
    $sql = "INSERT INTO ajustes (chave, valor, por, criado_em)
            VALUES ('wa_token', ?, ?, NOW())
            ON DUPLICATE KEY UPDATE valor = VALUES(valor), por = VALUES(por),
                                    criado_em = VALUES(criado_em)";
    bd()->prepare($sql)->execute([$token, $quem]);
    registrar('token_trocado', ['por' => $quem]);
}

function tokenQuemSalvou(): string
{
    try {
        return (string) (bd()->query("SELECT por FROM ajustes WHERE chave = 'wa_token'")
                             ->fetchColumn() ?: 'config.php');
    } catch (Throwable $e) {
        return 'config.php';
    }
}

function tokenQuando(): string
{
    try {
        $d = bd()->query("SELECT criado_em FROM ajustes WHERE chave = 'wa_token'")->fetchColumn();
        return $d ? date('d/m/Y H:i', strtotime((string) $d)) : '—';
    } catch (Throwable $e) {
        return '—';
    }
}

/**
 * Quantos dias faltam para o token caducar.
 *
 * A Meta não avisa quando o token está a expirar — a pessoa só descobre
 * quando o envio para. Como os tokens de usuário do sistema costumam
 * durar 60 dias, contamos a partir do dia em que foi salvo.
 *
 * Devolve null quando não há como saber.
 */
function tokenDiasRestantes(int $validade = 60): ?int
{
    try {
        $d = bd()->query("SELECT criado_em FROM ajustes WHERE chave = 'wa_token'")->fetchColumn();
        if (!$d) {
            return null;
        }
        $salvo = strtotime((string) $d);
        $passados = (int) floor((time() - $salvo) / 86400);
        return $validade - $passados;
    } catch (Throwable $e) {
        return null;
    }
}

/** Pergunta à Meta se o token serve. Usado antes de guardar. */
function testarToken(string $token): array
{
    if ($token === '' || !defined('WA_PHONE_NUMBER_ID') || WA_PHONE_NUMBER_ID === '') {
        return ['ok' => false, 'erro' => 'Falta o número configurado.'];
    }

    $url = 'https://graph.facebook.com/' . WA_VERSAO_API . '/' . WA_PHONE_NUMBER_ID;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
    ]);
    $resposta = curl_exec($ch);
    $codigo = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erroRede = curl_error($ch);
    curl_close($ch);

    if ($resposta === false) {
        return ['ok' => false, 'erro' => 'Sem resposta da Meta: ' . $erroRede];
    }

    $d = json_decode((string) $resposta, true);
    if (!is_array($d)) {
        return ['ok' => false, 'erro' => 'Resposta inesperada da Meta.'];
    }
    if ($codigo >= 400 || isset($d['error'])) {
        return ['ok' => false, 'erro' => $d['error']['message'] ?? ('Erro HTTP ' . $codigo)];
    }

    return ['ok' => true, 'numero' => (string) ($d['display_phone_number'] ?? '?')];
}

function whatsappConfigurado(): bool
{
    return tokenAtual() !== '' && WA_PHONE_NUMBER_ID !== '';
}

function chamarMeta(string $caminho, ?array $corpo = null, string $metodo = 'POST'): array
{
    $url = 'https://graph.facebook.com/' . WA_VERSAO_API . '/' . ltrim($caminho, '/');

    $ch = curl_init($url);
    $opcoes = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . tokenAtual()],
    ];

    if ($corpo !== null) {
        $opcoes[CURLOPT_POST] = true;
        $opcoes[CURLOPT_POSTFIELDS] = json_encode($corpo, JSON_UNESCAPED_UNICODE);
        $opcoes[CURLOPT_HTTPHEADER][] = 'Content-Type: application/json';
    }
    if ($metodo === 'GET') {
        $opcoes[CURLOPT_POST] = false;
    }

    curl_setopt_array($ch, $opcoes);
    $resposta = curl_exec($ch);
    $codigo   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erroCurl = curl_error($ch);
    curl_close($ch);

    if ($resposta === false) {
        return ['ok' => false, 'erro' => 'Falha de rede: ' . $erroCurl];
    }

    $dados = json_decode((string) $resposta, true);
    if (!is_array($dados)) {
        return ['ok' => false, 'erro' => 'Resposta inesperada da Meta.', 'bruto' => (string) $resposta];
    }
    if ($codigo >= 400) {
        $msg = $dados['error']['message'] ?? 'Erro HTTP ' . $codigo;
        return ['ok' => false, 'erro' => $msg, 'detalhe' => $dados];
    }

    return ['ok' => true, 'dados' => $dados];
}

/** Envia texto livre. Só funciona com a janela de 24h aberta. */
function enviarTexto(string $telefone, string $texto, string $autor, bool $assinar = true): array
{
    if (!whatsappConfigurado()) {
        return ['ok' => false, 'erro' => 'WhatsApp sem token válido. Abra a tela Token e cole um token novo.'];
    }

    $telefone = normalizarTelefone($telefone);
    if ($telefone === '') {
        return ['ok' => false, 'erro' => 'Telefone vazio.'];
    }
    if (trim($texto) === '') {
        return ['ok' => false, 'erro' => 'Mensagem vazia.'];
    }

    if (janelaAberta($telefone) <= 0) {
        return [
            'ok' => false,
            'janelaFechada' => true,
            'erro' => 'Passaram mais de 24h desde a última mensagem do cliente. '
                    . 'A Meta não aceita texto livre agora — use um modelo aprovado.',
        ];
    }

    // Assina, a não ser que a pessoa tenha desmarcado nesta mensagem
    // ou que o texto já termine com uma assinatura.
    $corpo = $texto;
    if ($assinar && strpos($texto, "\n_") === false) {
        $corpo .= assinaturaDe($autor, $telefone);
    }

    $resultado = chamarMeta(WA_PHONE_NUMBER_ID . '/messages', [
        'messaging_product' => 'whatsapp',
        'to'                => $telefone,
        'type'              => 'text',
        'text'              => ['preview_url' => true, 'body' => $corpo],
    ]);

    $wamid = $resultado['dados']['messages'][0]['id'] ?? null;

    guardarMensagem([
        'telefone'     => $telefone,
        'direcao'      => 'saida',
        'tipo'         => 'text',
        'texto'        => $corpo,
        'wamid'        => $wamid,
        'estado_envio' => $resultado['ok'] ? 'enviada' : 'falhou',
        'erro'         => $resultado['ok'] ? '' : ($resultado['erro'] ?? ''),
        'autor'        => $autor,
    ]);

    if (!$resultado['ok']) {
        registrar('envio_falhou', $resultado);
    } else {
        // Respondeu ao cliente: o pedido passa a ser desta pessoa.
        $pedido = pedidoDoTelefone($telefone);
        if ($pedido) {
            assumirPedido((string) $pedido['id'], $autor);
        }
    }

    return $resultado;
}

/** Envia um modelo aprovado. É o que funciona fora das 24h. */
/**
 * Pergunta à Meta como o modelo foi realmente aprovado.
 *
 * A Meta passou a criar modelos com variáveis NOMEADAS ({{nome}}) além
 * das numeradas ({{1}}). O envio tem de usar o mesmo formato — senão
 * ela recusa com "Parameter format does not match format in the
 * created template".
 *
 * Guardamos o resultado por uma hora para não perguntar a cada envio.
 */
function formatoDoModelo(string $modelo, string $idioma = 'pt_BR'): ?array
{
    static $cache = [];
    $chave = $modelo . '|' . $idioma;

    if (isset($cache[$chave])) {
        return $cache[$chave];
    }

    if (!defined('WA_WABA_ID') || WA_WABA_ID === '') {
        return null;   // sem a conta configurada não dá para perguntar
    }

    $r = chamarMeta(WA_WABA_ID . '/message_templates?name=' . urlencode($modelo)
                    . '&limit=20', null, 'GET');

    if (!$r['ok']) {
        return null;
    }

    foreach (($r['dados']['data'] ?? []) as $m) {
        if (($m['name'] ?? '') !== $modelo) {
            continue;
        }
        if ($idioma !== '' && ($m['language'] ?? '') !== $idioma) {
            continue;
        }

        $resposta = ['nomeadas' => [], 'quantas' => 0, 'texto' => '',
                     'cabecalho' => 0, 'cabecalhoTipo' => ''];

        foreach (($m['components'] ?? []) as $c) {
            $tipo = strtoupper((string) ($c['type'] ?? ''));
            $texto = (string) ($c['text'] ?? '');

            // Nomeadas aparecem como {{nome_do_campo}}; numeradas como {{1}}
            preg_match_all('/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}/', $texto, $nomeadas);
            preg_match_all('/\{\{(\d+)\}\}/', $texto, $numeradas);
            $quantas = count($nomeadas[1] ?? []) ?: count($numeradas[1] ?? []);

            if ($tipo === 'BODY') {
                $resposta['nomeadas'] = $nomeadas[1] ?? [];
                $resposta['quantas']  = $quantas;
                $resposta['texto']    = $texto;
            } elseif ($tipo === 'HEADER') {
                $resposta['cabecalho'] = $quantas;
                // TEXT, IMAGE, VIDEO ou DOCUMENT — cada um manda diferente
                $resposta['cabecalhoTipo'] = strtoupper((string) ($c['format'] ?? 'TEXT'));
            }
        }

        return $cache[$chave] = $resposta;
    }

    return $cache[$chave] = null;
}

function enviarModelo(string $telefone, string $modelo, string $idioma, array $variaveis, string $autor): array
{
    if (!whatsappConfigurado()) {
        return ['ok' => false, 'erro' => 'WhatsApp ainda não configurado.'];
    }

    $telefone = normalizarTelefone($telefone);
    $componentes = [];

    // Descobre como o modelo foi aprovado, para mandar no formato certo.
    $formato = formatoDoModelo($modelo, $idioma ?: 'pt_BR');

    // O cabeçalho tem de ser preenchido em cada envio — a Meta não guarda
    // o exemplo do modelo para reutilizar. Se faltar, ela recusa com
    // 132012 sem dizer onde está o problema.
    $tipoCab = $formato['cabecalhoTipo'] ?? '';

    if ($tipoCab === 'IMAGE') {
        // Sempre a mesma imagem: o logo da agência, hospedado no site.
        if (defined('MODELO_IMAGEM') && MODELO_IMAGEM !== '') {
            $componentes[] = [
                'type' => 'header',
                'parameters' => [
                    ['type' => 'image', 'image' => ['link' => MODELO_IMAGEM]],
                ],
            ];
        }
    } elseif ($tipoCab === 'DOCUMENT') {
        if (defined('MODELO_IMAGEM') && MODELO_IMAGEM !== '') {
            $componentes[] = [
                'type' => 'header',
                'parameters' => [
                    ['type' => 'document',
                     'document' => ['link' => MODELO_IMAGEM, 'filename' => 'VIP Turismo Paris']],
                ],
            ];
        }
    } elseif (!empty($formato['cabecalho']) && $variaveis) {
        // cabeçalho de texto com variável
        $paramsCab = [];
        for ($i = 0; $i < $formato['cabecalho']; $i++) {
            $paramsCab[] = ['type' => 'text', 'text' => (string) ($variaveis[$i] ?? '')];
        }
        $componentes[] = ['type' => 'header', 'parameters' => $paramsCab];
    }

    if ($variaveis) {
        $parametros = [];

        if ($formato && $formato['nomeadas']) {
            // Modelo com variáveis nomeadas: cada valor leva o nome do campo
            foreach ($formato['nomeadas'] as $i => $nome) {
                $parametros[] = [
                    'type' => 'text',
                    'parameter_name' => $nome,
                    'text' => (string) ($variaveis[$i] ?? ''),
                ];
            }
        } else {
            // Modelo com {{1}}, {{2}}: só os valores, em ordem
            foreach ($variaveis as $v) {
                $parametros[] = ['type' => 'text', 'text' => (string) $v];
            }
        }

        $componentes[] = ['type' => 'body', 'parameters' => $parametros];
    }

    $corpo = [
        'messaging_product' => 'whatsapp',
        'to'                => $telefone,
        'type'              => 'template',
        'template'          => [
            'name'     => $modelo,
            'language' => ['code' => $idioma ?: 'pt_BR'],
        ],
    ];
    if ($componentes) {
        $corpo['template']['components'] = $componentes;
    }

    $resultado = chamarMeta(WA_PHONE_NUMBER_ID . '/messages', $corpo);
    $wamid = $resultado['dados']['messages'][0]['id'] ?? null;

    // O erro 132012 é sempre a mesma coisa: número ou formato de
    // variáveis diferente do que foi aprovado. Vale explicar.
    if (!$resultado['ok'] && strpos((string) ($resultado['erro'] ?? ''), '132012') !== false) {
        $esperado = $formato ? $formato['quantas'] : '?';
        $extra = '';

        if (($formato['cabecalhoTipo'] ?? '') === 'IMAGE'
            && (!defined('MODELO_IMAGEM') || MODELO_IMAGEM === '')) {
            $extra = ' Este modelo tem imagem no cabeçalho e falta definir '
                   . 'MODELO_IMAGEM no config.php.';
        }

        $resultado['erro'] = 'O modelo "' . $modelo . '" espera ' . $esperado
            . ' campo(s) e recebeu ' . count($variaveis) . '.' . $extra
            . ' Confira em Modelos como ele foi aprovado.';
    }

    guardarMensagem([
        'telefone'     => $telefone,
        'direcao'      => 'saida',
        'tipo'         => 'template',
        'texto'        => '[modelo: ' . $modelo . '] ' . implode(' · ', $variaveis),
        'wamid'        => $wamid,
        'estado_envio' => $resultado['ok'] ? 'enviada' : 'falhou',
        'erro'         => $resultado['ok'] ? '' : ($resultado['erro'] ?? ''),
        'autor'        => $autor,
    ]);

    return $resultado;
}

/**
 * Manda um arquivo para os servidores da Meta e devolve o id dele.
 * É preciso este passo antes de enviar áudio, imagem ou PDF: a Meta
 * não aceita o arquivo junto com a mensagem, só a referência.
 */
function subirMidiaParaMeta(string $caminho, string $mime): array
{
    if (!whatsappConfigurado()) {
        return ['ok' => false, 'erro' => 'WhatsApp não configurado.'];
    }
    if (!is_file($caminho)) {
        return ['ok' => false, 'erro' => 'Arquivo não encontrado no servidor.'];
    }

    $url = 'https://graph.facebook.com/' . WA_VERSAO_API . '/' . WA_PHONE_NUMBER_ID . '/media';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . tokenAtual()],
        CURLOPT_POSTFIELDS     => [
            'messaging_product' => 'whatsapp',
            'type'              => $mime,
            'file'              => new CURLFile($caminho, $mime, basename($caminho)),
        ],
    ]);

    $resposta = curl_exec($ch);
    $codigo   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $erroRede = curl_error($ch);
    curl_close($ch);

    if ($resposta === false) {
        return ['ok' => false, 'erro' => 'Falha de rede ao enviar o arquivo: ' . $erroRede];
    }

    $dados = json_decode((string) $resposta, true);
    if (!is_array($dados)) {
        return ['ok' => false, 'erro' => 'Resposta inesperada da Meta ao enviar o arquivo.'];
    }
    if ($codigo >= 400 || empty($dados['id'])) {
        $msg = $dados['error']['message'] ?? ('Erro HTTP ' . $codigo);
        registrar('midia_upload_falhou', $dados);
        return ['ok' => false, 'erro' => $msg];
    }

    return ['ok' => true, 'media_id' => (string) $dados['id']];
}

/**
 * Envia um anexo já guardado aqui (áudio, imagem ou documento) pelo WhatsApp.
 * $formato: audio | image | document
 */
function enviarMidia(string $telefone, int $anexoId, string $formato, string $legenda, string $autor): array
{
    if (!whatsappConfigurado()) {
        return ['ok' => false, 'erro' => 'WhatsApp ainda não configurado.'];
    }

    $telefone = normalizarTelefone($telefone);
    if ($telefone === '') {
        return ['ok' => false, 'erro' => 'Telefone vazio.'];
    }

    if (janelaAberta($telefone) <= 0) {
        return [
            'ok' => false,
            'janelaFechada' => true,
            'erro' => 'Passaram mais de 24h desde a última mensagem do cliente. '
                    . 'A Meta não aceita envio livre agora.',
        ];
    }

    $st = bd()->prepare('SELECT nome, tipo, arquivo FROM anexos WHERE id = ?');
    $st->execute([$anexoId]);
    $a = $st->fetch();
    if ($a === false) {
        return ['ok' => false, 'erro' => 'Anexo não encontrado.'];
    }

    $caminho = pastaAnexos() . '/' . basename($a['arquivo']);
    $mime = trim(explode(';', (string) $a['tipo'])[0]);

    $subida = subirMidiaParaMeta($caminho, $mime);
    if (!$subida['ok']) {
        guardarMensagem([
            'telefone' => $telefone, 'direcao' => 'saida', 'tipo' => $formato,
            'texto' => $legenda !== '' ? $legenda : ('[' . $formato . ']'),
            'anexo_id' => $anexoId, 'estado_envio' => 'falhou',
            'erro' => $subida['erro'] ?? '', 'autor' => $autor,
        ]);
        return $subida;
    }

    $permitidos = ['audio', 'image', 'document'];
    if (!in_array($formato, $permitidos, true)) {
        $formato = 'document';
    }

    $conteudo = ['id' => $subida['media_id']];
    // Áudio não aceita legenda; documento e imagem aceitam.
    if ($formato !== 'audio' && $legenda !== '') {
        $conteudo['caption'] = $legenda;
    }
    if ($formato === 'document') {
        $conteudo['filename'] = $a['nome'];
    }

    $resultado = chamarMeta(WA_PHONE_NUMBER_ID . '/messages', [
        'messaging_product' => 'whatsapp',
        'to'                => $telefone,
        'type'              => $formato,
        $formato            => $conteudo,
    ]);

    $wamid = $resultado['dados']['messages'][0]['id'] ?? null;

    guardarMensagem([
        'telefone'     => $telefone,
        'direcao'      => 'saida',
        'tipo'         => $formato,
        'texto'        => $legenda !== '' ? $legenda : ('[' . $formato . ' enviado]'),
        'anexo_id'     => $anexoId,
        'wamid'        => $wamid,
        'estado_envio' => $resultado['ok'] ? 'enviada' : 'falhou',
        'erro'         => $resultado['ok'] ? '' : ($resultado['erro'] ?? ''),
        'autor'        => $autor,
    ]);

    if (!$resultado['ok']) {
        registrar('envio_midia_falhou', $resultado);
    } else {
        $pedido = pedidoDoTelefone($telefone);
        if ($pedido) {
            assumirPedido((string) $pedido['id'], $autor);
        }
    }

    return $resultado;
}

/**
 * Baixa as mídias que o webhook deixou pendentes nesta conversa.
 *
 * Chamada ao abrir a conversa, não pelo webhook. Aqui pode demorar:
 * quem espera é uma pessoa, não a Meta. Limitamos a poucas por vez
 * para a página não travar.
 */
function baixarMidiasPendentes(string $telefone, int $limite = 4): int
{
    $telefone = normalizarTelefone($telefone);
    if ($telefone === '' || !whatsappConfigurado()) {
        return 0;
    }

    $sql = "SELECT id, pedido_id, midia_id, midia_nome, midia_tipo
              FROM mensagens
             WHERE telefone = ? AND midia_id <> '' AND anexo_id IS NULL
          ORDER BY id DESC
             LIMIT " . (int) $limite;

    $st = bd()->prepare($sql);
    $st->execute([$telefone]);
    $pendentes = $st->fetchAll();

    $baixadas = 0;
    foreach ($pendentes as $p) {
        $anexoId = baixarMidia(
            (string) $p['midia_id'],
            $p['pedido_id'] ?: null,
            (string) $p['midia_nome'],
            (string) $p['midia_tipo']
        );

        if ($anexoId !== null) {
            bd()->prepare('UPDATE mensagens SET anexo_id = ? WHERE id = ?')
                ->execute([$anexoId, $p['id']]);
            $baixadas++;
        } else {
            // não conseguiu: marca para não tentar em loop toda vez
            bd()->prepare("UPDATE mensagens SET midia_id = '' WHERE id = ?")
                ->execute([$p['id']]);
            registrar('midia_desistiu', ['mensagem' => $p['id'], 'midia' => $p['midia_id']]);
        }
    }

    return $baixadas;
}

/** Baixa da Meta um arquivo que o cliente mandou e guarda aqui. */
function baixarMidia(string $mediaId, ?string $pedidoId, string $nomeSugerido, string $tipo): ?int
{
    $meta = chamarMeta($mediaId, null, 'GET');
    if (!$meta['ok'] || empty($meta['dados']['url'])) {
        registrar('midia_sem_url', $meta);
        return null;
    }

    $ch = curl_init($meta['dados']['url']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . tokenAtual()],
    ]);
    $bytes = curl_exec($ch);
    curl_close($ch);

    if ($bytes === false || $bytes === '') {
        registrar('midia_vazia', ['media_id' => $mediaId]);
        return null;
    }

    try {
        return guardarAnexo($pedidoId, $nomeSugerido, $tipo, (string) $bytes, 'whatsapp');
    } catch (Throwable $e) {
        registrar('midia_erro', $e->getMessage());
        return null;
    }
}

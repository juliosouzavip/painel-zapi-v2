<?php
// =====================================================================
// config.local.exemplo.php  —  MODELO. Não é usado por ninguém.
// =====================================================================
//
// COMO USAR
//   1. Copia este ficheiro para  config.local.php  (na mesma pasta)
//      ou para  ../../config/contabilidade.config.php  (fora da pasta
//      pública — mais seguro, é onde já está a config da base de dados).
//   2. Preenche os valores reais.
//   3. NUNCA envies o config.local.php para o Git/GitHub.
//
// Enquanto a chave não estiver preenchida, a IA responde com uma
// mensagem clara em vez de falhar sem explicação.
// =====================================================================

// Chave da API Anthropic — console.anthropic.com
// ⚠ A chave antiga foi partilhada dentro do upload.php: apaga-a lá e gera uma nova.
define('ANTHROPIC_KEY', 'sk-ant-api03-COLOCAR-A-CHAVE-NOVA-AQUI');

// URL do Google Apps Script (termina em /exec)
define('GAS_URL', 'https://script.google.com/macros/s/COLOCAR-AQUI/exec');

// Frase longa e aleatória, só tua — assina os tokens de sessão.
define('SECRET_TOKEN', 'inventa-aqui-uma-frase-longa-e-aleatoria');

// Utilizadores e senhas (a senha é guardada em hash, nunca em texto).
define('USERS_SERVER', [
    'vip'    => hash('sha256', 'SENHA_NOVA_AQUI'),
    'julio'  => hash('sha256', 'SENHA_NOVA_AQUI'),
    'aline'  => hash('sha256', 'SENHA_NOVA_AQUI'),
    'paulo'  => hash('sha256', 'SENHA_NOVA_AQUI'),
    'uane'   => hash('sha256', 'SENHA_NOVA_AQUI'),
    'view'   => hash('sha256', 'SENHA_NOVA_AQUI'),
    'marisa' => hash('sha256', 'SENHA_NOVA_AQUI'),
]);

// Quem só pode ver, sem editar
define('READONLY_USERS', ['view']);

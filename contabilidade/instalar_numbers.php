<?php
// Script de instalação única — apagar após usar
// Acede a: https://vipturismoparis.com/contabilidade/instalar_numbers.php
$output = shell_exec('pip3 install numbers-parser --break-system-packages 2>&1');
echo "<pre>" . htmlspecialchars($output) . "</pre>";
echo "<br>Feito! Apaga este ficheiro do servidor.";

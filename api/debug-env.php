<?php
// api/debug-env.php
header('Content-Type: text/plain');

echo "1. Tentando getenv: " . getenv('API_SECRET') . "\n";
echo "2. Tentando \$_ENV: " . ($_ENV['API_SECRET'] ?? 'Não definido') . "\n";
echo "3. Tentando \$_SERVER: " . ($_SERVER['API_SECRET'] ?? 'Não definido') . "\n";

echo "\n--- Todas as variáveis disponíveis ---\n";
print_r($_ENV);
?>
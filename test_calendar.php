<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$pdo = get_pdo(); // 👈 AQUI é a parte que faltava

echo "<pre>";
echo "Banco conectado: " . DB_NAME . PHP_EOL;

try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'calendar_events'");
    if ($stmt && $stmt->rowCount() > 0) {
        echo "✅ Tabela calendar_events ENCONTRADA nesse banco.";
    } else {
        echo "❌ Tabela calendar_events NÃO encontrada nesse banco.";
    }
} catch (Exception $e) {
    echo "Erro na consulta: " . $e->getMessage();
}
echo "</pre>";

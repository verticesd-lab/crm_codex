<?php
// Arquivo temporário só para rodar o ALTER TABLE em produção
// DEPOIS DE RODAR, APAGUE ESTE ARQUIVO.

require_once __DIR__ . '/db.php';

try {
    $pdo = get_pdo(); // mesma função que você já usa no sistema

    $sql = "ALTER TABLE products ADD COLUMN sizes VARCHAR(255) NULL";
    $pdo->exec($sql);

    echo "Coluna 'sizes' criada com sucesso na tabela 'products'.";
} catch (PDOException $e) {
    echo "Erro ao criar coluna 'sizes': " . $e->getMessage();
}

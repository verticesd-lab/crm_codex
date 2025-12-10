<?php
// migrate_appointments_status.php
// Script rápido só para ajustar a tabela appointments.

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

try {
    $pdo = get_pdo();

    $sql = "
        ALTER TABLE appointments
            ADD COLUMN status ENUM('agendado','cancelado') NOT NULL DEFAULT 'agendado' AFTER time,
            ADD COLUMN cancelled_at DATETIME NULL AFTER status;
    ";

    $pdo->exec($sql);
    echo '<h2>OK ✅</h2><p>Colunas <strong>status</strong> e <strong>cancelled_at</strong> criadas na tabela <strong>appointments</strong>.</p>';
} catch (PDOException $e) {
    $msg = $e->getMessage();

    // Se já existir as colunas, tratamos como sucesso também
    if (stripos($msg, 'Duplicate column name') !== false) {
        echo '<h2>OK ✅</h2><p>As colunas já existem na tabela <strong>appointments</strong>. Nada a fazer.</p>';
    } else {
        echo '<h2>Erro ❌</h2>';
        echo '<pre>' . htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') . '</pre>';
    }
}

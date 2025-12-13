<?php
// api/echo_teste.php

ini_set('display_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');

try {
    $rawBody = file_get_contents('php://input');
    $data    = json_decode($rawBody, true);

    echo json_encode([
        'ok'      => true,
        'method'  => $_SERVER['REQUEST_METHOD'],
        'get'     => $_GET,
        'rawBody' => $rawBody,
        'json'    => $data,
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok'       => false,
        'erro'     => 'excecao',
        'mensagem' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
}

<?php
declare(strict_types=1);

function env_local(string $key, string $default = ''): string {
  static $env = null;

  if ($env === null) {
    $env = [];

    // 1) tenta carregar .env (se existir)
    $envPath = dirname(__DIR__) . '/.env'; // ajuste se seu .env estiver em outro lugar
    if (is_file($envPath)) {
      foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        $v = trim($v, "\"'");
        $env[$k] = $v;
      }
    }
  }

  // 2) prioridade: getenv (se existir), depois .env, depois default
  $g = getenv($key);
  if ($g !== false && $g !== '') return (string)$g;

  if (isset($env[$key]) && $env[$key] !== '') return (string)$env[$key];

  return $default;
}

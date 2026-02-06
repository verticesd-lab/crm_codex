<?php
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
  'ts' => time(),
  'php' => PHP_VERSION,
  'file' => __FILE__,
  'pwd' => getcwd(),
  'sha' => substr(sha1_file(__FILE__), 0, 12),
], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

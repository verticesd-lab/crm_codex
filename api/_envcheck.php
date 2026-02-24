<?php
header('Content-Type: text/plain; charset=utf-8');
$k = 'WAHA_BRIDGE_TOKEN';
$t = getenv($k) ?: '';
echo "HTTP len=" . strlen($t) . "\n";
echo "HTTP tail=" . ($t ? substr($t, -6) : '') . "\n";

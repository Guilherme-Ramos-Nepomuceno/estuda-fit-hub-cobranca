<?php

// FrankenPHP worker mode: o app sobe uma vez e atende requisições em loop.
require __DIR__ . '/../app.php';

$handler = static fn () => rotear();
for ($n = 0; frankenphp_handle_request($handler); $n++) {
    gc_collect_cycles();
}

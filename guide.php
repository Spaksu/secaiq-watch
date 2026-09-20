<?php
/** Serves GUIDE.md to the "User guide" tab (local machine only). */
require __DIR__ . '/src/Guard.php';
Guard::localOnly();
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache');
readfile(__DIR__ . '/GUIDE.md');

<?php
/* Jalur cadangan untuk sw.js: PHP mengirim kode yang sama dengan jenis file JavaScript yang pasti benar.
   Dipakai otomatis oleh index.html bila sw.js ditolak oleh hosting. */
header('Content-Type: application/javascript; charset=utf-8');
header('Service-Worker-Allowed: /');
header('Cache-Control: no-cache');
readfile(__DIR__ . '/sw.js');

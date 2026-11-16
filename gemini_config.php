<?php
// Konfigurasi obrolan Gemini. Jangan dibagikan atau diunggah ke GitHub publik.
return [
    // Buat kunci GRATIS (tanpa kartu kredit) di https://aistudio.google.com/apikey lalu tempel di sini
    'api_key'    => 'A',
    // Flash-Lite = model paling ringan dan hemat kuota, tersedia di tier gratis
    'model'      => 'gemini-3.1-flash-lite',
    // true = hanya admin yang sudah login yang boleh mengobrol (melindungi kuota gratis)
    'admin_only' => false,
    // Batas jumlah pesan obrolan per hari untuk SEMUA pengunjung digabung (0 = tanpa batas).
    // Bila tercapai, obrolan berhenti sampai besok. Naikkan bila sering habis, turunkan untuk menghemat kuota.
    'daily_limit' => 100,
    // true = tampilkan rincian error koneksi di obrolan (untuk mencari masalah). Matikan setelah selesai.
    'debug'      => false,
];
<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Jumlah baris per halaman
    |--------------------------------------------------------------------------
    |
    | SATU angka untuk seluruh aplikasi. Ditaruh di sini, bukan diketik ulang di
    | tiap komponen, karena daftar yang berbeda-beda panjangnya membuat orang
    | kehilangan pegangan: ia menghitung "tinggal satu halaman lagi" berdasarkan
    | kebiasaan dari layar sebelumnya, lalu keliru di layar berikutnya.
    |
    | Sepuluh dipilih pemilik proyek. Konsekuensinya harus disadari: lembar opname
    | 120 barang berarti 12 kali pindah halaman. Karena itu nilai yang sudah
    | diketik WAJIB bertahan antar-halaman — dan itu sudah dijaga uji
    | "12 baris diketik lalu pindah halaman lalu simpan memproses semuanya".
    |
    */

    'per_halaman' => (int) env('NAMPAN_PER_HALAMAN', 10),

    /*
    |--------------------------------------------------------------------------
    | Umur kabar sisa stok di layar kasir (menit)
    |--------------------------------------------------------------------------
    |
    | Lencana "Habis"/"Menipis" di petak produk kasir berasal dari catatan server,
    | dan catatan itu SELALU tertinggal: kasir lain di perangkat lain menjual barang
    | yang sama, dan perangkat ini bisa saja sedang offline. Karena itu kabarnya
    | punya umur. Lewat batas ini lencananya hilang dan petak kembali tampil apa
    | adanya — seperti sebelum fitur ini ada.
    |
    | Kenapa hilang, bukan dibiarkan: lencana "Habis" berumur enam jam membuat kasir
    | MENOLAK menjual barang yang kirimannya sudah datang — kerugian yang sama
    | dengan masalah yang mau diselesaikan, hanya terbalik arahnya. Tidak tahu lebih
    | jujur daripada tahu yang sudah kedaluwarsa.
    |
    | Tiga puluh menit: cukup panjang supaya lencana tidak berkedip-kedip di warung
    | bersinyal putus-putus, cukup pendek supaya kabar yang salah tidak bertahan
    | melewati satu kiriman barang.
    |
    */

    'sisa_stok_kedaluwarsa_menit' => (int) env('NAMPAN_SISA_STOK_MENIT', 30),

];

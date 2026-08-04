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

];

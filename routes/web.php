<?php

use App\Http\Controllers\Auth\KeluarController;
use App\Http\Controllers\Kasir\KatalogController;
use App\Http\Controllers\Kasir\SisaStokController;
use App\Http\Controllers\OfflineSyncController;
use App\Livewire\Pages\Admin\Dasbor as DasborAdmin;
use App\Livewire\Pages\Auth\Masuk;
use App\Livewire\Pages\Auth\MasukKasir;
use App\Livewire\Pages\Kasir\Beranda as BerandaKasir;
use App\Livewire\Pages\Kasir\Transaksi as TransaksiKasir;
use App\Livewire\Pages\Owner\Dasbor as DasborOwner;
use App\Livewire\Pages\Owner\Langganan;
use App\Livewire\Pages\Owner\Laporan as LaporanOwner;
use App\Livewire\Pages\Owner\Opname as OpnameOwner;
use App\Livewire\Pages\Owner\Pembelian as PembelianOwner;
use App\Livewire\Pages\Owner\PembelianBaru as PembelianBaruOwner;
use App\Livewire\Pages\Owner\Produk as ProdukOwner;
use App\Livewire\Pages\Owner\Stok as StokOwner;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Halaman publik
|--------------------------------------------------------------------------
*/
Route::view('/', 'publik.beranda')->name('beranda');
Route::view('/cara-jualan', 'publik.cara-jualan')->name('cara-jualan');
Route::view('/mode-offline', 'publik.mode-offline')->name('mode-offline');
Route::view('/untuk-siapa', 'publik.untuk-siapa')->name('untuk-siapa');
Route::view('/harga', 'publik.harga')->name('harga');

/*
|--------------------------------------------------------------------------
| Autentikasi
|--------------------------------------------------------------------------
| Dua jalur masuk yang terpisah. Kasir & Dapur tidak boleh lewat jalur email
| karena di jalur itu tidak ada pemeriksaan device binding.
*/
Route::middleware('guest')->group(function () {
    Route::get('/masuk', Masuk::class)->name('masuk');
    Route::get('/masuk/kasir', MasukKasir::class)->name('masuk.kasir');
});

Route::post('/keluar', KeluarController::class)->middleware('auth')->name('keluar');

/*
|--------------------------------------------------------------------------
| Area pengelola platform
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'peran:super_admin'])
    ->prefix('platform')
    ->name('admin.')
    ->group(function () {
        Route::get('/', DasborAdmin::class)->name('dasbor');
    });

/*
|--------------------------------------------------------------------------
| Area back office merchant
|--------------------------------------------------------------------------
| Halaman langganan SENGAJA di luar gerbang tenant-aktif: ke sini merchant yang
| suspend dialihkan, jadi kalau ikut diblokir ia tidak punya jalan membayar.
*/
Route::middleware(['auth', 'peran:owner,regional_manager,manager_outlet'])
    ->prefix('kelola')
    ->name('owner.')
    ->group(function () {
        Route::get('/langganan', Langganan::class)->name('langganan');

        Route::middleware('tenant-aktif')->group(function () {
            Route::get('/', DasborOwner::class)->name('dasbor');
            Route::get('/produk', ProdukOwner::class)->name('produk');

            /*
             * Stok & opname sengaja HANYA di grup back office ini.
             *
             * Kasir tidak boleh membukanya: menghitung fisik adalah wewenang pemilik, dan
             * membukanya untuk kasir berarti selisih bisa ditutup sendiri oleh orang yang
             * memegang uangnya — laci kurang seratus ribu tinggal dicatat sebagai "rusak"
             * dan tidak ada yang bisa membedakannya dari barang yang benar-benar rusak.
             */
            Route::get('/stok', StokOwner::class)->name('stok');
            Route::get('/stok/opname', OpnameOwner::class)->name('stok.opname');

            /*
             * Pembelian ikut grup yang sama, dan alasannya sama dengan stok: mencatat
             * barang masuk berarti mengubah angka stok tanpa ada barang yang dihitung.
             * Kasir yang bisa mencatat pembelian bisa menutup selisih laci dengan "barang
             * datang" yang tidak pernah datang.
             *
             * Dua nama rute (`pembelian` dan `pembelian.baru`) supaya penanda menu samping
             * — `routeIs($rute, $rute.'.*')` — tetap menyala saat notanya sedang diketik.
             */
            Route::get('/pembelian', PembelianOwner::class)->name('pembelian');
            Route::get('/pembelian/baru', PembelianBaruOwner::class)->name('pembelian.baru');

            Route::get('/laporan', LaporanOwner::class)->name('laporan');
        });
    });

/*
|--------------------------------------------------------------------------
| Area kasir
|--------------------------------------------------------------------------
*/
Route::middleware(['auth', 'peran:kasir,dapur', 'tenant-aktif'])
    ->prefix('kasir')
    ->name('kasir.')
    ->group(function () {
        Route::get('/', BerandaKasir::class)->name('beranda');
        Route::get('/transaksi', TransaksiKasir::class)->name('transaksi');

        // Katalog dilayani sebagai JSON supaya klien kasir bisa menyimpannya di
        // perangkat dan menyegarkannya tanpa memuat ulang halaman.
        Route::get('/katalog', KatalogController::class)->name('katalog');

        /*
         * Sisa stok TERPISAH dari katalog, dan bukan karena kerapian.
         *
         * Katalog dimuat sekali lalu dipakai berjam-jam; penyegarannya sengaja manual
         * supaya harga tidak berganti diam-diam di tengah transaksi. Sisa stok berubah
         * tiap satu transaksi — termasuk transaksi kasir lain — jadi ia perlu jalur
         * yang boleh sering ditarik tanpa ikut menyeret harga, gambar, dan barcode.
         *
         * Kegagalan endpoint ini TIDAK berakibat apa pun di layar kasir: tanpa jawaban,
         * petak produk tampil apa adanya dan penjualan berjalan seperti biasa.
         */
        Route::get('/sisa-stok', SisaStokController::class)->name('sisa-stok');
    });

/*
|--------------------------------------------------------------------------
| Sinkronisasi antrean transaksi offline
|--------------------------------------------------------------------------
| Ditempatkan di grup web (session + CSRF), bukan API bertoken, karena klien
| kasir berjalan di origin yang sama dan sudah punya sesi login. Kalau nanti
| kasir dipisah jadi aplikasi native, endpoint ini perlu dipindah ke guard token.
*/
Route::middleware(['auth', 'tenant-aktif'])
    ->post('/sinkronisasi/transaksi', [OfflineSyncController::class, 'store'])
    ->name('sinkronisasi.transaksi');

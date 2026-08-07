<?php

use App\Http\Controllers\Auth\KeluarController;
use App\Http\Controllers\Kasir\KatalogController;
use App\Http\Controllers\Kasir\SisaStokController;
use App\Http\Controllers\Owner\LampiranController;
use App\Http\Controllers\Sinkronisasi\OfflineSyncController;
use App\Livewire\Pages\Admin\Dasbor\Dasbor as DasborAdmin;
use App\Livewire\Pages\Auth\Masuk\Masuk;
use App\Livewire\Pages\Auth\Masuk\MasukKasir;
use App\Livewire\Pages\Kasir\Beranda\Beranda as BerandaKasir;
use App\Livewire\Pages\Kasir\Transaksi\Transaksi as TransaksiKasir;
use App\Livewire\Pages\Owner\Bahan\Bahan as BahanOwner;
use App\Livewire\Pages\Owner\Dasbor\Dasbor as DasborOwner;
use App\Livewire\Pages\Owner\Langganan\Langganan;
use App\Livewire\Pages\Owner\Laporan\Laporan as LaporanOwner;
use App\Livewire\Pages\Owner\Pembelian\Pembelian as PembelianOwner;
use App\Livewire\Pages\Owner\Pembelian\PembelianBaru as PembelianBaruOwner;
use App\Livewire\Pages\Owner\Produk\Produk as ProdukOwner;
use App\Livewire\Pages\Owner\Stok\Opname as OpnameOwner;
use App\Livewire\Pages\Owner\Stok\Stok as StokOwner;
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
             * Bahan baku sejajar produk, dan SENGAJA tidak diberi middleware jenis usaha.
             *
             * Menunya memang hanya muncul untuk usaha yang memasak
             * (BusinessType::supportsRecipe()), karena kelontong tidak punya resep dan
             * butir menu yang tidak pernah dipakai hanya memanjangkan daftar. Tapi
             * MEMBLOKIR rutenya untuk jenis usaha lain berarti satu middleware baru plus
             * satu uji 403 demi nol manfaat bagi pemilik: yang bisa terjadi paling buruk
             * adalah pemilik kelontong mengetik URL-nya sendiri lalu melihat layar yang
             * tidak berguna baginya — bukan data orang lain, bukan uang yang salah.
             *
             * Nama rutenya BERSARANG-siap: penanda menu memakai
             * `routeIs('owner.bahan', 'owner.bahan.*')`, jadi layar Resep yang menyusul
             * (gelombang 2, `owner.bahan.resep`) akan tetap menyalakan menu induknya tanpa
             * menyentuh sidebar lagi.
             */
            Route::get('/bahan', BahanOwner::class)->name('bahan');

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

            /*
             * Lampiran nota (foto kwitansi/struk) — SATU-SATUNYA jalan membukanya.
             *
             * Sebelum ini berkasnya disajikan statis oleh web server dari
             * `storage/app/public/bukti-belanja/` lewat symlink `public/storage`: tanpa
             * login, tanpa tenant, tanpa outlet. Yang menahannya cuma nama berkas UUID yang
             * tidak bisa ditebak — dan satu tautan yang tersalin ke WhatsApp membocorkan
             * harga beli beserta nama pemasok SELAMANYA, tanpa cara mencabutnya.
             *
             * Sengaja di dalam grup yang sama dengan layar Pembelian, jadi ia mewarisi
             * seluruh gerbangnya: auth, peran back office (kasir TIDAK boleh — harga beli
             * bukan wewenang orang yang memegang uang laci), dan tenant-aktif. Sisa
             * penjagaannya — tenant lewat route-model binding, outlet lewat
             * canAccessOutlet() — ada di LampiranController, dan semuanya menjawab 404.
             *
             * BENTUK RUTENYA: /kelola/lampiran/pembelian/{nota}/{penanda}
             *  - `pembelian` menamai jenis dokumen induknya, jadi lampiran dokumen lain
             *    (mis. pengeluaran kas) bisa menyusul tanpa mengganti bentuk yang ini;
             *  - `{nota}` di-bind ke PurchaseOrder supaya TenantScope ikut menahan;
             *  - `{penanda}` masih bernilai tetap `bukti` di gelombang ini, dan justru itu
             *    gunanya: gelombang lampiran berikutnya (banyak lembar, PDF) mengisinya
             *    dengan id lampiran tanpa mengubah bentuk URL-nya lagi, sehingga tautan yang
             *    sudah tersimpan di riwayat peramban pemilik tetap berlaku.
             */
            Route::get('/lampiran/pembelian/{nota}/{penanda}', LampiranController::class)
                ->whereUuid('nota')
                ->where('penanda', '[A-Za-z0-9-]+')
                ->name('lampiran.lihat');

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

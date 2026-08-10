<?php

namespace Tests\Feature;

use App\Actions\Kas\BukaSesiKasAction;
use App\Actions\Kas\KoreksiModalAwalAction;
use App\Actions\Kasbon\CatatPelunasanAction;
use App\Actions\Kasir\SusunSisaStokAction;
use App\Actions\Pembelian\BatalkanPembelianAction;
use App\Actions\Pembelian\CatatPembelianAction;
use App\Actions\Stok\AdjustStockAction;
use App\Enums\AlasanOpname;
use App\Enums\CreditStatus;
use App\Enums\PeriodeBiaya;
use App\Enums\Satuan;
use App\Enums\StockMovementType;
use App\Livewire\Pages\Owner\Bahan\Bahan as BahanOwner;
use App\Livewire\Pages\Owner\Bahan\Resep as ResepOwner;
use App\Livewire\Pages\Owner\Biaya\Biaya as BiayaOwner;
use App\Livewire\Pages\Owner\Karyawan\Karyawan as KaryawanOwner;
use App\Livewire\Pages\Owner\Kasbon\Kasbon as KasbonOwner;
use App\Livewire\Pages\Owner\Pelanggan\Pelanggan as PelangganOwner;
use App\Livewire\Pages\Owner\Pembelian\Pembelian;
use App\Livewire\Pages\Owner\Pembelian\PembelianBaru;
use App\Livewire\Pages\Owner\Produk\ImporProduk as ImporProdukOwner;
use App\Livewire\Pages\Owner\Produk\Produk;
use App\Livewire\Pages\Owner\Stok\Opname;
use App\Livewire\Pages\Owner\Stok\Stok;
use App\Models\Bahan\RawMaterial;
use App\Models\Biaya\BiayaOperasional;
use App\Models\Kas\CashSession;
use App\Models\Kasir\Transaction;
use App\Models\Lampiran\Lampiran;
use App\Models\Pelanggan\CreditLedger;
use App\Models\Pelanggan\Customer;
use App\Models\Produk\Product;
use App\Models\Stok\Stock;
use App\Models\Tenant\Outlet;
use App\Models\Tenant\User;
use App\Support\TenantContext;
use Database\Seeders\DepotLaundrySeeder;
use Database\Seeders\KelontongSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\SuperAdminSeeder;
use Database\Seeders\WartegSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Bukan pengujian perilaku — ini alat bantu untuk menangkap HTML halaman agar
 * bisa dipotret dan diperiksa mata. Dilewati kecuali dijalankan sengaja dengan
 * PRATINJAU=1, supaya tidak menambah beban pada suite biasa.
 */
class PratinjauTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Vite dinyalakan kembali untuk kelas ini.
     *
     * TestCase mematikannya supaya uji tidak bergantung pada hasil build. Tapi harness ini
     * justru menulis HTML yang akan DIBUKA DI PERAMBAN untuk diukur — tanpa tag aset yang
     * nyata, halamannya tanpa gaya dan tanpa Alpine, dan seluruh pengukurannya menipu.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withVite();
    }

    public function test_menangkap_layar_kasir(): void
    {
        if (env('PRATINJAU') !== '1') {
            $this->markTestSkipped('Jalankan dengan PRATINJAU=1 untuk menangkap HTML.');
        }

        $this->seed(PlanSeeder::class);
        $this->seed(WartegSeeder::class);
        $this->seed(DepotLaundrySeeder::class);

        $tujuan = base_path('storage/pratinjau');

        if (! is_dir($tujuan)) {
            mkdir($tujuan, 0755, true);
        }

        $kasirWarteg = null;

        foreach (['warteg' => 'open_bill', 'depot' => 'pesan_antar'] as $nama => $mode) {
            // Outlet dibaca tanpa global scope: permintaan sebelumnya sudah menetapkan
            // TenantContext ke tenant lain, sehingga outlet tenant berikutnya
            // tidak terlihat kalau scope dibiarkan aktif.
            $outletMode = Outlet::withoutGlobalScopes()
                ->get()
                ->filter(fn (Outlet $o) => in_array($mode, $o->active_modes ?? [], true))
                ->pluck('id');

            $kasir = User::withoutGlobalScopes()
                ->where('role', 'kasir')
                ->whereIn('outlet_id', $outletMode)
                ->first();

            $this->assertNotNull($kasir, "Tidak ada kasir dengan mode {$mode}.");

            // Layar kasir tertutup tanpa sesi kas; pastikan ada satu supaya yang
            // terpotret adalah layar transaksinya, bukan gerbangnya. Sebagian kasir
            // demo sudah dibukakan sesi oleh seeder.
            $buka = app(BukaSesiKasAction::class);

            if ($buka->sesiBerjalan($kasir) === null) {
                $buka->execute($kasir, 200000);
            }

            $html = $this->ambil('kasir.transaksi', $kasir);

            file_put_contents("{$tujuan}/kasir-{$nama}.html", $html);

            if ($nama === 'warteg') {
                $kasirWarteg = $kasir;
            }
        }

        $this->tangkapSisaStok($kasirWarteg, $tujuan);

        /*
         * Beranda kasir (riwayat + ringkasan), dua rentang.
         *
         * Kasirnya dipilih dari yang benar-benar punya transaksi NON-draft hari ini.
         * Kalau diambil kasir pertama begitu saja, riwayatnya bisa kosong hanya karena
         * seluruh transaksinya berstatus draft — dan pratinjaunya tidak membuktikan
         * apa pun tentang tampilan daftar.
         */
        $kasirBeranda = User::withoutGlobalScopes()
            ->where('role', 'kasir')
            ->whereNotNull('outlet_id')
            ->get()
            ->first(fn (User $u) => Transaction::withoutGlobalScopes()
                ->where('staff_id', $u->getKey())
                ->whereNot('status', 'draft')
                ->whereDate('waktu_transaksi', today())
                ->exists())
            ?? User::withoutGlobalScopes()->where('role', 'kasir')->whereNotNull('outlet_id')->firstOrFail();

        $this->transaksiContohHariIni($kasirBeranda);

        // Satu koreksi modal awal supaya spanduk riwayatnya ikut terpotret.
        $sesiKasir = CashSession::withoutGlobalScopes()
            ->where('staff_id', $kasirBeranda->getKey())
            ->where('status', 'terbuka')
            ->latest('dibuka_pada')
            ->first();

        if ($sesiKasir !== null) {
            app(KoreksiModalAwalAction::class)->execute(
                $sesiKasir,
                (float) $sesiKasir->modal_awal + 73000,
                'salah hitung, ada uang di amplop terpisah',
                $kasirBeranda,
            );
        }

        foreach (['shift', 'hari'] as $rentang) {
            file_put_contents(
                "{$tujuan}/kasir-beranda-{$rentang}.html",
                $this->ambil('kasir.beranda', $kasirBeranda, ['rentang' => $rentang]),
            );
        }

        // Gerbang buka kasir: kasir yang belum punya sesi.
        $tanpaSesi = User::withoutGlobalScopes()
            ->where('role', 'dapur')
            ->whereNotNull('outlet_id')
            ->first();

        if ($tanpaSesi !== null) {
            $tanpaSesi->update(['role' => 'kasir']);
            $tanpaSesi = $tanpaSesi->fresh();

            file_put_contents(
                "{$tujuan}/kasir-gerbang.html",
                $this->ambil('kasir.transaksi', $tanpaSesi),
            );

            // Beranda tanpa sesi: keadaan yang memunculkan formulir modal awal.
            file_put_contents(
                "{$tujuan}/kasir-beranda-tutup.html",
                $this->ambil('kasir.beranda', $tanpaSesi),
            );
        }

        $this->assertFileExists("{$tujuan}/kasir-warteg.html");
    }

    /**
     * Layar kasir DENGAN lencana sisa stok — ketiga keadaannya sekaligus.
     *
     * KENAPA butuh perlakuan khusus: lencananya digambar Alpine dari kabar yang ditarik
     * lewat fetch ke /kasir/sisa-stok, sedangkan tangkapan ini HTML statis yang dibuka
     * peramban tanpa sesi. Fetch-nya karena itu selalu gagal (dialihkan ke halaman masuk),
     * dan tangkapan apa adanya cuma membuktikan satu hal: petak tanpa lencana tetap rapi.
     * Itu keadaan yang sudah terpotret di kasir-warteg.html.
     *
     * Jalan yang dipakai: kabarnya DITANAM di localStorage lewat satu skrip kecil di
     * kepala halaman, lalu jalur pemulihan yang sungguhan (pulihkanSisaStok) yang
     * mengangkatnya saat Alpine init. Dipilih daripada menambah 'bekalAwal' berisi kabar
     * awal karena:
     *   - tidak satu baris pun kode produksi berubah demi sebuah tangkapan layar, dan
     *   - jalur inilah yang benar-benar dipakai puluhan kali sehari di warung (tiap muat
     *     ulang halaman), jadi yang terpotret adalah kode yang memang berjalan.
     * Menaruhnya di bekalAwal justru akan melawan keputusan arsitekturnya: sisa stok
     * SENGAJA lepas dari katalog karena umurnya beda (lihat SisaStokController).
     *
     * Isi kabarnya bukan karangan: peta yang ditanam adalah keluaran SusunSisaStokAction
     * yang sebenarnya atas data yang dibengkokkan di bawah — kalau bentuk muatannya
     * berubah, tangkapan ini ikut berubah alih-alih diam-diam ketinggalan.
     */
    private function tangkapSisaStok(?User $kasir, string $tujuan): void
    {
        $this->assertNotNull($kasir, 'Kasir warteg tidak ditemukan untuk tangkapan sisa stok.');

        $outletId = $kasir->outlet_id;

        // Konteks diarahkan dulu: produk contoh di bawah dibuat lewat model biasa, dan
        // BelongsToTenant mengambil tenant_id dari konteks. Tanpa ini ia lahir di tenant
        // permintaan sebelumnya (depot) dan tidak akan pernah muncul di katalog warteg.
        app(TenantContext::class)->setTenant($kasir->tenant_id)->setOutlet($outletId);

        $bahan = RawMaterial::withoutGlobalScopes()
            ->where('tenant_id', $kasir->tenant_id)
            ->get()
            ->keyBy('nama');

        $produk = Product::withoutGlobalScopes()
            ->where('tenant_id', $kasir->tenant_id)
            ->get()
            ->keyBy('nama_produk');

        $stok = fn (string $kolom, string $id): ?Stock => Stock::withoutGlobalScopes()
            ->where('outlet_id', $outletId)
            ->where($kolom, $id)
            ->first();

        // 1. HABIS lewat bahan baku: ayam habis ⇒ menu "Ayam Goreng" berlencana Habis.
        //    Justru jalur ini yang paling mudah salah — stok produk jadinya tidak pernah
        //    bergerak, jadi kalau yang dibaca produk, menunya tidak akan pernah berlencana.
        $stok('raw_material_id', $bahan['Ayam Potong']->getKey())
            ?->forceFill(['jumlah_saat_ini' => 0, 'stok_minimum' => 2])->save();

        // 2. MENIPIS lewat bahan: gula tinggal sedikit ⇒ "Es Teh Manis" berlencana Menipis.
        $stok('raw_material_id', $bahan['Gula Pasir']->getKey())
            ?->forceFill(['jumlah_saat_ini' => 3, 'stok_minimum' => 8])->save();

        // 3. HABIS pada barang dagang biasa (bukan resep): Kerupuk. Dua jalur berbeda
        //    menuju lencana yang sama harus terlihat sama di layar.
        $stok('product_id', $produk['Kerupuk']->getKey())
            ?->forceFill(['jumlah_saat_ini' => 0, 'stok_minimum' => 5])->save();

        // 4. BELUM PERNAH DIHITUNG: baris telur dihapus ⇒ "Telur Dadar" TANPA lencana.
        //    Ini keadaan ketiga yang wajib hadir dalam satu bidikan: kalau suatu saat
        //    belum-dihitung ikut dikabarkan habis, tangkapan ini yang memperlihatkannya.
        $stok('raw_material_id', $bahan['Telur Ayam']->getKey())?->delete();

        /*
         * 5. Kasus tersempit yang bisa terjadi: harga TUJUH DIGIT bersama lencana
         *    "Menipis" di petak dua kolom pada layar 390px. Warteg memang menjual paket
         *    katering seharga jutaan, dan justru petak inilah yang menentukan apakah
         *    harganya masih terbaca utuh — angka uang tidak boleh terpotong atau pecah
         *    dua baris (PATOKAN RESPONSIF, kartu ringkasan).
         */
        $katering = Product::create([
            'kategori_id' => $produk['Kerupuk']->kategori_id,
            'nama_produk' => 'Paket Katering 50 Kotak',
            'sku' => 'BJM-KTR',
            'harga_default' => 1250000,
            'harga_beli' => 900000,
            'satuan' => Satuan::Dus,
        ]);

        Stock::create([
            'outlet_id' => $outletId,
            'product_id' => $katering->getKey(),
            'jumlah_saat_ini' => 2,
            'stok_minimum' => 5,
        ]);

        $sisa = app(SusunSisaStokAction::class)->execute($outletId);

        // Tangkapan yang cuma memuat satu keadaan tidak membuktikan kerapian keadaan
        // lainnya, dan itu tidak terlihat dari PNG-nya kalau tidak diperiksa di sini.
        $this->assertContains('habis', $sisa, 'Tidak ada barang berlencana Habis di tangkapan.');
        $this->assertContains('menipis', $sisa, 'Tidak ada barang berlencana Menipis di tangkapan.');
        $this->assertArrayNotHasKey(
            $produk['Nasi Putih']->getKey(),
            $sisa,
            'Tidak ada barang TANPA lencana di tangkapan.',
        );

        file_put_contents(
            "{$tujuan}/kasir-sisa-stok.html",
            $this->suntikKabarStok($this->ambil('kasir.transaksi', $kasir), $sisa),
        );
    }

    /**
     * Menanam kabar sisa stok ke localStorage halaman tangkapan.
     *
     * Skrip biasa (bukan module) di dalam <head> berjalan saat halaman diurai, yaitu
     * sebelum bundel Alpine/Livewire yang ditangguhkan — jadi kabarnya sudah ada ketika
     * init memanggil pulihkanSisaStok().
     *
     * 'sampai' dihitung di peramban, bukan di PHP: tangkapan yang dibuat kemarin lalu
     * diukur hari ini akan berisi batas umur yang sudah lewat, dan lencananya hilang
     * lagi — kegagalan yang menyamar sebagai "sudah rapi".
     *
     * Kuncinya DIHAPUS lagi begitu Alpine selesai init. localStorage dibagi seluruh
     * tangkapan (semuanya disajikan dari origin yang sama), jadi tanpa ini kasir-warteg
     * yang diukur sesudahnya ikut berlencana — tangkapan yang seharusnya membuktikan
     * keadaan TANPA lencana berubah diam-diam.
     */
    private function suntikKabarStok(string $halaman, array $sisa): string
    {
        $skrip = '<script>(function(){'
            .'localStorage.setItem("nampan.sisa", JSON.stringify({'
            .'sisa:'.json_encode($sisa).','
            .'sampai:Date.now()+30*60*1000,'
            .'jam:'.json_encode(now()->format('H.i'))
            .'}));'
            .'document.addEventListener("alpine:initialized",function(){'
            .'localStorage.removeItem("nampan.sisa");},{once:true});'
            .'})();</script>';

        return str_replace('</head>', $skrip.'</head>', $halaman);
    }

    public function test_menangkap_dasbor_dan_halaman_masuk(): void
    {
        if (env('PRATINJAU') !== '1') {
            $this->markTestSkipped('Jalankan dengan PRATINJAU=1 untuk menangkap HTML.');
        }

        $this->seed(PlanSeeder::class);
        $this->seed(WartegSeeder::class);
        // Kelontong disemai juga: kolom barcode & tombol pindai hanya muncul untuk
        // jenis usaha itu, dan pratinjau FnB saja tidak akan pernah menampilkannya.
        $this->seed(KelontongSeeder::class);
        $this->seed(SuperAdminSeeder::class);

        $tujuan = base_path('storage/pratinjau');

        if (! is_dir($tujuan)) {
            mkdir($tujuan, 0755, true);
        }

        $owner = User::withoutGlobalScopes()->where('role', 'owner')->firstOrFail();
        file_put_contents(
            "{$tujuan}/dasbor-owner.html",
            $this->ambil('owner.dasbor', $owner),
        );

        /*
         * Dua produk diberi gambar supaya pratinjau membuktikan jalur gambarnya, bukan
         * hanya tile inisial. Berkasnya dibuat di sini juga supaya harness ini berjalan
         * di mesin mana pun tanpa langkah persiapan manual.
         */
        /*
         * Berkas contoh ditaruh di direktori TERSENDIRI, bukan di 'produk'.
         *
         * 'produk' adalah tempat gambar unggahan sungguhan disimpan. Membersihkan
         * berkas pratinjau dari sana pernah menghapus gambar yang diunggah pengguna —
         * kerusakan yang tidak bisa dipulihkan. Direktori pratinjau boleh dihapus
         * kapan saja tanpa menyentuh data siapa pun.
         */
        $dirGambar = storage_path('app/public/pratinjau');

        if (! is_dir($dirGambar)) {
            mkdir($dirGambar, 0755, true);
        }

        file_put_contents("{$dirGambar}/contoh.svg",
            '<svg xmlns="http://www.w3.org/2000/svg" width="240" height="180">'
            .'<rect width="240" height="180" fill="#C0B8FE"/>'
            .'<circle cx="120" cy="100" r="52" fill="#422AFB"/>'
            .'<rect width="240" height="34" fill="#190793"/></svg>');

        Product::withoutGlobalScopes()
            ->whereNull('gambar_path')
            ->limit(2)
            ->get()
            ->each(fn (Product $p) => $p->forceFill(['gambar_path' => 'pratinjau/contoh.svg'])->save());

        /*
         * Owner kelontong dipotret terpisah: kolom barcode & tombol pindai hanya
         * muncul untuk jenis usaha itu, jadi pratinjau FnB tidak membuktikannya.
         */
        $ownerKelontong = User::withoutGlobalScopes()
            ->where('role', 'owner')
            ->get()
            ->first(fn (User $u) => $u->tenant?->business_type?->pakaiBarcode() === true);

        if ($ownerKelontong !== null) {
            $halamanKelontong = $this->ambil('owner.produk', $ownerKelontong);

            file_put_contents("{$tujuan}/owner-produk-kelontong.html", $halamanKelontong);

            /*
             * Panel formulirnya dipotret terpisah karena ia hanya ada di DOM saat dibuka,
             * dan justru di situlah seluruh jalur pemindaian berada. Fragmen komponennya
             * disuntikkan ke halaman yang sama supaya CSS dan fontnya ikut — fragmen
             * telanjang akan terpotret tanpa gaya apa pun dan tidak membuktikan apa-apa.
             */
            $panel = Livewire::actingAs($ownerKelontong)->test(Produk::class)->call('tambah')->html();

            /*
             * Penanda Livewire dilepas dari fragmennya sebelum disuntikkan.
             *
             * Dibiarkan utuh, halaman berisi DUA komponen dengan wire:id yang sama dan
             * Livewire gagal saat boot. Karena Alpine dimulai oleh Livewire, kegagalan itu
             * membuat seluruh x-show dan x-cloak tidak pernah dijalankan — pratinjaunya
             * memperlihatkan halaman yang mati, bukan halaman yang sedang dipotret.
             * (Mengganti wire:id saja tidak cukup: snapshotnya bersegel checksum.)
             *
             * Tanpa penanda itu, Livewire mengabaikan fragmennya dan Alpine tetap
             * menghidupkannya — dan Alpine-lah yang menjalankan seluruh panel ini.
             */
            $panel = preg_replace('/\s(wire:id|wire:snapshot|wire:effects)="[^"]*"/', '', $panel);

            file_put_contents(
                "{$tujuan}/owner-produk-form-kelontong.html",
                str_replace('</body>', $panel.'</body>', $halamanKelontong),
            );
        }

        // Satu produk dinonaktifkan supaya lencana status "Nonaktif" ikut terpotret;
        // tanpa ini pratinjau hanya membuktikan satu dari dua keadaan.
        Product::withoutGlobalScopes()->where('is_active', true)->first()
            ?->forceFill(['is_active' => false])->save();

        // Halaman produk owner, beserta panel formulirnya.
        /*
         * Satu produk sengaja dibuat MERUGI, supaya keadaan merah di kolom "Modal & margin"
         * ikut terpotret.
         *
         * Bukan kasus tepi: harga bahan naik dan harga menu tidak ikut disesuaikan
         * berbulan-bulan adalah cara paling biasa sebuah warung kehilangan uang tanpa
         * sadar. Justru keadaan itulah yang paling perlu dilihat mata sebelum dipercaya —
         * dan data demo tidak punya satu pun produk merugi, jadi tanpa pembengkokan ini
         * warna merahnya tidak pernah terukur sama sekali.
         */
        $produkRugi = Product::withoutGlobalScopes()
            ->where('tenant_id', $owner->tenant_id)
            ->whereNotNull('harga_beli')
            ->orderByDesc('harga_beli')
            ->first();

        $produkRugi?->forceFill([
            'harga_default' => round((float) $produkRugi->harga_beli * 0.85),
        ])->save();

        /*
         * Panel produk dengan SARAN HARGA terisi.
         *
         * Sarannya cuma muncul kalau modalnya diketahui, jadi yang dibuka produk yang punya
         * harga beli — panel kosong tidak membuktikan blok sarannya terender sama sekali.
         */
        $produkBermodal = Product::withoutGlobalScopes()
            ->where('tenant_id', $owner->tenant_id)
            ->whereNotNull('harga_beli')
            ->orderBy('nama_produk')
            ->first();

        if ($produkBermodal !== null) {
            file_put_contents(
                "{$tujuan}/owner-produk-saran.html",
                $this->suntik(
                    $this->ambil('owner.produk', $owner),
                    Livewire::actingAs($owner)->test(Produk::class)
                        ->call('ubah', $produkBermodal->getKey())
                        ->html(),
                ),
            );
        }

        file_put_contents(
            "{$tujuan}/owner-produk.html",
            $this->ambil('owner.produk', $owner),
        );

        file_put_contents(
            "{$tujuan}/owner-laporan.html",
            $this->ambil('owner.laporan', $owner),
        );

        /*
         * Layar Bahan baku, dua keadaan.
         *
         * Owner-nya WartegSeeder (fnb), karena hanya usaha yang memasak melihat menu ini —
         * dan seedernya sudah punya enam bahan beserta resepnya, jadi kolom "Dipakai di"
         * terpotret berisi nama menu, bukan hanya tanda "—".
         *
         * Satu bahan sengaja dikosongkan harga belinya supaya keadaan "belum diisi" ikut
         * terpotret. Tanpa itu pratinjau hanya membuktikan satu dari dua keadaan, dan justru
         * keadaan kosong itulah yang paling mudah tampil sebagai sel kosong tanpa penanda.
         */
        RawMaterial::withoutGlobalScopes()
            ->orderBy('nama')
            ->first()
            ?->forceFill(['harga_beli_terakhir' => null])->save();

        $halamanBahan = $this->ambil('owner.bahan', $owner);

        file_put_contents("{$tujuan}/owner-bahan.html", $halamanBahan);

        // Panel formulirnya hanya ada di DOM saat dibuka, jadi fragmennya disuntikkan ke
        // halaman yang sama supaya CSS dan fontnya ikut — lihat suntik() untuk alasan
        // penanda Livewire dilepas.
        file_put_contents(
            "{$tujuan}/owner-bahan-form.html",
            $this->suntik(
                $halamanBahan,
                Livewire::actingAs($owner)->test(BahanOwner::class)->call('tambah')->html(),
            ),
        );

        /*
         * Layar Resep, dua keadaan.
         *
         * Panelnya dipotret TERBUKA lewat menu yang BELUM punya resep — dua alasan: keadaan
         * bawaan panelnya tertutup sehingga tidak akan pernah terukur sendiri, dan menu
         * tanpa resep itulah yang memunculkan peringatan "masih punya sisa tercatat", yaitu
         * blok terpanjang di panel dan satu-satunya yang bisa membuatnya menggulir.
         */
        $halamanResep = $this->ambil('owner.bahan.resep', $owner);

        file_put_contents("{$tujuan}/owner-resep.html", $halamanResep);

        $menuTanpaResep = Product::withoutGlobalScopes()
            ->whereDoesntHave('recipeItems')
            ->orderBy('nama_produk')
            ->first();

        if ($menuTanpaResep !== null) {
            file_put_contents(
                "{$tujuan}/owner-resep-panel.html",
                $this->suntik(
                    $halamanResep,
                    Livewire::actingAs($owner)->test(ResepOwner::class)
                        ->call('atur', $menuTanpaResep->getKey())
                        ->html(),
                ),
            );
        }

        /*
         * Layar Pelanggan, dua keadaan.
         *
         * Datanya SENGAJA dibengkokkan lebih dulu. Seeder demo meninggalkan tiap kasbon
         * berstatus belum lunas dengan `jumlah_dibayar` nol, dan layar dalam keadaan itu
         * tidak membuktikan apa pun tentang kolom yang paling mudah salah: kolom kasbon
         * menampilkan SISA utang, bukan utang awal, dan selama belum ada satu pun yang
         * dicicil, kedua rumus itu menghasilkan angka yang sama persis.
         *
         * Satu pelanggan juga dikosongkan nomornya supaya keadaan "—" ikut terpotret. Sel
         * kosong tanpa penanda adalah cacat yang paling sering lolos dari mata, dan justru
         * itu yang dijaga angka `selKosong` di alat ukur.
         */
        $kasbonDicicil = CreditLedger::withoutGlobalScopes()
            ->where('status', CreditStatus::BelumLunas->value)
            ->orderBy('created_at')
            ->first();

        $kasbonDicicil?->forceFill([
            'jumlah_dibayar' => round((float) $kasbonDicicil->jumlah_utang * 0.4, 2),
        ])->save();

        Customer::withoutGlobalScopes()
            ->where('tenant_id', $owner->tenant_id)
            ->whereNotNull('no_hp')
            ->orderByDesc('nama')
            ->first()
            ?->forceFill(['no_hp' => null])->save();

        $halamanPelanggan = $this->ambil('owner.pelanggan', $owner);

        file_put_contents("{$tujuan}/owner-pelanggan.html", $halamanPelanggan);

        // Panel formulirnya hanya ada di DOM saat dibuka — sama seperti layar Bahan.
        file_put_contents(
            "{$tujuan}/owner-pelanggan-form.html",
            $this->suntik(
                $halamanPelanggan,
                Livewire::actingAs($owner)->test(PelangganOwner::class)->call('tambah')->html(),
            ),
        );

        /*
         * Layar Impor produk, dua keadaan.
         *
         * Keadaan AWAL (belum ada berkas) dan keadaan PRATINJAU. Yang kedua dibuat dengan
         * berkas yang sengaja berisi campuran: baris yang benar, baris berharga tak terbaca,
         * baris ber-SKU yang sudah ada, dan satu baris ber-SKU yang sudah ada. Berkas yang
         * seluruhnya benar cuma memotret satu dari empat blok di layar itu — dan tiga blok
         * yang tidak terpotret justru yang paling panjang dan paling mudah berantakan.
         */
        file_put_contents("{$tujuan}/owner-impor-produk.html", $this->ambil('owner.produk.impor', $owner));

        /*
         * Satu baris memakai SKU produk yang SUDAH ADA, supaya lencana "Diperbarui" ikut
         * terpotret di samping "Baru". Kedua keadaan itu berbeda artinya bagi pemilik —
         * yang satu menambah barang, yang lain MENGUBAH HARGA barang yang sudah dijual —
         * jadi keduanya harus terbukti bisa dibedakan mata, bukan cuma oleh uji.
         */
        $produkAda = Product::withoutGlobalScopes()
            ->where('tenant_id', $owner->tenant_id)
            ->whereNotNull('sku')
            ->orderBy('nama_produk')
            ->firstOrFail();

        /*
         * Berkas contohnya sengaja BERCAMPUR, karena berkas yang seluruhnya benar cuma
         * memotret satu dari empat blok di layar itu:
         *  - baris yang benar          → tabel "yang akan masuk"
         *  - harga "3.000"             → membuktikan titik ribuan memang terbaca
         *  - baris tanpa nama          → blok "baris yang dilewati"
         *  - satuan "meter"            → penolakan yang menyebut daftar satuan
         *  - harga "tidak tahu"        → penolakan harga tak terbaca
         *  - SKU yang sudah ada        → lencana "Diperbarui"
         *  - kolom "stok"              → kotak "kolom yang tidak dibaca"
         */
        $csvContoh = "nama,harga,satuan,kategori,sku,stok\n"
            ."Kerupuk Udang,3.000,pcs,Camilan,,20\n"
            ."Teh Kotak,5000,pcs,Minuman,,15\n"
            ."Kopi Sachet,2500,bungkus,Minuman,,40\n"
            .",9000,pcs,Minuman,,3\n"
            ."Kain Lap,25000,meter,Rumah Tangga,,5\n"
            ."Sirup Marjan,tidak tahu,pcs,Minuman,,8\n"
            .$produkAda->nama_produk.' (harga baru),19500,porsi,Makanan,'.$produkAda->sku.",\n";

        file_put_contents(
            "{$tujuan}/owner-impor-produk-pratinjau.html",
            $this->suntik(
                $this->ambil('owner.produk.impor', $owner),
                Livewire::actingAs($owner)->test(ImporProdukOwner::class)
                    ->set('berkas', UploadedFile::fake()->createWithContent('daftar.csv', $csvContoh))
                    ->html(),
            ),
        );

        /*
         * Layar Kasbon, tiga keadaan sekaligus di satu tangkapan.
         *
         * Datanya dibengkokkan supaya KETIGA lencana terpotret: lunas, belum lunas, dan lewat
         * jatuh tempo. Seeder demo meninggalkan semuanya belum lunas tanpa jatuh tempo, jadi
         * tangkapan apa adanya cuma membuktikan satu dari tiga — dan justru lencana merah
         * yang paling perlu dilihat mata, karena kontrasnya pernah jadi temuan tersendiri.
         *
         * Satu kasbon juga diberi riwayat setoran, karena riwayat itulah alasan barisnya
         * berbentuk kartu dan bukan baris tabel. Tanpa satu pun setoran, bentuk kartunya
         * terlihat boros ruang tanpa alasan yang kelihatan.
         */
        $kasbonWarteg = CreditLedger::withoutGlobalScopes()
            ->where('tenant_id', $owner->tenant_id)
            ->orderBy('created_at')
            ->get();

        if ($kasbonWarteg->count() >= 2) {
            $kasbonWarteg[0]->forceFill([
                'tanggal_jatuh_tempo' => now()->subWeeks(2)->toDateString(),
                'catatan' => 'Belanja lauk 3 hari, minta ditagih akhir bulan.',
            ])->save();

            app(CatatPelunasanAction::class)->execute(
                $kasbonWarteg[0],
                round((float) $kasbonWarteg[0]->jumlah_utang * 0.3),
                $owner,
                now()->subDays(4),
                catatan: 'dititip anaknya',
            );

            // Satu dibuat lunas penuh, supaya lencana hijau ikut terpotret.
            app(CatatPelunasanAction::class)->execute(
                $kasbonWarteg[1],
                (float) $kasbonWarteg[1]->jumlah_utang,
                $owner,
            );
        }

        // Saringan bawaannya "belum lunas"; potret memakai "semua" supaya ketiga lencana
        // benar-benar ada di halaman yang sama.
        $halamanKasbon = $this->ambil('owner.kasbon', $owner, ['status' => 'semua']);

        file_put_contents("{$tujuan}/owner-kasbon.html", $halamanKasbon);

        /*
         * Tangkapan KETIGA khusus yang sudah lunas.
         *
         * Bukan kelebihan: potret "semua" TIDAK membuktikan lencana hijau ada. Daftarnya
         * berisi 11 kasbon dengan `created_at` yang sama detik, jadi urutan di antara yang
         * seri tidak ditentukan apa pun — kasbon yang dilunasi bisa jatuh di halaman 2 dan
         * lencana hijaunya tidak pernah ikut terukur. Diperiksa pertama kali lewat potret,
         * bukan lewat angka: ketiga lencana memang tidak muncul bersama di satu halaman.
         *
         * Saringan `lunas` menjadikannya pasti, dan sekaligus mengukur satu keadaan saringan
         * yang lain.
         */
        $halamanLunas = $this->ambil('owner.kasbon', $owner, ['status' => 'lunas']);

        file_put_contents("{$tujuan}/owner-kasbon-lunas.html", $halamanLunas);

        // Dijaga di sini, bukan dipercaya: pembengkokan data yang gagal menghasilkan halaman
        // kosong, dan halaman kosong yang diukur akan dilaporkan BERSIH tanpa membuktikan apa pun.
        $this->assertStringContainsString('Lunas', $halamanLunas);

        if ($kasbonWarteg->isNotEmpty()) {
            file_put_contents(
                "{$tujuan}/owner-kasbon-setor.html",
                $this->suntik(
                    $halamanKasbon,
                    Livewire::actingAs($owner)->test(KasbonOwner::class)
                        ->call('setor', $kasbonWarteg[0]->getKey())
                        ->html(),
                ),
            );
        }

        /*
         * Layar Karyawan, dua keadaan.
         *
         * Satu karyawan sengaja dinonaktifkan supaya lencana abu-abu "Nonaktif" ikut
         * terpotret di samping "Aktif", DAN supaya kartu ringkasan "yang bisa masuk sekarang"
         * muncul — kartu itu hanya dirender kalau jumlah aktif berbeda dari jumlah total,
         * jadi tanpa pembengkokan ini ia tidak akan pernah terukur sama sekali.
         */
        User::withoutGlobalScopes()
            ->where('tenant_id', $owner->tenant_id)
            ->where('role', 'kasir')
            ->orderBy('name')
            ->first()
            ?->forceFill(['is_active' => false])->save();

        $halamanKaryawan = $this->ambil('owner.karyawan', $owner);

        file_put_contents("{$tujuan}/owner-karyawan.html", $halamanKaryawan);

        // Panel formulirnya dipotret dalam keadaan peran KASIR, karena bentuk itulah yang
        // paling banyak medannya: username, PIN, dan cabang yang wajib.
        file_put_contents(
            "{$tujuan}/owner-karyawan-form.html",
            $this->suntik(
                $halamanKaryawan,
                Livewire::actingAs($owner)->test(KaryawanOwner::class)->call('tambah')->html(),
            ),
        );

        /*
         * Layar Biaya operasional, dua keadaan.
         *
         * Seeder demo tidak membuat satu pun biaya — layar ini akan terpotret KOSONG, dan
         * yang terukur cuma keadaan kosongnya. Datanya karena itu ditanam di sini, sengaja
         * bercampur periode (bulanan, mingguan, tahunan) supaya kolom "Per hari" membuktikan
         * konversinya, plus satu biaya yang SUDAH BERHENTI supaya lencana abu-abunya ikut
         * terpotret bersama yang hijau.
         */
        $outletDemo = Outlet::withoutGlobalScopes()
            ->where('tenant_id', $owner->tenant_id)
            ->orderBy('outlet_name')
            ->first();

        app(TenantContext::class)->forTenant($owner->tenant_id, function () use ($outletDemo) {
            BiayaOperasional::create([
                'nama' => 'Sewa tempat',
                'nominal' => 1500000,
                'periode' => PeriodeBiaya::Bulanan,
                'outlet_id' => $outletDemo?->getKey(),
                'mulai' => now()->subMonths(8)->toDateString(),
                'catatan' => 'Bayar tiap tanggal 5',
            ]);

            BiayaOperasional::create([
                'nama' => 'Listrik & air',
                'nominal' => 650000,
                'periode' => PeriodeBiaya::Bulanan,
                'outlet_id' => $outletDemo?->getKey(),
                'mulai' => now()->subMonths(8)->toDateString(),
            ]);

            BiayaOperasional::create([
                'nama' => 'Gas elpiji',
                'nominal' => 140000,
                'periode' => PeriodeBiaya::Mingguan,
                'mulai' => now()->subMonths(6)->toDateString(),
            ]);

            BiayaOperasional::create([
                'nama' => 'PBB & retribusi',
                'nominal' => 730000,
                'periode' => PeriodeBiaya::Tahunan,
                'mulai' => now()->subYear()->toDateString(),
            ]);

            BiayaOperasional::create([
                'nama' => 'Sewa lapak lama',
                'nominal' => 400000,
                'periode' => PeriodeBiaya::Bulanan,
                'mulai' => now()->subYear()->toDateString(),
                'selesai' => now()->subMonths(2)->toDateString(),
            ]);
        });

        $halamanBiaya = $this->ambil('owner.biaya', $owner, ['berhenti' => 1]);

        file_put_contents("{$tujuan}/owner-biaya.html", $halamanBiaya);

        file_put_contents(
            "{$tujuan}/owner-biaya-form.html",
            $this->suntik(
                $halamanBiaya,
                Livewire::actingAs($owner)->test(BiayaOwner::class)->call('tambah')->html(),
            ),
        );

        // Dijaga, bukan dipercaya: penanaman data yang gagal menghasilkan halaman kosong,
        // dan halaman kosong yang diukur akan dilaporkan BERSIH tanpa membuktikan apa pun.
        $this->assertStringContainsString('Sewa tempat', $halamanBiaya);

        $admin = User::withoutGlobalScopes()->where('role', 'super_admin')->firstOrFail();
        file_put_contents(
            "{$tujuan}/dasbor-admin.html",
            $this->ambil('admin.dasbor', $admin),
        );

        // Halaman masuk berada di belakang middleware guest, jadi sesi harus dilepas
        // dulu — kalau tidak, yang tertangkap adalah pengalihan, bukan formulirnya.
        auth()->logout();

        foreach (['masuk' => 'masuk', 'masuk-kasir' => 'masuk.kasir'] as $nama => $rute) {
            file_put_contents(
                "{$tujuan}/{$nama}.html",
                $this->ambil($rute),
            );
        }

        $this->assertFileExists("{$tujuan}/masuk.html");
    }

    /**
     * Layar stok & lembar opname.
     *
     * Datanya SENGAJA dibengkokkan lebih dulu. Seeder demo meninggalkan stok yang
     * hampir seluruhnya aman, dan halaman stok dalam keadaan itu tidak membuktikan
     * apa pun: blok "Harus belanja" kosong, penanda "perlu dihitung ulang" tidak ada,
     * dan tiga keadaan yang paling mudah tercampur (belum pernah dicatat / habis tanpa
     * ambang / minus) tidak pernah terpotret sama sekali.
     */
    public function test_menangkap_stok_dan_opname(): void
    {
        if (env('PRATINJAU') !== '1') {
            $this->markTestSkipped('Jalankan dengan PRATINJAU=1 untuk menangkap HTML.');
        }

        $this->seed(PlanSeeder::class);
        $this->seed(WartegSeeder::class);

        $tujuan = base_path('storage/pratinjau');

        if (! is_dir($tujuan)) {
            mkdir($tujuan, 0755, true);
        }

        $owner = User::withoutGlobalScopes()->where('role', 'owner')->firstOrFail();

        // Outlet yang sama dengan pilihan bawaan komponen (urut nama, tenant owner ini).
        $outlet = Outlet::withoutGlobalScopes()
            ->where('tenant_id', $owner->tenant_id)
            ->orderBy('outlet_name')
            ->firstOrFail();

        $kunci = $this->siapkanStokPratinjau($owner, $outlet);

        file_put_contents("{$tujuan}/owner-stok.html", $this->ambil('owner.stok', $owner));
        file_put_contents("{$tujuan}/owner-opname.html", $this->ambil('owner.stok.opname', $owner));

        /*
         * Keadaan yang hanya ada SETELAH sesuatu diklik dipotret lewat fragmen komponen,
         * lalu disuntikkan ke halaman yang sama supaya CSS, font, dan Alpine-nya ikut.
         * Penanda Livewire dilepas dulu — dua komponen dengan wire:id yang sama membuat
         * Livewire gagal boot, dan karena Alpine dimulai oleh Livewire, seluruh x-show
         * dan x-cloak ikut mati (halaman terpotret dalam keadaan yang tidak pernah terjadi).
         */
        $fragmenStok = Livewire::actingAs($owner)->test(Stok::class)
            ->call('bukaKartu', $kunci['beras'])
            ->call('ubahAmbang', $kunci['gula'])
            ->html();

        file_put_contents(
            "{$tujuan}/owner-stok-kartu.html",
            $this->suntik($this->ambil('owner.stok', $owner), $fragmenStok),
        );

        /*
         * Lembar opname dengan angka yang sudah diketik DAN galat yang tertahan.
         *
         * Baris beras diberi selisih tanpa alasan, jadi simpan() ditolak — dan justru
         * keadaan itulah yang harus terbaca: ringkasan galat seluruh lembar, baris berwarna
         * sebelum disimpan, kolom catatan yang muncul untuk alasan "Lainnya", dan bar bawah
         * yang menyebut berapa baris terisi.
         */
        $fragmenOpname = Livewire::actingAs($owner)->test(Opname::class)
            ->set('fisik.'.$kunci['beras'], '58')
            ->set('fisik.'.$kunci['gula'], '3')
            ->set('fisik.'.$kunci['teh'], '0')
            ->set('alasan.'.$kunci['teh'], AlasanOpname::Lainnya->value)
            ->call('simpan')
            ->html();

        file_put_contents(
            "{$tujuan}/owner-opname-terisi.html",
            $this->suntik($this->ambil('owner.stok.opname', $owner), $fragmenOpname),
        );

        /*
         * Lembar dengan PERGANTIAN CABANG YANG DITOLAK.
         *
         * Blok peringatannya — judul, kalimat sebab-akibat, dan TIGA tombol keputusan yang
         * teksnya memuat nama cabang — hanya ada di DOM dalam keadaan ini. Tanpa tangkapan
         * ini, satu-satunya jalan keluar pemilik dari lembar yang terkunci tidak pernah
         * terukur di lebar mana pun, padahal justru tiga tombol berteks panjang itulah yang
         * paling mungkin melebarkan halaman di 390px.
         */
        $outletLain = Outlet::withoutGlobalScopes()
            ->where('tenant_id', $owner->tenant_id)
            ->whereKeyNot($outlet->getKey())
            ->orderBy('outlet_name')
            ->firstOrFail();

        $fragmenTolak = Livewire::actingAs($owner)->test(Opname::class)
            ->set('fisik.'.$kunci['beras'], '58')
            ->set('fisik.'.$kunci['gula'], '3')
            ->set('outletId', $outletLain->getKey())
            ->html();

        file_put_contents(
            "{$tujuan}/owner-opname-tolak.html",
            $this->suntik($this->ambil('owner.stok.opname', $owner), $fragmenTolak),
        );

        $this->assertFileExists("{$tujuan}/owner-stok.html");
    }

    /**
     * Layar nota belanja: daftar + formulir.
     *
     * Datanya dibengkokkan lebih dulu dengan alasan yang sama seperti stok: seeder demo
     * hanya meninggalkan SATU nota, dan daftar berisi satu baris tidak membuktikan apa pun
     * — tidak ada navigasi halaman, tidak ada nota dibatalkan, dan kolom "Beli dari" hanya
     * pernah berisi satu nama pendek.
     */
    public function test_menangkap_pembelian(): void
    {
        if (env('PRATINJAU') !== '1') {
            $this->markTestSkipped('Jalankan dengan PRATINJAU=1 untuk menangkap HTML.');
        }

        $this->seed(PlanSeeder::class);
        // Warteg, bukan kelontong: ia punya DUA outlet, dan blok peringatan "nota ini
        // diketik untuk cabang lain" hanya bisa muncul kalau ada cabang kedua.
        $this->seed(WartegSeeder::class);

        $tujuan = base_path('storage/pratinjau');

        if (! is_dir($tujuan)) {
            mkdir($tujuan, 0755, true);
        }

        $owner = User::withoutGlobalScopes()->where('role', 'owner')->firstOrFail();

        $outlet = Outlet::withoutGlobalScopes()
            ->where('tenant_id', $owner->tenant_id)
            ->orderBy('outlet_name')
            ->firstOrFail();

        $outletLain = Outlet::withoutGlobalScopes()
            ->where('tenant_id', $owner->tenant_id)
            ->whereKeyNot($outlet->getKey())
            ->orderBy('outlet_name')
            ->firstOrFail();

        $kunci = $this->siapkanPembelianPratinjau($owner, $outlet, $outletLain);

        file_put_contents("{$tujuan}/owner-pembelian.html", $this->ambil('owner.pembelian', $owner));
        file_put_contents("{$tujuan}/owner-pembelian-baru.html", $this->ambil('owner.pembelian.baru', $owner));

        /*
         * Panel rincian hanya ada di DOM sesudah sebuah nota dibuka — dan justru di situlah
         * tabel isi nota, ringkasan uangnya, dan tombol batalkan berada.
         */
        $fragmenRincian = Livewire::actingAs($owner)->test(Pembelian::class)
            ->call('bukaRincian', $kunci['nota'])
            ->html();

        file_put_contents(
            "{$tujuan}/owner-pembelian-rincian.html",
            $this->suntik($this->ambil('owner.pembelian', $owner), $fragmenRincian),
        );

        /*
         * Halaman yang SAMA, tapi dengan popup foto struknya TERBUKA.
         *
         * Keadaan bawaan popup adalah tertutup, dan popup yang tidak pernah terpotret tidak
         * pernah terukur sama sekali: ketujuh angka kerapian akan keluar nol untuk markup
         * yang memang tidak sedang tampil, lalu lolos sebagai bersih (CLAUDE.md). Justru
         * popup inilah yang paling mungkin melebihi layar — di dalamnya ada foto struk yang
         * lebih tinggi daripada lebarnya tiga kali.
         */
        file_put_contents(
            "{$tujuan}/owner-pembelian-rincian-bukti.html",
            $this->suntikPopupBukti(
                $this->suntik($this->ambil('owner.pembelian', $owner), $fragmenRincian),
            ),
        );

        /*
         * Blok "foto kwitansi/struk" punya TIGA keadaan yang bunyinya berbeda, dan yang
         * tidak terpotret tidak pernah terukur — angka nol untuk markup yang tidak ada di
         * halamannya lolos sebagai bersih (CLAUDE.md).
         *
         * Yang dipotret di sini dua sisanya: nota TANPA foto (keadaan netral + kalimat
         * "kenapa menyimpan struk itu berguna" + tombol pilih foto), dan nota DIBATALKAN yang
         * berfoto (fotonya tetap tampil, kedua tombolnya TIDAK dirender, dan alasannya
         * tertulis). Keadaan pertama — nota berfoto yang masih hidup — ada di tangkapan
         * rincian di atas.
         */
        $fragmenTanpaBukti = Livewire::actingAs($owner)->test(Pembelian::class)
            ->call('bukaRincian', $kunci['notaTanpaBukti'])
            ->html();

        file_put_contents(
            "{$tujuan}/owner-pembelian-rincian-tanpa-bukti.html",
            $this->suntik($this->ambil('owner.pembelian', $owner), $fragmenTanpaBukti),
        );

        $fragmenDibatalkan = Livewire::actingAs($owner)->test(Pembelian::class)
            ->call('bukaRincian', $kunci['notaDibatalkan'])
            ->html();

        file_put_contents(
            "{$tujuan}/owner-pembelian-rincian-dibatalkan.html",
            $this->suntik($this->ambil('owner.pembelian', $owner), $fragmenDibatalkan),
        );

        /*
         * Formulir dalam keadaan yang paling padat: baris terisi (bar uang berisi nominal
         * sungguhan), ringkasan galat karena harga belum diisi, DAN blok pergantian cabang
         * yang ditolak. Ketiganya blok berteks panjang, dan ketiganya paling mungkin
         * melebarkan halaman di 390px — keadaan yang tidak pernah terukur kalau yang
         * dipotret cuma formulir kosong.
         */
        $fragmenForm = Livewire::actingAs($owner)->test(PembelianBaru::class)
            ->set('beliDari', 'CV Sumber Pangan')
            ->set('jumlah.'.$kunci['beras'], '25')
            ->set('harga.'.$kunci['beras'], '13000')
            ->set('jumlah.'.$kunci['gula'], '4')
            ->set('ongkosKirim', '25000')
            ->call('simpan')
            ->set('outletId', $outletLain->getKey())
            ->html();

        file_put_contents(
            "{$tujuan}/owner-pembelian-baru-terisi.html",
            $this->suntik($this->ambil('owner.pembelian.baru', $owner), $fragmenForm),
        );

        $this->assertFileExists("{$tujuan}/owner-pembelian.html");
    }

    /**
     * Nota belanja contoh: cukup banyak untuk berhalaman, dan setiap keadaan yang harus
     * dibedakan di layar benar-benar ada.
     *
     * @return array<string, string>
     */
    private function siapkanPembelianPratinjau(User $owner, Outlet $outlet, Outlet $outletLain): array
    {
        $bahan = RawMaterial::withoutGlobalScopes()
            ->where('tenant_id', $owner->tenant_id)
            ->get()
            ->keyBy('nama');

        $produk = Product::withoutGlobalScopes()
            ->where('tenant_id', $owner->tenant_id)
            ->get()
            ->keyBy('nama_produk');

        // Satu barang diberi konversi satuan supaya baris "1 dus = 24 pcs" ikut terpotret:
        // itu keterangan yang menjelaskan kenapa angka yang diketik (2) berbeda dari angka
        // yang bertambah di kartu stok (48).
        $produk['Air Mineral 600ml']->forceFill([
            'satuan' => Satuan::Dus,
            'satuan_dasar' => Satuan::Pcs,
            'isi_per_satuan' => 24,
        ])->save();

        $catat = app(CatatPembelianAction::class);

        $pemasok = ['CV Sumber Pangan', 'Pasar Kranggan', 'Grosir Bu Yanti', 'Toko Berkah Jaya'];
        $notaPertama = null;
        $notaTanpaBukti = null;
        $notaDibatalkan = null;

        // 11 nota: dengan 10 baris per halaman, navigasi halamannya benar-benar muncul.
        for ($i = 0; $i < 11; $i++) {
            $nota = $catat->execute(
                $i % 3 === 2 ? $outletLain : $outlet,
                $owner,
                [
                    // Satu nota sengaja tanpa nama toko: belanja di pasar tidak selalu punya
                    // nama, dan kolomnya harus berbunyi "—", bukan kosong.
                    'beli_dari' => $i === 4 ? null : $pemasok[$i % count($pemasok)],
                    'tanggal' => now()->subDays($i)->toDateString(),
                    'diskon' => $i % 4 === 1 ? 5000 : 0,
                    'ongkos_kirim' => $i % 2 === 0 ? 25000 : 0,
                    'catatan' => $i === 0 ? 'Diantar sore, ongkir dibayar tunai.' : null,
                    /*
                     * DUA nota berstatus "belum datang", dan salah satunya nota pertama —
                     * yaitu nota yang panel rinciannya ikut dipotret.
                     *
                     * Tanpa satu pun nota begini, seluruh tampilan keadaan "belum datang"
                     * TIDAK PERNAH terpotret: lencana "Masih di jalan", tombol "Tandai sudah
                     * datang" beserta blok konfirmasinya di tiga tempat, dan kartu "Menunggu
                     * datang" yang berisi nominal sungguhan. Tangkapan yang tidak memuat
                     * keadaannya menghasilkan tujuh angka nol untuk markup yang tidak ada di
                     * halamannya — lolos tanpa terukur, cacat yang persis diperingatkan di
                     * CLAUDE.md.
                     */
                    'sudah_datang' => ! in_array($i, [0, 6], true),
                    'baris' => [
                        ['raw_material_id' => $bahan['Beras']->getKey(), 'qty_beli' => 25, 'harga_satuan' => 13000],
                        ['raw_material_id' => $bahan['Ayam Potong']->getKey(), 'qty_beli' => 10, 'harga_satuan' => 38000],
                        ['product_id' => $produk['Air Mineral 600ml']->getKey(), 'qty_beli' => 2, 'harga_satuan' => 58000],
                    ],
                ],
            );

            $notaPertama ??= $nota;

            // Nota kedua sengaja DIBIARKAN tanpa foto: keadaan "belum ada fotonya" adalah
            // keadaan yang paling sering (belanja pasar tidak berstruk), jadi ia harus ikut
            // terukur — termasuk kalimat yang menjelaskan kenapa menyimpan struk itu berguna.
            if ($i === 1) {
                $notaTanpaBukti = $nota;
            }

            // Satu nota dibatalkan: stoknya kembali, notanya TETAP ada di daftar, dan
            // lencananya harus terbaca berbeda dari nota yang barangnya sudah datang.
            if ($i === 3) {
                app(BatalkanPembelianAction::class)->execute($nota, $owner);
                $notaDibatalkan = $nota->fresh();
            }
        }

        /*
         * Foto bukti untuk pratinjau ditulis ke folder `pratinjau/`, BUKAN lewat
         * SimpanBuktiBelanjaAction.
         *
         * Aksinya akan menaruh berkasnya di `bukti-belanja/{tenant_id}/`, dan itu folder
         * unggahan sungguhan yang TIDAK boleh dibersihkan sesudah pengukuran — sampah
         * pratinjau di situ hanya bisa dipisahkan dari bukti asli dengan menebak. Satu-satunya
         * folder yang boleh dihapus adalah `storage/app/public/pratinjau` (aturan keras nomor 1
         * CLAUDE.md), jadi berkas pratinjaunya ditaruh di sana dan kolomnya diisi langsung.
         *
         * Berkasnya HARUS benar-benar ada di disk: punyaBukti() memeriksa keberadaannya, dan
         * urlBukti() mengembalikan null untuk path yang menggantung — panelnya lalu terpotret
         * dalam keadaan "belum ada foto" sementara yang mau diukur justru keadaan sebaliknya.
         * Gambar yang gagal dimuat juga tercatat oleh ukur.mjs.
         */
        /*
         * Disk 'lampiran' dan tabel `lampiran` — BUKAN disk 'public' + kolom bukti_path.
         *
         * Kolom itu sudah dibuang. Cacat ini hanya muncul dengan PRATINJAU=1, jadi ia lolos
         * dari suite biasa sampai tangkapan berikutnya dihasilkan — pengingat bahwa jalur
         * yang dijaga env flag tidak pernah ikut terperiksa saat uji dijalankan biasa.
         */
        $berkas = [
            [$notaPertama, 'pratinjau/bukti-struk.jpg', 'struk.jpg'],
            [$notaDibatalkan, 'pratinjau/bukti-struk-dibatalkan.jpg', 'struk-dikembalikan.jpg'],
        ];

        foreach ($berkas as [$nota, $path, $nama]) {
            $isi = UploadedFile::fake()->image($nama, 720, 1040)->get();

            Storage::disk('lampiran')->put($path, $isi);

            // tenant_id TIDAK fillable — aturan keras nomor 2, dan ia bekerja: mengirimnya
            // lewat create() diabaikan begitu saja, lalu NOT NULL menolak barisnya. Diisi
            // lewat konteks tenant, jalur yang sama dengan produksi.
            app(TenantContext::class)->forTenant($nota->tenant_id, fn () => Lampiran::create([
                'lampirable_type' => $nota->getMorphClass(),
                'lampirable_id' => $nota->getKey(),
                'path' => $path,
                'nama_asli' => $nama,
                'mime' => 'image/jpeg',
                'ukuran' => strlen((string) $isi),
                'urutan' => 1,
            ]));
        }

        return [
            'nota' => $notaPertama->getKey(),
            'notaTanpaBukti' => $notaTanpaBukti->getKey(),
            'notaDibatalkan' => $notaDibatalkan->getKey(),
            'beras' => $bahan['Beras']->getKey(),
            'gula' => $bahan['Gula Pasir']->getKey(),
        ];
    }

    /**
     * Membengkokkan stok outlet ini supaya SETIAP keadaan yang harus dibedakan di layar
     * benar-benar ada datanya.
     *
     * @return array<string, string> kunci baris (id barang) yang dipakai fragmen
     */
    private function siapkanStokPratinjau(User $owner, Outlet $outlet): array
    {
        $cariStok = fn (string $kolom, string $id): ?Stock => Stock::withoutGlobalScopes()
            ->where('outlet_id', $outlet->getKey())
            ->where($kolom, $id)
            ->first();

        $bahan = RawMaterial::withoutGlobalScopes()
            ->where('tenant_id', $owner->tenant_id)
            ->get()
            ->keyBy('nama');

        $produk = Product::withoutGlobalScopes()
            ->where('tenant_id', $owner->tenant_id)
            ->get()
            ->keyBy('nama_produk');

        // 1. Konversi satuan: kekurangan dinyatakan dalam DUS, bukan 50 pcs. Ini satu-satunya
        //    bentuk yang bisa dibawa ke grosir.
        $air = $produk['Air Mineral 600ml'];
        $air->forceFill([
            'satuan' => Satuan::Dus,
            'satuan_dasar' => Satuan::Pcs,
            'isi_per_satuan' => 24,
        ])->save();
        $cariStok('product_id', $air->getKey())?->forceFill([
            'jumlah_saat_ini' => 10,
            'stok_minimum' => 60,
        ])->save();

        // 2. Habis TANPA ambang: tetap masuk daftar belanja (rak kosong tetap rak kosong),
        //    tapi labelnya harus berbunyi lain daripada "kurang 3 dus".
        $cariStok('product_id', $produk['Kerupuk']->getKey())?->forceFill([
            'jumlah_saat_ini' => 0,
            'stok_minimum' => 0,
        ])->save();

        // 3. Minus: masalah pencatatan, bukan masalah belanja.
        $cariStok('raw_material_id', $bahan['Teh Tubruk']->getKey())?->forceFill([
            'jumlah_saat_ini' => -2,
            'stok_minimum' => 1,
        ])->save();

        // 4. Menipis biasa.
        $cariStok('raw_material_id', $bahan['Gula Pasir']->getKey())?->forceFill([
            'jumlah_saat_ini' => 3,
            'stok_minimum' => 4,
        ])->save();

        // 5. Saldo aman TAPI perlu dihitung ulang: penjualan offline tiba sesudah opname,
        //    jadi saldonya mungkin terpotong dua kali. Ini satu-satunya penanda yang
        //    memberitahu pemilik, dan ia tidak selalu berbarengan dengan status di bawah ambang.
        $cariStok('raw_material_id', $bahan['Ayam Potong']->getKey())?->forceFill([
            'perlu_diperiksa' => true,
            'opname_terakhir_pada' => now()->subDays(2),
        ])->save();

        // 6. Belum pernah dicatat sama sekali: barisnya dihapus, jadi punya_baris = false.
        //    Di layar harus terbaca "belum dihitung", bukan "0".
        $cariStok('raw_material_id', $bahan['Telur Ayam']->getKey())?->delete();

        // 7. Riwayat pergerakan untuk kartu stok — tanpa ini panelnya terpotret kosong dan
        //    tidak membuktikan apa pun tentang tampilan mutasinya.
        $stokBeras = $cariStok('raw_material_id', $bahan['Beras']->getKey());
        $adjust = app(AdjustStockAction::class);

        if ($stokBeras !== null) {
            $stokBeras->forceFill(['opname_terakhir_pada' => now()->subDays(6)])->save();

            $adjust->execute($stokBeras, StockMovementType::Masuk, 25, null, $owner->getKey(), 'Penerimaan PO-20260701-004');
            $adjust->execute($stokBeras->fresh(), StockMovementType::Keluar, -3.5, null, null, 'Terjual 14 porsi');
            $adjust->execute($stokBeras->fresh(), StockMovementType::Opname, 2, null, $owner->getKey(),
                'Hitung fisik: sistem 78,5 → fisik 80,5', AlasanOpname::TemuanLebih);
            $adjust->execute($stokBeras->fresh(), StockMovementType::Keluar, -1.25, null, null, null);
            $adjust->execute($stokBeras->fresh(), StockMovementType::Transfer, -5, null, $owner->getKey(), 'Kirim ke cabang');
        }

        return [
            'beras' => $bahan['Beras']->getKey(),
            'gula' => $bahan['Gula Pasir']->getKey(),
            'teh' => $bahan['Teh Tubruk']->getKey(),
        ];
    }

    /**
     * Menyuntikkan fragmen komponen ke dalam halaman yang sudah utuh.
     *
     * Penanda Livewire dibuang: dibiarkan utuh, halaman berisi dua komponen dengan wire:id
     * yang sama dan Livewire gagal saat boot — dan karena Alpine dimulai oleh Livewire,
     * pratinjaunya memperlihatkan halaman yang mati. (Mengganti wire:id saja tidak cukup:
     * snapshotnya bersegel checksum.)
     */
    /**
     * Membuka popup foto struk pada tangkapan, lewat KETUKAN sungguhan pada pemicunya.
     *
     * Bukan dengan menyetel keadaan Alpine dari luar, dan itu penting: yang harus diukur
     * adalah popup yang lahir dari jalur kode yang benar-benar dipakai pemilik (termasuk
     * kunci gulir latar dan x-trap), bukan sebuah <div> yang dipaksa tampil. Pemicunya
     * dikenali dari `aria-haspopup="dialog"` — penanda yang memang wajib ada di markupnya,
     * jadi tidak ada atribut yang ditambahkan ke aplikasi hanya demi pengukuran.
     *
     * Yang diketuk pemicu TERAKHIR: fragmen panel rincian disuntikkan di ujung <body>,
     * sesudah halaman aslinya.
     */
    private function suntikPopupBukti(string $halaman): string
    {
        $skrip = '<script>document.addEventListener("alpine:initialized",function(){'
            .'var pemicu=document.querySelectorAll("[aria-haspopup=\'dialog\']");'
            .'if(pemicu.length>0){pemicu[pemicu.length-1].click();}'
            .'},{once:true});</script>';

        return str_replace('</head>', $skrip.'</head>', $halaman);
    }

    private function suntik(string $halaman, string $fragmen): string
    {
        $fragmen = preg_replace('/\s(wire:id|wire:snapshot|wire:effects)="[^"]*"/', '', $fragmen);

        return str_replace('</body>', $fragmen.'</body>', $halaman);
    }

    /**
     * Mengambil HTML satu halaman untuk dipotret.
     *
     * flushState() wajib dipanggil lebih dulu: Livewire menyuntikkan tag skripnya HANYA
     * SEKALI per proses PHP. Tanpa ini hanya halaman pertama yang membawa Livewire dan
     * Alpine, dan halaman berikutnya terpotret dalam keadaan yang tidak pernah terjadi
     * di peramban — seluruh x-show, x-cloak, dan panel yang bergantung pada Alpine ikut
     * salah, sehingga pratinjaunya menyesatkan alih-alih membuktikan.
     */
    private function ambil(string $rute, ?User $sebagai = null, array $parameter = []): string
    {
        Livewire::flushState();

        if ($sebagai !== null) {
            $this->actingAs($sebagai);
        }

        return $this->get(route($rute, $parameter))->assertOk()->getContent();
    }

    /**
     * Transaksi contoh bertanggal HARI INI untuk kasir pratinjau.
     *
     * Seeder demo menaruh transaksi lunasnya di hari-hari sebelumnya, jadi tanpa ini
     * riwayat di pratinjau selalu kosong dan tidak membuktikan apa pun soal tampilan
     * barisnya — badge kasbon, void, dan gabungan metode bayar tidak pernah terlihat.
     */
    private function transaksiContohHariIni(User $kasir): void
    {
        /*
         * Konteks tenant diarahkan ke tenant kasir ini SEBELUM membuat record.
         * Trait BelongsToTenant mengisi tenant_id dari konteks itu; tanpa diarahkan,
         * transaksinya lahir di tenant lain (atau tanpa tenant) dan halaman kasir
         * tidak akan pernah melihatnya — persis yang terjadi pada percobaan pertama.
         */
        app(TenantContext::class)->setTenant($kasir->tenant_id)->setOutlet($kasir->outlet_id);

        $contoh = [
            ['lunas', 34000, [['cash', 34000]], '11:05', 2],
            ['lunas', 27000, [['cash', 15000], ['qris', 12000]], '11:40', 3],
            ['belum_lunas', 18000, [['kasbon', 18000]], '12:10', 2],
            ['void', 9000, [['cash', 9000]], '12:25', 1],
        ];

        foreach ($contoh as $i => [$status, $total, $bayar, $jam, $item]) {
            $trx = Transaction::create([
                'outlet_id' => $kasir->outlet_id,
                'staff_id' => $kasir->getKey(),
                'nomor_transaksi' => 'TRX-'.today()->format('Ymd').'-PRA-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT),
                'mode' => 'langsung',
                'subtotal' => $total,
                'total' => $total,
                'status' => $status,
                'waktu_transaksi' => today()->setTimeFromTimeString($jam),
                'origin' => $i === 1 ? 'offline' : 'online',
            ]);

            for ($n = 0; $n < $item; $n++) {
                $trx->items()->create([
                    'nama_produk' => 'Contoh '.($n + 1),
                    'qty' => 1,
                    'harga_satuan' => intdiv($total, $item),
                    'subtotal' => intdiv($total, $item),
                ]);
            }

            foreach ($bayar as [$metode, $jumlah]) {
                $trx->payments()->create(['metode' => $metode, 'jumlah' => $jumlah]);
            }
        }
    }
}

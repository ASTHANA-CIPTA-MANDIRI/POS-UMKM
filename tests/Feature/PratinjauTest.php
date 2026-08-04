<?php

namespace Tests\Feature;

use App\Actions\Kas\BukaSesiKasAction;
use App\Actions\Kas\KoreksiModalAwalAction;
use App\Actions\Stock\AdjustStockAction;
use App\Enums\AlasanOpname;
use App\Enums\Satuan;
use App\Enums\StockMovementType;
use App\Livewire\Pages\Owner\Opname;
use App\Livewire\Pages\Owner\Produk;
use App\Livewire\Pages\Owner\Stok;
use App\Models\CashSession;
use App\Models\Outlet;
use App\Models\Product;
use App\Models\RawMaterial;
use App\Models\Stock;
use App\Models\Transaction;
use App\Models\User;
use App\Support\TenantContext;
use Database\Seeders\DepotLaundrySeeder;
use Database\Seeders\KelontongSeeder;
use Database\Seeders\PlanSeeder;
use Database\Seeders\SuperAdminSeeder;
use Database\Seeders\WartegSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        }

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
        file_put_contents(
            "{$tujuan}/owner-produk.html",
            $this->ambil('owner.produk', $owner),
        );

        file_put_contents(
            "{$tujuan}/owner-laporan.html",
            $this->ambil('owner.laporan', $owner),
        );

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

        $this->assertFileExists("{$tujuan}/owner-stok.html");
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

<?php

namespace Tests\Feature;

use App\Actions\Purchase\BatalkanPembelianAction;
use App\Actions\Purchase\TerimaPembelianAction;
use App\Enums\DocumentStatus;
use App\Enums\Satuan;
use App\Enums\StockMovementType;
use App\Enums\UserRole;
use App\Livewire\Pages\Owner\Pembelian\Pembelian;
use App\Models\Pembelian\PurchaseOrder;
use App\Models\Pembelian\PurchaseOrderItem;
use App\Models\Stok\Stock;
use App\Models\Stok\StockMovement;
use App\Models\Tenant\Outlet;
use App\Models\Tenant\Tenant;
use App\Models\Tenant\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MembuatDataPembelian;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Menandai nota "barangnya sudah datang": satu-satunya jalur pembelian menaikkan stok.
 *
 * Kenapa aksinya terpisah dari CatatPembelianAction, bukan cabang `if` di dalamnya: dengan
 * aksi sendiri, AdjustStockAction dan SiapkanBarisStokAction punya TEPAT SATU pemanggil dari
 * jalur pembelian. Cabang `if` di tengah aksi sepanjang itu adalah cabang yang suatu hari
 * dibalik oleh perbaikan tak berhubungan, tanpa satu pun galat saat itu terjadi.
 *
 * Yang paling menentukan di berkas ini: uji potret konversi. Semua uji lainnya akan tetap
 * hijau kalau penerimaan menghitung ulang konversi dari master — dan justru bentuk itu yang
 * menghasilkan saldo salah tanpa satu pun galat.
 */
class PembelianTerimaTest extends TestCase
{
    use MembuatDataPembelian, MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $outlet;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant('Toko Terima');
        $this->outlet = $this->buatOutlet($this->tenant, 'Cabang Terima');
        $this->owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik Terima',
            'email' => 'owner@terima.test',
            'password' => 'rahasia123',
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());
    }

    /** @param  array<int, array<string, mixed>>  $baris */
    private function notaBelumDatang(array $baris, ?Outlet $outlet = null): PurchaseOrder
    {
        return $this->catatNotaBelumDatang($outlet ?? $this->outlet, $this->owner, ['baris' => $baris]);
    }

    /* ── Menambah stok, tepat satu kali ──────────────────────────────────── */

    public function test_menandai_datang_menambah_stok_satu_kali(): void
    {
        $susu = $this->buatProduk('Susu Kotak', [
            'satuan' => Satuan::Dus,
            'satuan_dasar' => Satuan::Pcs,
            'isi_per_satuan' => 12,
        ]);

        $this->buatStok($this->outlet, $susu, 5);

        $nota = $this->notaBelumDatang([$this->baris($susu, 2, 58000)]);

        $this->assertTrue(app(TerimaPembelianAction::class)->execute($nota, $this->owner));

        $this->assertEqualsWithDelta(29.0, $this->saldo($this->outlet, $susu), 0.001,
            '5 + 24 pcs (2 dus isi 12), bukan 5 + 2');

        $mutasi = StockMovement::query()->sole();

        $this->assertSame(StockMovementType::Masuk, $mutasi->tipe);
        $this->assertEqualsWithDelta(24.0, (float) $mutasi->jumlah, 0.001);
        $this->assertEqualsWithDelta(29.0, (float) $mutasi->saldo_sesudah, 0.001);
        $this->assertSame($nota->getKey(), $mutasi->referensi_id,
            'mutasinya menunjuk notanya, supaya riwayat barang bisa menjelaskan asal angkanya');
        $this->assertNull($mutasi->alasan, 'tipe Masuk sudah menjelaskan sebabnya; kolom alasan hanya untuk selisih');

        $this->assertSame(DocumentStatus::Diterima, $nota->fresh()->status);
    }

    /**
     * IDEMPOTENSI — dan cacat yang dijaga di sini tidak menghasilkan satu pun galat.
     *
     * Stok boleh berapa saja di POS ini (aturan 5 CLAUDE.md), jadi penambahan kedua tidak
     * ditolak oleh apa pun: tidak ada galat, tidak ada penolakan, hanya saldo yang salah dan
     * satu baris riwayat tambahan yang terbaca sah. Pemicunya sehari-hari: tombol yang ditekan
     * dua kali karena jaringan lambat.
     */
    public function test_menandai_datang_dua_kali_hanya_menghasilkan_satu_mutasi_masuk(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');
        $this->buatStok($this->outlet, $kopi, 10);

        $nota = $this->notaBelumDatang([$this->baris($kopi, 20, 1500)]);

        $this->assertTrue(app(TerimaPembelianAction::class)->execute($nota, $this->owner),
            'penerimaan pertama memang terjadi');
        $this->assertFalse(app(TerimaPembelianAction::class)->execute($nota->fresh(), $this->owner),
            'penerimaan kedua harus berhenti, dan mengatakannya lewat nilai kembalian');

        $this->assertEqualsWithDelta(30.0, $this->saldo($this->outlet, $kopi), 0.001,
            'saldo 30, bukan 50');
        $this->assertSame(1, StockMovement::query()->count());

        // Jalur yang sebenarnya dipakai: tombol di daftar, ditekan dua kali.
        $notaKedua = $this->notaBelumDatang([$this->baris($kopi, 20, 1500)]);

        Livewire::actingAs($this->owner)
            ->test(Pembelian::class)
            ->call('tandaiDatang', $notaKedua->getKey())
            ->call('tandaiDatang', $notaKedua->getKey());

        $this->assertEqualsWithDelta(50.0, $this->saldo($this->outlet, $kopi), 0.001);
        $this->assertSame(2, StockMovement::query()->count());
    }

    /**
     * Dua perangkat menandai datang pada saat yang sama.
     *
     * Yang menahannya bukan urutan pemanggilan melainkan pembacaan ULANG status DI DALAM
     * lockForUpdate() — memakai `$po->status` dari model yang sudah dimuat lebih dulu membuat
     * kedua permintaan sama-sama melihat "belum datang" dan sama-sama menambah stok. Di sini
     * kedua model sengaja dimuat LEBIH DULU, sebelum aksi pertama jalan; itu tepat keadaan
     * dua tablet yang merender daftar pada saat yang sama.
     */
    public function test_dua_perangkat_menandai_datang_bersamaan_hanya_satu_yang_berhasil(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');
        $this->buatStok($this->outlet, $kopi, 10);

        $nota = $this->notaBelumDatang([$this->baris($kopi, 20, 1500)]);

        $perangkatA = PurchaseOrder::query()->findOrFail($nota->getKey());
        $perangkatB = PurchaseOrder::query()->findOrFail($nota->getKey());

        $this->assertSame(DocumentStatus::Dikirim, $perangkatA->status);
        $this->assertSame(DocumentStatus::Dikirim, $perangkatB->status,
            'prasyarat: kedua perangkat memegang salinan yang sama-sama berkata "belum datang"');

        $this->assertTrue(app(TerimaPembelianAction::class)->execute($perangkatA, $this->owner));
        $this->assertFalse(app(TerimaPembelianAction::class)->execute($perangkatB, $this->owner),
            'perangkat kedua harus kalah, walau salinannya masih berkata "belum datang"');

        $this->assertEqualsWithDelta(30.0, $this->saldo($this->outlet, $kopi), 0.001);
        $this->assertSame(1, StockMovement::query()->count());
    }

    /**
     * POTRET KONVERSI, bukan master yang sudah berubah. Uji paling menentukan di berkas ini.
     *
     * Keadaannya sehari-hari di kelontong: grosir mengganti kemasan, jadi pemilik membetulkan
     * `isi_per_satuan` produknya dari 12 menjadi 10. Nota yang SUDAH dicatat sebelum
     * perubahan itu tetap 2 dus isi 12 = 24 pcs — itulah yang benar-benar akan turun dari
     * motor. Menghitung ulang dari master saat penerimaan memasukkan 20 pcs.
     *
     * Yang membuatnya berbahaya: selisih 4 pcs itu tidak melanggar aturan apa pun. Tidak ada
     * galat, tidak ada penolakan, saldonya cuma salah — selamanya, sampai ada yang menghitung
     * fisik dan mencatatnya sebagai "barang hilang".
     */
    public function test_menandai_datang_memakai_potret_konversi_bukan_master_yang_sudah_berubah(): void
    {
        $susu = $this->buatProduk('Susu Kotak', [
            'satuan' => Satuan::Dus,
            'satuan_dasar' => Satuan::Pcs,
            'isi_per_satuan' => 12,
        ]);

        $nota = $this->notaBelumDatang([$this->baris($susu, 10, 58000)]);

        $item = PurchaseOrderItem::query()->where('purchase_order_id', $nota->getKey())->sole();

        $this->assertEqualsWithDelta(120.0, (float) $item->qty, 0.001, 'prasyarat: potretnya 10 × 12');
        $this->assertEqualsWithDelta(12.0, (float) $item->isi_per_satuan_beli, 0.001);

        // Grosir ganti kemasan; masternya dibetulkan SESUDAH notanya dicatat.
        $susu->isi_per_satuan = 10;
        $susu->save();

        app(TerimaPembelianAction::class)->execute($nota, $this->owner);

        $this->assertEqualsWithDelta(120.0, $this->saldo($this->outlet, $susu), 0.001,
            'yang masuk 120 pcs — potret di nota — bukan 100 pcs hasil hitung ulang dari master');
        $this->assertEqualsWithDelta(120.0, (float) StockMovement::query()->sole()->jumlah, 0.001);
    }

    /**
     * Harga beli master diperbarui PER SATUAN DASAR, dan baru pada saat penerimaan.
     *
     * 10 dus @58.000 isi 12 = 4.833,33 per pcs. Menyimpan 58.000 tidak memunculkan satu pun
     * galat, tapi nilai persediaan melar 12 kali lipat dan setiap keputusan harga jual yang
     * dibuat dari angka itu salah.
     */
    public function test_menandai_datang_memperbarui_harga_beli_master_per_satuan_dasar(): void
    {
        $susu = $this->buatProduk('Susu Kotak', [
            'satuan' => Satuan::Dus,
            'satuan_dasar' => Satuan::Pcs,
            'isi_per_satuan' => 12,
            'harga_beli' => 4000,
        ]);

        $gula = $this->buatBahan('Gula Pasir', ['harga_beli_terakhir' => 12000]);

        $nota = $this->notaBelumDatang([
            $this->baris($susu, 10, 58000),
            $this->baris($gula, 5, 15000),
        ]);

        $this->assertEqualsWithDelta(4000.0, (float) $susu->fresh()->harga_beli, 0.01,
            'prasyarat: harga lama masih berlaku selama barangnya belum datang');

        app(TerimaPembelianAction::class)->execute($nota, $this->owner);

        $this->assertEqualsWithDelta(4833.33, (float) $susu->fresh()->harga_beli, 0.01,
            '580.000 ÷ 120 pcs — per satuan DASAR, bukan 58.000 per dus');
        $this->assertEqualsWithDelta(15000.0, (float) $gula->fresh()->harga_beli_terakhir, 0.01,
            'bahan baku tidak punya konversi, jadi harganya memang per satuannya sendiri');
    }

    /**
     * Bonus grosir (harga 0) tetap tidak menimpa harga beli, juga di jalur penerimaan.
     *
     * "Beli 10 dapat 1" dicatat dengan harga 0, dan menimpakan nol berarti menghapus harga
     * yang benar — barangnya lalu terbaca tidak bernilai di seluruh laporan persediaan.
     */
    public function test_menandai_datang_baris_berharga_nol_tidak_menimpa_harga_beli(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet', ['harga_beli' => 1500]);

        $nota = $this->notaBelumDatang([$this->baris($kopi, 1, 0)]);

        app(TerimaPembelianAction::class)->execute($nota, $this->owner);

        $this->assertEqualsWithDelta(1500.0, (float) $kopi->fresh()->harga_beli, 0.01);
        $this->assertEqualsWithDelta(1.0, $this->saldo($this->outlet, $kopi), 0.001,
            'barangnya tetap masuk stok — yang gratis tetap ada di rak');
    }

    public function test_menandai_datang_mengisi_diterima_pada_dan_qty_diterima_penuh(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet', [
            'satuan' => Satuan::Dus,
            'satuan_dasar' => Satuan::Pcs,
            'isi_per_satuan' => 40,
        ]);

        $nota = $this->notaBelumDatang([$this->baris($kopi, 2, 60000)]);

        $this->assertNull($nota->fresh()->diterima_pada);

        app(TerimaPembelianAction::class)->execute($nota, $this->owner);

        $segar = $nota->fresh();

        $this->assertNotNull($segar->diterima_pada);
        $this->assertTrue($segar->diterima_pada->isToday());

        // Terima SEBAGIAN sengaja tidak dibangun: qty_diterima selalu penuh, dalam satuan
        // DASAR. Selisihnya diselesaikan lewat hitung stok — dan kalimat yang mengatakannya
        // ke pemilik wajib tersedia, bukan diketik ulang per layar.
        $item = PurchaseOrderItem::query()->where('purchase_order_id', $nota->getKey())->sole();

        $this->assertEqualsWithDelta(80.0, (float) $item->qty_diterima, 0.001);
        $this->assertEqualsWithDelta((float) $item->qty, (float) $item->qty_diterima, 0.001);
    }

    /**
     * Kalimat jalan keluar untuk "yang datang tidak sama dengan nota" WAJIB tersedia di layar.
     *
     * Tanpa itu pemilik mengarang jalannya sendiri — biasanya nota kedua bernilai minus, yang
     * tidak punya wujud sama sekali di aplikasi ini. Disediakan sebagai satu konstanta supaya
     * daftar dan rincian tidak pernah memberi dua nasihat berbeda untuk satu keadaan.
     */
    public function test_layar_daftar_menyediakan_kalimat_untuk_barang_datang_tidak_sama_dengan_nota(): void
    {
        $catatan = Livewire::actingAs($this->owner)
            ->test(Pembelian::class)
            ->viewData('catatanTerimaSebagian');

        $this->assertSame(TerimaPembelianAction::CATATAN_TERIMA_SEBAGIAN, $catatan);
        $this->assertStringContainsString('Hitung stok', $catatan);
    }

    /* ── Outlet & peran ──────────────────────────────────────────────────── */

    /**
     * Barangnya masuk ke outlet NOTANYA, bukan ke outlet yang sedang dipilih di dropdown.
     *
     * Cacat yang dijaga: aksi penerimaan yang menerima outlet sebagai parameter dari layar.
     * Bentuk itu tidak memunculkan galat apa pun — barangnya cuma mendarat di cabang yang
     * tidak disaksikan pemiliknya, persis cacat yang sudah pernah terjadi di lembar hitung
     * stok. Karena itu TerimaPembelianAction tidak punya parameter outlet sama sekali.
     */
    public function test_menandai_datang_menambah_stok_di_outlet_nota_bukan_outlet_yang_sedang_dipilih_di_dropdown(): void
    {
        $cabangB = $this->buatOutlet($this->tenant, 'Cabang B');

        $kopi = $this->buatProduk('Kopi Sachet');
        $this->buatStok($this->outlet, $kopi, 5);
        $this->buatStok($cabangB, $kopi, 100);

        $nota = $this->notaBelumDatang([$this->baris($kopi, 20, 1500)]);

        $komponen = Livewire::actingAs($this->owner)
            ->test(Pembelian::class)
            ->set('outletId', $cabangB->getKey());

        // Layar sedang menampilkan daftar Cabang B: nota Cabang A bahkan tidak bisa disentuh
        // dari situ — gerbangnya kueri() yang sama dengan pembatalan.
        $komponen->call('tandaiDatang', $nota->getKey());

        $this->assertSame(DocumentStatus::Dikirim, $nota->fresh()->status);
        $this->assertEqualsWithDelta(5.0, $this->saldo($this->outlet, $kopi), 0.001);
        $this->assertEqualsWithDelta(100.0, $this->saldo($cabangB, $kopi), 0.001);

        // Dropdown dikembalikan ke "semua outlet", lalu notanya ditandai datang. Yang
        // menentukan tujuannya adalah notanya, bukan pilihan di layar.
        $komponen->set('outletId', '')->call('tandaiDatang', $nota->getKey());

        $this->assertEqualsWithDelta(25.0, $this->saldo($this->outlet, $kopi), 0.001,
            'barangnya masuk ke cabang notanya');
        $this->assertEqualsWithDelta(100.0, $this->saldo($cabangB, $kopi), 0.001,
            'tidak boleh ada satu pun barang yang mendarat di cabang lain');
        $this->assertSame($this->outlet->getKey(), StockMovement::query()->sole()->outlet_id);
    }

    /** Manager outlet: nota cabang lain terbaca sebagai "tidak ada", bukan sebagai tombol. */
    public function test_manager_outlet_tidak_bisa_menandai_datang_nota_cabang_lain(): void
    {
        $cabangB = $this->buatOutlet($this->tenant, 'Cabang B');

        $manager = $this->buatUser($this->tenant, UserRole::ManagerOutlet, [
            'name' => 'Manager Cabang B',
            'email' => 'manager@terima.test',
            'password' => 'rahasia123',
            'outlet_id' => $cabangB->getKey(),
        ]);

        $this->assertNotNull($manager->scopedOutletId(), 'prasyarat: perannya memang terkunci satu outlet');

        $kopi = $this->buatProduk('Kopi Sachet');
        $this->buatStok($this->outlet, $kopi, 5);

        // Notanya milik Cabang A (outlet bawaan berkas uji ini).
        $nota = $this->notaBelumDatang([$this->baris($kopi, 20, 1500)]);

        Livewire::actingAs($manager)
            ->test(Pembelian::class)
            ->set('outletId', $this->outlet->getKey())
            ->call('tandaiDatang', $nota->getKey());

        $this->assertSame(DocumentStatus::Dikirim, $nota->fresh()->status,
            'nilai outlet dari klien diabaikan sama sekali untuk peran ini');
        $this->assertEqualsWithDelta(5.0, $this->saldo($this->outlet, $kopi), 0.001);
        $this->assertSame(0, StockMovement::query()->count());
    }

    /* ── Keadaan tepi ────────────────────────────────────────────────────── */

    /**
     * Nota yang sudah DIBATALKAN tidak bisa ditandai datang.
     *
     * Notanya sudah dinyatakan tidak pernah terjadi; menerimanya berarti memasukkan barang
     * yang dokumennya sendiri sudah dibatalkan — dan tidak ada satu pun angka di aplikasi yang
     * bisa menjelaskan mutasi itu nanti.
     */
    public function test_nota_yang_sudah_dibatalkan_tidak_bisa_ditandai_datang(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');
        $this->buatStok($this->outlet, $kopi, 5);

        $nota = $this->notaBelumDatang([$this->baris($kopi, 20, 1500)]);

        app(BatalkanPembelianAction::class)->execute($nota, $this->owner);

        $this->assertFalse(app(TerimaPembelianAction::class)->execute($nota->fresh(), $this->owner));

        $this->assertSame(DocumentStatus::Dibatalkan, $nota->fresh()->status);
        $this->assertEqualsWithDelta(5.0, $this->saldo($this->outlet, $kopi), 0.001);
        $this->assertSame(0, StockMovement::query()->count());

        // Lewat layar, pemiliknya diberi kalimat yang benar — bukan "sudah ditandai datang
        // sebelumnya", yang akan membuatnya mencari barangnya di catatan stok.
        Livewire::actingAs($this->owner)
            ->test(Pembelian::class)
            ->call('tandaiDatang', $nota->getKey())
            ->assertDispatched('toast', fn (string $nama, array $data) => str_contains($data['pesan'], 'sudah dibatalkan'));
    }

    /**
     * Saldo minus TIDAK dijadikan alasan menolak apa pun; barangnya cukup ditambahkan.
     *
     * −3 + 10 = 7. Minus lahir dari penjualan offline yang masuk belakangan (aturan 5
     * CLAUDE.md), dan barang yang datang memang menutupnya sebagian.
     */
    public function test_menandai_datang_barang_bersaldo_minus_tiga_menjadi_tujuh(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');
        $this->buatStok($this->outlet, $kopi, -3);

        $nota = $this->notaBelumDatang([$this->baris($kopi, 10, 1500)]);

        app(TerimaPembelianAction::class)->execute($nota, $this->owner);

        $this->assertEqualsWithDelta(7.0, $this->saldo($this->outlet, $kopi), 0.001);
        $this->assertEqualsWithDelta(7.0, (float) StockMovement::query()->sole()->saldo_sesudah, 0.001);
    }

    /**
     * Barang MASUK bukan barang yang DIHITUNG.
     *
     * Mematikan bendera "perlu diperiksa" atau menggeser "terakhir dihitung" karena ada barang
     * datang berarti menghapus pengingat memeriksa selisih yang belum pernah diperiksa — dan
     * pengingat yang hilang tidak meninggalkan jejak apa pun.
     */
    public function test_menandai_datang_tidak_menyentuh_opname_terakhir_maupun_perlu_diperiksa(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        $dihitungPada = now()->subDays(9)->startOfMinute();

        $this->buatStok($this->outlet, $kopi, 4, [
            'opname_terakhir_pada' => $dihitungPada,
            'perlu_diperiksa' => true,
        ]);

        $nota = $this->notaBelumDatang([$this->baris($kopi, 10, 1500)]);

        app(TerimaPembelianAction::class)->execute($nota, $this->owner);

        $stok = Stock::query()->where('product_id', $kopi->getKey())->sole();

        $this->assertEqualsWithDelta(14.0, (float) $stok->jumlah_saat_ini, 0.001);
        $this->assertTrue($stok->perlu_diperiksa, 'bendera "perlu diperiksa" tidak boleh mati karena ada barang datang');
        $this->assertSame($dihitungPada->toDateTimeString(), $stok->opname_terakhir_pada->toDateTimeString(),
            '"terakhir dihitung" hanya bergerak saat barangnya benar-benar dihitung');
    }
}

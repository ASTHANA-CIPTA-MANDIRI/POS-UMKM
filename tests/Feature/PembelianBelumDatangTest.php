<?php

namespace Tests\Feature;

use App\Actions\Kasir\SusunSisaStokAction;
use App\Actions\Stock\SusunBarisStokAction;
use App\Enums\DocumentStatus;
use App\Enums\Satuan;
use App\Enums\UserRole;
use App\Livewire\Pages\Owner\Pembelian\PembelianBaru;
use App\Livewire\Pages\Owner\Stok\Stok;
use App\Models\Outlet;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Stock;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MembuatDataPembelian;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Nota belanja yang barangnya BELUM DATANG tidak boleh menyentuh satu angka stok pun.
 *
 * CACAT NYATA yang melahirkan berkas ini: menyimpan nota LANGSUNG menambah stok. Jadi kalau
 * pemilik mencatat belanja hari ini dan barangnya datang tujuh hari kemudian, selama seminggu
 * itu saldo mengaku ada barang yang belum tiba di rak — dan layar kasir mengabari "Aman"
 * untuk barang yang raknya kosong, lalu kasir menjanjikannya ke pembeli.
 *
 * YANG SENGAJA TIDAK DIBANGUN, dan jangan pernah dibangun: apa pun yang membuat kasir tidak
 * bisa menjual. Itu melanggar aturan keras nomor 5 CLAUDE.md. Yang benar adalah dua hal yang
 * lebih sempit: kasir tidak pernah DIKABARI barangnya tersedia, dan saldo yang jadi dasar
 * keputusan tidak pernah memuat barang yang belum tiba. Uji di berkas ini memeriksa keduanya
 * — bukan memeriksa adanya penolakan.
 */
class PembelianBelumDatangTest extends TestCase
{
    use MembuatDataPembelian, MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $outlet;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant('Toko Belum Datang');
        $this->outlet = $this->buatOutlet($this->tenant, 'Cabang Belum Datang');
        $this->owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik Belum Datang',
            'email' => 'owner@belumdatang.test',
            'password' => 'rahasia123',
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());
    }

    /**
     * Nota yang barangnya belum datang, jumlahnya dalam satuan BELI.
     *
     * @param  array<int, array<string, mixed>>  $baris
     */
    private function notaBelumDatang(array $baris): PurchaseOrder
    {
        return $this->catatNotaBelumDatang($this->outlet, $this->owner, ['baris' => $baris]);
    }

    /* ── Saldo & jejaknya ────────────────────────────────────────────────── */

    public function test_nota_belum_datang_tidak_mengubah_saldo_stok(): void
    {
        $susu = $this->buatProduk('Susu Kotak', [
            'satuan' => Satuan::Dus,
            'satuan_dasar' => Satuan::Pcs,
            'isi_per_satuan' => 12,
        ]);

        $this->buatStok($this->outlet, $susu, 5);

        $nota = $this->notaBelumDatang([$this->baris($susu, 2, 58000)]);

        $this->assertEqualsWithDelta(5.0, $this->saldo($this->outlet, $susu), 0.001,
            'saldo harus tetap 5; 24 pcs itu masih di grosir, bukan di rak');

        $this->assertSame(DocumentStatus::Dikirim, $nota->fresh()->status,
            'statusnya "masih di jalan" — bukan Diterima, bukan Draft');
        $this->assertNull($nota->fresh()->diterima_pada,
            'belum ada yang diterima, jadi tidak ada tanggal penerimaan yang bisa ditulis');
    }

    /**
     * Bukan cuma saldonya yang harus tetap: riwayat barang tidak boleh memuat SATU BARIS pun
     * yang menunjuk nota ini.
     *
     * Mutasi bernilai 0 pun tidak boleh ada. Riwayat barang dibaca sebagai penjelasan atas
     * saldo, dan baris "Masuk" yang muncul di tanggal nota membuat pemilik menyimpulkan
     * barangnya sudah pernah masuk lalu hilang — lalu ia mencari barang yang tidak pernah
     * ada, atau lebih buruk, menuduh karyawannya.
     */
    public function test_nota_belum_datang_tidak_meninggalkan_mutasi_stok_yang_menunjuk_notanya(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');
        $this->buatStok($this->outlet, $kopi, 10);

        $nota = $this->notaBelumDatang([$this->baris($kopi, 20, 1500)]);

        $this->assertSame(0, StockMovement::query()->where('referensi_id', $nota->getKey())->count(),
            'tidak boleh ada mutasi yang menunjuk nota ini');
        $this->assertSame(0, StockMovement::query()->count(),
            'dan tidak ada mutasi lain yang lahir sebagai gantinya');

        // qty_diterima NOL: nota yang barangnya masih di jalan tidak boleh mengaku sudah
        // diterima seluruhnya. Kalau ia penuh sejak awal, tidak ada satu angka pun di
        // aplikasi yang bisa membantah lencana "sudah datang" yang salah.
        $item = PurchaseOrderItem::query()->where('purchase_order_id', $nota->getKey())->sole();

        $this->assertEqualsWithDelta(0.0, (float) $item->qty_diterima, 0.001);
        $this->assertEqualsWithDelta(20.0, (float) $item->qty, 0.001, 'yang dipesan tetap tercatat penuh');
    }

    /**
     * Baris `stocks` TIDAK dibuat — dan ini yang paling mudah terlewat.
     *
     * Membuat baris bersaldo 0 sudah cukup untuk merusak: status barang berpindah dari
     * "belum dihitung" menjadi "habis", dan "habis" adalah pernyataan yang dikirim ke layar
     * kasir. Bedanya nyata (CLAUDE.md & layar Stok sudah memisahkannya): belum dihitung
     * berarti angkanya belum ada, habis berarti angkanya ada dan nol.
     */
    public function test_nota_belum_datang_tidak_membuat_baris_stocks_baru_sehingga_status_tetap_belum_dihitung(): void
    {
        $mie = $this->buatProduk('Mie Instan');

        $this->assertSame(0, Stock::query()->count(), 'prasyarat: barangnya belum pernah dicatat di outlet ini');

        $this->notaBelumDatang([$this->baris($mie, 40, 3000)]);

        $this->assertSame(0, Stock::query()->count(), 'baris stocks tidak boleh lahir dari nota yang barangnya belum datang');
        $this->assertNull($this->saldo($this->outlet, $mie));

        $baris = app(SusunBarisStokAction::class)->execute($this->outlet->getKey())
            ->firstWhere('kunci', $mie->getKey());

        $this->assertFalse($baris['punya_baris']);
        $this->assertSame('belum_dihitung', $baris['status'],
            '"belum dihitung" bukan "habis": yang pertama berarti angkanya belum ada');
    }

    /* ── Uang ────────────────────────────────────────────────────────────── */

    /**
     * Harga beli master TIDAK berubah sampai barangnya datang.
     *
     * Nilai persediaan = saldo × harga_beli, dan aturannya "harga terakhir menang".
     * Memperbaruinya saat nota disimpan berarti menilai ulang barang yang SUDAH di rak dengan
     * harga barang yang BELUM dibeli — angkanya melompat tanpa satu barang pun berpindah.
     */
    public function test_nota_belum_datang_tidak_mengubah_harga_beli_master(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet', ['harga_beli' => 1500]);
        $gula = $this->buatBahan('Gula Pasir', ['harga_beli_terakhir' => 12000]);

        $this->notaBelumDatang([
            $this->baris($kopi, 100, 1800),
            $this->baris($gula, 10, 15000),
        ]);

        $this->assertEqualsWithDelta(1500.0, (float) $kopi->fresh()->harga_beli, 0.01,
            'harga beli produk tetap harga nota yang barangnya sudah benar-benar datang');
        $this->assertEqualsWithDelta(12000.0, (float) $gula->fresh()->harga_beli_terakhir, 0.01,
            'begitu juga bahan baku');
    }

    /**
     * Akibatnya diukur di layar yang benar-benar dibaca pemilik: nilai persediaan.
     *
     * Dua hal yang bisa membuat angka ini melompat, dan keduanya dijaga sekaligus di sini:
     * saldo yang bertambah (barang belum ada) dan harga beli yang berubah (barang belum
     * dibeli). Pemilik yang melihat "uang saya yang berbentuk barang" naik pada hari ia
     * mencatat PESANAN akan menyimpulkan barangnya sudah ada di rak.
     */
    public function test_nota_belum_datang_tidak_menambah_nilai_persediaan_di_layar_stok(): void
    {
        $susu = $this->buatProduk('Susu Kotak', [
            'satuan' => Satuan::Dus,
            'satuan_dasar' => Satuan::Pcs,
            'isi_per_satuan' => 12,
            'harga_beli' => 4000,
        ]);

        $this->buatStok($this->outlet, $susu, 10);

        $sebelum = Livewire::actingAs($this->owner)->test(Stok::class)->viewData('nilaiPersediaan');

        $this->assertEqualsWithDelta(40000.0, $sebelum['nilai'], 0.01, 'prasyarat: 10 pcs × 4.000');

        $this->notaBelumDatang([$this->baris($susu, 2, 58000)]);

        $sesudah = Livewire::actingAs($this->owner)->test(Stok::class)->viewData('nilaiPersediaan');

        $this->assertEqualsWithDelta(40000.0, $sesudah['nilai'], 0.01,
            'nilai persediaan tidak boleh bergerak: barangnya belum datang dan harganya belum berlaku');
    }

    /* ── Layar kasir: inti fitur ini ─────────────────────────────────────── */

    /**
     * INTI FITUR INI. Lencana kasir tetap "Habis" untuk barang yang notanya belum datang.
     *
     * Ini kerugian yang paling langsung dari cacat aslinya: kasir melihat petaknya bersih
     * (tanpa lencana), menjanjikan barangnya ke pembeli, lalu mencarinya di rak yang kosong.
     * Yang membuatnya berbahaya, lencananya tidak pernah SALAH secara teknis — ia cuma
     * membaca saldo yang sudah salah lebih dulu.
     *
     * Perhatikan bentuk ujinya: yang diperiksa adalah KABAR ke kasir, bukan adanya penolakan.
     * Kasir tetap harus bisa menjual barang ini (aturan 5 CLAUDE.md).
     */
    public function test_lencana_kasir_tetap_habis_untuk_barang_yang_notanya_belum_datang(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');
        $this->buatStok($this->outlet, $kopi, 0);

        $sebelum = app(SusunSisaStokAction::class)->execute($this->outlet->getKey());

        $this->assertSame('habis', $sebelum[$kopi->getKey()] ?? null, 'prasyarat: raknya memang kosong');

        $this->notaBelumDatang([$this->baris($kopi, 100, 1500)]);

        $sesudah = app(SusunSisaStokAction::class)->execute($this->outlet->getKey());

        $this->assertSame('habis', $sesudah[$kopi->getKey()] ?? null,
            'raknya masih kosong sampai barangnya benar-benar datang; kasir tidak boleh dikabari sebaliknya');
    }

    /**
     * Bentuk kedua dari kerugian yang sama, dan yang paling sulit dilihat: lencananya HILANG.
     *
     * 'aman' tidak dikirim sebagai nilai — absennya kunci itulah yang berarti "tidak ada yang
     * perlu dikabarkan" (lihat SusunSisaStokAction). Jadi nota belum-datang yang menaikkan
     * saldo tidak memunculkan lencana hijau yang keliru; ia MENGHAPUS lencana kuningnya, dan
     * petak yang bersih justru terbaca kasir sebagai "aman".
     */
    public function test_lencana_kasir_tidak_pernah_aman_karena_nota_belum_datang(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');
        $this->buatStok($this->outlet, $kopi, 3, ['stok_minimum' => 10]);

        $sebelum = app(SusunSisaStokAction::class)->execute($this->outlet->getKey());

        $this->assertSame('menipis', $sebelum[$kopi->getKey()] ?? null, 'prasyarat: sisa 3 dari batas minimal 10');

        // 100 pcs dipesan — cukup untuk membuat barangnya "aman" kalau saldonya ikut naik.
        $this->notaBelumDatang([$this->baris($kopi, 100, 1500)]);

        $sesudah = app(SusunSisaStokAction::class)->execute($this->outlet->getKey());

        $this->assertArrayHasKey($kopi->getKey(), $sesudah,
            'kabarnya tidak boleh hilang: petak tanpa lencana dibaca kasir sebagai aman');
        $this->assertSame('menipis', $sesudah[$kopi->getKey()],
            'yang ada di rak tetap 3; 100 pcs itu belum berpindah ke mana pun');
    }

    /* ── Formulir & penjaga status ───────────────────────────────────────── */

    /**
     * BAWAAN formulir: "barangnya sudah saya terima".
     *
     * Bawaan ini yang menjaga belanja warung yang biasa — dicatat sesudah barangnya
     * diturunkan dari motor — tidak berubah jadi nota menggantung yang stoknya tidak pernah
     * masuk. Bawaan sebaliknya menukar satu cacat dengan cacat yang lebih sering terjadi.
     */
    public function test_bawaan_formulir_nota_baru_adalah_barang_sudah_diterima(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        $komponen = Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            ->assertSet('sudahDatang', true);

        // Dan bawaannya benar-benar berlaku: tanpa menyentuh pilihan itu sama sekali,
        // notanya masuk stok seperti sebelum keadaan "belum datang" ada.
        $komponen->set('jumlah.'.$kopi->getKey(), 20)
            ->set('harga.'.$kopi->getKey(), 1500)
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(20.0, $this->saldo($this->outlet, $kopi), 0.001);
        $this->assertSame(DocumentStatus::Diterima, PurchaseOrder::query()->sole()->status);

        // Pilihannya kembali ke bawaan sesudah tersimpan: pilihan yang MENEMPEL dari nota
        // sebelumnya membuat belanja berikutnya diam-diam tidak masuk stok.
        $komponen->assertSet('sudahDatang', true);
    }

    /**
     * `Draft` TIDAK PERNAH ditulis aplikasi ini, walaupun ia default kolomnya.
     *
     * Defaultnya sengaja DIBIARKAN 'draft' di migrasi maupun di $attributes model. Justru
     * karena aplikasi tidak pernah menulisnya, nota berstatus draft menjadi penanda anomali
     * yang bisa dilihat: ia berarti ada baris yang lahir tanpa lewat CatatPembelianAction.
     * Mengubah defaultnya jadi 'dikirim' akan menghapus penanda itu — anomalinya lalu
     * menyamar sebagai nota yang sah dan ikut terhitung di kartu "Menunggu datang".
     */
    public function test_tidak_ada_nota_yang_lahir_berstatus_draft(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        $this->catatNota($this->outlet, $this->owner, ['baris' => [$this->baris($kopi, 5, 1500)]]);
        $this->notaBelumDatang([$this->baris($kopi, 5, 1500)]);

        Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            ->set('jumlah.'.$kopi->getKey(), 3)
            ->set('harga.'.$kopi->getKey(), 1500)
            ->call('simpan');

        $this->assertSame(3, PurchaseOrder::query()->count(), 'prasyarat: ketiga jalur benar-benar menulis nota');

        $this->assertSame(0, PurchaseOrder::query()->withoutGlobalScopes()->where('status', DocumentStatus::Draft->value)->count(),
            'tidak ada jalur yang boleh melahirkan nota berstatus draft');

        // Dan defaultnya tetap 'draft' — itulah yang membuat uji di atas berarti sesuatu.
        $this->assertSame('draft', (new PurchaseOrder)->getAttributes()['status'],
            "default model JANGAN diubah: 'draft' yang tidak pernah ditulis adalah penanda anomali");
        $this->assertStringContainsString("\$table->string('status')->default('draft')",
            (string) file_get_contents(database_path('migrations/2026_07_28_101600_create_purchase_orders_table.php')),
            'default kolomnya di migrasi juga jangan diubah');
    }
}

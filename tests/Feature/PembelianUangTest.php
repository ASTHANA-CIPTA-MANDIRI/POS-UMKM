<?php

namespace Tests\Feature;

use App\Enums\Satuan;
use App\Enums\UserRole;
use App\Livewire\Pages\Owner\Pembelian\PembelianBaru;
use App\Livewire\Pages\Owner\Stok\Stok;
use App\Models\Pembelian\PurchaseOrder;
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
 * Uang di nota pembelian: harga beli, total nota, dan apa yang TIDAK boleh mengubah harga.
 *
 * Aturan 4 CLAUDE.md — uang divalidasi ketat. Di layar ini kesalahannya punya sifat
 * khusus: tidak satu pun dari cacat yang dijaga berkas ini menghasilkan galat. Semuanya
 * menghasilkan ANGKA YANG TERLIHAT WAJAR di layar, dan baru terbaca sebagai salah berbulan
 * kemudian — saat pemilik memakainya untuk menentukan harga jual.
 */
class PembelianUangTest extends TestCase
{
    use MembuatDataPembelian, MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $outlet;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant('Toko Uang Belanja');
        $this->outlet = $this->buatOutlet($this->tenant, 'Cabang Uang');
        $this->owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik Uang',
            'email' => 'owner@uangbelanja.test',
            'password' => 'rahasia123',
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());
    }

    /**
     * RISIKO TERBESAR FITUR INI.
     *
     * 2 dus @58.000, isi 12 ⇒ 116.000 untuk 24 pcs ⇒ 4.833,33 per pcs.
     *
     * Kalau yang tersimpan 58.000 (harga per DUS di kolom yang artinya per PCS), tidak ada
     * satu pun galat: notanya benar, stoknya benar, totalnya benar. Yang salah cuma satu
     * angka yang dipakai di dua tempat paling menentukan — nilai persediaan di layar stok
     * (melar 12 kali lipat) dan dasar penetapan harga jual. Pemilik yang menghitung margin
     * dari situ akan menyimpulkan barangnya rugi dan menaikkan harga sampai tidak laku.
     */
    public function test_harga_beli_produk_disimpan_per_satuan_dasar_bukan_per_dus(): void
    {
        $susu = $this->buatProduk('Susu Kotak', [
            'satuan' => Satuan::Dus,
            'satuan_dasar' => Satuan::Pcs,
            'isi_per_satuan' => 12,
        ]);

        $this->catatNota($this->outlet, $this->owner, [
            'baris' => [$this->baris($susu, 2, 58000)],
        ]);

        $this->assertEqualsWithDelta(4833.33, (float) $susu->fresh()->harga_beli, 0.01,
            'harga beli disimpan per satuan DASAR: 116.000 ÷ 24 pcs');

        $this->assertNotEqualsWithDelta(58000.0, (float) $susu->fresh()->harga_beli, 0.01,
            '58.000 adalah harga per dus; menyimpannya sebagai harga per pcs membuat nilai persediaan 12 kali lipat');
    }

    /**
     * Akibat langsung dari uji di atas, diukur di layar yang benar-benar dibaca pemilik.
     *
     * Nilai persediaan sesudah belanja harus mendekati UANG YANG BENAR-BENAR DIBAYAR untuk
     * barang itu (116.000), bukan 12 kali lipatnya. Angka ini yang paling dicari pemilik
     * warung di layar stok — "berapa uang saya yang sekarang berbentuk barang".
     */
    public function test_nilai_persediaan_di_layar_stok_ikut_naik_setelah_harga_beli_diperbarui(): void
    {
        $susu = $this->buatProduk('Susu Kotak', [
            'satuan' => Satuan::Dus,
            'satuan_dasar' => Satuan::Pcs,
            'isi_per_satuan' => 12,
        ]);

        $sebelum = Livewire::actingAs($this->owner)->test(Stok::class)->viewData('nilaiPersediaan');

        $this->assertEqualsWithDelta(0.0, $sebelum['nilai'], 0.01, 'prasyarat: belum ada barang bernilai');

        $this->catatNota($this->outlet, $this->owner, [
            'baris' => [$this->baris($susu, 2, 58000)],
        ]);

        $sesudah = Livewire::actingAs($this->owner)->test(Stok::class)->viewData('nilaiPersediaan');

        // 24 pcs × 4.833,33 = 115.999,92 — dua sen dari uang yang benar-benar dibayar,
        // selisih pembulatan harga per satuan dasar ke dua desimal.
        $this->assertEqualsWithDelta(116000.0, $sesudah['nilai'], 1.0);
        $this->assertSame(0, $sesudah['tanpa_harga'],
            'barang yang baru dibeli tidak boleh terhitung sebagai "harganya belum diisi"');
    }

    /** total = subtotal − diskon + ongkir. Keduanya TIDAK dibagi ke harga barang. */
    public function test_total_nota_sama_dengan_subtotal_dikurangi_diskon_ditambah_ongkir(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');
        $gula = $this->buatProduk('Gula Pasir', ['satuan' => Satuan::Kg]);

        $nota = $this->catatNota($this->outlet, $this->owner, [
            'diskon' => 5000,
            'ongkos_kirim' => 20000,
            'baris' => [
                $this->baris($kopi, 100, 1500),   // 150.000
                $this->baris($gula, 10, 14000),   //  140.000
            ],
        ]);

        // 290.000 − 5.000 + 20.000
        $this->assertEqualsWithDelta(305000.0, (float) $nota->fresh()->total, 0.01);
        $this->assertEqualsWithDelta(5000.0, (float) $nota->fresh()->diskon, 0.01);
        $this->assertEqualsWithDelta(20000.0, (float) $nota->fresh()->ongkos_kirim, 0.01);
    }

    /**
     * Ongkir bukan nilai barang.
     *
     * Kalau ongkir dibagi rata ke harga barang, nilai persediaan naik karena biaya angkut —
     * dan barang yang sama menjadi "lebih mahal" hanya karena hari itu diantar. Harga jual
     * yang dihitung dari situ ikut naik tanpa satu pun sebab yang bisa dijelaskan ke pembeli.
     */
    public function test_ongkir_tidak_mengubah_harga_beli_barang(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        $this->catatNota($this->outlet, $this->owner, [
            'ongkos_kirim' => 50000,
            'diskon' => 10000,
            'baris' => [$this->baris($kopi, 100, 1500)],
        ]);

        $this->assertEqualsWithDelta(1500.0, (float) $kopi->fresh()->harga_beli, 0.01,
            'harga beli barang tetap 1.500 walau ada ongkir 50.000 dan diskon 10.000 di nota yang sama');
    }

    /**
     * Baris berharga NOL adalah bonus grosir ("beli 10 dapat 1"), bukan pernyataan bahwa
     * barangnya tidak bernilai.
     *
     * Menimpakan nol menghapus harga yang benar, dan barangnya langsung berhenti terhitung
     * di nilai persediaan — pemilik melihat uangnya "hilang" dari layar tanpa ada barang
     * yang keluar dari rak.
     */
    public function test_baris_berharga_nol_tidak_menghapus_harga_beli_lama(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet', ['harga_beli' => 1500]);

        $this->catatNota($this->outlet, $this->owner, [
            'baris' => [$this->baris($kopi, 1, 0)],
        ]);

        $this->assertEqualsWithDelta(1500.0, (float) $kopi->fresh()->harga_beli, 0.01,
            'harga lama harus bertahan');

        // Barangnya tetap masuk stok: hadiah tetap barang yang ada di rak.
        $this->assertEqualsWithDelta(1.0, $this->saldo($this->outlet, $kopi), 0.001);
        $this->assertSame(1, StockMovement::query()->count());
    }

    /**
     * Angka minus di nota belanja selalu salah ketik.
     *
     * Jumlah minus akan menjadi mutasi stok bertanda terbalik (barang KELUAR lewat nota
     * masuk), dan harga minus membuat total nota berkurang — uang masuk menurut catatan,
     * padahal pemiliknya baru saja membayar.
     */
    public function test_harga_negatif_dan_jumlah_negatif_ditolak_validasi(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            ->set('jumlah.'.$kopi->getKey(), -2)
            ->set('harga.'.$kopi->getKey(), 1500)
            ->call('simpan')
            ->assertHasErrors('jumlah.'.$kopi->getKey());

        Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            ->set('jumlah.'.$kopi->getKey(), 2)
            ->set('harga.'.$kopi->getKey(), -1500)
            ->call('simpan')
            ->assertHasErrors('harga.'.$kopi->getKey());

        // Tidak satu pun nota tersimpan, dan tidak satu pun mutasi stok terjadi.
        $this->assertSame(0, PurchaseOrder::query()->count());
        $this->assertSame(0, StockMovement::query()->count());
    }

    /**
     * Harga yang DIKOSONGKAN ditolak, dan itu berbeda dari harga nol.
     *
     * Nol adalah pernyataan "bonus"; kosong berarti belum diisi. Membiarkan kosong lolos
     * sebagai nol akan menghapus harga beli barangnya di master lewat pintu belakang —
     * cacat yang sama dengan baris bonus, tapi tanpa ada yang bermaksud demikian.
     */
    public function test_harga_yang_dikosongkan_ditolak(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet', ['harga_beli' => 1500]);

        Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            ->set('jumlah.'.$kopi->getKey(), 3)
            ->call('simpan')
            ->assertHasErrors('harga.'.$kopi->getKey());

        $this->assertSame(0, PurchaseOrder::query()->count());
        $this->assertEqualsWithDelta(1500.0, (float) $kopi->fresh()->harga_beli, 0.01);
    }

    /**
     * Pecahan hanya untuk satuan yang memang bisa dipecah.
     *
     * 2,5 kg beras masuk akal. 2,5 dus tidak — dan angka itu akan menjadi 30 pcs di kartu
     * stok tanpa ada setengah dus yang pernah dibawa pulang dari grosir. Aturannya memakai
     * Satuan::allowsFraction(), yang sama dengan yang dipakai di tempat lain.
     */
    public function test_jumlah_pecahan_diterima_untuk_kg_dan_ditolak_untuk_pcs(): void
    {
        $gula = $this->buatProduk('Gula Pasir', ['satuan' => Satuan::Kg]);
        $kopi = $this->buatProduk('Kopi Sachet', ['satuan' => Satuan::Pcs]);

        Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            ->set('jumlah.'.$gula->getKey(), 2.5)
            ->set('harga.'.$gula->getKey(), 14000)
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertEqualsWithDelta(2.5, $this->saldo($this->outlet, $gula), 0.001);

        Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            ->set('jumlah.'.$kopi->getKey(), 2.5)
            ->set('harga.'.$kopi->getKey(), 1500)
            ->call('simpan')
            ->assertHasErrors('jumlah.'.$kopi->getKey());

        $this->assertNull($this->saldo($this->outlet, $kopi),
            'baris pecahan bersatuan pcs tidak boleh menghasilkan stok sama sekali');
    }

    /**
     * Diskon yang lebih besar daripada belanjaannya selalu salah ketik — angka harga yang
     * masuk ke kolom diskon. Hasilnya total nota NEGATIF: menurut catatan, belanja ini
     * memasukkan uang.
     */
    public function test_diskon_melebihi_belanja_ditolak(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        Livewire::actingAs($this->owner)
            ->test(PembelianBaru::class)
            ->set('jumlah.'.$kopi->getKey(), 10)
            ->set('harga.'.$kopi->getKey(), 1500)
            ->set('diskon', 54000)
            ->call('simpan')
            ->assertHasErrors('diskon');

        $this->assertSame(0, PurchaseOrder::query()->count());
    }

    /** Harga beli barang ditimpa oleh nota TERAKHIR, bukan dirata-ratakan. */
    public function test_harga_beli_dari_nota_terakhir_yang_menang(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet', ['harga_beli' => 1500]);

        $this->catatNota($this->outlet, $this->owner, ['baris' => [$this->baris($kopi, 10, 1600)]]);
        $this->assertEqualsWithDelta(1600.0, (float) $kopi->fresh()->harga_beli, 0.01);

        $this->catatNota($this->outlet, $this->owner, ['baris' => [$this->baris($kopi, 10, 1800)]]);

        $this->assertEqualsWithDelta(1800.0, (float) $kopi->fresh()->harga_beli, 0.01,
            'rata-rata bergerak ditolak: ia butuh saldo sebagai pembagi, dan saldo boleh minus');
    }
}

<?php

namespace Tests\Feature;

use App\Actions\Purchase\BatalkanPembelianAction;
use App\Actions\Purchase\TerimaPembelianAction;
use App\Enums\Satuan;
use App\Enums\UserRole;
use App\Livewire\Pages\Owner\Bahan as LayarBahan;
use App\Models\Outlet;
use App\Models\RawMaterial;
use App\Models\Stock;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\Concerns\MembuatDataPembelian;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * QA — gerbang "satuan terkunci" milik layar Bahan (App\Livewire\Pages\Owner\Bahan).
 *
 * CACAT YANG PERNAH ADA DI SINI, dan berkas ini lahir untuk menutupnya:
 * `aturanSatuanTerkunci()` dulu hanya mengunci satuan kalau bahannya SUDAH punya
 * `stocks()->exists()` ATAU `recipeItems()->exists()`. Yang TIDAK diperiksa: nota belanja
 * yang sudah dicatat tapi BELUM ditandai datang.
 *
 * Kenapa gerbang lama buta terhadapnya — dan ini bukan kelalaian, melainkan akibat dari
 * keputusan yang benar di tempat lain: nota belum-datang SENGAJA tidak membuat baris
 * `stocks` (lihat PembelianBelumDatangTest; TerimaPembelianAction adalah satu-satunya jalur
 * pembelian menaikkan stok). Jadi bahan yang baru dibelanjakan 5 kg, notanya belum "sudah
 * datang", dan belum pernah dipakai resep, tidak punya SATU PUN jejak yang dilihat gerbang
 * lama.
 *
 * Akibatnya kalau satuannya lalu diganti ke gram: baris nota
 * (`purchase_order_items.qty`) untuk bahan baku TIDAK dikonversi sama sekali
 * (CatatPembelianAction: "Bahan baku belum punya kolom konversi, jadi angkanya memang sudah
 * dalam satuan pencatatannya sendiri") — jadi begitu notanya ditandai datang, angka "5" yang
 * dimaksud sebagai 5 KG masuk ke kartu stok dan langsung dibaca dengan satuan SEKARANG
 * bahannya: gram. Pemilik membaca "5 gram gula masuk", padahal notanya 5 KILOGRAM —
 * kesalahan 1000×, persis jenis cacat yang aturanSatuanTerkunci() memang dibuat untuk
 * mencegah, hanya lewat pintu yang belum dijaganya.
 *
 * Syarat ketiga sekarang ada di aturanSatuanTerkunci(). Penandanya STATUS NOTA (Draft /
 * Dikirim), bukan `qty_diterima` — alasannya ditulis di notaBelumDatang() dan dijaga oleh
 * uji nota dibatalkan di bawah.
 */
class QaStokBahanTest extends TestCase
{
    use MembuatDataPembelian, MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $outlet;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant('Warteg QA Stok Bahan');
        $this->outlet = $this->buatOutlet($this->tenant, 'Outlet Utama');
        $this->owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik QA',
            'email' => 'owner@qastokbahan.test',
            'password' => 'rahasia123',
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());
    }

    /**
     * CACAT (sudah ditutup): satuan bahan bisa diubah SESUDAH ada nota belanja untuknya
     * (5 kg gula), SELAMA nota itu belum ditandai datang — lalu begitu notanya diterima,
     * 5 (kg yang dimaksud pemilik) masuk kartu stok dan dibaca 5 GRAM karena satuan masternya
     * sudah berubah. Tidak ada satu galat pun di sepanjang jalan.
     *
     * Uji ini dulu merekam cacatnya (`assertHasNoErrors('satuan')`); sekarang ia menuntut
     * penolakannya. Kalau suatu hari syarat ketiga di aturanSatuanTerkunci() dianggap
     * berlebihan dan dibuang, uji ini yang merah — jangan "merapikannya" dengan mengembalikan
     * assertHasNoErrors.
     */
    public function test_satuan_bahan_ditolak_saat_ada_nota_belanja_yang_barangnya_belum_datang(): void
    {
        $gula = $this->buatBahan('Gula Pasir', ['satuan' => Satuan::Kg]);

        // Nota dicatat: 5 kg gula, TAPI belum ditandai datang — sengaja, meniru cara pemilik
        // biasa mencatat belanja sebelum kurirnya tiba.
        $nota = $this->catatNotaBelumDatang($this->outlet, $this->owner, [
            'baris' => [$this->baris($gula, qtyBeli: 5, harga: 15000)],
        ]);

        // Pramis yang membuat cacatnya mungkin: belum ada baris `stocks` sama sekali untuk
        // Gula Pasir, dan belum dipakai resep — dua syarat lama gerbangnya sama-sama diam.
        $this->assertNull(
            Stock::withoutGlobalScopes()->where('raw_material_id', $gula->getKey())->first(),
            'nota belum datang tidak boleh membuat baris stok — pramis uji ini'
        );
        $this->assertFalse($gula->recipeItems()->exists());

        $galat = Livewire::actingAs($this->owner)->test(LayarBahan::class)
            ->call('ubah', $gula->getKey())
            ->set('satuan', 'gram')
            ->call('simpan')
            ->assertHasErrors('satuan')
            ->errors()->first('satuan');

        // Pesannya menyebut NOMOR NOTANYA. Tanpa nomor, pemilik tahu ia ditahan tapi tidak
        // tahu oleh nota yang mana — dan nota belum-datang justru yang paling mudah lupa
        // pernah dicatat.
        $this->assertSame(
            'Satuan tidak bisa diubah karena Gula Pasir ada di nota belanja yang barangnya '
            .'belum datang ('.$nota->nomor_po.'). Buat bahan baru kalau satuannya mau beda.',
            $galat,
        );

        $this->assertSame('kg', $gula->fresh()->satuan->value, 'satuan tidak boleh ikut tersimpan');

        // Dan angkanya sekarang selamat sampai ujung: notanya ditandai datang, 5 masuk kartu
        // stok, dan satuan yang dipakai membacanya masih kg — sama dengan satuan saat nota
        // itu dicatat.
        app(TerimaPembelianAction::class)->execute($nota->fresh(), $this->owner);

        $baris = Stock::withoutGlobalScopes()
            ->where('raw_material_id', $gula->getKey())
            ->sole();

        $this->assertEqualsWithDelta(5.0, (float) $baris->jumlah_saat_ini, 0.001);
        $this->assertSame('kg', $gula->fresh()->satuan->value,
            'kartu stok harus membaca "5 kg" — angka nota dan satuan masternya satu bahasa');
    }

    /**
     * Arah sebaliknya #1: nota yang SUDAH ditandai datang tetap menahan satuan — dan yang
     * menahannya syarat LAMA (baris `stocks`), bukan syarat baru.
     *
     * Perlu dijaga karena syarat baru sengaja melepas nota berstatus Diterima. Kalau baris
     * `stocks`-nya suatu hari tidak lagi dibuat saat penerimaan, bahan yang barangnya sudah
     * benar-benar di rak akan lolos ganti satuan — dan itu justru keadaan yang paling mahal.
     * Uji ini menyatakan pesannya secara utuh supaya pergeseran itu terbaca sebagai merah.
     */
    public function test_bahan_yang_notanya_sudah_ditandai_datang_tetap_tertahan_lewat_catatan_stok(): void
    {
        $terigu = $this->buatBahan('Terigu', ['satuan' => Satuan::Kg]);

        $nota = $this->catatNotaBelumDatang($this->outlet, $this->owner, [
            'baris' => [$this->baris($terigu, qtyBeli: 10, harga: 12000)],
        ]);

        $this->assertTrue(app(TerimaPembelianAction::class)->execute($nota->fresh(), $this->owner));

        // Pramisnya dibuktikan, tidak dikira: penerimaan memang meninggalkan baris `stocks`.
        $this->assertNotNull(
            Stock::withoutGlobalScopes()->where('raw_material_id', $terigu->getKey())->first(),
            'nota yang diterima harus meninggalkan baris stok — pramis uji ini'
        );

        $galat = Livewire::actingAs($this->owner)->test(LayarBahan::class)
            ->call('ubah', $terigu->getKey())
            ->set('satuan', 'gram')
            ->call('simpan')
            ->assertHasErrors('satuan')
            ->errors()->first('satuan');

        $this->assertSame(
            'Satuan tidak bisa diubah karena Terigu sudah punya catatan stok. '
            .'Buat bahan baru kalau satuannya mau beda.',
            $galat,
        );
        $this->assertSame('kg', $terigu->fresh()->satuan->value);
    }

    /**
     * Arah sebaliknya #2: nota yang DIBATALKAN tidak ikut menahan — gerbangnya tidak boleh
     * jadi terlalu galak.
     *
     * Ini yang menentukan penandanya status nota, bukan `qty_diterima`. Nota belum-datang
     * yang dibatalkan meninggalkan barisnya dengan `qty_diterima` tetap 0 SELAMANYA
     * (BatalkanPembelianAction tidak pernah menyentuh kolom itu, dan sengaja: barangnya
     * memang tidak pernah datang). Gerbang yang membaca "qty_diterima = 0" karena itu akan
     * mengunci satuan bahan ini untuk selama-lamanya — tidak ada satu pun layar yang bisa
     * menaikkan angka itu lagi, jadi pemiliknya harus mengarang bahan baru gara-gara nota
     * yang sudah dinyatakan tidak pernah terjadi.
     *
     * Nota batal juga tidak berbahaya: TerimaPembelianAction menolak menerimanya, jadi tidak
     * akan pernah ada angka mentahnya yang masuk kartu stok dengan satuan baru.
     */
    public function test_bahan_yang_notanya_dibatalkan_tidak_ikut_tertahan(): void
    {
        $kecap = $this->buatBahan('Kecap Manis', ['satuan' => Satuan::Liter]);

        $nota = $this->catatNotaBelumDatang($this->outlet, $this->owner, [
            'baris' => [$this->baris($kecap, qtyBeli: 2, harga: 25000)],
        ]);

        $this->assertTrue(app(BatalkanPembelianAction::class)->execute($nota->fresh(), $this->owner));

        // Pramis: pembatalan nota belum-datang tidak meninggalkan jejak lain sama sekali —
        // tidak ada baris `stocks`, dan `qty_diterima` barisnya masih 0.
        $this->assertNull(
            Stock::withoutGlobalScopes()->where('raw_material_id', $kecap->getKey())->first(),
            'nota belum-datang yang dibatalkan tidak boleh membuat baris stok — pramis uji ini'
        );
        $this->assertEqualsWithDelta(
            0.0,
            (float) $nota->fresh()->items()->sole()->qty_diterima,
            0.001,
            'qty_diterima tetap 0 sesudah pembatalan — inilah kenapa ia bukan penanda yang benar'
        );

        Livewire::actingAs($this->owner)->test(LayarBahan::class)
            ->call('ubah', $kecap->getKey())
            ->set('satuan', 'ml')
            ->call('simpan')
            ->assertHasNoErrors('satuan');

        $this->assertSame('ml', $kecap->fresh()->satuan->value);
    }

    /**
     * Satuan terbuka lagi begitu SEMUA notanya selesai, dan pesannya menyebut semua nomor
     * selama masih ada yang menggantung.
     *
     * Pesan yang cuma menyebut satu nomor membuat pemilik menerima nota itu, mencoba lagi,
     * dan tertahan lagi oleh nota berikutnya yang tidak pernah disebut — tanpa cara tahu
     * berapa sisanya.
     */
    public function test_pesannya_menyebut_semua_nota_yang_menggantung(): void
    {
        $bawang = $this->buatBahan('Bawang Merah', ['satuan' => Satuan::Kg]);

        $pertama = $this->catatNotaBelumDatang($this->outlet, $this->owner, [
            'baris' => [$this->baris($bawang, qtyBeli: 3, harga: 30000)],
        ]);
        $kedua = $this->catatNotaBelumDatang($this->outlet, $this->owner, [
            'baris' => [$this->baris($bawang, qtyBeli: 1, harga: 31000)],
        ]);

        $galat = Livewire::actingAs($this->owner)->test(LayarBahan::class)
            ->call('ubah', $bawang->getKey())
            ->set('satuan', 'gram')
            ->call('simpan')
            ->assertHasErrors('satuan')
            ->errors()->first('satuan');

        $this->assertStringContainsString($pertama->nomor_po, $galat);
        $this->assertStringContainsString($kedua->nomor_po, $galat);
    }

    /**
     * Bahan yang tidak punya jejak apa pun tetap boleh berganti satuan — pramis yang membuat
     * ketiga syarat gerbangnya berarti.
     *
     * Tanpa uji ini, "perbaikan" yang mengunci satuan untuk semua bahan lolos diam-diam, dan
     * pemilik yang salah pilih satuan sedetik sebelumnya harus mengarang nama bahan baru
     * untuk memperbaiki salah ketik.
     */
    public function test_bahan_tanpa_jejak_apa_pun_masih_boleh_berganti_satuan(): void
    {
        $cabai = $this->buatBahan('Cabai Rawit', ['satuan' => Satuan::Kg]);

        Livewire::actingAs($this->owner)->test(LayarBahan::class)
            ->call('ubah', $cabai->getKey())
            ->set('satuan', 'gram')
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertSame('gram', $cabai->fresh()->satuan->value);
    }

    /* ── $bahanId: penentu tujuan penyimpanan ───────────────────────────── */

    /**
     * `$bahanId` tidak boleh bisa diisi dari muatan klien, dan tenant lain tidak boleh bisa
     * disentuh lewat `ubah()` maupun `simpan()`.
     *
     * QA sebelumnya menilai risikonya rendah karena tiap jalur memanggil `findOrFail()` yang
     * tunduk TenantScope — benar, tapi ia hanya membuktikannya untuk `hapus()`
     * (OwnerBahanTest::test_bahan_tenant_lain_tidak_terlihat_dan_tidak_bisa_dihapus). Uji ini
     * membuktikan dua jalur sisanya, dan sekaligus lapis pertamanya (#[Locked]): kalau id-nya
     * tidak pernah bisa diganti dari klien, TenantScope tidak perlu jadi satu-satunya yang
     * berdiri di sana. Itu penting karena TenantScope BERHENTI MEMFILTER saat TenantContext
     * kosong (lihat TenantContext::shouldScope()).
     */
    public function test_bahan_id_terkunci_dari_klien_dan_ubah_menolak_bahan_tenant_lain(): void
    {
        $tetangga = $this->buatTenant('Warteg Sebelah');
        $bahanTetangga = $this->konteks()->forTenant(
            $tetangga->getKey(),
            fn () => RawMaterial::create(['nama' => 'Santan Tetangga', 'satuan' => Satuan::Liter]),
        );

        $layar = Livewire::actingAs($this->owner)->test(LayarBahan::class);

        // Lapis 1: muatan klien tidak bisa menyetel penentu tujuan penyimpanan sama sekali.
        try {
            $layar->set('bahanId', $bahanTetangga->getKey());
            $this->fail('$bahanId harus #[Locked] — klien tidak boleh menentukan baris mana yang ditimpa');
        } catch (CannotUpdateLockedPropertyException) {
            // sesuai harapan
        }

        // Lapis 2: satu-satunya pintu yang mengisinya pun menolak id tenant lain, jadi
        // `simpan()` tidak pernah punya sasaran lintas tenant untuk ditulis.
        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($this->owner)->test(LayarBahan::class)
            ->call('ubah', $bahanTetangga->getKey());
    }
}

<?php

namespace Tests\Feature;

use App\Actions\Purchase\BatalkanPembelianAction;
use App\Enums\DocumentStatus;
use App\Enums\Satuan;
use App\Enums\StockMovementType;
use App\Enums\UserRole;
use App\Livewire\Pages\Owner\Pembelian;
use App\Models\Outlet;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MembuatDataPembelian;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Membatalkan nota belanja: stok kembali, harga beli pulih, notanya TETAP ADA.
 *
 * Nota salah ketik pasti terjadi — 100 ditulis 1000, barang tertukar, nota grosir yang
 * ternyata sudah dicatat kemarin. Tanpa jalan membatalkannya, satu-satunya cara
 * memperbaiki angka stok adalah lewat hitung fisik, dan selisihnya akan tercatat sebagai
 * "barang hilang" — persis keterangan yang salah untuk barang yang tidak pernah ada.
 */
class PembelianBatalTest extends TestCase
{
    use MembuatDataPembelian, MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $outlet;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant('Toko Batal');
        $this->outlet = $this->buatOutlet($this->tenant, 'Cabang Batal');
        $this->owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik Batal',
            'email' => 'owner@batal.test',
            'password' => 'rahasia123',
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());
    }

    /**
     * Persis, termasuk konversi satuannya.
     *
     * Yang masuk 24 pcs (2 dus isi 12), jadi yang dikembalikan juga harus 24 pcs — bukan 2.
     * Pembatalan yang mengembalikan angka SATUAN BELI meninggalkan 22 pcs hantu di rak
     * menurut catatan, dan tidak ada satu pun galat yang menyebutkannya.
     */
    public function test_membatalkan_nota_mengembalikan_stok_persis_ke_angka_sebelumnya(): void
    {
        $susu = $this->buatProduk('Susu Kotak', [
            'satuan' => Satuan::Dus,
            'satuan_dasar' => Satuan::Pcs,
            'isi_per_satuan' => 12,
        ]);

        $this->buatStok($this->outlet, $susu, 5);

        $nota = $this->catatNota($this->outlet, $this->owner, [
            'baris' => [$this->baris($susu, 2, 58000)],
        ]);

        $this->assertEqualsWithDelta(29.0, $this->saldo($this->outlet, $susu), 0.001);

        app(BatalkanPembelianAction::class)->execute($nota, $this->owner);

        $this->assertEqualsWithDelta(5.0, $this->saldo($this->outlet, $susu), 0.001);

        // Dua baris kartu stok: masuk lalu keluar, keduanya menunjuk nota yang SAMA.
        // Itulah satu-satunya cara membaca "barang ini pernah masuk lewat nota itu, lalu
        // dikembalikan karena notanya dibatalkan".
        $mutasi = StockMovement::query()->orderBy('created_at')->orderBy('id')->get();

        $this->assertCount(2, $mutasi);
        $this->assertSame(StockMovementType::Masuk, $mutasi[0]->tipe);
        $this->assertSame(StockMovementType::Keluar, $mutasi[1]->tipe);
        $this->assertEqualsWithDelta(-24.0, (float) $mutasi[1]->jumlah, 0.001);
        $this->assertEqualsWithDelta(5.0, (float) $mutasi[1]->saldo_sesudah, 0.001);
        $this->assertSame($nota->getKey(), $mutasi[1]->referensi_id);
    }

    /**
     * IDEMPOTENSI — dan cacat yang dijaga di sini tidak menghasilkan satu pun galat.
     *
     * Stok boleh minus di POS ini (aturan 5 CLAUDE.md), jadi pengurangan kedua tidak
     * ditolak oleh apa pun: tidak ada galat, tidak ada penolakan, hanya saldo yang salah
     * dan satu baris kartu stok tambahan yang terbaca sah. Pemicunya sehari-hari: tombol
     * yang ditekan dua kali karena jaringan lambat.
     */
    public function test_membatalkan_nota_dua_kali_tidak_mengurangi_stok_dua_kali(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');
        $this->buatStok($this->outlet, $kopi, 10);

        $nota = $this->catatNota($this->outlet, $this->owner, [
            'baris' => [$this->baris($kopi, 20, 1500)],
        ]);

        $this->assertTrue(app(BatalkanPembelianAction::class)->execute($nota, $this->owner),
            'pembatalan pertama memang terjadi');

        $this->assertFalse(app(BatalkanPembelianAction::class)->execute($nota->fresh(), $this->owner),
            'pembatalan kedua harus berhenti, dan mengatakannya lewat nilai kembalian');

        $this->assertEqualsWithDelta(10.0, $this->saldo($this->outlet, $kopi), 0.001,
            'saldo harus kembali ke 10, bukan turun ke −10');

        $this->assertSame(2, StockMovement::query()->count(),
            'hanya dua mutasi: satu masuk, satu keluar');
    }

    /** Jalur yang sebenarnya dipakai: tombol di daftar, ditekan dua kali. */
    public function test_menekan_tombol_batalkan_dua_kali_di_daftar_tidak_mengurangi_stok_dua_kali(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');
        $this->buatStok($this->outlet, $kopi, 10);

        $nota = $this->catatNota($this->outlet, $this->owner, [
            'baris' => [$this->baris($kopi, 20, 1500)],
        ]);

        Livewire::actingAs($this->owner)
            ->test(Pembelian::class)
            ->call('batalkan', $nota->getKey())
            ->call('batalkan', $nota->getKey());

        $this->assertEqualsWithDelta(10.0, $this->saldo($this->outlet, $kopi), 0.001);
        $this->assertSame(2, StockMovement::query()->count());
    }

    /**
     * Harga beli dipulihkan ke nota Diterima TERAKHIR YANG TERSISA.
     *
     * Aturannya "harga terakhir menang", jadi membatalkan nota terakhir harus mengembalikan
     * harga ke nota sebelum itu. Membiarkan 1.800 berarti harga barang ditentukan oleh nota
     * yang sudah dinyatakan tidak pernah terjadi.
     */
    public function test_membatalkan_nota_mengembalikan_harga_beli_ke_nota_diterima_sebelumnya(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet', ['harga_beli' => 1500]);

        $this->catatNota($this->outlet, $this->owner, ['baris' => [$this->baris($kopi, 10, 1600)]]);
        $kedua = $this->catatNota($this->outlet, $this->owner, ['baris' => [$this->baris($kopi, 10, 1800)]]);

        $this->assertEqualsWithDelta(1800.0, (float) $kopi->fresh()->harga_beli, 0.01);

        app(BatalkanPembelianAction::class)->execute($kedua, $this->owner);

        $this->assertEqualsWithDelta(1600.0, (float) $kopi->fresh()->harga_beli, 0.01);
    }

    /**
     * Tidak ada nota lain yang tersisa ⇒ harga DIBIARKAN.
     *
     * Menolkannya berarti menyatakan barangnya tidak bernilai, dan nilai persediaannya
     * langsung hilang dari layar stok — pemilik melihat uangnya menguap tanpa ada barang
     * yang keluar dari rak.
     */
    public function test_membatalkan_satu_satunya_nota_membiarkan_harga_beli_apa_adanya(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet', ['harga_beli' => 1500]);

        $nota = $this->catatNota($this->outlet, $this->owner, ['baris' => [$this->baris($kopi, 10, 1600)]]);

        app(BatalkanPembelianAction::class)->execute($nota, $this->owner);

        $this->assertEqualsWithDelta(1600.0, (float) $kopi->fresh()->harga_beli, 0.01,
            'harga dibiarkan; menolkannya menghapus nilai persediaan barang yang masih ada di rak');
    }

    /**
     * Nota yang dibatalkan TIDAK dihapus.
     *
     * Kartu stok memuat mutasi masuk dan keluar yang menunjuk nota ini. Kalau notanya
     * lenyap, kedua baris itu menunjuk dokumen yang tidak bisa dibuka siapa pun, dan
     * sebulan kemudian tidak ada yang bisa menjawab kenapa angkanya sempat naik lalu turun.
     */
    public function test_nota_dibatalkan_tidak_dihapus_dan_masih_terbaca_di_daftar(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        $nota = $this->catatNota($this->outlet, $this->owner, ['baris' => [$this->baris($kopi, 20, 1500)]]);

        app(BatalkanPembelianAction::class)->execute($nota, $this->owner);

        $tersimpan = PurchaseOrder::query()->find($nota->getKey());

        $this->assertNotNull($tersimpan, 'notanya tidak boleh terhapus');
        $this->assertNull($tersimpan->deleted_at, 'juga tidak boleh dihapus lunak');
        $this->assertSame(DocumentStatus::Dibatalkan, $tersimpan->status);
        $this->assertSame(1, $tersimpan->items()->count(), 'barisnya ikut bertahan supaya isinya tetap terbaca');

        $daftar = Livewire::actingAs($this->owner)->test(Pembelian::class)->viewData('daftar');

        $this->assertTrue(collect($daftar->items())->contains(fn (PurchaseOrder $n) => $n->getKey() === $nota->getKey()),
            'nota yang dibatalkan tetap muncul di daftar');
    }

    /* ── Nota yang barangnya BELUM DATANG ────────────────────────────────── */

    /**
     * Membatalkan nota belum-datang tidak boleh mengurangi stok.
     *
     * Notanya belum pernah menambah stok, jadi mengeluarkan barangnya berarti mengarang
     * pengurangan yang tidak berpasangan dengan apa pun — dan pengurangan itu terbaca SAH di
     * riwayat barang, lengkap dengan keterangan "Pembatalan nota …". Sebulan kemudian tidak
     * ada yang bisa menjawab kenapa saldonya turun 24.
     */
    public function test_membatalkan_nota_belum_datang_tidak_mengurangi_stok(): void
    {
        $susu = $this->buatProduk('Susu Kotak', [
            'satuan' => Satuan::Dus,
            'satuan_dasar' => Satuan::Pcs,
            'isi_per_satuan' => 12,
        ]);

        $this->buatStok($this->outlet, $susu, 5);

        $nota = $this->catatNotaBelumDatang($this->outlet, $this->owner, [
            'baris' => [$this->baris($susu, 2, 58000)],
        ]);

        $this->assertTrue(app(BatalkanPembelianAction::class)->execute($nota, $this->owner));

        $this->assertEqualsWithDelta(5.0, $this->saldo($this->outlet, $susu), 0.001,
            'saldo tidak boleh turun ke −19: 24 pcs itu tidak pernah masuk');
        $this->assertSame(0, StockMovement::query()->count(),
            'tidak ada mutasi masuk, jadi tidak boleh ada mutasi keluar');
        $this->assertSame(DocumentStatus::Dibatalkan, $nota->fresh()->status);
    }

    /**
     * CACAT NYATA yang dijaga di sini: `pulihkanHargaBeli()` dipanggil tanpa penjagaan
     * `$pernahMasuk`.
     *
     * Nota yang barangnya belum datang belum pernah menimpa harga beli apa pun, jadi
     * "memulihkan" darinya bukan memulihkan — ia MENGUBAH `harga_beli` master ke harga nota
     * sebelumnya, dan seluruh saldo yang ada di rak ikut dinilai ulang. Tidak ada satu barang
     * pun yang bergerak, tidak ada satu galat pun, hanya nilai persediaan yang melompat di
     * layar yang paling dipercaya pemiliknya.
     *
     * Jangan "merapikan" penjagaannya ke dalam pulihkanHargaBeli() sebagai baris tunggal:
     * pemanggilan bersyarat itulah yang diuji di sini.
     */
    public function test_membatalkan_nota_belum_datang_tidak_mengubah_harga_beli_master(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet', ['harga_beli' => 1500]);

        // Nota lama yang barangnya SUDAH datang: harga master jadi 1.600.
        $this->catatNota($this->outlet, $this->owner, ['baris' => [$this->baris($kopi, 10, 1600)]]);

        // Lalu pemilik menaikkan harga belinya sendiri — mis. dari nota kertas grosir.
        $kopi->harga_beli = 1900;
        $kopi->save();

        // Nota baru yang barangnya BELUM datang: harganya belum berlaku sama sekali.
        $belum = $this->catatNotaBelumDatang($this->outlet, $this->owner, [
            'baris' => [$this->baris($kopi, 10, 2500)],
        ]);

        $this->assertEqualsWithDelta(1900.0, (float) $kopi->fresh()->harga_beli, 0.01,
            'prasyarat: nota belum-datang memang tidak menyentuh harga master');

        app(BatalkanPembelianAction::class)->execute($belum, $this->owner);

        $this->assertEqualsWithDelta(1900.0, (float) $kopi->fresh()->harga_beli, 0.01,
            'harga master TIDAK boleh dikembalikan ke 1.600: nota yang dibatalkan itu belum pernah mengubahnya');
    }

    /**
     * Pesannya harus jujur — ini juga cacat nyata.
     *
     * "Stok dikembalikan seperti sebelum nota ini dicatat" untuk nota yang barangnya belum
     * pernah datang membuat pemilik mencari 24 pcs yang tidak pernah ada di catatannya. Dan
     * pesan yang salah satu kali membuat seluruh pesan berikutnya berhenti dipercaya, termasuk
     * yang benar.
     */
    public function test_pesan_pembatalan_nota_belum_datang_tidak_mengaku_mengembalikan_stok(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');
        $this->buatStok($this->outlet, $kopi, 10);

        $sudah = $this->catatNota($this->outlet, $this->owner, ['baris' => [$this->baris($kopi, 5, 1500)]]);
        $belum = $this->catatNotaBelumDatang($this->outlet, $this->owner, ['baris' => [$this->baris($kopi, 5, 1500)]]);

        $komponen = Livewire::actingAs($this->owner)->test(Pembelian::class);

        $komponen->call('batalkan', $belum->getKey())
            ->assertDispatched('toast', function (string $nama, array $data): bool {
                return str_contains($data['pesan'], 'belum datang')
                    && ! str_contains($data['pesan'], 'Stok dikembalikan');
            });

        // Nota yang barangnya memang pernah masuk tetap memakai kalimat aslinya — kalau
        // keduanya disamakan, keterangan yang benar-benar penting ikut hilang.
        $komponen->call('batalkan', $sudah->getKey())
            ->assertDispatched('toast', fn (string $nama, array $data): bool => str_contains($data['pesan'], 'Stok dikembalikan'));
    }

    /** Saringan status memisahkan yang dibatalkan, tanpa menyembunyikannya dari "semua". */
    public function test_saringan_status_memisahkan_nota_dibatalkan(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        $hidup = $this->catatNota($this->outlet, $this->owner, ['baris' => [$this->baris($kopi, 5, 1500)]]);
        $mati = $this->catatNota($this->outlet, $this->owner, ['baris' => [$this->baris($kopi, 5, 1500)]]);

        app(BatalkanPembelianAction::class)->execute($mati, $this->owner);

        $komponen = Livewire::actingAs($this->owner)->test(Pembelian::class);

        $nomorDi = fn ($daftar) => collect($daftar->items())->pluck('id')->all();

        $this->assertCount(2, $nomorDi($komponen->viewData('daftar')));

        $komponen->set('status', 'dibatalkan');
        $this->assertSame([$mati->getKey()], $nomorDi($komponen->viewData('daftar')));

        $komponen->set('status', 'diterima');
        $this->assertSame([$hidup->getKey()], $nomorDi($komponen->viewData('daftar')));
    }
}

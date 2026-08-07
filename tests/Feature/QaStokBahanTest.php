<?php

namespace Tests\Feature;

use App\Actions\Purchase\TerimaPembelianAction;
use App\Enums\Satuan;
use App\Enums\UserRole;
use App\Livewire\Pages\Owner\Bahan as LayarBahan;
use App\Models\Outlet;
use App\Models\Stock;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MembuatDataPembelian;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * QA — lubang di gerbang "satuan terkunci" milik layar Bahan (App\Livewire\Pages\Owner\Bahan).
 *
 * `aturanSatuanTerkunci()` (Bahan.php) hanya mengunci satuan kalau bahannya SUDAH punya
 * `stocks()->exists()` ATAU `recipeItems()->exists()`. Yang TIDAK diperiksa: nota belanja
 * yang SUDAH dicatat tapi BELUM ditandai datang.
 *
 * Nota belum-datang SENGAJA tidak membuat baris `stocks` (lihat PembelianBelumDatangTest) —
 * itu keputusan yang benar untuk stok, tapi efek sampingnya: bahan yang baru dibelanjakan
 * 5 kg, notanya belum "sudah datang", dan belum pernah dipakai resep, LOLOS gerbang satuan
 * terkunci. Pemilik lalu boleh mengganti satuannya ke gram. Baris nota (`purchase_order_items
 * .qty`) untuk bahan baku TIDAK dikonversi sama sekali (CatatPembelianAction: "Bahan baku
 * belum punya kolom konversi, jadi angkanya memang sudah dalam satuan pencatatannya
 * sendiri") — jadi begitu notanya ditandai datang, angka "5" yang dimaksud sebagai 5 KG
 * masuk ke kartu stok dan langsung dibaca dengan satuan SEKARANG bahannya: gram. Pemilik
 * membaca "5 gram gula masuk", padahal notanya 5 KILOGRAM — kesalahan 1000×, persis jenis
 * cacat yang aturanSatuanTerkunci() coba cegah, hanya lewat pintu yang tidak dijaganya.
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
     * TEMUAN: satuan bahan bisa diubah SESUDAH sudah ada nota belanja untuknya (5 kg gula),
     * SELAMA nota itu belum ditandai datang — lalu begitu notanya diterima, 5 (kg yang
     * dimaksud pemilik) masuk kartu stok dibaca sebagai 5 GRAM karena satuan masternya
     * sudah berubah. Tidak ada satu galat pun di sepanjang jalan.
     */
    public function test_satuan_bahan_masih_bisa_diubah_walau_sudah_ada_nota_belanja_yang_belum_datang(): void
    {
        $gula = $this->buatBahan('Gula Pasir', ['satuan' => Satuan::Kg]);

        // Nota dicatat: 5 kg gula, TAPI belum ditandai datang — sengaja, meniru cara pemilik
        // biasa mencatat belanja sebelum kurirnya tiba.
        $nota = $this->catatNotaBelumDatang($this->outlet, $this->owner, [
            'baris' => [$this->baris($gula, qtyBeli: 5, harga: 15000)],
        ]);

        // Pastikan pramisnya benar: belum ada baris `stocks` sama sekali untuk Gula Pasir,
        // dan belum dipakai resep — jadi gerbang aturanSatuanTerkunci() melihat "aman".
        $this->assertNull(
            Stock::withoutGlobalScopes()->where('raw_material_id', $gula->getKey())->first(),
            'nota belum datang tidak boleh membuat baris stok — pramis uji ini'
        );
        $this->assertFalse($gula->recipeItems()->exists());

        // Gerbangnya LOLOS: layar Bahan MENGIZINKAN mengganti kg → gram di sini, padahal
        // ada nota senilai 5 (yang dimaksud KILOGRAM) sedang menunggu diterima.
        Livewire::actingAs($this->owner)->test(LayarBahan::class)
            ->call('ubah', $gula->getKey())
            ->set('satuan', 'gram')
            ->call('simpan')
            ->assertHasNoErrors('satuan');

        $this->assertSame('gram', $gula->fresh()->satuan->value,
            'CACAT: satuan berhasil berubah walau ada nota belanja tertunda yang mengasumsikan kg');

        // Notanya sekarang ditandai datang — jalur SATU-SATUNYA pembelian menaikkan stok.
        app(TerimaPembelianAction::class)->execute($nota->fresh(), $this->owner);

        $baris = Stock::withoutGlobalScopes()
            ->where('raw_material_id', $gula->getKey())
            ->sole();

        // Angkanya utuh 5 — TIDAK dikonversi (bahan baku memang tidak punya konversi).
        $this->assertEqualsWithDelta(5.0, (float) $baris->jumlah_saat_ini, 0.001);

        // Dan di sinilah cacatnya: satuan yang dibaca SEKARANG adalah gram, bukan kg.
        // Pemilik yang membeli 5 KG gula membaca kartu stoknya bertambah "5 gram" —
        // seribu kali lebih kecil daripada yang sungguh terjadi, tanpa satu pun peringatan.
        $this->assertSame('gram', $gula->fresh()->satuan->value,
            'kartu stok akan menampilkan "5 gram" untuk belanja yang sungguhnya 5 kg — '.
            'gerbang satuan terkunci tidak melihat nota yang belum datang');
    }
}

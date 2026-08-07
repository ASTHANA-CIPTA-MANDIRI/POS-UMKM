<?php

namespace Tests\Feature;

use App\Actions\Stok\AdjustStockAction;
use App\Actions\Stok\ApplySaleToStockAction;
use App\Enums\AlasanOpname;
use App\Enums\Satuan;
use App\Enums\StockMovementType;
use App\Enums\TransactionMode;
use App\Enums\TransactionOrigin;
use App\Enums\UserRole;
use App\Livewire\Pages\Owner\Stok\Opname;
use App\Models\Bahan\RawMaterial;
use App\Models\Kasir\Transaction;
use App\Models\Kasir\TransactionItem;
use App\Models\Produk\Product;
use App\Models\Stok\Stock;
use App\Models\Stok\StockMovement;
use App\Models\Tenant\Outlet;
use App\Models\Tenant\Tenant;
use App\Models\Tenant\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Stok opname: hitung fisik, selisih, dan alasannya.
 *
 * Opname adalah satu-satunya cara memperbaiki angka stok yang sudah menyimpang (termasuk
 * yang minus), jadi kesalahan di sini tidak berhenti sebagai angka salah — ia menghapus
 * satu-satunya alat perbaikan yang ada. Yang dijaga berkas ini:
 *
 * - selisih dihitung terhadap saldo SAAT SIMPAN, bukan saldo saat layar dibuka;
 * - kolom fisik yang DIBIARKAN KOSONG bukan nol;
 * - satu baris yang ditolak tidak boleh menyimpan baris lain separuh jalan;
 * - angka yang sudah diketik bertahan melewati paginasi & saringan;
 * - penjualan offline yang tiba sesudah opname tetap dicatat, tapi ditandai.
 */
class OpnameStokTest extends TestCase
{
    use MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $outlet;

    private User $owner;

    private User $kasir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant('Toko Opname');
        $this->outlet = $this->buatOutlet($this->tenant, 'Outlet Opname');

        $this->owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik Opname',
            'email' => 'owner@opname.test',
            'password' => 'rahasia123',
        ]);

        $this->kasir = $this->buatUser($this->tenant, UserRole::Kasir, [
            'name' => 'Kasir Opname',
            'username' => 'kasiropname',
            'pin_hash' => '123456',
            'outlet_id' => $this->outlet->getKey(),
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());
    }

    /* ── Selisih & mutasi ────────────────────────────────────────────────── */

    /**
     * Kasus paling dasar, dan tiap potongannya diperiksa terpisah dengan sengaja.
     *
     * Saldo dan kartu stok bisa menyimpang satu dari yang lain (saldo dikurangi tapi
     * mutasinya mencatat angka lain), dan begitu itu terjadi kartu stok berhenti bisa
     * merekonstruksi saldonya — padahal itulah satu-satunya bukti yang tersisa saat ada
     * selisih yang harus dijelaskan.
     */
    public function test_sistem_10_fisik_7_alasan_rusak_menghasilkan_satu_mutasi_opname_minus_3(): void
    {
        $produk = $this->buatProduk('Kerupuk');
        $stok = $this->buatStok($produk, 10);

        Livewire::actingAs($this->owner)
            ->test(Opname::class)
            ->set('fisik.'.$produk->getKey(), 7)
            ->set('alasan.'.$produk->getKey(), AlasanOpname::Rusak->value)
            ->call('simpan')
            ->assertHasNoErrors();

        $mutasi = StockMovement::sole();

        $this->assertSame(StockMovementType::Opname, $mutasi->tipe);
        $this->assertEqualsWithDelta(-3.0, (float) $mutasi->jumlah, 0.001);
        $this->assertEqualsWithDelta(7.0, (float) $mutasi->saldo_sesudah, 0.001);
        $this->assertSame(AlasanOpname::Rusak, $mutasi->alasan);
        $this->assertSame($this->owner->getKey(), $mutasi->oleh_user_id);

        // Referensi NULL memang benar: opname tidak punya dokumen sumber. Kalau nanti
        // kolom ini dibuat wajib, seluruh jalur opname berhenti.
        $this->assertNull($mutasi->referensi_id);
        $this->assertNull($mutasi->referensi_type);

        $this->assertEqualsWithDelta(7.0, (float) $stok->fresh()->jumlah_saat_ini, 0.001);
    }

    /**
     * Fisik 0 dihitung; fisik yang DIBIARKAN KOSONG tidak.
     *
     * Ini pembedaan yang paling mahal kalau salah. "0" adalah pernyataan bahwa raknya
     * kosong — selisih terbesar yang bisa ada. Sebaliknya kolom kosong berarti barangnya
     * belum sempat dihitung, dan menganggapnya nol akan MENGHAPUS seluruh stok barang
     * yang tidak ikut dihitung sore itu.
     */
    public function test_fisik_0_dihitung_penuh_sedangkan_fisik_kosong_tidak_menghasilkan_mutasi(): void
    {
        $teh = $this->buatProduk('Teh Kotak');
        $stokTeh = $this->buatStok($teh, 5);

        $gula = $this->buatProduk('Gula Pasir');
        $stokGula = $this->buatStok($gula, 8);

        Livewire::actingAs($this->owner)
            ->test(Opname::class)
            ->set('fisik.'.$teh->getKey(), 0)
            ->set('alasan.'.$teh->getKey(), AlasanOpname::Hilang->value)
            // Gula sengaja dibiarkan kosong.
            ->call('simpan')
            ->assertHasNoErrors();

        $mutasi = StockMovement::sole();

        $this->assertSame($stokTeh->getKey(), $mutasi->stock_id);
        $this->assertEqualsWithDelta(-5.0, (float) $mutasi->jumlah, 0.001, 'selisihnya 0 − 5');
        $this->assertEqualsWithDelta(0.0, (float) $stokTeh->fresh()->jumlah_saat_ini, 0.001);
        $this->assertNotNull($stokTeh->fresh()->opname_terakhir_pada);

        $this->assertEqualsWithDelta(8.0, (float) $stokGula->fresh()->jumlah_saat_ini, 0.001,
            'barang yang tidak dihitung tidak boleh ikut berubah');
        $this->assertNull($stokGula->fresh()->opname_terakhir_pada,
            'barang yang tidak dihitung tidak boleh ditandai sudah dihitung');
    }

    /**
     * Fisik PAS dengan sistem: tanpa mutasi, tapi tetap tercatat sudah dihitung.
     *
     * Mutasi bernilai nol hanya menambah baris kosong di kartu stok. Tapi kalau barisnya
     * tidak ditandai sama sekali, hasil hitung yang paling berharga — bukti bahwa
     * angkanya benar — tidak tersimpan di mana pun, dan barang itu akan tampil "belum
     * pernah dihitung" lalu dihitung lagi minggu depan tanpa perlu.
     */
    public function test_fisik_sama_dengan_sistem_tidak_membuat_mutasi_tapi_menandai_sudah_diopname(): void
    {
        $produk = $this->buatProduk('Kopi Sachet');
        $stok = $this->buatStok($produk, 12);

        Livewire::actingAs($this->owner)
            ->test(Opname::class)
            ->set('fisik.'.$produk->getKey(), 12)
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertSame(0, StockMovement::count(), 'selisih nol tidak boleh mengarang mutasi');

        $segar = $stok->fresh();

        $this->assertNotNull($segar->opname_terakhir_pada);
        $this->assertFalse($segar->perlu_diperiksa);
        $this->assertEqualsWithDelta(12.0, (float) $segar->jumlah_saat_ini, 0.001);
    }

    /**
     * Opname adalah SATU-SATUNYA cara memperbaiki stok minus.
     *
     * Kalau jalur ini menolak saldo negatif (mis. karena "jumlah tidak boleh negatif"
     * dipasang di tempat yang salah), angka minus akibat penjualan offline tidak akan
     * pernah bisa diperbaiki dari layar mana pun.
     */
    public function test_stok_minus_3_diopname_fisik_8_alasan_salah_catat_menjadi_8(): void
    {
        $produk = $this->buatProduk('Mie Instan');
        $stok = $this->buatStok($produk, -3);

        Livewire::actingAs($this->owner)
            ->test(Opname::class)
            ->set('fisik.'.$produk->getKey(), 8)
            ->set('alasan.'.$produk->getKey(), AlasanOpname::SalahCatat->value)
            ->call('simpan')
            ->assertHasNoErrors();

        $mutasi = StockMovement::sole();

        $this->assertEqualsWithDelta(11.0, (float) $mutasi->jumlah, 0.001, 'dari −3 ke 8 berarti +11');
        $this->assertEqualsWithDelta(8.0, (float) $stok->fresh()->jumlah_saat_ini, 0.001);
    }

    /* ── Validasi ────────────────────────────────────────────────────────── */

    /**
     * Selisih tanpa alasan ditolak — DAN baris lain di lembar yang sama tidak boleh
     * ikut tersimpan.
     *
     * Ini bagian yang mudah salah: kalau penyimpanan berjalan baris per baris tanpa
     * validasi seluruh lembar lebih dulu, baris yang sah sudah masuk saat baris yang
     * bermasalah ditolak. Pemiliknya melihat pesan galat, mengulang seluruh lembar, dan
     * baris yang sudah masuk dihitung dua kali.
     */
    public function test_selisih_tanpa_alasan_ditolak_dan_tidak_ada_baris_lain_yang_tersimpan(): void
    {
        $tanpaAlasan = $this->buatProduk('Sabun Cuci');
        $stokTanpaAlasan = $this->buatStok($tanpaAlasan, 10);

        $sah = $this->buatProduk('Minyak Goreng');
        $stokSah = $this->buatStok($sah, 4);

        Livewire::actingAs($this->owner)
            ->test(Opname::class)
            ->set('fisik.'.$tanpaAlasan->getKey(), 6)
            ->set('fisik.'.$sah->getKey(), 9)
            ->set('alasan.'.$sah->getKey(), AlasanOpname::TemuanLebih->value)
            ->call('simpan')
            ->assertHasErrors('alasan.'.$tanpaAlasan->getKey());

        $this->assertSame(0, StockMovement::count());
        $this->assertEqualsWithDelta(10.0, (float) $stokTanpaAlasan->fresh()->jumlah_saat_ini, 0.001);
        $this->assertEqualsWithDelta(4.0, (float) $stokSah->fresh()->jumlah_saat_ini, 0.001,
            'baris yang sah tidak boleh tersimpan separuh jalan');
        $this->assertNull($stokSah->fresh()->opname_terakhir_pada);
    }

    /**
     * "Lainnya" tanpa catatan ditolak.
     *
     * Alasan lain tidak mewajibkan catatan (lihat AlasanOpname::butuhCatatan) supaya
     * kolomnya tidak diisi "-" oleh orang yang sedang menghitung 200 barang. Tapi
     * "lainnya" tanpa kalimat tidak menyimpan informasi apa pun sama sekali.
     */
    public function test_alasan_lainnya_tanpa_catatan_ditolak(): void
    {
        $produk = $this->buatProduk('Rokok');
        $stok = $this->buatStok($produk, 20);

        Livewire::actingAs($this->owner)
            ->test(Opname::class)
            ->set('fisik.'.$produk->getKey(), 15)
            ->set('alasan.'.$produk->getKey(), AlasanOpname::Lainnya->value)
            ->call('simpan')
            ->assertHasErrors('catatan.'.$produk->getKey());

        $this->assertSame(0, StockMovement::count());
        $this->assertEqualsWithDelta(20.0, (float) $stok->fresh()->jumlah_saat_ini, 0.001);

        // Dengan catatan, baris yang sama diterima.
        Livewire::actingAs($this->owner)
            ->test(Opname::class)
            ->set('fisik.'.$produk->getKey(), 15)
            ->set('alasan.'.$produk->getKey(), AlasanOpname::Lainnya->value)
            ->set('catatan.'.$produk->getKey(), 'Dipakai untuk hajatan keluarga, belum dicatat.')
            ->call('simpan')
            ->assertHasNoErrors();

        $mutasi = StockMovement::sole();

        $this->assertSame(AlasanOpname::Lainnya, $mutasi->alasan);
        $this->assertStringContainsString('hajatan', (string) $mutasi->catatan);
    }

    /* ── Barang yang belum punya baris stok ──────────────────────────────── */

    /**
     * Barang yang belum pernah dicatat di outlet ini WAJIB tetap muncul di lembar hitung.
     *
     * Kalau lembar opname hanya menampilkan barang yang sudah punya baris `stocks`, maka
     * barang yang belum pernah punya angka tidak akan pernah bisa diberi angka — dan
     * itu justru barang yang paling butuh dihitung.
     */
    public function test_produk_tanpa_baris_stok_muncul_dengan_sistem_0_dan_bisa_diopname(): void
    {
        $produk = $this->buatProduk('Gas 3 Kg');

        $komponen = Livewire::actingAs($this->owner)->test(Opname::class);

        $baris = collect($komponen->viewData('daftar')->items())->firstWhere('kunci', $produk->getKey());

        $this->assertNotNull($baris, 'barang tanpa baris stok harus tetap tampil');
        $this->assertFalse($baris['punya_baris']);
        $this->assertSame(0.0, $baris['sistem']);

        $komponen
            ->set('fisik.'.$produk->getKey(), 12)
            ->set('alasan.'.$produk->getKey(), AlasanOpname::StokAwal->value)
            ->call('simpan')
            ->assertHasNoErrors();

        $stok = Stock::query()->where('product_id', $produk->getKey())->sole();

        $this->assertEqualsWithDelta(12.0, (float) $stok->jumlah_saat_ini, 0.001);
        $this->assertEqualsWithDelta(12.0, (float) StockMovement::sole()->jumlah, 0.001);
    }

    /** Mutasi bahan baku menempel pada baris stok ber-raw_material_id, bukan product_id. */
    public function test_bahan_baku_diopname_mutasinya_menempel_pada_baris_raw_material(): void
    {
        $bahan = RawMaterial::create(['nama' => 'Beras', 'satuan' => Satuan::Kg]);

        Livewire::actingAs($this->owner)
            ->test(Opname::class)
            ->set('fisik.'.$bahan->getKey(), 8.5)
            ->set('alasan.'.$bahan->getKey(), AlasanOpname::StokAwal->value)
            ->call('simpan')
            ->assertHasNoErrors();

        $mutasi = StockMovement::sole();
        $stok = $mutasi->stock;

        $this->assertSame($bahan->getKey(), $stok->raw_material_id);
        $this->assertNull($stok->product_id);
        $this->assertEqualsWithDelta(8.5, (float) $stok->jumlah_saat_ini, 0.001);
        $this->assertEqualsWithDelta(8.5, (float) $mutasi->jumlah, 0.001);
    }

    /* ── Nilai yang sudah diketik ────────────────────────────────────────── */

    /**
     * CACAT YANG DIJAGA: 12 baris diketik, lalu pindah halaman & ganti saringan, dan
     * simpan() hanya memproses baris yang sedang tampak.
     *
     * Di layar tidak ada tanda apa pun bahwa ada yang hilang — angkanya masih terlihat
     * sampai halaman berganti. Pemilik menghitung 80 barang, menyimpan, dan setengahnya
     * tidak tersimpan tanpa satu pun galat. Ia tidak akan mencoba lagi.
     */
    public function test_dua_belas_baris_terisi_tetap_tersimpan_setelah_pindah_halaman_dan_ganti_saringan(): void
    {
        $produk = [];

        // 30 baris supaya benar-benar ada halaman kedua (25 baris per halaman).
        for ($i = 1; $i <= 30; $i++) {
            $nama = 'Barang '.str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $produk[$i] = $this->buatProduk($nama);
            $this->buatStok($produk[$i], 10);
        }

        $komponen = Livewire::actingAs($this->owner)->test(Opname::class);

        // Dua belas baris pertama dihitung.
        for ($i = 1; $i <= 12; $i++) {
            $komponen
                ->set('fisik.'.$produk[$i]->getKey(), 7)
                ->set('alasan.'.$produk[$i]->getKey(), AlasanOpname::SalahCatat->value);
        }

        // Pindah ke halaman 2 (barang 26–30), lalu saring sampai hanya satu baris tampak.
        $komponen->call('gotoPage', 2);
        $komponen->set('cari', 'Barang 30');

        $this->assertSame(12, $komponen->viewData('jumlahTerisi'),
            'nilai yang sudah diketik harus bertahan melewati paginasi & saringan');
        $this->assertCount(1, $komponen->viewData('daftar')->items(),
            'saringannya benar-benar menyembunyikan 11 baris terisi dari layar');

        $komponen->call('simpan')->assertHasNoErrors();

        $this->assertSame(12, StockMovement::count(), 'semua baris terisi harus diproses, bukan hanya yang tampak');

        for ($i = 1; $i <= 12; $i++) {
            $this->assertEqualsWithDelta(7.0, (float) $produk[$i]->stocks()->sole()->jumlah_saat_ini, 0.001,
                'Barang '.$i.' seharusnya ikut tersimpan');
        }

        $this->assertEqualsWithDelta(10.0, (float) $produk[30]->stocks()->sole()->jumlah_saat_ini, 0.001,
            'baris yang tampak tapi tidak diisi tidak boleh berubah');
    }

    /**
     * Saldo bergerak antara "layar dibuka" dan "disimpan" — kasir tetap berjualan selama
     * pemilik menghitung.
     *
     * Selisih WAJIB dihitung terhadap saldo saat simpan. Kalau dihitung dari angka layar,
     * opname akan membatalkan penjualan yang terjadi selama penghitungan: stok
     * "diperbaiki" ke angka kedaluwarsa dan barang yang sudah terjual muncul lagi di
     * catatan. Dan karena selisih yang tercatat jadi tidak bisa dijelaskan, catatannya
     * wajib memuat KEDUA angka — kalau tidak, orang yang menghitung akan disalahkan untuk
     * pergerakan yang terjadi sesudah ia menghitung.
     */
    public function test_saldo_berubah_saat_menyimpan_selisih_dihitung_dari_saldo_terbaru_dan_catatan_memuat_dua_angka(): void
    {
        $produk = $this->buatProduk('Air Mineral');
        $stok = $this->buatStok($produk, 10);

        $komponen = Livewire::actingAs($this->owner)
            ->test(Opname::class)
            ->set('fisik.'.$produk->getKey(), 7)
            ->set('alasan.'.$produk->getKey(), AlasanOpname::Rusak->value);

        // Kasir menjual 6 pcs saat lembar masih terbuka → saldo sistem turun ke 4.
        app(AdjustStockAction::class)->execute(
            $stok,
            StockMovementType::Keluar,
            -6,
            olehUserId: $this->kasir->getKey(),
        );

        $komponen->call('simpan')->assertHasNoErrors();

        $mutasi = StockMovement::query()->where('tipe', StockMovementType::Opname)->sole();

        $this->assertEqualsWithDelta(3.0, (float) $mutasi->jumlah, 0.001,
            'selisihnya 7 − 4 (saldo saat simpan), bukan 7 − 10 (saldo saat layar dibuka)');
        $this->assertEqualsWithDelta(7.0, (float) $stok->fresh()->jumlah_saat_ini, 0.001);

        $catatan = (string) $mutasi->catatan;

        $this->assertStringContainsString('layar menunjukkan 10', $catatan);
        $this->assertStringContainsString('saldo saat disimpan 4', $catatan);
    }

    /**
     * Kasus yang sama, tapi angka fisiknya baru TIBA DI SERVER sesudah saldonya bergerak.
     *
     * Ini urutan yang sesungguhnya terjadi di lapangan: `wire:model` bawaan Livewire
     * bersifat tertunda, jadi 80 angka yang diketik pemilik baru dikirim bersamaan dengan
     * penekanan tombol simpan — saat itu saldo sistem sudah berubah. Kalau angka "yang
     * terbaca di layar" baru direkam saat nilainya tiba, ia selalu sama dengan saldo
     * terbaru, dan keterangan yang membedakan "penghitungnya salah" dari "barangnya
     * terjual sesudah dihitung" hilang tanpa jejak.
     */
    public function test_angka_sistem_yang_tampil_di_layar_tetap_tercatat_walau_fisiknya_dikirim_belakangan(): void
    {
        $produk = $this->buatProduk('Susu Kental');
        $stok = $this->buatStok($produk, 10);

        // Layar dibuka & dirender: sistem menampilkan 10.
        $komponen = Livewire::actingAs($this->owner)->test(Opname::class);

        // Kasir menjual 6 pcs sebelum lembar disimpan.
        app(AdjustStockAction::class)->execute(
            $stok,
            StockMovementType::Keluar,
            -6,
            olehUserId: $this->kasir->getKey(),
        );

        // Baru sekarang angka hasil hitung tiba di server, bersama tombol simpan.
        $komponen
            ->set('fisik.'.$produk->getKey(), 7)
            ->set('alasan.'.$produk->getKey(), AlasanOpname::Rusak->value)
            ->call('simpan')
            ->assertHasNoErrors();

        $mutasi = StockMovement::query()->where('tipe', StockMovementType::Opname)->sole();

        $this->assertEqualsWithDelta(3.0, (float) $mutasi->jumlah, 0.001);
        $this->assertStringContainsString('layar menunjukkan 10', (string) $mutasi->catatan);
        $this->assertStringContainsString('saldo saat disimpan 4', (string) $mutasi->catatan);
    }

    /* ── Bendera perlu diperiksa ─────────────────────────────────────────── */

    /**
     * CACAT YANG DIJAGA: penjualan offline yang tiba SESUDAH opname memotong stok dua
     * kali, dan tanpa penanda itu benar-benar tidak terdeteksi.
     *
     * Transaksi jam 09.00 baru disinkronkan jam 11.00, sedangkan opname dilakukan jam
     * 10.00. Barang yang terjual pagi itu sudah tidak ada di rak saat dihitung — jadi
     * opname sudah memotongnya sekali, dan mutasi penjualan ini memotongnya lagi.
     *
     * Mutasinya TETAP diterapkan (aturan 5 CLAUDE.md: penjualannya sungguh terjadi),
     * tapi barisnya ditandai dan mutasinya membawa penjelasan. Tanpa itu, selisihnya baru
     * muncul di opname berikutnya sebagai "hilang" — dan dicurigai sebagai pencurian.
     */
    public function test_transaksi_offline_yang_terjadi_sebelum_opname_tetap_diterapkan_tapi_ditandai(): void
    {
        $produk = $this->buatProduk('Kopi Susu');
        $stok = $this->buatStok($produk, 10);

        Carbon::setTestNow('2026-08-04 10:00:00');

        Livewire::actingAs($this->owner)
            ->test(Opname::class)
            ->set('fisik.'.$produk->getKey(), 10)
            ->call('simpan')
            ->assertHasNoErrors();

        Carbon::setTestNow('2026-08-04 11:00:00');

        $transaksi = Transaction::create([
            'outlet_id' => $this->outlet->getKey(),
            'staff_id' => $this->kasir->getKey(),
            'nomor_transaksi' => 'TRX-TELAT',
            'mode' => TransactionMode::Langsung,
            'subtotal' => 4000,
            'total' => 4000,
            'origin' => TransactionOrigin::Offline,
            'waktu_transaksi' => '2026-08-04 09:00:00',
            'dibuat_offline_pada' => '2026-08-04 09:00:00',
        ]);

        TransactionItem::create([
            'transaction_id' => $transaksi->getKey(),
            'product_id' => $produk->getKey(),
            'nama_produk' => $produk->nama_produk,
            'qty' => 2,
            'harga_satuan' => 2000,
            'subtotal' => 4000,
        ]);

        app(ApplySaleToStockAction::class)->execute($transaksi->fresh(), $this->kasir->getKey());

        $segar = $stok->fresh();

        $this->assertEqualsWithDelta(8.0, (float) $segar->jumlah_saat_ini, 0.001,
            'penjualannya sungguh terjadi — mutasinya tidak boleh dibatalkan');
        $this->assertTrue($segar->perlu_diperiksa,
            'stok yang mungkin terpotong dua kali wajib masuk daftar hitung berikutnya');

        $mutasi = StockMovement::query()->where('tipe', StockMovementType::Keluar)->sole();

        $this->assertStringContainsString('SEBELUM opname', (string) $mutasi->catatan);
        $this->assertStringContainsString('dua kali', (string) $mutasi->catatan);
    }

    /** Barang yang sudah dihitung ulang tidak perlu diperiksa lagi — itu definisi benderanya. */
    public function test_item_yang_perlu_diperiksa_kembali_bersih_setelah_diopname_ulang(): void
    {
        $produk = $this->buatProduk('Teh Manis');
        $stok = $this->buatStok($produk, 5, atribut: ['perlu_diperiksa' => true]);

        Livewire::actingAs($this->owner)
            ->test(Opname::class)
            ->set('fisik.'.$produk->getKey(), 4)
            ->set('alasan.'.$produk->getKey(), AlasanOpname::Hilang->value)
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertFalse($stok->fresh()->perlu_diperiksa);
        $this->assertNotNull($stok->fresh()->opname_terakhir_pada);
    }

    /* ── Pembantu ────────────────────────────────────────────────────────── */

    private function buatProduk(string $nama, array $atribut = []): Product
    {
        return Product::create(array_merge([
            'nama_produk' => $nama,
            'harga_default' => 2000,
            'satuan' => Satuan::Pcs,
        ], $atribut));
    }

    private function buatStok(Product|RawMaterial $item, float $jumlah, float $minimum = 0, array $atribut = []): Stock
    {
        return Stock::create(array_merge([
            'outlet_id' => $this->outlet->getKey(),
            'product_id' => $item instanceof Product ? $item->getKey() : null,
            'raw_material_id' => $item instanceof RawMaterial ? $item->getKey() : null,
            'jumlah_saat_ini' => $jumlah,
            'stok_minimum' => $minimum,
        ], $atribut));
    }
}

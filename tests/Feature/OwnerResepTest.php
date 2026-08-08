<?php

namespace Tests\Feature;

use App\Actions\Stok\SusunBarisStokAction;
use App\Enums\Satuan;
use App\Enums\UserRole;
use App\Livewire\Pages\Owner\Bahan\Resep as LayarResep;
use App\Models\Bahan\RawMaterial;
use App\Models\Bahan\RecipeItem;
use App\Models\Tenant\Outlet;
use App\Models\Tenant\Tenant;
use App\Models\Tenant\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MembuatDataPembelian;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Layar Resep: 1 porsi menu habis berapa bahan mentah.
 *
 * Layar ini menyalakan tiga hal yang SUDAH ADA dan sudah teruji tapi belum bisa dipakai
 * siapa pun, karena tidak ada pintu untuk mengisi resepnya:
 * pemotongan bahan saat menu terjual, lencana "Habis" kasir yang mengikuti bahan terparah,
 * dan keluarnya menu berbasis resep dari daftar Stok.
 *
 * Yang dijaga di sini bukan "formulirnya bisa disimpan", melainkan tiga hal yang kalau
 * salah tidak pernah berteriak:
 *
 * 1. **Skala angkanya.** "0,25" yang tersimpan sebagai 25 memotong stok seratus kali lebih
 *    cepat; tidak ada galat, tidak ada uang yang salah, hanya lele yang habis tiap dua hari
 *    dan hitung stok yang menambalnya tiap minggu tanpa ada yang tahu sebabnya.
 * 2. **Perpindahan cara hitung.** Begitu resep pertama tersimpan, stok menunya berhenti
 *    dihitung TANPA pernah menjadi nol — angkanya menggantung di nilai persediaan.
 * 3. **Tujuan penyimpanannya.** $produkId adalah penentu menu mana yang ditulis; kalau bisa
 *    ditukar dari klien, resep "Lele Goreng" mendarat di "Es Teh" tanpa satu pun galat.
 */
class OwnerResepTest extends TestCase
{
    use MembuatDataPembelian, MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $outlet;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant('Warteg Resep');
        $this->outlet = $this->buatOutlet($this->tenant, 'Cabang Resep');
        $this->owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik Resep',
            'email' => 'owner@resep.test',
            'password' => 'rahasia123',
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());
    }

    /* ── Menyimpan ───────────────────────────────────────────────────────── */

    public function test_resep_tersimpan_dan_koma_dibaca_sebagai_desimal(): void
    {
        $menu = $this->buatProduk('Lele Goreng', ['satuan' => Satuan::Porsi]);
        $lele = $this->buatBahan('Lele Segar', ['satuan' => Satuan::Kg]);
        $minyak = $this->buatBahan('Minyak Goreng', ['satuan' => Satuan::Liter]);

        Livewire::actingAs($this->owner)
            ->test(LayarResep::class)
            ->call('atur', $menu->getKey())
            ->set('baris.0.bahan', $lele->getKey())
            // Koma, bukan titik: begitulah orang di sini menulis desimal.
            ->set('baris.0.jumlah', '0,25')
            ->call('tambahBaris')
            ->set('baris.1.bahan', $minyak->getKey())
            ->set('baris.1.jumlah', '0,03')
            ->call('simpan')
            ->assertHasNoErrors();

        $resep = RecipeItem::query()->where('product_id', $menu->getKey())->get();

        $this->assertCount(2, $resep);
        $this->assertEqualsWithDelta(0.25, (float) $resep->firstWhere('raw_material_id', $lele->getKey())->jumlah_terpakai, 0.0001);
        $this->assertEqualsWithDelta(0.03, (float) $resep->firstWhere('raw_material_id', $minyak->getKey())->jumlah_terpakai, 0.0001);
    }

    /**
     * "1.500" DITOLAK — bukan tersimpan sebagai 1,5.
     *
     * Di kolom jumlah, 1,5 dan 1.500 dua-duanya "angka yang wajar", jadi tidak ada satu pun
     * galat yang muncul kalau titiknya diterima sebagai desimal. Resep 1,5 kg per porsi
     * memotong stok enam kali lebih cepat daripada 0,25 — dan itu baru ketahuan berminggu
     * kemudian sebagai "kok lelenya cepat habis".
     */
    public function test_jumlah_bertitik_ribuan_ditolak_bukan_dibaca_sebagai_desimal(): void
    {
        $menu = $this->buatProduk('Nasi Goreng');
        $beras = $this->buatBahan('Beras', ['satuan' => Satuan::Kg]);

        Livewire::actingAs($this->owner)
            ->test(LayarResep::class)
            ->call('atur', $menu->getKey())
            ->set('baris.0.bahan', $beras->getKey())
            ->set('baris.0.jumlah', '1.500')
            ->call('simpan')
            ->assertHasErrors('baris.0.jumlah');

        $this->assertSame(0, RecipeItem::query()->count(),
            'tidak boleh ada resep tersimpan dari angka yang ambigu');
    }

    public function test_jumlah_nol_ditolak_dan_pesannya_menyebut_jalan_keluarnya(): void
    {
        $menu = $this->buatProduk('Es Teh');
        $gula = $this->buatBahan('Gula Pasir', ['satuan' => Satuan::Gram]);

        $komponen = Livewire::actingAs($this->owner)
            ->test(LayarResep::class)
            ->call('atur', $menu->getKey())
            ->set('baris.0.bahan', $gula->getKey())
            ->set('baris.0.jumlah', '0')
            ->call('simpan')
            ->assertHasErrors('baris.0.jumlah');

        $this->assertStringContainsString('buang barisnya', $komponen->html(),
            'nol berarti bahannya tidak dipakai — pesannya harus menyebut cara menyatakannya');
        $this->assertSame(0, RecipeItem::query()->count());
    }

    /**
     * Bahan kembar ditolak APLIKASI, bukan oleh unique index basis data.
     *
     * Kalau dibiarkan sampai ke indeks, pemilik warung membaca kalimat Inggris berisi nama
     * kolom — dan tidak ada satu pun bagian kalimat itu yang bisa ia kerjakan.
     */
    public function test_bahan_kembar_ditolak_dengan_menyebut_namanya(): void
    {
        $menu = $this->buatProduk('Ayam Goreng');
        $ayam = $this->buatBahan('Ayam Potong', ['satuan' => Satuan::Kg]);

        $komponen = Livewire::actingAs($this->owner)
            ->test(LayarResep::class)
            ->call('atur', $menu->getKey())
            ->set('baris.0.bahan', $ayam->getKey())
            ->set('baris.0.jumlah', '0,3')
            ->call('tambahBaris')
            ->set('baris.1.bahan', $ayam->getKey())
            ->set('baris.1.jumlah', '0,1')
            ->call('simpan')
            ->assertHasErrors('baris.1.bahan');

        $this->assertStringContainsString('Ayam Potong sudah ada di resep ini', $komponen->html());
        $this->assertSame(0, RecipeItem::query()->count());
    }

    /* ── Akibatnya pada stok ─────────────────────────────────────────────── */

    /**
     * Menu berbasis resep KELUAR dari daftar Stok; bahannya yang muncul.
     *
     * Ini inti perpindahan cara hitungnya: yang habis bahannya, bukan menunya. Kalau menunya
     * tetap ada di daftar, pemilik akan menghitung fisik "lele goreng" — dan angka itu tidak
     * pernah dipakai siapa pun lagi.
     */
    public function test_menu_berresep_keluar_dari_daftar_stok_dan_bahannya_masuk(): void
    {
        $menu = $this->buatProduk('Lele Goreng', ['satuan' => Satuan::Porsi]);
        $lele = $this->buatBahan('Lele Segar', ['satuan' => Satuan::Kg]);

        // execute() menerima ID outlet dan mengembalikan Collection apa adanya — bukan
        // larik ber-kunci 'baris'. Bentuk yang salah membuat ujinya "gagal" atas API-nya
        // sendiri, bukan atas layarnya.
        $sebelum = app(SusunBarisStokAction::class)->execute($this->outlet->getKey())->pluck('nama');

        $this->assertTrue($sebelum->contains('Lele Goreng'),
            'pramis: sebelum punya resep, menunya memang ada di daftar stok');

        Livewire::actingAs($this->owner)
            ->test(LayarResep::class)
            ->call('atur', $menu->getKey())
            ->set('baris.0.bahan', $lele->getKey())
            ->set('baris.0.jumlah', '0,25')
            ->call('simpan')
            ->assertHasNoErrors();

        $sesudah = app(SusunBarisStokAction::class)->execute($this->outlet->getKey())->pluck('nama');

        $this->assertFalse($sesudah->contains('Lele Goreng'),
            'menu berbasis resep tidak boleh lagi dihitung sebagai barang jadi');
        $this->assertTrue($sesudah->contains('Lele Segar'),
            'bahannya yang menggantikannya di daftar stok');
    }

    /**
     * Membuang bahan TERAKHIR mengembalikan menunya jadi barang jadi.
     *
     * Arah sebaliknya dari uji di atas, dan perlu: resep yang dikosongkan tapi menunya tidak
     * kembali ke daftar Stok berarti barang itu berhenti dihitung sama sekali — hilang dari
     * dua-duanya.
     */
    public function test_resep_yang_dikosongkan_mengembalikan_menu_ke_daftar_stok(): void
    {
        $menu = $this->buatProduk('Tempe Goreng', ['satuan' => Satuan::Porsi]);
        $tempe = $this->buatBahan('Tempe', ['satuan' => Satuan::Kg]);

        RecipeItem::create([
            'product_id' => $menu->getKey(),
            'raw_material_id' => $tempe->getKey(),
            'jumlah_terpakai' => 0.1,
        ]);

        Livewire::actingAs($this->owner)
            ->test(LayarResep::class)
            ->call('atur', $menu->getKey())
            ->call('buangBaris', 0)
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertSame(0, RecipeItem::query()->where('product_id', $menu->getKey())->count());

        $nama = app(SusunBarisStokAction::class)->execute($this->outlet->getKey())->pluck('nama');

        $this->assertTrue($nama->contains('Tempe Goreng'),
            'menu tanpa resep dihitung sebagai barang jadi lagi — bukan hilang dari dua-duanya');
    }

    /* ── Peringatan giliran pertama ──────────────────────────────────────── */

    /**
     * Menu yang masih punya sisa tercatat: angkanya DISEBUT beserta cabangnya.
     *
     * "Cara hitungnya berubah" tanpa angka tidak memberi tahu apa yang akan hilang dari layar
     * Stok besok pagi. Yang membuat kalimat ini bisa dikerjakan adalah "4 porsi di Cabang
     * Resep" — pemilik jadi tahu ia perlu menghitung stok dulu kalau angkanya mau dinolkan.
     */
    public function test_panel_menyebut_sisa_tercatat_beserta_cabangnya(): void
    {
        $menu = $this->buatProduk('Soto Ayam', ['satuan' => Satuan::Porsi]);
        $this->buatStok($this->outlet, $menu, 4);

        $html = Livewire::actingAs($this->owner)
            ->test(LayarResep::class)
            ->call('atur', $menu->getKey())
            ->html();

        $this->assertStringContainsString('4 porsi di Cabang Resep', $html);
        $this->assertStringContainsString('berhenti dihitung', $html);
    }

    public function test_menu_yang_sudah_berresep_tidak_diperingatkan_lagi(): void
    {
        $menu = $this->buatProduk('Soto Ayam', ['satuan' => Satuan::Porsi]);
        $ayam = $this->buatBahan('Ayam Potong', ['satuan' => Satuan::Kg]);
        $this->buatStok($this->outlet, $menu, 4);

        RecipeItem::create([
            'product_id' => $menu->getKey(),
            'raw_material_id' => $ayam->getKey(),
            'jumlah_terpakai' => 0.2,
        ]);

        $html = Livewire::actingAs($this->owner)
            ->test(LayarResep::class)
            ->call('atur', $menu->getKey())
            ->html();

        $this->assertStringNotContainsString('masih punya sisa tercatat', $html,
            'peringatan perpindahan cara hitung hanya untuk GILIRAN PERTAMA; mengulangnya '
            .'tiap kali resep diubah membuatnya berhenti dibaca');
    }

    /* ── Modal per porsi ─────────────────────────────────────────────────── */

    /**
     * Satu bahan tanpa harga beli → modal TIDAK dihitung, dan itu disebut.
     *
     * Rp 0 di kolom modal berarti untung 100%, dan pemilik yang membacanya akan menaikkan
     * menu itu justru karena angkanya bohong. Mengaku belum bisa menghitung lebih menolong.
     */
    public function test_modal_tidak_dihitung_kalau_ada_bahan_yang_harga_belinya_kosong(): void
    {
        $menu = $this->buatProduk('Lele Goreng', ['satuan' => Satuan::Porsi]);
        $lele = $this->buatBahan('Lele Segar', ['satuan' => Satuan::Kg, 'harga_beli_terakhir' => 30000]);
        $minyak = $this->buatBahan('Minyak Goreng', ['satuan' => Satuan::Liter, 'harga_beli_terakhir' => null]);

        foreach ([[$lele, 0.25], [$minyak, 0.03]] as [$bahan, $jumlah]) {
            RecipeItem::create([
                'product_id' => $menu->getKey(),
                'raw_material_id' => $bahan->getKey(),
                'jumlah_terpakai' => $jumlah,
            ]);
        }

        $html = Livewire::actingAs($this->owner)->test(LayarResep::class)->html();

        $this->assertStringContainsString('Modal belum bisa dihitung', $html);
        $this->assertStringContainsString('Minyak Goreng', $html);
    }

    public function test_modal_dihitung_kalau_semua_bahan_berharga(): void
    {
        $menu = $this->buatProduk('Lele Goreng', ['satuan' => Satuan::Porsi]);
        $lele = $this->buatBahan('Lele Segar', ['satuan' => Satuan::Kg, 'harga_beli_terakhir' => 30000]);

        RecipeItem::create([
            'product_id' => $menu->getKey(),
            'raw_material_id' => $lele->getKey(),
            'jumlah_terpakai' => 0.25,
        ]);

        $html = Livewire::actingAs($this->owner)->test(LayarResep::class)->html();

        // 0,25 kg × Rp 30.000 = Rp 7.500
        $this->assertStringContainsString('Rp 7.500', $html);
    }

    /* ── Batas akses ─────────────────────────────────────────────────────── */

    /**
     * Menu milik tenant lain tidak bisa dibuka maupun ditulisi.
     *
     * Dipanggil LANGSUNG sebagai aksi Livewire, tanpa lewat layar: itu satu-satunya cara
     * membuktikan gerbangnya ada di server, bukan cuma di daftar yang dirender.
     */
    public function test_menu_tenant_lain_tidak_bisa_diatur(): void
    {
        $lain = $this->buatTenant('Warteg Sebelah');
        $this->buatOutlet($lain, 'Cabang Sebelah');
        $this->konteks()->setTenant($lain->getKey());
        $menuLain = $this->buatProduk('Rendang Sebelah');
        $this->konteks()->setTenant($this->tenant->getKey());

        // findOrFail() di bawah TenantScope tidak menemukannya sama sekali — jadi yang
        // terjadi ModelNotFoundException, bukan balasan HTTP 404. Menuntut assertStatus(404)
        // di sini membuat ujinya menguji kerangka kerja, bukan gerbangnya.
        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($this->owner)
            ->test(LayarResep::class)
            ->call('atur', $menuLain->getKey());
    }

    /* ── Bentuk layar ────────────────────────────────────────────────────── */

    public function test_daftar_menu_sepuluh_baris_per_halaman(): void
    {
        for ($i = 1; $i <= 12; $i++) {
            $this->buatProduk('Menu '.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }

        $komponen = Livewire::actingAs($this->owner)->test(LayarResep::class);

        $this->assertCount(config('nampan.per_halaman'), $komponen->viewData('daftar')->items());
        $this->assertStringContainsString('Menu 01', $komponen->html());
        $this->assertStringNotContainsString('Menu 12', $komponen->html());
    }

    /**
     * Bintang wajib HANYA pada bahan dan jumlah.
     *
     * Keduanya memang ditolak validator kalau kosong. Bintang pada medan yang tidak wajib
     * membuat bintang di seluruh formulir berhenti dipercaya, lalu yang sungguh wajib
     * dilewatkan.
     */
    public function test_bintang_wajib_hanya_pada_bahan_dan_jumlah(): void
    {
        $menu = $this->buatProduk('Lele Goreng');
        $this->buatBahan('Lele Segar', ['satuan' => Satuan::Kg]);

        $html = Livewire::actingAs($this->owner)
            ->test(LayarResep::class)
            ->call('atur', $menu->getKey())
            ->html();

        // <x-wajib /> merender sr-only "(wajib diisi)"; satu untuk bahan, satu untuk jumlah.
        $this->assertSame(2, substr_count($html, '(wajib diisi)'),
            'tepat dua bintang wajib di panel resep: bahan dan jumlah per porsi');
    }

    /** Bahan yang belum ada sama sekali: panelnya menuntun, bukan menampilkan formulir kosong. */
    public function test_panel_menuntun_kalau_belum_ada_bahan_sama_sekali(): void
    {
        $menu = $this->buatProduk('Lele Goreng');

        $this->assertSame(0, RawMaterial::query()->count(), 'pramis: memang belum ada bahan');

        $html = Livewire::actingAs($this->owner)
            ->test(LayarResep::class)
            ->call('atur', $menu->getKey())
            ->html();

        $this->assertStringContainsString('Belum ada bahan mentah sama sekali', $html);
    }

    /** Menu tanpa resep dikatakan apa adanya, bukan dibiarkan kosong. */
    public function test_menu_tanpa_resep_menyebut_stoknya_dihitung_sebagai_barang_jadi(): void
    {
        $this->buatProduk('Kerupuk');

        $html = Livewire::actingAs($this->owner)->test(LayarResep::class)->html();

        $this->assertStringContainsString('dihitung sebagai barang jadi', $html);
    }
}

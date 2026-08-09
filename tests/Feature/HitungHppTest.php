<?php

namespace Tests\Feature;

use App\Actions\Produk\HitungHppAction;
use App\Enums\Satuan;
use App\Enums\UserRole;
use App\Models\Bahan\RawMaterial;
use App\Models\Bahan\RecipeItem;
use App\Models\Produk\Product;
use App\Models\Tenant\Tenant;
use App\Models\Tenant\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * HPP per produk — satu rumus, satu tempat.
 *
 * Angka ini akan dibaca setidaknya lima layar: saran harga jual, margin per produk,
 * peringatan "diskon ini membuat rugi", laporan barang yang rugi, dan titik impas. Kalau
 * tiap layar menghitung sendiri, dua layar akan menjawab berbeda untuk barang yang sama —
 * dan yang diperdebatkan bukan tampilan, melainkan apakah barangnya untung.
 *
 * Yang dijaga paling keras, dan ketiganya keputusan yang mudah diambil salah:
 *
 *  1. Menu berresep dihitung dari BAHANNYA, bukan dari kolom harga_beli menu itu — kolom
 *     itu memang tidak pernah diisi untuk menu masakan, jadi membacanya menghasilkan HPP nol
 *     dan margin 100%.
 *  2. Menu yang SEBAGIAN bahannya belum berharga mengembalikan null, BUKAN jumlah
 *     sebagiannya. Angka yang salah arah dan tidak bersuara lebih buruk daripada tidak ada
 *     angka: yang kedua membuat orang mencari, yang pertama membuatnya tenang.
 *  3. HPP yang tidak diketahui menghasilkan margin null, BUKAN 0%. Margin 0% terbaca
 *     "barang ini tidak untung"; yang benar "belum bisa dihitung".
 */
class HitungHppTest extends TestCase
{
    use MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant('Warteg HPP');
        $this->owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik',
            'email' => 'owner@hpp.test',
            'password' => 'rahasia123',
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());
    }

    private function aksi(): HitungHppAction
    {
        return app(HitungHppAction::class);
    }

    private function buatBarang(string $nama, float $jual, ?float $beli): Product
    {
        return Product::create([
            'nama_produk' => $nama,
            'harga_default' => $jual,
            'harga_beli' => $beli,
            'satuan' => Satuan::Pcs,
        ]);
    }

    private function buatMenu(string $nama, float $jual): Product
    {
        return Product::create([
            'nama_produk' => $nama,
            'harga_default' => $jual,
            'satuan' => Satuan::Porsi,
            // Menu berresep tidak melacak stoknya sendiri — yang dilacak bahan bakunya.
            'lacak_stok' => false,
        ]);
    }

    private function buatBahan(string $nama, Satuan $satuan, ?float $harga): RawMaterial
    {
        return RawMaterial::create([
            'nama' => $nama,
            'satuan' => $satuan,
            'harga_beli_terakhir' => $harga,
        ]);
    }

    private function tambahResep(Product $menu, RawMaterial $bahan, float $jumlah): void
    {
        RecipeItem::create([
            'product_id' => $menu->getKey(),
            'raw_material_id' => $bahan->getKey(),
            'jumlah_terpakai' => $jumlah,
        ]);
    }

    /* ── Barang jadi ─────────────────────────────────────────────────────── */

    #[Test]
    public function barang_jadi_memakai_harga_belinya(): void
    {
        $hasil = $this->aksi()->untuk($this->buatBarang('Teh Kotak', 5000, 3500));

        $this->assertSame(3500.0, $hasil['hpp']);
        $this->assertSame('harga_beli', $hasil['sumber']);
        $this->assertTrue($hasil['lengkap']);
    }

    #[Test]
    public function barang_tanpa_harga_beli_menghasilkan_null_bukan_nol(): void
    {
        /*
         * Nol berarti "gratis", dan margin barang gratis adalah 100% — angka yang terlihat
         * hebat dan membuat pemilik yakin barang itu paling menguntungkan di warungnya.
         * Yang benar: harga belinya memang belum pernah diisi.
         */
        $hasil = $this->aksi()->untuk($this->buatBarang('Teh Kotak', 5000, null));

        $this->assertNull($hasil['hpp']);
        $this->assertSame('tidak_diketahui', $hasil['sumber']);
        $this->assertFalse($hasil['lengkap']);
    }

    /* ── Menu berresep ───────────────────────────────────────────────────── */

    #[Test]
    public function menu_berresep_dihitung_dari_bahannya(): void
    {
        // Lele 0,25 kg x Rp 32.000 = Rp 8.000; minyak 0,05 liter x Rp 20.000 = Rp 1.000.
        $menu = $this->buatMenu('Lele Goreng', 15000);
        $this->tambahResep($menu, $this->buatBahan('Lele Segar', Satuan::Kg, 32000), 0.25);
        $this->tambahResep($menu, $this->buatBahan('Minyak Goreng', Satuan::Liter, 20000), 0.05);

        $hasil = $this->aksi()->untuk($menu);

        $this->assertSame(9000.0, $hasil['hpp']);
        $this->assertSame('resep', $hasil['sumber']);
        $this->assertCount(2, $hasil['rincian']);
    }

    #[Test]
    public function menu_berresep_tidak_membaca_kolom_harga_beli_menunya(): void
    {
        /*
         * Kolom `products.harga_beli` memang tidak pernah diisi untuk menu masakan — tidak
         * ada yang "membeli" satu porsi lele goreng. Kalau aksinya membacanya, HPP menu
         * jadi nol dan setiap menu warteg terlihat bermargin 100%.
         *
         * Diuji dengan MENGISI kolom itu dengan angka yang mustahil: kalau ia terbaca,
         * hasilnya 1 dan bukan 9.000.
         */
        $menu = $this->buatMenu('Lele Goreng', 15000);
        $menu->forceFill(['harga_beli' => 1])->save();

        $this->tambahResep($menu, $this->buatBahan('Lele Segar', Satuan::Kg, 32000), 0.25);
        $this->tambahResep($menu, $this->buatBahan('Minyak Goreng', Satuan::Liter, 20000), 0.05);

        $this->assertSame(9000.0, $this->aksi()->untuk($menu->fresh())['hpp']);
    }

    #[Test]
    public function menu_yang_sebagian_bahannya_belum_berharga_menghasilkan_null(): void
    {
        /*
         * Keputusan yang paling mudah diambil salah. Jumlah sebagian adalah angka yang
         * TERLIHAT sah — Rp 8.000 untuk lele goreng yang bumbunya belum berharga — dan
         * pemilik menyimpulkan marginnya 47%. Angka yang salah arah dan tidak bersuara lebih
         * buruk daripada tidak ada angka.
         */
        $menu = $this->buatMenu('Lele Goreng', 15000);
        $this->tambahResep($menu, $this->buatBahan('Lele Segar', Satuan::Kg, 32000), 0.25);
        $this->tambahResep($menu, $this->buatBahan('Bumbu Racik', Satuan::Gram, null), 20);

        $hasil = $this->aksi()->untuk($menu);

        $this->assertNull($hasil['hpp']);
        $this->assertFalse($hasil['lengkap']);
    }

    #[Test]
    public function bahan_yang_belum_berharga_disebut_namanya(): void
    {
        // "HPP belum lengkap" tanpa nama membuat pemilik membuka satu per satu enam bahan
        // untuk mencari yang mana.
        $menu = $this->buatMenu('Lele Goreng', 15000);
        $this->tambahResep($menu, $this->buatBahan('Lele Segar', Satuan::Kg, 32000), 0.25);
        $this->tambahResep($menu, $this->buatBahan('Bumbu Racik', Satuan::Gram, null), 20);

        $this->assertSame(['Bumbu Racik'], $this->aksi()->untuk($menu)['bahanTanpaHarga']);
    }

    #[Test]
    public function rincian_tetap_dikembalikan_walau_hppnya_belum_bisa_dihitung(): void
    {
        // Penolakan yang tetap menuntun: layar bisa memperlihatkan yang SUDAH terhitung
        // beserta yang belum, jadi pemilik tahu tinggal satu angka lagi yang kurang.
        $menu = $this->buatMenu('Lele Goreng', 15000);
        $this->tambahResep($menu, $this->buatBahan('Lele Segar', Satuan::Kg, 32000), 0.25);
        $this->tambahResep($menu, $this->buatBahan('Bumbu Racik', Satuan::Gram, null), 20);

        $rincian = $this->aksi()->untuk($menu)['rincian'];

        $this->assertCount(2, $rincian);
        $this->assertSame(8000.0, $rincian[0]['subtotal']);
        $this->assertNull($rincian[1]['subtotal']);
    }

    #[Test]
    public function menu_tanpa_satu_pun_baris_resep_dianggap_barang_jadi(): void
    {
        // usesRecipe() false, jadi ia jatuh ke jalur harga beli — dan kolomnya kosong, jadi
        // hasilnya "tidak diketahui". Bukan nol: menu tanpa resep memang belum bisa dihitung.
        $hasil = $this->aksi()->untuk($this->buatMenu('Nasi Putih', 5000));

        $this->assertNull($hasil['hpp']);
        $this->assertSame('tidak_diketahui', $hasil['sumber']);
    }

    /* ── Margin ──────────────────────────────────────────────────────────── */

    #[Test]
    public function margin_dihitung_terhadap_harga_jual_bukan_terhadap_hpp(): void
    {
        /*
         * Dua angka yang sangat sering tertukar: modal 10.000 dijual 15.000 adalah MARGIN
         * 33,3% atau MARKUP 50%. Keduanya benar untuk pertanyaan yang berbeda, dan yang
         * dipakai di sini margin — karena saran harga jual nanti menyusun harga DARI target
         * margin, dan kedua layar tidak boleh memakai rumus yang berbeda.
         */
        $margin = $this->aksi()->margin(10000, 15000);

        $this->assertSame(5000.0, $margin['rupiah']);
        $this->assertSame(33.3, $margin['persen']);
        $this->assertFalse($margin['rugi']);
    }

    #[Test]
    public function hpp_yang_tidak_diketahui_menghasilkan_margin_null_bukan_nol(): void
    {
        // Margin 0% terbaca "barang ini tidak untung sama sekali", dan pemilik menaikkan
        // harga barang yang sebenarnya sudah untung. Yang benar: belum bisa dihitung.
        $margin = $this->aksi()->margin(null, 15000);

        $this->assertNull($margin['rupiah']);
        $this->assertNull($margin['persen']);
        $this->assertFalse($margin['rugi'], 'yang belum diketahui bukan berarti rugi');
    }

    #[Test]
    public function harga_jual_di_bawah_hpp_ditandai_rugi(): void
    {
        /*
         * Keadaan yang benar-benar terjadi: harga bahan naik dan harga menu tidak ikut
         * disesuaikan berbulan-bulan. Tanpa penanda ini, tidak ada satu pun angka di
         * aplikasi yang akan terlihat aneh.
         */
        $margin = $this->aksi()->margin(18000, 15000);

        $this->assertSame(-3000.0, $margin['rupiah']);
        $this->assertTrue($margin['rugi']);
    }

    #[Test]
    public function harga_jual_nol_tidak_membuat_pembagian_nol(): void
    {
        // Produk berharga nol memang ada (bonus, tester). Yang dijaga: layarnya tidak mati.
        $margin = $this->aksi()->margin(5000, 0);

        $this->assertSame(-5000.0, $margin['rupiah']);
        $this->assertNull($margin['persen']);
        $this->assertTrue($margin['rugi']);
    }

    /* ── Banyak sekaligus ────────────────────────────────────────────────── */

    #[Test]
    public function untuk_banyak_mengembalikan_hasil_berkunci_id_produk(): void
    {
        $teh = $this->buatBarang('Teh Kotak', 5000, 3500);
        $kopi = $this->buatBarang('Kopi Sachet', 3000, 2000);

        $hasil = $this->aksi()->untukBanyak([$teh, $kopi]);

        $this->assertSame(3500.0, $hasil[$teh->getKey()]['hpp']);
        $this->assertSame(2000.0, $hasil[$kopi->getKey()]['hpp']);
    }

    #[Test]
    public function resep_yang_sudah_di_eager_load_tidak_dibaca_ulang_dari_basis_data(): void
    {
        /*
         * Daftar produk memuat resepnya sekaligus. Kalau aksi ini tetap menembakkan kueri
         * sendiri per baris, daftar 300 produk jadi 600 kueri — dan halaman yang lambat
         * membuat pemilik berhenti membukanya, jadi seluruh angka margin ini tidak pernah
         * dilihat siapa pun.
         *
         * Dibuktikan dengan menghitung kueri, bukan dengan membaca kode.
         */
        $menu = $this->buatMenu('Lele Goreng', 15000);
        $this->tambahResep($menu, $this->buatBahan('Lele Segar', Satuan::Kg, 32000), 0.25);

        $termuat = Product::with('recipeItems.rawMaterial')->findOrFail($menu->getKey());

        DB::enableQueryLog();
        $hasil = $this->aksi()->untuk($termuat);
        $jumlahKueri = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(8000.0, $hasil['hpp']);
        $this->assertSame(0, $jumlahKueri, 'resep yang sudah dimuat tidak boleh dibaca ulang');
    }
}

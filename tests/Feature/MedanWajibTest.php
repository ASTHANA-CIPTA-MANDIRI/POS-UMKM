<?php

namespace Tests\Feature;

use App\Enums\Satuan;
use App\Enums\UserRole;
use App\Livewire\Pages\Owner\Pembelian\PembelianBaru;
use App\Livewire\Pages\Owner\Stok\Stok as LayarStok;
use App\Models\Produk\Product;
use App\Models\Stok\Stock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Bintang merah HANYA pada medan yang validatornya `required`.
 *
 * Uji ini menjaga dua arah sekaligus, dan arah kedualah yang mudah dilupakan: bintang yang
 * KURANG membuat orang ditolak saat menekan simpan tanpa tahu sebabnya, tapi bintang yang
 * BERLEBIH lebih merusak — orang mengisi hal yang tidak perlu, dan sesudah dua kali begitu ia
 * berhenti memercayai bintangnya, lalu melewatkan yang sungguh wajib.
 *
 * Diperiksa atas HTML terender, bukan atas daftar aturan validasi: yang menyesatkan orang
 * adalah apa yang sampai ke layar.
 */
class MedanWajibTest extends TestCase
{
    use MembuatDataUji, RefreshDatabase;

    private function halamanPembelian(): string
    {
        $tenant = $this->buatTenant('Toko Wajib');
        $outlet = $this->buatOutlet($tenant, 'Outlet Wajib');
        $owner = $this->buatUser($tenant, UserRole::Owner, [
            'name' => 'Pemilik', 'email' => 'o@wajib.test', 'password' => 'rahasia123',
        ]);
        $this->konteks()->setTenant($tenant->getKey());

        $produk = Product::create([
            'nama_produk' => 'Beras Wajib', 'harga_default' => 12000, 'satuan' => Satuan::Kg,
        ]);
        Stock::create([
            'outlet_id' => $outlet->getKey(), 'product_id' => $produk->getKey(),
            'jumlah_saat_ini' => 10, 'stok_minimum' => 0,
        ]);

        return Livewire::actingAs($owner)->test(PembelianBaru::class)->html();
    }

    /** Label + bintang harus berdampingan; potongan HTML di antaranya diabaikan. */
    private function berbintang(string $html, string $label): bool
    {
        return preg_match(
            '/'.preg_quote($label, '/').'(?:(?!<\/label>|<\/th>).){0,400}?text-merah-tua/s',
            $html,
        ) === 1;
    }

    public function test_tanggal_nota_dan_harga_beli_berbintang(): void
    {
        $html = $this->halamanPembelian();

        $this->assertTrue($this->berbintang($html, 'Tanggal nota'),
            'tanggal nota ber-required di validator, jadi wajib berbintang');
        $this->assertTrue($this->berbintang($html, 'Harga beli'),
            'harga beli ber-required begitu barisnya terisi');
    }

    /**
     * Arah yang paling mudah dilupakan: medan `nullable` TIDAK boleh berbintang.
     */
    public function test_medan_opsional_tidak_berbintang(): void
    {
        $html = $this->halamanPembelian();

        foreach (['Beli dari', 'Ongkos kirim', 'Catatan', 'Diskon'] as $label) {
            $this->assertFalse($this->berbintang($html, $label),
                "'{$label}' nullable di validator — bintang di situ membuat orang mengisi "
                .'hal yang tidak perlu, dan bintangnya berhenti dipercaya');
        }
    }

    /**
     * Layar Stok tidak punya medan wajib sama sekali (`ambangNilai` nullable), jadi tidak
     * boleh ada satu bintang pun di sana.
     */
    public function test_layar_stok_tidak_punya_bintang_wajib(): void
    {
        $tenant = $this->buatTenant('Toko Stok Wajib');
        $this->buatOutlet($tenant, 'Outlet');
        $owner = $this->buatUser($tenant, UserRole::Owner, [
            'name' => 'P', 'email' => 'p@stokwajib.test', 'password' => 'rahasia123',
        ]);
        $this->konteks()->setTenant($tenant->getKey());
        Product::create(['nama_produk' => 'Gula', 'harga_default' => 1000, 'satuan' => Satuan::Kg]);

        $html = Livewire::actingAs($owner)->test(LayarStok::class)->html();

        $this->assertStringNotContainsString('text-merah-tua', $html,
            'batas minimal nullable — tidak ada medan wajib di layar Stok');
    }

    /**
     * Bintang wajib membawa artinya untuk pembaca layar.
     *
     * Bintang telanjang dibacakan "asterisk" atau dilewati; orang yang memakai pembaca layar
     * justru paling butuh tahu medan mana yang menahan tombol simpan.
     */
    public function test_bintang_terbaca_pembaca_layar(): void
    {
        $html = $this->halamanPembelian();

        $this->assertStringContainsString('(wajib diisi)', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
    }
}

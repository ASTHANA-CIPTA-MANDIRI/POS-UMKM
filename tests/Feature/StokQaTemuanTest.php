<?php

namespace Tests\Feature;

use App\Enums\Satuan;
use App\Enums\UserRole;
use App\Livewire\Pages\Owner\Produk\Produk as LayarProduk;
use App\Livewire\Pages\Owner\Stok\Stok as LayarStok;
use App\Models\Produk\Product;
use App\Models\Stok\Stock;
use App\Models\Tenant\Outlet;
use App\Models\Tenant\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Temuan QA layar Stok (lihat laporan QA). Tiga uji di sini SENGAJA gagal pada kode
 * sekarang — masing-masing membuktikan satu cacat nyata, bukan dugaan.
 */
class StokQaTemuanTest extends TestCase
{
    use MembuatDataUji, RefreshDatabase;

    /**
     * CACAT: Stock::kekuranganDalamSatuanBeli() memakai `ceil($kekurangan / $isi)` dengan
     * float mentah. 2.1 / 0.7 SEHARUSNYA persis 3, tapi representasi float PHP menghasilkan
     * 3.00000000000000044409 — dan ceil() membulatkannya jadi 4. Blok "Harus belanja"
     * lalu menyuruh pemilik membeli SATU dus lebih banyak daripada yang sebenarnya kurang.
     * Ini bukan kasus langka: isi_per_satuan desimal (0.5 liter, 0.7 kg, dll) sudah cukup
     * untuk memicunya pada kombinasi angka tertentu.
     */
    public function test_pembulatan_satuan_beli_salah_karena_presisi_float(): void
    {
        $tenant = $this->buatTenant('Toko Float');
        $outlet = $this->buatOutlet($tenant, 'Outlet Float');
        $this->konteks()->setTenant($tenant->getKey());

        $produk = Product::create([
            'nama_produk' => 'Barang Isi 0,7',
            'harga_default' => 1000,
            'satuan' => Satuan::Dus,
            'satuan_dasar' => Satuan::Pcs,
            'isi_per_satuan' => 0.7,
        ]);

        $stok = Stock::create([
            'outlet_id' => $outlet->getKey(),
            'product_id' => $produk->getKey(),
            'jumlah_saat_ini' => 0,
            'stok_minimum' => 2.1, // kekurangan = 2.1; 2.1 / 0.7 = tepat 3 secara matematis
        ]);

        $beli = $stok->kekuranganDalamSatuanBeli();

        $this->assertSame(3.0, $beli['jumlah'],
            'BUG app/Models/Stock.php:151 — ceil(2.1/0.7) memakai float mentah, hasilnya 4 bukan 3 karena presisi biner (2.1/0.7 = 3.00000000000000044409 di PHP).');
    }

    /**
     * CACAT: `Produk::outletStokTerpakai()` (app/Livewire/Pages/Owner/Produk/Produk.php) tidak
     * memanggil `canAccessOutlet()` sama sekali — beda dengan `Stok::outletTerpakai()` yang
     * memanggil `abort_unless($user->canAccessOutlet(...), 403)` untuk syarat yang SAMA.
     * Akibatnya Regional Manager yang hanya ditugaskan ke Outlet A bisa menyetel properti
     * publik `outletStok` ke Outlet B (outlet lain di tenant yang sama, tapi bukan
     * tanggung jawabnya) dan kolom stok + hitungan "menipis" di layar produk memakai data
     * Outlet B — persis kebocoran lintas-outlet yang gerbang di Stok.php dirancang mencegah.
     */
    public function test_regional_manager_bisa_melihat_stok_outlet_yang_bukan_tanggung_jawabnya_di_layar_produk(): void
    {
        $tenant = $this->buatTenant('Toko RM');
        $outletA = $this->buatOutlet($tenant, 'Outlet A');
        $outletB = $this->buatOutlet($tenant, 'Outlet B'); // TIDAK ditugaskan ke RM

        $rm = $this->buatUser($tenant, UserRole::RegionalManager, [
            'name' => 'RM Uji',
            'email' => 'rm@ujitenant.test',
            'password' => 'rahasia123',
        ]);
        $rm->outlets()->attach($outletA->getKey());

        $this->konteks()->setTenant($tenant->getKey());

        $this->assertFalse($rm->canAccessOutlet($outletB->getKey()), 'pembanding: RM memang tidak berhak atas outlet B');

        $komponen = Livewire::actingAs($rm)->test(LayarProduk::class);
        $komponen->set('outletStok', $outletB->getKey());

        $dipakai = $komponen->instance()->outletStokTerpakai();

        $this->assertNotSame($outletB->getKey(), $dipakai,
            'BUG app/Livewire/Pages/Owner/Produk/Produk.php outletStokTerpakai() — tidak menggerbang canAccessOutlet() seperti Stok.php::outletTerpakai(), sehingga RM bisa memaksa kolom stok/menipis memakai outlet yang bukan haknya.');
    }

    /**
     * CACAT: barang yang BELUM PERNAH dicatat (tidak ada baris `stocks` di outlet ini
     * sama sekali — punya_baris=false) diberi status 'habis' yang SAMA dengan barang yang
     * confirmed kosong (saldo 0 dari opname sungguhan). Kolom "Sistem" sudah benar
     * membedakannya ("— belum dihitung" vs angka), tapi lencana status di sebelahnya tetap
     * merah "Habis" — mengklaim raknya pasti kosong padahal aplikasi belum pernah tahu.
     * Baris itu juga ikut masuk hitungan ringkasan "Habis" dan saringan status=habis,
     * mencampur barang yang confirmed kosong dengan barang yang sekadar belum diopname.
     */
    public function test_barang_belum_pernah_dicatat_diberi_status_habis_bukan_dibedakan(): void
    {
        $tenant = $this->buatTenant('Toko Belum Dicatat');
        $this->buatOutlet($tenant, 'Outlet Belum Dicatat');
        $owner = $this->buatUser($tenant, UserRole::Owner, [
            'name' => 'Owner Belum Dicatat',
            'email' => 'owner@belumdicatat.test',
            'password' => 'rahasia123',
        ]);
        $this->konteks()->setTenant($tenant->getKey());

        Product::create([
            'nama_produk' => 'Barang Baru Belum Diopname',
            'harga_default' => 1000,
            'satuan' => Satuan::Pcs,
        ]);
        // Sengaja TIDAK ada Stock::create() untuk produk ini di outlet ini.

        $baris = Livewire::actingAs($owner)->test(LayarStok::class)->viewData('daftar')->items()[0];

        $this->assertFalse($baris['punya_baris'], 'pembanding: barang ini memang belum pernah dicatat');

        $this->assertNotSame('habis', $baris['status'],
            'BUG app/Actions/Stock/SusunBarisStokAction.php:154-157 (stokSementara) — barang tanpa baris stocks dipetakan ke jumlah=0 sebelum dikirim ke Stock::statusStok(), jadi statusnya "habis" persis seperti barang confirmed kosong. Badge merah "Habis" tampil di resources/views/livewire/pages/owner/stok/stok.blade.php untuk barang yang belum pernah diopname sama sekali, dan baris itu ikut masuk hitungan ringkasan "Habis".');
    }
}

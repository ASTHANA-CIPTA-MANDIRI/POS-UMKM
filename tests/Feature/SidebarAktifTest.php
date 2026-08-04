<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Penanda menu samping harus menyala di SUB-rute juga.
 *
 * Lahir dari cacat nyata: membuka lembar opname (`owner.stok.opname`) membuat seluruh menu
 * mati, karena penandanya memakai `routeIs('owner.stok')` yang tidak cocok dengan nama
 * rute anaknya. Akibatnya orang kehilangan petunjuk sedang berada di mana — paling terasa
 * di halaman kerja panjang yang butuh belasan kali pindah halaman.
 *
 * Diuji atas HTML terender, bukan atas daftar rutenya: yang salah dulu bukan rutenya,
 * melainkan pembandingnya di Blade.
 */
class SidebarAktifTest extends TestCase
{
    use MembuatDataUji, RefreshDatabase;

    private int $nomor = 0;

    private function bukaSebagaiOwner(string $rute): string
    {
        // Nomor urut supaya satu uji bisa membuka beberapa halaman: tiap pemanggilan
        // membuat tenant & pemilik sendiri, dan email pemilik harus unik.
        $this->nomor++;

        $tenant = $this->buatTenant('Toko Sidebar '.$this->nomor);
        $this->buatOutlet($tenant, 'Outlet Sidebar');

        $owner = $this->buatUser($tenant, UserRole::Owner, [
            'name' => 'Pemilik Sidebar',
            'email' => 'owner'.$this->nomor.'@sidebar.test',
            'password' => 'rahasia123',
        ]);

        $this->konteks()->setTenant($tenant->getKey());

        return $this->actingAs($owner)->get(route($rute))->assertOk()->getContent();
    }

    /** Menghitung berapa item menu yang bertanda aktif. */
    private function jumlahAktif(string $html): int
    {
        return substr_count($html, 'aria-current="page"');
    }

    public function test_menu_stok_menyala_di_halaman_daftar_stok(): void
    {
        $this->assertSame(1, $this->jumlahAktif($this->bukaSebagaiOwner('owner.stok')));
    }

    public function test_menu_stok_tetap_menyala_di_lembar_opname(): void
    {
        $html = $this->bukaSebagaiOwner('owner.stok.opname');

        $this->assertSame(1, $this->jumlahAktif($html),
            'lembar opname adalah sub-rute owner.stok — menunya harus tetap menyala, '.
            'dan HANYA satu item yang boleh aktif');
    }

    /**
     * Tepat satu menu aktif di setiap halaman.
     *
     * Dua penanda "Anda di sini" sama membingungkannya dengan tidak ada penanda.
     *
     * Batas uji ini, supaya tidak dipercaya lebih dari semestinya: ia BELUM bisa menangkap
     * pembanding yang diperlonggar jadi awalan tanpa titik (`$rute.'*'`). Saya mencobanya —
     * ujinya tetap hijau, karena hari ini tidak ada dua item menu yang nama rutenya
     * berawalan sama, jadi tidak ada yang bisa tersulut ganda. Penjagaannya baru berarti
     * begitu ada menu seperti itu; sampai saat itu, titik di `.*` adalah kehati-hatian yang
     * belum berpenjaga.
     */
    public function test_hanya_satu_menu_aktif_di_setiap_halaman(): void
    {
        foreach (['owner.dasbor', 'owner.produk', 'owner.stok', 'owner.stok.opname'] as $rute) {
            if (! Route::has($rute)) {
                continue;
            }

            $this->assertSame(1, $this->jumlahAktif($this->bukaSebagaiOwner($rute)),
                "tepat satu menu harus aktif di {$rute}");
        }
    }
}

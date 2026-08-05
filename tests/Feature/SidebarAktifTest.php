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
        foreach ([
            'owner.dasbor',
            'owner.produk',
            'owner.stok',
            'owner.stok.opname',
            // Pembelian punya DUA rute (daftar + nota baru) justru supaya pola `.*` di
            // sidebar menyalakan induknya saat notanya sedang diketik. Kalau nama rute
            // formulirnya suatu hari diubah jadi berdiri sendiri, layar itu akan mematikan
            // seluruh menu dan penggunanya kehilangan petunjuk sedang berada di mana.
            'owner.pembelian',
            'owner.pembelian.baru',
        ] as $rute) {
            if (! Route::has($rute)) {
                continue;
            }

            $this->assertSame(1, $this->jumlahAktif($this->bukaSebagaiOwner($rute)),
                "tepat satu menu harus aktif di {$rute}");
        }
    }

    /* ── Judul halaman di navbar ─────────────────────────────────────────── */

    /**
     * Judul navbar tidak boleh membocorkan kata akuntansi yang dilarang tampil.
     *
     * Cacat nyata yang ditemukan agen frontend: rute `owner.stok.opname` belum terpeta di
     * `topbar.blade.php`, jadi judulnya jatuh ke cadangan "nama rute sesudah titik terakhir"
     * dan tercetak **"Opname"** — kata yang seluruh isi layarnya sudah dihindari
     * (CLAUDE.md, bagian "Bahasa layar"). Cadangan itu berguna supaya halaman baru tidak
     * tampil tanpa judul, tapi ia mengambil nama TEKNIS rutenya, dan nama rute tidak wajib
     * memakai bahasa pengguna.
     *
     * Diuji atas HTML terender, bukan atas isi $peta: yang salah bukan petanya, melainkan
     * apa yang sampai ke layar.
     */
    public function test_judul_navbar_tidak_memakai_kata_akuntansi(): void
    {
        foreach ([
            'owner.stok.opname' => 'Hitung stok',
            'owner.pembelian' => 'Nota belanja',
            'owner.pembelian.baru' => 'Catat nota',
        ] as $rute => $judulSeharusnya) {
            if (! Route::has($rute)) {
                continue;
            }

            $html = $this->bukaSebagaiOwner($rute);

            $this->assertStringContainsString($judulSeharusnya, $html,
                "judul halaman {$rute} harus '{$judulSeharusnya}'");

            foreach (['Opname', 'Purchase', 'Supplier', 'Draft'] as $terlarang) {
                $this->assertStringNotContainsString($terlarang, $html,
                    "kata '{$terlarang}' tidak boleh tampil di {$rute} — lihat CLAUDE.md, Bahasa layar");
            }
        }
    }
}

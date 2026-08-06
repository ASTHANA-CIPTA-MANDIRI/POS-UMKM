<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\Pages\Owner\Pembelian;
use App\Models\Outlet;
use App\Models\Supplier;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MembuatDataPembelian;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Daftar nota belanja & panel rincian isinya.
 *
 * Yang dijaga di sini adalah aturan paginasi bersama (CLAUDE.md): sepuluh baris per
 * halaman dari setelan terpusat, DAN daftar di dalam panel memakai penunjuk halamannya
 * sendiri yang direset saat panelnya dibuka.
 *
 * Kenapa penunjuknya harus sendiri: kalau rincian ikut `page`, membuka rincian sebuah nota
 * saat daftar sedang di halaman 3 akan melompatkan daftarnya — dua daftar berbeda memakai
 * satu penunjuk. Dan kalau tidak direset saat dibuka, halaman 3 dari nota sebelumnya
 * terbawa ke nota yang isinya dua baris: panelnya terbuka KOSONG dan terbaca sebagai "nota
 * ini tidak ada isinya", padahal ada.
 */
class PembelianDaftarTest extends TestCase
{
    use MembuatDataPembelian, MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $outlet;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant('Toko Daftar Nota');
        $this->outlet = $this->buatOutlet($this->tenant, 'Cabang Daftar');
        $this->owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik Daftar',
            'email' => 'owner@daftarnota.test',
            'password' => 'rahasia123',
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());
    }

    public function test_daftar_nota_memakai_setelan_baris_per_halaman_bersama(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        for ($i = 0; $i < 12; $i++) {
            $this->catatNota($this->outlet, $this->owner, ['baris' => [$this->baris($kopi, 1, 1500)]]);
        }

        $daftar = Livewire::actingAs($this->owner)->test(Pembelian::class)->viewData('daftar');

        $this->assertSame((int) config('nampan.per_halaman'), $daftar->perPage());
        $this->assertSame(12, $daftar->total());
    }

    public function test_rincian_nota_punya_penunjuk_halaman_sendiri_dan_mulai_dari_halaman_satu(): void
    {
        $barang = [];

        for ($i = 1; $i <= 14; $i++) {
            $barang[] = $this->buatProduk('Barang '.str_pad((string) $i, 2, '0', STR_PAD_LEFT));
        }

        $notaPanjang = $this->catatNota($this->outlet, $this->owner, [
            'baris' => collect($barang)->map(fn ($b) => $this->baris($b, 1, 1000))->all(),
        ]);

        $notaPendek = $this->catatNota($this->outlet, $this->owner, [
            'baris' => [$this->baris($barang[0], 1, 1000)],
        ]);

        $komponen = Livewire::actingAs($this->owner)
            ->test(Pembelian::class)
            ->call('bukaRincian', $notaPanjang->getKey());

        $rincian = $komponen->viewData('barisRincian');

        $this->assertSame((int) config('nampan.per_halaman'), $rincian->perPage());
        $this->assertSame(14, $rincian->total(), 'seluruh isi nota terbaca, tidak dipotong diam-diam');
        $this->assertSame('baris', $rincian->getPageName(), 'penunjuk halamannya sendiri, bukan "page"');

        // Pindah ke halaman 2 rincian, lalu buka nota lain yang isinya cuma satu baris.
        $komponen->call('gotoPage', 2, 'baris');
        $this->assertSame(2, $komponen->viewData('barisRincian')->currentPage());

        $komponen->call('bukaRincian', $notaPendek->getKey());

        $this->assertSame(1, $komponen->viewData('barisRincian')->currentPage(),
            'panel harus terbuka di halaman 1; kalau tidak, ia tampil kosong dan terbaca sebagai "tidak ada isinya"');
        $this->assertSame(1, $komponen->viewData('barisRincian')->total());
    }

    /** Panel yang ditutup tidak menyisakan kueri rincian sama sekali. */
    public function test_menutup_rincian_mengosongkan_panelnya(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');
        $nota = $this->catatNota($this->outlet, $this->owner, ['baris' => [$this->baris($kopi, 1, 1500)]]);

        $komponen = Livewire::actingAs($this->owner)
            ->test(Pembelian::class)
            ->call('bukaRincian', $nota->getKey());

        $this->assertNotNull($komponen->viewData('notaRincian'));

        $komponen->call('tutupRincian');

        $this->assertNull($komponen->viewData('notaRincian'));
        $this->assertCount(0, $komponen->viewData('barisRincian'));
    }

    /** Pencarian mengenali nomor nota DAN nama pemasok — dua pegangan yang benar-benar dipakai. */
    public function test_pencarian_mengenali_nomor_nota_dan_nama_pemasok(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        $amanah = $this->catatNota($this->outlet, $this->owner, [
            'beli_dari' => 'Grosir Amanah',
            'baris' => [$this->baris($kopi, 1, 1500)],
        ]);

        $berkah = $this->catatNota($this->outlet, $this->owner, [
            'beli_dari' => 'Toko Berkah',
            'baris' => [$this->baris($kopi, 1, 1500)],
        ]);

        $komponen = Livewire::actingAs($this->owner)->test(Pembelian::class);

        $komponen->set('cari', 'Amanah');
        $this->assertSame([$amanah->getKey()], collect($komponen->viewData('daftar')->items())->pluck('id')->all());

        $komponen->set('cari', $berkah->nomor_po);
        $this->assertSame([$berkah->getKey()], collect($komponen->viewData('daftar')->items())->pluck('id')->all());
    }

    /**
     * Nama pemasok yang sama tidak melahirkan baris pemasok kembar, apa pun besar-kecil
     * hurufnya — daftar sarannya jadi tidak berisi "Grosir Amanah" tiga kali.
     */
    public function test_nama_pemasok_yang_sama_tidak_melahirkan_baris_kembar(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        $satu = $this->catatNota($this->outlet, $this->owner, [
            'beli_dari' => 'Grosir Amanah',
            'baris' => [$this->baris($kopi, 1, 1500)],
        ]);

        $dua = $this->catatNota($this->outlet, $this->owner, [
            'beli_dari' => 'grosir amanah',
            'baris' => [$this->baris($kopi, 1, 1500)],
        ]);

        $this->assertSame($satu->supplier_id, $dua->supplier_id);
        $this->assertSame(1, Supplier::query()->count());
    }

    /* ── Nota yang barangnya belum datang ────────────────────────────────── */

    /**
     * Saringan "Belum datang" — pertanyaan yang dibawa pemilik: apa yang masih saya tunggu.
     *
     * Tanpa saringan ini, nota menggantung tenggelam di antara nota biasa yang jumlahnya jauh
     * lebih banyak, dan barang yang tidak pernah datang tidak pernah ditanyakan ke grosirnya.
     */
    public function test_saringan_belum_datang_hanya_menampilkan_nota_yang_belum_datang(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        $sudah = $this->catatNota($this->outlet, $this->owner, ['baris' => [$this->baris($kopi, 5, 1500)]]);
        $belum = $this->catatNotaBelumDatang($this->outlet, $this->owner, ['baris' => [$this->baris($kopi, 5, 1500)]]);

        $komponen = Livewire::actingAs($this->owner)->test(Pembelian::class);

        $idDi = fn ($daftar) => collect($daftar->items())->pluck('id')->all();

        $this->assertCount(2, $idDi($komponen->viewData('daftar')), 'keduanya tetap terbaca di "semua"');

        $komponen->set('status', 'belum');
        $this->assertSame([$belum->getKey()], $idDi($komponen->viewData('daftar')));

        $komponen->set('status', 'diterima');
        $this->assertSame([$sudah->getKey()], $idDi($komponen->viewData('daftar')));
    }

    /**
     * "Belanja bulan ini" HANYA menghitung nota yang barangnya sudah datang.
     *
     * Uang untuk barang yang belum ada tidak boleh berbaur dengan uang yang sudah jadi barang:
     * yang pertama masih bisa hangus atau berubah jumlahnya, yang kedua sudah ada di rak. Satu
     * angka yang mencampur keduanya tidak bisa dipakai memutuskan apa pun — dan pemilik yang
     * melihatnya melompat pada hari ia mencatat PESANAN menyimpulkan uangnya sudah keluar.
     */
    public function test_kartu_belanja_bulan_ini_tidak_memuat_nota_yang_belum_datang(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        $this->catatNota($this->outlet, $this->owner, ['baris' => [$this->baris($kopi, 100, 1500)]]);
        $this->catatNotaBelumDatang($this->outlet, $this->owner, ['baris' => [$this->baris($kopi, 200, 1500)]]);

        $ringkasan = Livewire::actingAs($this->owner)->test(Pembelian::class)->viewData('ringkasan');

        $this->assertEqualsWithDelta(150000.0, $ringkasan['belanja'], 0.01,
            'hanya 150.000 yang sudah jadi barang; 300.000 lainnya masih di grosir');
        $this->assertSame(1, $ringkasan['nota'], 'jumlah notanya menghitung himpunan yang sama dengan uangnya');

        $this->assertEqualsWithDelta(300000.0, $ringkasan['menunggu']['nilai'], 0.01,
            'uang yang ditunggu tetap terbaca — di kartunya sendiri, bukan bercampur');
        $this->assertSame(1, $ringkasan['menunggu']['nota']);
    }

    /**
     * Kartu "Menunggu datang" menyebut UMUR nota tertua, dan tidak dibatasi bulan ini.
     *
     * Nota yang barangnya belum sampai sejak bulan lalu justru yang paling perlu ditanyakan ke
     * grosirnya, jadi membatasi kartu ini ke bulan berjalan akan menyembunyikan tepat kasus
     * yang paling merugikan. Dan "menunggu 19 hari" adalah pertanyaan, sedangkan "3 nota
     * menunggu" cuma angka.
     */
    public function test_kartu_menunggu_datang_menyebut_umur_nota_tertua(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        // 19 hari lalu — sengaja bisa jatuh di bulan sebelumnya tergantung tanggal hari ini.
        $tertua = now()->subDays(19)->startOfDay();

        $this->catatNotaBelumDatang($this->outlet, $this->owner, [
            'tanggal' => $tertua->toDateString(),
            'baris' => [$this->baris($kopi, 10, 1500)],
        ]);

        $this->catatNotaBelumDatang($this->outlet, $this->owner, [
            'tanggal' => now()->subDays(2)->toDateString(),
            'baris' => [$this->baris($kopi, 10, 1500)],
        ]);

        $menunggu = Livewire::actingAs($this->owner)->test(Pembelian::class)->viewData('ringkasan')['menunggu'];

        $this->assertSame(2, $menunggu['nota']);
        $this->assertSame(19, $menunggu['umur_hari'], 'umurnya dari nota TERTUA, bukan dari yang terbaru');
        $this->assertSame($tertua->toDateString(), $menunggu['tertua']->toDateString());

        // Nota yang sudah ditandai datang keluar dari kartu ini seluruhnya.
        $this->catatNota($this->outlet, $this->owner, ['baris' => [$this->baris($kopi, 10, 1500)]]);

        $lagi = Livewire::actingAs($this->owner)->test(Pembelian::class)->viewData('ringkasan')['menunggu'];

        $this->assertSame(2, $lagi['nota']);
    }

    /** "Beli dari" boleh dikosongkan: belanja di pasar tidak selalu punya nama toko. */
    public function test_beli_dari_boleh_dikosongkan(): void
    {
        $kopi = $this->buatProduk('Kopi Sachet');

        $nota = $this->catatNota($this->outlet, $this->owner, [
            'beli_dari' => '   ',
            'baris' => [$this->baris($kopi, 1, 1500)],
        ]);

        $this->assertNull($nota->supplier_id);
        $this->assertSame(0, Supplier::query()->count());
    }
}

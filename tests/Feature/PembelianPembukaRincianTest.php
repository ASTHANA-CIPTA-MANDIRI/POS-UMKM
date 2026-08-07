<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Livewire\Pages\Owner\Pembelian\Pembelian;
use App\Models\Pembelian\PurchaseOrder;
use App\Models\Tenant\Outlet;
use App\Models\Tenant\Tenant;
use App\Models\Tenant\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\MembuatDataPembelian;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Penanda "nota ini bisa dibuka" di daftar nota belanja.
 *
 * Cacat yang melahirkan berkas ini, dan kalimat pemilik proyeknya hampir apa adanya:
 * "bagaimana owner bisa tahu kalau PB-20260801-002 di pembelian bisa diklik kalau tidak
 * ada ikonnya?" Bentuk lama nomor notanya hanya teks berwarna di dalam sebuah <button>
 * tanpa garis, tanpa latar, tanpa ikon — satu-satunya petunjuk adalah warnanya, dan warna
 * saja tidak pernah cukup untuk membedakan teks berwarna dari tindakan.
 *
 * Yang dijaga di sini ada empat, dan tiap satunya sudah pernah menggigit di repo ini:
 *
 * 1. Penandanya SELALU TERLIHAT, tidak menunggu hover. Layar owner dipakai di tablet dan
 *    HP; di sana hover TIDAK ADA. Cacat yang sama pernah terjadi pada tombol hapus yang
 *    kelabu sampai disorot: tanda bahayanya tidak pernah muncul justru di perangkat yang
 *    paling mungkin salah-tekan.
 * 2. `aria-label` MENYEBUT nomor notanya. Sepuluh baris berbunyi "Lihat isi nota" identik
 *    membuat pembaca layar tidak tahu ia sedang berdiri di nota yang mana.
 * 3. Pembukanya elemen yang bisa DIFOKUS Tab — <button>, bukan <div>/<tr wire:click>.
 *    Baris tabel yang bisa diklik tapi tidak bisa dijangkau papan tuts berarti layar ini
 *    hanya bisa dipakai dengan jempol atau tetikus.
 * 4. Bentuknya SAMA di kedua tata letak (kartu <lg dan tabel ≥lg). Satu layar dengan dua
 *    bentuk untuk tindakan yang sama membuat orang belajar dua kali.
 */
class PembelianPembukaRincianTest extends TestCase
{
    use MembuatDataPembelian, MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $outlet;

    private User $owner;

    private PurchaseOrder $nota;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant('Toko Penanda Nota');
        $this->outlet = $this->buatOutlet($this->tenant, 'Cabang Penanda');
        $this->owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik Penanda',
            'email' => 'owner@penandanota.test',
            'password' => 'rahasia123',
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());

        $kopi = $this->buatProduk('Kopi Sachet');
        $this->nota = $this->catatNota($this->outlet, $this->owner, [
            'beli_dari' => 'Grosir Amanah',
            'baris' => [$this->baris($kopi, 3, 1500)],
        ]);
    }

    /**
     * Setiap pembuka rincian: <button>, ber-aria-label bernomor nota, dan berikon.
     *
     * Diperiksa dari HTML yang benar-benar dirender, bukan dari sumber Blade-nya: yang
     * menentukan bagi pengguna adalah apa yang sampai ke peramban.
     */
    public function test_pembuka_rincian_selalu_berupa_tombol_berikon_dengan_aria_label_bernomor_nota(): void
    {
        $html = Livewire::actingAs($this->owner)->test(Pembelian::class)->html();

        $pembuka = $this->pembukaRincian($html);

        // Dua: satu di kartu (<lg), satu di tabel (≥lg) — bentuknya harus ada di keduanya.
        $this->assertCount(2, $pembuka,
            'pembuka rincian harus ada di KEDUA tata letak: kartu <lg dan tabel ≥lg');

        foreach ($pembuka as $tombol) {
            $this->assertStringContainsString($this->nota->nomor_po, $tombol['pembuka'],
                'aria-label wajib menyebut nomor notanya; "Lihat isi nota" sepuluh kali tidak '
                .'memberi tahu pembaca layar ia sedang di nota yang mana');

            $this->assertMatchesRegularExpression('/aria-label="[^"]*'.preg_quote($this->nota->nomor_po, '/').'/', $tombol['pembuka'],
                'nomor notanya harus berada di dalam aria-label, bukan sekadar di tempat lain pada tombolnya');

            $this->assertStringContainsString('<svg', $tombol['isi'],
                'penandanya harus berupa ikon yang ikut dirender, bukan hanya warna teks');

            $this->assertStringContainsString('tombol-ikon', $tombol['isi'],
                'ikonnya memakai wadah .tombol-ikon yang sudah ada — bukan gaya ketiga yang '
                .'dikarang khusus untuk layar ini');
        }
    }

    /**
     * Penandanya tidak boleh bergantung hover ATAU fokus.
     *
     * Di tablet dan HP keduanya tidak pernah terjadi sampai orangnya sudah menekan — jadi
     * penanda yang muncul saat disorot sama saja dengan tidak ada penanda.
     */
    public function test_penanda_pembuka_rincian_tidak_bergantung_hover(): void
    {
        $html = Livewire::actingAs($this->owner)->test(Pembelian::class)->html();

        foreach ($this->pembukaRincian($html) as $tombol) {
            $markup = $tombol['pembuka'].$tombol['isi'];

            foreach (['group-hover:', 'hover:opacity-100', 'hover:visible', 'opacity-0', 'invisible'] as $terlarang) {
                $this->assertStringNotContainsString($terlarang, $markup,
                    "penanda yang muncul lewat {$terlarang} tidak pernah terlihat di layar sentuh");
            }

            // Ikonnya juga tidak boleh disembunyikan sampai ada hover.
            $this->assertDoesNotMatchRegularExpression('/class="[^"]*\bhidden\b[^"]*"[^>]*>\s*<svg/', $tombol['isi'],
                'ikonnya tidak boleh berangkat dari keadaan tersembunyi');
        }
    }

    /**
     * Tidak ada pembuka rincian yang menempel pada elemen yang tidak bisa difokus.
     *
     * `<tr wire:click>` dan `<div wire:click>` tidak pernah masuk urutan Tab: kalau baris
     * tabelnya sendiri yang diklik, harus tetap ada satu <button>/<a> yang bisa dijangkau
     * papan tuts. Diperiksa dengan mencocokkan SEMUA kemunculan bukaRincian, bukan hanya
     * yang berbentuk tombol.
     */
    public function test_semua_pemicu_buka_rincian_menempel_pada_elemen_yang_bisa_difokus(): void
    {
        $html = Livewire::actingAs($this->owner)->test(Pembelian::class)->html();

        $jumlah = preg_match_all('/bukaRincian\(/', $html);
        $pada = preg_match_all('/<(button|a)\b[^>]*bukaRincian\(/s', $html);

        $this->assertGreaterThan(0, $jumlah, 'daftar harus punya pemicu rincian');
        $this->assertSame($jumlah, $pada,
            'setiap pemicu bukaRincian harus menempel pada <button> atau <a>; '
            .'<tr>/<div> berwire:click tidak bisa dijangkau Tab');
    }

    /** Sesudah dibuka, labelnya berubah jadi "tutup" — tetap dengan nomor notanya. */
    public function test_label_pembuka_berubah_menjadi_tutup_saat_rinciannya_terbuka(): void
    {
        $html = Livewire::actingAs($this->owner)->test(Pembelian::class)
            ->call('bukaRincian', $this->nota->getKey())
            ->html();

        foreach ($this->pembukaRincian($html) as $tombol) {
            $this->assertStringContainsString('Tutup rincian nota '.$this->nota->nomor_po, $tombol['pembuka'],
                'tombol yang notanya sedang terbuka harus mengatakan bahwa ia menutup, dan nota yang mana');
        }
    }

    /**
     * Semua <button> pembuka rincian beserta isinya.
     *
     * @return list<array{pembuka: string, isi: string}>
     */
    private function pembukaRincian(string $html): array
    {
        preg_match_all('/(<button\b[^>]*bukaRincian\([^>]*>)(.*?)<\/button>/s', $html, $cocok, PREG_SET_ORDER);

        return array_map(fn (array $c) => ['pembuka' => $c[1], 'isi' => $c[2]], $cocok);
    }
}

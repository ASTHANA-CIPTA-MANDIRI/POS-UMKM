<?php

namespace Tests\Feature;

use App\Actions\Biaya\HitungBiayaHarianAction;
use App\Enums\PeriodeBiaya;
use App\Enums\UserRole;
use App\Livewire\Pages\Owner\Biaya\Biaya as LayarBiaya;
use App\Models\Biaya\BiayaOperasional;
use App\Models\Kas\CashMovement;
use App\Models\Tenant\Outlet;
use App\Models\Tenant\Tenant;
use App\Models\Tenant\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\MembuatDataUji;
use Tests\TestCase;

/**
 * Biaya operasional — beban warung yang berulang.
 *
 * KENAPA ADA. Margin di layar Produk adalah margin KOTOR: "Ayam Goreng untung Rp 1.960" belum
 * dipotong sewa, listrik, dan gas. Pemilik yang membaca angka itu menyimpulkan warungnya
 * untung padahal bisa saja rugi tiap hari. Angka dari layar inilah yang menutup selisihnya.
 *
 * Yang dijaga paling keras:
 *
 *  - PEMBAGINYA angka bulat (30 / 7 / 365), sama seperti kalau dihitung di kertas. Aplikasi
 *    yang menjawab Rp 48.387 di Januari dan Rp 53.571 di Februari untuk sewa yang TIDAK
 *    berubah akan dianggap salah hitung, dan angka yang tidak dipercaya tidak dipakai.
 *  - RENTANG BERLAKU. Biaya yang sudah berhenti tidak boleh membebani hari ini, dan biaya
 *    yang belum mulai juga tidak.
 *  - RENTANG TERBALIK ditolak. Kalau lolos, biayanya tersimpan, muncul di daftar, dan TIDAK
 *    PERNAH ikut dihitung — tanpa satu pun petunjuk kenapa.
 *  - Layar ini TIDAK PERNAH menyentuh kas. Ia angka perencanaan; kalau ia membuat baris kas,
 *    uang yang sama tercatat dua kali.
 */
class OwnerBiayaTest extends TestCase
{
    use MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $outlet;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant('Warung Biaya');
        $this->outlet = $this->buatOutlet($this->tenant, 'Outlet Utama');

        $this->owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik',
            'email' => 'owner@biaya.test',
            'password' => 'rahasia123',
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());
    }

    private function layar(): Testable
    {
        return Livewire::actingAs($this->owner)->test(LayarBiaya::class);
    }

    private function aksi(): HitungBiayaHarianAction
    {
        return app(HitungBiayaHarianAction::class);
    }

    private function buatBiaya(
        string $nama,
        float $nominal,
        PeriodeBiaya $periode = PeriodeBiaya::Bulanan,
        ?string $outletId = null,
        ?string $mulai = null,
        ?string $selesai = null,
    ): BiayaOperasional {
        return BiayaOperasional::create([
            'nama' => $nama,
            'nominal' => $nominal,
            'periode' => $periode,
            'outlet_id' => $outletId,
            'mulai' => $mulai ?? now()->subMonth()->toDateString(),
            'selesai' => $selesai,
        ]);
    }

    /* ── Konversi ke per hari ────────────────────────────────────────────── */

    #[Test]
    public function sewa_sebulan_dibagi_tiga_puluh_seperti_dihitung_di_kertas(): void
    {
        /*
         * Pembagi 30, bukan jumlah hari sebenarnya di bulan berjalan. Pemilik yang menghitung
         * 1.500.000 ÷ 30 = 50.000 di kertasnya harus mendapat angka yang SAMA dari aplikasi;
         * kalau tidak, ia menganggap aplikasinya salah hitung dan berhenti memakai angkanya.
         */
        $this->buatBiaya('Sewa tempat', 1500000);

        $this->assertSame(50000.0, $this->aksi()->untuk()['perHari']);
    }

    #[Test]
    public function periode_mingguan_dan_tahunan_ikut_dikonversi_ke_hari(): void
    {
        // Gas Rp 70.000/minggu = Rp 10.000/hari; PBB Rp 365.000/tahun = Rp 1.000/hari.
        $this->buatBiaya('Gas', 70000, PeriodeBiaya::Mingguan);
        $this->buatBiaya('PBB', 365000, PeriodeBiaya::Tahunan);

        $this->assertSame(11000.0, $this->aksi()->untuk()['perHari']);
    }

    #[Test]
    public function total_per_bulan_dihitung_lewat_hari_bukan_menjumlah_nominal(): void
    {
        /*
         * Bedanya terasa begitu ada biaya tahunan: menjumlah nominal apa adanya akan
         * menambahkan PBB setahun penuh ke total sebulan — beban bulanan warung terlihat
         * berlipat, dan saran harga jual yang dibangun di atasnya jadi mustahil dipakai.
         */
        $this->buatBiaya('Sewa tempat', 1500000);
        $this->buatBiaya('PBB', 365000, PeriodeBiaya::Tahunan);

        // (50.000 + 1.000) x 30 = 1.530.000, BUKAN 1.500.000 + 365.000 = 1.865.000.
        $this->assertSame(1530000.0, $this->aksi()->untuk()['perBulan']);
    }

    /* ── Rentang berlaku ─────────────────────────────────────────────────── */

    #[Test]
    public function biaya_yang_sudah_berhenti_tidak_membebani_hari_ini(): void
    {
        // Sewa lapak lama dan gaji karyawan yang sudah keluar akan membebani hitungan
        // SELAMANYA kalau rentangnya tidak dibaca — dan margin warung terlihat lebih buruk
        // daripada kenyataannya sampai ada yang sadar.
        $this->buatBiaya('Sewa lapak lama', 900000, mulai: now()->subYear()->toDateString(), selesai: now()->subMonth()->toDateString());

        $this->assertSame(0.0, $this->aksi()->untuk()['perHari']);
    }

    #[Test]
    public function biaya_yang_belum_mulai_juga_belum_membebani(): void
    {
        // Sewa cabang baru yang akad-nya bulan depan bukan beban hari ini.
        $this->buatBiaya('Sewa cabang baru', 1200000, mulai: now()->addMonth()->toDateString());

        $this->assertSame(0.0, $this->aksi()->untuk()['perHari']);
    }

    #[Test]
    public function batas_rentang_inklusif_di_kedua_ujungnya(): void
    {
        /*
         * Batas eksklusif membuat satu hari hilang tiap periode, dan hilangnya tidak pernah
         * terlihat karena angkanya cuma turun sedikit. Sewa yang berlaku 1–31 memang
         * membebani tanggal 31 juga.
         */
        $mulaiHariIni = $this->buatBiaya('Mulai hari ini', 300000, mulai: now()->toDateString());
        $berhentiHariIni = $this->buatBiaya('Berhenti hari ini', 300000, mulai: now()->subMonth()->toDateString(), selesai: now()->toDateString());

        $this->assertTrue($mulaiHariIni->berlakuPada());
        $this->assertTrue($berhentiHariIni->berlakuPada());
        $this->assertSame(20000.0, $this->aksi()->untuk()['perHari']);
    }

    /* ── Cabang ──────────────────────────────────────────────────────────── */

    #[Test]
    public function biaya_cabang_lain_tidak_ikut_membebani_cabang_yang_diminta(): void
    {
        $cabangDua = $this->buatOutlet($this->tenant, 'Cabang Dua');

        $this->buatBiaya('Sewa Utama', 1500000, outletId: $this->outlet->getKey());
        $this->buatBiaya('Sewa Cabang Dua', 3000000, outletId: $cabangDua->getKey());

        $this->assertSame(50000.0, $this->aksi()->untuk($this->outlet->getKey())['perHari']);
    }

    #[Test]
    public function biaya_bersama_ikut_membebani_cabang_mana_pun(): void
    {
        /*
         * Gaji pemilik dan internet tidak menempel pada satu cabang, tapi cabang mana pun
         * tetap menanggungnya. Kalau tidak ikut, cabang yang sewanya mahal terlihat sama
         * beratnya dengan cabang yang menumpang — dan keputusan harga di keduanya jadi salah.
         */
        $this->buatBiaya('Sewa Utama', 1500000, outletId: $this->outlet->getKey());
        $this->buatBiaya('Internet', 300000);

        $this->assertSame(60000.0, $this->aksi()->untuk($this->outlet->getKey())['perHari']);
    }

    #[Test]
    public function tanpa_cabang_seluruh_biaya_ikut_satu_kali(): void
    {
        // Pagar: pemilik yang melihat angka seluruh warung memang ingin melihat semuanya,
        // dan tidak boleh ada yang terhitung dua kali.
        $cabangDua = $this->buatOutlet($this->tenant, 'Cabang Dua');

        $this->buatBiaya('Sewa Utama', 1500000, outletId: $this->outlet->getKey());
        $this->buatBiaya('Sewa Cabang Dua', 3000000, outletId: $cabangDua->getKey());
        $this->buatBiaya('Internet', 300000);

        $this->assertSame(160000.0, $this->aksi()->untuk()['perHari']);
    }

    /* ── Layar: menyimpan ────────────────────────────────────────────────── */

    #[Test]
    public function nominal_bertitik_ribuan_tersimpan_utuh(): void
    {
        /*
         * `(float) '1.500.000'` bernilai 1.5 — sewa satu setengah juta tercatat Rp 2, dan
         * seluruh hitungan beban warung jadi angka yang tidak berarti apa-apa. Cacat yang
         * sama sudah pernah terjadi di kolom harga beli nota belanja (96d4844).
         */
        $this->layar()
            ->call('tambah')
            ->set('nama', 'Sewa tempat')
            ->set('nominal', '1.500.000')
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertSame(1500000.0, (float) BiayaOperasional::firstOrFail()->nominal);
    }

    #[Test]
    public function nominal_nol_ditolak(): void
    {
        // Biaya Rp 0 bukan biaya. Ia cuma memanjangkan daftar dengan baris yang tidak
        // berpengaruh pada satu pun angka di halaman itu.
        $this->layar()
            ->call('tambah')
            ->set('nama', 'Entah')
            ->set('nominal', '0')
            ->call('simpan')
            ->assertHasErrors('nominal');

        $this->assertSame(0, BiayaOperasional::count());
    }

    #[Test]
    public function rentang_terbalik_ditolak(): void
    {
        /*
         * Kalau lolos, berlakuPada() menjawab false untuk SETIAP tanggal: biayanya tersimpan,
         * muncul di daftar, dan tidak pernah ikut dihitung sama sekali. Pemilik melihat
         * sewanya ada dan beban hariannya tidak berubah, tanpa satu pun petunjuk kenapa.
         */
        $this->layar()
            ->call('tambah')
            ->set('nama', 'Sewa')
            ->set('nominal', '1500000')
            ->set('mulai', now()->toDateString())
            ->set('selesai', now()->subWeek()->toDateString())
            ->call('simpan')
            ->assertHasErrors('selesai');

        $this->assertSame(0, BiayaOperasional::count());
    }

    #[Test]
    public function formulir_ubah_terisi_termasuk_tanggal_dalam_bentuk_yang_dibaca_kotak_date(): void
    {
        // Kotak <input type="date"> hanya menerima Y-m-d. Format lain membuat kotaknya terbuka
        // KOSONG, dan menyimpan dari keadaan itu MENGHAPUS tanggalnya.
        $biaya = $this->buatBiaya('Sewa tempat', 1500000, mulai: '2026-07-01', selesai: '2026-12-31');

        $this->layar()
            ->call('ubah', $biaya->getKey())
            ->assertSet('nama', 'Sewa tempat')
            ->assertSet('nominal', '1500000')
            ->assertSet('mulai', '2026-07-01')
            ->assertSet('selesai', '2026-12-31');
    }

    /* ── Layar: hentikan vs hapus ────────────────────────────────────────── */

    #[Test]
    public function menghentikan_biaya_melepas_beban_tanpa_mengubah_riwayat(): void
    {
        /*
         * Bedanya dengan menghapus, dan ini yang membuat tombol "Hentikan" ada sama sekali:
         * menghapus membuat hitungan bulan LALU ikut berubah, seolah sewanya tidak pernah
         * ada. Menghentikan membiarkan riwayatnya utuh.
         */
        $biaya = $this->buatBiaya('Sewa lapak', 900000);

        $this->layar()->call('hentikan', $biaya->getKey());

        $biaya->refresh();

        $this->assertNotNull($biaya->selesai);
        $this->assertNotSoftDeleted($biaya);
        $this->assertTrue(
            $biaya->berlakuPada(now()->subMonth()),
            'bulan lalu biayanya memang masih berjalan, dan itu tidak boleh berubah',
        );
    }

    #[Test]
    public function biaya_yang_sudah_berhenti_tidak_dihentikan_dua_kali(): void
    {
        // Menghentikan lagi akan MEMAJUKAN tanggal berhentinya ke hari ini — diam-diam
        // menambahkan beban untuk hari-hari yang sebenarnya sudah lewat.
        $biaya = $this->buatBiaya('Sewa lapak', 900000, mulai: now()->subYear()->toDateString(), selesai: now()->subMonth()->toDateString());
        $tanggalLama = $biaya->selesai->toDateString();

        $this->layar()->call('hentikan', $biaya->getKey());

        $this->assertSame($tanggalLama, $biaya->fresh()->selesai->toDateString());
    }

    #[Test]
    public function menghapus_memakai_soft_delete(): void
    {
        $biaya = $this->buatBiaya('Salah catat', 100000);

        $this->layar()->call('hapus', $biaya->getKey());

        $this->assertSoftDeleted($biaya);
    }

    /* ── Layar: daftar ───────────────────────────────────────────────────── */

    #[Test]
    public function bawaannya_hanya_menampilkan_yang_masih_berjalan(): void
    {
        $this->buatBiaya('Sewa Berjalan', 1500000);
        $this->buatBiaya('Sewa Lama', 900000, mulai: now()->subYear()->toDateString(), selesai: now()->subMonth()->toDateString());

        $this->layar()
            ->assertSee('Sewa Berjalan')
            ->assertDontSee('Sewa Lama');
    }

    #[Test]
    public function saringan_menampilkan_yang_berhenti_kalau_diminta(): void
    {
        // Kontrol: saringan yang kebetulan menyembunyikan SEMUA baris juga akan lolos uji di
        // atas. Yang berhenti tetap harus bisa dilihat — riwayatnya masih dipakai laporan.
        $this->buatBiaya('Sewa Berjalan', 1500000);
        $this->buatBiaya('Sewa Lama', 900000, mulai: now()->subYear()->toDateString(), selesai: now()->subMonth()->toDateString());

        $this->layar()
            ->set('tampilkanBerhenti', true)
            ->assertSee('Sewa Berjalan')
            ->assertSee('Sewa Lama');
    }

    #[Test]
    public function layar_ini_tidak_pernah_membuat_baris_kas(): void
    {
        /*
         * Ini angka PERENCANAAN. Kalau ia membuat baris kas, uang yang sama tercatat dua kali
         * — sekali di sini, sekali saat sewanya benar-benar dibayar — dan laporan kas jadi
         * salah tanpa ada yang bisa menjelaskan selisihnya.
         */
        $this->layar()
            ->call('tambah')
            ->set('nama', 'Sewa tempat')
            ->set('nominal', '1500000')
            ->call('simpan')
            ->assertHasNoErrors();

        $this->assertSame(0, CashMovement::count());
    }

    /* ── Target margin ───────────────────────────────────────────────────── */

    #[Test]
    public function target_margin_tersimpan_dan_terbaca_lagi_saat_layar_dibuka(): void
    {
        $this->layar()
            ->set('targetMargin', '35')
            ->call('simpanTargetMargin')
            ->assertHasNoErrors();

        $this->assertSame(35.0, (float) $this->tenant->fresh()->target_margin);
        $this->layar()->assertSet('targetMargin', '35');
    }

    #[Test]
    public function target_margin_seratus_persen_ditolak(): void
    {
        /*
         * Pada 100% pembagi rumus saran harga menjadi NOL, dan di atasnya hasilnya negatif —
         * aplikasi akan menyarankan harga minus tanpa satu pun galat, dan angka itu terlihat
         * seperti hitungan yang sah. Batasnya 95%.
         */
        $this->layar()
            ->set('targetMargin', '100')
            ->call('simpanTargetMargin')
            ->assertHasErrors('targetMargin');

        $this->assertSame(30.0, (float) $this->tenant->fresh()->target_margin);
    }

    #[Test]
    public function target_margin_tidak_bisa_digeser_lewat_mass_assignment(): void
    {
        /*
         * Kolomnya sengaja TIDAK fillable. Kalau ia ikut mass-assignment, satu muatan yang
         * membawa `target_margin` di layar mana pun bisa menggesernya — dan yang bergeser
         * adalah angka yang menyusun saran harga SELURUH katalog.
         */
        $this->tenant->update(['target_margin' => 90]);

        $this->assertSame(30.0, (float) $this->tenant->fresh()->target_margin);
    }

    /* ── Tenant & peran ──────────────────────────────────────────────────── */

    #[Test]
    public function biaya_warung_lain_tidak_terlihat_dan_tidak_bisa_diubah(): void
    {
        $lain = $this->buatTenant('Warung Sebelah');
        $asing = $this->konteks()->forTenant($lain->getKey(), fn () => BiayaOperasional::create([
            'nama' => 'Sewa Sebelah',
            'nominal' => 9000000,
            'periode' => PeriodeBiaya::Bulanan,
            'mulai' => now()->subMonth()->toDateString(),
        ]));

        $this->konteks()->setTenant($this->tenant->getKey());

        $this->layar()->assertDontSee('Sewa Sebelah');
        $this->assertSame(0.0, $this->aksi()->untuk()['perHari'], 'beban warung lain tidak boleh ikut terhitung');

        $this->expectException(ModelNotFoundException::class);
        $this->layar()->call('ubah', $asing->getKey());
    }

    #[Test]
    public function kasir_tidak_boleh_membuka_layar_ini(): void
    {
        // Angka di layar ini yang menentukan apakah warung untung. Kasir yang bisa
        // mengubahnya bisa membuat warung terlihat untung pada hari mana pun.
        $kasir = $this->buatUser($this->tenant, UserRole::Kasir, [
            'name' => 'Kasir',
            'username' => 'kasir-biaya',
            'password' => 'rahasia123',
            'outlet_id' => $this->outlet->getKey(),
        ]);

        $this->actingAs($kasir)
            ->get(route('owner.biaya'))
            ->assertRedirect(route('kasir.beranda'));
    }
}

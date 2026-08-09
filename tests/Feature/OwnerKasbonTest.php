<?php

namespace Tests\Feature;

use App\Enums\CreditStatus;
use App\Enums\UserRole;
use App\Livewire\Pages\Owner\Kasbon\Kasbon as LayarKasbon;
use App\Models\Pelanggan\CreditLedger;
use App\Models\Pelanggan\CreditPayment;
use App\Models\Pelanggan\Customer;
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
 * Layar Kasbon — buku utang pelanggan berikut riwayat setorannya.
 *
 * Aturan uangnya sendiri dijaga KasbonPelunasanTest (setoran berlebih, pembatalan, status).
 * Berkas ini menjaga apa yang cuma bisa salah di LAYAR, dan tiap butirnya bisa terjadi tanpa
 * satu pun galat:
 *
 *  - Uang yang diketik pakai titik ribuan. "150.000" lolos `numeric` dan `(float)`
 *    membacanya 150 — pelanggan yang sudah membayar tetap tertagih hampir seluruhnya.
 *  - Tanggal setor di masa depan. Aksinya menolaknya, tapi layar menjepit nilainya ke now()
 *    sebelum sampai ke sana, jadi tanpa aturan di layar ia diam-diam tercatat hari ini.
 *  - Penolakan aksi yang bocor jadi halaman 500. Yang di depan layar sedang memegang uang
 *    pelanggan, dan halaman galat membuatnya tidak tahu uangnya sudah tercatat atau belum.
 *  - Kasbon warung lain yang bisa disetor lewat muatan Livewire.
 */
class OwnerKasbonTest extends TestCase
{
    use MembuatDataUji, RefreshDatabase;

    private Tenant $tenant;

    private Outlet $outlet;

    private User $owner;

    private Customer $budi;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = $this->buatTenant('Warung Kasbon');
        $this->outlet = $this->buatOutlet($this->tenant, 'Outlet Utama');

        $this->owner = $this->buatUser($this->tenant, UserRole::Owner, [
            'name' => 'Pemilik',
            'email' => 'owner@layar-kasbon.test',
            'password' => 'rahasia123',
        ]);

        $this->konteks()->setTenant($this->tenant->getKey());

        $this->budi = Customer::create(['nama' => 'Pak Budi', 'no_hp' => '081234567890']);
    }

    private function layar(): Testable
    {
        return Livewire::actingAs($this->owner)->test(LayarKasbon::class);
    }

    private function buatKasbon(float $utang = 500000, ?Customer $siapa = null): CreditLedger
    {
        return CreditLedger::create([
            'outlet_id' => $this->outlet->getKey(),
            'customer_id' => ($siapa ?? $this->budi)->getKey(),
            'jumlah_utang' => $utang,
        ]);
    }

    /* ── Setoran lewat layar ─────────────────────────────────────────────── */

    #[Test]
    public function setoran_yang_diketik_pakai_titik_ribuan_tersimpan_utuh(): void
    {
        /*
         * Cacat yang sudah benar-benar terjadi di kolom harga beli nota belanja (96d4844):
         * `is_numeric('150.000')` true dan `(float)` membacanya 150. Di layar ini akibatnya
         * langsung — pelanggan yang menyerahkan Rp 150.000 tercatat membayar Rp 150.
         */
        $kasbon = $this->buatKasbon(500000);

        $this->layar()
            ->call('setor', $kasbon->getKey())
            ->set('jumlahSetor', '150.000')
            ->call('simpanSetoran')
            ->assertHasNoErrors();

        $this->assertSame(350000.0, $kasbon->refresh()->sisaUtang());
    }

    #[Test]
    public function nominal_berdesimal_ditolak_bukan_ditebak(): void
    {
        // "150,5" dan "150.5" ambigu di kolom rupiah. Menebaknya berarti menebak uang orang
        // lain, dan tebakan yang senyap tidak pernah ketahuan.
        $kasbon = $this->buatKasbon(500000);

        $this->layar()
            ->call('setor', $kasbon->getKey())
            ->set('jumlahSetor', '150.5')
            ->call('simpanSetoran')
            ->assertHasErrors('jumlahSetor');

        $this->assertSame(0, CreditPayment::count());
    }

    #[Test]
    public function tanggal_setor_di_masa_depan_ditolak_di_layar_juga(): void
    {
        /*
         * Aksinya memang menolak masa depan, TAPI layar menjepit nilainya ke now() sebelum
         * memanggilnya. Tanpa aturan `before_or_equal:today` di layar, tanggal bulan depan
         * lolos dan diam-diam tercatat sebagai hari ini — perbaikan senyap atas salah ketik,
         * yang lebih buruk daripada penolakan karena pemiliknya tidak pernah tahu.
         */
        $kasbon = $this->buatKasbon(500000);

        $this->layar()
            ->call('setor', $kasbon->getKey())
            ->set('jumlahSetor', '100000')
            ->set('tanggalSetor', now()->addMonth()->toDateString())
            ->call('simpanSetoran')
            ->assertHasErrors('tanggalSetor');

        $this->assertSame(0, CreditPayment::count());
    }

    #[Test]
    public function setoran_hari_ini_bertanda_waktu_jam_sebenarnya_bukan_2359(): void
    {
        // Rekap "masuk hari ini" membaca jamnya, dan jam yang mengada-ada membuat urutan
        // setoran dalam satu hari jadi acak.
        $kasbon = $this->buatKasbon(500000);

        $this->layar()
            ->call('setor', $kasbon->getKey())
            ->set('jumlahSetor', '100000')
            ->call('simpanSetoran')
            ->assertHasNoErrors();

        $this->assertTrue(CreditPayment::firstOrFail()->dibayar_pada->lessThanOrEqualTo(now()));
    }

    #[Test]
    public function setoran_melebihi_sisa_muncul_sebagai_toast_bukan_halaman_galat(): void
    {
        /*
         * Aksinya melempar RuntimeException. Kalau layar membiarkannya lewat, yang muncul
         * halaman 500 — dan orang yang sedang memegang uang pelanggan tidak tahu apakah
         * setorannya sudah tercatat atau belum, lalu mencatatnya sekali lagi.
         */
        $kasbon = $this->buatKasbon(100000);

        $this->layar()
            ->call('setor', $kasbon->getKey())
            ->set('jumlahSetor', '500000')
            ->call('simpanSetoran')
            ->assertHasNoErrors()
            ->assertDispatched('toast', fn (string $n, array $d) => $d['jenis'] === 'galat'
                && str_contains($d['pesan'], 'Rp 100.000'));

        $this->assertSame(0, CreditPayment::count());
    }

    #[Test]
    public function tombol_isi_sisa_semuanya_mengisi_angka_tanpa_desimal(): void
    {
        /*
         * Kolom decimal(15,2) mengembalikan "350000.00", dan bentuk itu DITOLAK Uang::baca().
         * Kalau tombolnya mengisi apa adanya, jalur yang paling sering dipakai orang justru
         * yang paling sering ditolak — dengan pesan yang tidak bisa dijelaskan siapa pun.
         */
        $kasbon = $this->buatKasbon(500000);

        $this->layar()
            ->call('setor', $kasbon->getKey())
            ->set('jumlahSetor', '150000')
            ->call('simpanSetoran')
            ->call('setor', $kasbon->refresh()->getKey())
            ->call('setorPenuh')
            ->assertSet('jumlahSetor', '350000')
            ->call('simpanSetoran')
            ->assertHasNoErrors();

        $this->assertSame(CreditStatus::Lunas, $kasbon->refresh()->status);
    }

    #[Test]
    public function kasbon_bersen_masih_bisa_dilunasi_lewat_tombol_isi_sisa(): void
    {
        /*
         * JALAN BUNTU YANG TERUKUR, ditemukan lewat uji mutasi.
         *
         * `jumlah_utang` boleh bersen (decimal(15,2); kasbon dari struk berpajak bisa membawa
         * 100000.50), sementara semua nominal yang diketik orang dibaca App\Support\Uang yang
         * menolak desimal. Dulu kedua jalannya mati: mengisi apa adanya ("100000.5") ditolak
         * validasi, dan round() menghasilkan 100001 yang ditolak aksinya sebagai melebihi
         * sisa. Kasbonnya tidak akan pernah bisa dilunasi siapa pun, dan menetap selamanya di
         * daftar penagihan.
         *
         * Sekarang: dibulatkan KE BAWAH, dan sisa di bawah satu rupiah dinyatakan lunas.
         */
        $kasbon = $this->buatKasbon(100000.50);

        $this->layar()
            ->call('setor', $kasbon->getKey())
            ->call('setorPenuh')
            ->assertSet('jumlahSetor', '100000')
            ->call('simpanSetoran')
            ->assertHasNoErrors();

        $this->assertSame(CreditStatus::Lunas, $kasbon->refresh()->status);
    }

    #[Test]
    public function sisa_lima_puluh_rupiah_tetap_dihitung_utang(): void
    {
        // Pagar untuk ambang di atas: yang dinyatakan tidak bisa dibayar hanyalah pecahan
        // yang memang tidak punya wujud fisik. Rp 50 yang benar-benar terutang tetap utang,
        // kalau tidak, ambangnya berubah jadi pemutihan utang kecil-kecilan.
        $kasbon = $this->buatKasbon(100000);

        $this->layar()
            ->call('setor', $kasbon->getKey())
            ->set('jumlahSetor', '99950')
            ->call('simpanSetoran')
            ->assertHasNoErrors();

        $this->assertSame(CreditStatus::BelumLunas, $kasbon->refresh()->status);
        $this->assertSame(50.0, $kasbon->sisaUtang());
    }

    #[Test]
    public function membatalkan_setoran_lewat_layar_mengembalikan_sisa_utang(): void
    {
        $kasbon = $this->buatKasbon(500000);

        $this->layar()
            ->call('setor', $kasbon->getKey())
            ->set('jumlahSetor', '200000')
            ->call('simpanSetoran');

        $setoran = CreditPayment::firstOrFail();

        $this->layar()->call('batalkanSetoran', $setoran->getKey());

        $this->assertSame(500000.0, $kasbon->refresh()->sisaUtang());
        $this->assertSoftDeleted($setoran);
    }

    /* ── Kasbon baru ─────────────────────────────────────────────────────── */

    #[Test]
    public function kasbon_manual_tercatat_atas_nama_pelanggan_yang_dipilih(): void
    {
        $this->layar()
            ->call('tambahKasbon')
            ->set('pelangganId', $this->budi->getKey())
            ->set('jumlahUtang', '75.000')
            ->call('simpanKasbon')
            ->assertHasNoErrors();

        $kasbon = CreditLedger::firstOrFail();

        $this->assertSame($this->budi->getKey(), $kasbon->customer_id);
        $this->assertSame(75000.0, (float) $kasbon->jumlah_utang);
        $this->assertSame(CreditStatus::BelumLunas, $kasbon->status);
    }

    #[Test]
    public function kasbon_bernilai_nol_ditolak(): void
    {
        // Utang Rp 0 bukan utang. Ia cuma memanjangkan daftar penagihan dengan baris yang
        // tidak pernah bisa diselesaikan — setoran nol ditolak aksinya.
        $this->layar()
            ->call('tambahKasbon')
            ->set('pelangganId', $this->budi->getKey())
            ->set('jumlahUtang', '0')
            ->call('simpanKasbon')
            ->assertHasErrors('jumlahUtang');

        $this->assertSame(0, CreditLedger::count());
    }

    #[Test]
    public function jatuh_tempo_yang_sudah_lewat_ditolak(): void
    {
        // Kasbon baru yang lahir langsung berstatus telat menyalakan lencana merah pada hari
        // pertamanya. Merah yang menyala tanpa sebab membuat merah berhenti berarti.
        $this->layar()
            ->call('tambahKasbon')
            ->set('pelangganId', $this->budi->getKey())
            ->set('jumlahUtang', '50000')
            ->set('jatuhTempo', now()->subWeek()->toDateString())
            ->call('simpanKasbon')
            ->assertHasErrors('jatuhTempo');
    }

    /* ── Daftar & saringan ───────────────────────────────────────────────── */

    #[Test]
    public function bawaannya_menampilkan_yang_belum_lunas_saja(): void
    {
        // Yang dibawa orang ke layar ini adalah menagih, bukan mengarsip.
        $this->buatKasbon(100000);

        $siti = Customer::create(['nama' => 'Bu Siti', 'no_hp' => '081200000002']);
        $lunas = $this->buatKasbon(200000, $siti);
        $lunas->update(['jumlah_dibayar' => 200000, 'status' => CreditStatus::Lunas]);

        $this->layar()
            ->assertSee('Pak Budi')
            ->assertDontSee('Bu Siti');
    }

    #[Test]
    public function saringan_semua_menampilkan_keduanya(): void
    {
        // Kontrol untuk uji di atas: saringan yang kebetulan menyembunyikan SEMUA orang juga
        // akan lolos tanpa ini.
        $this->buatKasbon(100000);

        $siti = Customer::create(['nama' => 'Bu Siti', 'no_hp' => '081200000002']);
        $lunas = $this->buatKasbon(200000, $siti);
        $lunas->update(['jumlah_dibayar' => 200000, 'status' => CreditStatus::Lunas]);

        $this->layar()
            ->set('saringStatus', 'semua')
            ->assertSee('Pak Budi')
            ->assertSee('Bu Siti');
    }

    #[Test]
    public function total_piutang_tidak_ikut_berubah_saat_daftarnya_disaring(): void
    {
        /*
         * Angka yang mengecil mengikuti saringan terbaca sebagai piutang yang berkurang
         * karena daftarnya disaring — kesimpulan yang salah tentang uang.
         */
        $this->buatKasbon(300000);

        $siti = Customer::create(['nama' => 'Bu Siti', 'no_hp' => '081200000002']);
        $lunas = $this->buatKasbon(200000, $siti);
        $lunas->update(['jumlah_dibayar' => 200000, 'status' => CreditStatus::Lunas]);

        $this->layar()->assertViewHas('totalPiutang', 300000.0);
        $this->layar()->set('saringStatus', 'lunas')->assertViewHas('totalPiutang', 300000.0);
    }

    #[Test]
    public function riwayat_setoran_terlihat_di_daftar(): void
    {
        /*
         * Inti kenapa barisnya berbentuk kartu, bukan tabel: pertanyaan pertama pelanggan
         * adalah "kapan saya bayar yang seratus ribu itu?". Selama aplikasinya tidak bisa
         * menjawab itu, buku tulis tetap menang.
         */
        $kasbon = $this->buatKasbon(500000);

        $this->layar()
            ->call('setor', $kasbon->getKey())
            ->set('jumlahSetor', '125000')
            ->set('catatanSetor', 'dititip anaknya')
            ->call('simpanSetoran');

        $this->layar()
            ->assertSee('Rp 125.000')
            ->assertSee('dititip anaknya')
            ->assertSee($this->owner->name);
    }

    /* ── Tenant & peran ──────────────────────────────────────────────────── */

    #[Test]
    public function kasbon_warung_lain_tidak_bisa_disetor(): void
    {
        $lain = $this->buatTenant('Warung Sebelah');
        $asing = $this->konteks()->forTenant($lain->getKey(), function () use ($lain) {
            $outlet = $this->buatOutlet($lain, 'Outlet Sebelah');
            $orang = Customer::create(['nama' => 'Orang Sebelah']);

            return CreditLedger::create([
                'outlet_id' => $outlet->getKey(),
                'customer_id' => $orang->getKey(),
                'jumlah_utang' => 999000,
            ]);
        });

        $this->konteks()->setTenant($this->tenant->getKey());

        $this->expectException(ModelNotFoundException::class);
        $this->layar()->call('setor', $asing->getKey());
    }

    #[Test]
    public function kasir_tidak_boleh_membuka_layar_ini(): void
    {
        /*
         * Layar ini MENGUBAH catatan uang yang belum kembali. Kasir yang bisa mencatat
         * setoran bisa menyatakan utang seseorang lunas tanpa satu rupiah pun masuk laci —
         * dan sesudah itu tidak ada lagi yang menagih.
         */
        $kasir = $this->buatUser($this->tenant, UserRole::Kasir, [
            'name' => 'Kasir',
            'username' => 'kasir-kasbon',
            'password' => 'rahasia123',
            'outlet_id' => $this->outlet->getKey(),
        ]);

        $this->actingAs($kasir)
            ->get(route('owner.kasbon'))
            ->assertRedirect(route('kasir.beranda'));
    }
}

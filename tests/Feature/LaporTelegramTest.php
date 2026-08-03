<?php

namespace Tests\Feature;

use App\Console\Commands\LaporTelegram;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Menguji laporan progres ke Telegram TANPA menyentuh jaringan.
 *
 * Yang dijaga di sini bukan "apakah pesannya sampai" — itu urusan Telegram — melainkan
 * hal-hal yang membuat laporan otomatis jadi berbahaya kalau salah: angka yang keliru,
 * pesan yang ditolak karena tag HTML terbelah, token yang ikut tercetak ke log, dan
 * laporan yang terkirim padahal isinya kosong.
 */
class LaporTelegramTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /** Tanpa --kirim, perintahnya hanya menampilkan. Tidak ada pesan yang lolos. */
    public function test_tanpa_opsi_kirim_tidak_menghubungi_telegram(): void
    {
        $this->artisan('lapor:telegram --tanpa-uji')
            ->expectsOutputToContain('Nampan POS — progres')
            ->assertSuccessful();

        Http::assertNothingSent();
    }

    /**
     * Isi pesannya diperiksa dari MUATAN yang dikirim, bukan dari keluaran terminal.
     *
     * Yang menentukan berguna-tidaknya laporan ini adalah teks yang sampai di Telegram;
     * keluaran terminal cuma pratinjau. Memeriksa pratinjaunya bisa lulus sementara
     * pesan yang benar-benar terkirim kehilangan satu bagian.
     */
    public function test_pesan_yang_terkirim_memuat_bagian_yang_dipakai_membaca_progres(): void
    {
        config(['services.telegram.token' => 'uji', 'services.telegram.chat_id' => '1']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->artisan('lapor:telegram --tanpa-uji --kirim')->assertSuccessful();

        Http::assertSent(function ($permintaan) {
            $teks = (string) $permintaan['text'];

            foreach (['Nampan POS', 'pekerjaan', 'Sedang dikerjakan', 'Belum mulai', 'Catatan', 'Cabang'] as $bagian) {
                if (! str_contains($teks, $bagian)) {
                    return false;
                }
            }

            // Bilah progres: penanda visual yang terbaca tanpa gambar.
            return preg_match('/[▰▱]{10}/u', $teks) === 1;
        });
    }

    /**
     * Konfigurasi yang belum diisi harus GAGAL dengan jelas.
     *
     * Kalau perintahnya diam-diam berhasil tanpa token, laporan mingguan tidak akan
     * pernah sampai dan tidak ada yang menyadarinya — persis kegagalan yang paling
     * mahal untuk alat pelaporan.
     */
    public function test_menolak_mengirim_ketika_token_belum_diisi(): void
    {
        config(['services.telegram.token' => null, 'services.telegram.chat_id' => null]);

        $this->artisan('lapor:telegram --tanpa-uji --kirim')
            ->expectsOutputToContain('belum diisi')
            ->assertFailed();

        Http::assertNothingSent();
    }

    public function test_mengirim_ke_alamat_bot_dengan_muatan_yang_benar(): void
    {
        config(['services.telegram.token' => 'rahasia-uji', 'services.telegram.chat_id' => '12345']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->artisan('lapor:telegram --tanpa-uji --kirim')
            ->expectsOutputToContain('terkirim')
            ->assertSuccessful();

        Http::assertSent(function ($permintaan) {
            return $permintaan->url() === 'https://api.telegram.org/botrahasia-uji/sendMessage'
                && $permintaan['chat_id'] === '12345'
                && $permintaan['parse_mode'] === 'HTML'
                && str_contains((string) $permintaan['text'], 'Nampan POS');
        });
    }

    /**
     * Galat dari Telegram dilaporkan TANPA menyertakan tokennya.
     *
     * Pesan galat berakhir di log dan di riwayat terminal yang dibagikan; token bot yang
     * bocor memberi orang lain kemampuan mengirim pesan sebagai bot itu.
     */
    public function test_galat_pengiriman_tidak_membocorkan_token(): void
    {
        config(['services.telegram.token' => 'token-sangat-rahasia', 'services.telegram.chat_id' => '9']);
        Http::fake(['api.telegram.org/*' => Http::response(['description' => 'chat not found'], 400)]);

        $this->artisan('lapor:telegram --tanpa-uji --kirim')
            ->expectsOutputToContain('chat not found')
            ->doesntExpectOutputToContain('token-sangat-rahasia')
            ->assertFailed();
    }

    /**
     * Pesan panjang dipotong di batas BARIS.
     *
     * Telegram menolak pesan di atas 4096 karakter, dan potongan yang membelah tag <b>
     * membuat seluruh bagian itu gagal terkirim dengan galat "can't parse entities" —
     * bukan sekadar terlihat jelek.
     */
    public function test_pesan_panjang_dipotong_tanpa_membelah_baris(): void
    {
        $baris = array_map(fn (int $i) => "<b>baris {$i}</b> isi secukupnya untuk memenuhi batas", range(1, 200));
        $teks = implode("\n", $baris);

        $bagian = LaporTelegram::potong($teks, 500);

        $this->assertGreaterThan(1, count($bagian));

        foreach ($bagian as $satu) {
            $this->assertLessThanOrEqual(500, mb_strlen($satu));
            // Tag utuh: jumlah pembuka sama dengan jumlah penutup di setiap potongan.
            $this->assertSame(substr_count($satu, '<b>'), substr_count($satu, '</b>'));
        }

        // Tidak ada baris yang hilang saat dipotong.
        $this->assertSame($teks, implode("\n", $bagian));
    }

    /**
     * Kabar singkat: satu pesan, tanpa menjalankan suite.
     *
     * Ini yang dipakai alur QA → lead → BE/FE saat kejadian. Kalau ia ikut menjalankan
     * seluruh uji, satu putaran perbaikan berisi empat kabar akan memakan puluhan detik
     * dan alurnya berhenti dipakai.
     */
    public function test_kabar_singkat_terkirim_tanpa_menyusun_laporan_lengkap(): void
    {
        config(['services.telegram.token' => 'uji', 'services.telegram.chat_id' => '7']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->artisan('lapor:telegram --kirim --pesan="QA: 2 cacat di layar kasir"')->assertSuccessful();

        Http::assertSent(function ($permintaan) {
            $teks = (string) $permintaan['text'];

            return str_contains($teks, 'QA: 2 cacat di layar kasir')
                && str_contains($teks, 'Nampan POS')
                // Bagian laporan lengkap TIDAK boleh ikut: ini kabar singkat.
                && ! str_contains($teks, 'Belum mulai')
                && ! str_contains($teks, 'pekerjaan');
        });
    }

    /** Tanda kurung sudut di kabar harus dilolosi, atau Telegram menolak pesannya. */
    public function test_kabar_singkat_melolosi_tanda_yang_merusak_html(): void
    {
        config(['services.telegram.token' => 'uji', 'services.telegram.chat_id' => '7']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->artisan('lapor:telegram --kirim --pesan="cek <div> & <span> kosong"')->assertSuccessful();

        Http::assertSent(fn ($permintaan) => str_contains((string) $permintaan['text'], '&lt;div&gt; &amp; &lt;span&gt;'));
    }

    /* ── Perkiraan tanggal siap deploy ───────────────────────────────────── */

    /**
     * Rumus proyeksinya harus bisa dijelaskan, karena angkanya akan dibaca sebagai janji.
     *
     * 74 jam pada 4 jam/hari = 19 hari kerja; pada 6 hari kerja per pekan, 19 hari kerja
     * membentang ± 23 hari kalender. Tidak ada usaha menebak hari libur — ketepatan semu
     * pada tanggal rilis lebih menyesatkan daripada angka kasar yang jujur.
     */
    public function test_proyeksi_hari_kalender_mengikuti_kapasitas(): void
    {
        $this->assertSame(23, LaporTelegram::proyeksiHariKalender(74, 4, 6));

        // Kapasitas dua kali lipat memangkas waktunya, tapi tidak pernah jadi setengah
        // persis karena hari kerja dibulatkan ke atas.
        $this->assertLessThan(23, LaporTelegram::proyeksiHariKalender(74, 8, 6));

        // Hari kerja lebih sedikit per pekan = tanggalnya makin jauh.
        $this->assertGreaterThan(
            LaporTelegram::proyeksiHariKalender(74, 4, 6),
            LaporTelegram::proyeksiHariKalender(74, 4, 5),
        );
    }

    public function test_tanpa_sisa_pekerjaan_tidak_ada_proyeksi(): void
    {
        $this->assertSame(0, LaporTelegram::proyeksiHariKalender(0, 4, 6));
    }

    /**
     * Pekerjaan "sesudah deploy" TIDAK boleh ikut menghitung tanggal rilis.
     *
     * Kalau ikut, tanggal siapnya melompat berpekan-pekan karena daftar pembeda, dan
     * pemilik proyek menunda rilis untuk fitur yang justru tidak menghalanginya.
     */
    public function test_pembeda_sesudah_rilis_tidak_menggeser_tanggal_siap(): void
    {
        config(['services.telegram.token' => 'uji', 'services.telegram.chat_id' => '1']);
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        $this->artisan('lapor:telegram --tanpa-uji --kirim')->assertSuccessful();

        Http::assertSent(function ($permintaan) {
            $teks = (string) $permintaan['text'];

            // Blok kesiapan harus ada, dengan asumsinya tercantum.
            return str_contains($teks, 'Kesiapan deploy')
                && str_contains($teks, 'Sisa wajib')
                && str_contains($teks, 'Kapasitas')
                && str_contains($teks, 'Perkiraan siap')
                // Pembeda dilaporkan TERPISAH, bukan dicampur ke daftar wajib.
                && str_contains($teks, 'Pembeda, sesudah rilis')
                && str_contains($teks, 'wajib untuk rilis');
        });
    }

    public function test_pesan_pendek_tidak_dipotong(): void
    {
        $this->assertSame(['satu baris'], LaporTelegram::potong('satu baris', 500));
    }
}

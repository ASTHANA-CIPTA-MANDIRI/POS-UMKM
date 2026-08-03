<?php

namespace Tests\Feature;

use App\Support\JawabanBot;
use App\Support\PemeriksaUji;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Menguji bot yang menjawab perintah Telegram, tanpa menyentuh jaringan.
 *
 * Yang dijaga di sini bukan "apakah balasannya bagus", melainkan empat hal yang membuat
 * bot laporan jadi berbahaya atau tidak berguna: menjawab orang yang bukan pemiliknya,
 * menjawab pesan lama berulang kali, menjalankan seluruh suite untuk perintah yang tidak
 * membutuhkannya, dan diam ketika perintahnya salah ketik sedikit.
 */
class BotLayaniTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        config(['services.telegram.token' => 'uji', 'services.telegram.chat_id' => '99']);

        // Pemeriksa palsu: menguji jalur berat tanpa menjalankan suite di dalam suite.
        $this->app->bind(PemeriksaUji::class, fn () => new class extends PemeriksaUji
        {
            public function jalankan(): array
            {
                return [
                    'php' => ['jumlah' => 10, 'gagal' => 2, 'dilewati' => 0, 'nama' => ['uang kasir bocor', 'stok minus ditolak']],
                    'js' => ['jumlah' => 5, 'gagal' => 0, 'dilewati' => 0, 'nama' => []],
                ];
            }
        });
    }

    /** @param array<int, array<string, mixed>> $pesan */
    private function palsukan(array $pesan): void
    {
        $pembaruan = [];

        foreach ($pesan as $i => $satu) {
            $pembaruan[] = [
                'update_id' => 1000 + $i,
                'message' => [
                    'text' => $satu['teks'],
                    'chat' => ['id' => $satu['chat'] ?? '99', 'type' => 'private'],
                ],
            ];
        }

        Http::fake([
            'api.telegram.org/*/getUpdates*' => Http::response(['ok' => true, 'result' => $pembaruan]),
            'api.telegram.org/*/sendMessage' => Http::response(['ok' => true]),
        ]);
    }

    private function terkirim(): array
    {
        $teks = [];

        foreach (Http::recorded() as [$permintaan]) {
            if (str_contains($permintaan->url(), 'sendMessage')) {
                $teks[] = (string) $permintaan['text'];
            }
        }

        return $teks;
    }

    public function test_pengenalan_perintah_memaafkan_salah_ketik_dan_nama_lain(): void
    {
        // Nama di menu BotFather boleh berbeda dari yang diketik orang, dan salah ketik
        // yang wajar tidak boleh membuat bot diam — bot yang diam terasa rusak.
        $this->assertSame('todolist', JawabanBot::kenali('/todolist'));
        $this->assertSame('todolist', JawabanBot::kenali('/todo'));
        $this->assertSame('todolist', JawabanBot::kenali('/command1'));
        $this->assertSame('timeline', JawabanBot::kenali('/jadwal'));
        $this->assertSame('progress', JawabanBot::kenali('/progres'));
        $this->assertSame('improvement', JawabanBot::kenali('/improvment'));
        $this->assertSame('bug', JawabanBot::kenali('/cacat'));
        $this->assertSame('bantuan', JawabanBot::kenali('/start'));
        // Di dalam grup, Telegram menambahkan nama bot ke perintahnya.
        $this->assertSame('timeline', JawabanBot::kenali('/timeline@PosUMKMbot'));
        $this->assertNull(JawabanBot::kenali('halo bot'));
    }

    public function test_todolist_menjawab_isi_rencana(): void
    {
        $this->palsukan([['teks' => '/todolist']]);

        $this->artisan('bot:layani')->assertSuccessful();

        $teks = $this->terkirim();
        $this->assertCount(1, $teks);
        $this->assertStringContainsString('Daftar tugas', $teks[0]);
        $this->assertStringContainsString('wajib untuk rilis', $teks[0]);
    }

    public function test_timeline_menjawab_tanggal_dan_asumsinya(): void
    {
        $this->palsukan([['teks' => '/timeline']]);

        $this->artisan('bot:layani')->assertSuccessful();

        $teks = $this->terkirim()[0];
        $this->assertStringContainsString('Perkiraan siap', $teks);
        // Asumsi WAJIB ikut: tanggal tanpa asumsi akan dibaca sebagai janji.
        $this->assertStringContainsString('Kapasitas dipakai', $teks);
    }

    /**
     * Perintah ringan TIDAK boleh menjalankan suite.
     *
     * Kalau semua perintah menjalankan uji, satu batch berisi tiga perintah memakan lebih
     * dari sepuluh menit — dan pada alur berjadwal, itu biaya yang terbuang setiap kali.
     */
    public function test_perintah_ringan_tidak_menjalankan_uji(): void
    {
        $this->palsukan([['teks' => '/todolist'], ['teks' => '/improvement']]);

        $this->artisan('bot:layani')->assertSuccessful();

        foreach ($this->terkirim() as $teks) {
            $this->assertStringNotContainsString('Menjalankan uji', $teks);
        }
    }

    public function test_perintah_bug_memberi_kabar_tunggu_lalu_daftar_cacat(): void
    {
        $this->palsukan([['teks' => '/bug']]);

        $this->artisan('bot:layani')->assertSuccessful();

        $teks = $this->terkirim();

        // Uji butuh menit; tanpa kabar "tunggu", bot terasa mati.
        $this->assertStringContainsString('Menjalankan uji', $teks[0]);
        $this->assertStringContainsString('uang kasir bocor', $teks[1]);
        $this->assertStringContainsString('2 uji gagal', $teks[1]);
    }

    /**
     * Hanya chat pemilik yang dilayani.
     *
     * Alamat bot bisa ditemukan siapa saja, dan jawabannya berisi rencana, tenggat, dan
     * daftar cacat yang belum ditambal — persis yang tidak boleh dibaca orang luar.
     */
    public function test_chat_lain_ditolak_tanpa_diberi_isi_laporan(): void
    {
        $this->palsukan([['teks' => '/timeline', 'chat' => '12345']]);

        $this->artisan('bot:layani')->assertSuccessful();

        $teks = $this->terkirim();

        $this->assertCount(1, $teks);
        $this->assertStringContainsString('hanya melayani pemiliknya', $teks[0]);
        $this->assertStringNotContainsString('Perkiraan siap', $teks[0]);
    }

    /** Perintah yang sama dua kali dalam satu batch cukup dijawab sekali. */
    public function test_perintah_kembar_dijawab_sekali(): void
    {
        $this->palsukan([['teks' => '/todolist'], ['teks' => '/todolist']]);

        $this->artisan('bot:layani')->assertSuccessful();

        $this->assertCount(1, $this->terkirim());
    }

    /**
     * Pesan yang sudah dibaca ditandai di sisi Telegram.
     *
     * Tanpa ini, alur berjadwal membaca ulang antrean yang sama setiap kali berjalan dan
     * menjawab perintah lama berulang-ulang. Penanda dikirim walau tidak ada perintah
     * yang cocok — obrolan biasa juga harus dibersihkan dari antrean.
     */
    public function test_menandai_pesan_sudah_dibaca(): void
    {
        $this->palsukan([['teks' => 'halo, apa kabar']]);

        $this->artisan('bot:layani')->assertSuccessful();

        $this->assertSame([], $this->terkirim(), 'obrolan biasa tidak dibalas');

        $adaPenanda = false;

        foreach (Http::recorded() as [$permintaan]) {
            if (str_contains($permintaan->url(), 'offset=1001')) {
                $adaPenanda = true;
            }
        }

        $this->assertTrue($adaPenanda, 'offset penanda baca harus dikirim');
    }

    public function test_tanpa_pesan_masuk_tidak_mengirim_apa_pun(): void
    {
        Http::fake([
            'api.telegram.org/*/getUpdates*' => Http::response(['ok' => true, 'result' => []]),
        ]);

        $this->artisan('bot:layani')->expectsOutputToContain('Tidak ada perintah baru')->assertSuccessful();

        $this->assertSame([], $this->terkirim());
    }

    public function test_menolak_jalan_tanpa_token(): void
    {
        config(['services.telegram.token' => null]);

        $this->artisan('bot:layani')->expectsOutputToContain('belum diisi')->assertFailed();
    }
}

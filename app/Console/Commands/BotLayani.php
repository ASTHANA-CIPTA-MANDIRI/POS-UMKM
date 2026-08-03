<?php

namespace App\Console\Commands;

use App\Support\JawabanBot;
use App\Support\PemeriksaUji;
use App\Support\PengirimTelegram;
use App\Support\RencanaProgres;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Menjawab perintah yang dikirim ke bot Telegram.
 *
 * CARA KERJANYA: menarik (polling), bukan webhook. Webhook menuntut alamat HTTPS yang
 * selalu hidup — yaitu server, yang justru belum ada. Menarik bisa dijalankan dari mana
 * saja, termasuk dari GitHub Actions berjadwal, sehingga perintah tetap dijawab walau
 * laptopnya mati.
 *
 * KONSEKUENSINYA HARUS DISADARI: balasannya tidak seketika. Kalau alurnya dijadwalkan
 * tiap 15 menit, jawaban datang paling lama 15 menit sesudah perintah dikirim. Ini bukan
 * bot obrolan; ini laporan yang bisa diminta.
 *
 * Penanda baca (offset) tidak disimpan di mana pun. getUpdates dipanggil sekali untuk
 * membaca, lalu dipanggil lagi dengan offset id terakhir + 1 — Telegram sendiri yang
 * mencatat bahwa pesan itu sudah dibaca. Tanpa cara ini, alur di CI yang tidak punya
 * penyimpanan tetap akan menjawab pesan lama berulang kali setiap kali berjalan.
 */
class BotLayani extends Command
{
    protected $signature = 'bot:layani
        {--batas=10 : Paling banyak perintah yang dilayani dalam satu jalan}
        {--kering : Tampilkan jawabannya saja, jangan dikirim}';

    protected $description
        = 'Membaca perintah yang masuk ke bot Telegram (/todolist, /timeline, /progress, /improvement, /bug) lalu menjawabnya';

    public function handle(PengirimTelegram $pengirim): int
    {
        if (! $pengirim->siap()) {
            $this->error('TELEGRAM_BOT_TOKEN dan TELEGRAM_CHAT_ID belum diisi.');

            return self::FAILURE;
        }

        $rencana = RencanaProgres::dariBerkas();

        if ($rencana === null) {
            $this->error('docs/RENCANA.md tidak terbaca.');

            return self::FAILURE;
        }

        $respons = Http::timeout(30)
            ->get("https://api.telegram.org/bot{$pengirim->token()}/getUpdates", ['timeout' => 0]);

        /*
         * Telegram yang MENOLAK harus membuat perintah ini gagal, bukan diam.
         *
         * Ditemukan saat mencoba jalur CI: dengan token salah, Telegram membalas 401 dan
         * `->json('result')` menjadi null, sehingga perintah ini dulu mencetak "tidak ada
         * perintah baru" lalu keluar SUKSES. Di alur berjadwal itu artinya run hijau yang
         * tidak pernah menjawab apa pun — kegagalan paling mahal karena tidak terlihat.
         */
        if ($respons->failed() || $respons->json('ok') !== true) {
            $this->error('Telegram menolak permintaan: HTTP '.$respons->status().' — '
                .($respons->json('description') ?? 'tanpa keterangan'));
            $this->line('Periksa TELEGRAM_BOT_TOKEN. Token yang dicabut di BotFather akan selalu ditolak.');

            return self::FAILURE;
        }

        $masuk = $respons->json('result') ?? [];

        if ($masuk === []) {
            $this->info('Tidak ada perintah baru.');

            return self::SUCCESS;
        }

        $idTerakhir = 0;
        $dilayani = 0;
        $dijawab = [];

        foreach ($masuk as $pembaruan) {
            $idTerakhir = max($idTerakhir, (int) ($pembaruan['update_id'] ?? 0));

            $pesan = $pembaruan['message'] ?? $pembaruan['edited_message'] ?? null;
            $teks = trim((string) ($pesan['text'] ?? ''));
            $chat = (string) ($pesan['chat']['id'] ?? '');

            /*
             * HANYA chat pemilik yang dilayani.
             *
             * Alamat bot Telegram bisa ditemukan siapa saja, dan jawaban bot ini berisi
             * rencana, tenggat, dan daftar cacat sistem yang belum ditambal — persis yang
             * tidak boleh dibaca orang luar. Chat lain dibalas satu kalimat penolakan
             * supaya tidak terkesan rusak, lalu diabaikan.
             */
            if ($chat !== $pengirim->chat()) {
                if ($chat !== '' && JawabanBot::kenali($teks) !== null) {
                    $pengirim->kirim('Bot ini hanya melayani pemiliknya.', $chat);
                }

                continue;
            }

            $perintah = JawabanBot::kenali($teks);

            if ($perintah === null || $dilayani >= (int) $this->option('batas')) {
                continue;
            }

            // Perintah yang sama dikirim dua kali dalam satu batch cukup dijawab sekali.
            if (in_array($perintah, $dijawab, true)) {
                continue;
            }

            $dijawab[] = $perintah;
            $dilayani++;

            /*
             * Uji hanya dijalankan untuk perintah yang memang membutuhkannya. /todolist,
             * /timeline, dan /improvement dijawab dari berkas rencana saja — kalau semuanya
             * ikut menjalankan suite, satu batch berisi tiga perintah memakan lebih dari
             * sepuluh menit dan alur berjadwalnya jadi mahal tanpa guna.
             */
            $butuhUji = in_array($perintah, JawabanBot::perintahBerat(), true);

            if ($butuhUji) {
                $pengirim->kirim('⏳ Menjalankan uji dulu, mohon tunggu…', $chat);
            }

            // Diambil dari container, bukan `new`: dengan begitu uji bisa memasang
            // pemeriksa palsu. Kalau tidak, menguji jalur /bug berarti menjalankan seluruh
            // suite DI DALAM suite — rekursi yang tidak akan pernah selesai.
            $jawaban = (new JawabanBot($rencana, $butuhUji ? app(PemeriksaUji::class) : null))->jawab($perintah);

            if ($this->option('kering')) {
                $this->line(html_entity_decode(strip_tags($jawaban), ENT_QUOTES, 'UTF-8'));

                continue;
            }

            [$berhasil, $galat] = $pengirim->kirim($jawaban, $chat);

            $berhasil
                ? $this->info("/{$perintah} dijawab.")
                : $this->error("/{$perintah} gagal dikirim: {$galat}");
        }

        $this->tandaiTerbaca($pengirim, $idTerakhir);

        return self::SUCCESS;
    }

    /**
     * Menandai pesan sudah dibaca di sisi Telegram.
     *
     * WAJIB, dan dijalankan walau tidak ada perintah yang cocok: tanpa ini, obrolan biasa
     * ("halo") menumpuk di antrean getUpdates dan setiap jalan berikutnya membaca ulang
     * semuanya. Pada alur berjadwal, itu berarti perintah lama dijawab berkali-kali.
     */
    private function tandaiTerbaca(PengirimTelegram $pengirim, int $idTerakhir): void
    {
        if ($idTerakhir <= 0 || $this->option('kering')) {
            return;
        }

        Http::timeout(20)->get(
            "https://api.telegram.org/bot{$pengirim->token()}/getUpdates",
            ['offset' => $idTerakhir + 1, 'timeout' => 0],
        );
    }
}

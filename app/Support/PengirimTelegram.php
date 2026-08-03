<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Pengirim pesan Telegram.
 *
 * Dipakai laporan berjadwal maupun bot yang menjawab perintah. Tiga hal yang membuatnya
 * tidak sesederhana "POST teks", dan semuanya sudah pernah menggagalkan pengiriman:
 * batas 4096 karakter, tag HTML yang terbelah saat dipotong, dan token yang ikut
 * tercetak ke log saat gagal.
 */
class PengirimTelegram
{
    /** Batas Telegram 4096; disisakan ruang untuk penanda potongan. */
    public const BATAS_PESAN = 3800;

    public function __construct(
        private readonly ?string $token = null,
        private readonly ?string $chatBawaan = null,
    ) {}

    public function siap(): bool
    {
        return $this->token() !== '' && $this->chat() !== '';
    }

    public function token(): string
    {
        return (string) ($this->token ?? config('services.telegram.token'));
    }

    public function chat(): string
    {
        return (string) ($this->chatBawaan ?? config('services.telegram.chat_id'));
    }

    /**
     * @return array{0: bool, 1: string} [berhasil, keterangan galat tanpa token]
     */
    public function kirim(string $teks, ?string $chat = null): array
    {
        foreach (self::potong($teks, self::BATAS_PESAN) as $nomor => $bagian) {
            $respons = Http::asForm()->timeout(20)->post(
                "https://api.telegram.org/bot{$this->token()}/sendMessage",
                [
                    'chat_id' => $chat ?? $this->chat(),
                    'text' => $bagian,
                    'parse_mode' => 'HTML',
                    'disable_web_page_preview' => true,
                ],
            );

            if ($respons->failed()) {
                // Token TIDAK ikut dikembalikan: keterangan galat sering berakhir di log
                // dan riwayat terminal yang dibagikan.
                return [false, 'bagian '.($nomor + 1).': HTTP '.$respons->status().' — '
                    .Str::limit((string) $respons->json('description', $respons->body()), 200)];
            }
        }

        return [true, ''];
    }

    /** Melolosi teks agar tidak merusak parse_mode HTML Telegram. */
    public static function aman(string $teks): string
    {
        return htmlspecialchars($teks, ENT_NOQUOTES, 'UTF-8');
    }

    /**
     * Memotong pesan panjang di batas BARIS, bukan di tengah kata.
     *
     * Potongan yang membelah tag HTML membuat seluruh bagian itu DITOLAK Telegram dengan
     * galat "can't parse entities" — bukan sekadar terlihat jelek.
     *
     * @return array<int, string>
     */
    public static function potong(string $teks, int $batas): array
    {
        if (mb_strlen($teks) <= $batas) {
            return [$teks];
        }

        $bagian = [];
        $sekarang = '';

        foreach (explode("\n", $teks) as $baris) {
            if ($sekarang !== '' && mb_strlen($sekarang."\n".$baris) > $batas) {
                $bagian[] = $sekarang;
                $sekarang = $baris;

                continue;
            }

            $sekarang = $sekarang === '' ? $baris : $sekarang."\n".$baris;
        }

        if ($sekarang !== '') {
            $bagian[] = $sekarang;
        }

        return $bagian;
    }
}

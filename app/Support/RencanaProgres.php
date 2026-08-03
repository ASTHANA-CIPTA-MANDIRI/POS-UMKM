<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Pembaca docs/RENCANA.md.
 *
 * Dipakai bersama oleh laporan berjadwal (LaporTelegram) dan bot yang menjawab perintah
 * (BotLayani). Ditaruh di satu tempat supaya kedua jalur itu tidak pernah menyebut angka
 * yang berbeda untuk pertanyaan yang sama — dua sumber kebenaran pada laporan progres
 * adalah cara tercepat membuat orang berhenti mempercayai keduanya.
 */
class RencanaProgres
{
    /** @param array<int, array<string, mixed>> $pekerjaan */
    private function __construct(
        public readonly array $pekerjaan,
        public readonly array $catatan,
        public readonly float $jamPerHari,
        public readonly int $hariPerPekan,
    ) {}

    public static function dariBerkas(?string $berkas = null): ?self
    {
        $berkas ??= base_path('docs/RENCANA.md');

        if (! is_file($berkas)) {
            return null;
        }

        $isi = (string) file_get_contents($berkas);
        $pekerjaan = [];
        $bagian = '';

        /*
         * Baris rencana: "- [x] Judul | 2026-08-05 | area | 6j".
         *
         * Dipecah per pipa, bukan dengan satu regex raksasa: kolom yang boleh kosong
         * membuat regexnya cepat jadi tak terbaca, dan format yang menuntut semua kolom
         * akan membuat orang berhenti memperbaruinya — berkas yang tidak diperbarui
         * adalah laporan yang berbohong.
         *
         * Judul bagian ikut dicatat karena itu yang menentukan apakah sebuah pekerjaan
         * MENGHALANGI rilis atau tidak.
         */
        foreach (explode("\n", $isi) as $baris) {
            if (preg_match('/^##\s*(.+)$/', trim($baris), $judul) === 1) {
                $bagian = strtolower(trim($judul[1]));

                continue;
            }

            if (preg_match('/^- \[([ x~])\]\s*(.+)$/', trim($baris), $cocok) !== 1) {
                continue;
            }

            $kolom = array_map('trim', explode('|', $cocok[2]));
            $target = $kolom[1] ?? '';

            $pekerjaan[] = [
                'status' => match ($cocok[1]) {
                    'x' => 'selesai',
                    '~' => 'berjalan',
                    default => 'belum',
                },
                'judul' => $kolom[0],
                'target' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $target) === 1 ? $target : null,
                'area' => ($kolom[2] ?? '') ?: '-',
                'jam' => (float) preg_replace('/[^0-9.]/', '', $kolom[3] ?? ''),
                // "Wajib sebelum deploy" = penghalang rilis. Sisanya tidak dihitung ke
                // tanggal siap: pembeda memang dikerjakan sesudah rilis.
                'penghalang' => str_contains($bagian, 'sebelum deploy'),
            ];
        }

        if ($pekerjaan === []) {
            return null;
        }

        $angka = function (string $pola, float $bawaan) use ($isi): float {
            return preg_match($pola, $isi, $cocok) === 1 ? (float) $cocok[1] : $bawaan;
        };

        return new self(
            pekerjaan: $pekerjaan,
            catatan: self::bacaCatatan($isi),
            jamPerHari: max(0.5, $angka('/jam per hari:\s*([0-9.]+)/i', 4)),
            hariPerPekan: (int) min(7, max(1, $angka('/hari kerja per pekan:\s*([0-9]+)/i', 6))),
        );
    }

    /** @return array<int, string> */
    private static function bacaCatatan(string $isi): array
    {
        if (preg_match('/^## Catatan\s*$(.*)/ms', $isi, $cocok) !== 1) {
            return [];
        }

        preg_match_all('/^- (.+(?:\n  .+)*)$/m', $cocok[1], $baris, PREG_SET_ORDER);

        return array_map(fn ($b) => (string) preg_replace('/\s+/', ' ', trim($b[1])), $baris);
    }

    /** @return array<int, array<string, mixed>> */
    public function berstatus(string $status): array
    {
        return array_values(array_filter($this->pekerjaan, fn ($p) => $p['status'] === $status));
    }

    /** Yang menahan rilis dan belum selesai. @return array<int, array<string, mixed>> */
    public function penghalangRilis(): array
    {
        return array_values(array_filter(
            $this->pekerjaan,
            fn ($p) => $p['penghalang'] && $p['status'] !== 'selesai',
        ));
    }

    /** Pembeda yang dikerjakan sesudah rilis. @return array<int, array<string, mixed>> */
    public function pembeda(): array
    {
        return array_values(array_filter(
            $this->pekerjaan,
            fn ($p) => ! $p['penghalang'] && $p['status'] !== 'selesai',
        ));
    }

    /** @return array<int, array<string, mixed>> */
    public function telat(): array
    {
        return array_values(array_filter(
            $this->pekerjaan,
            fn ($p) => $p['status'] !== 'selesai' && $p['target'] !== null
                && Carbon::parse($p['target'])->lt(Carbon::today()),
        ));
    }

    /** @return array<int, array<string, mixed>> */
    public function jatuhTempoPekanIni(): array
    {
        $hari = Carbon::today();

        return array_values(array_filter(
            $this->pekerjaan,
            fn ($p) => $p['status'] !== 'selesai' && $p['target'] !== null
                && Carbon::parse($p['target'])->betweenIncluded($hari, $hari->copy()->addDays(7)),
        ));
    }

    public function persenSelesai(): int
    {
        $total = count($this->pekerjaan);

        return $total > 0 ? (int) round(count($this->berstatus('selesai')) / $total * 100) : 0;
    }

    public function jamSisaRilis(): float
    {
        return (float) array_sum(array_column($this->penghalangRilis(), 'jam'));
    }

    public function tanggalPerkiraanSiap(): Carbon
    {
        return Carbon::today()->addDays($this->hariMenujuRilis());
    }

    public function hariMenujuRilis(): int
    {
        return self::proyeksiHariKalender($this->jamSisaRilis(), $this->jamPerHari, $this->hariPerPekan);
    }

    /**
     * Berapa HARI KALENDER untuk menyelesaikan sejumlah jam kerja.
     *
     * Rumusnya sengaja sederhana dan bisa dijelaskan: jam → hari kerja → dibentang ke
     * hari kalender menurut jumlah hari kerja per pekan. Tidak ada usaha menebak hari
     * libur nasional atau hari mana yang kosong — ketepatan semu pada tanggal rilis
     * lebih menyesatkan daripada angka kasar yang jujur.
     */
    public static function proyeksiHariKalender(float $jam, float $jamPerHari, int $hariPerPekan): int
    {
        if ($jam <= 0) {
            return 0;
        }

        $hariKerja = (int) ceil($jam / max(0.5, $jamPerHari));

        return (int) ceil($hariKerja / max(1, $hariPerPekan) * 7);
    }
}

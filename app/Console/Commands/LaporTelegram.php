<?php

namespace App\Console\Commands;

use App\Support\JawabanBot;
use App\Support\PemeriksaUji;
use App\Support\PengirimTelegram;
use App\Support\RencanaProgres;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Mengirim laporan progres lengkap ke Telegram.
 *
 * Isi laporannya dua macam, dan pembagiannya disengaja:
 *
 * 1. Yang DIKETIK orang — daftar rencana & catatan di docs/RENCANA.md.
 * 2. Yang DIUKUR mesin — hasil uji, uji yang gagal, dan aktivitas git.
 *
 * Bagian kedua itu yang membuat laporan ini bukan sekadar daftar harapan. Laporan yang
 * seluruhnya ditulis tangan akan tetap berbunyi "hampir selesai" sampai hari tenggat;
 * jumlah uji yang gagal tidak bisa diyakinkan siapa pun.
 *
 * Bawaannya hanya MENAMPILKAN di layar. Mengirim butuh --kirim, supaya isinya bisa
 * diperiksa dulu tanpa mengirimi orang pesan setengah jadi.
 */
class LaporTelegram extends Command
{
    protected $signature = 'lapor:telegram
        {--kirim : Kirim ke Telegram, bukan hanya ditampilkan}
        {--tanpa-uji : Lewati menjalankan suite (laporan lebih cepat, tanpa angka uji)}
        {--pesan= : Kirim satu kabar singkat saja, tanpa menyusun laporan lengkap}';

    protected $description = 'Menyusun laporan progres dari docs/RENCANA.md + hasil uji, lalu mengirimnya ke Telegram';

    public function handle(PengirimTelegram $pengirim): int
    {
        /*
         * Kabar singkat: dipakai alur QA → lead → BE/FE. Satu putaran perbaikan bisa
         * menghasilkan empat kabar (temuan, penugasan, selesai dikerjakan, hasil uji
         * ulang), dan menjalankan seluruh suite untuk tiap kabar akan membuat alurnya
         * berhenti dipakai. Laporan lengkap tetap dikirim di akhir sesi.
         */
        if (($pesan = $this->option('pesan')) !== null) {
            $teks = '<b>Nampan POS</b> · '.now()->format('d/m H:i')."\n"
                .PengirimTelegram::aman(trim((string) $pesan));

            return $this->keluarkan($teks, $pengirim);
        }

        $rencana = RencanaProgres::dariBerkas();

        if ($rencana === null) {
            $this->error('docs/RENCANA.md tidak ada atau tidak berisi baris rencana yang bisa dibaca.');

            return self::FAILURE;
        }

        if (! $this->option('tanpa-uji')) {
            $this->comment('Menjalankan uji…');
        }

        $uji = $this->option('tanpa-uji') ? null : (new PemeriksaUji)->jalankan();

        return $this->keluarkan($this->susun($rencana, $uji), $pengirim);
    }

    private function keluarkan(string $teks, PengirimTelegram $pengirim): int
    {
        if (! $this->option('kirim')) {
            $this->line(html_entity_decode(strip_tags($teks), ENT_QUOTES, 'UTF-8'));
            $this->newLine();
            $this->comment('Belum dikirim. Tambahkan --kirim untuk mengirim ke Telegram.');

            return self::SUCCESS;
        }

        if (! $pengirim->siap()) {
            $this->error('TELEGRAM_BOT_TOKEN dan TELEGRAM_CHAT_ID belum diisi di .env.');
            $this->line('Cara mendapatkannya ada di docs/otomatis-lokal.md.');

            return self::FAILURE;
        }

        [$berhasil, $galat] = $pengirim->kirim($teks);

        if (! $berhasil) {
            $this->error('Gagal mengirim '.$galat);

            return self::FAILURE;
        }

        $this->info('Laporan terkirim.');

        return self::SUCCESS;
    }

    /** @param array<string, array<string, mixed>>|null $uji */
    private function susun(RencanaProgres $rencana, ?array $uji): string
    {
        $hari = Carbon::today();
        $selesai = $rencana->berstatus('selesai');
        $berjalan = $rencana->berstatus('berjalan');
        $belum = $rencana->berstatus('belum');
        $persen = $rencana->persenSelesai();

        $b = [];
        $b[] = '<b>Nampan POS — progres '.$hari->locale('id')->translatedFormat('d F Y').'</b>';
        $b[] = '';
        $b[] = $this->bilah($persen).' <b>'.$persen.'%</b> ('.count($selesai).'/'.count($rencana->pekerjaan).' pekerjaan)';
        $b[] = 'Sedang dikerjakan: '.count($berjalan).' · Belum mulai: '.count($belum);

        /*
         * Kesiapan deploy: yang paling ditanya pemilik proyek, dan satu-satunya angka di
         * laporan ini yang berupa ramalan. Karena itu asumsinya ikut dicantumkan — tanggal
         * tanpa asumsi akan dibaca sebagai janji.
         */
        $penghalang = $rencana->penghalangRilis();

        if ($penghalang !== []) {
            $b[] = '';
            $b[] = '🚀 <b>Kesiapan deploy</b>';
            $b[] = 'Sisa wajib: '.count($penghalang).' pekerjaan · '.$this->jamManusiawi($rencana->jamSisaRilis());
            $b[] = 'Kapasitas: '.$this->angka($rencana->jamPerHari).' jam/hari · '.$rencana->hariPerPekan.' hari/pekan';
            $b[] = 'Perkiraan siap: <b>'.$rencana->tanggalPerkiraanSiap()->locale('id')->translatedFormat('d F Y')
                .'</b> (± '.$rencana->hariMenujuRilis().' hari)';

            if ($uji !== null && ($uji['php']['gagal'] + $uji['js']['gagal']) > 0) {
                $b[] = '⛔ Belum bisa rilis: masih ada '.($uji['php']['gagal'] + $uji['js']['gagal']).' uji gagal.';
            }

            $pembeda = $rencana->pembeda();

            if ($pembeda !== []) {
                $b[] = 'Sesudah rilis (pembeda): '.count($pembeda).' pekerjaan · '
                    .$this->jamManusiawi((float) array_sum(array_column($pembeda, 'jam')));
            }
        }

        $telat = $rencana->telat();

        if ($telat !== []) {
            $b[] = '';
            $b[] = '⚠️ <b>Telat dari target</b>';
            foreach ($telat as $p) {
                $b[] = '• '.PengirimTelegram::aman((string) $p['judul']).' — target '.$p['target']
                    .', lewat '.Carbon::parse($p['target'])->diffInDays($hari).' hari';
            }
        }

        if ($berjalan !== []) {
            $b[] = '';
            $b[] = '🔧 <b>Sedang dikerjakan</b>';
            $b[] = $this->daftar($berjalan, 5);
        }

        $tempo = $rencana->jatuhTempoPekanIni();

        if ($tempo !== []) {
            $b[] = '';
            $b[] = '📅 <b>Jatuh tempo 7 hari ke depan</b>';
            $b[] = $this->daftar($tempo, 6);
        }

        $belumWajib = array_values(array_filter($belum, fn ($p) => $p['penghalang']));
        $belumNanti = array_values(array_filter($belum, fn ($p) => ! $p['penghalang']));

        if ($belumWajib !== []) {
            $b[] = '';
            $b[] = '⏳ <b>Belum mulai — wajib untuk rilis</b> ('.count($belumWajib).')';
            $b[] = $this->daftar($belumWajib, 8);
        }

        if ($belumNanti !== []) {
            $b[] = '';
            $b[] = '💡 <b>Pembeda, sesudah rilis</b> ('.count($belumNanti).')';
            $b[] = $this->daftar($belumNanti, 5);
        }

        if ($uji !== null) {
            $b[] = '';
            $b[] = '🧪 <b>Uji</b>';
            $b[] = '• PHP: '.$this->angkaUji($uji['php']);
            $b[] = '• JS: '.$this->angkaUji($uji['js']);

            $gagal = array_merge($uji['php']['nama'], $uji['js']['nama']);

            if ($gagal !== []) {
                $b[] = '';
                $b[] = '🔴 <b>Perlu diperbaiki</b> ('.count($gagal).' uji gagal)';
                foreach (array_slice($gagal, 0, 10) as $nama) {
                    $b[] = '• '.PengirimTelegram::aman($nama);
                }
                if (count($gagal) > 10) {
                    $b[] = '• … dan '.(count($gagal) - 10).' lagi';
                }
            }
        }

        if ($rencana->catatan !== []) {
            $b[] = '';
            $b[] = '📝 <b>Catatan &amp; keputusan yang menggantung</b>';
            foreach ($rencana->catatan as $catatan) {
                $b[] = '• '.PengirimTelegram::aman($catatan);
            }
        }

        $git = $this->ringkasGit();
        $b[] = '';
        $b[] = '<i>Cabang '.PengirimTelegram::aman((string) $git['cabang']).' · '.$git['komit7hari']
            .' komit 7 hari terakhir · terakhir: '.PengirimTelegram::aman(Str::limit((string) $git['terakhir'], 70)).'</i>';
        $b[] = '';
        $b[] = '<i>Minta kapan saja: /todolist /timeline /progress /improvement /bug</i>';

        return implode("\n", $b);
    }

    /** @return array{cabang: string, komit7hari: int, terakhir: string} */
    private function ringkasGit(): array
    {
        $jalankan = function (string $perintah): string {
            $hasil = Process::path(base_path())->run($perintah);

            return $hasil->successful() ? trim($hasil->output()) : '';
        };

        return [
            // git branch --show-current tetap menjawab pada repo yang belum punya komit.
            'cabang' => $jalankan('git branch --show-current') ?: '(belum ada cabang)',
            'komit7hari' => (int) ($jalankan('git rev-list --count --since=7.days HEAD') ?: 0),
            'terakhir' => $jalankan('git log -1 --pretty=%s') ?: 'belum ada komit sama sekali',
        ];
    }

    /** @param array<int, array<string, mixed>> $pekerjaan */
    private function daftar(array $pekerjaan, int $batas): string
    {
        $baris = [];

        foreach (array_slice($pekerjaan, 0, $batas) as $p) {
            $jam = $p['jam'] > 0 ? ' · '.$this->angka((float) $p['jam']).'j' : '';
            $target = $p['target'] !== null ? ' — '.$p['target'] : ' <i>(belum dijadwalkan)</i>';
            $baris[] = '• '.PengirimTelegram::aman((string) $p['judul']).$jam.$target;
        }

        if (count($pekerjaan) > $batas) {
            $baris[] = '• … dan '.(count($pekerjaan) - $batas).' lagi';
        }

        return implode("\n", $baris);
    }

    /** @param array{jumlah: int, gagal: int, dilewati: int, nama: array<int, string>} $angka */
    private function angkaUji(array $angka): string
    {
        if ($angka['jumlah'] === 0) {
            return 'tidak terbaca';
        }

        $teks = ($angka['jumlah'] - $angka['gagal'] - $angka['dilewati']).'/'.$angka['jumlah'].' lulus';

        if ($angka['gagal'] > 0) {
            $teks .= ', <b>'.$angka['gagal'].' gagal</b>';
        }

        return $angka['dilewati'] > 0 ? $teks.', '.$angka['dilewati'].' dilewati' : $teks;
    }

    private function jamManusiawi(float $jam): string
    {
        $teks = $this->angka($jam).' jam';

        return $jam >= 8 ? $teks.' (± '.$this->angka(round($jam / 8, 1)).' hari penuh)' : $teks;
    }

    private function angka(float $nilai): string
    {
        return rtrim(rtrim(number_format($nilai, 1, ',', '.'), '0'), ',');
    }

    /** Bilah teks: terbaca di Telegram tanpa gambar dan tanpa lampiran. */
    private function bilah(int $persen): string
    {
        $penuh = (int) round($persen / 10);

        return str_repeat('▰', $penuh).str_repeat('▱', 10 - $penuh);
    }

    /** @return array<int, string> */
    public static function potong(string $teks, int $batas): array
    {
        // Tetap ada supaya uji lama dan pemanggil luar tidak perlu tahu perpindahannya.
        return PengirimTelegram::potong($teks, $batas);
    }

    public static function proyeksiHariKalender(float $jam, float $jamPerHari, int $hariPerPekan): int
    {
        return RencanaProgres::proyeksiHariKalender($jam, $jamPerHari, $hariPerPekan);
    }

    /** @return array<int, string> */
    public static function perintahBot(): array
    {
        return JawabanBot::perintahBerat();
    }
}

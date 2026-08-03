<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Menyusun jawaban untuk perintah bot Telegram.
 *
 * Lima perintah yang dipasang di menu bot, dan satu aturan yang membentuk semuanya:
 * jawabannya harus bisa dibaca sambil berjalan, di layar ponsel, tanpa perlu membuka
 * laptop. Karena itu tidak ada tabel, tidak ada tautan yang harus diklik, dan angka
 * penting selalu di baris pertama.
 *
 * Perintah yang berat (yang menjalankan suite uji) dipisahkan dari yang ringan: /todolist,
 * /timeline, dan /improvement dijawab dari berkas rencana saja sehingga balasannya datang
 * dalam hitungan detik. Hanya /progress dan /bug yang menjalankan uji.
 */
class JawabanBot
{
    public function __construct(
        private readonly RencanaProgres $rencana,
        private readonly ?PemeriksaUji $pemeriksa = null,
    ) {}

    /** @return array<int, string> */
    public static function perintahBerat(): array
    {
        return ['progress', 'bug'];
    }

    /**
     * Memetakan teks perintah ke nama kanonis.
     *
     * Aliasnya banyak dengan sengaja: nama perintah di menu BotFather boleh berbeda dari
     * yang diketik orang, dan salah ketik yang wajar ("improvment", "progres") tidak boleh
     * membuat bot diam. Bot yang diam terasa rusak.
     */
    public static function kenali(string $teks): ?string
    {
        $kata = strtolower(trim(explode(' ', ltrim($teks, '/'))[0]));
        $kata = explode('@', $kata)[0];   // /todolist@PosUMKMbot di dalam grup

        return match (true) {
            in_array($kata, ['todolist', 'todo', 'tugas', 'command1'], true) => 'todolist',
            in_array($kata, ['timeline', 'jadwal', 'tenggat', 'command2'], true) => 'timeline',
            in_array($kata, ['progress', 'progres', 'command3'], true) => 'progress',
            in_array($kata, ['improvement', 'improvment', 'fitur', 'pembeda', 'command4'], true) => 'improvement',
            in_array($kata, ['bug', 'cacat', 'error', 'command5'], true) => 'bug',
            in_array($kata, ['start', 'help', 'bantuan', 'menu'], true) => 'bantuan',
            default => null,
        };
    }

    public function jawab(string $perintah): string
    {
        return match ($perintah) {
            'todolist' => $this->todolist(),
            'timeline' => $this->timeline(),
            'progress' => $this->progress(),
            'improvement' => $this->improvement(),
            'bug' => $this->bug(),
            default => $this->bantuan(),
        };
    }

    /* ── 1. /todolist ───────────────────────────────────────────────────────── */

    private function todolist(): string
    {
        $berjalan = $this->rencana->berstatus('berjalan');
        $wajib = array_values(array_filter($this->rencana->penghalangRilis(), fn ($p) => $p['status'] === 'belum'));

        $b = ['<b>📋 Daftar tugas</b>'];

        if ($berjalan !== []) {
            $b[] = '';
            $b[] = '<b>Sedang dikerjakan</b>';
            $b[] = $this->daftar($berjalan, 5);
        }

        $b[] = '';
        $b[] = '<b>Berikutnya — wajib untuk rilis</b> ('.count($wajib).')';
        $b[] = $wajib === [] ? '• tidak ada, semua penghalang rilis sudah selesai' : $this->daftar($wajib, 12);

        $telat = $this->rencana->telat();

        if ($telat !== []) {
            $b[] = '';
            $b[] = '⚠️ <b>Lewat target</b>';
            $b[] = $this->daftar($telat, 5);
        }

        return implode("\n", $b);
    }

    /* ── 2. /timeline ───────────────────────────────────────────────────────── */

    private function timeline(): string
    {
        $penghalang = $this->rencana->penghalangRilis();
        $jam = $this->rencana->jamSisaRilis();
        $hari = $this->rencana->hariMenujuRilis();

        $b = ['<b>🚀 Timeline sampai siap deploy</b>'];
        $b[] = '';

        if ($penghalang === []) {
            $b[] = 'Tidak ada lagi yang menahan rilis.';

            return implode("\n", $b);
        }

        $b[] = 'Perkiraan siap: <b>'.$this->rencana->tanggalPerkiraanSiap()->locale('id')->translatedFormat('d F Y').'</b>';
        $b[] = '± '.$hari.' hari dari hari ini ('.Carbon::today()->locale('id')->translatedFormat('d M Y').')';
        $b[] = '';
        $b[] = 'Sisa wajib: '.count($penghalang).' pekerjaan · '.$this->jamManusiawi($jam);
        $b[] = 'Kapasitas dipakai: '.$this->angka($this->rencana->jamPerHari).' jam/hari · '
            .$this->rencana->hariPerPekan.' hari/pekan';
        $b[] = '';
        $b[] = '<i>Tanggal ini turun sendiri setiap satu pekerjaan ditandai selesai. Kalau '
            .'kapasitasnya tidak sesuai, ubah di docs/RENCANA.md — di situ tanggalnya berubah.</i>';

        $tempo = $this->rencana->jatuhTempoPekanIni();

        if ($tempo !== []) {
            $b[] = '';
            $b[] = '📅 <b>Jatuh tempo 7 hari ke depan</b>';
            $b[] = $this->daftar($tempo, 6);
        }

        $pembeda = $this->rencana->pembeda();

        if ($pembeda !== []) {
            $b[] = '';
            $b[] = 'Sesudah rilis: '.count($pembeda).' pembeda · '
                .$this->jamManusiawi((float) array_sum(array_column($pembeda, 'jam')));
        }

        return implode("\n", $b);
    }

    /* ── 3. /progress ───────────────────────────────────────────────────────── */

    private function progress(): string
    {
        $persen = $this->rencana->persenSelesai();
        $selesai = count($this->rencana->berstatus('selesai'));
        $total = count($this->rencana->pekerjaan);

        $b = ['<b>📊 Progres sistem</b>'];
        $b[] = '';
        $b[] = $this->bilah($persen).' <b>'.$persen.'%</b>';
        $b[] = $selesai.' dari '.$total.' pekerjaan selesai';
        $b[] = 'Berjalan: '.count($this->rencana->berstatus('berjalan'))
            .' · Belum: '.count($this->rencana->berstatus('belum'));

        $uji = $this->pemeriksa?->jalankan();

        if ($uji !== null) {
            $b[] = '';
            $b[] = '<b>Uji otomatis</b>';
            $b[] = '• PHP: '.$this->angkaUji($uji['php']);
            $b[] = '• JS: '.$this->angkaUji($uji['js']);

            $gagal = $uji['php']['gagal'] + $uji['js']['gagal'];
            $b[] = '';
            $b[] = $gagal === 0
                ? '✅ Semua uji hijau — layak dilanjutkan ke rilis.'
                : '⛔ Belum layak rilis: '.$gagal.' uji gagal. Kirim /bug untuk daftarnya.';
        }

        return implode("\n", $b);
    }

    /* ── 4. /improvement ────────────────────────────────────────────────────── */

    private function improvement(): string
    {
        $pembeda = $this->rencana->pembeda();

        $b = ['<b>💡 Ide pembeda dari POS lain</b>'];
        $b[] = '';

        if ($pembeda === []) {
            $b[] = 'Belum ada yang tercatat di docs/RENCANA.md bagian "Sesudah deploy".';

            return implode("\n", $b);
        }

        $b[] = $this->daftar($pembeda, 12);
        $b[] = '';
        $b[] = 'Total '.$this->jamManusiawi((float) array_sum(array_column($pembeda, 'jam')))
            .', dan TIDAK menahan rilis.';

        return implode("\n", $b);
    }

    /* ── 5. /bug ────────────────────────────────────────────────────────────── */

    private function bug(): string
    {
        $b = ['<b>🐞 Cacat yang sedang ditandai uji</b>'];
        $uji = $this->pemeriksa?->jalankan();

        if ($uji === null) {
            $b[] = '';
            $b[] = 'Uji tidak dijalankan.';

            return implode("\n", $b);
        }

        $gagal = array_merge($uji['php']['nama'], $uji['js']['nama']);

        $b[] = '';

        if ($gagal === []) {
            $b[] = '✅ Tidak ada uji yang gagal.';
            $b[] = 'PHP '.$this->angkaUji($uji['php']).' · JS '.$this->angkaUji($uji['js']);

            return implode("\n", $b);
        }

        $b[] = $gagal === [] ? '' : '<b>'.count($gagal).' uji gagal</b>';

        foreach (array_slice($gagal, 0, 15) as $nama) {
            $b[] = '• '.PengirimTelegram::aman($nama);
        }

        if (count($gagal) > 15) {
            $b[] = '• … dan '.(count($gagal) - 15).' lagi';
        }

        $b[] = '';
        $b[] = '<i>Nama uji di proyek ini menerangkan perilakunya, jadi daftar di atas '
            .'sudah menjadi daftar cacatnya.</i>';

        return implode("\n", $b);
    }

    private function bantuan(): string
    {
        return implode("\n", [
            '<b>Nampan POS — bot laporan</b>',
            '',
            '/todolist — apa yang belum dikerjakan',
            '/timeline — perkiraan tanggal siap deploy',
            '/progress — persen selesai + hasil uji',
            '/improvement — ide pembeda dari POS lain',
            '/bug — cacat yang sedang ditandai uji',
            '',
            '<i>/progress dan /bug menjalankan seluruh uji, jadi balasannya beberapa menit.</i>',
        ]);
    }

    /* ── Pembantu ───────────────────────────────────────────────────────────── */

    /** @param array<int, array<string, mixed>> $pekerjaan */
    private function daftar(array $pekerjaan, int $batas): string
    {
        $baris = [];

        foreach (array_slice($pekerjaan, 0, $batas) as $p) {
            $jam = $p['jam'] > 0 ? ' · '.$this->angka((float) $p['jam']).'j' : '';
            $target = $p['target'] !== null ? ' · '.$p['target'] : '';
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
}

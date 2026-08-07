<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Penjaga SUMBER: setiap pemicu hapus di layar mana pun bertanya lewat pembungkus bersama.
 *
 * Pemilik proyek meminta ini tegas — "untuk delete gunakan sweet alert, terapkan di semua
 * fitur" — dan permintaan seperti itu luntur tanpa penjaga: layar berikutnya menyalin bentuk
 * layar sebelumnya, jadi satu panel dua langkah yang tertinggal akan berkembang biak. Tiga
 * bentuk yang dilarang, masing-masing pernah ada di repo ini:
 *
 *  1. `confirm()` bawaan peramban — teksnya tidak bisa menyebut nama barangnya, tombolnya
 *     tidak bisa diberi warna bahaya, dan di layar sentuh dialog sistem sering tertekan
 *     tanpa terbaca.
 *  2. Panel dua langkah SEBARIS ("Ya, hapus / Batal" menukar isi sel) — tombol pembenarnya
 *     muncul tepat di bawah jempol yang baru menekan ikon hapus, dan kemunculannya menggeser
 *     tombol baris lain.
 *  3. `Swal.fire` mentah per layar — teks, warna, dan URUTAN tombolnya bercabang, sehingga
 *     "tombol kanan berarti melanjutkan" berhenti bisa dipelajari.
 *
 * Yang benar: `window.konfirmasiNampan({...})` (resources/js/toast.js), SweetAlert yang
 * dibundel (bukan CDN — layar kasir wajib jalan tanpa jaringan), tombol pembenar
 * `.tombol-bahaya`, dan fokus awal di tombol batal.
 *
 * Penjaga ini membaca KODE, bukan prosa: komentar dibuang lebih dulu. Versi pertamanya
 * menuduh komentar yang berbunyi "bukan confirm() bawaan" di dua berkas — dan peringatan
 * yang tidak boleh menyebut nama hal yang dilarangnya berhenti mengajari siapa pun.
 */
class KonfirmasiHapusTest extends TestCase
{
    /**
     * Berkas yang SENGAJA belum dijaga, berikut alasannya.
     *
     * `resources/views/livewire/pages/kasir/transaksi.blade.php` — sedang dikerjakan
     * gelombang ini oleh agen lain, jadi menyentuhnya berarti dua agen di satu berkas.
     * Diperiksa saat pengecualian ini dibuat (2026-08-06): satu tuduhan tersisa di situ,
     * tombol "Hapus pembayaran" pada bayar terpisah — dan tuduhan itu mungkin memang tidak
     * perlu dituruti, karena yang dibuang adalah baris yang baru diketik dan belum tersimpan.
     * Keputusannya milik siapa pun yang menyelesaikan layar itu.
     *
     * Pengecualian ini BERNAMA supaya gampang dicabut: hapus barisnya, jalankan uji ini, dan
     * yang tersisa muncul sebagai daftar pekerjaan — bukan sebagai penjaga yang melemah untuk
     * seluruh aplikasi.
     *
     * @var array<int, string>
     */
    private const DIKERJAKAN_GELOMBANG_INI = [
        // sedang dikerjakan gelombang ini
        'resources/views/livewire/pages/kasir/transaksi.blade.php',
    ];

    /**
     * `confirm()` bawaan peramban dilarang di seluruh layar.
     *
     * Dialog sistem tidak bisa menyebut nama barangnya, tidak bisa memberi warna bahaya pada
     * tombol pembenarnya, dan di tablet tampil sebagai potongan kecil di puncak layar yang
     * tertekan sambil lalu.
     */
    public function test_tidak_ada_confirm_bawaan_peramban(): void
    {
        $pelanggar = [];

        foreach ($this->halamanBlade() as $jalur => $isi) {
            // `konfirmasiNampan(` sengaja tidak ikut tertangkap: yang dicari kata `confirm`
            // sebagai pemanggilan fungsi, bukan bagian dari nama lain.
            if (preg_match('/\bconfirm\s*\(/', $isi) === 1) {
                $pelanggar[] = $jalur;
            }
        }

        $this->assertSame([], $pelanggar,
            'pakai window.konfirmasiNampan (resources/js/toast.js), bukan confirm() bawaan: '
            .implode(', ', $pelanggar));
    }

    /**
     * Tidak ada panel konfirmasi hapus SEBARIS.
     *
     * Sidik jarinya adalah tombol pembenar yang digambar di halaman: "Ya, hapus", "Ya, buang
     * N baris". Sejak konfirmasinya lewat SweetAlert, tombol seperti itu hidup di dalam
     * dialognya (dan teksnya dikirim sebagai `tombolYa`), bukan sebagai elemen halaman.
     *
     * Bentuk lamanya sudah pernah ada di layar produk: sel aksinya ditukar menjadi "Ya, hapus
     * / Batal", sehingga tombol pembenarnya muncul persis di tempat jempol baru menekan ikon
     * hapus — dan ketiga tombol baris itu bergeser saat panelnya muncul.
     */
    public function test_tidak_ada_panel_konfirmasi_hapus_sebaris(): void
    {
        $pelanggar = [];

        foreach ($this->halamanBlade() as $jalur => $isi) {
            foreach ($this->elemen($isi) as [$atribut, $label]) {
                // "Ya, hapus", "Ya, buang 12 pesanan", "Ya, batalkan nota". Yang berupa
                // pertanyaan ("Hapus foto", "Batalkan nota") adalah PEMICU dan memang tetap ada
                // di halaman.
                if (preg_match('/^ya[,\s].*\b(hapus|buang|void|batalkan)\b/i', $label) !== 1) {
                    continue;
                }

                // Tombol di dalam dialog SweetAlert tidak digambar di Blade, jadi apa pun yang
                // cocok di sini adalah tombol halaman — kecuali kalau ia justru pemicu dialog.
                if (str_contains($atribut, 'konfirmasiNampan')) {
                    continue;
                }

                $pelanggar[] = $jalur.' — "'.Str::limit($label, 40).'"';
            }
        }

        $this->assertSame([], $pelanggar,
            'tombol pembenar hapus hidup di dalam dialog konfirmasiNampan, bukan di dalam '
            .'barisnya: '.implode(', ', $pelanggar));
    }

    /**
     * Setiap pemicu hapus benar-benar lewat `konfirmasiNampan`.
     *
     * Yang dihitung sebagai pemicu hapus: elemen yang labelnya menyebut hapus DAN yang
     * klik-nya memanggil metode hapus di server (`wire:click="hapus(…)"`, `$wire.hapusBukti()`).
     * Dua syarat, bukan satu, supaya penjaga ini tidak menuduh hal yang tidak menghapus data:
     *
     *  - tombol "Hapus" di kolom nominal modal awal kasir hanya mengosongkan isian
     *    (`nilai = null`) — tidak ada yang hilang, dan dialog di situ hanya mengajari orang
     *    mengabaikan dialog;
     *  - tombol "Hapus" pada gambar produk memanggil `tandaiHapusGambar` — ia menandai di
     *    formulir, berkasnya baru benar-benar dibuang saat Simpan dan Batal memulihkannya.
     *
     * "Batalkan" (nota pembelian) IKUT DIJAGA sejak pemilik melaporkan cacatnya: tombol
     * "Batalkan nota" di layar Pembelian tidak memunculkan popup apa pun, ia memakai panel dua
     * langkah sebaris — padahal tombol "Hapus foto" di berkas Blade yang SAMA sudah lewat
     * dialog bersama. Pembatalan nota mengeluarkan kembali stok yang sudah masuk dan memulihkan
     * harga beli; kalau itu bukan tindakan yang perlu dikonfirmasi, tidak ada yang perlu.
     * Perluasannya dikerjakan di dua tempat sekaligus, seperti dicatat di sini sebelumnya:
     * daftar label DAN daftar nama metode (memanggilPenghapusServer).
     *
     * "Buang N baris" (pindah cabang di lembar hitung stok) tetap TIDAK dijaga: yang dibuang
     * adalah isian yang belum tersimpan, bukan data.
     */
    public function test_setiap_pemicu_hapus_lewat_konfirmasi_nampan(): void
    {
        $pelanggar = [];

        foreach ($this->halamanBlade() as $jalur => $isi) {
            foreach ($this->elemen($isi) as [$atribut, $label]) {
                if (preg_match('/\b(hapus|void|batalkan)\b/i', $label) !== 1) {
                    continue;
                }

                // Tombol yang MEMBATALKAN pertanyaannya bukan pemicu.
                if (preg_match('/\b(tidak jadi|batal saja|belum)\b/i', $label) === 1) {
                    continue;
                }

                if (! $this->memanggilPenghapusServer($atribut)) {
                    continue;
                }

                if (str_contains($atribut, 'konfirmasiNampan')) {
                    continue;
                }

                $pelanggar[] = $jalur.' — "'.Str::limit($label, 40).'"';
            }
        }

        $this->assertSame([], $pelanggar,
            'pemicu hapus wajib bertanya lewat window.konfirmasiNampan lebih dulu: '
            .implode(', ', $pelanggar));
    }

    /**
     * Pembandingnya: penjaga di atas benar-benar MELIHAT tombol hapus.
     *
     * Tanpa uji ini, regex yang tidak cocok dengan apa pun akan lulus ketiga uji di atas
     * sebagai "tidak ada pelanggar" — penjaga yang tidak menjaga apa pun, dan itu lebih buruk
     * daripada tidak ada penjaga karena laporannya berbunyi hijau.
     */
    public function test_penjaganya_memang_menemukan_pemicu_hapus_yang_sudah_benar(): void
    {
        $terlihat = [];

        foreach ($this->halamanBlade() as $jalur => $isi) {
            foreach ($this->elemen($isi) as [$atribut, $label]) {
                if (preg_match('/\bhapus\b/i', $label) === 1 && $this->memanggilPenghapusServer($atribut)) {
                    $terlihat[$jalur] = ($terlihat[$jalur] ?? 0) + 1;
                }
            }
        }

        // Layar produk (dua bentuk baris: kartu ponsel + tabel) dan tombol hapus foto di
        // layar pembelian. Kalau salah satu hilang dari daftar ini, penjaganya buta — bukan
        // layarnya yang bersih.
        $this->assertSame(2, $terlihat['resources/views/livewire/pages/owner/produk.blade.php'] ?? 0,
            'penjaga harus melihat kedua tombol hapus produk (kartu ponsel + tabel)');
        $this->assertGreaterThanOrEqual(1, $terlihat['resources/views/livewire/pages/owner/pembelian.blade.php'] ?? 0,
            'penjaga harus melihat tombol "Hapus foto" di layar pembelian');

        /*
         * Dan perluasan ke PEMBATALAN benar-benar terlihat juga.
         *
         * Tanpa pembanding ini, menambahkan kata "batalkan" ke kedua regex di atas bisa saja
         * tidak cocok dengan apa pun — dan uji pelanggarnya tetap hijau sebagai "tidak ada
         * pelanggar". Itu bentuk kebutaan yang sama yang sudah tiga kali terjadi di repo ini,
         * hanya dengan kata kunci yang lebih baru.
         */
        $pemicuBatal = 0;

        foreach ($this->halamanBlade() as $isi) {
            foreach ($this->elemen($isi) as [$atribut, $label]) {
                if (preg_match('/\bbatalkan\b/i', $label) === 1 && $this->memanggilPenghapusServer($atribut)) {
                    $pemicuBatal++;
                }
            }
        }

        $this->assertGreaterThanOrEqual(1, $pemicuBatal,
            'penjaga harus melihat pemicu "Batalkan nota" di layar pembelian; kalau tidak, kata '
            .'"batalkan" di regexnya tidak menjaga apa pun');
    }

    /* ── Bantuan ─────────────────────────────────────────────────────────── */

    /**
     * Semua Blade halaman, isinya sudah tanpa komentar, berkas yang dikecualikan dibuang.
     *
     * @return array<string, string> jalur relatif => isi
     */
    private function halamanBlade(): array
    {
        $hasil = [];

        $dir = resource_path('views/livewire/pages');

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir)) as $berkas) {
            if (! $berkas->isFile() || ! str_ends_with($berkas->getFilename(), '.blade.php')) {
                continue;
            }

            $jalur = str_replace(base_path().'/', '', $berkas->getPathname());

            if (in_array($jalur, self::DIKERJAKAN_GELOMBANG_INI, true)) {
                continue;
            }

            $hasil[$jalur] = $this->tanpaKomentar((string) file_get_contents($berkas->getPathname()));
        }

        ksort($hasil);

        return $hasil;
    }

    /**
     * Isi Blade tanpa komentar, supaya penjaga membaca KODE dan bukan prosa.
     *
     * Dua teknik, dan keduanya perlu:
     *  - komentar Blade `{{-- … --}}` dibuang dengan regex; berkas Blade bukan PHP yang sah,
     *    jadi token_get_all tidak bisa dipakai atas seluruh berkasnya;
     *  - komentar di dalam blok `@php … @endphp` dibuang lewat token_get_all, BUKAN regex.
     *    Regex `//.*` memotong separuh alamat `https://…` di dalam string dan bisa membuang
     *    kode yang sebenarnya ada.
     */
    private function tanpaKomentar(string $isi): string
    {
        $isi = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $isi);

        return (string) preg_replace_callback(
            '/@php\b(.*?)@endphp/s',
            fn (array $blok): string => '@php'.$this->phpTanpaKomentar($blok[1]).'@endphp',
            $isi,
        );
    }

    /** Potongan PHP tanpa komentar, lewat tokenizer — bukan regex. */
    private function phpTanpaKomentar(string $potongan): string
    {
        $bersih = '';

        foreach (token_get_all('<?php '.$potongan) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG], true)) {
                continue;
            }

            $bersih .= is_array($token) ? $token[1] : $token;
        }

        return $bersih;
    }

    /**
     * Tombol/tautan/komponen aksi beserta labelnya.
     *
     * Nilai atribut ber-kutip dilewati UTUH lebih dulu: `wire:click="hapus('{{ $p->id }}')"`
     * mengandung `>` dari panah objek Blade, jadi `[^>]*` memotong tagnya di tengah dan
     * potongan sisanya terbaca sebagai label. Cacat itu sudah pernah terjadi di
     * TombolBahayaTest dan menghasilkan empat tuduhan palsu.
     *
     * Label dikumpulkan dari tiga tempat, karena tombol di repo ini memakai ketiganya: isi
     * elemen, atribut `label`/`aria-label` (tombol ikon `x-aksi` tidak punya teks), dan
     * literal di dalam `x-text` (teks yang disusun Alpine).
     *
     * @return array<int, array{0: string, 1: string}> [atribut, label]
     */
    private function elemen(string $isi): array
    {
        $hasil = [];

        preg_match_all(
            '/<(button|a|x-aksi)\b((?:"[^"]*"|\'[^\']*\'|[^>"\'])*)>(.*?)<\/\1>/s',
            $isi, $berpasangan, PREG_SET_ORDER
        );

        foreach ($berpasangan as $satu) {
            $hasil[] = [$satu[2], $this->label($satu[2], $satu[3])];
        }

        // Bentuk menutup-sendiri: `<x-aksi … />` tidak punya isi, labelnya di atribut.
        preg_match_all(
            '/<(?:x-aksi)\b((?:"[^"]*"|\'[^\']*\'|[^>"\'])*)\/>/s',
            $isi, $sendiri, PREG_SET_ORDER
        );

        foreach ($sendiri as $satu) {
            $hasil[] = [$satu[1], $this->label($satu[1], '')];
        }

        return $hasil;
    }

    private function label(string $atribut, string $dalam): string
    {
        $teks = strip_tags($dalam);

        foreach (['label', 'aria-label'] as $nama) {
            if (preg_match('/(?<![\w-])'.$nama.'="([^"]*)"/', $atribut, $cocok) === 1) {
                $teks .= ' '.$cocok[1];
            }
        }

        // Teks yang disusun Alpine: ambil literalnya. "Ya, buang N pesanan" tinggal di sini.
        if (preg_match('/(?::|x-)text="([^"]*)"/', $atribut, $cocok) === 1) {
            preg_match_all("/'([^']*)'/", $cocok[1], $literal);
            $teks .= ' '.implode(' ', $literal[1] ?? []);
        }

        return trim((string) preg_replace('/\s+/', ' ', $teks));
    }

    /**
     * Apakah klik elemen ini benar-benar memanggil penghapus di SERVER.
     *
     * Yang dicari nama metode Livewire yang diawali hapus/buang/void/batalkan — lewat
     * `wire:click` maupun `$wire.` di dalam Alpine. Fungsi Alpine lokal dengan nama serupa (mis.
     * `hapusPembayaran(i)` yang membuang baris yang baru diketik) SENGAJA tidak dihitung:
     * yang belum tersimpan bukan data yang hilang.
     *
     * `[^"]*` pada nilai atributnya sengaja dipertahankan walaupun penangan Alpine memuat `>`
     * di dalam `=>`: yang dibatasi di sini adalah tanda petik, bukan tanda lebih-besar, jadi
     * panah fungsi lewat utuh. Yang memutus tag adalah pola `[^>]*` di elemen(), dan di situ
     * sudah ditangani dengan melewati nilai ber-petik lebih dulu.
     */
    private function memanggilPenghapusServer(string $atribut): bool
    {
        preg_match_all('/(?:wire:click|x-on:click|@click)[.\w]*="([^"]*)"/s', $atribut, $cocok);

        foreach ($cocok[1] ?? [] as $penangan) {
            // `$wire.hapusBukti()` / `$wire.hapus('…')` / `$wire.batalkan('…')`
            if (preg_match('/\$wire\.(hapus|buang|void|batalkan)[A-Za-z]*\s*\(/', $penangan) === 1) {
                return true;
            }

            // `wire:click="hapus('…')"` atau `wire:click="hapusBukti"` (tanpa tanda kurung).
            if (preg_match('/^\s*(hapus|buang|void|batalkan)[A-Za-z]*\s*(\(|$)/', $penangan) === 1) {
                return true;
            }
        }

        return false;
    }
}

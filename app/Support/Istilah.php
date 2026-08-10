<?php

namespace App\Support;

/**
 * Arti istilah aplikasi, dalam bahasa yang dipakai orang di warung.
 *
 * KENAPA SATU SUMBER, dan kenapa bukan sekadar teks di Blade masing-masing. Arti yang sama
 * ditulis di dua tempat — sekali di gelembung penjelasan yang muncul di layar, sekali di
 * halaman Istilah — akan bercabang pada perbaikan pertama. Dan yang bercabang di sini adalah
 * penjelasan tentang uang: pemilik yang membaca dua kalimat berbeda untuk kata yang sama
 * akan menyimpulkan aplikasinya sendiri tidak yakin.
 *
 * ATURAN MENULISNYA, dan ini yang membuat berkas ini berguna atau tidak:
 *
 *  1. JANGAN memakai kata yang sedang dijelaskan di dalam penjelasannya. "Margin adalah
 *     persentase margin keuntungan" tidak menolong siapa pun.
 *  2. JANGAN memakai istilah lain yang juga perlu dijelaskan. Kalau terpaksa, tautkan lewat
 *     `lihatJuga` supaya orangnya bisa meloncat, bukan menebak.
 *  3. SELALU ada contoh berangka rupiah. Orang yang tidak paham "30%" langsung paham "modal
 *     Rp 10.000, dijual Rp 14.500, untung Rp 4.500". Contoh adalah penjelasan yang sebenarnya;
 *     kalimat di atasnya cuma pengantar.
 *  4. Kalimatnya pendek. Penjelasan yang butuh dibaca dua kali sama saja dengan tidak ada.
 */
class Istilah
{
    /**
     * @return array<string, array{
     *     istilah: string,
     *     arti: string,
     *     contoh: ?string,
     *     lihatJuga: array<int, string>,
     *     kelompok: string,
     * }>
     */
    public static function semua(): array
    {
        return [
            /* ── Uang & untung ───────────────────────────────────────────── */

            'modal' => [
                'istilah' => 'Modal',
                'arti' => 'Uang yang keluar untuk mengadakan satu barang, sebelum dijual. '
                    .'Untuk barang jadi, ini harga belinya dari grosir. Untuk masakan, ini '
                    .'jumlah harga semua bahan yang terpakai untuk satu porsi.',
                'contoh' => 'Satu porsi lele goreng: lele 1/4 kg Rp 8.000 + minyak Rp 1.000 '
                    .'+ bumbu Rp 500. Modalnya Rp 9.500.',
                'lihatJuga' => ['untung', 'resep'],
                'kelompok' => 'Uang & untung',
            ],

            'untung' => [
                'istilah' => 'Untung (margin)',
                'arti' => 'Sisa uang setelah harga jual dikurangi modal. Persennya dihitung '
                    .'dari harga jual — jadi "untung 30%" berarti dari tiap Rp 1.000 yang '
                    .'dibayar pembeli, Rp 300 jadi untung.',
                'contoh' => 'Modal Rp 10.000, dijual Rp 14.500. Untungnya Rp 4.500, atau 31% '
                    .'dari harga jual.',
                'lihatJuga' => ['modal', 'untung-bersih'],
                'kelompok' => 'Uang & untung',
            ],

            'untung-bersih' => [
                'istilah' => 'Untung bersih',
                'arti' => 'Untung yang sudah dipotong biaya warung sehari-hari: sewa, listrik, '
                    .'gas, gaji. Angka untung biasa belum memotong itu semua, jadi ia selalu '
                    .'terlihat lebih besar daripada yang benar-benar masuk kantong.',
                'contoh' => 'Sehari jual 100 porsi, untung kotornya Rp 450.000. Sewa dan '
                    .'listrik Rp 93.000 sehari. Yang benar-benar tersisa Rp 357.000.',
                'lihatJuga' => ['untung', 'biaya-operasional'],
                'kelompok' => 'Uang & untung',
            ],

            'biaya-operasional' => [
                'istilah' => 'Biaya operasional',
                'arti' => 'Pengeluaran warung yang datang terus-menerus, bukan untuk membeli '
                    .'barang dagangan: sewa tempat, listrik, air, gas, gaji, langganan '
                    .'internet. Dicatat sekali, lalu dihitung otomatis jatuhnya berapa per hari.',
                'contoh' => 'Sewa Rp 1.500.000 sebulan sama dengan Rp 50.000 sehari.',
                'lihatJuga' => ['untung-bersih', 'titik-impas'],
                'kelompok' => 'Uang & untung',
            ],

            'omzet' => [
                'istilah' => 'Omzet',
                'arti' => 'Seluruh uang yang masuk dari penjualan, sebelum dikurangi apa pun. '
                    .'Omzet besar belum tentu untung besar — modalnya belum dipotong.',
                'contoh' => 'Sehari terjual Rp 2.000.000. Itu omzetnya. Kalau modal barangnya '
                    .'Rp 1.400.000, yang jadi untung baru Rp 600.000.',
                'lihatJuga' => ['untung'],
                'kelompok' => 'Uang & untung',
            ],

            'titik-impas' => [
                'istilah' => 'Titik impas (balik modal)',
                'arti' => 'Berapa banyak yang harus terjual dalam sehari supaya warung tidak '
                    .'rugi. Di bawah angka itu, warung nombok walaupun tetap ada penjualan.',
                'contoh' => 'Biaya sehari Rp 93.000, untung rata-rata Rp 4.500 per porsi. '
                    .'Harus terjual sekitar 21 porsi dulu sebelum mulai untung.',
                'lihatJuga' => ['biaya-operasional', 'untung-bersih'],
                'kelompok' => 'Uang & untung',
            ],

            /* ── Barang & stok ───────────────────────────────────────────── */

            'stok' => [
                'istilah' => 'Stok',
                'arti' => 'Sisa barang yang masih ada sekarang menurut catatan aplikasi.',
                'contoh' => 'Beras masuk 20 karung, terjual 12. Stoknya 8 karung.',
                'lihatJuga' => ['hitung-stok', 'batas-menipis'],
                'kelompok' => 'Barang & stok',
            ],

            'hitung-stok' => [
                'istilah' => 'Hitung stok (opname)',
                'arti' => 'Menghitung barang yang benar-benar ada di rak, lalu mencocokkannya '
                    .'dengan catatan aplikasi. Kalau beda, selisihnya tercatat berikut '
                    .'alasannya — jadi ketahuan barang hilang, rusak, atau salah catat.',
                'contoh' => 'Catatan bilang 8 karung, di gudang cuma ada 7. Selisih 1 karung '
                    .'dicatat sebagai rusak.',
                'lihatJuga' => ['stok', 'kartu-stok'],
                'kelompok' => 'Barang & stok',
            ],

            'kartu-stok' => [
                'istilah' => 'Kartu stok',
                'arti' => 'Daftar semua yang pernah menambah dan mengurangi stok satu barang, '
                    .'berikut tanggal dan siapa yang melakukannya. Inilah buktinya kalau ada '
                    .'yang bertanya "kok bisa tinggal segini?".',
                'contoh' => '10 Agt masuk 20 dari nota belanja · 11 Agt keluar 3 terjual · '
                    .'12 Agt kurang 1 karena rusak.',
                'lihatJuga' => ['stok', 'hitung-stok'],
                'kelompok' => 'Barang & stok',
            ],

            'batas-menipis' => [
                'istilah' => 'Batas menipis (ambang minimum)',
                'arti' => 'Jumlah sisa barang yang bikin aplikasi mulai mengingatkan supaya '
                    .'segera kulakan. Diisi sesuai kebiasaan warung, bukan angka baku.',
                'contoh' => 'Batas menipis beras diisi 5 karung. Begitu sisa 5 atau kurang, '
                    .'beras muncul di daftar yang perlu dibeli.',
                'lihatJuga' => ['stok'],
                'kelompok' => 'Barang & stok',
            ],

            'kode-barang' => [
                'istilah' => 'Kode barang (SKU)',
                'arti' => 'Kode pendek buatan warung sendiri untuk satu barang, supaya gampang '
                    .'dicari dan ditulis. Berbeda dari barcode pabrik. Kalau dikosongkan, '
                    .'aplikasi membuatkannya sendiri.',
                'contoh' => 'Beras Premium 5kg diberi kode BRS-5.',
                'lihatJuga' => ['barcode'],
                'kelompok' => 'Barang & stok',
            ],

            'barcode' => [
                'istilah' => 'Barcode',
                'arti' => 'Garis-garis hitam yang sudah dicetak pabrik di bungkus barang. '
                    .'Dipindai kasir supaya barangnya langsung ketemu tanpa mengetik nama.',
                'contoh' => 'Indomie Goreng barcodenya 8992388101010. Satu barcode hanya boleh '
                    .'dipakai satu barang — kalau dipakai dua, kasir memindai dan yang muncul '
                    .'barang yang salah.',
                'lihatJuga' => ['kode-barang'],
                'kelompok' => 'Barang & stok',
            ],

            'resep' => [
                'istilah' => 'Resep',
                'arti' => 'Daftar bahan dan takarannya untuk satu porsi menu. Dipakai aplikasi '
                    .'untuk dua hal: menghitung modal satu porsi, dan mengurangi bahan baku '
                    .'setiap kali menu itu terjual.',
                'contoh' => 'Satu porsi lele goreng: lele 0,25 kg, minyak 0,05 liter, '
                    .'bumbu 20 gram.',
                'lihatJuga' => ['modal', 'bahan-baku'],
                'kelompok' => 'Barang & stok',
            ],

            'bahan-baku' => [
                'istilah' => 'Bahan baku',
                'arti' => 'Barang mentah yang dibeli untuk dimasak, bukan untuk dijual apa '
                    .'adanya. Yang dihitung stoknya bahan bakunya, bukan menunya.',
                'contoh' => 'Lele mentah dibeli 5 kg seharga Rp 160.000. Yang dijual lele '
                    .'goreng per porsi, dan 1 porsi memakai 0,25 kg dari lele itu.',
                'lihatJuga' => ['resep', 'stok'],
                'kelompok' => 'Barang & stok',
            ],

            /* ── Jualan & kasir ──────────────────────────────────────────── */

            'sesi-kas' => [
                'istilah' => 'Sesi kas (buka–tutup kasir)',
                'arti' => 'Satu putaran jualan dari kasir membuka laci sampai menutupnya. '
                    .'Uang di awal dicatat, uang di akhir dihitung, selisihnya ketahuan.',
                'contoh' => 'Buka pukul 07.00 dengan uang kembalian Rp 200.000, tutup pukul '
                    .'15.00. Aplikasi memberi tahu seharusnya ada berapa di laci.',
                'lihatJuga' => ['modal-awal', 'selisih-kas'],
                'kelompok' => 'Jualan & kasir',
            ],

            'modal-awal' => [
                'istilah' => 'Modal awal (uang kembalian)',
                'arti' => 'Uang receh yang sudah ada di laci sebelum jualan dimulai, untuk '
                    .'kembalian. Bukan hasil jualan, jadi tidak dihitung sebagai pemasukan.',
                'contoh' => 'Pagi hari laci diisi Rp 200.000 uang kecil.',
                'lihatJuga' => ['sesi-kas', 'selisih-kas'],
                'kelompok' => 'Jualan & kasir',
            ],

            'selisih-kas' => [
                'istilah' => 'Selisih kas',
                'arti' => 'Beda antara uang yang seharusnya ada di laci menurut catatan dengan '
                    .'uang yang benar-benar dihitung waktu tutup. Selisih kecil biasa; selisih '
                    .'yang berulang tiap hari perlu ditanyakan.',
                'contoh' => 'Seharusnya Rp 1.250.000, dihitung Rp 1.235.000. Kurang Rp 15.000.',
                'lihatJuga' => ['sesi-kas', 'modal-awal'],
                'kelompok' => 'Jualan & kasir',
            ],

            'kasbon' => [
                'istilah' => 'Kasbon (utang pelanggan)',
                'arti' => 'Belanja yang dibawa dulu, dibayar belakangan. Tercatat atas nama '
                    .'orangnya, berikut tiap kali ia menyetor.',
                'contoh' => 'Pak Budi belanja Rp 500.000, bayar Rp 150.000. Sisa utangnya '
                    .'Rp 350.000.',
                'lihatJuga' => ['jatuh-tempo'],
                'kelompok' => 'Jualan & kasir',
            ],

            'jatuh-tempo' => [
                'istilah' => 'Jatuh tempo',
                'arti' => 'Tanggal utang dijanjikan lunas. Lewat tanggal itu, kasbonnya '
                    .'bertanda merah supaya tidak terlupakan.',
                'contoh' => 'Kasbon 1 Agustus, jatuh tempo 31 Agustus.',
                'lihatJuga' => ['kasbon'],
                'kelompok' => 'Jualan & kasir',
            ],

            /* ── Warung & orang ──────────────────────────────────────────── */

            'cabang' => [
                'istilah' => 'Cabang (outlet)',
                'arti' => 'Satu tempat jualan. Warung yang cuma punya satu tempat tetap punya '
                    .'satu cabang — stok dan uangnya dihitung per cabang, jadi tidak tercampur '
                    .'kalau suatu hari buka yang kedua.',
                'contoh' => 'Warung Pusat punya 8 karung beras, Cabang Seturan punya 3 karung. '
                    .'Dua angka yang berbeda, tidak dijumlah jadi satu.',
                'lihatJuga' => [],
                'kelompok' => 'Warung & orang',
            ],

            'peran' => [
                'istilah' => 'Peran',
                'arti' => 'Batas sampai mana seseorang boleh melihat dan mengubah. Kasir hanya '
                    .'layar jualan; pemilik semuanya termasuk untung rugi.',
                'contoh' => 'Dari 5 orang di warung, hanya 1 yang berperan pemilik. Kasir tidak '
                    .'bisa membuka daftar biaya dan tidak bisa mengubah harga.',
                'lihatJuga' => ['pin'],
                'kelompok' => 'Warung & orang',
            ],

            'pin' => [
                'istilah' => 'PIN',
                'arti' => 'Enam angka rahasia untuk kasir masuk ke layar jualan. Lebih cepat '
                    .'diketik di layar sentuh daripada kata sandi, dan tidak bisa dilihat lagi '
                    .'setelah disimpan — yang lupa diberi PIN baru.',
                'contoh' => 'Kasir Andi masuk dengan username andi-utama dan PIN 6 angka.',
                'lihatJuga' => ['peran'],
                'kelompok' => 'Warung & orang',
            ],

            'shift' => [
                'istilah' => 'Shift',
                'arti' => 'Giliran jaga satu orang dalam sehari. Dipakai memisahkan penjualan '
                    .'pagi dan sore supaya jelas hasil siapa.',
                'contoh' => 'Shift pagi 07.00–15.00, shift sore 15.00–22.00.',
                'lihatJuga' => ['sesi-kas'],
                'kelompok' => 'Warung & orang',
            ],
        ];
    }

    /** Satu istilah, atau null kalau kuncinya tidak dikenal. */
    public static function ambil(string $kunci): ?array
    {
        return static::semua()[$kunci] ?? null;
    }

    /**
     * Dikelompokkan untuk halaman Istilah.
     *
     * Urutan kelompoknya mengikuti urutan kemunculan pertama di semua(), bukan diurutkan
     * abjad: yang paling sering ditanyakan (uang dan untung) harus di atas, dan abjad akan
     * menaruhnya di tengah.
     *
     * @return array<string, array<string, array<string, mixed>>>
     */
    public static function perKelompok(): array
    {
        $hasil = [];

        foreach (static::semua() as $kunci => $isi) {
            $hasil[$isi['kelompok']][$kunci] = $isi;
        }

        return $hasil;
    }
}

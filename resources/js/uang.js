/**
 * Kotak nominal rupiah di layar owner — memformat SAAT DIKETIK.
 *
 * KENAPA BERKAS INI ADA (keluhan pemilik: "format belum rupiah")
 *
 * Kolom harga/potongan/ongkir dulu kotak teks telanjang: mengetik 58000 tampil "58000",
 * tanpa Rp dan tanpa titik ribuan. Akibatnya bukan cuma kurang cantik — pemilik warung
 * menulis titik ribuannya SENDIRI ("58.000"), dan titik itulah yang pernah membuat nota
 * Rp 116.000 tersimpan Rp 116 (lihat App\Support\Uang). Sesudah sisi server diperketat,
 * cacatnya berubah bentuk: yang mengetik seperti kebiasaannya ditolak dengan "harus berupa
 * angka". Kotak yang memformat sendiri menghapus kedua-duanya: titiknya digambar aplikasi,
 * dan yang dikirim ke server selalu digit kanonik.
 *
 * TIGA KEPUTUSAN YANG MENENTUKAN, dan masing-masing sudah ditolak alternatifnya:
 *
 * 1. **Memformat saat mengetik, BUKAN saat blur.** Kotak yang baru berformat setelah
 *    ditinggalkan berbohong soal formatnya selama orang masih mengetik di dalamnya — dan
 *    tombol "Selesai" papan ketik ponsel tidak selalu memicu blur, jadi angka terakhir bisa
 *    tidak pernah berformat sama sekali.
 *
 * 2. **Kursor dipertahankan di posisi DIGIT yang sama, bukan dilempar ke ujung kanan.**
 *    Menyisipkan satu angka di tengah "1.500.000" lalu menemukan kursor di ujung membuat
 *    orang mengetik ulang seluruh nominal. Hitungannya di posisiKursor() — fungsi MURNI,
 *    diuji di tests/js/uang.test.mjs, karena inilah bagian yang paling mudah salah dan
 *    paling tidak terlihat salahnya di tangkapan layar.
 *
 * 3. **Rupiah BULAT.** Kemampuan yang sengaja dihapus: dulu `harga = '1500.5'` lolos
 *    (`numeric`), sekarang ditolak. Rupiah yang diketik orang tidak punya sen. Harga
 *    pecahan tetap hidup di tempat yang memang butuh — harga per satuan dasar
 *    (10.000 / 12 = 833,33) DIHITUNG TerimaPembelianAction, bukan diketik.
 *
 * Tanpa CDN (aturan keras nomor 3): pengelompokan ribuan dari `Intl.NumberFormat('id-ID')`
 * bawaan peramban, di modul yang dibundel Vite dan didaftarkan lewat `alpine:init` — pola
 * yang sama dengan pasangBukti()/pasangOpname().
 *
 * Layar kasir TIDAK memakai berkas ini: ia punya salinannya sendiri di kasir.js yang sudah
 * offline-aman, dan menyatukan keduanya berarti satu perubahan di layar owner bisa
 * mematikan layar transaksi.
 */

/** Pengelompok ribuan gaya Indonesia: 58000 → "58.000". */
const PENGELOMPOK = new Intl.NumberFormat('id-ID');

/** Awalannya ikut di dalam kotak, bukan sebagai hiasan di sebelahnya — lihat catatan di Blade. */
export const AWALAN = 'Rp ';

/**
 * Batas digit, sama dengan App\Support\Uang::MAKS_DIGIT.
 *
 * Bukan aturan bisnis: di atas 15 digit `Number` kehilangan ketelitian, jadi kotaknya akan
 * MENAMPILKAN angka yang berbeda dari yang dikirim. Nilai yang dikarang mesin lebih buruk
 * daripada ketikan yang berhenti diterima dan bisa dilihat orangnya.
 */
export const MAKS_DIGIT = 15;

/** Jeda penyegaran bar ringkasan; sama dengan debounce ikatan `jumlah.*` di Blade. */
export const JEDA_SEGAR = 600;

const angka = (huruf) => huruf >= '0' && huruf <= '9';

/** Semua yang bukan digit dibuang — termasuk "Rp", titik, koma, dan spasi tempelan. */
export function digitSaja(teks) {
    return String(teks ?? '').replace(/[^0-9]/g, '');
}

/**
 * Bentuk kanonik yang dikirim ke server: digit saja, tanpa nol di depan.
 *
 * "0" TETAP "0" dan itu bukan kelalaian: nol adalah pernyataan "bonus grosir", sedangkan
 * kosong berarti belum diisi. Keduanya harus tetap bisa dibedakan sampai ke validator
 * (harga kosong = wajib diisi, harga nol = sah).
 */
export function kanonik(teks) {
    const digit = digitSaja(teks).slice(0, MAKS_DIGIT);

    return digit === '' ? '' : digit.replace(/^0+(?=\d)/, '');
}

/** Yang dilihat mata: "58000" → "Rp 58.000". Kosong tetap kosong supaya placeholder muncul. */
export function formatUang(teks) {
    const bersih = kanonik(teks);

    return bersih === '' ? '' : AWALAN + PENGELOMPOK.format(Number(bersih));
}

/**
 * Indeks kursor sesudah digit ke-`jumlahDigit` pada teks berformat. FUNGSI MURNI.
 *
 * Yang dihitung digit, bukan karakter: titik ribuan lahir dan mati sendiri saat nominalnya
 * melewati kelipatan seribu, jadi menyimpan "posisi karakter" akan menggeser kursor satu
 * langkah setiap kali sebuah titik muncul.
 *
 * jumlahDigit = 0 berarti kursor berada SEBELUM digit pertama, yaitu sesudah awalan "Rp " —
 * bukan di indeks 0. Kursor di dalam kata "Rp" membuat ketikan berikutnya masuk ke tempat
 * yang bukan angka.
 */
export function posisiKursor(tampil, jumlahDigit) {
    const teks = String(tampil ?? '');

    if (jumlahDigit <= 0) {
        for (let i = 0; i < teks.length; i++) {
            if (angka(teks[i])) {
                return i;
            }
        }

        return teks.length;
    }

    let dihitung = 0;

    for (let i = 0; i < teks.length; i++) {
        if (angka(teks[i])) {
            dihitung++;

            if (dihitung === jumlahDigit) {
                return i + 1;
            }
        }
    }

    return teks.length;
}

/**
 * Satu ketikan: dari (nilai mentah kotak, posisi kursor) ke (teks tampil, digit kanonik,
 * posisi kursor baru). FUNGSI MURNI — tidak menyentuh DOM sama sekali.
 *
 * Nol di depan yang dibuang ("058" → "58") ikut menggeser hitungan digit sebelum kursor;
 * tanpa koreksi itu, mengetik di depan angka membuat kursor melompat satu digit ke kanan.
 */
export function sunting(nilai, kursor) {
    const mentah = String(nilai ?? '');
    const batas = Math.max(0, Math.min(mentah.length, kursor ?? mentah.length));

    const semua = digitSaja(mentah).slice(0, MAKS_DIGIT);
    const bersih = kanonik(mentah);
    const dibuang = semua.length - bersih.length;

    const sebelum = digitSaja(mentah.slice(0, batas)).length;
    const tersisa = Math.max(0, Math.min(bersih.length, sebelum - dibuang));
    const tampil = formatUang(bersih);

    return { tampil, digit: bersih, kursor: posisiKursor(tampil, tersisa) };
}

/**
 * Backspace yang berdiri tepat di belakang titik ribuan: kursornya dipindahkan dulu supaya
 * yang terhapus DIGITNYA. FUNGSI MURNI; null berarti tidak ada yang perlu dicampuri.
 *
 * Tanpa ini, backspace di "Rp 1.|500" menghapus titik yang langsung digambar ulang — dari
 * luar terlihat sebagai tombol hapus yang macet, dan orang akan menekannya berkali-kali
 * lalu menghapus seluruh isian dengan blok pilih.
 */
export function kursorMundur(nilai, kursor) {
    const teks = String(nilai ?? '');
    const titik = Math.max(0, Math.min(teks.length, kursor ?? 0));

    if (titik <= 0 || angka(teks[titik - 1])) {
        // Sudah di depan digit (atau di ujung kiri): peramban sendiri sudah benar.
        return null;
    }

    let i = titik - 1;

    while (i > 0 && ! angka(teks[i - 1])) {
        i--;
    }

    // i === 0 berarti yang ada di kiri cuma awalan "Rp " — tidak ada digit untuk dihapus.
    return i > 0 ? i : null;
}

export function pasangUang() {
    document.addEventListener('alpine:init', () => {
        /**
         * @param awal  nilai dari server saat kotaknya lahir (digit kanonik atau kosong)
         * @param nama  properti Livewire tujuannya, mis. "harga.<id barang>"
         */
        window.Alpine.data('kotakUang', (awal = '', nama = '') => ({
            nama,

            /** Penunda penyegaran bar ringkasan; dibatalkan saat komponennya dibuang. */
            tunda: null,

            init() {
                /*
                 * Nilainya ditulis DI SINI, bukan lewat atribut value= di Blade.
                 *
                 * Atribut value= akan ditimpa morph Livewire di tengah orang mengetik — dan
                 * yang ditulisnya angka MENTAH ("58000") di atas teks berformat, jadi kotaknya
                 * berkedip berganti bentuk. Karena kotak ini juga tidak punya wire:model,
                 * morph tidak punya alasan menyentuh nilainya sama sekali.
                 */
                this.$el.value = formatUang(awal);
            },

            /**
             * Tiap ketikan: yang dilihat mata diformat, yang dikirim ke server digit kanonik.
             *
             * Argumen ketiga `false` pada $wire.set() — TANPA permintaan jaringan. Nilainya
             * sudah ada di keadaan klien saat itu juga, jadi tombol Simpan yang ditekan
             * setengah detik kemudian tetap mengirim angka yang terakhir terlihat. Kalau
             * di-`true`, tiap huruf menjadi satu perjalanan ke server; di warung bersinyal
             * seadanya itu berarti kotak yang macet tiap ketikan.
             */
            ketik() {
                const el = this.$el;
                const { tampil, digit, kursor } = sunting(el.value, el.selectionStart);

                el.value = tampil;
                el.setSelectionRange?.(kursor, kursor);

                this.$wire.set(this.nama, digit, false);
                this.segarkanRingkasan();
            },

            mundur(peristiwa) {
                const el = this.$el;

                // Blok yang sedang dipilih dihapus apa adanya — jangan dicampuri.
                if (el.selectionStart !== el.selectionEnd) {
                    return;
                }

                const pindah = kursorMundur(el.value, el.selectionStart);

                if (pindah !== null) {
                    // Sesudah preventDefault peramban tidak menghapus apa pun lagi, jadi
                    // digitnya (satu langkah di kiri pemisah) dibuang di sini.
                    peristiwa.preventDefault();
                    el.value = el.value.slice(0, pindah - 1) + el.value.slice(pindah);
                    el.setSelectionRange?.(pindah - 1, pindah - 1);
                    this.ketik();
                }
            },

            /**
             * Bar ringkasan (subtotal/potongan/ongkir/total) dihitung SERVER, jadi ia hanya
             * bergerak kalau ada permintaan. Satu $refresh yang ditunda 600 ms — angka yang
             * sama dengan debounce ikatan `jumlah.*` — supaya barnya tetap hidup tanpa satu
             * perjalanan per huruf.
             */
            segarkanRingkasan() {
                // window.setTimeout, bukan setTimeout telanjang: bentuk yang sama dipakai
                // kasir.js, dan yang membuatnya perlu adalah ujinya — memalsukan timer global
                // di node ikut mematikan penjadwal uji itu sendiri.
                window.clearTimeout(this.tunda);
                this.tunda = window.setTimeout(() => this.$wire.$refresh(), JEDA_SEGAR);
            },

            destroy() {
                // Penyegaran yang menyala sesudah komponennya dibuang (pindah halaman daftar,
                // ganti outlet) memanggil $wire yang sudah tidak ada.
                window.clearTimeout(this.tunda);
            },
        }));
    });
}

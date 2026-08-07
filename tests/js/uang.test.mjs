/**
 * Pengujian kotak nominal rupiah (resources/js/uang.js).
 *
 * Kenapa berkas ini ada, dan kenapa isinya bukan uji "kosmetik": yang dilihat pemilik di
 * kolom harga digambar di KLIEN, sedangkan yang tersimpan adalah digit yang dikirim modul
 * ini. Begitu keduanya bercabang — kotak berbunyi "Rp 58.000" tapi yang terkirim "5.800" —
 * tidak ada satu pun uji PHP yang bisa menangkapnya, dan tidak ada galat di layar.
 *
 * Bagian yang paling mudah salah dan paling tidak terlihat salahnya adalah POSISI KURSOR:
 * titik ribuan lahir & mati sendiri saat nominalnya melewati kelipatan seribu, jadi kursor
 * yang dihitung per karakter bergeser satu langkah tiap kali sebuah titik muncul. Karena
 * itu hitungannya dipisah jadi fungsi murni dan diuji di sini.
 *
 * Dijalankan dengan: npm run uji:js
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import {
    digitSaja,
    formatUang,
    JEDA_SEGAR,
    kanonik,
    kursorMundur,
    MAKS_DIGIT,
    posisiKursor,
    sunting,
} from '../../resources/js/uang.js';

/**
 * Menyalakan modulnya dengan Alpine palsu, lalu mengembalikan pabrik komponennya.
 *
 * Penundanya dipalsukan di `window`, BUKAN di global: memalsukan setTimeout global di node
 * ikut mematikan penjadwal uji itu sendiri, dan yang gagal lalu bukan ujinya melainkan
 * seluruh berkasnya. Modulnya memang memanggil window.setTimeout — bentuk yang sama dengan
 * kasir.js.
 */
async function pabrik(timer) {
    let daftar = null;

    global.window = {
        Alpine: { data: (_nama, factory) => { daftar = factory; } },
        setTimeout: (f, ms) => {
            timer.push({ f, ms });

            return timer.length;
        },
        clearTimeout: (id) => {
            if (id) {
                timer[id - 1] = null;
            }
        },
    };

    global.document = {
        addEventListener: (nama, f) => { if (nama === 'alpine:init') f(); },
    };

    const modul = await import('../../resources/js/uang.js?t=' + Math.random());
    modul.pasangUang();

    assert.ok(daftar, 'komponen kotakUang tidak terdaftar');

    return daftar;
}

/**
 * Satu kotak uang lengkap dengan elemen & $wire palsu.
 *
 * Elemennya menyimpan riwayat setSelectionRange supaya kursor bisa diperiksa, dan $wire
 * mencatat SETIAP panggilan set()/$refresh() beserta urutannya — dua hal yang harus
 * dibedakan: set() wajib segera, $refresh() wajib ditunda.
 */
async function kotak(awal = '', nama = 'harga.abc') {
    const timer = [];
    const komponen = await pabrik(timer);
    const dikirim = [];
    const disegarkan = [];

    const el = {
        value: '',
        selectionStart: 0,
        selectionEnd: 0,
        setSelectionRange(a, b) {
            this.selectionStart = a;
            this.selectionEnd = b;
        },
    };

    const kotakUang = {
        ...komponen(awal, nama),
        $el: el,
        $wire: {
            set: (n, v, hidup) => dikirim.push({ nama: n, nilai: v, hidup }),
            $refresh: () => disegarkan.push(true),
        },
    };

    kotakUang.init();

    /** Menjalankan penunda yang masih hidup — meniru berlalunya 600 ms. */
    const majuWaktu = () => timer.filter(Boolean).forEach((t) => t.f());

    /** Meniru satu ketikan: teks disisipkan di posisi kursor. */
    const ketikkan = (huruf, di = el.value.length) => {
        el.value = el.value.slice(0, di) + huruf + el.value.slice(di);
        el.selectionStart = di + huruf.length;
        el.selectionEnd = el.selectionStart;
        kotakUang.ketik();
    };

    return { kotakUang, el, dikirim, disegarkan, timer, majuWaktu, ketikkan };
}

describe('format rupiah', () => {
    it('titik ribuan digambar aplikasi, bukan diketik orang', () => {
        assert.equal(formatUang('58000'), 'Rp 58.000');
        assert.equal(formatUang('1500000'), 'Rp 1.500.000');
        assert.equal(formatUang('999'), 'Rp 999');
    });

    it('kosong tetap kosong supaya placeholder-nya muncul', () => {
        // "Rp 0" untuk kotak yang belum diisi akan membuat harga yang lupa diisi terbaca
        // sebagai bonus grosir — dan bonus menghapus harga beli barangnya di master.
        assert.equal(formatUang(''), '');
        assert.equal(formatUang(null), '');
        assert.equal(formatUang('Rp '), '');
    });

    it('NOL bukan kosong: "0" tetap terkirim sebagai "0"', () => {
        // Nol adalah pernyataan "bonus"; kosong berarti belum diisi. Keduanya harus tetap
        // bisa dibedakan sampai ke validator (harga kosong = wajib diisi, harga nol = sah).
        assert.equal(formatUang('0'), 'Rp 0');
        assert.equal(kanonik('0'), '0');
        assert.equal(kanonik('000'), '0');
    });

    it('nol di depan dibuang supaya "058" tidak terbaca sebagai angka lain', () => {
        assert.equal(kanonik('058'), '58');
        assert.equal(formatUang('0058000'), 'Rp 58.000');
    });

    it('tempelan dari WhatsApp/spreadsheet dibaca apa adanya digitnya', () => {
        // "Rp 58.000" bernbsp adalah bentuk yang benar-benar keluar dari number_format
        // gaya Indonesia dan dari salin-tempel; menolaknya berarti kotaknya mengosongkan
        // diri tepat setelah orang menempel angka yang benar.
        assert.equal(digitSaja('Rp 58.000'), '58000');
        assert.equal(formatUang('Rp 58.000'), 'Rp 58.000');
        assert.equal(formatUang('58 000'), 'Rp 58.000');
    });

    it('sen dan huruf dibuang — rupiah yang diketik orang bulat', () => {
        // Kemampuan yang SENGAJA dihapus: "1500.5" dulu lolos aturan `numeric` di server.
        // Di sini titiknya hilang saat diketik, jadi orangnya LANGSUNG melihat akibatnya
        // alih-alih menemukan "Rp 1.500" tersimpan sebagai Rp 15005 nanti.
        assert.equal(formatUang('1500.5'), 'Rp 15.005');
        assert.equal(formatUang('58rb'), 'Rp 58');
    });

    it('digit di atas batas dipotong, tidak dibiarkan mengarang angka', () => {
        // Di atas 15 digit Number kehilangan ketelitian, dan kotaknya akan MENAMPILKAN
        // angka yang berbeda dari yang dikirim.
        const panjang = '1'.repeat(MAKS_DIGIT + 4);

        assert.equal(kanonik(panjang).length, MAKS_DIGIT);
        assert.equal(digitSaja(formatUang(panjang)).length, MAKS_DIGIT);
    });
});

describe('posisi kursor', () => {
    it('kursor mengikuti DIGIT, bukan karakter', () => {
        // "Rp 589.000": sesudah digit ke-3 berarti indeks 6, bukan 5 — titiknya ikut
        // terhitung sebagai karakter tapi bukan sebagai digit.
        assert.equal(posisiKursor('Rp 589.000', 3), 6);
        assert.equal(posisiKursor('Rp 58.000', 2), 5);
        // "Rp 1.500.000": digit ke-4 ada di indeks 7, jadi kursornya di 8 — tepat SEBELUM
        // titik kedua, bukan sesudahnya.
        assert.equal(posisiKursor('Rp 1.500.000', 4), 8);
    });

    it('nol digit berarti sesudah awalan "Rp ", bukan indeks 0', () => {
        // Kursor di dalam kata "Rp" membuat ketikan berikutnya masuk ke tempat yang bukan
        // angka, dan hurufnya lalu dibuang tanpa penjelasan.
        assert.equal(posisiKursor('Rp 58.000', 0), 3);
        assert.equal(posisiKursor('', 0), 0);
    });

    it('digit lebih banyak daripada yang ada berhenti di ujung', () => {
        assert.equal(posisiKursor('Rp 58', 9), 5);
    });
});

describe('menyunting di tengah nominal', () => {
    it('menyisipkan digit di tengah TIDAK melempar kursor ke ujung kanan', () => {
        // Keluhan yang paling mungkin muncul kalau ini salah: "angkanya jadi acak".
        // Orang menaruh kursor sesudah 8 di "Rp 58.000" lalu mengetik 9 — hasilnya harus
        // 589.000 dengan kursor tepat sesudah 9, bukan 58.0009 atau kursor di ujung.
        const { tampil, digit, kursor } = sunting('Rp 589.000', 6);

        assert.equal(tampil, 'Rp 589.000');
        assert.equal(digit, '589000');
        assert.equal(kursor, 6, 'kursor harus tetap sesudah digit ke-3');
    });

    it('titik ribuan yang baru lahir menggeser kursor sekali, bukan dua kali', () => {
        // "Rp 999" + "9" di ujung → "Rp 9.999": jumlah karakter naik 2, jumlah digit naik 1.
        const { tampil, kursor } = sunting('Rp 9999', 7);

        assert.equal(tampil, 'Rp 9.999');
        assert.equal(kursor, 8);
    });

    it('nol depan yang dibuang tidak menggeser kursor ke kanan', () => {
        // "0" lalu mengetik "5" di depan angka: digitnya jadi "50", nolnya TIDAK dibuang.
        // Tapi "05" (nol lalu 5 di belakang) menjadi "5", dan kursor yang tadi di belakang
        // dua digit harus jatuh di belakang SATU digit.
        const { tampil, digit, kursor } = sunting('Rp 05', 5);

        assert.equal(tampil, 'Rp 5');
        assert.equal(digit, '5');
        assert.equal(kursor, 4);
    });

    it('kotak yang dikosongkan mengirim teks kosong, bukan nol', () => {
        const { tampil, digit } = sunting('', 0);

        assert.equal(tampil, '');
        assert.equal(digit, '', 'kosong yang berubah jadi "0" menghapus harga beli di master');
    });
});

describe('backspace di belakang titik ribuan', () => {
    it('kursornya dipindahkan supaya DIGIT-nya yang terhapus', () => {
        // Tanpa ini backspace menghapus titik yang langsung digambar ulang — dari luar
        // terlihat sebagai tombol hapus yang macet.
        assert.equal(kursorMundur('Rp 1.500', 5), 4);
    });

    it('backspace di belakang digit tidak dicampuri', () => {
        assert.equal(kursorMundur('Rp 1.500', 8), null);
        assert.equal(kursorMundur('Rp 1.500', 4), null);
    });

    it('backspace di dalam awalan "Rp " tidak menghapus apa pun', () => {
        assert.equal(kursorMundur('Rp 500', 3), null);
        assert.equal(kursorMundur('Rp 500', 0), null);
    });
});

describe('kotak uang di layar', () => {
    it('nilai dari server ditulis Alpine, bukan lewat atribut value=', async () => {
        // Atribut value= akan ditimpa morph Livewire dengan angka MENTAH di tengah orang
        // mengetik; karena itu nilai awalnya masuk sebagai argumen dan ditulis di init().
        const { el } = await kotak('58000');

        assert.equal(el.value, 'Rp 58.000');
    });

    it('yang dikirim ke server DIGIT KANONIK, bukan teks berformat', async () => {
        // Inilah cacat aslinya: "58.000" yang terkirim tersimpan sebagai Rp 58.
        const { dikirim, ketikkan } = await kotak('');

        ketikkan('58000');

        assert.deepEqual(dikirim.at(-1), { nama: 'harga.abc', nilai: '58000', hidup: false });
    });

    it('mengetik 58000 menampilkan Rp 58.000', async () => {
        const { el, ketikkan } = await kotak('');

        ketikkan('58000');

        assert.equal(el.value, 'Rp 58.000');
    });

    it('menyisipkan 9 sesudah digit 8 menghasilkan 589.000 dengan kursor sesudah 9', async () => {
        const { el, dikirim, ketikkan } = await kotak('58000');

        // Kursor ditaruh sesudah "8" di "Rp 58.000" (indeks 5), lalu 9 diketik.
        ketikkan('9', 5);

        assert.equal(el.value, 'Rp 589.000');
        assert.equal(el.selectionStart, 6, 'kursor harus sesudah 9, bukan di ujung kanan');
        assert.equal(dikirim.at(-1).nilai, '589000');
    });

    it('nilainya terkirim SEBELUM penundaan — Simpan yang ditekan cepat tetap benar', async () => {
        /*
         * Keadaan nyata: pemilik mengetik angka terakhir lalu langsung menekan Simpan.
         * Kalau nilainya ikut ditunda 600 ms bersama penyegaran bar, yang tersimpan adalah
         * angka SEBELUM ketikan terakhir — nota Rp 5.800 untuk belanja Rp 58.000, tanpa satu
         * pun galat. Karena itu set() harus segera dan HANYA $refresh yang ditunda.
         */
        const { dikirim, disegarkan, majuWaktu, ketikkan } = await kotak('');

        ketikkan('58000');

        assert.equal(dikirim.length, 1, 'nilainya harus sudah terkirim tanpa menunggu');
        assert.equal(disegarkan.length, 0, 'penyegaran bar ringkasan yang ditunda, bukan nilainya');

        majuWaktu();

        assert.equal(disegarkan.length, 1, 'bar ringkasan tetap harus bergerak');
    });

    it('penyegaran bar ringkasan digabung, bukan satu permintaan per huruf', async () => {
        // Satu perjalanan Livewire per ketikan berarti kotak yang macet tiap huruf di warung
        // bersinyal seadanya — dan orangnya berhenti memakai fitur ini sebelum baris kesepuluh.
        const { disegarkan, timer, majuWaktu, ketikkan } = await kotak('');

        ketikkan('5');
        ketikkan('8');
        ketikkan('0');

        majuWaktu();

        assert.equal(disegarkan.length, 1, `3 ketikan harus menyisakan 1 penyegaran, bukan ${disegarkan.length}`);
        assert.equal(timer.filter(Boolean).length, 1, 'penunda yang lama harus dibatalkan');
    });

    it('penundaannya sama dengan debounce kolom jumlah', () => {
        // Dua jeda yang berbeda membuat bar ringkasan bergerak dua kali untuk satu nota yang
        // sama — terlihat seperti angka yang berubah sendiri.
        assert.equal(JEDA_SEGAR, 600);
    });

    it('penunda dibuang saat kotaknya dibuang', async () => {
        // Kotaknya ikut hilang saat pindah halaman daftar barang atau ganti outlet; penyegaran
        // yang menyala sesudah itu memanggil $wire yang sudah tidak ada.
        const { kotakUang, disegarkan, majuWaktu, ketikkan } = await kotak('');

        ketikkan('5');
        kotakUang.destroy();
        majuWaktu();

        assert.equal(disegarkan.length, 0);
    });
});

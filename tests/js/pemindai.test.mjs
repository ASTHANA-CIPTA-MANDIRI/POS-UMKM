/**
 * Pengujian pemindai barcode.
 *
 * Empat jalur pemindaian (USB, kamera bawaan, kamera cadangan, foto) tidak bisa diuji
 * dengan PHPUnit dan hampir tidak bisa diuji dengan tangan: menguji jalur cadangan
 * berarti mencari peramban yang tidak punya BarcodeDetector, dan menguji kegagalan izin
 * berarti menolak izin kamera lalu mengembalikannya. Berkas ini memalsukan keadaan itu.
 *
 * Yang paling penting diuji adalah penangkap ketikan pemindai USB: ia mendengarkan
 * SELURUH jendela, jadi kalau penjaganya salah, ia akan membajak ketikan orang yang
 * sedang mengisi formulir. Dijalankan dengan: npm run uji:js
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

let terkirim;
let toast;

/** Detektor palsu: mengembalikan nilai yang sudah ditentukan per pemanggilan. */
function detektorPalsu(nilai) {
    return { detect: async () => (nilai === null ? [] : [{ rawValue: nilai }]) };
}

function dekoderPalsu({ nilaiKamera = '8991002101234', cadangan = false, nilaiFoto = '8991002105555', gagalFoto = false } = {}) {
    return {
        buatDetektor: async () => ({ detektor: detektorPalsu(nilaiKamera), cadangan }),
        bacaBerkas: async () => {
            if (gagalFoto) {
                throw new Error('rusak');
            }

            return nilaiFoto;
        },
    };
}

/**
 * Aliran kamera palsu.
 *
 * Mencatat apakah trek-treknya benar-benar dihentikan, dan menirukan tiga perangkat
 * yang berbeda soal lampu: tidak punya, punya, serta yang MENGAKU punya lalu menolak
 * menyalakannya (kejadian nyata di sebagian ponsel).
 */
function aliranPalsu(deviceId = 'kam-1', { lampu = false, lampuGagal = false } = {}) {
    const trek = {
        berhenti: false,
        batasan: [],
        stop() { this.berhenti = true; },
        getSettings: () => ({ deviceId }),
        getCapabilities: lampu ? () => ({ torch: true }) : undefined,
        async applyConstraints(b) {
            if (lampuGagal) {
                throw new Error('ditolak');
            }

            this.batasan.push(b);
        },
    };

    return { trek, getTracks: () => [trek], getVideoTracks: () => [trek] };
}

/**
 * Menyiapkan komponen beserta lingkungan peramban palsunya.
 *
 * @param {object} pilihan
 * @param {object|null} pilihan.mediaDevices  null = peramban tanpa kamera
 * @param {boolean}     pilihan.aman          konteks aman (HTTPS/localhost)
 */
async function pasang({ dekoder = dekoderPalsu(), mediaDevices = undefined, aman = true, kamera = ['kam-1'], lampu = false, lampuGagal = false, mode = undefined } = {}) {
    terkirim = [];
    toast = [];

    const alat = mediaDevices === undefined
        ? {
            getUserMedia: async (batasan) => {
                const diminta = batasan.video?.deviceId?.exact;

                if (diminta && ! kamera.includes(diminta)) {
                    throw Object.assign(new Error('tidak ada'), { name: 'OverconstrainedError' });
                }

                return aliranPalsu(diminta ?? kamera[0], { lampu, lampuGagal });
            },
            enumerateDevices: async () => kamera.map((id) => ({ kind: 'videoinput', deviceId: id })),
        }
        : mediaDevices;

    Object.defineProperty(global, 'navigator', {
        value: alat === null ? {} : { mediaDevices: alat },
        configurable: true,
        writable: true,
    });

    let daftar = null;

    global.window = {
        isSecureContext: aman,
        Alpine: { data: (_nama, factory) => { daftar = factory; } },
        toastNampan: (pesan, jenis) => toast.push({ pesan, jenis }),
    };

    global.document = {
        addEventListener: (nama, f) => { if (nama === 'alpine:init') f(); },
        getElementById: () => null,
    };

    // Modul dimuat ulang tiap kali supaya keadaan di dalamnya tidak bocor antar-uji.
    const modul = await import('../../resources/js/pemindai.js?t=' + Math.random());
    modul.pasangPemindai(async () => dekoder);

    assert.ok(daftar, 'komponen pemindai tidak terdaftar');

    const objek = mode === undefined ? daftar() : daftar(mode);

    objek.$nextTick = async () => {};
    objek.$dispatch = (nama, detail) => terkirim.push({ nama, detail });
    objek.$refs = { video: { srcObject: null, play: async () => {} } };

    return objek;
}

/** Menunggu pekerjaan yang dijalankan tanpa await (pantau) selesai satu putaran. */
function tunggu() {
    return new Promise((r) => setTimeout(r, 0));
}

/** Ketikan pemindai USB: karakter berurutan cepat, ditutup Enter. */
function pindaiUsb(p, angka, { penutup = 'Enter', jeda = 12, sasaran = { tagName: 'BODY' } } = {}) {
    let jam = 1000;
    p.sekarang = () => jam;

    for (const huruf of angka) {
        jam += jeda;
        p.tangkap({ key: huruf, target: sasaran, preventDefault() {} });
    }

    if (penutup) {
        jam += jeda;
        p.tangkap({ key: penutup, target: sasaran, preventDefault() {} });
    }
}

describe('pemindai USB', () => {
    it('menangkap angka yang diketik pemindai walau kolomnya belum diklik', async () => {
        const p = await pasang();

        pindaiUsb(p, '8991002101234');

        assert.deepEqual(terkirim, [{ nama: 'barcode-terpindai', detail: { nilai: '8991002101234' } }]);
    });

    it('menerima Tab sebagai penutup, bukan hanya Enter', async () => {
        const p = await pasang();

        pindaiUsb(p, '8991002101234', { penutup: 'Tab' });

        assert.equal(terkirim.length, 1);
    });

    it('tidak membajak ketikan orang saat fokus berada di sebuah kolom', async () => {
        const p = await pasang();

        // Angka harga diketik di kolomnya sendiri; kolom itu yang berhak menerimanya.
        pindaiUsb(p, '12500', { sasaran: { tagName: 'INPUT' } });

        assert.deepEqual(terkirim, []);
        assert.equal(p.sanggah, '');
    });

    it('tidak menyalahartikan ketikan manusia sebagai pindaian', async () => {
        const p = await pasang();

        // Jeda 200 ms antar-tombol: jauh di atas kecepatan pemindai mana pun.
        pindaiUsb(p, '8991002101234', { jeda: 200 });

        assert.deepEqual(terkirim, []);
    });

    it('mengabaikan angka yang terlalu pendek untuk sebuah barcode', async () => {
        const p = await pasang();

        pindaiUsb(p, '12345');

        assert.deepEqual(terkirim, []);
    });

    it('tidak memutus pindaian karena tombol Shift di tengah', async () => {
        const p = await pasang();
        let jam = 1000;
        p.sekarang = () => jam;

        for (const huruf of ['8', '9', '9', 'Shift', '1', '0', '0', '2']) {
            jam += 12;
            p.tangkap({ key: huruf, target: { tagName: 'BODY' }, preventDefault() {} });
        }

        jam += 12;
        p.tangkap({ key: 'Enter', target: { tagName: 'BODY' }, preventDefault() {} });

        assert.deepEqual(terkirim, [{ nama: 'barcode-terpindai', detail: { nilai: '8991002' } }]);
    });

    it('mencegah Enter dari pemindai menekan tombol yang sedang terfokus', async () => {
        const p = await pasang();
        let dicegah = false;
        let jam = 1000;
        p.sekarang = () => jam;

        for (const huruf of '8991002101234') {
            jam += 12;
            p.tangkap({ key: huruf, target: { tagName: 'BODY' }, preventDefault() {} });
        }

        p.tangkap({ key: 'Enter', target: { tagName: 'BODY' }, preventDefault: () => { dicegah = true; } });

        assert.ok(dicegah, 'Enter dari pemindai harus dicegah');
    });

    it('berhenti mendengarkan selama panel kamera terbuka', async () => {
        const p = await pasang();
        p.terbuka = true;

        pindaiUsb(p, '8991002101234');

        assert.deepEqual(terkirim, []);
    });
});

describe('kamera', () => {
    it('menyembunyikan jalur kamera di peramban tanpa mediaDevices', async () => {
        const p = await pasang({ mediaDevices: null });

        assert.equal(p.bisaKamera, false);
    });

    it('menyembunyikan jalur kamera di alamat yang bukan konteks aman', async () => {
        // Persis keadaan tablet kasir yang membuka aplikasi lewat http://192.168.x.x.
        const p = await pasang({ aman: false });

        assert.equal(p.bisaKamera, false);
    });

    it('mengarahkan ke USB, foto, atau ketik manual ketika kamera tidak boleh dipakai', async () => {
        const p = await pasang({ aman: false });

        await p.buka();

        assert.equal(p.terbuka, false);
        assert.match(p.galat, /USB/);
        assert.match(p.galat, /foto/);
        assert.match(p.galat, /ketik/);
    });

    it('mengirim nilai lalu mematikan kamera begitu barcode terbaca', async () => {
        const p = await pasang();

        await p.buka();
        await tunggu();

        assert.deepEqual(terkirim, [{ nama: 'barcode-terpindai', detail: { nilai: '8991002101234' } }]);
        assert.equal(p.terbuka, false, 'panel harus menutup sendiri');
        assert.equal(p.berjalan, false, 'perulangan pemantauan harus berhenti');
        assert.equal(p.aliran, null, 'aliran kamera harus dilepas, bukan dibiarkan menyala');
        assert.deepEqual(toast.map((t) => t.jenis), ['sukses']);
    });

    it('memberi tahu ketika yang dipakai pembaca cadangan', async () => {
        const p = await pasang({ dekoder: dekoderPalsu({ cadangan: true }) });

        await p.buka();

        assert.equal(p.memakaiCadangan, true);
    });

    it('tidak meninggalkan penanda "menyiapkan" setelah pemindai siap', async () => {
        const p = await pasang({ dekoder: dekoderPalsu({ nilaiKamera: null }) });

        await p.buka();

        assert.equal(p.menyiapkan, false);
        assert.equal(p.berjalan, true);

        p.tutup();
    });

    it('menjelaskan izin yang ditolak beserta jalan keluarnya', async () => {
        const p = await pasang({
            mediaDevices: {
                getUserMedia: async () => { throw Object.assign(new Error('x'), { name: 'NotAllowedError' }); },
                enumerateDevices: async () => [],
            },
        });

        await p.buka();

        assert.equal(p.terbuka, false);
        assert.match(p.galat, /[Ii]zin/);
        assert.match(p.galat, /foto/);
    });

    it('menjelaskan kamera yang sedang dipakai aplikasi lain', async () => {
        const p = await pasang({
            mediaDevices: {
                getUserMedia: async () => { throw Object.assign(new Error('x'), { name: 'NotReadableError' }); },
                enumerateDevices: async () => [],
            },
        });

        await p.buka();

        assert.match(p.galat, /aplikasi lain/);
    });

    it('menawarkan ganti kamera hanya kalau memang ada lebih dari satu', async () => {
        const satu = await pasang({ dekoder: dekoderPalsu({ nilaiKamera: null }), kamera: ['kam-1'] });
        await satu.buka();
        assert.equal(satu.banyakKamera, false);
        satu.tutup();

        const dua = await pasang({ dekoder: dekoderPalsu({ nilaiKamera: null }), kamera: ['kam-1', 'kam-2'] });
        await dua.buka();
        assert.equal(dua.banyakKamera, true);
        dua.tutup();
    });

    it('melepas aliran lama saat berpindah kamera', async () => {
        const p = await pasang({ dekoder: dekoderPalsu({ nilaiKamera: null }), kamera: ['kam-1', 'kam-2'] });

        await p.buka();

        const lama = p.aliran;
        await p.gantiKamera();

        assert.ok(lama.trek.berhenti, 'kamera pertama harus dimatikan, bukan dibiarkan menyala');
        assert.notEqual(p.aliran, lama);

        p.tutup();
    });
});

describe('pindaian berturut-turut di kasir', () => {
    /** Kamera yang mengembalikan nilai berbeda tiap pemanggilan. */
    function dekoderBerurutan(nilai) {
        let i = 0;

        return {
            buatDetektor: async () => ({
                detektor: {
                    detect: async () => {
                        const n = nilai[Math.min(i++, nilai.length - 1)];

                        return n === null ? [] : [{ rawValue: n }];
                    },
                },
                cadangan: false,
            }),
            bacaBerkas: async () => null,
        };
    }

    it('tetap membuka panel dan terus memindai setelah satu barcode berhasil', async () => {
        const p = await pasang({ mode: 'lanjut', dekoder: dekoderBerurutan(['8991002101234', null]) });

        await p.buka();
        await tunggu();

        assert.equal(terkirim.length, 1);
        assert.equal(p.terbuka, true, 'panel tidak boleh menutup di mode berkelanjutan');
        assert.equal(p.berjalan, true, 'pemantauan harus lanjut untuk barang berikutnya');
        assert.notEqual(p.aliran, null, 'kamera harus tetap menyala');

        p.tutup();
    });

    it('menutup panel setelah berhasil di mode sekali pakai', async () => {
        const p = await pasang();

        await p.buka();
        await tunggu();

        assert.equal(terkirim.length, 1);
        assert.equal(p.terbuka, false);
        assert.equal(p.aliran, null);
    });

    it('tidak menerima kode yang sama berulang dari frame yang sama', async () => {
        // Barang yang masih dipegang di depan lensa terbaca di setiap frame.
        const p = await pasang({ mode: 'lanjut', dekoder: dekoderBerurutan(['8991002101234']) });
        let jam = 1000;
        p.sekarang = () => jam;

        await p.buka();
        await new Promise((r) => setTimeout(r, 700));

        assert.equal(terkirim.length, 1, 'satu barang tidak boleh masuk berkali-kali');

        // Setelah jeda terlewat, barang yang sama boleh dipindai lagi — pembeli
        // memang bisa membawa dua bungkus yang sama.
        jam += 2000;
        await new Promise((r) => setTimeout(r, 400));

        assert.equal(terkirim.length, 2);

        p.tutup();
    });

    it('menerima kode berbeda tanpa menunggu jeda', async () => {
        const p = await pasang({ mode: 'lanjut', dekoder: dekoderBerurutan(['8991002101234', '8991002105555', null]) });
        let jam = 1000;
        p.sekarang = () => jam;

        await p.buka();
        await new Promise((r) => setTimeout(r, 700));

        assert.deepEqual(terkirim.map((t) => t.detail.nilai), ['8991002101234', '8991002105555']);

        p.tutup();
    });

    it('tidak menoastkan tiap pindaian di mode berkelanjutan', async () => {
        const p = await pasang({ mode: 'lanjut', dekoder: dekoderBerurutan(['8991002101234', null]) });

        await p.buka();
        await tunggu();

        // Sepuluh barang berarti sepuluh toast yang menutupi layar yang sedang dipakai;
        // hasilnya ditampilkan di dalam panel oleh pemakainya.
        assert.deepEqual(toast, []);

        p.tutup();
    });

    it('menghitung pindaian dan melaporkannya sekali saat selesai', async () => {
        const p = await pasang({ mode: 'lanjut', dekoder: dekoderBerurutan(['8991002101234', '8991002105555', null]) });

        await p.buka();
        await new Promise((r) => setTimeout(r, 700));

        assert.equal(p.jumlahPindai, 2);
        assert.equal(p.terakhir, '8991002105555');

        p.selesai();

        assert.equal(p.terbuka, false);
        assert.deepEqual(toast.map((t) => t.pesan), ['2 barang dipindai.']);
    });

    it('tidak melaporkan apa pun kalau ditutup tanpa memindai', async () => {
        const p = await pasang({ mode: 'lanjut', dekoder: dekoderPalsu({ nilaiKamera: null }) });

        await p.buka();
        p.selesai();

        assert.deepEqual(toast, []);
    });

    it('memulai hitungan dari nol setiap kali panel dibuka', async () => {
        const p = await pasang({ mode: 'lanjut', dekoder: dekoderBerurutan(['8991002101234', null]) });

        await p.buka();
        await tunggu();
        assert.equal(p.jumlahPindai, 1);

        p.selesai();
        await p.buka();

        assert.equal(p.jumlahPindai, 0);
        assert.equal(p.terakhir, null);

        p.tutup();
    });

    it('tidak membatasi pindaian USB yang sama berturut-turut', async () => {
        // Dua bungkus mi identik dipindai cepat: keduanya HARUS masuk. Menelan yang
        // kedua berarti satu barang terjual tanpa tercatat.
        const p = await pasang({ mode: 'lanjut' });

        pindaiUsb(p, '8991002101234');
        pindaiUsb(p, '8991002101234');

        assert.equal(terkirim.length, 2);
    });
    it('tidak melaporkan apa-apa kalau selesai() terpicu saat panel sudah tertutup', async () => {
        // Pendengar Escape terpasang di tingkat jendela dan tetap hidup setelah panel
        // menutup sendiri di mode sekali pakai.
        const p = await pasang();

        await p.buka();
        await tunggu();

        assert.equal(p.terbuka, false, 'mode sekali pakai menutup sendiri');
        toast.length = 0;

        p.selesai();

        assert.deepEqual(toast, []);
    });
});

describe('lampu kamera', () => {
    it('tidak menawarkan lampu kalau kameranya tidak punya', async () => {
        const p = await pasang({ dekoder: dekoderPalsu({ nilaiKamera: null }) });

        await p.buka();

        assert.equal(p.punyaLampu, false);

        p.tutup();
    });

    it('menawarkan lampu kalau kameranya punya', async () => {
        const p = await pasang({ dekoder: dekoderPalsu({ nilaiKamera: null }), lampu: true });

        await p.buka();

        assert.equal(p.punyaLampu, true);
        assert.equal(p.lampuNyala, false, 'lampu tidak boleh menyala sendiri');

        p.tutup();
    });

    it('menyalakan lalu mematikan lampu lewat batasan trek', async () => {
        const p = await pasang({ dekoder: dekoderPalsu({ nilaiKamera: null }), lampu: true });

        await p.buka();
        const trek = p.aliran.trek;

        await p.gantiLampu();
        assert.equal(p.lampuNyala, true);

        await p.gantiLampu();
        assert.equal(p.lampuNyala, false);

        assert.deepEqual(trek.batasan, [
            { advanced: [{ torch: true }] },
            { advanced: [{ torch: false }] },
        ]);

        p.tutup();
    });

    it('menyembunyikan tombolnya kalau perangkat menolak menyalakan lampu', async () => {
        const p = await pasang({ dekoder: dekoderPalsu({ nilaiKamera: null }), lampu: true, lampuGagal: true });

        await p.buka();
        await p.gantiLampu();

        // Tombol yang tidak berbuat apa pun lebih membingungkan daripada tidak ada.
        assert.equal(p.punyaLampu, false);
        assert.equal(p.lampuNyala, false);

        p.tutup();
    });

    it('melupakan keadaan lampu setelah panel ditutup', async () => {
        const p = await pasang({ dekoder: dekoderPalsu({ nilaiKamera: null }), lampu: true });

        await p.buka();
        await p.gantiLampu();
        p.tutup();

        assert.equal(p.lampuNyala, false, 'panel berikutnya tidak boleh mengira lampunya masih menyala');
        assert.equal(p.punyaLampu, false);
    });

    it('memeriksa ulang lampu setiap kali kamera diganti', async () => {
        // Lampu milik kamera belakang; berpindah kamera harus memeriksanya lagi.
        const p = await pasang({ dekoder: dekoderPalsu({ nilaiKamera: null }), lampu: true, kamera: ['kam-1', 'kam-2'] });

        await p.buka();
        await p.gantiLampu();
        assert.equal(p.lampuNyala, true);

        await p.gantiKamera();
        assert.equal(p.lampuNyala, false, 'kamera baru berarti lampu baru, mulai dari mati');

        p.tutup();
    });
});

describe('foto sebagai cadangan terakhir', () => {
    it('mengisi barcode dari foto yang dipilih', async () => {
        const p = await pasang();
        const medan = { files: [{ name: 'barcode.jpg' }], value: 'barcode.jpg' };

        await p.dariBerkas({ target: medan });

        assert.deepEqual(terkirim, [{ nama: 'barcode-terpindai', detail: { nilai: '8991002105555' } }]);
        assert.equal(medan.value, '', 'kolom berkas harus dikosongkan agar foto sama bisa dicoba lagi');
        assert.equal(p.membacaFoto, false);
    });

    it('mengatakan apa yang harus diperbaiki ketika foto tidak berisi barcode', async () => {
        const p = await pasang({ dekoder: dekoderPalsu({ nilaiFoto: null }) });

        await p.dariBerkas({ target: { files: [{}], value: 'a.jpg' } });

        assert.deepEqual(terkirim, []);
        assert.match(p.galat, /lebih dekat/);
    });

    it('tidak menggantung di keadaan membaca ketika pembacaan gagal', async () => {
        const p = await pasang({ dekoder: dekoderPalsu({ gagalFoto: true }) });

        await p.dariBerkas({ target: { files: [{}], value: 'a.jpg' } });

        assert.equal(p.membacaFoto, false);
        assert.match(p.galat, /tidak bisa dibaca/);
    });

    it('tidak melakukan apa pun kalau pemilihan foto dibatalkan', async () => {
        const p = await pasang();

        await p.dariBerkas({ target: { files: [], value: '' } });

        assert.deepEqual(terkirim, []);
        assert.equal(p.galat, null);
    });
});

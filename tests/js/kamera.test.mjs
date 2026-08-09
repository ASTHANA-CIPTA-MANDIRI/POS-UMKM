/**
 * Kamera pemotret struk (layar owner "Nota belanja").
 *
 * Kamera hampir tidak bisa diuji dengan tangan: menguji "izin ditolak" berarti menolak izin
 * lalu mengembalikannya, dan menguji "kamera dipakai aplikasi lain" berarti membuka Zoom.
 * Berkas ini memalsukan keadaan itu.
 *
 * Tiga hal yang dijaga paling keras, dan ketiganya cacat yang SUNYI:
 *
 *  1. Potret diambil dari ukuran TREK, bukan ukuran elemen videonya. Salah di sini
 *     menghasilkan JPEG selebar 320 px yang tampak baik di pratinjau kecil dan tidak
 *     terbaca sesudah disimpan — ketahuan paling lambat, saat fotonya dibutuhkan.
 *  2. Menutup panel MEMATIKAN kameranya. Trek yang tertinggal hidup meninggalkan lampu
 *     kamera menyala, dan pemilik menyimpulkan aplikasinya merekam.
 *  3. Di konteks tidak aman, getUserMedia TIDAK pernah dipanggil. Tombol yang pasti gagal
 *     lebih buruk daripada tombol yang tidak ada.
 *
 * Dijalankan dengan: npm run uji:js
 */

import assert from 'node:assert/strict';
import { describe, it, beforeEach } from 'node:test';

import { namaPotret, pesanKamera, ukuranPotret, pasangKamera } from '../../resources/js/kamera.js';

/* ── Fungsi murni ────────────────────────────────────────────────────────── */

describe('ukuranPotret', () => {
    it('membiarkan gambar yang sudah di bawah batas apa adanya', () => {
        assert.deepEqual(ukuranPotret(1920, 1080), { lebar: 1920, tinggi: 1080 });
    });

    it('memperkecil sisi terpanjang ke 2400 dan mempertahankan rasionya', () => {
        const h = ukuranPotret(4000, 3000);

        assert.equal(h.lebar, 2400);
        assert.equal(h.tinggi, 1800);
        assert.equal(Math.round((h.lebar / h.tinggi) * 100), Math.round((4000 / 3000) * 100),
            'struk yang gepeng lebih buruk daripada struk yang kecil');
    });

    it('memperkecil dari sisi TINGGI kalau itu yang terpanjang — struk memang tegak', () => {
        const h = ukuranPotret(1200, 4800);

        assert.equal(h.tinggi, 2400);
        assert.equal(h.lebar, 600);
    });

    it('mengembalikan nol untuk ukuran yang tidak masuk akal, bukan NaN', () => {
        assert.deepEqual(ukuranPotret(0, 0), { lebar: 0, tinggi: 0 });
        assert.deepEqual(ukuranPotret(NaN, 100), { lebar: 0, tinggi: 0 });
    });
});

describe('namaPotret', () => {
    it('berpola struk-YYYYMMDD-HHmm.jpg memakai waktu lokal', () => {
        assert.equal(namaPotret(new Date(2026, 7, 9, 6, 5)), 'struk-20260809-0605.jpg');
    });
});

describe('pesanKamera', () => {
    it('izin ditolak: menyebut cara mengizinkannya', () => {
        const p = pesanKamera({ name: 'NotAllowedError' });

        assert.match(p, /Izin kamera ditolak/);
        assert.match(p, /ikon kunci/, 'harus menyebut DI MANA izinnya diubah');
    });

    it('kamera dipakai aplikasi lain: menyebut aplikasi yang biasa memakainya', () => {
        assert.match(pesanKamera({ name: 'NotReadableError' }), /Zoom|Meet|WhatsApp/);
    });

    it('tanpa kamera: menawarkan jalan lain, bukan sekadar mengabarkan', () => {
        assert.match(pesanKamera({ name: 'NotFoundError' }), /Pilih foto atau PDF|pakai HP/);
    });

    it('sebab yang tidak dikenal tetap menawarkan jalan keluar', () => {
        assert.match(pesanKamera(new Error('aneh')), /Pilih foto atau PDF/);
    });
});

/* ── Komponen Alpine ─────────────────────────────────────────────────────── */

/** Trek palsu yang mencatat apakah ia dihentikan. */
function trekPalsu(setelan) {
    return { berhenti: false, getSettings: () => setelan, stop() { this.berhenti = true; } };
}

function aliranPalsu(trek) {
    return { getVideoTracks: () => [trek], getTracks: () => [trek] };
}

/**
 * Menyiapkan window/document palsu lalu mengembalikan komponennya.
 *
 * Alpine tidak dijalankan; `Alpine.data` cuma ditangkap supaya pabriknya bisa dipanggil
 * langsung. Yang diuji logikanya, bukan Alpine-nya.
 */
function siapkan({ aman = true, adaMedia = true, gagal = null, setelan = { width: 1920, height: 1080 } } = {}) {
    const trek = trekPalsu(setelan);
    const dipanggil = { getUserMedia: 0, toBlobMutu: null };
    let pabrik = null;

    const video = { srcObject: null, videoWidth: 320, videoHeight: 180, play: async () => {} };

    const kanvasPalsu = {
        width: 0,
        height: 0,
        getContext: () => ({ drawImage: () => {} }),
        toDataURL: () => 'data:image/jpeg;base64,AAAA',
        toBlob(cb, jenis, mutu) {
            dipanggil.toBlobMutu = { jenis, mutu };
            cb({ size: 1234, type: 'image/jpeg' });
        },
    };

    const berkasTerpasang = [];
    const input = {
        id: 'lampiran-baru',
        files: [],
        dispatchEvent(e) { berkasTerpasang.push(e.type); return true; },
    };

    global.window = {
        isSecureContext: aman,
        Alpine: { data: (_nama, f) => { pabrik = f; } },
    };
    global.document = {
        addEventListener: (_n, f) => f(),
        createElement: () => kanvasPalsu,
        getElementById: (id) => (id === 'lampiran-baru' ? input : null),
    };
    global.navigator = {
        mediaDevices: adaMedia
            ? {
                getUserMedia: async (batasan) => {
                    dipanggil.getUserMedia++;
                    dipanggil.batasan = batasan;

                    if (gagal) { throw gagal; }

                    return aliranPalsu(trek);
                },
            }
            : {},
    };
    global.Event = class { constructor(t) { this.type = t; } };
    global.File = class { constructor(bagian, nama, opsi) { this.name = nama; this.type = opsi?.type; } };
    global.DataTransfer = class {
        constructor() { this.berkas = []; this.items = { add: (b) => this.berkas.push(b) }; }
        get files() { return this.berkas; }
    };

    pasangKamera();

    const k = pabrik('lampiran-baru');
    k.$refs = { video };

    return { k, trek, dipanggil, input, kanvasPalsu, video, berkasTerpasang };
}

describe('kameraBukti', () => {
    beforeEach(() => {
        delete global.window;
        delete global.document;
        delete global.navigator;
    });

    it('di konteks TIDAK aman, getUserMedia tidak pernah dipanggil', async () => {
        const { k, dipanggil } = siapkan({ aman: false });

        assert.equal(k.bisaKamera, false);

        await k.buka();

        assert.equal(dipanggil.getUserMedia, 0,
            'tombol yang pasti gagal lebih buruk daripada tombol yang tidak ada');
        assert.equal(k.terbuka, false);
    });

    it('tanpa mediaDevices sama sekali, bisaKamera false', () => {
        const { k } = siapkan({ adaMedia: false });

        assert.equal(k.bisaKamera, false);
    });

    it('meminta 1920x1080 sebagai ideal, bukan exact', async () => {
        const { k, dipanggil } = siapkan();

        await k.buka();

        assert.equal(dipanggil.batasan.video.width.ideal, 1920);
        assert.equal(dipanggil.batasan.video.height.ideal, 1080);
        assert.equal(dipanggil.batasan.video.width.exact, undefined,
            'exact membuat webcam 1280x720 melempar OverconstrainedError — fitur mati total');
    });

    it('izin ditolak: panel DITUTUP dan galatnya tetap terbaca', async () => {
        const { k } = siapkan({ gagal: Object.assign(new Error('x'), { name: 'NotAllowedError' }) });

        await k.buka();

        assert.equal(k.terbuka, false, 'panelnya tidak boleh menggantung terbuka tanpa gambar');
        assert.match(k.galat, /Izin kamera ditolak/);
    });

    it('memotret dari ukuran TREK, bukan ukuran elemen videonya', async () => {
        const { k, kanvasPalsu, video } = siapkan({ setelan: { width: 1920, height: 1080 } });

        assert.equal(video.videoWidth, 320, 'pramis: elemennya memang jauh lebih kecil');

        await k.buka();
        k.jepret();

        assert.equal(kanvasPalsu.width, 1920);
        assert.equal(kanvasPalsu.height, 1080);
    });

    it('potret di atas 2400 px diperkecil, rasionya utuh', async () => {
        const { k, kanvasPalsu } = siapkan({ setelan: { width: 4000, height: 3000 } });

        await k.buka();
        k.jepret();

        assert.equal(kanvasPalsu.width, 2400);
        assert.equal(kanvasPalsu.height, 1800);
    });

    it('menutup panel MEMATIKAN treknya', async () => {
        const { k, trek } = siapkan();

        await k.buka();
        assert.equal(trek.berhenti, false);

        k.tutup();

        assert.equal(trek.berhenti, true, 'lampu kamera yang menyala membuat orang berhenti memakainya');
        assert.equal(k.aliran, null);
    });

    it('destroy() ikut mematikan kamera — Livewire boleh membuang komponennya kapan saja', async () => {
        const { k, trek } = siapkan();

        await k.buka();
        k.destroy();

        assert.equal(trek.berhenti, true);
    });

    it('memakai potret: menyuntikkan JPEG ke kotak berkas dan memicu change', async () => {
        const { k, input, dipanggil, berkasTerpasang, trek } = siapkan();

        await k.buka();
        k.jepret();
        k.pakai();

        assert.equal(input.files.length, 1);
        assert.match(input.files[0].name, /^struk-\d{8}-\d{4}\.jpg$/);
        assert.equal(input.files[0].type, 'image/jpeg');
        assert.deepEqual(berkasTerpasang, ['change'], 'Livewire hanya bereaksi pada event change');
        assert.equal(dipanggil.toBlobMutu.jenis, 'image/jpeg');
        assert.equal(dipanggil.toBlobMutu.mutu, 0.85);
        assert.equal(trek.berhenti, true, 'sesudah dipakai, kameranya dimatikan');
    });

    it('berkas yang SUDAH dipilih tidak terbuang oleh potret baru', async () => {
        const { k, input } = siapkan();

        input.files = [{ name: 'lembar-1.jpg' }];

        await k.buka();
        k.jepret();
        k.pakai();

        assert.equal(input.files.length, 2,
            'memotret lembar kedua tidak boleh membuang lembar pertama yang baru dipilih');
        assert.equal(input.files[0].name, 'lembar-1.jpg');
    });

    it('ulangi membuang pratinjau tanpa mematikan kameranya', async () => {
        const { k, trek } = siapkan();

        await k.buka();
        k.jepret();
        assert.notEqual(k.pratinjau, '');

        k.ulangi();

        assert.equal(k.pratinjau, '');
        assert.equal(trek.berhenti, false, 'membidik ulang bukan menutup panel');
    });
});

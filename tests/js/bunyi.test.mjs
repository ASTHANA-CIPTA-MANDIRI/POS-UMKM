/**
 * Pengujian bunyi umpan balik pindaian.
 *
 * Yang diuji bukan "apakah ada suara" — itu tidak bisa didengar oleh mesin — melainkan
 * hal yang menentukan gunanya: bahwa bunyi BERHASIL dan GAGAL benar-benar berbeda, dan
 * bahwa perangkat tanpa Web Audio tidak jadi rusak karenanya. Dijalankan: npm run uji:js
 */

import assert from 'node:assert/strict';
import { beforeEach, describe, it } from 'node:test';

let dibuat;
let keadaan;
let dilanjutkan;
let diucapkan;
let dibatalkan;

/** AudioContext palsu yang mencatat nada apa saja yang disusun. */
function konteksPalsu() {
    return class {
        constructor() {
            this.currentTime = 0;
            this.destination = { nama: 'keluaran' };
            Object.defineProperty(this, 'state', { get: () => keadaan });
        }

        resume() {
            dilanjutkan++;

            return Promise.resolve();
        }

        createOscillator() {
            const osilator = {
                type: null,
                frequency: { value: null },
                connect() {},
                start(t) { this.mulai = t; },
                stop(t) { this.selesai = t; },
            };

            dibuat.push(osilator);

            return osilator;
        }

        createGain() {
            return {
                gain: {
                    nilai: [],
                    setValueAtTime(v, t) { this.nilai.push(['setel', v, t]); },
                    linearRampToValueAtTime(v, t) { this.nilai.push(['linear', v, t]); },
                    exponentialRampToValueAtTime(v, t) { this.nilai.push(['eksponen', v, t]); },
                },
                connect() {},
            };
        }
    };
}

/** Memuat ulang modul supaya AudioContext yang tersimpan di dalamnya tidak bocor. */
async function muat({ adaAudio = true, state = 'running', adaUcapan = false, suara = [] } = {}) {
    dibuat = [];
    dilanjutkan = 0;
    diucapkan = [];
    dibatalkan = 0;
    keadaan = state;

    global.window = adaAudio ? { AudioContext: konteksPalsu() } : {};

    if (adaUcapan) {
        global.window.speechSynthesis = {
            getVoices: () => suara,
            cancel: () => { dibatalkan++; },
            speak: (u) => diucapkan.push(u),
            addEventListener: () => {},
        };

        global.window.SpeechSynthesisUtterance = class {
            constructor(teks) {
                this.text = teks;
                this.lang = null;
                this.rate = null;
                this.pitch = null;
                this.volume = null;
                this.voice = null;
            }
        };
    }

    return import('../../resources/js/bunyi.js?t=' + Math.random());
}

describe('bunyi pindaian', () => {
    beforeEach(() => {
        dibuat = [];
        dilanjutkan = 0;
    });

    it('membunyikan satu nada tinggi untuk pindaian berhasil', async () => {
        const { mainkan } = await muat();

        mainkan('sukses');

        assert.equal(dibuat.length, 1, 'berhasil cukup satu blip pendek');
        assert.equal(dibuat[0].frequency.value, 1180);
    });

    it('membunyikan dua nada rendah yang menurun untuk pindaian gagal', async () => {
        const { mainkan } = await muat();

        mainkan('gagal');

        const frekuensi = dibuat.map((o) => o.frequency.value);

        assert.equal(dibuat.length, 2, 'gagal harus lebih dari satu nada');
        assert.ok(frekuensi[0] > frekuensi[1], 'nadanya harus menurun, bukan naik');
        assert.ok(Math.max(...frekuensi) < 1180, 'gagal harus lebih rendah daripada berhasil');
    });

    /**
     * Beda tinggi nada saja tidak cukup: pengeras suara tablet dan ponsel murah memangkas
     * frekuensi rendah, jadi bunyi gagal harus juga lebih PANJANG dan berjumlah lebih dari
     * satu supaya tetap terbedakan di perangkat yang buruk.
     */
    it('membedakan gagal dari berhasil lewat panjang, bukan hanya tinggi nada', async () => {
        const { mainkan } = await muat();

        mainkan('sukses');
        const panjangSukses = Math.max(...dibuat.map((o) => o.selesai));

        dibuat = [];
        mainkan('gagal');
        const panjangGagal = Math.max(...dibuat.map((o) => o.selesai));

        assert.ok(panjangGagal > panjangSukses * 3, `gagal (${panjangGagal}s) harus jelas lebih panjang daripada berhasil (${panjangSukses}s)`);
    });

    it('menaikkan dan menurunkan volume secara halus, bukan nyala-mati mentah', async () => {
        // Osilator yang dihidupkan pada amplitudo penuh berbunyi "klik" di tiap nada, dan
        // pada dua puluh pindaian semenit klik itulah yang membuat orang mematikan suara.
        const { mainkan } = await muat();
        const dibuatGain = [];
        const asli = global.window.AudioContext;

        global.window.AudioContext = class extends asli {
            createGain() {
                const g = super.createGain();
                dibuatGain.push(g);

                return g;
            }
        };

        mainkan('sukses');

        const jenis = dibuatGain[0].gain.nilai.map((n) => n[0]);

        assert.deepEqual(jenis, ['setel', 'linear', 'eksponen']);
        assert.ok(dibuatGain[0].gain.nilai[0][1] < 0.01, 'harus mulai dari hampir nol');
    });

    it('melanjutkan konteks yang ditahan peramban', async () => {
        // Peramban menahan AudioContext sampai ada sentuhan; tanpa resume() bunyinya tidak
        // akan pernah keluar walau kodenya berjalan.
        const { mainkan } = await muat({ state: 'suspended' });

        mainkan('sukses');

        assert.equal(dilanjutkan, 1);
    });

    it('tidak melakukan apa pun di peramban tanpa Web Audio', async () => {
        const { mainkan } = await muat({ adaAudio: false });

        mainkan('sukses');

        assert.equal(dibuat.length, 0);
    });

    it('mengabaikan jenis bunyi yang tidak dikenal', async () => {
        const { mainkan } = await muat();

        mainkan('entah');

        assert.equal(dibuat.length, 0);
    });

    it('memakai satu konteks untuk semua bunyi', async () => {
        // Membuat AudioContext baru tiap pindaian akan menghabiskan kuota konteks
        // peramban dan menghentikan bunyinya di tengah shift.
        const { mainkan } = await muat();
        let jumlahKonteks = 0;
        const asli = global.window.AudioContext;

        global.window.AudioContext = class extends asli {
            constructor() {
                super();
                jumlahKonteks++;
            }
        };

        mainkan('sukses');
        mainkan('gagal');
        mainkan('sukses');

        assert.ok(jumlahKonteks <= 1, 'konteks tidak boleh dibuat ulang tiap bunyi');
    });

    it('memasang dirinya di window supaya bisa dipanggil dari Alpine', async () => {
        const modul = await muat();

        modul.pasangBunyi();

        assert.equal(typeof global.window.bunyiNampan, 'function');
    });
});

describe('ucapan pindaian', () => {
    const suaraLokal = { name: 'Damayanti', lang: 'id-ID', localService: true };
    const suaraJaringan = { name: 'Awan', lang: 'id-ID', localService: false };
    const suaraInggris = { name: 'Samantha', lang: 'en-US', localService: true };
    const lelakiLokal = { name: 'Microsoft Ardi - Indonesian', lang: 'id-ID', localService: true };
    const perempuanLokal = { name: 'Microsoft Gadis - Indonesian', lang: 'id-ID', localService: true };
    const perempuanJaringan = { name: 'Google Bahasa Indonesia', lang: 'id-ID', localService: false };
    const tanpaPenanda = { name: 'Microsoft Andika', lang: 'id-ID', localService: true };

    it('mengucap "Berhasil" dan "Gagal"', async () => {
        const { mainkan } = await muat({ adaUcapan: true, suara: [suaraLokal] });

        mainkan('sukses', 'ucapan');
        mainkan('gagal', 'ucapan');

        // Bertitik: kata tunggal tanpa tanda baca dibaca datar oleh hampir semua mesin
        // ucap, sedangkan kalimat utuh mendapat intonasi menurun di akhir.
        assert.deepEqual(diucapkan.map((u) => u.text), ['Berhasil.', 'Gagal.']);
        assert.deepEqual(diucapkan.map((u) => u.lang), ['id-ID', 'id-ID']);
    });

    it('tidak membangkitkan nada apa pun saat memakai ucapan', async () => {
        const { mainkan } = await muat({ adaUcapan: true, suara: [suaraLokal] });

        mainkan('sukses', 'ucapan');

        assert.equal(dibuat.length, 0, 'ucapan dan bip tidak boleh berbunyi bersamaan');
    });

    /**
     * Ucapan mengantre. Sepuluh pindaian cepat tanpa cancel() membuat kasir mendengar
     * hasil barang keempat saat sudah memindai yang kesembilan — kabar terlambat yang
     * akan dikira milik barang lain.
     */
    it('membatalkan antrean supaya pindaian terbaru yang terdengar', async () => {
        const { mainkan } = await muat({ adaUcapan: true, suara: [suaraLokal] });

        mainkan('sukses', 'ucapan');
        mainkan('sukses', 'ucapan');
        mainkan('gagal', 'ucapan');

        assert.equal(dibatalkan, 3, 'setiap ucapan harus membatalkan yang sedang berjalan');
    });

    it('memilih suara Indonesia yang LOKAL, bukan yang dari jaringan', async () => {
        // Suara dari server diam saat jaringan mati — keadaan yang justru harus bersuara.
        const { mainkan } = await muat({ adaUcapan: true, suara: [suaraJaringan, suaraLokal] });

        mainkan('sukses', 'ucapan');

        assert.equal(diucapkan[0].voice, suaraLokal);
    });

    it('memakai suara Indonesia dari jaringan kalau tidak ada yang lokal', async () => {
        const { mainkan } = await muat({ adaUcapan: true, suara: [suaraInggris, suaraJaringan] });

        mainkan('sukses', 'ucapan');

        assert.equal(diucapkan[0].voice, suaraJaringan);
    });

    it('tetap mengucap tanpa suara Indonesia sama sekali', async () => {
        // Aksennya asing, tapi "Berhasil"/"Gagal" masih terdengar jelas — jauh lebih baik
        // daripada diam.
        const { mainkan } = await muat({ adaUcapan: true, suara: [suaraInggris] });

        mainkan('gagal', 'ucapan');

        assert.equal(diucapkan.length, 1);
        assert.equal(diucapkan[0].voice, null);
    });

    it('jatuh ke bip di perangkat tanpa mesin ucap', async () => {
        const { mainkan } = await muat({ adaUcapan: false });

        mainkan('gagal', 'ucapan');

        // Bukan diam: sebagian peramban dalam aplikasi dan Android lama tidak punya TTS.
        assert.equal(diucapkan.length, 0);
        assert.equal(dibuat.length, 2, 'harus jatuh ke dua nada bip');
    });

    it('memakai bip ketika modenya bip, walau mesin ucap tersedia', async () => {
        const { mainkan } = await muat({ adaUcapan: true, suara: [suaraLokal] });

        mainkan('sukses', 'bip');

        assert.equal(diucapkan.length, 0);
        assert.equal(dibuat.length, 1);
    });

    it('mengabaikan jenis yang tidak dikenal tanpa mengucap apa pun', async () => {
        const { mainkan } = await muat({ adaUcapan: true, suara: [suaraLokal] });

        mainkan('entah', 'ucapan');

        assert.equal(diucapkan.length, 0);
        assert.equal(dibuat.length, 0);
    });
    it('berbicara dengan laju wajar, nada lembut, dan volume tidak mentok', async () => {
        const { mainkan } = await muat({ adaUcapan: true, suara: [suaraLokal] });

        mainkan('sukses', 'ucapan');

        const u = diucapkan[0];

        // Laju dipercepat terdengar terpotong dan paling mudah salah dengar di warung
        // ramai; yang butuh cepat memakai mode bip.
        assert.ok(u.rate <= 1.05, `laju ${u.rate} terlalu cepat untuk ucapan yang jelas`);
        assert.ok(u.pitch > 1, 'nada sedikit di atas normal supaya terdengar lembut');
        assert.ok(u.pitch <= 1.2, 'jangan sampai melengking');
        assert.ok(u.volume < 1, 'volume mentok mudah pecah di pengeras suara tablet');
    });

    it('memilih suara perempuan di antara suara lokal yang tersedia', async () => {
        const { mainkan } = await muat({ adaUcapan: true, suara: [lelakiLokal, perempuanLokal] });

        mainkan('sukses', 'ucapan');

        assert.equal(diucapkan[0].voice, perempuanLokal);
    });

    it('memilih suara yang belum jelas jenisnya daripada yang jelas lelaki', async () => {
        // "Andika" tidak ditebak jenisnya — menebak salah lebih buruk daripada tidak
        // menebak — tapi ia tetap lebih dipilih daripada suara yang pasti lelaki.
        const { mainkan } = await muat({ adaUcapan: true, suara: [lelakiLokal, tanpaPenanda] });

        mainkan('sukses', 'ucapan');

        assert.equal(diucapkan[0].voice, tanpaPenanda);
    });

    /**
     * Pertukaran yang disengaja: suara yang ada DI PERANGKAT menang atas jenis suara.
     *
     * Suara dari server diam saat jaringan mati, dan warung tanpa internet adalah keadaan
     * yang paling butuh suaranya. Suara lelaki yang berbunyi lebih berguna daripada suara
     * perempuan yang bisu.
     */
    it('mendahulukan suara yang ada di perangkat daripada suara perempuan dari jaringan', async () => {
        const { mainkan } = await muat({ adaUcapan: true, suara: [perempuanJaringan, lelakiLokal] });

        mainkan('sukses', 'ucapan');

        assert.equal(diucapkan[0].voice, lelakiLokal);
    });

    it('memakai suara perempuan dari jaringan kalau di perangkat tidak ada sama sekali', async () => {
        const { mainkan } = await muat({ adaUcapan: true, suara: [perempuanJaringan] });

        mainkan('sukses', 'ucapan');

        assert.equal(diucapkan[0].voice, perempuanJaringan);
    });
});


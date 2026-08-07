/**
 * Uji cacat layar kasir: perhitungan uang & keadaan offline.
 *
 * Berkas ini LAHIR DARI CACAT — setiap uji di sini GAGAL pada kode sekarang dan
 * komentarnya menjelaskan cacat apa yang dibuktikannya. Pola harnessnya sama dengan
 * tests/js/kasir.test.mjs (Alpine dipalsukan lewat window.Alpine.data), bedanya
 * fetch di sini bisa DIGANTUNG supaya keadaan "kiriman belum dibalas server" bisa
 * diuji — itu keadaan yang paling sering terjadi di warung dengan sinyal 2G.
 *
 * Jalankan: npm run uji:js
 */

import assert from 'node:assert/strict';
import { before, beforeEach, describe, it } from 'node:test';

let buatKasir;
let simpanan;
let permintaan;
let balasan;

/** Diisi tes yang ingin menggantung kiriman: fetch tidak dibalas sampai dituntaskan. */
let tuntaskanFetch = null;

const PRODUK = [
    { id: 'p1', nama: 'Nasi Putih', harga: 5000, kategori: 'Makanan', pecahan: false, barcode: '8991002101234', sku: 'NSP-1' },
    { id: 'p2', nama: 'Es Teh', harga: 4000, kategori: 'Minuman', pecahan: false, barcode: null, sku: 'TEH-1' },
    { id: 'p3', nama: 'Beras', harga: 12000, kategori: 'Bahan', pecahan: true, barcode: '8991002109999', sku: null },
    /*
     * Harga ganjil pada satuan pecahan. Bukan data karangan: Owner › Produk
     * memvalidasi harga hanya dengan ['required','numeric','min:0'] dan menyimpannya
     * ke decimal(15,2) (app/Livewire/Pages/Owner/Produk/Produk.php:138), jadi Rp 4.999/kg
     * memang bisa disimpan owner.
     */
    { id: 'p4', nama: 'Cabai', harga: 4999, kategori: 'Bahan', pecahan: true, barcode: null, sku: 'CBI-1' },
];

function pasang(konfigurasi = {}) {
    const objek = buatKasir({
        outletId: 'outlet-1',
        deviceId: 'device-1',
        sesiId: 'sesi-1',
        urlKatalog: '/kasir/katalog',
        urlSinkron: '/sinkronisasi/transaksi',
        bekalAwal: {
            produk: PRODUK,
            pelanggan: [{ id: 'c1', nama: 'Bu Sinta', no_hp: '0812' }],
            mode: ['langsung', 'open_bill', 'pesan_antar'],
        },
        ...konfigurasi,
    });

    objek.init();

    return objek;
}

/** Meniru apa yang dilakukan medan nominal di layar saat kasir mengetik di baris i. */
function ketikJumlah(k, i, teks) {
    k.pembayaran[i].jumlah = k.angkaDari(teks);
    k.lepasOtomatis(i);
}

before(async () => {
    simpanan = new Map();

    global.localStorage = {
        getItem: (k) => (simpanan.has(k) ? simpanan.get(k) : null),
        setItem: (k, v) => simpanan.set(k, String(v)),
        removeItem: (k) => simpanan.delete(k),
    };

    Object.defineProperty(global, 'navigator', {
        value: { onLine: true },
        configurable: true,
        writable: true,
    });

    global.window = {
        addEventListener: () => {},
        setTimeout: () => 0,
        clearTimeout: () => {},
        print: () => {},
        Alpine: { data: (_nama, factory) => { buatKasir = factory; } },
    };

    global.document = {
        addEventListener: (nama, f) => { if (nama === 'alpine:init') f(); },
        querySelector: () => ({ content: 'token-csrf' }),
    };

    global.fetch = async (url, opsi) => {
        permintaan.push({ url, body: JSON.parse(opsi.body) });

        if (tuntaskanFetch !== null) {
            return new Promise((resolve) => { tuntaskanFetch = resolve; });
        }

        return balasan;
    };

    const modul = await import('../../resources/js/kasir.js');
    modul.pasangKasir();

    assert.ok(buatKasir, 'komponen kasir tidak terdaftar');
});

beforeEach(() => {
    simpanan.clear();
    permintaan = [];
    tuntaskanFetch = null;
    balasan = { ok: true, status: 200, json: async () => ({ jumlah_dibuat: 1, detail_gagal: [] }) };
});

describe('CACAT — bayar terpisah menimpa nominal yang sudah diketik kasir', () => {
    /**
     * resources/js/kasir.js:885 lepasOtomatis()
     *
     * Peran "penutup sisa" dipindahkan ke baris LAIN tanpa peduli baris itu sudah
     * diketik kasir atau belum, lalu isiSisa() menimpa angkanya. Akibatnya nominal
     * yang benar-benar diterima kasir diubah diam-diam supaya jumlahnya pas dengan
     * tagihan — dan kurang bayar (pembeli uangnya tidak cukup) tidak pernah terlihat.
     */
    it('tidak menimpa nominal tunai yang sudah diketik saat baris kedua diketik', () => {
        const k = pasang();

        k.tambah(k.katalog[0]); // 5.000
        k.tambah(k.katalog[1]); // 9.000

        // Pembeli menyerahkan 4.000 tunai; sisanya mau dibayar QRIS.
        ketikJumlah(k, 0, '4000');
        k.tambahPembayaran();
        k.pembayaran[1].metode = 'qris';

        // Ternyata QRIS-nya hanya berhasil 3.000 (saldo pembeli kurang).
        ketikJumlah(k, 1, '3000');

        assert.equal(k.pembayaran[0].jumlah, 4000,
            'tunai yang benar-benar diterima 4.000 tidak boleh diubah sistem');
        assert.equal(k.pembayaran[1].jumlah, 3000);
        assert.equal(k.selisihBayar, -2000, 'kurang bayar 2.000 harus terlihat sebagai kurang');
        assert.equal(k.bisaBayar, false, 'transaksi kurang bayar tidak boleh bisa ditutup');
    });

    /**
     * Kasus paling merugikan: baris nontunai yang SUDAH TERBAYAR di mesin QRIS ikut
     * ditimpa. Angka di laporan per metode jadi lebih besar daripada yang benar-benar
     * masuk ke rekening, dan tidak ada peringatan apa pun di layar.
     */
    it('tidak menimpa nominal QRIS yang sudah terbayar di mesin', () => {
        const k = pasang();

        k.tambah(k.katalog[0]);
        k.tambah(k.katalog[0]); // 10.000
        k.tambah(k.katalog[1]); // 14.000

        ketikJumlah(k, 0, '4000');   // tunai 4.000
        k.tambahPembayaran();
        k.pembayaran[1].metode = 'qris';
        ketikJumlah(k, 1, '3000');   // QRIS 3.000 — sudah tercetak di mesin
        k.tambahPembayaran();
        k.pembayaran[2].metode = 'ewallet';
        ketikJumlah(k, 2, '1000');   // e-wallet 1.000

        assert.equal(k.pembayaran[1].jumlah, 3000,
            'QRIS yang sudah terbayar 3.000 tidak boleh dinaikkan sistem');
        assert.equal(k.totalDibayar, 8000);
        assert.equal(k.selisihBayar, -6000);
    });

    /**
     * resources/js/kasir.js:1054 — setiap baris tunai mencatat this.kembalian, yaitu
     * kembalian SELURUH transaksi. Dua baris tunai berarti kembalian yang sama
     * tersimpan dua kali di transaction_payments.
     */
    it('tidak mencatat kembalian yang sama di setiap baris tunai', async () => {
        const k = pasang();

        k.tambah(k.katalog[0]); // 5.000
        ketikJumlah(k, 0, '3000');
        k.pembayaran[0].diterima = 20000;
        k.tambahPembayaran();
        k.pembayaran[1].metode = 'cash';
        k.pembayaran[1].diterima = 0;

        await k.bayar();

        const bayar = permintaan[0].body.transactions[0].payments;
        const totalKembalian = bayar.reduce((j, p) => j + (p.kembalian ?? 0), 0);

        assert.equal(totalKembalian, k.strukTerakhir.kembalian,
            'jumlah kembalian tersimpan harus sama dengan kembalian yang diberikan');
    });
});

describe('CACAT — satuan pecahan berharga ganjil menghasilkan uang setengah rupiah', () => {
    /**
     * resources/js/kasir.js:792 totalKeranjang + :911 isiSisa
     *
     * 0,5 kg × Rp 4.999 = Rp 2.499,5. Angka itu dipakai apa adanya sebagai total
     * transaksi, nominal pembayaran, dan kas masuk — padahal rupiah(2499.5) di layar
     * dan di struk menampilkan "2.500". Yang tercatat di buku bukan yang dibaca kasir
     * maupun yang diterima laci.
     */
    it('membulatkan tagihan ke rupiah utuh sebelum dicatat', async () => {
        const k = pasang();

        k.tambah(k.katalog[3]); // Cabai 0,5 kg

        assert.equal(k.rupiah(k.totalTagihan), '2.500', 'yang dibaca kasir di layar');
        assert.ok(Number.isInteger(k.totalTagihan),
            `tagihan tidak boleh mengandung pecahan rupiah, dapat: ${k.totalTagihan}`);

        k.pembayaran[0].diterima = 2500;
        await k.bayar();

        const trx = permintaan[0].body.transactions[0];
        assert.ok(Number.isInteger(trx.total), `total tercatat: ${trx.total}`);
        assert.ok(Number.isInteger(trx.payments[0].jumlah), `nominal tercatat: ${trx.payments[0].jumlah}`);
    });

    /**
     * Akibat kedua, yang membuat kasir macet: begitu medan nominal disentuh, isinya
     * dibaca ulang lewat angkaDari() yang membuang pecahan (2.500), sedangkan tagihan
     * tetap 2.499,5. Selisihnya 0,5 — tidak bisa dihilangkan dengan cara apa pun
     * karena angkaDari() tidak bisa menghasilkan angka pecahan — dan bisaBayar
     * mensyaratkan totalDibayar === totalTagihan (kasir.js:978). Tombol Bayar mati
     * permanen dengan keterangan "Lebih Rp 1".
     */
    it('tetap bisa dibayar setelah kasir menyentuh medan nominal', () => {
        const k = pasang();

        k.tambah(k.katalog[3]);

        // Persis yang terjadi di layar: @input membaca nilai tampilan lewat angkaDari.
        ketikJumlah(k, 0, k.rupiah(k.pembayaran[0].jumlah));
        k.pembayaran[0].diterima = 2500;

        assert.equal(k.bisaBayar, true,
            'kasir menerima uang pas seperti yang tertulis di layar, transaksi harus bisa ditutup');
    });
});

describe('CACAT — kasbon nol menandai transaksi lunas sebagai belum lunas', () => {
    /**
     * resources/js/kasir.js:1028 (status) vs :1049 (filter payments)
     *
     * Status diambil dari adaKasbon — ADA BARISNYA — sedangkan baris bernominal 0
     * dibuang dari payload. Jadi transaksi yang uangnya sudah diterima penuh dikirim
     * dengan status 'belum_lunas' tanpa satu pun pembayaran kasbon. Di server tidak
     * ada CreditLedger yang dibuat (SyncOfflineTransactionsAction.php:269), sementara
     * Owner › Laporan menghitung 'belum_lunas' dari total transaksi
     * (Laporan.php:88): piutang hantu yang tidak bisa ditagih maupun dilunasi.
     *
     * Cara memunculkannya di layar: tekan "+ Bayar terpisah" ketika baris pertama
     * sudah menutup seluruh tagihan (baris baru lahir bernilai 0), lalu tekan tombol
     * metode "Kasbon" di baris baru itu.
     */
    it('tidak menandai belum lunas ketika baris kasbon nominalnya nol', async () => {
        const k = pasang();

        k.tambah(k.katalog[0]); // 5.000 — baris pertama otomatis menutup semuanya
        k.pembayaran[0].diterima = 5000;

        k.tambahPembayaran();          // baris kedua lahir bernilai 0
        k.pembayaran[1].metode = 'kasbon';
        k.pelangganId = 'c1';          // layar memaksa memilih pelanggan dulu

        await k.bayar();

        const trx = permintaan[0].body.transactions[0];

        assert.equal(trx.payments.length, 1, 'hanya tunai yang benar-benar dikirim');
        assert.equal(trx.status, 'lunas',
            'uangnya sudah diterima penuh dan tidak ada piutang yang dikirim');
    });
});

describe('CACAT — kiriman yang menggantung membekukan kasir', () => {
    /**
     * resources/js/kasir.js:1122 (sibuk = true sebelum fetch) + :959 (bisaBayar
     * menolak saat sibuk), dan fetch di :1125 TIDAK punya batas waktu maupun
     * AbortController.
     *
     * Sinyal lemah (bukan mati) membuat POST menggantung sampai batas waktu TCP.
     * Selama itu bisaBayar false, jadi tombol Bayar mati dan kasir tidak bisa
     * menutup transaksi siapa pun — melanggar aturan 3 CLAUDE.md: layar kasir tidak
     * boleh bergantung jaringan.
     */
    it('tetap bisa menutup transaksi selama antrean masih menggantung di jaringan', async () => {
        const k = pasang();

        k.online = false;
        k.tambah(k.katalog[0]);
        k.pembayaran[0].diterima = 5000;
        await k.bayar();
        assert.equal(k.antrean.length, 1);

        // Jaringan kembali tapi lambat: kiriman belum dibalas server.
        tuntaskanFetch = () => {};
        k.online = true;
        const kiriman = k.kirimAntrean();
        assert.equal(permintaan.length, 1, 'kiriman sudah berjalan');

        // Pembeli berikutnya sudah menunggu di depan meja.
        k.tambah(k.katalog[1]);
        k.pembayaran[0].diterima = 4000;

        assert.equal(k.bisaBayar, true,
            'penjualan tidak boleh ikut terhenti hanya karena kiriman belum dibalas');

        await k.bayar();
        assert.equal(k.antrean.length, 2, 'transaksi kedua harus tercatat di perangkat');

        tuntaskanFetch(balasan);
        await kiriman;
    });

    /**
     * resources/js/kasir.js:1159 — setelah server membalas, ANTREAN SEKARANG
     * (this.antrean) yang disaring, bukan paket yang tadi dikirim. Paket yang masuk
     * SELAMA kiriman berjalan tidak ada di dalam balasan server, jadi ia tidak punya
     * id gagal dan ikut terhapus tanpa pernah terkirim.
     */
    it('tidak membuang paket yang masuk antrean selama kiriman berjalan', async () => {
        const k = pasang();

        k.online = false;
        k.gantiMode('pesan_antar');
        k.labelBaru = 'LDY-001';
        k.bukaBill();
        k.tambah(k.katalog[1]);
        k.tambahKeBill();
        k.pembayaran[0].diterima = 4000;
        await k.bayar();               // 1 paket: pelunasan LDY-001
        assert.equal(k.antrean.length, 1);

        k.labelBaru = 'LDY-002';       // titipan lain yang masih diproses
        k.bukaBill();

        tuntaskanFetch = () => {};
        k.online = true;
        const kiriman = k.kirimAntrean();

        // Cucian LDY-002 selesai dicuci sementara kiriman tadi belum dibalas.
        k.majukanStatus(k.billTerpilih);
        assert.equal(k.antrean.length, 2, 'perubahan status masuk antrean');

        tuntaskanFetch(balasan);
        await kiriman;

        assert.equal(k.antrean.length, 1,
            'perubahan status LDY-002 belum pernah dikirim, jadi tidak boleh dibuang');
        assert.equal(JSON.parse(simpanan.get('nampan.antrean')).length, 1);
    });
});

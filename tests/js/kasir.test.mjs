/**
 * Pengujian logika klien kasir.
 *
 * Perhitungan uang di layar kasir hidup di JavaScript, jadi ia tidak tersentuh oleh
 * suite PHPUnit. Tanpa berkas ini, kesalahan pada total, kembalian, atau pembagian
 * pembayaran baru ketahuan di warung. Dijalankan dengan: npm run uji:js
 */

import assert from 'node:assert/strict';
import { before, beforeEach, describe, it } from 'node:test';

let buatKasir;
let bunyi;
let simpanan;
let permintaan;
let balasan;

/** Alpine memanggil factory lalu memakai objeknya sebagai `this`; getter harus ikut. */
function pasang(konfigurasi = {}) {
    const objek = buatKasir({
        outletId: 'outlet-1',
        deviceId: 'device-1',
        sesiId: 'sesi-1',
        urlKatalog: '/kasir/katalog',
        urlSinkron: '/sinkronisasi/transaksi',
        bekalAwal: {
            produk: [
                { id: 'p1', nama: 'Nasi Putih', harga: 5000, kategori: 'Makanan', pecahan: false, barcode: '8991002101234', sku: 'NSP-1' },
                { id: 'p2', nama: 'Es Teh', harga: 4000, kategori: 'Minuman', pecahan: false, barcode: null, sku: 'TEH-1' },
                { id: 'p3', nama: 'Beras', harga: 12000, kategori: 'Bahan', pecahan: true, barcode: '8991002109999', sku: null },
            ],
            pelanggan: [{ id: 'c1', nama: 'Bu Sinta', no_hp: '0812' }],
            mode: ['langsung', 'open_bill', 'pesan_antar'],
            bill: [],
        },
        ...konfigurasi,
    });

    objek.init();

    return objek;
}

before(async () => {
    simpanan = new Map();

    global.localStorage = {
        getItem: (k) => (simpanan.has(k) ? simpanan.get(k) : null),
        setItem: (k, v) => simpanan.set(k, String(v)),
        removeItem: (k) => simpanan.delete(k),
    };

    // navigator di Node hanya punya getter, jadi tidak bisa ditimpa lewat penugasan.
    Object.defineProperty(global, 'navigator', {
        value: { onLine: true },
        configurable: true,
        writable: true,
    });

    const pendengar = new Map();
    global.window = {
        addEventListener: (n, f) => pendengar.set(n, f),
        setTimeout: () => 0,
        clearTimeout: () => {},
        print: () => {},
        // Bunyi pindaian: yang dicatat cukup JENISnya — apakah berhasil atau gagal yang
        // dibunyikan. Nadanya sendiri diuji di bunyi.test.mjs.
        bunyiNampan: (jenis, mode) => bunyi.push(mode === undefined ? jenis : jenis + ':' + mode),
        Alpine: { data: (_nama, factory) => { buatKasir = factory; } },
    };

    global.document = {
        addEventListener: (nama, f) => { if (nama === 'alpine:init') f(); },
        querySelector: () => ({ content: 'token-csrf' }),
    };

    global.fetch = async (url, opsi) => {
        permintaan.push({ url, body: JSON.parse(opsi.body) });

        return balasan;
    };

    const modul = await import('../../resources/js/kasir.js');
    modul.pasangKasir();

    assert.ok(buatKasir, 'komponen kasir tidak terdaftar');
});

beforeEach(() => {
    simpanan.clear();
    bunyi = [];
    permintaan = [];
    balasan = { ok: true, status: 200, json: async () => ({ jumlah_dibuat: 1, detail_gagal: [] }) };
});

describe('keranjang', () => {
    it('menjumlahkan harga kali jumlah', () => {
        const k = pasang();

        k.tambah(k.katalog[0]);
        k.tambah(k.katalog[0]);
        k.tambah(k.katalog[1]);

        assert.equal(k.totalKeranjang, 14000);
    });

    it('memakai langkah 0,5 untuk satuan pecahan dan tidak menyimpan galat pembulatan', () => {
        const k = pasang();
        const beras = k.katalog[2];

        k.tambah(beras);
        k.tambah(beras);
        k.tambah(beras);

        // 0,5 + 0,5 + 0,5 = 1,5 — bukan 1.5000000000000002
        assert.equal(k.keranjang[0].qty, 1.5);
        assert.equal(k.totalKeranjang, 18000);
    });

    it('membuang baris ketika jumlahnya turun ke nol', () => {
        const k = pasang();

        k.tambah(k.katalog[0]);
        k.ubahQty(k.keranjang, k.keranjang[0], -1);

        assert.equal(k.keranjang.length, 0);
    });
});

describe('kategori', () => {
    it('menghitung isi kategori dan mengikuti kata pencarian', () => {
        const k = pasang();

        assert.equal(k.jumlahKategori('Semua'), 3);
        assert.equal(k.jumlahKategori('Minuman'), 1);
        assert.equal(k.jumlahKategori('Makanan'), 1);

        // Angka harus menyusut mengikuti pencarian, kalau tidak ia menjanjikan
        // isi yang tidak ada di grid.
        k.pencarian = 'nasi';
        assert.equal(k.jumlahKategori('Semua'), 1);
        assert.equal(k.jumlahKategori('Minuman'), 0);
    });

    it('menyusun daftar kategori dengan Semua di depan tanpa duplikat', () => {
        const k = pasang();

        assert.equal(k.kategori[0], 'Semua');
        assert.equal(new Set(k.kategori).size, k.kategori.length);
    });
});

describe('pembayaran', () => {
    it('mengisi jumlah bayar otomatis mengikuti tagihan', () => {
        const k = pasang();

        k.tambah(k.katalog[0]); // 5.000
        assert.equal(k.pembayaran[0].jumlah, 5000, 'tanpa perlu menyentuh medannya');

        k.tambah(k.katalog[1]); // + 4.000
        assert.equal(k.pembayaran[0].jumlah, 9000, 'ikut naik saat produk ditambah');

        k.ubahQty(k.keranjang, k.keranjang[1], -1);
        assert.equal(k.pembayaran[0].jumlah, 5000, 'ikut turun saat produk dikurangi');

        k.kosongkan();
        assert.equal(k.pembayaran[0].jumlah, 0);
    });

    it('berhenti mengikuti tagihan setelah kasir mengetik sendiri', () => {
        const k = pasang();

        k.tambah(k.katalog[0]); // 5.000
        k.pembayaran[0].jumlah = 3000;
        k.lepasOtomatis(0);

        k.tambah(k.katalog[1]);

        assert.equal(k.pembayaran[0].jumlah, 3000, 'angka yang diketik kasir tidak boleh ditimpa');
        assert.equal(k.bisaBayar, false, 'kurang bayar tetap ditolak');
    });

    it('menahan tombol bayar sampai jumlah pembayaran sama dengan tagihan', () => {
        const k = pasang();

        k.tambah(k.katalog[0]);
        k.pembayaran[0].jumlah = 4000;
        k.lepasOtomatis(0);
        assert.equal(k.bisaBayar, false, 'kurang bayar seharusnya ditolak');

        k.pembayaran[0].jumlah = 5000;
        k.pembayaran[0].diterima = 5000;
        assert.equal(k.bisaBayar, true);
    });

    it('menolak tunai yang uang diterimanya kurang', () => {
        const k = pasang();

        k.tambah(k.katalog[0]);
        k.pembayaran[0].jumlah = 5000;
        k.pembayaran[0].diterima = 3000;

        assert.equal(k.bisaBayar, false);
    });

    it('membagi tagihan antara tunai dan QRIS, dan hanya tunai yang punya kembalian', () => {
        const k = pasang();

        k.tambah(k.katalog[0]); // 5.000
        k.tambah(k.katalog[1]); // 4.000  → 9.000

        k.pembayaran[0].jumlah = 4000;
        k.pembayaran[0].diterima = 5000;
        k.tambahPembayaran();
        k.pembayaran[1].metode = 'qris';
        k.pembayaran[1].jumlah = 5000;

        assert.equal(k.totalTagihan, 9000);
        assert.equal(k.totalDibayar, 9000);
        assert.equal(k.totalTunai, 4000);
        assert.equal(k.kembalian, 1000);
        assert.equal(k.bisaBayar, true);
    });

    it('menyeimbangkan bayar terpisah saat kasir mengetik di baris kedua', () => {
        const k = pasang();

        k.tambah(k.katalog[0]); // 5.000
        k.tambah(k.katalog[1]); // 9.000
        assert.equal(k.pembayaran[0].jumlah, 9000);

        k.tambahPembayaran();
        k.pembayaran[1].metode = 'qris';

        // Inilah urutan yang dilakukan kasir di lapangan: nominal nontunai diketik
        // lebih dulu. Baris tunai harus MENGURANGI dirinya, bukan membeku penuh.
        k.pembayaran[1].jumlah = 4000;
        k.lepasOtomatis(1);

        assert.equal(k.pembayaran[0].jumlah, 5000, 'baris pertama mengambil alih sisa');
        assert.equal(k.totalDibayar, 9000, 'jumlah bayar tidak boleh melebihi tagihan');
        assert.equal(k.selisihBayar, 0);

        k.pembayaran[0].diterima = 5000;
        assert.equal(k.bisaBayar, true);
    });

    it('melaporkan arah selisih saat jumlah bayar tidak pas', () => {
        const k = pasang();

        k.tambah(k.katalog[0]); // 5.000
        k.pembayaran[0].jumlah = 3000;
        k.lepasOtomatis(0);
        assert.equal(k.selisihBayar, -2000, 'kurang bayar bernilai negatif');

        k.pembayaran[0].jumlah = 8000;
        assert.equal(k.selisihBayar, 3000, 'kelebihan bernilai positif');
        assert.equal(k.bisaBayar, false);
    });

    it('menutup sisa tagihan pada baris kedua tanpa diketik', () => {
        const k = pasang();

        k.tambah(k.katalog[0]); // 5.000
        k.tambah(k.katalog[1]); // 9.000
        assert.equal(k.pembayaran[0].jumlah, 9000);

        // Kasir menerima 4.000 tunai, sisanya nontunai.
        k.pembayaran[0].jumlah = 4000;
        k.lepasOtomatis(0);
        k.tambahPembayaran();

        assert.equal(k.pembayaran[1].jumlah, 5000, 'baris baru menutup sisanya sendiri');
        assert.equal(k.totalDibayar, 9000);

        // Menghapus baris kedua membuat baris pertama kembali menutup seluruh tagihan.
        k.hapusPembayaran(1);
        assert.equal(k.pembayaran[0].jumlah, 9000);
    });

    it('menyediakan tepat metode yang dikenal server', () => {
        const k = pasang();

        // Kode ini dikirim apa adanya ke endpoint sinkronisasi dan divalidasi di sana
        // dengan enum PaymentMethod. Salah tulis satu huruf = 422 di tengah antrean.
        assert.deepEqual(k.metodeTersedia.map((m) => m.kode), ['cash', 'qris', 'ewallet', 'edc', 'kasbon']);
        assert.equal(k.labelMetode('cash'), 'Tunai');
        assert.equal(k.labelMetode('kasbon'), 'Kasbon');
        // Kode tak dikenal tidak boleh membuat struk kosong.
        assert.equal(k.labelMetode('entah'), 'entah');
    });

    it('memakai nama metode yang sama di struk seperti yang ditekan kasir', async () => {
        const k = pasang();

        k.tambah(k.katalog[0]);
        k.pembayaran[0].metode = 'qris';
        k.pembayaran[0].jumlah = 5000;
        await k.bayar();

        assert.equal(k.strukTerakhir.pembayaran[0].metode, 'qris');
        assert.equal(k.labelMetode(k.strukTerakhir.pembayaran[0].metode), 'QRIS');
    });

    it('menolak kasbon tanpa pelanggan', () => {
        const k = pasang();

        k.tambah(k.katalog[0]);
        k.pembayaran[0].metode = 'kasbon';
        k.pembayaran[0].jumlah = 5000;

        assert.equal(k.adaKasbon, true);
        assert.equal(k.bisaBayar, false, 'kasbon tanpa pelanggan tidak bisa ditagih');

        k.pelangganId = 'c1';
        assert.equal(k.bisaBayar, true);
    });

    it('menandai transaksi kasbon sebagai belum lunas beserta jatuh temponya', async () => {
        const k = pasang();

        k.tambah(k.katalog[0]);
        k.pembayaran[0].metode = 'kasbon';
        k.pembayaran[0].jumlah = 5000;
        k.pelangganId = 'c1';
        k.jatuhTempo = '2026-08-30';

        await k.bayar();

        const trx = permintaan[0].body.transactions[0];
        assert.equal(trx.status, 'belum_lunas');
        assert.equal(trx.customer_id, 'c1');
        assert.equal(trx.tanggal_jatuh_tempo, '2026-08-30');
    });
});

describe('mode langsung', () => {
    it('mengirim satu transaksi lunas berisi item dan pembayarannya', async () => {
        const k = pasang();

        k.tambah(k.katalog[0]);
        k.pembayaran[0].jumlah = 5000;
        k.pembayaran[0].diterima = 10000;

        await k.bayar();

        assert.equal(permintaan.length, 1);

        const kirim = permintaan[0].body;
        assert.equal(kirim.outlet_id, 'outlet-1');
        assert.equal(kirim.bills.length, 0);
        assert.equal(kirim.transactions.length, 1);

        const trx = kirim.transactions[0];
        assert.equal(trx.status, 'lunas');
        assert.equal(trx.origin, 'online');
        assert.equal(trx.total, 5000);
        assert.equal(trx.bill_id, null);
        assert.equal(trx.items.length, 1);
        assert.equal(trx.items[0].subtotal, 5000);
        assert.equal(trx.payments[0].kembalian, 5000);

        // Antrean bersih setelah server menerima.
        assert.equal(k.antrean.length, 0);
        assert.equal(k.keranjang.length, 0);
    });

    it('menyimpan ke antrean tanpa mengirim saat offline, dan menandainya offline', async () => {
        const k = pasang();
        k.online = false;

        k.tambah(k.katalog[0]);
        k.pembayaran[0].jumlah = 5000;
        k.pembayaran[0].diterima = 5000;

        await k.bayar();

        assert.equal(permintaan.length, 0, 'tidak boleh ada permintaan saat offline');
        assert.equal(k.antrean.length, 1);
        assert.equal(k.antrean[0].transactions[0].origin, 'offline');

        // Harus benar-benar tersimpan, bukan hanya di memori.
        assert.equal(JSON.parse(simpanan.get('nampan.antrean')).length, 1);
    });

    it('menahan transaksi yang ditolak server dan melepas yang sudah tersimpan', async () => {
        const k = pasang();
        k.online = false;

        for (const harga of [k.katalog[0], k.katalog[1]]) {
            k.tambah(harga);
            k.pembayaran[0].jumlah = harga.harga;
            k.pembayaran[0].diterima = harga.harga;
            await k.bayar();
        }

        assert.equal(k.antrean.length, 2);
        const idGagal = k.antrean[1].transactions[0].id;

        balasan = {
            ok: false,
            status: 207,
            json: async () => ({ detail_gagal: [{ id: idGagal, alasan: 'stok' }] }),
        };

        k.online = true;
        await k.kirimAntrean();

        assert.equal(k.antrean.length, 1);
        assert.equal(k.antrean[0].transactions[0].id, idGagal);
    });

    it('tidak membuang antrean ketika sesi kedaluwarsa', async () => {
        const k = pasang();
        k.online = false;

        k.tambah(k.katalog[0]);
        k.pembayaran[0].jumlah = 5000;
        k.pembayaran[0].diterima = 5000;
        await k.bayar();

        balasan = { ok: false, status: 419, json: async () => ({}) };
        k.online = true;
        await k.kirimAntrean();

        assert.equal(k.antrean.length, 1, 'antrean tidak boleh hilang karena 419');
    });

    it('memberi nomor transaksi berurutan yang memuat kode perangkat', async () => {
        simpanan.set('nampan.perangkat', 'KASIR-A7B9');
        const k = pasang();

        const satu = k.nomorTransaksi();
        const dua = k.nomorTransaksi();

        assert.match(satu, /^TRX-\d{8}-A7B9-0001$/);
        assert.match(dua, /^TRX-\d{8}-A7B9-0002$/);
    });
});

describe('mode open_bill', () => {
    function billTerbuka() {
        const k = pasang();
        k.gantiMode('open_bill');
        k.labelBaru = 'Meja 4';
        k.bukaBill();

        return k;
    }

    it('menolak bill tanpa label dan melaporkan kegagalannya', () => {
        const k = pasang();
        k.gantiMode('open_bill');
        k.labelBaru = '   ';

        // Nilai kembalinya dipakai layar untuk memutuskan menutup dialog atau tidak.
        assert.equal(k.bukaBill(), false);
        assert.equal(k.billLokal.length, 0);

        k.labelBaru = 'Meja 2';
        assert.equal(k.bukaBill(), true);
        assert.equal(k.billLokal.length, 1);
    });

    it('memindahkan keranjang ke bill dan menggabungkan produk yang sama', () => {
        const k = billTerbuka();

        k.tambah(k.katalog[0]);
        k.tambahKeBill();
        k.tambah(k.katalog[0]);
        k.tambah(k.katalog[1]);
        k.tambahKeBill();

        assert.equal(k.keranjang.length, 0);
        assert.equal(k.billTerpilih.pesanan.length, 2);
        assert.equal(k.billTerpilih.pesanan[0].qty, 2);
        assert.equal(k.totalBill, 14000);
        assert.equal(k.totalTagihan, 14000, 'yang ditagih adalah isi bill');
    });

    it('menutup bill bersama pelunasannya dalam satu kiriman', async () => {
        const k = billTerbuka();
        const idBill = k.billAktif;

        k.tambah(k.katalog[0]);
        k.tambahKeBill();

        k.pembayaran[0].jumlah = 5000;
        k.pembayaran[0].diterima = 5000;
        await k.bayar();

        const kirim = permintaan[0].body;

        assert.equal(kirim.bills.length, 1);
        assert.equal(kirim.bills[0].id, idBill);
        assert.equal(kirim.bills[0].status, 'selesai_dibayar');
        assert.equal(kirim.transactions[0].bill_id, idBill, 'transaksi harus menempel pada bill');
        assert.equal(kirim.transactions[0].mode, 'open_bill');
        assert.equal(kirim.transactions[0].items.length, 1);

        // Bill yang sudah dibayar tidak boleh tersisa di perangkat.
        assert.equal(k.billLokal.length, 0);
        assert.equal(k.billAktif, null);
    });

    it('menolak pembayaran selama masih ada pesanan di keranjang', () => {
        const k = billTerbuka();

        k.tambah(k.katalog[0]);
        k.tambahKeBill();

        k.pembayaran[0].jumlah = 5000;
        k.pembayaran[0].diterima = 5000;
        assert.equal(k.bisaBayar, true);

        // Pesanan baru diketik tapi belum dimasukkan: total tidak menghitungnya,
        // jadi membayar sekarang akan membuang pesanan itu.
        k.tambah(k.katalog[1]);
        assert.equal(k.totalTagihan, 5000, 'keranjang tidak boleh ikut ditagih');
        assert.equal(k.bisaBayar, false);

        k.tambahKeBill();
        k.pembayaran[0].jumlah = 9000;
        k.pembayaran[0].diterima = 9000;
        assert.equal(k.bisaBayar, true);
    });

    it('tidak menampilkan bill milik perangkat lain sama sekali', () => {
        const k = pasang({
            bekalAwal: {
                produk: [{ id: 'p1', nama: 'Nasi', harga: 5000, kategori: 'Makanan', pecahan: false }],
                pelanggan: [],
                mode: ['open_bill'],
                bill: [{ id: 'bill-server', mode: 'open_bill', status: 'terbuka', label: 'Meja 9', total: 42000 }],
            },
        });

        assert.equal(k.daftarBill.length, 0, 'bill perangkat lain tidak boleh muncul');

        // Memilih id yang tidak dikenal tidak boleh mengubah bill yang sedang aktif —
        // itu yang dulu membuat pesanan menempel di meja yang salah.
        k.labelBaru = 'Meja 1';
        k.bukaBill();
        const punyaSendiri = k.billAktif;

        k.pilihBill('bill-server');

        assert.equal(k.billAktif, punyaSendiri);
        assert.equal(k.billTerpilih.label, 'Meja 1');
    });

    it('tidak membuang keranjang saat berpindah atau membuka bill', () => {
        const k = pasang();
        k.gantiMode('open_bill');
        k.labelBaru = 'Meja 1';
        k.bukaBill();

        // Pesanan diketik untuk meja lain yang billnya belum dibuka.
        k.tambah(k.katalog[0]);
        k.tambah(k.katalog[1]);
        assert.equal(k.keranjang.length, 2);

        k.labelBaru = 'Meja 10';
        k.bukaBill();
        assert.equal(k.keranjang.length, 2, 'membuka bill baru tidak boleh menghapus keranjang');
        assert.equal(k.billTerpilih.label, 'Meja 10');

        k.tambahKeBill();
        assert.equal(k.billTerpilih.pesanan.length, 2);
        assert.equal(k.billLokal.find((b) => b.label === 'Meja 1').pesanan.length, 0,
            'pesanan tidak boleh menempel di bill yang sebelumnya terpilih');

        // Berpindah bill juga harus mempertahankan keranjang.
        k.tambah(k.katalog[0]);
        k.pilihBill(k.billLokal.find((b) => b.label === 'Meja 1').id);
        assert.equal(k.keranjang.length, 1);

        // Begitu pula berpindah mode.
        k.gantiMode('langsung');
        assert.equal(k.keranjang.length, 1);
    });

    it('memisahkan bill menurut mode yang sedang aktif', () => {
        const k = pasang();

        k.gantiMode('open_bill');
        k.labelBaru = 'Meja 1';
        k.bukaBill();

        k.gantiMode('pesan_antar');
        assert.equal(k.daftarBill.length, 0, 'bill meja tidak boleh muncul di mode titipan');

        k.gantiMode('open_bill');
        assert.equal(k.daftarBill.length, 1);
    });
});

describe('keranjang tersimpan di perangkat', () => {
    it('memulihkan keranjang setelah halaman dimuat ulang', () => {
        const k = pasang();

        k.tambah(k.katalog[0]);
        k.tambah(k.katalog[0]);
        k.tambah(k.katalog[1]);

        // Yang tersimpan harus benar-benar di localStorage, bukan hanya di memori:
        // inilah yang membuat tombol Beranda dan muat ulang aman ditekan.
        const tersimpan = JSON.parse(simpanan.get('nampan.keranjang'));
        assert.equal(tersimpan.length, 2);
        assert.equal(tersimpan[0].qty, 2);

        // Komponen baru pada perangkat yang sama = halaman dimuat ulang.
        const lagi = pasang({ bekalAwal: {} });
        assert.equal(lagi.keranjang.length, 2);
        assert.equal(lagi.totalKeranjang, 14000);
        assert.equal(lagi.pembayaran[0].jumlah, 14000, 'nominal bayar ikut menyesuaikan');
    });

    it('mengosongkan simpanan saat keranjang dikosongkan dan setelah dibayar', async () => {
        const k = pasang();

        k.tambah(k.katalog[0]);
        k.kosongkan();
        assert.deepEqual(JSON.parse(simpanan.get('nampan.keranjang')), []);

        k.tambah(k.katalog[0]);
        k.pembayaran[0].diterima = 5000;
        await k.bayar();
        assert.deepEqual(JSON.parse(simpanan.get('nampan.keranjang')), [],
            'keranjang tidak boleh tertinggal setelah transaksi ditutup');
    });

    it('memindahkan ke bill juga mengosongkan simpanan keranjang', () => {
        const k = pasang();
        k.gantiMode('open_bill');
        k.labelBaru = 'Meja 2';
        k.bukaBill();

        k.tambah(k.katalog[0]);
        k.tambahKeBill();

        assert.deepEqual(JSON.parse(simpanan.get('nampan.keranjang')), []);
        assert.equal(JSON.parse(simpanan.get('nampan.bill'))[0].pesanan.length, 1);
    });
});

describe('keadaan kosong panel', () => {
    it('menuntun sesuai penyebab kosongnya', () => {
        const k = pasang();
        k.gantiMode('open_bill');

        // Belum ada bill: yang dibutuhkan tombol buka bill, bukan ajakan pilih produk.
        assert.equal(k.panelTanpaBill, true);
        assert.equal(k.panelKosong, false);

        k.labelBaru = 'Bu Sinta';
        k.bukaBill();
        assert.equal(k.panelTanpaBill, false);
        assert.equal(k.panelKosong, true, 'bill kosong perlu ajakan pilih produk');

        k.tambah(k.katalog[0]);
        assert.equal(k.panelKosong, false);

        k.tambahKeBill();
        assert.equal(k.panelKosong, false, 'bill yang sudah berisi bukan keadaan kosong');

        k.lepasBill();
        assert.equal(k.panelTanpaBill, true);
        assert.equal(k.panelKosong, false);
    });

    it('mode langsung hanya bergantung pada keranjang', () => {
        const k = pasang();

        assert.equal(k.panelTanpaBill, false, 'mode langsung tidak mengenal bill');
        assert.equal(k.panelKosong, true);

        k.tambah(k.katalog[0]);
        assert.equal(k.panelKosong, false);

        k.kosongkan();
        assert.equal(k.panelKosong, true);
    });
});

describe('keterangan bill', () => {
    it('menyebut jumlah item dan jam buka untuk bill meja, bukan status', () => {
        const k = pasang();
        k.gantiMode('open_bill');
        k.labelBaru = 'Meja 4';
        k.bukaBill();

        // Status "Terbuka" tidak dipakai di sini: semua bill meja yang tampil pasti
        // terbuka, jadi kata itu tidak membedakan apa pun.
        let teks = k.ringkasanBill(k.billTerpilih);
        assert.match(teks, /belum ada pesanan/);
        assert.doesNotMatch(teks, /Terbuka/);

        k.tambah(k.katalog[0]);
        k.tambah(k.katalog[0]);
        k.tambah(k.katalog[1]);
        k.tambahKeBill();

        teks = k.ringkasanBill(k.billTerpilih);
        assert.match(teks, /^3 item · sejak \d{2}:\d{2}$/, `dapat: ${teks}`);
    });

    it('menyebut status dan estimasi untuk titipan', () => {
        const k = pasang();
        k.gantiMode('pesan_antar');
        k.labelBaru = 'Pak Rudi';
        k.estimasiBaru = '2026-07-30T15:30';
        k.bukaBill();

        assert.equal(k.ringkasanBill(k.billTerpilih), 'Diterima · selesai 15:30');

        k.majukanStatus(k.billTerpilih);
        assert.match(k.ringkasanBill(k.billTerpilih), /^Diproses/);
    });

    it('menerima nama orang sebagai label bill meja', () => {
        const k = pasang();
        k.gantiMode('open_bill');
        k.labelBaru = 'Bu Sinta';

        assert.equal(k.bukaBill(), true);
        assert.equal(k.billTerpilih.label, 'Bu Sinta');
        assert.equal(k.daftarBill[0].label, 'Bu Sinta');
    });

    it('tidak melempar untuk bill yang tidak ada', () => {
        const k = pasang();

        assert.equal(k.ringkasanBill(null), '');
    });
});

describe('lepas & batalkan bill', () => {
    function billBerisi() {
        const k = pasang();
        k.gantiMode('open_bill');
        k.labelBaru = 'Meja 1';
        k.bukaBill();
        k.tambah(k.katalog[0]);
        k.tambahKeBill();

        return k;
    }

    it('melepas bill tanpa menutupnya, dan tidak menyentuh keranjang', () => {
        const k = billBerisi();

        k.tambah(k.katalog[1]);
        k.pembayaran[0].jumlah = 5000;

        k.lepasBill();

        assert.equal(k.billAktif, null);
        assert.equal(k.billLokal.length, 1, 'bill harus tetap terbuka');
        assert.equal(k.billLokal[0].pesanan.length, 1, 'pesanannya harus utuh');
        assert.equal(k.keranjang.length, 1, 'keranjang tidak boleh ikut dibuang');

        // Angka 5.000 yang diketik untuk tagihan Meja 1 tidak boleh menempel. Barisnya
        // kembali otomatis, jadi nilainya turunan dari tagihan sekarang (keranjang
        // 4.000), bukan sisa ketikan sebelumnya.
        assert.equal(k.pembayaran[0].otomatis, true);
        assert.equal(k.pembayaran[0].jumlah, k.totalTagihan);
        assert.equal(k.pembayaran[0].jumlah, 4000);

        // Masih bisa dilayani lagi lewat daftar.
        k.pilihBill(k.billLokal[0].id);
        assert.equal(k.billTerpilih.label, 'Meja 1');
    });

    it('membatalkan bill beserta pesanannya dan melepas pilihannya', () => {
        const k = billBerisi();
        const id = k.billAktif;

        assert.equal(k.batalkanBill(id), true);

        assert.equal(k.billLokal.length, 0);
        assert.equal(k.billAktif, null);
        assert.equal(k.daftarBill.length, 0);
    });

    it('menolak membatalkan bill yang tidak dikenal', () => {
        const k = billBerisi();

        assert.equal(k.batalkanBill('bukan-id'), false);
        assert.equal(k.billLokal.length, 1);
    });

    it('membuang perubahan status yang masih menunggu saat bill dibatalkan', () => {
        const k = pasang();
        k.gantiMode('pesan_antar');
        k.labelBaru = 'LDY-020';
        k.bukaBill();

        k.majukanStatus(k.billTerpilih);
        assert.equal(k.antrean.length, 1, 'perubahan status menunggu di antrean');

        k.batalkanBill(k.billLokal[0].id);

        // Kalau tertinggal, server akan membuatkan bill yang sudah tidak ada di
        // perangkat mana pun dan tak bisa ditutup siapa pun.
        assert.equal(k.antrean.length, 0);
        assert.equal(JSON.parse(simpanan.get('nampan.antrean')).length, 0);
    });

    it('tidak membuang antrean transaksi milik bill lain', async () => {
        const k = pasang();
        k.online = false;
        k.gantiMode('open_bill');

        k.labelBaru = 'Meja 1';
        k.bukaBill();
        k.tambah(k.katalog[0]);
        k.tambahKeBill();
        k.pembayaran[0].jumlah = 5000;
        k.pembayaran[0].diterima = 5000;
        await k.bayar();

        assert.equal(k.antrean.length, 1);

        k.labelBaru = 'Meja 2';
        k.bukaBill();
        k.batalkanBill(k.billLokal[0].id);

        assert.equal(k.antrean.length, 1, 'pelunasan meja lain harus tetap di antrean');
    });
});

describe('mode pesan_antar', () => {
    it('mencatat estimasi selesai dan memajukan status satu langkah', () => {
        const k = pasang();
        k.gantiMode('pesan_antar');
        k.labelBaru = 'LDY-010 / Pak Budi';
        k.estimasiBaru = '2026-07-30T15:00';
        k.bukaBill();

        const bill = k.billTerpilih;
        assert.equal(bill.estimasi_selesai, '2026-07-30 15:00:00');
        assert.equal(bill.status, 'terbuka');

        k.majukanStatus(bill);
        assert.equal(bill.status, 'diproses');

        k.majukanStatus(bill);
        assert.equal(bill.status, 'siap_diambil');

        // Titipan yang siap diambil hanya bisa maju lewat pelunasan.
        k.majukanStatus(bill);
        assert.equal(bill.status, 'siap_diambil');
    });

    it('menahan perubahan status yang belum disertai transaksi', async () => {
        const k = pasang();
        k.gantiMode('pesan_antar');
        k.labelBaru = 'LDY-011';
        k.bukaBill();

        k.majukanStatus(k.billTerpilih);
        await k.kirimAntrean();

        assert.equal(permintaan.length, 0, 'endpoint mensyaratkan minimal satu transaksi');
        assert.equal(k.antrean.length, 1, 'perubahan status tidak boleh hilang');
    });

    it('mengirim status terakhir bill saat titipan dilunasi', async () => {
        const k = pasang();
        k.gantiMode('pesan_antar');
        k.labelBaru = 'LDY-012';
        k.bukaBill();

        k.tambah(k.katalog[1]);
        k.tambahKeBill();
        k.majukanStatus(k.billTerpilih);

        k.pembayaran[0].jumlah = 4000;
        k.pembayaran[0].diterima = 4000;
        await k.bayar();

        // Dua paket terkirim sekaligus: perubahan status yang tertahan, lalu pelunasan.
        const kirim = permintaan[0].body;
        assert.equal(kirim.bills.length, 2);
        assert.equal(kirim.bills.at(-1).status, 'selesai_dibayar');
        assert.equal(kirim.transactions.length, 1);
    });
});

describe('pemindaian barcode di kasir', () => {
    it('memasukkan barang ke keranjang dari kode yang dipindai', () => {
        const k = pasang();

        assert.equal(k.terimaKode('8991002101234'), true);
        assert.equal(k.keranjang.length, 1);
        assert.equal(k.keranjang[0].nama, 'Nasi Putih');
    });

    it('menambah jumlah ketika barang yang sama dipindai lagi', () => {
        const k = pasang();

        k.terimaKode('8991002101234');
        k.terimaKode('8991002101234');

        assert.equal(k.keranjang.length, 1);
        assert.equal(k.keranjang[0].qty, 2);
    });

    it('memakai SKU sebagai cadangan kalau barcodenya tidak cocok', () => {
        const k = pasang();

        // Huruf kecil: kode buatan sendiri sering diketik apa adanya.
        assert.equal(k.terimaKode('teh-1'), true);
        assert.equal(k.keranjang[0].nama, 'Es Teh');
    });

    it('tidak menyamakan produk hanya karena kolom kodenya sama-sama kosong', () => {
        const k = pasang();

        // Beras tanpa SKU dan Es Teh tanpa barcode: keduanya null, dan null TIDAK
        // boleh dianggap cocok dengan apa pun.
        assert.equal(k.cariKode(''), null);
        assert.equal(k.cariKode('   '), null);
        assert.equal(k.keranjang.length, 0);
    });

    it('menyebut kodenya dan ke mana harus pergi saat barcode belum terdaftar', () => {
        const k = pasang();

        assert.equal(k.terimaKode('9999999999999'), false);
        assert.equal(k.keranjang.length, 0);
        assert.match(k.pesan, /9999999999999/);
        assert.match(k.pesan, /Kelola/);
        assert.equal(k.jenisPesan, 'peringatan');
    });

    it('mengosongkan kolom cari setelah pindaian berhasil', () => {
        const k = pasang();

        k.pencarian = '8991002101234';
        k.pindaiDariPencarian();

        assert.equal(k.pencarian, '');
        assert.equal(k.keranjang[0].nama, 'Nasi Putih');
    });

    it('menambahkan satu-satunya hasil pencarian ketika Enter ditekan', () => {
        const k = pasang();

        k.pencarian = 'es te';
        k.pindaiDariPencarian();

        assert.equal(k.keranjang.length, 1);
        assert.equal(k.keranjang[0].nama, 'Es Teh');
        assert.equal(k.pencarian, '');
    });

    it('diam saja ketika Enter ditekan di tengah mengetik nama barang', () => {
        const k = pasang();

        // 'a' cocok dengan Nasi Putih dan Beras — belum jelas yang mana yang dimaksud.
        k.pencarian = 'a';
        k.pindaiDariPencarian();

        assert.equal(k.keranjang.length, 0);
        assert.ok(! k.pesan, 'tidak boleh mengeluh saat kasir masih mengetik');
        assert.equal(k.pencarian, 'a', 'kata yang sedang diketik tidak boleh dihapus');
    });

    it('memperingatkan ketika deretan angka panjang tidak dikenal', () => {
        const k = pasang();

        k.pencarian = '1234567890123';
        k.pindaiDariPencarian();

        assert.match(k.pesan, /belum terdaftar/);
        assert.equal(k.keranjang.length, 0);
    });

    it('menambah setengah untuk satuan pecahan, sama seperti menekan tilenya', () => {
        const k = pasang();

        k.terimaKode('8991002109999');

        assert.equal(k.keranjang[0].nama, 'Beras');
        assert.equal(k.keranjang[0].qty, 0.5);
    });
    it('menyimpan hasil pindaian terakhir supaya kabarnya tidak hilang', () => {
        const k = pasang();

        k.terimaKode('8991002101234');

        // Panel kamera menutupi layar; pemberitahuan biasa muncul di belakangnya. Keadaan
        // inilah yang ditampilkan DI DALAM panel, dan ia bertahan sampai pindaian
        // berikutnya — bukan hilang setelah beberapa detik.
        assert.deepEqual(k.pindaiTerakhir, {
            nilai: '8991002101234',
            nama: 'Nasi Putih',
            dikenal: true,
        });
    });

    it('menahan kode yang gagal dikenali supaya bisa dicatat', () => {
        const k = pasang();

        k.terimaKode('9999999999999');

        assert.deepEqual(k.pindaiTerakhir, {
            nilai: '9999999999999',
            nama: null,
            dikenal: false,
        });
    });

    it('mengganti hasil terakhir pada pindaian berikutnya, tidak menumpuk', () => {
        const k = pasang();

        k.terimaKode('9999999999999');
        k.terimaKode('8991002101234');

        assert.equal(k.pindaiTerakhir.dikenal, true);
        assert.equal(k.pindaiTerakhir.nilai, '8991002101234');
    });
    it('tidak menoastkan tiap pindaian saat panel kamera terbuka', () => {
        const k = pasang();

        // Panel sudah menampilkan hasilnya sendiri. Sepuluh barang berarti sepuluh toast
        // menumpuk di atas layar yang justru sedang dipakai.
        k.terimaKode('8991002101234', true);

        assert.equal(k.keranjang.length, 1, 'barangnya tetap harus masuk');
        assert.ok(! k.pesan, 'pemberitahuan tidak boleh muncul saat panel terbuka');
        assert.equal(k.pindaiTerakhir.dikenal, true, 'hasilnya tetap dicatat untuk panel');
    });

    it('tetap mencatat kode asing tanpa pemberitahuan saat panel terbuka', () => {
        const k = pasang();

        k.terimaKode('9999999999999', true);

        assert.ok(! k.pesan);
        assert.deepEqual(k.pindaiTerakhir, { nilai: '9999999999999', nama: null, dikenal: false });
    });
});

describe('bunyi pindaian di kasir', () => {
    it('mengucap berhasil ketika barangnya dikenali', () => {
        const k = pasang();

        k.terimaKode('8991002101234');

        // Bawaan: ucapan — kasir tidak perlu menghafal arti dua nada.
        assert.deepEqual(bunyi, ['sukses:ucapan']);
    });

    it('mengucap gagal ketika barcodenya belum terdaftar', () => {
        const k = pasang();

        k.terimaKode('9999999999999');

        assert.deepEqual(bunyi, ['gagal:ucapan']);
    });

    /**
     * Bunyi TETAP keluar walau pemberitahuan di layar ditahan.
     *
     * Justru di keadaan inilah bunyi paling dibutuhkan: panel kamera menutupi layar dan
     * kasir memindai sambil melihat barang, bukan melihat layar.
     */
    it('tetap berbunyi saat panel kamera menutupi layar', () => {
        const k = pasang();

        k.terimaKode('8991002101234', true);
        k.terimaKode('9999999999999', true);

        assert.deepEqual(bunyi, ['sukses:ucapan', 'gagal:ucapan']);
        assert.ok(! k.pesan, 'pemberitahuan di layar tetap ditahan');
    });

    it('diam sepenuhnya ketika suaranya dimatikan', () => {
        const k = pasang();

        k.suara = 'mati';
        k.terimaKode('8991002101234');
        k.terimaKode('9999999999999');

        assert.deepEqual(bunyi, []);
        assert.equal(k.keranjang.length, 1, 'mematikan bunyi tidak boleh mengubah transaksinya');
    });

    it('berputar ucapan → bip → mati → ucapan', () => {
        const k = pasang();

        assert.equal(k.suara, 'ucapan');

        k.gantiSuara();
        assert.equal(k.suara, 'bip');

        k.gantiSuara();
        assert.equal(k.suara, 'mati');

        k.gantiSuara();
        assert.equal(k.suara, 'ucapan');
    });

    it('memakai bip ketika modenya bip', () => {
        const k = pasang();

        k.suara = 'bip';
        k.terimaKode('8991002101234');

        assert.deepEqual(bunyi, ['sukses:bip']);
    });

    it('mengingat pilihan suara di perangkat', () => {
        const k = pasang();

        k.gantiSuara();

        assert.equal(simpanan.get('nampan.suara'), '"bip"');

        // Muat ulang halaman: pilihannya harus tetap.
        assert.equal(pasang().suara, 'bip');
    });

    /**
     * Pilihan lama masih berbentuk true/false — versi sebelum mode ucapan ada. Kasir yang
     * sudah mematikan suaranya tidak boleh tiba-tiba berbunyi lagi hanya karena
     * aplikasinya diperbarui.
     */
    it('menghormati pilihan lama yang masih berbentuk true/false', () => {
        simpanan.set('nampan.suara', 'false');
        assert.equal(pasang().suara, 'mati');

        simpanan.set('nampan.suara', 'true');
        assert.equal(pasang().suara, 'ucapan');
    });

    it('mencontohkan suaranya begitu modenya dipilih', () => {
        const k = pasang();

        bunyi.length = 0;
        k.gantiSuara();

        // Supaya kasir tahu seperti apa suaranya dan seberapa kencang — bukan
        // menemukannya di pindaian pertama di depan pembeli.
        assert.deepEqual(bunyi, ['sukses:bip']);
    });

    it('tidak mencontohkan apa pun saat dipilih mati', () => {
        const k = pasang();

        k.suara = 'bip';
        bunyi.length = 0;
        k.gantiSuara();

        assert.equal(k.suara, 'mati');
        assert.deepEqual(bunyi, []);
    });

    it('tidak berbunyi ketika Enter ditekan di tengah mengetik nama barang', () => {
        const k = pasang();

        k.pencarian = 'a';
        k.pindaiDariPencarian();

        assert.deepEqual(bunyi, [], 'tidak ada yang masuk keranjang, jadi tidak ada yang dibunyikan');
    });

    it('berbunyi berhasil ketika Enter memasukkan satu-satunya hasil pencarian', () => {
        const k = pasang();

        k.pencarian = 'es te';
        k.pindaiDariPencarian();

        assert.deepEqual(bunyi, ['sukses:ucapan']);
    });
});

describe('format angka uang', () => {
    it('membaca angka dari teks berformat rupiah', () => {
        const k = pasang();

        assert.equal(k.angkaDari('21.000'), 21000);
        assert.equal(k.angkaDari('Rp 50.000'), 50000);
        assert.equal(k.angkaDari(''), 0);
        assert.equal(k.angkaDari('abc'), 0);
        // Titik yang kita sendiri tambahkan tidak boleh terbaca sebagai desimal.
        assert.equal(k.angkaDari('1.5'), 15);
    });

    it('menampilkan rupiah dengan pemisah ribuan', () => {
        const k = pasang();

        assert.equal(k.rupiah(21000), '21.000');
        assert.equal(k.rupiah(0), '0');
    });

    it('bolak-balik antara tampilan dan nilai tanpa berubah', () => {
        const k = pasang();

        for (const n of [0, 500, 21000, 1250000]) {
            assert.equal(k.angkaDari(k.rupiah(n)), n, `nilai berubah pada ${n}`);
        }
    });
});

describe('gambar default produk', () => {
    it('menyusun inisial dari nama produk', () => {
        const k = pasang();

        assert.equal(k.inisial('Air Mineral 600ml'), 'AM');
        assert.equal(k.inisial('Kerupuk'), 'KE');
        assert.equal(k.inisial('  Es   Teh Manis '), 'ET');
        assert.equal(k.inisial('N'), 'N');
        assert.equal(k.inisial('---'), '?', 'nama tanpa huruf tetap harus punya tile');
    });

    it('memberi warna yang sama untuk nama yang sama', () => {
        const k = pasang();

        // Warna wajib tetap antar pemuatan; kalau berubah-ubah, hafalan kasir rusak.
        assert.equal(k.warnaProduk('Nasi Putih'), k.warnaProduk('Nasi Putih'));
        assert.notEqual(k.warnaProduk('Nasi Putih'), '');
    });

    it('memakai kelas dari palet yang dipindai Tailwind', () => {
        const k = pasang();
        const sah = [
            'bg-brand-50 text-brand-600',
            'bg-navy-50 text-navy-700',
            'bg-hijau/12 text-hijau-tua',
            'bg-jingga/15 text-jingga-tua',
            'bg-gray-100 text-gray-800',
            'bg-brand-100 text-brand-700',
        ];

        for (const nama of ['Nasi Putih', 'Es Teh', 'Beras', 'Kerupuk', 'Ayam Goreng', 'Telur']) {
            assert.ok(sah.includes(k.warnaProduk(nama)), `warna tak dikenal untuk ${nama}`);
        }
    });
});

describe('struk', () => {
    it('menyusun struk dari transaksi yang baru dibayar', async () => {
        const k = pasang();

        k.tambah(k.katalog[0]);
        k.pembayaran[0].jumlah = 5000;
        k.pembayaran[0].diterima = 20000;
        await k.bayar();

        assert.ok(k.strukTerakhir);
        assert.equal(k.strukTerakhir.total, 5000);
        assert.equal(k.strukTerakhir.kembalian, 15000);
        assert.equal(k.strukTerakhir.items[0].nama_produk, 'Nasi Putih');
        assert.match(k.strukTerakhir.nomor, /^TRX-/);
    });
});

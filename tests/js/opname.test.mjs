/**
 * Pengujian baris lembar opname (resources/js/opname.js).
 *
 * Selisih fisik − sistem dihitung di klien supaya pemilik tidak menunggu jaringan tiap
 * ketikan. Konsekuensinya: rumusnya tidak lagi dilindungi PHPUnit, dan setiap salah tanda
 * atau salah pembulatan di sini langsung menjadi angka salah di layar orang yang sedang
 * menghitung 200 barang. Berkas ini yang menjaganya.
 *
 * Dijalankan dengan: npm run uji:js
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

/** Menyalakan modulnya dengan Alpine palsu, lalu mengembalikan pabrik komponennya. */
async function pabrik() {
    let daftar = null;

    global.window = {
        Alpine: { data: (_nama, factory) => { daftar = factory; } },
    };

    global.document = {
        addEventListener: (nama, f) => { if (nama === 'alpine:init') f(); },
    };

    const modul = await import('../../resources/js/opname.js?t=' + Math.random());
    modul.pasangOpname();

    assert.ok(daftar, 'komponen barisOpname tidak terdaftar');

    return daftar;
}

async function baris(fisik = '', sistem = 0, alasan = '') {
    return (await pabrik())(fisik, sistem, alasan);
}

describe('baris opname', () => {
    it('kolom kosong berarti BELUM DIHITUNG, bukan nol', async () => {
        // Ini pembedaan terpenting di seluruh layar stok: nol adalah pernyataan bahwa
        // raknya kosong. Kalau kolom kosong dihitung sebagai 0, membuka lembar lalu
        // menyimpannya akan mengarang selisih sebesar seluruh stok yang tidak dihitung.
        const b = await baris('', 12);

        assert.equal(b.terisi, false);
        assert.equal(b.beda, null);
        assert.equal(b.teksBeda, '—');
        assert.equal(b.selisih, false);
    });

    it('nol dianggap terisi dan menghasilkan selisih penuh', async () => {
        const b = await baris('0', 12);

        assert.equal(b.terisi, true);
        assert.equal(b.beda, -12);
        assert.equal(b.selisih, true);
        assert.equal(b.teksBeda, '-12');
    });

    it('fisik sama dengan sistem bukan selisih — barisnya tidak boleh ditandai', async () => {
        const b = await baris('12', 12);

        assert.equal(b.beda, 0);
        assert.equal(b.selisih, false, 'baris tanpa selisih tidak boleh diwarnai');
        assert.equal(b.teksBeda, '0');
    });

    it('selisih lebih diberi tanda + supaya tidak terbaca sebagai kekurangan', async () => {
        const b = await baris('15', 12);

        assert.equal(b.beda, 3);
        assert.equal(b.teksBeda, '+3');
    });

    it('desimal dibulatkan ke ketelitian stok (3 angka), bukan dibiarkan mengapung', async () => {
        // 0.3 - 0.1 di JS menghasilkan 0.19999999999999998. Tanpa pembulatan, kolom
        // selisih memperlihatkan angka seperti itu kepada pemilik warung.
        const b = await baris('0.3', 0.1);

        assert.equal(b.beda, 0.2);
    });

    it('koma diubah jadi titik saat diketik — validasi server menolak koma', async () => {
        const b = await baris('', 1);
        const el = { value: '1,5' };

        b.ketik(el);

        assert.equal(el.value, '1.5', 'nilai di kolom harus ternormalisasi');
        assert.equal(b.fisik, '1.5');
        assert.equal(b.beda, 0.5);
    });

    it('nilai tanpa koma TIDAK ditulis ulang, supaya kursor tidak melompat', async () => {
        const b = await baris('', 1);
        let ditulis = 0;
        const el = {
            _v: '25',
            get value() { return this._v; },
            set value(v) { ditulis++; this._v = v; },
        };

        b.ketik(el);

        assert.equal(ditulis, 0, 'kolom tanpa koma tidak boleh ditimpa');
        assert.equal(b.fisik, '25');
    });

    it('isian yang bukan angka tidak memalsukan selisih', async () => {
        const b = await baris('dua', 5);

        assert.equal(b.beda, null);
        assert.equal(b.teksBeda, '—');
    });

    it('catatan hanya diminta untuk alasan "lainnya"', async () => {
        assert.equal((await baris('1', 1, 'lainnya')).butuhCatatan, true);
        assert.equal((await baris('1', 1, 'rusak')).butuhCatatan, false);
        assert.equal((await baris('1', 1, '')).butuhCatatan, false);
    });
});

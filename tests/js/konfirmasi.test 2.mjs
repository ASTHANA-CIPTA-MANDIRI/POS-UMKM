/**
 * Pembungkus dialog konfirmasi bersama (resources/js/toast.js → konfirmasiNampan).
 *
 * Kenapa ini diuji: dialognya dipakai untuk tindakan MERUSAK, dan tiga hal di dalamnya
 * mudah hilang tanpa terlihat di layar mana pun sampai seseorang menekan tombol yang salah.
 *
 *  - `buttonsStyling: false` — tanpa itu gaya bawaan SweetAlert menang atas `.tombol-bahaya`
 *    (kekhususannya lebih tinggi), jadi tombol pembenar untuk "hapus" keluar BIRU. Merah yang
 *    hilang di satu tempat membuat "merah = merusak" berhenti terbaca sebagai aturan.
 *  - `focusCancel` — yang mendapat fokus awal harus tombol BATAL. Kalau tombol hapusnya yang
 *    terfokus, satu Enter yang tertekan sambil lalu langsung menghapus.
 *  - hasil `false` untuk apa pun selain penekanan tombol pembenar (Esc, klik latar, tombol
 *    batal). Kalau ia mengembalikan sesuatu yang truthy, aksinya tetap jalan padahal orangnya
 *    baru saja menolak.
 *
 * SweetAlert-nya dipalsukan di tingkat `Swal.fire` — bukan dijalankan sungguhan — karena yang
 * diuji di sini adalah PARAMETER yang dikirim pembungkusnya, dan itu justru bagian yang bisa
 * salah tanpa DOM.
 *
 * Dijalankan dengan: npm run uji:js
 */

import assert from 'node:assert/strict';
import { describe, it } from 'node:test';

import Swal from 'sweetalert2';
import { konfirmasiNampan } from '../../resources/js/toast.js';

/** Menyadap Swal.fire, menjalankan pembungkusnya, lalu mengembalikan opsi + hasilnya. */
async function panggil(hasilDialog, opsiPemanggil = { judul: 'Hapus Kopi Sachet?' }) {
    let opsi = null;

    const asli = Swal.fire;
    Swal.fire = (diterima) => {
        opsi = diterima;

        return Promise.resolve(hasilDialog);
    };

    try {
        const jawaban = await konfirmasiNampan(opsiPemanggil);

        return { opsi, jawaban };
    } finally {
        Swal.fire = asli;
    }
}

describe('konfirmasiNampan', () => {
    it('tombol pembenarnya memakai kelas bahaya bersama, bukan gaya bawaan SweetAlert', async () => {
        const { opsi } = await panggil({ isConfirmed: true });

        assert.equal(opsi.buttonsStyling, false,
            'gaya bawaan SweetAlert menang atas .tombol-bahaya kalau ini dibiarkan hidup');
        assert.match(opsi.customClass.confirmButton, /\btombol-bahaya\b/);
        assert.match(opsi.customClass.cancelButton, /\btombol-kedua\b/);
    });

    it('kedua tombolnya setinggi minimal 44px — dipakai di tablet dan HP', async () => {
        const { opsi } = await panggil({ isConfirmed: true });

        // min-h-11 = 2.75rem = 44px, sasaran sentuh minimum yang dipakai seluruh aplikasi.
        assert.match(opsi.customClass.confirmButton, /\bmin-h-11\b/);
        assert.match(opsi.customClass.cancelButton, /\bmin-h-11\b/);
    });

    it('fokus awal jatuh ke tombol batal, dan Esc tetap bisa menutup', async () => {
        const { opsi } = await panggil({ isConfirmed: true });

        assert.equal(opsi.focusCancel, true);
        assert.equal(opsi.allowEscapeKey, true);
    });

    it('teksnya datang dari pemanggil, termasuk nama benda yang dihapus', async () => {
        const { opsi } = await panggil({ isConfirmed: true }, {
            judul: 'Hapus foto struk nota PB-20260806-001?',
            pesan: 'Notanya tetap tersimpan.',
            tombolYa: 'Ya, hapus fotonya',
            tombolBatal: 'Tidak jadi',
        });

        // Judul yang tidak menyebut APA yang dihapus membuat orang menekan "Ya" untuk benda
        // yang salah — karena itu judulnya tidak punya nilai bawaan yang samar.
        assert.equal(opsi.title, 'Hapus foto struk nota PB-20260806-001?');
        assert.equal(opsi.text, 'Notanya tetap tersimpan.');
        assert.equal(opsi.confirmButtonText, 'Ya, hapus fotonya');
        assert.equal(opsi.cancelButtonText, 'Tidak jadi');
    });

    it('hanya penekanan tombol pembenar yang berarti "ya"', async () => {
        assert.equal((await panggil({ isConfirmed: true })).jawaban, true);

        // Batal, Esc, dan klik latar semuanya bukan persetujuan.
        assert.equal((await panggil({ isConfirmed: false, dismiss: 'cancel' })).jawaban, false);
        assert.equal((await panggil({ isConfirmed: false, dismiss: 'esc' })).jawaban, false);
        assert.equal((await panggil({ isDismissed: true })).jawaban, false);
        assert.equal((await panggil(undefined)).jawaban, false);
    });
});

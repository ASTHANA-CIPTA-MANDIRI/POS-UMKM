import Swal from 'sweetalert2';

/**
 * Satu-satunya sumber toast di seluruh aplikasi.
 *
 * SweetAlert2 diimpor dari npm dan ikut dibundel, BUKAN dari CDN. Layar kasir wajib
 * jalan saat jaringan mati; toast yang menunggu berkas dari internet akan diam persis
 * di saat kasir paling butuh tahu bahwa transaksinya tersimpan.
 *
 * Dipasang sekali di window supaya bisa dipanggil dari mana saja — Livewire, Alpine,
 * maupun skrip biasa — tanpa tiap tempat menyusun konfigurasinya sendiri lalu
 * menghasilkan tampilan yang berbeda-beda.
 */

const PETA_IKON = {
    sukses: 'success',
    galat: 'error',
    peringatan: 'warning',
    offline: 'warning',
    info: 'info',
};

const toast = Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    // 4 detik: cukup dibaca sambil melayani, tapi tidak menghalangi layar terlalu lama.
    timer: 4000,
    timerProgressBar: true,
    // Bertumpuk ke bawah kalau beberapa aksi berurutan, tidak saling menimpa.
    showClass: { popup: 'swal-masuk' },
    hideClass: { popup: 'swal-keluar' },
    customClass: {
        popup: 'toast-nampan',
        title: 'toast-nampan-judul',
        timerProgressBar: 'toast-nampan-bilah',
    },
    didOpen: (el) => {
        el.addEventListener('mouseenter', Swal.stopTimer);
        el.addEventListener('mouseleave', Swal.resumeTimer);
    },
});

/**
 * @param {string} pesan
 * @param {'sukses'|'galat'|'peringatan'|'offline'|'info'} jenis
 */
export function tampilkanToast(pesan, jenis = 'info') {
    if (! pesan) {
        return;
    }

    toast.fire({ icon: PETA_IKON[jenis] ?? 'info', title: pesan });
}

/**
 * Dialog konfirmasi BERSAMA untuk tindakan merusak (hapus, buang, void).
 *
 * Kenapa satu pembungkus dan bukan `Swal.fire` mentah di tiap layar: kalau setiap layar
 * menyusun dialognya sendiri, teksnya, warna tombolnya, dan URUTAN tombolnya akan
 * bercabang — dan "tombol kanan berarti melanjutkan" berhenti bisa dipelajari. Satu-satunya
 * yang berbeda antar-pemanggil adalah kalimatnya.
 *
 * Empat hal yang ditetapkan di sini, semuanya dari aturan CLAUDE.md:
 *  - `buttonsStyling: false` supaya kelas bersama (`.tombol-bahaya`, `.tombol-kedua`)
 *    benar-benar terpakai; gaya bawaan SweetAlert punya kekhususan lebih tinggi dan akan
 *    menang, sehingga tombol pembenarnya keluar biru — bukan merah.
 *  - `min-h-11` (44px) di kedua tombol: sasaran sentuh di tablet & HP.
 *  - `focusCancel` — yang mendapat fokus awal adalah tombol BATAL, bukan tombol hapusnya,
 *    jadi Enter yang tertekan sambil lalu tidak menghapus apa pun.
 *  - Esc menutup dialog (bawaan SweetAlert, dibiarkan hidup dengan sengaja).
 *
 * Dialognya BUKAN pengaman: ia hanya mencegah salah-tekan. Wewenang tetap diperiksa server
 * pada setiap aksi — muatan Livewire bisa dikirim tanpa pernah melewati dialog apa pun.
 *
 * @param {{judul: string, pesan?: string, tombolYa?: string, tombolBatal?: string}} opsi
 * @returns {Promise<boolean>} true kalau pemakainya benar-benar menekan tombol pembenarnya.
 */
export function konfirmasiNampan({
    judul,
    pesan = '',
    tombolYa = 'Ya, lanjutkan',
    tombolBatal = 'Tidak jadi',
} = {}) {
    return Swal.fire({
        title: judul,
        text: pesan,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonText: tombolYa,
        cancelButtonText: tombolBatal,
        focusCancel: true,
        allowEscapeKey: true,
        buttonsStyling: false,
        customClass: {
            popup: 'konfirmasi-nampan',
            confirmButton: 'tombol-bahaya mx-1 min-h-11 cursor-pointer px-5 text-[0.875rem]',
            cancelButton: 'tombol-kedua mx-1 min-h-11 cursor-pointer px-5 text-[0.875rem]',
        },
    }).then((hasil) => hasil?.isConfirmed === true);
}

export function pasangToast() {
    window.toastNampan = tampilkanToast;
    // Dipasang di window supaya bisa dipanggil dari Alpine di Blade tanpa tiap layar
    // mengimpor SweetAlert sendiri.
    window.konfirmasiNampan = konfirmasiNampan;

    /*
     * Livewire mengirim toast lewat event peramban, bukan lewat session flash.
     *
     * Flash message menuntut halaman dimuat ulang untuk terlihat; dengan Livewire yang
     * memperbarui sebagian halaman, pesannya bisa muncul terlambat atau tidak muncul
     * sama sekali. Event dikirim tepat saat aksinya selesai.
     */
    window.addEventListener('toast', (peristiwa) => {
        const { pesan, jenis } = peristiwa.detail ?? {};
        tampilkanToast(pesan, jenis);
    });
}

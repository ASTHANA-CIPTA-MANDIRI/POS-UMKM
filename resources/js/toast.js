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

export function pasangToast() {
    window.toastNampan = tampilkanToast;

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

import { pasangBukti } from './bukti';
import { pasangBunyi } from './bunyi';
import { pasangEchoKeWindow } from './echo';
import { pasangKamera } from './kamera';
import { pasangKasir } from './kasir';
import { pasangToast } from './toast';
import { mulaiAnimasi } from './motion';
import { pasangNavbar } from './nav';
import { pasangOpname } from './opname';
import { pasangPemindai } from './pemindai';
import { pasangAngkaNaik, pasangScene3D } from './scene3d';
import { pasangTerminal3D } from './terminal3d';
import { pasangUang } from './uang';

// Harus didaftarkan SEBELUM Alpine init, karena memakai hook alpine:init.
pasangKasir();
pasangPemindai();
pasangOpname();
pasangBukti();
pasangUang();
pasangKamera();

// Dipasang di luar mulai(): toast bisa dikirim Livewire sebelum DOMContentLoaded,
// dan pendengarnya harus sudah ada saat event pertama tiba.
pasangToast();
pasangBunyi();

/*
 * Hanya MENDAFTARKAN window.pasangEcho — TIDAK menyambung.
 *
 * Koneksinya baru terbuka kalau ada layar yang benar-benar memanggilnya, dan pusher-js
 * ada di chunk terpisah sehingga layar kasir tidak pernah mengunduhnya. Aturan keras
 * nomor 3: layar kasir tidak boleh bergantung jaringan.
 */
pasangEchoKeWindow();

function mulai() {
    pasangNavbar();
    pasangScene3D();
    pasangTerminal3D();
    pasangAngkaNaik();
    mulaiAnimasi();
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', mulai);
} else {
    mulai();
}

// Livewire mengganti sebagian DOM tanpa memuat ulang halaman, jadi helper yang
// idempoten dipasang ulang setiap kali komponen selesai dirender.
document.addEventListener('livewire:navigated', mulaiAnimasi);
document.addEventListener('livewire:initialized', () => {
    window.Livewire?.hook('morph.added', () => mulaiAnimasi());
});

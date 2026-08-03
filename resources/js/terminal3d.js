/**
 * Animasi terminal kasir 3D di hero.
 *
 * Pembagian tugas transform dijaga ketat supaya tidak saling menimpa:
 *   [data-term-spin] -> rotateY, dipegang motion (goyangan bolak-balik)
 *   [data-term-tilt] -> rotateX + translateY, ditulis callback scroll
 *
 * Kalau keduanya digarap pada satu elemen, animasi goyangan akan menghapus
 * kemiringan setiap frame.
 */
import { animate, scroll } from 'motion';

function gerakDikurangi() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

export function pasangTerminal3D() {
    const scene = document.querySelector('[data-term-scene]');

    if (!scene) {
        return;
    }

    const spin = scene.querySelector('[data-term-spin]');
    const tilt = scene.querySelector('[data-term-tilt]');

    if (!spin || !tilt) {
        return;
    }

    // Gerak minimal: terminal tetap tampil miring dari CSS, tanpa bergoyang.
    if (gerakDikurangi()) {
        return;
    }

    /*
     * Goyangan bolak-balik, bukan putaran penuh. repeatType 'mirror' membuat arah
     * berbalik di ujung sehingga tidak ada lompatan, dan easeInOut memberi jeda
     * sesaat di titik balik — tanpa itu gerakannya terasa seperti metronom.
     */
    animate(spin, { rotateY: [-19, 19] }, {
        duration: 9,
        repeat: Infinity,
        repeatType: 'mirror',
        ease: 'easeInOut',
    });

    /*
     * Animasi 3D saat scroll: sudut pandang berangsur turun dari melihat layar
     * dari atas menjadi hampir sejajar mata, sambil bergerak lebih lambat dari
     * halaman. Nilainya ditulis 1:1 terhadap progres scroll, bukan dianimasikan
     * berdurasi — gerak yang tertinggal dari jari selalu terasa salah.
     */
    scroll(
        (progress) => {
            const rotasiX = 17 - progress * 20;
            const geser = (progress - 0.5) * -34;

            tilt.style.transform = `rotateX(${rotasiX}deg) translateY(${geser}px)`;
        },
        { target: scene, offset: ['start end', 'end start'] },
    );
}

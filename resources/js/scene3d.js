/**
 * Panggung 3D untuk hero landing page.
 *
 * Ini 3D CSS asli, bukan WebGL: wadah luar memberi `perspective`, panggung memakai
 * `transform-style: preserve-3d`, dan tiap lapisan didorong ke kedalaman berbeda
 * lewat translateZ. Konsekuensi yang dimanfaatkan di sini: memutar panggung satu
 * kali otomatis menghasilkan parallax pada semua lapisan sesuai kedalamannya —
 * jadi tidak perlu menganimasikan pergeseran tiap lapisan secara manual.
 *
 * Pembagian tugas transform, supaya tidak saling menimpa:
 *   panggung  -> rotateX/rotateY via custom property (--stage-rx/--stage-ry)
 *   lapisan   -> z, y, opacity dipegang sepenuhnya oleh motion
 */
import { animate, motionValue } from 'motion';

function gerakDikurangi() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/** Parallax kursor hanya relevan untuk penunjuk presisi; layar sentuh dilewati. */
function penunjukPresisi() {
    return window.matchMedia('(pointer: fine)').matches;
}

/**
 * Tempatkan lapisan pada kedalamannya lalu munculkan berurutan dari belakang ke
 * depan, sehingga terbaca sebagai satu ruang yang tersusun — bukan sekadar
 * beberapa kartu yang muncul bersamaan.
 */
function munculkanLapisan(lapisan) {
    const instan = gerakDikurangi();

    lapisan
        .map((el) => ({ el, z: Number(el.dataset.depth ?? 0) }))
        .sort((a, b) => a.z - b.z)
        .forEach(({ el, z }, i) => {
            if (instan) {
                animate(el, { opacity: 1, y: 0, z, scale: 1 }, { duration: 0 });

                return;
            }

            animate(
                el,
                { opacity: [0, 1], y: [26, 0], z, scale: [0.94, 1] },
                { duration: 0.75, delay: 0.12 + i * 0.09, ease: [0.16, 1, 0.3, 1] },
            );
        });
}

/**
 * Ambang gerak dibuat proporsional terhadap kedalaman: lapisan yang lebih dekat
 * mengapung lebih jauh. Fase awal tiap lapisan digeser agar tidak bergerak serempak
 * seperti metronom.
 */
function apungkanLapisan(lapisan) {
    if (gerakDikurangi()) {
        return;
    }

    lapisan.forEach((el, i) => {
        const z = Number(el.dataset.depth ?? 0);
        const jarak = 4 + Math.abs(z) / 26;

        animate(
            el,
            { y: [0, -jarak, 0] },
            {
                duration: 6.5 + i * 0.85,
                delay: 0.9 + i * 0.35,
                repeat: Infinity,
                ease: 'easeInOut',
            },
        );
    });
}

/**
 * Panggung mengikuti kursor dengan spring, bukan mengunci 1:1 ke posisi pointer —
 * gerakan yang mengunci terasa mekanis dan membuat pusing pada sudut lebar.
 * Sudutnya sengaja kecil (maks 7°) supaya terbaca sebagai kedalaman, bukan atraksi.
 */
function pasangParallaxKursor(scene, stage) {
    if (gerakDikurangi() || !penunjukPresisi()) {
        return;
    }

    const rx = motionValue(0);
    const ry = motionValue(0);

    rx.on('change', (v) => stage.style.setProperty('--stage-rx', `${v}deg`));
    ry.on('change', (v) => stage.style.setProperty('--stage-ry', `${v}deg`));

    const pegas = { type: 'spring', stiffness: 55, damping: 18, mass: 0.6 };

    scene.addEventListener('pointermove', (event) => {
        const kotak = scene.getBoundingClientRect();
        const nx = (event.clientX - kotak.left) / kotak.width - 0.5;
        const ny = (event.clientY - kotak.top) / kotak.height - 0.5;

        animate(rx, -ny * 7, pegas);
        animate(ry, nx * 9, pegas);
    });

    scene.addEventListener('pointerleave', () => {
        animate(rx, 0, pegas);
        animate(ry, 0, pegas);
    });
}

export function pasangScene3D() {
    const scene = document.querySelector('[data-scene]');
    const stage = scene?.querySelector('[data-stage]');

    if (!scene || !stage) {
        return;
    }

    const lapisan = Array.from(stage.querySelectorAll('[data-layer]'));

    if (lapisan.length === 0) {
        return;
    }

    munculkanLapisan(lapisan);
    apungkanLapisan(lapisan);
    pasangParallaxKursor(scene, stage);
}

/**
 * Angka statistik dihitung naik saat pertama terlihat. Diformat lokal id-ID supaya
 * pemisah ribuan memakai titik, bukan koma.
 */
export function pasangAngkaNaik() {
    const elemen = Array.from(document.querySelectorAll('[data-hitung]'));

    if (elemen.length === 0) {
        return;
    }

    const formatter = new Intl.NumberFormat('id-ID');

    const jalankan = (el) => {
        const target = Number(el.dataset.hitung);

        if (Number.isNaN(target)) {
            return;
        }

        if (gerakDikurangi()) {
            el.textContent = formatter.format(target);

            return;
        }

        const nilai = motionValue(0);
        nilai.on('change', (v) => {
            el.textContent = formatter.format(Math.round(v));
        });

        animate(nilai, target, { duration: 1.4, ease: [0.16, 1, 0.3, 1] });
    };

    const pengamat = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    jalankan(entry.target);
                    pengamat.unobserve(entry.target);
                }
            });
        },
        { threshold: 0.4 },
    );

    elemen.forEach((el) => pengamat.observe(el));
}

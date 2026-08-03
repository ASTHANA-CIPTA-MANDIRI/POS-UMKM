/**
 * Helper animasi berbasis paket `motion` (entry vanilla, bukan `motion/react`).
 *
 * Semua helper digerakkan atribut data supaya bisa dipakai langsung dari Blade
 * tanpa React: cukup tambahkan atributnya di markup, tidak ada komponen JS yang
 * perlu di-mount.
 *
 * Aman dipanggil ulang setelah Livewire mengganti DOM — tiap elemen ditandai
 * supaya tidak dipasangi listener dua kali.
 */
import { animate, inView, press, stagger } from 'motion';

const SUDAH_DIPASANG = 'data-motion-siap';

/** Hormati preferensi sistem: pengguna yang minta gerak minimal tidak dianimasikan. */
function gerakDikurangi() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/** @return {HTMLElement[]} elemen yang cocok dan belum pernah dipasangi. */
function elemenBaru(selector) {
    return Array.from(document.querySelectorAll(selector)).filter(
        (el) => !el.hasAttribute(SUDAH_DIPASANG),
    );
}

function tandai(el) {
    el.setAttribute(SUDAH_DIPASANG, '');
}

/**
 * Umpan balik tekan untuk grid tombol kasir.
 *
 * Kasir warung menekan tombol besar berulang kali dengan cepat, dan tanpa respons
 * visual mereka tidak yakin tap-nya terdaftar — ini penyebab umum item terhitung
 * dobel. Pakai: <button data-tap>Ayam Goreng</button>
 */
export function pasangUmpanBalikTekan() {
    elemenBaru('[data-tap]').forEach((el) => {
        tandai(el);

        press(el, () => {
            if (gerakDikurangi()) {
                return;
            }

            animate(el, { scale: 0.96 }, { duration: 0.08 });

            // Callback yang dikembalikan dijalankan saat tekanan dilepas.
            return () => animate(el, { scale: 1 }, { type: 'spring', stiffness: 500, damping: 25 });
        });
    });
}

/**
 * Munculkan elemen saat masuk viewport, sekali saja.
 * Pakai: <div data-reveal> atau <div data-reveal="0.3"> (delay detik).
 *
 * Elemen yang SUDAH terlihat saat pemasangan tidak disembunyikan sama sekali —
 * kalau tidak, membuka tautan langsung ke anchor (mis. /#harga) membuat section
 * tujuan berkedip kosong lebih dulu.
 *
 * Sengaja TIDAK ada batas waktu yang memunculkan paksa setelah beberapa detik.
 * Pengaman semacam itu ikut memunculkan elemen yang masih jauh di bawah layar,
 * sehingga animasi saat digulir tidak pernah benar-benar terjadi — yang muncul
 * hanyalah timer. IntersectionObserver sendiri sudah memicu callback untuk elemen
 * yang sudah bersinggungan saat mulai diamati, jadi kasus anchor tetap aman.
 */
export function pasangRevealSaatTerlihat() {
    elemenBaru('[data-reveal]').forEach((el) => {
        tandai(el);

        const munculkan = () => {
            const delay = parseFloat(el.dataset.reveal) || 0;

            animate(
                el,
                { opacity: [0, 1], transform: ['translateY(12px)', 'translateY(0px)'] },
                { duration: 0.35, delay },
            );
        };

        if (gerakDikurangi()) {
            return;
        }

        const kotak = el.getBoundingClientRect();
        const sudahTerlihat = kotak.top < window.innerHeight && kotak.bottom > 0;

        if (sudahTerlihat) {
            munculkan();

            return;
        }

        el.style.opacity = '0';

        inView(el, munculkan, { amount: 0.2 });
    });
}

/**
 * Animasikan anak-anak sebuah wadah secara berurutan — dipakai untuk daftar bill
 * terbuka dan grid menu supaya perpindahan halaman tidak terasa menyentak.
 * Pakai: <ul data-stagger><li>...</li></ul>
 */
export function pasangStaggerDaftar() {
    elemenBaru('[data-stagger]').forEach((wadah) => {
        tandai(wadah);

        const anak = Array.from(wadah.children);

        if (anak.length === 0 || gerakDikurangi()) {
            return;
        }

        animate(
            anak,
            { opacity: [0, 1], transform: ['translateY(8px)', 'translateY(0px)'] },
            { duration: 0.25, delay: stagger(0.04) },
        );
    });
}

/**
 * Kartu masuk dengan rotasi 3D saat digulir ke dalam viewport: berputar dari
 * mendongak ke tegak sekaligus datang dari kedalaman.
 *
 * Perspektif diberikan per elemen lewat `transformPerspective`, bukan lewat properti
 * `perspective` di wadah. Kalau perspektif dipasang di grid, semua kartu berbagi satu
 * titik hilang di tengah wadah — kartu di kolom tepi jadi terlihat menceng.
 *
 * Pakai: <article data-tilt-in>…</article>
 */
export function pasangTiltMasuk() {
    elemenBaru('[data-tilt-in]').forEach((el) => {
        tandai(el);

        if (gerakDikurangi()) {
            return;
        }

        const munculkan = () => {
            animate(
                el,
                {
                    transformPerspective: 1100,
                    opacity: [0, 1],
                    rotateX: [-16, 0],
                    y: [34, 0],
                    z: [-70, 0],
                },
                { duration: 0.65, ease: [0.16, 1, 0.3, 1] },
            );
        };

        const kotak = el.getBoundingClientRect();

        // Sama seperti data-reveal: yang sudah terlihat tidak disembunyikan dulu,
        // supaya mendarat langsung di anchor tidak meninggalkan kartu kosong.
        if (kotak.top < window.innerHeight && kotak.bottom > 0) {
            munculkan();

            return;
        }

        el.style.opacity = '0';

        inView(el, munculkan, { amount: 0.15 });
    });
}

/** Pasang semua helper. Idempoten, jadi boleh dipanggil berkali-kali. */
export function mulaiAnimasi() {
    pasangUmpanBalikTekan();
    pasangRevealSaatTerlihat();
    pasangStaggerDaftar();
    pasangTiltMasuk();
}

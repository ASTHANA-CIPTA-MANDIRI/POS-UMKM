/**
 * Perilaku navbar mengapung: reaksi terhadap scroll, dropdown, dan panel mobile.
 *
 * Dropdown dan panel dibuka dengan KLIK, bukan hover. Hover-only tidak bisa dipakai
 * di layar sentuh sama sekali, dan itu salah satu penyebab paling umum menu tidak
 * bisa diakses di HP.
 */
import { animate } from 'motion';

function gerakDikurangi() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

/** Navbar merapat & bayangannya menguat begitu halaman digulir. */
function pasangReaksiScroll(bar) {
    let terakhir = null;

    const perbarui = () => {
        const digulir = window.scrollY > 12;

        if (digulir === terakhir) {
            return;
        }

        terakhir = digulir;
        bar.dataset.scrolled = digulir ? 'true' : 'false';
    };

    perbarui();
    window.addEventListener('scroll', perbarui, { passive: true });
}

/**
 * Dropdown desktop. Menu dianimasikan dari atas (asalnya) supaya arah gerakannya
 * menjelaskan dari mana panel itu datang.
 */
function pasangDropdown(pemicu) {
    const menu = document.getElementById(pemicu.getAttribute('aria-controls'));

    if (!menu) {
        return;
    }

    const setStatus = (terbuka) => {
        pemicu.setAttribute('aria-expanded', String(terbuka));

        if (terbuka) {
            menu.hidden = false;

            if (!gerakDikurangi()) {
                animate(
                    menu,
                    { opacity: [0, 1], y: [-8, 0], scale: [0.97, 1] },
                    { duration: 0.18, ease: 'easeOut' },
                );
            }

            return;
        }

        if (gerakDikurangi()) {
            menu.hidden = true;

            return;
        }

        // Keluar lebih cepat daripada masuk supaya terasa responsif.
        animate(menu, { opacity: 0, y: -6 }, { duration: 0.12, ease: 'easeIn' }).finished.then(() => {
            menu.hidden = true;
        });
    };

    pemicu.addEventListener('click', () => {
        setStatus(pemicu.getAttribute('aria-expanded') !== 'true');
    });

    document.addEventListener('click', (event) => {
        if (!menu.contains(event.target) && !pemicu.contains(event.target)) {
            if (pemicu.getAttribute('aria-expanded') === 'true') {
                setStatus(false);
            }
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && pemicu.getAttribute('aria-expanded') === 'true') {
            setStatus(false);
            pemicu.focus();
        }
    });
}

/** Panel navigasi mobile beserta backdrop-nya. */
function pasangPanelMobile(pemicu) {
    const panel = document.getElementById(pemicu.getAttribute('aria-controls'));
    const backdrop = document.querySelector('[data-nav-backdrop]');

    if (!panel) {
        return;
    }

    const buka = () => {
        pemicu.setAttribute('aria-expanded', 'true');
        panel.hidden = false;
        document.body.style.overflow = 'hidden';

        if (backdrop) {
            backdrop.hidden = false;
        }

        if (gerakDikurangi()) {
            return;
        }

        if (backdrop) {
            animate(backdrop, { opacity: [0, 1] }, { duration: 0.2 });
        }

        animate(panel, { opacity: [0, 1], y: [-12, 0] }, { duration: 0.24, ease: 'easeOut' });

        // Butir menu menyusul agar panel terasa terbuka, bukan sekadar muncul.
        const butir = panel.querySelectorAll('[data-nav-item]');
        butir.forEach((el, i) => {
            animate(el, { opacity: [0, 1], y: [8, 0] }, { duration: 0.22, delay: 0.05 + i * 0.035 });
        });
    };

    const tutup = () => {
        pemicu.setAttribute('aria-expanded', 'false');
        document.body.style.overflow = '';

        const selesai = () => {
            panel.hidden = true;

            if (backdrop) {
                backdrop.hidden = true;
            }
        };

        if (gerakDikurangi()) {
            selesai();

            return;
        }

        if (backdrop) {
            animate(backdrop, { opacity: 0 }, { duration: 0.15 });
        }

        animate(panel, { opacity: 0, y: -8 }, { duration: 0.15, ease: 'easeIn' }).finished.then(selesai);
    };

    pemicu.addEventListener('click', () => {
        pemicu.getAttribute('aria-expanded') === 'true' ? tutup() : buka();
    });

    backdrop?.addEventListener('click', tutup);

    // Menutup panel setelah memilih tujuan; kalau tidak, panel menghalangi
    // halaman yang baru saja di-scroll ke bawah.
    panel.querySelectorAll('a[href^="#"]').forEach((tautan) => {
        tautan.addEventListener('click', tutup);
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && pemicu.getAttribute('aria-expanded') === 'true') {
            tutup();
            pemicu.focus();
        }
    });

    // Kalau layar dilebarkan sampai navigasi desktop tampil, panel wajib ditutup —
    // kalau tidak, overflow body tetap terkunci dan halaman tidak bisa digulir.
    window.matchMedia('(min-width: 1024px)').addEventListener('change', (e) => {
        if (e.matches && pemicu.getAttribute('aria-expanded') === 'true') {
            tutup();
        }
    });
}

export function pasangNavbar() {
    const bar = document.querySelector('[data-navbar]');

    if (bar) {
        pasangReaksiScroll(bar);
    }

    document.querySelectorAll('[data-dropdown-trigger]').forEach(pasangDropdown);

    const pemicuMobile = document.querySelector('[data-nav-toggle]');

    if (pemicuMobile) {
        pasangPanelMobile(pemicuMobile);
    }
}

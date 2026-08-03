/**
 * Pemeriksaan kerapian & responsivitas baku.
 *
 * Dipakai bersama tests/browser/ukur.mjs:
 *   node tests/browser/ukur.mjs <url> 390 /tmp/a.png tests/browser/periksa-rapi.js 844
 *
 * Gunanya mengubah "pastikan rapi, responsif, dan tidak ada kolom kosong" dari pendapat
 * menjadi angka. Tujuh hal di bawah ini semuanya PERNAH lolos dari mata dan dari PHPUnit
 * di proyek ini, dan tiap satunya sudah pernah sampai ke pengguna:
 *
 * 1. gulirMendatar   — halaman melebar; hampir selalu min-width:auto pada anak flex/grid
 * 2. panelMenggulir  — dialog lebih tinggi daripada layar, tombol simpan tak terjangkau
 * 3. tumpangTindih   — tombol menimpa teks di kolom sempit
 * 4. cloakTersisa    — elemen bertanda x-cloak yang tersembunyi selamanya (Alpine mati)
 * 5. tombolTakSeragam— tombol sebaris beda ukuran/tidak sejajar
 * 6. wadahKosong     — kotak yang memakan tinggi tanpa isi apa pun
 * 7. selKosong       — sel tabel kosong tanpa penanda "—"
 *
 * Keluarannya satu baris ringkas; setiap angka yang bukan 0 berarti ada yang harus
 * dibetulkan, dan contohnya ikut disebutkan supaya bisa langsung dicari.
 */

(() => {
    const kotak = (e) => e.getBoundingClientRect();
    /*
     * Terlihat = punya ukuran, tidak disembunyikan, DAN memotong layar.
     *
     * Syarat terakhir itu yang menyelamatkan pemeriksa ini dari salah tuduh: menu samping
     * ponsel diparkir di luar layar dengan transform (mis. x = -300), ukurannya tetap
     * utuh, dan tanpa syarat ini seluruh isinya dilaporkan bertumpang-tindih satu sama
     * lain di setiap halaman.
     */
    const tampak = (e) => {
        const r = kotak(e);

        return r.width > 0 && r.height > 0
            && getComputedStyle(e).visibility !== 'hidden'
            && r.right > 0 && r.left < window.innerWidth
            && r.bottom > 0;
    };
    const sebut = (e) => {
        const nama = e.tagName.toLowerCase();
        const id = e.id ? '#' + e.id : '';
        const kelas = (typeof e.className === 'string' ? e.className : '').split(' ').filter(Boolean)[0];

        return nama + id + (id === '' && kelas ? '.' + kelas : '');
    };
    const laporan = [];
    const contoh = [];

    /* 1. Gulir mendatar — halaman tidak boleh lebih lebar daripada layarnya. */
    const gulirMendatar = document.documentElement.scrollWidth > window.innerWidth + 1;

    if (gulirMendatar) {
        // Biang keladinya dicari sekalian: elemen yang tepinya melewati lebar layar.
        const pelebar = [...document.querySelectorAll('*')]
            .filter((e) => tampak(e) && kotak(e).right > window.innerWidth + 1)
            .slice(0, 3)
            .map(sebut);

        contoh.push('pelebar: ' + (pelebar.join(', ') || 'tidak terlacak'));
    }

    /* 2. Panel/dialog yang menggulir. Yang diperiksa hanya elemen bergulir yang
          tingginya dibatasi layar — bukan badan halaman, yang memang boleh digulir. */
    const panel = [...document.querySelectorAll('[role=dialog], .kartu, [class*="max-h-"]')]
        .filter((e) => tampak(e) && e.scrollHeight - e.clientHeight > 1 && kotak(e).height <= window.innerHeight);

    if (panel.length > 0) {
        contoh.push('panelMenggulir: ' + panel.slice(0, 2).map((e) => sebut(e) + ' (+' + (e.scrollHeight - e.clientHeight) + 'px)').join(', '));
    }

    /*
     * 3. Tumpang-tindih antar-elemen berdaun (tanpa anak), yang berarti ada yang tertutup.
     *
     * Hanya dibandingkan yang berada dalam SATU konteks berposisi. Tanpa batasan itu,
     * teks di dalam dialog akan selalu dilaporkan menimpa isi halaman di belakangnya —
     * padahal menutupi latar memang tugas sebuah dialog. Pemeriksa yang salah tuduh
     * akan diabaikan orang, dan sesudah itu temuan sungguhan ikut terabaikan.
     */
    const konteks = (e) => {
        for (let n = e.parentElement; n !== null; n = n.parentElement) {
            if (getComputedStyle(n).position !== 'static') {
                return n;
            }
        }

        return null;
    };
    const daun = [...document.querySelectorAll('input:not([type=file]), select, textarea, button, a, p, span, label, td, th')]
        .filter((e) => tampak(e) && e.children.length === 0
            && (e.textContent.trim() !== '' || ['INPUT', 'SELECT', 'TEXTAREA'].includes(e.tagName))
            && ! ['absolute', 'fixed'].includes(getComputedStyle(e).position));

    const tumpang = [];

    for (let i = 0; i < daun.length && tumpang.length < 3; i++) {
        for (let j = i + 1; j < daun.length && tumpang.length < 3; j++) {
            const a = daun[i];
            const b = daun[j];

            if (a.contains(b) || b.contains(a) || konteks(a) !== konteks(b)) {
                continue;
            }

            const ra = kotak(a);
            const rb = kotak(b);
            const x = Math.min(ra.right, rb.right) - Math.max(ra.left, rb.left);
            const y = Math.min(ra.bottom, rb.bottom) - Math.max(ra.top, rb.top);

            if (x > 4 && y > 4) {
                tumpang.push(sebut(a) + '×' + sebut(b));
            }
        }
    }

    if (tumpang.length > 0) {
        contoh.push('tumpangTindih: ' + tumpang.join(', '));
    }

    /* 4. x-cloak yang tertinggal = Alpine tidak menghidupkan elemennya; isinya tidak akan
          pernah muncul. Penyebab tersering: skrip gagal dimuat, atau <template x-if> di
          dalam <svg>. */
    const cloak = [...document.querySelectorAll('[x-cloak]')];

    if (cloak.length > 0) {
        contoh.push('cloakTersisa: ' + cloak.slice(0, 3).map(sebut).join(', '));
    }

    /* 5. Tombol sebaris harus seukuran dan sejajar. Dikelompokkan per induk supaya tombol
          di dua bagian berbeda tidak dibandingkan satu sama lain. */
    const takSeragam = [];

    for (const induk of new Set([...document.querySelectorAll('button')].map((b) => b.parentElement))) {
        const tombol = [...(induk?.children ?? [])].filter((e) => e.tagName === 'BUTTON' && tampak(e));

        if (tombol.length < 2) {
            continue;
        }

        /*
         * Dikelompokkan per BARIS, bukan per induk.
         *
         * Grid berisi banyak baris (mis. petak produk di kasir) memang punya tombol di
         * ketinggian berbeda-beda; membandingkan semuanya sekaligus selalu melaporkan
         * "tidak sejajar" untuk tata letak yang justru benar. Dua tombol dianggap
         * sebaris kalau rentang tegaknya bertumpang lebih dari separuh.
         */
        const baris = [];

        for (const b of tombol) {
            const r = kotak(b);
            const sebaris = baris.find((kel) => {
                const q = kotak(kel[0]);
                const tumpang = Math.min(q.bottom, r.bottom) - Math.max(q.top, r.top);

                return tumpang > Math.min(q.height, r.height) / 2;
            });

            sebaris ? sebaris.push(b) : baris.push([b]);
        }

        for (const kel of baris) {
            if (kel.length < 2) {
                continue;
            }

            const tinggi = new Set(kel.map((b) => Math.round(kotak(b).height)));
            const atas = new Set(kel.map((b) => Math.round(kotak(b).top)));

            if (tinggi.size > 1 || atas.size > 1) {
                takSeragam.push(sebut(induk) + ' (tinggi ' + [...tinggi].join('/') + ', atas ' + [...atas].join('/') + ')');
            }
        }
    }

    if (takSeragam.length > 0) {
        contoh.push('tombolTakSeragam: ' + takSeragam.slice(0, 3).join(', '));
    }

    /* 6. Wadah yang memakan tinggi tapi tidak berisi apa pun — inilah "kolom kosong":
          kotak yang terlihat sebagai lubang di tata letak. Ambang 24px supaya pengatur
          jarak setipis garis tidak ikut terhitung. */
    /**
     * Apakah elemen ini (atau salah satu keturunannya) benar-benar menggambar sesuatu.
     *
     * Diperiksa turun ke bawah, bukan hanya di elemen itu sendiri. Jalur batang pada
     * grafik omzet tidak punya teks maupun ikon, tapi di dalamnya ada batang berlatar
     * warna — dan melaporkannya sebagai lubang tata letak akan membuat setiap halaman
     * bergrafik selalu bertanda merah.
     */
    const adaIsi = (e) => {
        if (e.textContent.trim() !== '') {
            return true;
        }

        if (e.querySelector('img, svg, canvas, video, input, select, textarea, iframe')) {
            return true;
        }

        for (const anak of [e, ...e.querySelectorAll('*')]) {
            const g = getComputedStyle(anak);
            const r = kotak(anak);

            if (r.width < 1 || r.height < 1) {
                continue;
            }

            const berlatar = g.backgroundImage !== 'none'
                || (g.backgroundColor !== 'rgba(0, 0, 0, 0)' && g.backgroundColor !== 'transparent');

            if (berlatar || g.borderTopWidth !== '0px' || g.borderBottomWidth !== '0px'
                || g.borderLeftWidth !== '0px' || g.borderRightWidth !== '0px') {
                return true;
            }
        }

        return false;
    };

    const wadahKosong = [...document.querySelectorAll('div, section, aside, td, li')]
        .filter((e) => {
            const r = kotak(e);

            return r.height >= 24 && r.width >= 24 && tampak(e) && ! adaIsi(e);
        });

    if (wadahKosong.length > 0) {
        contoh.push('wadahKosong: ' + wadahKosong.slice(0, 3).map((e) => sebut(e) + ' ' + Math.round(kotak(e).width) + '×' + Math.round(kotak(e).height)).join(', '));
    }

    /* 7. Sel tabel kosong. Sel tanpa isi membuat pembacanya menebak: nol? belum diisi?
          Aturan proyek ini: selalu ada penanda, mis. "—". */
    const selKosong = [...document.querySelectorAll('tbody td')]
        .filter((e) => tampak(e) && e.textContent.trim() === '' && ! e.querySelector('img, svg, input, button, a'));

    if (selKosong.length > 0) {
        contoh.push('selKosong: ' + selKosong.length + ' sel');
    }

    laporan.push('lebar=' + window.innerWidth);
    laporan.push('gulirMendatar=' + (gulirMendatar ? 'YA' : 'tidak'));
    laporan.push('panelMenggulir=' + panel.length);
    laporan.push('tumpangTindih=' + tumpang.length);
    laporan.push('cloakTersisa=' + cloak.length);
    laporan.push('tombolTakSeragam=' + takSeragam.length);
    laporan.push('wadahKosong=' + wadahKosong.length);
    laporan.push('selKosong=' + selKosong.length);

    const bersih = ! gulirMendatar && panel.length === 0 && tumpang.length === 0
        && cloak.length === 0 && takSeragam.length === 0 && wadahKosong.length === 0 && selKosong.length === 0;

    return laporan.join(' ') + (bersih ? ' → BERSIH' : '\n  ' + contoh.join('\n  '));
})()

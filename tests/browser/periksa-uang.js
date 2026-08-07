/**
 * Probe khusus KOTAK UANG di layar nota belanja — dipakai bersama ukur.mjs:
 *   node tests/browser/ukur.mjs <url> 390 /tmp/a.png tests/browser/periksa-uang.js 844
 *
 * Kenapa terpisah dari periksa-rapi.js: ketujuh angka kerapian mengukur apa yang SUDAH ada
 * di halaman, sedangkan yang harus dibuktikan di sini lahir dari ketikan — bahwa "1500000"
 * yang diketik benar-benar menjadi "Rp 1.500.000" di peramban sungguhan, dan bahwa nominal
 * sepanjang itu MUAT di kotak selebar setengah kartu 390px.
 *
 * Angka uang yang terpotong lebih merugikan daripada tata letak jelek: pembacanya menduga
 * digit yang hilang lalu memakainya untuk memutuskan belanja. Mata melewatkan pemotongan
 * seperti ini karena kotaknya tetap terlihat normal — yang menangkapnya cuma
 * scrollWidth > clientWidth pada elemen yang overflow-nya bukan `visible`.
 *
 * Yang dilaporkan:
 *   kotak        — berapa kotak uang yang ditemukan (0 berarti Alpine mati / bundel basi)
 *   awalFormat   — kotak berisi nilai dari server yang TIDAK berformat "Rp …"
 *   ketikSalah   — hasil ketikan yang tidak sama dengan "Rp 1.500.000"
 *   terpotong    — kotak yang isinya melebihi lebar kotaknya
 *   kursorSalah  — sisipan digit di tengah nominal yang melempar kursor ke tempat lain
 */

(() => {
    const kotak = [...document.querySelectorAll('input[x-data^="kotakUang("]')];
    const catatan = [];
    const sebut = (e) => 'input#' + (e.id || '(tanpa id)');

    /* 1. Nilai dari server harus SUDAH berformat saat halaman dibuka. Kotak yang masih
          berisi angka mentah berarti init() Alpine tidak pernah jalan. */
    const awalFormat = kotak.filter((e) => e.value !== '' && ! e.value.startsWith('Rp '));

    if (awalFormat.length > 0) {
        catatan.push('awalFormat: ' + awalFormat.slice(0, 3).map((e) => sebut(e) + '="' + e.value + '"').join(', '));
    }

    /* 2. Mengetik nominal terpanjang yang masih wajar untuk belanja warung. */
    const ketik = (el, teks, di) => {
        const posisi = di ?? el.value.length;
        el.value = el.value.slice(0, posisi) + teks + el.value.slice(posisi);
        el.setSelectionRange(posisi + teks.length, posisi + teks.length);
        el.dispatchEvent(new Event('input', { bubbles: true }));
    };

    const ketikSalah = [];
    const terpotong = [];

    /*
     * Kotak yang paling SEMPIT ikut dilaporkan angkanya, bukan cuma "terpotong=0".
     *
     * Di 390px seluruh kotak di tabel dekstop tersembunyi (lg:block), dan elemen tersembunyi
     * berlebar 0 — jadi "tidak ada yang terpotong" bisa berarti "tidak ada yang terukur".
     * Angka isi-vs-kotak yang tercetak membuat bedanya terlihat.
     */
    let sempit = null;

    for (const el of kotak) {
        el.value = '';
        ketik(el, '1500000');

        if (el.value !== 'Rp 1.500.000') {
            ketikSalah.push(sebut(el) + '→"' + el.value + '"');

            continue;
        }

        if (el.clientWidth > 0 && (sempit === null || el.clientWidth < sempit.kotak)) {
            sempit = { nama: sebut(el), kotak: el.clientWidth, isi: el.scrollWidth };
        }

        // Ambang 1px: pembulatan sub-piksel bukan pemotongan.
        if (el.scrollWidth > el.clientWidth + 1 && getComputedStyle(el).overflow !== 'visible') {
            terpotong.push(sebut(el) + ' isi ' + el.scrollWidth + 'px di kotak ' + el.clientWidth + 'px');
        }
    }

    if (ketikSalah.length > 0) {
        catatan.push('ketikSalah: ' + ketikSalah.slice(0, 3).join(', '));
    }

    if (terpotong.length > 0) {
        catatan.push('terpotong: ' + terpotong.slice(0, 3).join(', '));
    }

    /* 3. Kursor: sisipan di tengah nominal tidak boleh melempar kursor ke ujung kanan.
          Diuji di kotak pertama saja — logikanya satu fungsi untuk semua kotak, dan
          hitungan murninya sudah diuji baris-per-baris di tests/js/uang.test.mjs. */
    const kursorSalah = [];

    if (kotak.length > 0) {
        const el = kotak[0];
        el.value = '';
        ketik(el, '58000');
        // Kursor ditaruh sesudah digit "8" pada "Rp 58.000", lalu 9 diketik.
        ketik(el, '9', 5);

        if (el.value !== 'Rp 589.000' || el.selectionStart !== 6) {
            kursorSalah.push(sebut(el) + '→"' + el.value + '" kursor=' + el.selectionStart + ' (harus "Rp 589.000" kursor=6)');
        }
    }

    if (kursorSalah.length > 0) {
        catatan.push('kursorSalah: ' + kursorSalah.join(', '));
    }

    const laporan = [
        'lebar=' + window.innerWidth,
        'kotak=' + kotak.length,
        'awalFormat=' + awalFormat.length,
        'ketikSalah=' + ketikSalah.length,
        'terpotong=' + terpotong.length,
        'kursorSalah=' + kursorSalah.length,
        'kotakTersempit=' + (sempit === null
            ? 'tidak ada yang tampak'
            : sempit.nama + ' isi ' + sempit.isi + 'px di ' + sempit.kotak + 'px'),
    ];

    const bersih = kotak.length > 0 && sempit !== null && awalFormat.length === 0
        && ketikSalah.length === 0 && terpotong.length === 0 && kursorSalah.length === 0;

    return laporan.join(' ') + (bersih ? ' → BERSIH' : '\n  ' + (kotak.length === 0
        ? 'kotak=0: tidak ada kotak uang di halaman ini — Alpine mati, bundel basi, atau '
          + 'tangkapannya bukan layar nota belanja. Angka di atas jangan dibaca sebagai rapi.'
        : catatan.join('\n  ')));
})()

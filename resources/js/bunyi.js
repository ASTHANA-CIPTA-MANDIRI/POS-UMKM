/**
 * Suara umpan balik untuk layar kasir: UCAPAN, dengan bip sebagai cadangan.
 *
 * DIBANGKITKAN, bukan diunduh. Tidak ada berkas mp3/wav sama sekali — nadanya disusun
 * Web Audio API di perangkat. Alasannya sama dengan alasan seluruh layar kasir ini
 * dibuat: bunyi yang menunggu berkas dari jaringan akan diam persis saat jaringan mati,
 * dan justru saat itulah kasir paling bergantung pada tanda bahwa barangnya sudah masuk.
 * Ukurannya juga nol byte, bukan puluhan kilobyte.
 *
 * BERHASIL dan GAGAL harus bisa dibedakan TANPA melihat layar, karena itu gunanya:
 *
 *   berhasil — satu blip pendek & tinggi (1180 Hz, 70 ms). Cepat, tidak mengganggu,
 *              boleh terdengar dua puluh kali semenit tanpa membuat orang lelah.
 *   gagal    — dua nada rendah yang MENURUN (320 → 200 Hz, hampir setengah detik).
 *              Lebih rendah, lebih lama, dan turun — tiga hal yang membuatnya tidak
 *              mungkin disalahartikan sebagai bunyi berhasil di warung yang ramai.
 *
 * Membedakannya hanya dari tinggi nada tidak cukup: ponsel murah dan pengeras suara
 * tablet memangkas frekuensi rendah, jadi PANJANG dan JUMLAH nadanya ikut dibedakan.
 *
 * UCAPAN memakai Web Speech API bawaan peramban — tetap tanpa satu pun berkas audio.
 * Suara yang mengucap "berhasil"/"gagal" tidak bisa disalahartikan seperti bip, dan
 * itu gunanya: kasir tidak perlu menghafal arti dua nada.
 *
 * Tiga hal yang membuat ucapan TIDAK sesederhana bip, dan ditangani di bawah:
 *
 * 1. Ucapan MENGANTRE. Sepuluh pindaian cepat akan menumpuk dan tertinggal beberapa
 *    detik di belakang kenyataan — kasir mendengar "berhasil" untuk barang yang sudah
 *    lewat. Karena itu antreannya selalu dibatalkan dulu: yang terbaru yang menang.
 * 2. Suaranya bisa TIDAK ADA di perangkat itu. Kalau begitu, jatuh ke bip — bukan diam.
 * 3. Sebagian peramban memakai suara dari INTERNET. Yang dipilih di sini suara lokal
 *    lebih dulu, supaya tetap berbunyi saat jaringan mati.
 */

/**
 * Yang diucapkan. Satu kata: makin panjang, makin jauh tertinggal dari pindaiannya.
 *
 * Titik di belakangnya BUKAN hiasan. Kata tunggal tanpa tanda baca dibaca datar oleh
 * hampir semua mesin ucap; dengan titik, mesinnya memperlakukannya sebagai kalimat utuh
 * dan memberi intonasi menurun di akhir — itulah yang membuat "Berhasil." terdengar
 * selesai dan tenang, bukan terpotong.
 */
const UCAPAN = {
    sukses: 'Berhasil.',
    gagal: 'Gagal.',
};

/**
 * Nada bicara: perempuan, tenang, jelas.
 *
 * rate 1.0 — laju wajar. Sebelumnya 1,25 supaya cepat, tapi ucapan yang dipercepat
 *            terdengar terpotong dan justru paling mudah salah dengar di warung ramai.
 *            Yang butuh cepat memakai mode bip; ucapan tugasnya jelas, bukan singkat.
 * pitch 1.12 — sedikit di atas normal: lebih lembut tanpa jadi melengking.
 * volume 0.9 — tidak mentok; suara mentok pada pengeras suara tablet mudah pecah, dan
 *            suara pecah adalah kebalikan dari jelas.
 */
const NADA_BICARA = { rate: 1, pitch: 1.12, volume: 0.9 };

/*
 * Web Speech API TIDAK punya keterangan jenis suara — hanya nama, bahasa, dan apakah
 * suaranya ada di perangkat. Jadi perempuan/lelaki cuma bisa dikenali dari NAMANYA, dan
 * daftar di bawah sengaja dibuat tidak sok tahu:
 *
 * PEREMPUAN hanya berisi nama yang memang pasti (Damayanti di macOS/iOS, Gadis di
 * Windows/Azure, Google Bahasa Indonesia di Android), plus kata penanda umum.
 * LELAKI berisi yang pasti lelaki, supaya dihindari.
 *
 * Nama yang tidak ada di keduanya (mis. "Andika") tidak ditebak — ia tetap boleh dipakai
 * kalau tidak ada pilihan lain. Menebak salah lebih buruk daripada tidak menebak.
 */
const NAMA_PEREMPUAN = ['damayanti', 'gadis', 'google bahasa indonesia', 'female', 'wanita', 'perempuan', 'siti', 'dewi', 'indah', 'putri'];
const NAMA_LELAKI = ['ardi', 'male', 'pria', 'laki'];

const NADA = {
    sukses: [
        { frekuensi: 1180, mulai: 0, durasi: 0.07, kuat: 0.16 },
    ],
    gagal: [
        { frekuensi: 320, mulai: 0, durasi: 0.16, kuat: 0.3 },
        { frekuensi: 200, mulai: 0.18, durasi: 0.26, kuat: 0.3 },
    ],
};

let konteks = null;
let suaraTerpilih = null;

function siapkan() {
    if (konteks !== null) {
        return konteks;
    }

    const Kelas = window.AudioContext ?? window.webkitAudioContext;

    if (typeof Kelas !== 'function') {
        return null;
    }

    konteks = new Kelas();

    return konteks;
}

/** Mesin ucap peramban, kalau memang ada. */
function mesinUcap() {
    return typeof window.speechSynthesis === 'object'
        && typeof window.SpeechSynthesisUtterance === 'function'
        ? window.speechSynthesis
        : null;
}

function jenisSuara(suara) {
    const nama = (suara.name ?? '').toLowerCase();

    if (NAMA_PEREMPUAN.some((n) => nama.includes(n))) {
        return 'perempuan';
    }

    if (NAMA_LELAKI.some((n) => nama.includes(n))) {
        return 'lelaki';
    }

    return 'tidak diketahui';
}

/**
 * Memilih suara Indonesia: LOKAL lebih dulu, lalu PEREMPUAN.
 *
 * Urutannya sengaja begitu, dan ini pertukaran yang perlu disadari. Suara yang
 * disintesis di server DIAM saat jaringan mati — dan warung tanpa internet adalah
 * keadaan yang justru paling butuh suaranya. Suara lelaki yang berbunyi lebih berguna
 * daripada suara perempuan yang bisu, jadi "ada di perangkat" menang atas jenis suara.
 *
 * Dalam praktiknya keduanya hampir selalu sejalan: Damayanti (macOS/iOS) dan Google
 * Bahasa Indonesia (Android) sama-sama perempuan.
 *
 * Kalau tidak ada suara Indonesia sama sekali, dibiarkan kosong: peramban memakai suara
 * bawaannya, dan "Berhasil."/"Gagal." masih terdengar jelas walau aksennya asing.
 */
function pilihSuara(mesin) {
    if (suaraTerpilih !== null) {
        return suaraTerpilih;
    }

    const peringkat = { perempuan: 0, 'tidak diketahui': 1, lelaki: 2 };

    suaraTerpilih = (mesin.getVoices?.() ?? [])
        .filter((v) => (v.lang ?? '').toLowerCase().startsWith('id'))
        .sort((a, b) => (b.localService === true) - (a.localService === true)
            || peringkat[jenisSuara(a)] - peringkat[jenisSuara(b)])[0] ?? null;

    return suaraTerpilih;
}

/**
 * @param {'sukses'|'gagal'} jenis
 * @returns {boolean} false berarti ucapan tidak bisa dipakai di perangkat ini.
 */
export function ucapkan(jenis) {
    const kata = UCAPAN[jenis];
    const mesin = mesinUcap();

    if (kata === undefined || mesin === null) {
        return false;
    }

    /*
     * Antrean dibatalkan lebih dulu.
     *
     * Tanpa ini, memindai sepuluh barang berturut-turut menghasilkan sepuluh ucapan yang
     * mengantre — dan kasir mendengar hasil barang keempat saat sudah memindai yang
     * kesembilan. Kabar yang terlambat lebih buruk daripada tidak ada kabar, karena
     * barang yang gagal akan dikira barang lain.
     */
    mesin.cancel?.();

    const ucapan = new window.SpeechSynthesisUtterance(kata);

    ucapan.lang = 'id-ID';
    ucapan.rate = NADA_BICARA.rate;
    ucapan.pitch = NADA_BICARA.pitch;
    ucapan.volume = NADA_BICARA.volume;

    const suara = pilihSuara(mesin);

    if (suara !== null) {
        ucapan.voice = suara;
    }

    mesin.speak(ucapan);

    return true;
}

/**
 * @param {'sukses'|'gagal'} jenis
 * @param {'ucapan'|'bip'} mode
 */
export function mainkan(jenis, mode = 'bip') {
    // Ucapan gagal disiapkan → jatuh ke bip, bukan diam. Perangkat tanpa mesin ucap
    // (sebagian peramban dalam aplikasi, Android lama) tetap harus memberi kabar.
    if (mode === 'ucapan' && ucapkan(jenis)) {
        return;
    }

    const nada = NADA[jenis];

    if (nada === undefined) {
        return;
    }

    const k = siapkan();

    if (k === null) {
        return;
    }

    /*
     * Peramban menahan AudioContext sampai ada sentuhan/ketukan dari orangnya. Di layar
     * kasir sentuhan itu selalu ada (menekan tombol pindai, mengetuk tile, mengetik), jadi
     * resume() di sini cukup. Kalau tetap ditolak, bunyinya hilang tanpa merusak apa pun —
     * tanda di layar tetap ada, dan itu yang tidak boleh bergantung pada izin apa pun.
     */
    if (k.state === 'suspended') {
        k.resume?.().catch?.(() => {});
    }

    const mulai = k.currentTime;

    for (const n of nada) {
        const osilator = k.createOscillator();
        const penguat = k.createGain();

        // Gelombang segitiga: nada murni (sine) terlalu lembut untuk warung ramai,
        // gelombang kotak terlalu kasar dan cepat melelahkan telinga.
        osilator.type = 'triangle';
        osilator.frequency.value = n.frekuensi;

        const t0 = mulai + n.mulai;
        const t1 = t0 + n.durasi;

        /*
         * Naik-turun halus, BUKAN nyala-mati mentah. Osilator yang dihidupkan pada
         * amplitudo penuh menghasilkan "klik" di awal dan akhir tiap nada — pada dua
         * puluh pindaian semenit, klik itu yang paling cepat membuat orang mematikan
         * suaranya.
         */
        penguat.gain.setValueAtTime(0.0001, t0);
        penguat.gain.linearRampToValueAtTime(n.kuat, t0 + 0.008);
        penguat.gain.exponentialRampToValueAtTime(0.0001, t1);

        osilator.connect(penguat);
        penguat.connect(k.destination);
        osilator.start(t0);
        osilator.stop(t1 + 0.02);
    }
}

export function pasangBunyi() {
    // Dipasang di window seperti toast: dipanggil dari Alpine tanpa tiap tempat perlu
    // tahu cara menyusun nadanya.
    window.bunyiNampan = mainkan;

    /*
     * Daftar suara diisi peramban secara asinkron — pada muat pertama getVoices() sering
     * masih kosong. Pilihan yang sudah dibuat dilupakan saat daftarnya berubah supaya
     * suara Indonesia yang datang terlambat tetap terpakai.
     */
    window.speechSynthesis?.addEventListener?.('voiceschanged', () => {
        suaraTerpilih = null;
    });
}

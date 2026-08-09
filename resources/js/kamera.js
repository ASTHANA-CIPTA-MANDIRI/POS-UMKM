/**
 * Kamera untuk memotret struk — layar owner "Nota belanja".
 *
 * KENAPA ADA. Pemilih berkas di dekstop tidak punya tombol kamera; di HP ada, karena
 * `accept="image/*"` membuat sistem menawarkan kamera bersama galeri. Jadi yang benar-benar
 * kekurangan pintu adalah dekstop dan laptop — dan di sanalah pemilik biasanya duduk saat
 * merapikan nota belanja sore hari.
 *
 * KENAPA MODUL SENDIRI, bukan cabang di dalam pemindaiBarcode. Pemindai barcode adalah
 * layar KASIR: ia wajib jalan tanpa jaringan dan tidak boleh berubah perilakunya karena
 * fitur owner. Satu panel yang melayani "baca barcode terus-menerus" dan "potret sekali
 * lalu tinjau" akan penuh cabang, dan cacat yang satu lahir dari perbaikan yang lain.
 *
 * Harganya diakui: plumbing kameranya (gerbang konteks aman, nyalakan/matikan aliran,
 * penerjemahan galat) mirip dengan pemindai.js. Menyatukannya jadi campuran bersama adalah
 * pekerjaan yang benar, TAPI ia menyentuh berkas kasir — dan itu dijadwalkan sendiri,
 * bukan diselundupkan ke fitur owner. Sampai itu terjadi: kalau memperbaiki penerjemahan
 * galat di sini, periksa juga pemindai.js.
 *
 * Yang SENGAJA tidak ada, dan jangan ditambahkan:
 *  - pembaca barcode. Memotret struk tidak butuh ZXing (±1 MB bundel).
 *  - unggahan sendiri. Hasil potret disuntikkan ke <input type="file"> yang sudah ada,
 *    jadi saringan jumlah, batas ukuran, validasi isi berkas, dan seluruh ujinya berlaku
 *    sama untuk foto yang dipilih maupun yang dipotret. Dua jalur unggah berarti dua
 *    tempat untuk lupa memeriksa.
 */

/** Sisi terpanjang hasil potret. Di atas ini keterbacaan tidak bertambah, ukurannya iya. */
const MAKS_SISI = 2400;

/** JPEG, bukan PNG: PNG 1920×1080 dari foto 3–5 MB dan menabrak batas 4 MB. */
const MUTU_JPEG = 0.85;

/** Nama berkas hasil potret: struk-YYYYMMDD-HHmm.jpg (waktu lokal). */
export function namaPotret(waktu = new Date()) {
    const p = (n) => String(n).padStart(2, '0');

    return 'struk-'
        + waktu.getFullYear() + p(waktu.getMonth() + 1) + p(waktu.getDate())
        + '-' + p(waktu.getHours()) + p(waktu.getMinutes()) + '.jpg';
}

/**
 * Ukuran kanvas hasil potret dari ukuran SUMBERNYA.
 *
 * Fungsi murni supaya bisa diuji tanpa kamera. Yang dijaga: sisi terpanjang tidak melebihi
 * MAKS_SISI, dan rasionya utuh — struk yang gepeng lebih buruk daripada struk yang kecil.
 */
export function ukuranPotret(lebar, tinggi, maks = MAKS_SISI) {
    if (! Number.isFinite(lebar) || ! Number.isFinite(tinggi) || lebar <= 0 || tinggi <= 0) {
        return { lebar: 0, tinggi: 0 };
    }

    const panjang = Math.max(lebar, tinggi);

    if (panjang <= maks) {
        return { lebar: Math.round(lebar), tinggi: Math.round(tinggi) };
    }

    const rasio = maks / panjang;

    return { lebar: Math.round(lebar * rasio), tinggi: Math.round(tinggi * rasio) };
}

/**
 * Kalimat untuk tiap sebab kegagalan kamera.
 *
 * Tiap kalimat menyebut apa yang bisa DIKERJAKAN orangnya. "Kamera tidak bisa diakses"
 * memberi tahu keadaan tanpa memberi tahu jalan keluar, dan orang yang tidak punya jalan
 * keluar akan menekan tombolnya berkali-kali.
 */
export function pesanKamera(e) {
    if (e?.name === 'NotAllowedError') {
        return 'Izin kamera ditolak. Buka ikon kunci di bilah alamat, izinkan kamera, lalu coba lagi. '
            + 'Atau pakai tombol "Pilih foto atau PDF".';
    }

    if (e?.name === 'NotFoundError' || e?.name === 'OverconstrainedError') {
        return 'Tidak ada kamera yang bisa dipakai di perangkat ini. Foto struknya pakai HP, '
            + 'lalu kirim ke komputer — atau pakai tombol "Pilih foto atau PDF".';
    }

    if (e?.name === 'NotReadableError') {
        return 'Kamera sedang dipakai aplikasi lain (Zoom, Meet, WhatsApp Desktop). '
            + 'Tutup aplikasi itu, lalu coba lagi.';
    }

    return 'Kameranya tidak bisa disiapkan. Pakai tombol "Pilih foto atau PDF".';
}

export function pasangKamera() {
    document.addEventListener('alpine:init', () => {
        window.Alpine.data('kameraBukti', (idInput = '') => ({
            terbuka: false,
            menyiapkan: false,

            /** Galat ditaruh di LUAR panel — lihat catatan di Blade. */
            galat: '',

            /** Hasil potret yang sedang ditinjau; kosong berarti masih membidik. */
            pratinjau: '',

            aliran: null,

            /**
             * Kamera hanya mungkin di konteks aman.
             *
             * Diperiksa sebelum apa pun ditawarkan: di alamat LAN (http://192.168.x.x:8000)
             * getUserMedia tidak pernah ada, jadi tombol yang tetap dipasang di situ adalah
             * tombol yang pasti diam saat ditekan.
             */
            get bisaKamera() {
                return typeof navigator.mediaDevices?.getUserMedia === 'function'
                    && window.isSecureContext !== false;
            },

            async buka() {
                if (this.terbuka || ! this.bisaKamera) {
                    return;
                }

                this.galat = '';
                this.pratinjau = '';
                this.terbuka = true;
                this.menyiapkan = true;

                try {
                    /*
                     * `ideal`, BUKAN `exact`.
                     *
                     * Banyak webcam dekstop berhenti di 1280×720; `exact` di situ melempar
                     * OverconstrainedError dan fiturnya mati total alih-alih turun mutu.
                     *
                     * 1920 dan bukan 1280: digit di struk termal 58 mm yang dipegang ±25 cm
                     * jatuh sekitar 8 px tinggi pada 720p — di ambang tidak terbaca. 1080p
                     * menggandakannya. Tidak 4K, karena yang membatasi keterbacaan adalah
                     * fokus dan pembingkaian, bukan piksel — sementara berkasnya jadi 2–3x.
                     */
                    this.aliran = await navigator.mediaDevices.getUserMedia({
                        video: {
                            facingMode: { ideal: 'environment' },
                            width: { ideal: 1920 },
                            height: { ideal: 1080 },
                        },
                    });

                    this.$refs.video.srcObject = this.aliran;
                    await this.$refs.video.play();
                } catch (e) {
                    // Panelnya DITUTUP dan galatnya ditaruh di luar: teks galat di dalam panel
                    // yang menutup tidak pernah terbaca siapa pun — cacat yang sudah tercatat
                    // pada panel pemindai barcode.
                    this.tutup();
                    this.galat = pesanKamera(e);

                    return;
                } finally {
                    this.menyiapkan = false;
                }
            },

            /** Memotret dari ukuran TREK, bukan dari ukuran elemen videonya. */
            jepret() {
                const trek = this.aliran?.getVideoTracks?.()[0];
                const setelan = trek?.getSettings?.() ?? {};

                /*
                 * Titik gagal yang paling mungkin, dan paling sunyi.
                 *
                 * `video.clientWidth` adalah ukuran TAMPILANNYA — bisa 320 px di panel
                 * sempit. Memotret dari situ menghasilkan JPEG selebar 320 px yang tampak
                 * baik-baik saja di pratinjau kecil dan tidak terbaca sesudah disimpan;
                 * ketahuannya paling lambat, yaitu saat fotonya dibutuhkan.
                 */
                const sumberLebar = setelan.width ?? this.$refs.video?.videoWidth ?? 0;
                const sumberTinggi = setelan.height ?? this.$refs.video?.videoHeight ?? 0;
                const ukuran = ukuranPotret(sumberLebar, sumberTinggi);

                if (ukuran.lebar === 0) {
                    this.galat = 'Gambarnya belum siap. Tunggu sebentar lalu coba lagi.';

                    return;
                }

                const kanvas = document.createElement('canvas');
                kanvas.width = ukuran.lebar;
                kanvas.height = ukuran.tinggi;
                kanvas.getContext('2d').drawImage(this.$refs.video, 0, 0, ukuran.lebar, ukuran.tinggi);

                this.pratinjau = kanvas.toDataURL('image/jpeg', MUTU_JPEG);
                this.kanvas = kanvas;
            },

            ulangi() {
                this.pratinjau = '';
                this.kanvas = null;
            },

            /**
             * Menyerahkan hasil potret ke kotak berkas yang sudah ada.
             *
             * Lewat DataTransfer + event `change`, jadi Livewire memprosesnya persis seperti
             * berkas yang dipilih tangan. Satu jalur unggah untuk dua cara memasukkan foto.
             */
            pakai() {
                if (! this.kanvas) {
                    return;
                }

                const input = document.getElementById(idInput);

                if (! input) {
                    this.galat = 'Kotak unggahnya tidak ditemukan di layar ini.';
                    this.tutup();

                    return;
                }

                this.kanvas.toBlob((blob) => {
                    if (! blob) {
                        this.galat = 'Fotonya gagal disiapkan. Coba potret ulang.';

                        return;
                    }

                    const berkas = new File([blob], namaPotret(), { type: 'image/jpeg' });
                    const wadah = new DataTransfer();

                    // Berkas yang SUDAH dipilih dipertahankan: memotret lembar kedua tidak
                    // boleh membuang lembar pertama yang baru saja dipilih dari galeri.
                    for (const lama of input.files ?? []) {
                        wadah.items.add(lama);
                    }

                    wadah.items.add(berkas);
                    input.files = wadah.files;
                    input.dispatchEvent(new Event('change', { bubbles: true }));

                    this.tutup();
                }, 'image/jpeg', MUTU_JPEG);
            },

            /**
             * Menutup panel DAN mematikan kameranya.
             *
             * Trek yang tidak dihentikan meninggalkan lampu kamera menyala; pemilik yang
             * melihatnya menyimpulkan aplikasinya merekam, dan berhenti memakainya.
             */
            tutup() {
                this.terbuka = false;
                this.menyiapkan = false;
                this.pratinjau = '';
                this.kanvas = null;

                for (const trek of this.aliran?.getTracks?.() ?? []) {
                    trek.stop();
                }

                this.aliran = null;
            },

            destroy() {
                this.tutup();
            },
        }));
    });
}

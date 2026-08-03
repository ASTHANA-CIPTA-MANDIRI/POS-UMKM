/**
 * Pemindai barcode untuk formulir produk.
 *
 * EMPAT jalur, dan urutannya bukan selera — ini urutan keberhasilannya di warung
 * sungguhan. Tiap jalur menutup kegagalan jalur di atasnya, sehingga tidak ada
 * peramban, perangkat, atau jaringan yang membuat kolom barcode jadi jalan buntu.
 *
 * 1. PEMINDAI USB/Bluetooth — jalur utama. Alat ini bekerja seperti papan ketik: ia
 *    "mengetik" angkanya lalu menekan Enter. Cepat, murah, tanpa izin, tanpa internet,
 *    dan tidak peduli peramban apa pun. Kode di sini hanya perlu memastikan angkanya
 *    masuk ke kolom yang benar meski kolomnya belum diklik (lihat `tangkap`), dan Enter
 *    tidak menyimpan formulir setengah jadi.
 *
 * 2. KAMERA + pembaca bawaan peramban — cepat dan tanpa unduhan.
 *
 * 3. KAMERA + pembaca cadangan (ZXing, diunduh saat itu) — untuk iPhone/Safari,
 *    Firefox, dan Chrome Windows yang tidak punya pembaca bawaan.
 *
 * 4. FOTO dari kamera bawaan sistem — untuk keadaan yang menutup jalur 2 dan 3:
 *    izin kamera ditolak, aplikasi dibuka lewat alamat LAN (bukan HTTPS, jadi kamera
 *    langsung dilarang peramban), atau dibuka di peramban dalam aplikasi.
 *
 * Di bawah semuanya selalu ada jalur kelima yang tidak bisa gagal: mengetik angkanya.
 * Karena itu kolomnya tetap kolom teks biasa, bukan kolom yang hanya bisa diisi mesin.
 */

const JEDA_FRAME = 180;

/**
 * Batas jeda antar-ketikan untuk mengenali pemindai USB, dalam milidetik.
 *
 * Pemindai mengetik satu karakter tiap ±10–20 ms; manusia tercepat masih di atas 100 ms.
 * 120 ms memberi ruang untuk alat murah yang lebih lambat tanpa pernah menyalahartikan
 * orang yang sedang mengetik.
 */
const BATAS_KETIK = 120;

/** Barcode pabrik terpendek (EAN-8) ada 8 digit; 6 memberi ruang untuk label sendiri. */
const MINIMAL_DIGIT = 6;

/**
 * Jarak minimal sebelum KODE YANG SAMA diterima lagi dari kamera, dalam milidetik.
 *
 * Kamera membaca ±5 frame per detik, dan barang yang masih dipegang di depan lensa akan
 * terbaca di setiap frame. Tanpa jarak ini, satu bungkus mi masuk keranjang sepuluh kali
 * dalam dua detik.
 *
 * HANYA berlaku untuk kamera. Pemindai USB tidak dibatasi: memindai barang yang sama dua
 * kali berturut-turut adalah cara kasir menghitung dua barang identik, dan menelan
 * pindaian kedua berarti satu barang terjual tanpa tercatat.
 */
const JEDA_KODE_SAMA = 1500;

export function pasangPemindai(muatDekoder = () => import('./dekoder')) {
    document.addEventListener('alpine:init', () => {
        /**
         * @param {'sekali'|'lanjut'} mode
         *        'sekali' — panel menutup begitu satu barcode terbaca. Dipakai formulir
         *        produk: di sana yang dibutuhkan memang satu kode, lalu kolom berikutnya.
         *
         *        'lanjut' — panel TETAP TERBUKA dan terus memindai. Dipakai layar kasir:
         *        kelontong memindai sepuluh barang berturut-turut, dan panel yang menutup
         *        sendiri setiap kali memaksa kasir menekan tombol pindai sepuluh kali
         *        sambil pembeli menunggu. Kode yang gagal dikenali pun tidak menutup
         *        panelnya — kasir perlu melihat apa yang terbaca, lalu lanjut memindai.
         */
        window.Alpine.data('pemindaiBarcode', (mode = 'sekali') => ({
            berkelanjutan: mode === 'lanjut',
            terbuka: false,
            /** Menyiapkan = pembaca cadangan sedang diunduh. Ditampilkan supaya
             *  layar gelap 1–2 detik tidak terlihat seperti aplikasi menggantung. */
            menyiapkan: false,
            memakaiCadangan: false,
            membacaFoto: false,
            galat: null,
            aliran: null,
            berjalan: false,
            detektor: null,
            kamera: [],
            indeksKamera: 0,
            punyaLampu: false,
            lampuNyala: false,
            /** Kode terakhir yang terbaca & jumlah pindaian sejak panel dibuka.
             *  Ditampilkan di dalam panel supaya pindaian berhasil tetap terlihat
             *  walau panelnya tidak menutup. */
            terakhir: null,
            jumlahPindai: 0,
            /** Kode & waktu pindaian kamera terakhir, khusus untuk menyaring frame kembar. */
            kodeKamera: null,
            waktuKamera: 0,
            /** Penyangga ketikan dari pemindai USB. */
            sanggah: '',
            ketikTerakhir: 0,

            /* ── Jalur 1: pemindai USB ───────────────────────────────────────── */

            /**
             * Menangkap ketikan pemindai USB meski kolomnya belum diklik.
             *
             * Tanpa ini, memindai barang tepat setelah formulir dibuka tidak
             * menghasilkan apa pun — angkanya "diketik" ke halaman yang tidak punya
             * fokus, lalu hilang. Orangnya tidak punya cara menduga bahwa yang kurang
             * hanya satu klik.
             *
             * Aman terhadap orang yang sedang mengetik karena hanya berlaku saat fokus
             * TIDAK berada di sebuah kolom: kalau fokusnya di kolom mana pun, ketikannya
             * memang sudah masuk ke sana dan tidak boleh dibajak.
             */
            tangkap(peristiwa) {
                if (this.terbuka || peristiwa.ctrlKey || peristiwa.metaKey || peristiwa.altKey) {
                    return;
                }

                const jenis = peristiwa.target?.tagName?.toLowerCase();

                if (jenis === 'input' || jenis === 'textarea' || jenis === 'select' || peristiwa.target?.isContentEditable) {
                    return;
                }

                const sekarang = this.sekarang();

                // Tombol pengubah tidak memutus pindaian: sebagian pemindai menekan Shift
                // di antara karakter, dan mengosongkan penyangga di situ akan membuang
                // separuh angka yang sudah masuk.
                if (['Shift', 'Control', 'Alt', 'Meta', 'CapsLock'].includes(peristiwa.key)) {
                    return;
                }

                // Tab diperlakukan sama dengan Enter: banyak pemindai USB dikonfigurasi
                // mengirim Tab sebagai penutup, bukan Enter.
                if (peristiwa.key === 'Enter' || peristiwa.key === 'Tab') {
                    if (this.sanggah.length >= MINIMAL_DIGIT) {
                        // preventDefault: Enter dari pemindai tidak boleh menekan tombol
                        // simpan yang sedang terfokus di halaman.
                        peristiwa.preventDefault?.();
                        this.terima(this.sanggah);
                    }

                    this.sanggah = '';

                    return;
                }

                if (peristiwa.key?.length !== 1 || peristiwa.key < '0' || peristiwa.key > '9') {
                    this.sanggah = '';

                    return;
                }

                // Jeda panjang berarti ini ketikan baru, bukan lanjutan pindaian.
                this.sanggah = sekarang - this.ketikTerakhir > BATAS_KETIK ? peristiwa.key : this.sanggah + peristiwa.key;
                this.ketikTerakhir = sekarang;
            },

            /** Dipisah supaya waktunya bisa dipalsukan saat diuji. */
            sekarang() {
                return Date.now();
            },

            /* ── Jalur 2 & 3: kamera langsung ────────────────────────────────── */

            /**
             * Apakah kamera langsung mungkin dipakai di halaman ini.
             *
             * Peramban hanya mengizinkan kamera di konteks aman. Membuka aplikasi lewat
             * alamat LAN (http://192.168.1.5) — yang justru cara tablet kasir memakainya
             * — BUKAN konteks aman, jadi tombolnya harus jujur disembunyikan dan
             * diarahkan ke USB atau foto, bukan dibiarkan gagal dengan pesan izin yang
             * membingungkan.
             */
            get bisaKamera() {
                return typeof navigator.mediaDevices?.getUserMedia === 'function'
                    && window.isSecureContext !== false;
            },

            get banyakKamera() {
                return this.kamera.length > 1;
            },

            async buka() {
                this.galat = null;

                if (! this.bisaKamera) {
                    this.galat = 'Peramban ini tidak boleh memakai kamera di alamat sekarang (kamera hanya jalan di HTTPS atau localhost). Pakai pemindai USB, ambil foto barcodenya, atau ketik angkanya.';

                    return;
                }

                this.terbuka = true;
                this.menyiapkan = true;
                this.jumlahPindai = 0;
                this.terakhir = null;
                this.kodeKamera = null;

                try {
                    const { buatDetektor } = await muatDekoder();
                    const { detektor, cadangan } = await buatDetektor();

                    this.detektor = detektor;
                    this.memakaiCadangan = cadangan;

                    // $nextTick: elemen video baru ada setelah panelnya dirender.
                    await this.$nextTick();
                    await this.nyalakan();
                    await this.daftarkanKamera();

                    this.menyiapkan = false;
                    this.berjalan = true;
                    this.pantau();
                } catch (e) {
                    this.galat = this.pesanGagal(e);
                    this.tutup();
                }
            },

            /**
             * Menyalakan (atau memindahkan) aliran kamera.
             *
             * facingMode belakang: kamera depan tidak bisa diarahkan ke barang. Dipakai
             * `ideal`, bukan `exact`, supaya laptop yang cuma punya kamera depan tetap
             * dapat gambar alih-alih ditolak mentah-mentah.
             */
            async nyalakan() {
                this.hentikanAliran();

                const pilihan = this.kamera[this.indeksKamera]
                    ? { deviceId: { exact: this.kamera[this.indeksKamera] } }
                    : { facingMode: { ideal: 'environment' } };

                this.aliran = await navigator.mediaDevices.getUserMedia({ video: pilihan });
                this.$refs.video.srcObject = this.aliran;
                await this.$refs.video.play();

                this.periksaLampu();
            },

            /**
             * Apakah kamera ini punya lampu yang bisa dinyalakan.
             *
             * Ditanya per aliran, bukan sekali per perangkat: lampunya milik kamera
             * belakang, jadi berpindah ke kamera depan harus ikut menghilangkan
             * tombolnya. Dukungannya juga jauh dari merata — banyak peramban tidak
             * memaparkan torch sama sekali, dan tombol yang tidak berbuat apa pun lebih
             * membingungkan daripada tidak ada.
             */
            periksaLampu() {
                const trek = this.trekVideo();

                this.punyaLampu = trek?.getCapabilities?.().torch === true;
                this.lampuNyala = false;
            },

            trekVideo() {
                return this.aliran?.getVideoTracks?.()[0] ?? null;
            },

            /**
             * Menyalakan/mematikan lampu kamera.
             *
             * Warung sore hari, rak paling dalam, dan barcode di badan kaleng yang
             * memantul adalah keadaan yang membuat pemindaian gagal berulang-ulang tanpa
             * sebab yang jelas. Lampunya bukan pemanis — ia yang membuat pemindaian
             * berhasil di tempat gelap.
             */
            async gantiLampu() {
                const trek = this.trekVideo();

                if (! trek) {
                    return;
                }

                const mau = ! this.lampuNyala;

                try {
                    await trek.applyConstraints({ advanced: [{ torch: mau }] });
                    this.lampuNyala = mau;
                } catch {
                    // Sebagian perangkat MENGAKU punya torch lalu menolak menyalakannya.
                    // Tombolnya disembunyikan daripada dibiarkan sebagai tombol mati.
                    this.punyaLampu = false;
                    this.lampuNyala = false;
                }
            },

            /**
             * Mendaftar kamera yang ada. Sengaja dijalankan SETELAH izin diberikan:
             * sebelum itu daftarnya tanpa deviceId, jadi tidak bisa dipakai berpindah.
             */
            async daftarkanKamera() {
                try {
                    const alat = await navigator.mediaDevices.enumerateDevices();

                    this.kamera = alat
                        .filter((a) => a.kind === 'videoinput' && a.deviceId)
                        .map((a) => a.deviceId);

                    const dipakai = this.aliran?.getVideoTracks?.()[0]?.getSettings?.().deviceId;
                    const posisi = this.kamera.indexOf(dipakai);

                    if (posisi >= 0) {
                        this.indeksKamera = posisi;
                    }
                } catch {
                    // Gagal mendaftar kamera hanya menghilangkan tombol "Ganti kamera";
                    // pemindaian dengan kamera yang sudah menyala tetap berjalan.
                    this.kamera = [];
                }
            },

            /**
             * Berpindah kamera.
             *
             * Bukan pemanis: laptop dengan webcam tambahan dan ponsel dengan beberapa
             * lensa belakang sering memilih lensa yang tidak bisa fokus dekat, dan
             * barcode tidak akan pernah terbaca sampai kameranya diganti.
             */
            async gantiKamera() {
                if (! this.banyakKamera) {
                    return;
                }

                this.indeksKamera = (this.indeksKamera + 1) % this.kamera.length;

                try {
                    await this.nyalakan();
                } catch (e) {
                    this.galat = this.pesanGagal(e);
                    this.tutup();
                }
            },

            async pantau() {
                while (this.berjalan) {
                    try {
                        const hasil = await this.detektor.detect(this.$refs.video);
                        const nilai = hasil.find((baris) => baris.rawValue)?.rawValue;

                        if (nilai && this.bolehDariKamera(nilai)) {
                            this.kodeKamera = nilai;
                            this.waktuKamera = this.sekarang();
                            this.terima(nilai);

                            // Mode sekali pakai berhenti di sini; mode berkelanjutan
                            // meneruskan perulangan untuk barang berikutnya.
                            if (! this.berkelanjutan) {
                                return;
                            }
                        }
                    } catch {
                        // Frame gagal dibaca itu normal saat kamera masih fokus;
                        // yang salah hanya frame itu, bukan seluruh pemindaiannya.
                    }

                    await new Promise((r) => setTimeout(r, JEDA_FRAME));
                }
            },

            /* ── Jalur 4: foto ───────────────────────────────────────────────── */

            /**
             * Membaca barcode dari foto yang dipilih atau baru diambil.
             *
             * Kolom berkasnya dikosongkan setelah dibaca supaya memilih foto yang SAMA
             * dua kali tetap memicu pembacaan — kalau tidak, percobaan kedua terlihat
             * seperti aplikasi yang tidak merespons.
             */
            async dariBerkas(peristiwa) {
                const berkas = peristiwa.target.files?.[0];

                peristiwa.target.value = '';

                if (! berkas) {
                    return;
                }

                this.galat = null;
                this.membacaFoto = true;

                try {
                    const { bacaBerkas } = await muatDekoder();
                    const nilai = await bacaBerkas(berkas);

                    if (nilai) {
                        this.terima(nilai);
                    } else {
                        this.galat = 'Barcode tidak terbaca dari foto itu. Ambil lebih dekat, pastikan garisnya tidak buram dan tidak tertekuk, lalu coba lagi.';
                    }
                } catch {
                    this.galat = 'Foto itu tidak bisa dibaca. Coba foto lain, atau ketik angkanya.';
                } finally {
                    this.membacaFoto = false;
                }
            },

            /* ── Bersama ─────────────────────────────────────────────────────── */

            pesanGagal(e) {
                if (e?.name === 'NotAllowedError') {
                    return 'Izin kamera ditolak. Aktifkan izinnya di pengaturan peramban, atau ambil foto barcodenya, atau ketik angkanya.';
                }

                if (e?.name === 'NotFoundError' || e?.name === 'OverconstrainedError') {
                    return 'Tidak ada kamera yang bisa dipakai di perangkat ini. Pakai pemindai USB, ambil foto barcodenya, atau ketik angkanya.';
                }

                if (e?.name === 'NotReadableError') {
                    return 'Kamera sedang dipakai aplikasi lain. Tutup aplikasi itu, lalu coba lagi.';
                }

                return 'Pemindai tidak bisa disiapkan. Pakai pemindai USB, ambil foto barcodenya, atau ketik angkanya.';
            },

            /** Menyaring frame kembar: barang yang masih dipegang terbaca berkali-kali. */
            bolehDariKamera(nilai) {
                return nilai !== this.kodeKamera
                    || this.sekarang() - this.waktuKamera > JEDA_KODE_SAMA;
            },

            terima(nilai) {
                this.sanggah = '';
                this.terakhir = nilai;
                this.jumlahPindai++;

                // Nilai diserahkan lewat event supaya komponen ini tidak perlu tahu kolom
                // mana yang mengisinya — pemakainya yang memutuskan.
                this.$dispatch('barcode-terpindai', { nilai });

                if (this.berkelanjutan) {
                    /*
                     * Tanpa toast: yang memakai mode ini menampilkan hasilnya sendiri di
                     * dalam panel (nama barang, jumlah, total). Dua pemberitahuan untuk
                     * satu pindaian hanya melatih orang mengabaikan keduanya — dan toast
                     * yang menumpuk sepuluh kali saat memindai sepuluh barang menutupi
                     * layar yang justru sedang dipakai.
                     */
                    return;
                }

                this.tutup();
                window.toastNampan?.('Barcode terbaca: ' + nilai, 'sukses');
            },

            hentikanAliran() {
                this.aliran?.getTracks().forEach((t) => t.stop());
                this.aliran = null;
                // Trek yang dihentikan otomatis memadamkan lampunya; keadaannya ikut
                // disetel ulang supaya tombolnya tidak menyala di pembukaan berikutnya.
                this.punyaLampu = false;
                this.lampuNyala = false;
            },

            tutup() {
                this.berjalan = false;
                this.terbuka = false;
                this.menyiapkan = false;
                this.hentikanAliran();
            },

            /**
             * Menutup panel setelah selesai memindai serangkaian barang.
             *
             * Dijaga terhadap panel yang SUDAH tertutup: pendengar Escape terpasang di
             * tingkat jendela dan tetap hidup setelah panelnya menutup, jadi tanpa
             * penjaga ini menekan Escape kapan pun sesudah memindai akan memunculkan
             * ringkasan pindaian yang tidak diminta siapa pun.
             */
            selesai() {
                if (! this.terbuka) {
                    return;
                }

                this.tutup();

                if (this.jumlahPindai > 0) {
                    window.toastNampan?.(this.jumlahPindai + ' barang dipindai.', 'sukses');
                }
            },
        }));
    });
}

/**
 * Klien layar kasir — tiga mode transaksi.
 *
 * Seluruh interaksi berjalan di sisi klien tanpa menyentuh server sampai transaksi
 * ditutup. Itu syarat mutlak agar kasir tetap melayani saat jaringan mati.
 *
 * MODE A (langsung)    : keranjang -> bayar -> kirim
 * MODE B (open_bill)   : buka bill per meja, pesanan menumpuk, bayar di akhir
 * MODE C (pesan_antar) : titipan dengan status berjalan sampai diambil & dibayar
 *
 * CARA BILL DISIMPAN, DAN ALASANNYA:
 * Pesanan bill yang belum dibayar disimpan DI PERANGKAT, lalu saat ditutup dikirim
 * sebagai SATU transaksi berisi seluruh item beserta pembayarannya. Alternatifnya —
 * mengirim tiap ronde pesanan sebagai transaksi draft — menuntut jalur pembaruan di
 * server saat pelunasan, dan membuat laporan produk terlaris kehilangan item Mode B
 * karena transaksi draft tidak dihitung sebagai omzet.
 *
 * Konsekuensi yang harus disadari: bill yang masih terbuka ikut hilang kalau
 * perangkatnya hilang, dan bill yang dibuka di perangkat lain tidak bisa dilunasi
 * dari sini (ditandai di layar).
 *
 * Pengiriman selalu lewat endpoint sinkronisasi yang idempoten — jalur yang sama
 * baik saat online maupun saat antrean menyusul belakangan.
 */

const KUNCI_ANTREAN = 'nampan.antrean';
const KUNCI_BEKAL = 'nampan.bekal';
const KUNCI_BILL = 'nampan.bill';
const KUNCI_KERANJANG = 'nampan.keranjang';
const KUNCI_SUARA = 'nampan.suara';

/**
 * Tiga keadaan suara, bukan dua.
 *
 * 'ucapan' — mengucap "berhasil"/"gagal". Tidak perlu dihafal artinya.
 * 'bip'    — dua nada berbeda. Jauh lebih pendek: saat memindai puluhan barang
 *            berturut-turut, ucapan sepanjang setengah detik mulai tertinggal dan
 *            saling memotong, sedangkan bip tidak.
 * 'mati'   — untuk warung yang buka sebelum subuh dan dua kasir berdampingan.
 */
const MODE_SUARA = ['ucapan', 'bip', 'mati'];
const KUNCI_URUTAN = 'nampan.urutan';

function bacaJson(kunci, bawaan) {
    try {
        const isi = localStorage.getItem(kunci);

        return isi === null ? bawaan : JSON.parse(isi);
    } catch {
        // Data rusak dari versi sebelumnya tidak boleh membuat layar gagal dimuat.
        return bawaan;
    }
}

function tulisJson(kunci, nilai) {
    try {
        localStorage.setItem(kunci, JSON.stringify(nilai));
    } catch {
        // Kuota penuh atau mode privat: transaksi berjalan tetap bisa dikirim.
    }
}

/** UUID v4, dibuat di perangkat supaya jadi kunci idempotensi saat sinkronisasi. */
function uuid() {
    if (crypto.randomUUID) {
        return crypto.randomUUID();
    }

    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = (Math.random() * 16) | 0;

        return (c === 'x' ? r : (r & 0x3) | 0x8).toString(16);
    });
}

function waktuSekarang() {
    return new Date().toISOString().slice(0, 19).replace('T', ' ');
}

export function pasangKasir() {
    document.addEventListener('alpine:init', () => {
        window.Alpine.data('kasir', (konfigurasi) => ({
            outletId: konfigurasi.outletId,
            deviceId: konfigurasi.deviceId,
            sesiId: konfigurasi.sesiId,
            urlKatalog: konfigurasi.urlKatalog,
            urlSinkron: konfigurasi.urlSinkron,
            bekalAwal: konfigurasi.bekalAwal ?? {},

            katalog: [],
            pelanggan: [],
            modeTersedia: ['langsung'],

            mode: 'langsung',
            billAktif: null,
            billLokal: [],

            keranjang: [],
            pencarian: '',
            kategoriAktif: 'Semua',

            /** Hasil pindaian terakhir: { nilai, nama, dikenal }. Bertahan sampai
             *  pindaian berikutnya, supaya kabarnya tidak hilang saat panel terbuka. */
            pindaiTerakhir: null,

            pembayaran: [],
            pelangganId: '',
            jatuhTempo: '',
            labelBaru: '',
            billMauDibatalkan: null,
            estimasiBaru: '',

            /*
             * Satu sumber untuk metode pembayaran: dipakai tombol pilih di layar dan
             * penamaan di struk. Sebelumnya nama metode ditulis ulang di dua tempat
             * pada Blade, dan itu cara termudah membuat struk menyebut metode yang
             * berbeda dari yang ditekan kasir.
             *
             * KODE HARUS ADA DI enum App\Enums\PaymentMethod. Endpoint sinkronisasi
             * memvalidasinya dengan enum itu, jadi kode yang tidak dikenal membuat
             * transaksinya ditolak 422 dan tertahan di antrean — kasir baru tahu jauh
             * setelah pelanggan pergi. Kesesuaiannya dijaga oleh MetodePembayaranTest.
             */
            metodeTersedia: [
                { kode: 'cash', label: 'Tunai' },
                { kode: 'qris', label: 'QRIS' },
                { kode: 'ewallet', label: 'E-Wallet' },
                { kode: 'edc', label: 'Kartu' },
                { kode: 'kasbon', label: 'Kasbon' },
            ],

            /**
             * Bunyi umpan balik pindaian.
              *
             * Bisa dimatikan DAN pilihannya diingat: warung yang buka sebelum subuh, kasir
             * yang memakai earphone, atau ruang sempit dengan dua kasir berdampingan
             * adalah keadaan nyata di mana bunyi justru mengganggu. Bunyi yang tidak bisa
             * dimatikan akan membuat orang mengecilkan volume perangkat — dan ikut
             * mematikan tanda-tanda lain yang lebih penting.
             */
            suara: 'ucapan',

            antrean: [],
            online: navigator.onLine,
            sibuk: false,
            pesan: null,
            jenisPesan: 'info',
            strukTerakhir: null,

            init() {
                this.antrean = bacaJson(KUNCI_ANTREAN, []);
                this.billLokal = bacaJson(KUNCI_BILL, []);

                /*
                 * Keranjang ikut disimpan di perangkat.
                 *
                 * Tanpa ini, pesanan yang sudah diketik hilang begitu halaman dimuat
                 * ulang atau kasir berpindah ke beranda — padahal di warung, layar
                 * tersenggol dan tombol kembali tertekan itu kejadian biasa.
                */
                this.keranjang = bacaJson(KUNCI_KERANJANG, []);
                /*
                 * Pilihan lama masih berbentuk true/false — versi sebelum mode ucapan ada.
                 * Dipetakan, bukan diabaikan: kasir yang sudah mematikan suaranya tidak
                 * boleh tiba-tiba berbunyi lagi hanya karena aplikasinya diperbarui.
                 */
                const tersimpan = bacaJson(KUNCI_SUARA, 'ucapan');

                this.suara = MODE_SUARA.includes(tersimpan)
                    ? tersimpan
                    : (tersimpan === false ? 'mati' : 'ucapan');

                const bekal = Object.keys(this.bekalAwal).length > 0
                    ? this.bekalAwal
                    : bacaJson(KUNCI_BEKAL, {});

                this.terapkanBekal(bekal);

                if (Object.keys(this.bekalAwal).length > 0) {
                    tulisJson(KUNCI_BEKAL, this.bekalAwal);
                }

                this.mode = this.modeTersedia[0] ?? 'langsung';
                this.resetPembayaran();

                window.addEventListener('online', () => {
                    this.online = true;
                    this.kirimAntrean();
                });

                window.addEventListener('offline', () => {
                    this.online = false;
                    this.beriPesan('Jaringan mati. Transaksi tetap bisa dilanjutkan.', 'offline');
                });

                if (this.online && this.antrean.length > 0) {
                    this.kirimAntrean();
                }

                /*
                 * Jaring pengaman. Titik yang mengubah tagihan sudah memanggil
                 * sinkronOtomatis() masing-masing, tapi pengawas ini menangkap jalur
                 * yang terlewat — misalnya isi bill yang berubah dari tempat lain.
                 * $watch hanya ada saat Alpine yang menjalankan komponen ini.
                */
                if (typeof this.$watch === 'function') {
                    this.$watch('totalTagihan', () => this.sinkronOtomatis());
                }
            },

            terapkanBekal(bekal) {
                this.katalog = bekal.produk ?? [];
                this.pelanggan = bekal.pelanggan ?? [];
                this.modeTersedia = bekal.mode ?? ['langsung'];
            },

            /**
             * Menyegarkan bekal tanpa memuat ulang halaman. Dipicu manual, bukan
             * otomatis, supaya harga tidak berubah diam-diam di tengah transaksi.
             */
            async segarkan() {
                if (! this.online) {
                    this.beriPesan('Tidak bisa menyegarkan saat offline.', 'offline');

                    return;
                }

                try {
                    const respons = await fetch(this.urlKatalog, { headers: { Accept: 'application/json' } });

                    if (! respons.ok) {
                        this.beriPesan('Gagal menyegarkan.', 'galat');

                        return;
                    }

                    const data = await respons.json();
                    this.terapkanBekal(data);
                    tulisJson(KUNCI_BEKAL, data);
                    this.beriPesan('Data disegarkan.', 'sukses');
                } catch {
                    this.beriPesan('Gagal menyegarkan.', 'galat');
                }
            },

            get kategori() {
                return ['Semua', ...new Set(this.katalog.map((p) => p.kategori))];
            },

            get produkTampil() {
                const kata = this.pencarian.trim().toLowerCase();
                const kategori = this.kategoriAktif;

                return this.katalog.filter((p) => {
                    const cocokKategori = kategori === 'Semua' || p.kategori === kategori;
                    const cocokKata = kata === '' || p.nama.toLowerCase().includes(kata);

                    return cocokKategori && cocokKata;
                });
            },

            /**
             * Jumlah produk di satu kategori, MENGIKUTI kata pencarian yang sedang
             * aktif. Angka yang mengabaikan pencarian akan menjanjikan isi yang tidak
             * ada — kasir menekan "Minuman 8" lalu menemukan gridnya kosong.
             */
            jumlahKategori(nama) {
                const kata = this.pencarian.trim().toLowerCase();

                return this.katalog.filter((p) => (nama === 'Semua' || p.kategori === nama)
                    && (kata === '' || p.nama.toLowerCase().includes(kata))).length;
            },

            get pakaiBill() {
                return this.mode !== 'langsung';
            },

            /*
             * Berpindah mode melepas bill yang dipilih, tapi TIDAK membuang keranjang.
             * Keranjang adalah pesanan yang belum ditugaskan ke mana pun; membuangnya
             * berarti kasir kehilangan apa yang sudah diketik hanya karena salah tekan
             * satu pil mode.
             */
            gantiMode(m) {
                this.mode = m;
                this.billAktif = null;
                this.resetPembayaran();
            },

            /* ── Bill ──────────────────────────────────────────────────────── */

            /**
             * HANYA bill yang dibuka di perangkat ini.
             *
             * Bill milik perangkat lain sengaja tidak ditampilkan sama sekali.
             * Sebelumnya ia muncul dalam keadaan tidak bisa ditekan, dan itu justru
             * menyesatkan: kasir mengeklik "Meja 1" milik perangkat lain, tidak ada
             * yang terjadi, lalu produk yang ditekan berikutnya masuk ke bill yang
             * masih terpilih sebelumnya — pesanannya menempel di meja yang salah.
             *
             * Karena isi bill disimpan di perangkat yang membukanya, bill perangkat
             * lain memang tidak bisa dilayani dari sini. Menyembunyikannya membuat
             * batas itu tegas, bukan setengah-setengah.
             */
            get daftarBill() {
                return this.billLokal.filter((b) => b.mode === this.mode);
            },

            /**
             * @returns {boolean} true kalau bill benar-benar dibuat. Dipakai layar untuk
             *   memutuskan apakah dialog bill boleh ditutup — menutupnya saat label
             *   masih kosong berarti kasir kehilangan formulir tanpa tahu sebabnya.
             */
            bukaBill() {
                const label = this.labelBaru.trim();

                if (label === '') {
                    this.beriPesan('Isi dulu nomor meja atau nama pelanggan.', 'galat');

                    return false;
                }

                const bill = {
                    id: uuid(),
                    mode: this.mode,
                    status: 'terbuka',
                    label,
                    customer_id: this.pelangganId || null,
                    dibuka_pada: waktuSekarang(),
                    estimasi_selesai: this.estimasiBaru ? this.estimasiBaru.replace('T', ' ') + ':00' : null,
                    pesanan: [],
                };

                this.billLokal.unshift(bill);
                this.simpanBill();

                this.labelBaru = '';
                this.estimasiBaru = '';
                this.pelangganId = '';
                this.billAktif = bill.id;

                this.beriPesan('Bill "' + label + '" dibuka.', 'sukses');

                return true;
            },

            /*
             * Keranjang DIPERTAHANKAN saat berpindah bill.
             *
             * Dulu di sini ada this.keranjang = [] dan itu membuang pesanan yang sudah
             * diketik tanpa peringatan: kasir mencatat pesanan meja 10, membuka bill
             * barunya, lalu pesanannya lenyap. Keranjang baru berpindah kepemilikan
             * saat tombol "Masukkan ke ..." ditekan — sampai saat itu ia menunggu bill
             * mana pun yang dipilih.
             */
            pilihBill(id) {
                const bill = this.billLokal.find((b) => b.id === id);

                if (! bill) {
                    return;
                }

                this.billAktif = id;
                this.pelangganId = bill.customer_id ?? '';
                this.sinkronOtomatis();
            },

            /*
             * billAktif DIBACA DI LUAR callback dengan sengaja.
             *
             * Reaktivitas Alpine mendaftarkan dependensi dari properti yang benar-benar
             * dibaca saat getter dijalankan. Kalau billAktif hanya disentuh di dalam
             * callback find(), maka ketika billLokal masih kosong callback itu tidak
             * pernah jalan — billAktif tidak terdaftar sebagai dependensi, dan tampilan
             * berhenti mengikuti perubahan pilihan bill. Gejalanya: isi bill baru muncul
             * satu langkah terlambat, seolah-olah DOM-nya ketinggalan.
             */
            get billTerpilih() {
                return this.billLokal.find((b) => b.id === this.billAktif) ?? null;
            },

            get totalBill() {
                const bill = this.billTerpilih;

                return bill ? bill.pesanan.reduce((j, i) => j + i.harga * i.qty, 0) : 0;
            },

            /** Memindahkan isi keranjang menjadi pesanan yang menempel pada bill. */
            tambahKeBill() {
                const bill = this.billTerpilih;

                if (! bill || this.keranjang.length === 0) {
                    return;
                }

                this.keranjang.forEach((baris) => {
                    const ada = bill.pesanan.find((p) => p.id === baris.id);

                    if (ada) {
                        ada.qty = this.bulatkan(ada.qty + baris.qty);

                        return;
                    }

                    bill.pesanan.push({ ...baris });
                });

                this.keranjang = [];
                this.simpanBill();
                this.simpanKeranjang();
                this.sinkronOtomatis();
                this.beriPesan('Pesanan masuk ke bill.', 'sukses');
            },

            /**
             * Berhenti melayani bill ini tanpa menutupnya.
             *
             * Bill tetap terbuka beserta seluruh pesanannya — kasir hanya berpindah
             * perhatian, misalnya karena ada pesanan meja lain yang harus dicatat
             * lebih dulu. Keranjang tidak disentuh; pembayaran yang sudah diketik
             * untuk bill ini dibuang karena angkanya milik tagihan yang berbeda.
             */
            lepasBill() {
                this.billAktif = null;
                this.resetPembayaran();
            },

            /**
             * Menghapus bill beserta pesanannya dari perangkat ini.
             *
             * Dipakai untuk bill yang salah dibuka. Tidak ada stok yang perlu
             * dikembalikan: stok baru bergerak ketika transaksi lunas disinkronkan,
             * dan bill yang belum dibayar belum pernah menyentuhnya.
             *
             * Layar WAJIB meminta konfirmasi dulu untuk bill yang sudah berisi —
             * fungsi ini tidak bertanya, ia hanya menjalankan.
             */
            batalkanBill(id) {
                const bill = this.billLokal.find((b) => b.id === id);

                if (! bill) {
                    return false;
                }

                this.billLokal = this.billLokal.filter((b) => b.id !== id);
                this.simpanBill();

                /*
                 * Perubahan status Mode C yang masih menunggu kiriman ikut dibuang.
                 * Membiarkannya berarti server membuatkan bill yang sudah tidak ada
                 * di perangkat mana pun — bill hantu yang tak bisa ditutup siapa pun.
                */
                this.antrean = this.antrean.filter((paket) => {
                    const adaTransaksi = (paket.transactions ?? []).length > 0;
                    const hanyaBillIni = (paket.bills ?? []).every((b) => b.id === id);

                    return adaTransaksi || ! hanyaBillIni;
                });
                tulisJson(KUNCI_ANTREAN, this.antrean);

                if (this.billAktif === id) {
                    this.billAktif = null;
                    this.resetPembayaran();
                }

                this.billMauDibatalkan = null;
                this.beriPesan('Bill "' + bill.label + '" dibatalkan.', 'info');

                return true;
            },

            /** Mode C: memajukan status titipan. Server menolak status yang mundur. */
            majukanStatus(bill) {
                const alur = ['terbuka', 'diproses', 'siap_diambil'];
                const posisi = alur.indexOf(bill.status);

                if (posisi === -1 || posisi === alur.length - 1) {
                    return;
                }

                bill.status = alur[posisi + 1];
                this.simpanBill();
                this.antreanTambah({ bills: [this.bentukBill(bill)], transactions: [] });
                this.beriPesan('Status jadi ' + this.labelStatus(bill.status) + '.', 'sukses');
            },

            /**
             * Keterangan pendek di bawah nama bill.
             *
             * Untuk bill meja, status "Terbuka" tidak membawa informasi: bill yang sudah
             * dibayar hilang dari daftar, jadi semua yang tampil pasti terbuka. Yang
             * berguna justru berapa item yang sudah masuk dan sejak kapan meja itu
             * dilayani. Untuk titipan, statusnya memang berpindah, jadi status yang
             * ditampilkan.
             */
            ringkasanBill(bill) {
                if (! bill) {
                    return '';
                }

                if (bill.mode === 'pesan_antar') {
                    const estimasi = bill.estimasi_selesai
                        ? ' · selesai ' + bill.estimasi_selesai.slice(11, 16)
                        : '';

                    return this.labelStatus(bill.status) + estimasi;
                }

                const jumlah = bill.pesanan.reduce((j, i) => j + i.qty, 0);
                const bagian = jumlah > 0
                    ? this.tampilQty(jumlah) + ' item'
                    : 'belum ada pesanan';
                const jam = bill.dibuka_pada ? ' · sejak ' + bill.dibuka_pada.slice(11, 16) : '';

                return bagian + jam;
            },

            labelMetode(kode) {
                return this.metodeTersedia.find((m) => m.kode === kode)?.label ?? kode;
            },

            labelStatus(status) {
                // "Diterima" bermakna untuk titipan (laundry, depot), tapi menyesatkan
                // untuk bill meja — di sana artinya bill itu masih terbuka.
                if (status === 'terbuka') {
                    return this.mode === 'pesan_antar' ? 'Diterima' : 'Terbuka';
                }

                return {
                    terbuka: 'Terbuka',
                    diproses: 'Diproses',
                    siap_diambil: 'Siap diambil',
                    selesai_dibayar: 'Selesai',
                }[status] ?? status;
            },

            bentukBill(bill, status = null) {
                return {
                    id: bill.id,
                    mode: bill.mode,
                    status: status ?? bill.status,
                    label: bill.label,
                    customer_id: bill.customer_id,
                    dibuka_pada: bill.dibuka_pada,
                    estimasi_selesai: bill.estimasi_selesai,
                };
            },

            simpanBill() {
                tulisJson(KUNCI_BILL, this.billLokal);
            },

            simpanKeranjang() {
                tulisJson(KUNCI_KERANJANG, this.keranjang);
            },

            /* ── Pemindaian barcode ────────────────────────────────────────── */

            /**
             * Bunyi pendek: berhasil atau gagal.
             *
             * Ini bukan hiasan. Kasir kelontong memindai sambil memegang barang dan
             * melihat pembeli, bukan melihat layar — bunyilah satu-satunya kabar yang
             * sampai tanpa menoleh. Yang paling penting justru bunyi GAGAL: tanpa itu,
             * barang yang tidak terbaca akan terlewat dan pembeli membawanya pulang tanpa
             * pernah tercatat.
             */
            bunyi(jenis) {
                if (this.suara === 'mati') {
                    return;
                }

                // Opsional: di lingkungan uji tidak ada Web Audio, dan itu bukan galat.
                window.bunyiNampan?.(jenis, this.suara);
            },

            /** Berputar: ucapan → bip → mati → ucapan. */
            gantiSuara() {
                const posisi = MODE_SUARA.indexOf(this.suara);

                this.suara = MODE_SUARA[(posisi + 1) % MODE_SUARA.length];
                tulisJson(KUNCI_SUARA, this.suara);

                // Dicontohkan begitu dipilih supaya kasir langsung tahu bunyinya seperti apa
                // dan seberapa kencang — bukan menemukannya di pindaian pertama di depan
                // pembeli.
                if (this.suara !== 'mati') {
                    window.bunyiNampan?.('sukses', this.suara);
                }
            },

            labelSuara() {
                return { ucapan: 'Suara: ucapan', bip: 'Suara: bip', mati: 'Suara: mati' }[this.suara];
            },

            /**
             * Mencari produk dari kode yang dipindai.
             *
             * Dicocokkan ke katalog yang SUDAH ADA DI PERANGKAT, bukan ditanyakan ke
             * server. Menanyakan ke server berarti memindai berhenti bekerja persis saat
             * jaringan mati — keadaan yang paling harus tetap jalan di kasir.
             *
             * Barcode diutamakan, SKU jadi cadangan: barcode berasal dari pabrik dan
             * pasti unik, SKU adalah kode buatan sendiri yang lebih mungkin tumpang
             * tindih dengan angka lain.
             */
            cariKode(kode) {
                const bersih = String(kode ?? '').trim();

                if (bersih === '') {
                    return null;
                }

                const sama = (nilai) => String(nilai ?? '').trim().toLowerCase() === bersih.toLowerCase();

                return this.katalog.find((p) => p.barcode && sama(p.barcode))
                    ?? this.katalog.find((p) => p.sku && sama(p.sku))
                    ?? null;
            },

            /**
             * Hasil pindaian: barang langsung masuk keranjang.
             *
             * Memindai barang yang sama dua kali menambah jumlahnya, sama seperti menekan
             * tilenya dua kali — itulah cara kelontong menghitung tiga bungkus mi yang
             * sama tanpa mengetik angka.
             *
             * @param {boolean} sunyi  true saat panel kamera terbuka: panel itu sudah
             *        menampilkan hasilnya sendiri, jadi pemberitahuan tambahan hanya
             *        menumpuk sepuluh toast di atas layar yang sedang dipakai.
             * @returns {boolean} false berarti kodenya tidak dikenal.
             */
            terimaKode(kode, sunyi = false) {
                const produk = this.cariKode(kode);

                /*
                 * Hasil pindaian terakhir disimpan, bukan hanya ditoastkan.
                 *
                 * Panel kamera menutupi layar dan pemberitahuan biasa muncul di
                 * belakangnya, jadi selama memindai berturut-turut kasir tidak akan
                 * melihat kabar apa pun. Keadaan ini ditampilkan DI DALAM panel, dan
                 * bertahan sampai pindaian berikutnya — bukan hilang setelah beberapa
                 * detik. Kode yang gagal dikenali justru yang paling perlu tetap terlihat:
                 * itu yang harus dicatat atau diketik ulang.
                */
                this.pindaiTerakhir = {
                    nilai: String(kode).trim(),
                    nama: produk?.nama ?? null,
                    dikenal: produk !== null,
                };

                if (! produk) {
                    /*
                     * Barang yang belum terdaftar itu kejadian sehari-hari di kelontong
                     * (kiriman baru belum dimasukkan). Pesannya menyebutkan kodenya —
                     * supaya bisa dicatat atau diketik ulang — dan menyebut ke mana harus
                     * pergi, bukan cuma mengatakan "tidak ditemukan".
                    */
                    // Bunyi TETAP dibunyikan walau pesannya ditahan: `sunyi` hanya
                    // menahan pemberitahuan di layar yang sudah tergantikan oleh panel,
                    // sedangkan bunyi justru yang paling dibutuhkan saat memindai
                    // berturut-turut tanpa melihat layar.
                    this.bunyi('gagal');

                    if (! sunyi) {
                        this.beriPesan(
                            'Barcode ' + String(kode).trim() + ' belum terdaftar. Daftarkan dulu di Kelola › Produk.',
                            'peringatan',
                        );
                    }

                    return false;
                }

                this.tambah(produk);
                this.bunyi('sukses');

                if (! sunyi) {
                    this.beriPesan(produk.nama + ' masuk keranjang.', 'sukses');
                }

                return true;
            },

            /**
             * Enter di kolom cari.
             *
             * Kolom ini menerima DUA hal, karena pemindai USB "mengetik" ke kolom yang
             * sedang terfokus dan kasir juga mengetik nama barang di kolom yang sama:
             *
             * 1. Kode yang cocok → barangnya masuk keranjang, kolomnya dikosongkan.
             * 2. Kata pencarian yang menyisakan tepat satu hasil → hasil itu yang masuk.
             *    ("nasi" + Enter lebih cepat daripada mengetik lalu mengarahkan tangan
             *    ke tile-nya.)
             *
             * Kalau keduanya tidak terpenuhi, layar SENGAJA diam — kecuali yang diketik
             * memang berbentuk barcode. Mengeluh setiap kali kasir menekan Enter di
             * tengah mengetik nama barang hanya melatih orang mengabaikan pesan.
             */
            pindaiDariPencarian() {
                const kata = this.pencarian.trim();

                if (kata === '') {
                    return;
                }

                if (this.cariKode(kata)) {
                    this.terimaKode(kata);
                    this.pencarian = '';

                    return;
                }

                if (this.produkTampil.length === 1) {
                    const produk = this.produkTampil[0];

                    this.tambah(produk);
                    this.bunyi('sukses');
                    this.beriPesan(produk.nama + ' masuk keranjang.', 'sukses');
                    this.pencarian = '';

                    return;
                }

                // Deretan angka sepanjang ini tidak mungkin nama barang; itu pindaian
                // yang gagal, dan kasir perlu tahu.
                if (/^\d{6,}$/.test(kata)) {
                    this.terimaKode(kata);
                }
            },

            /* ── Keranjang ─────────────────────────────────────────────────── */

            tambah(produk) {
                const ada = this.keranjang.find((b) => b.id === produk.id);

                if (ada) {
                    ada.qty = this.bulatkan(ada.qty + (produk.pecahan ? 0.5 : 1));
                    this.simpanKeranjang();
                    this.sinkronOtomatis();

                    return;
                }

                this.keranjang.push({
                    id: produk.id,
                    nama: produk.nama,
                    harga: produk.harga,
                    pecahan: produk.pecahan,
                    qty: produk.pecahan ? 0.5 : 1,
                });

                this.simpanKeranjang();
                this.sinkronOtomatis();
            },

            ubahQty(daftar, baris, delta) {
                const langkah = baris.pecahan ? 0.5 : 1;
                const baru = this.bulatkan(baris.qty + delta * langkah);

                if (baru <= 0) {
                    const posisi = daftar.indexOf(baris);

                    if (posisi > -1) {
                        daftar.splice(posisi, 1);
                    }

                    this.simpanBill();
                    this.simpanKeranjang();
                    this.sinkronOtomatis();

                    return;
                }

                baris.qty = baru;
                this.simpanBill();
                this.simpanKeranjang();
                this.sinkronOtomatis();
            },

            kosongkan() {
                this.keranjang = [];
                this.simpanKeranjang();
                this.resetPembayaran();
            },

            /** Menghindari galat pembulatan biner pada satuan pecahan (0,1 + 0,2). */
            bulatkan(n) {
                return Math.round(n * 1000) / 1000;
            },

            get totalKeranjang() {
                return this.keranjang.reduce((j, b) => j + b.harga * b.qty, 0);
            },

            /*
             * Dua keadaan kosong panel, dihitung di sini dan BUKAN sebagai ekspresi
             * berantai di Blade.
             *
             * Alasannya terbukti dari pengujian di peramban: ekspresi seperti
             * `(! pakaiBill || billTerpilih) && keranjang.length === 0 && totalBill === 0`
             * berhenti di tengah ketika bagian pertamanya sudah menentukan hasil,
             * sehingga bagian sisanya tidak pernah dibaca — dan yang tidak dibaca tidak
             * terdaftar sebagai dependensi reaktif. Akibatnya pesan panduan tidak muncul
             * saat bill baru dibuka.
             *
             * Di sini semua nilai dibaca ke variabel lokal lebih dulu, jadi tidak ada
             * cabang yang bisa menyembunyikan dependensi.
             */
            get panelTanpaBill() {
                const pakai = this.pakaiBill;
                const bill = this.billTerpilih;
                const item = this.keranjang.length;

                return pakai && bill === null && item === 0;
            },

            get panelKosong() {
                const pakai = this.pakaiBill;
                const bill = this.billTerpilih;
                const item = this.keranjang.length;
                const isiBill = this.totalBill;

                return (! pakai || bill !== null) && item === 0 && isiBill === 0;
            },

            /** Yang ditagih: isi bill kalau sedang melunasi bill, kalau tidak keranjang. */
            get totalTagihan() {
                return this.pakaiBill && this.billTerpilih ? this.totalBill : this.totalKeranjang;
            },

            /* ── Pembayaran ────────────────────────────────────────────────── */

            /*
             * otomatis: baris ini mengikuti sisa tagihan dengan sendirinya.
             *
             * Dulu jumlahnya baru terisi ketika medannya disentuh (@focus), jadi kasir
             * harus mengetuk kolom lebih dulu hanya untuk melihat angka yang sudah
             * pasti. Sekarang baris pertama selalu menampilkan tagihan yang harus
             * ditutup, dan berhenti mengikuti begitu kasir mengetik sendiri.
             */
            resetPembayaran() {
                this.pembayaran = [{ metode: 'cash', jumlah: 0, diterima: 0, otomatis: true }];
                this.pelangganId = '';
                this.jatuhTempo = '';
                this.sinkronOtomatis();
            },

            /** Split payment: tunai + QRIS (atau kasbon) dalam satu transaksi. */
            tambahPembayaran() {
                /*
                 * Baris yang sudah ada dibekukan pada nilainya sekarang, dan baris baru
                 * yang menutup sisanya. Kalau dua baris sama-sama otomatis, tidak ada
                 * aturan yang jelas soal siapa menutup berapa.
                */
                this.pembayaran.forEach((p) => { p.otomatis = false; });
                this.pembayaran.push({ metode: 'qris', jumlah: 0, diterima: 0, otomatis: true });
                this.sinkronOtomatis();
            },

            hapusPembayaran(i) {
                if (this.pembayaran.length > 1) {
                    this.pembayaran.splice(i, 1);

                    // Baris terakhir kembali mengikuti sisa, kalau tidak sisa tagihan
                    // menggantung tanpa ada yang menutupnya.
                    this.pembayaran.at(-1).otomatis = true;
                    this.sinkronOtomatis();
                }
            },

            /**
             * Baris yang diketik kasir berhenti mengikuti sisa tagihan — dan perannya
             * sebagai penutup sisa DIPINDAHKAN ke baris lain.
             *
             * Tanpa pemindahan itu, bayar terpisah menghasilkan jumlah yang melebihi
             * tagihan: baris pertama membeku di nominal penuh, kasir mengetik nominal
             * kedua, dan tidak ada yang mengurangi baris pertama. Yang terlihat kasir:
             * "saya menambah cara bayar, kok totalnya justru bertambah".
             *
             * Aturannya sekarang: SELALU ada tepat satu baris penutup sisa. Mengetik di
             * baris penutup memindahkan peran itu ke baris lain, sehingga jumlah seluruh
             * baris tetap sama dengan tagihan.
             */
            lepasOtomatis(i) {
                this.pembayaran[i].otomatis = false;

                if (this.pembayaran.length < 2) {
                    return;
                }

                const masihAda = this.pembayaran.some((p) => p.otomatis);

                if (! masihAda) {
                    // Baris terakhir yang bukan baris ini yang mengambil alih; kalau
                    // yang diketik justru baris terakhir, giliran baris sebelumnya.
                    const lain = this.pembayaran.findLastIndex((_, idx) => idx !== i);
                    this.pembayaran[lain].otomatis = true;
                }

                this.sinkronOtomatis();
            },

            /** Menutup sisa tagihan otomatis supaya kasir tidak menghitung manual. */
            isiSisa(i) {
                const lain = this.pembayaran.reduce(
                    (j, p, idx) => (idx === i ? j : j + Number(p.jumlah || 0)),
                    0,
                );

                this.pembayaran[i].jumlah = Math.max(0, this.totalTagihan - lain);
            },

            /**
             * Menyelaraskan baris otomatis dengan tagihan yang sedang berjalan.
             * Aman dipanggil berulang kali — hasilnya hanya bergantung pada keadaan
             * sekarang, bukan pada berapa kali ia dijalankan.
             */
            sinkronOtomatis() {
                const i = this.pembayaran.findIndex((p) => p.otomatis);

                if (i === -1) {
                    return;
                }

                this.isiSisa(i);
            },

            get totalDibayar() {
                return this.pembayaran.reduce((j, p) => j + Number(p.jumlah || 0), 0);
            },

            /** Positif = kelebihan bayar, negatif = masih kurang. */
            get selisihBayar() {
                return this.totalDibayar - this.totalTagihan;
            },

            get adaKasbon() {
                return this.pembayaran.some((p) => p.metode === 'kasbon');
            },

            get totalTunai() {
                return this.pembayaran
                    .filter((p) => p.metode === 'cash')
                    .reduce((j, p) => j + Number(p.jumlah || 0), 0);
            },

            get uangDiterima() {
                return this.pembayaran
                    .filter((p) => p.metode === 'cash')
                    .reduce((j, p) => j + Number(p.diterima || 0), 0);
            },

            get kembalian() {
                return Math.max(0, this.uangDiterima - this.totalTunai);
            },

            get bisaBayar() {
                if (this.sibuk || ! this.sesiId || this.totalTagihan <= 0) {
                    return false;
                }

                // Bill dari perangkat lain tidak punya itemnya di sini.
                if (this.pakaiBill && ! this.billTerpilih) {
                    return false;
                }

                /*
                 * Keranjang yang belum dimasukkan ke bill TIDAK ikut ditagih —
                 * totalTagihan hanya menghitung isi bill. Membiarkan tombol Bayar
                 * aktif dalam keadaan ini berarti pesanan yang sudah diketik hilang
                 * tanpa jejak begitu bill ditutup, dan warung kehilangan uangnya.
                */
                if (this.pakaiBill && this.keranjang.length > 0) {
                    return false;
                }

                if (this.totalDibayar !== this.totalTagihan) {
                    return false;
                }

                // Kasbon tanpa pelanggan tidak bisa ditagih ke siapa pun.
                if (this.adaKasbon && ! this.pelangganId) {
                    return false;
                }

                return this.uangDiterima >= this.totalTunai;
            },

            /* ── Simpan transaksi ──────────────────────────────────────────── */

            /**
             * Nomor transaksi dibuat di perangkat karena saat offline tidak ada server
             * yang bisa memberi nomor. Kode perangkat diselipkan supaya dua kasir yang
             * sedang offline bersamaan tidak menghasilkan nomor kembar.
             */
            nomorTransaksi() {
                const t = new Date();
                const ymd = String(t.getFullYear())
                    + String(t.getMonth() + 1).padStart(2, '0')
                    + String(t.getDate()).padStart(2, '0');

                const kode = (localStorage.getItem('nampan.perangkat') ?? 'LOKAL')
                    .replace(/[^A-Za-z0-9]/g, '').slice(-4).toUpperCase() || 'LOKAL';

                const urutan = bacaJson(KUNCI_URUTAN, {});
                urutan[ymd] = (urutan[ymd] ?? 0) + 1;
                tulisJson(KUNCI_URUTAN, urutan);

                return 'TRX-' + ymd + '-' + kode + '-' + String(urutan[ymd]).padStart(4, '0');
            },

            async bayar() {
                if (! this.bisaBayar) {
                    return;
                }

                this.sibuk = true;

                const bill = this.pakaiBill ? this.billTerpilih : null;
                const item = bill ? bill.pesanan : this.keranjang;
                const total = this.totalTagihan;

                const transaksi = {
                    id: uuid(),
                    nomor_transaksi: this.nomorTransaksi(),
                    mode: this.mode,
                    status: this.adaKasbon ? 'belum_lunas' : 'lunas',
                    subtotal: total,
                    total,
                    // Asal dicatat saat transaksi DIBUAT, bukan saat terkirim. Kalau
                    // dicatat saat terkirim, transaksi yang sempat tertahan akan
                    // terlihat seolah-olah mulus.
                    origin: this.online ? 'online' : 'offline',
                    waktu_transaksi: waktuSekarang(),
                    dibuat_offline_pada: waktuSekarang(),
                    cash_session_id: this.sesiId,
                    bill_id: bill ? bill.id : null,
                    customer_id: this.pelangganId || null,
                    tanggal_jatuh_tempo: this.adaKasbon && this.jatuhTempo ? this.jatuhTempo : null,
                    items: item.map((b) => ({
                        product_id: b.id,
                        nama_produk: b.nama,
                        qty: b.qty,
                        harga_satuan: b.harga,
                        subtotal: this.bulatkan(b.harga * b.qty),
                    })),
                    payments: this.pembayaran
                        .filter((p) => Number(p.jumlah) > 0)
                        .map((p) => ({
                            metode: p.metode,
                            jumlah: Number(p.jumlah),
                            jumlah_diterima: p.metode === 'cash' ? Number(p.diterima || 0) : null,
                            kembalian: p.metode === 'cash' ? this.kembalian : null,
                        })),
                };

                this.strukTerakhir = {
                    nomor: transaksi.nomor_transaksi,
                    waktu: transaksi.waktu_transaksi,
                    label: bill ? bill.label : null,
                    items: transaksi.items,
                    total,
                    pembayaran: transaksi.payments,
                    kembalian: this.kembalian,
                };

                // Bill ditutup dalam kiriman yang sama dengan pelunasannya.
                const bills = bill ? [this.bentukBill(bill, 'selesai_dibayar')] : [];

                this.antreanTambah({ bills, transactions: [transaksi] });

                if (bill) {
                    this.billLokal = this.billLokal.filter((b) => b.id !== bill.id);
                    this.simpanBill();
                    this.billAktif = null;
                }

                this.keranjang = [];
                this.simpanKeranjang();
                this.resetPembayaran();
                this.sibuk = false;

                if (this.online) {
                    await this.kirimAntrean();
                } else {
                    this.beriPesan('Tersimpan di perangkat. ' + this.antrean.length + ' menunggu kirim.', 'offline');
                }
            },

            /**
             * Selalu masuk antrean lebih dulu, baru dikirim. Kalau proses terputus di
             * tengah (browser ditutup, listrik mati), kirimannya tidak hilang.
             */
            antreanTambah(paket) {
                this.antrean.push(paket);
                tulisJson(KUNCI_ANTREAN, this.antrean);
            },

            async kirimAntrean() {
                if (this.antrean.length === 0 || this.sibuk) {
                    return;
                }

                const paket = [...this.antrean];
                const bills = paket.flatMap((p) => p.bills ?? []);
                const transactions = paket.flatMap((p) => p.transactions ?? []);

                /*
                 * Endpoint mensyaratkan minimal satu transaksi. Paket yang hanya berisi
                 * perubahan status bill ditahan di antrean sampai ada transaksi yang
                 * menyertainya — status titipan tidak mendesak sampai bill dilunasi,
                 * dan menambah endpoint terpisah hanya untuk itu berarti dua jalur
                 * tulis yang harus dijaga konsisten.
                */
                if (transactions.length === 0) {
                    this.beriPesan('Perubahan status tersimpan, dikirim bersama transaksi berikutnya.', 'info');

                    return;
                }

                this.sibuk = true;

                try {
                    const respons = await fetch(this.urlSinkron, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            Accept: 'application/json',
                            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                        },
                        body: JSON.stringify({
                            outlet_id: this.outletId,
                            device_id: this.deviceId,
                            bills,
                            transactions,
                        }),
                    });

                    // 419 = token sesi kedaluwarsa, biasanya setelah lama offline.
                    // Antrean TIDAK dibuang; halaman perlu dimuat ulang.
                    if (respons.status === 419) {
                        this.beriPesan('Sesi kedaluwarsa. Muat ulang halaman untuk mengirim antrean.', 'galat');

                        return;
                    }

                    if (! respons.ok && respons.status !== 207) {
                        this.beriPesan('Server menolak sebagian antrean. Dicoba lagi nanti.', 'galat');

                        return;
                    }

                    const hasil = await respons.json();
                    const gagal = new Set((hasil.detail_gagal ?? []).map((g) => g.id));

                    // Yang dibuat maupun duplikat sama-sama sudah tersimpan di server,
                    // jadi keduanya keluar dari antrean. Hanya yang gagal ditahan.
                    this.antrean = this.antrean.filter(
                        (p) => (p.transactions ?? []).some((t) => gagal.has(t.id)),
                    );
                    tulisJson(KUNCI_ANTREAN, this.antrean);

                    if (gagal.size > 0) {
                        this.beriPesan(gagal.size + ' transaksi gagal. Cek dengan pemilik.', 'galat');
                    } else {
                        this.beriPesan('Tersimpan.', 'sukses');
                    }
                } catch {
                    this.online = navigator.onLine;
                    this.beriPesan('Belum terkirim. ' + this.antrean.length + ' menunggu.', 'offline');
                } finally {
                    this.sibuk = false;
                }
            },

            cetakStruk() {
                if (this.strukTerakhir) {
                    window.print();
                }
            },

            /*
             * Diteruskan ke toast bersama (SweetAlert2) supaya seluruh aplikasi memberi
             * kabar dengan cara yang sama. Dipanggil opsional: di lingkungan pengujian
             * Node tidak ada window.toastNampan, dan logika kasir tidak boleh gagal
             * hanya karena lapisan tampilannya tidak ada.
             *
             * this.pesan tetap disimpan — layar kasir memakainya untuk pemberitahuan
             * di dalam halaman, yang tetap terlihat meski toastnya sudah lewat.
             */
            beriPesan(teks, jenis = 'info') {
                this.pesan = teks;
                this.jenisPesan = jenis;

                window.toastNampan?.(teks, jenis);

                window.clearTimeout(this._timer);
                this._timer = window.setTimeout(() => {
                    this.pesan = null;
                }, 4000);
            },

            /*
             * Gambar default untuk produk yang belum punya foto.
             *
             * Dibangun dari nama produknya sendiri — inisial di atas warna yang
             * ditentukan secara tetap oleh nama itu — bukan satu ikon generik yang
             * sama untuk semuanya. Grid kasir dipindai dengan mata dalam hitungan
             * detik; enam kotak identik tidak membantu memilih, sedangkan warna dan
             * huruf yang selalu sama untuk produk yang sama bisa dihafal.
             *
             * Dirender sebagai elemen biasa, bukan permintaan gambar, supaya tetap
             * muncul saat perangkat offline.
             */
            inisial(nama) {
                const kata = String(nama).trim().split(/\s+/).filter((k) => /[a-z0-9]/i.test(k));

                if (kata.length === 0) {
                    return '?';
                }

                if (kata.length === 1) {
                    return kata[0].slice(0, 2).toUpperCase();
                }

                return (kata[0][0] + kata[1][0]).toUpperCase();
            },

            /**
             * Warna tile default. Hash sederhana atas nama produk, bukan angka acak:
             * warna harus SAMA setiap kali layar dimuat, kalau tidak ia justru
             * mengacaukan hafalan kasir.
             */
            warnaProduk(nama) {
                const palet = [
                    'bg-brand-50 text-brand-600',
                    'bg-navy-50 text-navy-700',
                    'bg-hijau/12 text-hijau-tua',
                    'bg-jingga/15 text-jingga-tua',
                    'bg-gray-100 text-gray-800',
                    'bg-brand-100 text-brand-700',
                ];

                let h = 0;

                for (const ch of String(nama)) {
                    h = (h * 31 + ch.charCodeAt(0)) >>> 0;
                }

                return palet[h % palet.length];
            },

            /**
             * Membaca angka dari teks berformat rupiah.
             *
             * Medan uang memakai type="text", bukan number, karena input number tidak
             * bisa menampilkan pemisah ribuan — dan "21000" tanpa pemisah adalah cara
             * termudah salah baca satu nol di depan pelanggan. Semua yang bukan digit
             * dibuang, termasuk titik pemisah yang kita sendiri tambahkan.
             */
            angkaDari(teks) {
                const digit = String(teks).replace(/\D/g, '');

                return digit === '' ? 0 : Number(digit);
            },

            rupiah(nilai) {
                return new Intl.NumberFormat('id-ID').format(Math.round(nilai));
            },

            tampilQty(n) {
                return Number.isInteger(n) ? String(n) : String(n).replace('.', ',');
            },
        }));
    });
}

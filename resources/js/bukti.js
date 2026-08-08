/**
 * Popup foto bukti belanja (kwitansi/struk) — layar owner "Nota belanja".
 *
 * KENAPA popup-nya harus bisa diperbesar, dan kenapa itu bukan hiasan:
 *
 * Sebelum ini fotonya dibuka di TAB BARU, dan alasannya masih berlaku — struk difoto pakai
 * kamera ponsel, tulisannya kecil, dan gambar yang dipaskan ke dalam kotak dialog malah
 * MENGECIL sehingga tetap tidak terbaca. Pemilik proyek memutuskan mau popup, jadi popup-nya
 * dibangun dengan syarat: "pas layar" berarti pas LEBARNYA lalu boleh digulir ke bawah (struk
 * kelontong bisa 3× lebih tinggi daripada lebar), bukan seluruh struk diperkecil sampai
 * hurufnya jadi setitik. Ketukan pada gambarnya berpindah ke UKURAN ASLI, dan saat diperbesar
 * gambarnya bisa digeser. Tautan "buka di tab baru" TIDAK dihapus, cuma dipindahkan ke dalam
 * popup-nya: zoom bawaan peramban selalu lebih baik daripada zoom yang kita tulis sendiri.
 *
 * Yang SENGAJA tidak ada di berkas ini, dan jangan ditambahkan:
 *
 *  - `touch-action` apa pun, dan `preventDefault()` pada peristiwa sentuh. Keduanya
 *    mematikan cubit-perbesar peramban — justru kemampuan yang paling dibutuhkan di HP.
 *    Seretan hanya ditangani untuk penunjuk TETIKUS (`pointerType === 'mouse'`); sentuhan
 *    diserahkan seluruhnya ke peramban.
 *  - pustaka gambar dari luar. Aturan keras nomor 3: tidak ada aset dari CDN.
 *
 * Latar halaman dikunci dengan `position: fixed` pada <body> beserta simpanan posisi
 * gulirnya, BUKAN dengan `overflow: hidden` saja: sesudah popup ditutup, halaman harus
 * berada di tempat yang sama seperti sebelum dibuka. Panel rincian nota juga tetap terbuka,
 * karena seluruh keadaan popup ini hidup di Alpine dan tidak pernah menyentuh server.
 */

/** Seretan lebih pendek dari ini masih dihitung sebagai ketukan, bukan geseran. */
const AMBANG_SERET = 5;

const batasi = (nilai) => Math.min(1, Math.max(0, Number.isFinite(nilai) ? nilai : 0.5));

export function pasangBukti() {
    document.addEventListener('alpine:init', () => {
        window.Alpine.data('lihatBukti', () => ({
            terbuka: false,

            /** false = pas lebar layar (boleh digulir ke bawah), true = ukuran asli fotonya. */
            asli: false,

            /** Posisi gulir halaman saat popup dibuka; dipulihkan apa adanya saat ditutup. */
            gulirY: 0,

            terkunci: false,

            /** Keadaan seretan tetikus; null berarti tidak sedang menyeret. */
            seret: null,

            /**
             * Seretan berakhir dengan peristiwa `click` juga. Tanpa penanda ini, menggeser
             * foto yang sedang diperbesar akan sekalian mengembalikannya ke "pas lebar" —
             * jadi bagian yang baru ditemukan pemiliknya lenyap tepat saat ia melepas tetikus.
             */
            abaikanKlik: false,

            /**
             * Alamat foto yang sedang dibuka.
             *
             * Ada di STATE, bukan dipasang mati di <img src="…">, sejak satu nota bisa punya
             * banyak lampiran: satu popup melayani semua petak, dan yang menentukan isinya
             * adalah petak yang barusan diketuk. Popup per foto berarti sepuluh salinan
             * markup yang sama di satu halaman — dan sepuluh tempat untuk lupa memperbaiki.
             */
            alamat: '',

            /** Nama berkas untuk pembaca layar & tautan "buka di tab baru". */
            judul: '',

            buka(alamat = '', judul = '') {
                if (this.terbuka) {
                    return;
                }

                if (alamat !== '') {
                    this.alamat = alamat;
                    this.judul = judul;
                }

                // Popup tanpa alamat tidak dibuka sama sekali: kotak hitam berisi ikon gambar
                // rusak tidak menjelaskan apa pun, dan orang menekan lagi mengira salah tekan.
                if (this.alamat === '') {
                    return;
                }

                // Selalu mulai dari "pas lebar": popup yang terbuka dalam keadaan diperbesar
                // memperlihatkan sudut acak sebuah struk, dan orang tidak tahu ia sedang
                // melihat bagian mana.
                this.asli = false;
                this.seret = null;
                this.abaikanKlik = false;
                this.terbuka = true;
                this.kunciLatar();
            },

            tutup() {
                if (! this.terbuka) {
                    return;
                }

                this.terbuka = false;
                this.asli = false;
                this.seret = null;
                this.abaikanKlik = false;
                this.lepasLatar();

                /*
                 * Fokus kembali ke foto yang tadi diketuk — bukan ke awal halaman.
                 *
                 * Ditunda satu putaran karena x-trap baru melepas fokusnya sesudah elemen
                 * popup dibuang; memanggil focus() lebih dulu akan ditimpa olehnya.
                 *
                 * `preventScroll` bukan kehati-hatian berlebihan: terukur di peramban, focus()
                 * biasa MENGGULIR pemicunya ke dalam pandangan dan dengan itu membatalkan
                 * pemulihan posisi yang baru saja dikerjakan lepasLatar() satu baris di atas.
                 * Jadi dua perilaku yang keduanya benar saling menghapus, dan yang menang
                 * adalah yang jalan terakhir. Pengembalian fokusnya tetap utuh — yang dibuang
                 * hanya efek sampingnya.
                 */
                const kembalikan = () => this.$refs?.pemicu?.focus?.({ preventScroll: true });

                typeof this.$nextTick === 'function' ? this.$nextTick(kembalikan) : kembalikan();
            },

            /* ── Perbesar ────────────────────────────────────────────────────── */

            /**
             * Ketukan/klik pada fotonya: pas lebar ⇄ ukuran asli.
             *
             * Titik yang diketuk ikut dihitung supaya bagian ITU yang berada di tengah
             * sesudah diperbesar. Tanpa itu, memperbesar selalu melompat ke pojok kiri atas
             * dan orangnya harus mencari lagi baris yang tadi mau ia baca.
             */
            ketukGambar(peristiwa) {
                if (this.abaikanKlik) {
                    this.abaikanKlik = false;

                    return;
                }

                const titik = this.rasioTitik(peristiwa);

                this.asli = ! this.asli;

                const pusatkan = () => this.pusatkan(titik.x, titik.y);

                typeof this.$nextTick === 'function' ? this.$nextTick(pusatkan) : pusatkan();
            },

            /** Posisi ketukan sebagai rasio 0–1 terhadap fotonya. */
            rasioTitik(peristiwa) {
                const kotak = this.$refs?.gambar?.getBoundingClientRect?.();

                if (! kotak || ! kotak.width || ! kotak.height) {
                    return { x: 0.5, y: 0.5 };
                }

                return {
                    x: batasi((peristiwa.clientX - kotak.left) / kotak.width),
                    y: batasi((peristiwa.clientY - kotak.top) / kotak.height),
                };
            },

            pusatkan(rasioX, rasioY) {
                const jalur = this.$refs?.jalur;

                if (! jalur) {
                    return;
                }

                jalur.scrollLeft = Math.max(0, rasioX * jalur.scrollWidth - jalur.clientWidth / 2);
                jalur.scrollTop = Math.max(0, rasioY * jalur.scrollHeight - jalur.clientHeight / 2);
            },

            /* ── Geser saat diperbesar (hanya tetikus) ───────────────────────── */

            mulaiSeret(peristiwa) {
                // Sentuhan TIDAK disentuh sama sekali: gulir dan cubit-perbesar bawaan
                // peramban jauh lebih baik daripada yang bisa ditulis di sini, dan sekali
                // peristiwanya diambil alih, cubitnya mati.
                if (peristiwa?.pointerType === 'touch' || ! this.asli) {
                    return;
                }

                const jalur = this.$refs?.jalur;

                if (! jalur) {
                    return;
                }

                this.seret = {
                    x: peristiwa.clientX,
                    y: peristiwa.clientY,
                    kiri: jalur.scrollLeft,
                    atas: jalur.scrollTop,
                    jauh: 0,
                };
            },

            lanjutSeret(peristiwa) {
                const jalur = this.$refs?.jalur;

                if (this.seret === null || ! jalur) {
                    return;
                }

                const dx = peristiwa.clientX - this.seret.x;
                const dy = peristiwa.clientY - this.seret.y;

                this.seret.jauh = Math.max(this.seret.jauh, Math.abs(dx) + Math.abs(dy));

                jalur.scrollLeft = this.seret.kiri - dx;
                jalur.scrollTop = this.seret.atas - dy;
            },

            akhiriSeret() {
                if (this.seret === null) {
                    return;
                }

                this.abaikanKlik = this.seret.jauh > AMBANG_SERET;
                this.seret = null;
            },

            /* ── Kunci latar halaman ─────────────────────────────────────────── */

            kunciLatar() {
                const badan = document?.body;

                if (this.terkunci || ! badan) {
                    return;
                }

                this.gulirY = window.scrollY ?? window.pageYOffset ?? 0;

                /*
                 * Tempat yang tadi dipakai bilah gulir diganti padding, kalau ada bilahnya.
                 * Tanpa itu seluruh halaman di belakang popup bergeser ±15px pada saat
                 * dibuka — dan pergeseran itu terlihat lewat latar gelapnya.
                 */
                const bilah = Math.max(
                    0,
                    (window.innerWidth ?? 0) - (document.documentElement?.clientWidth ?? 0),
                );

                badan.style.position = 'fixed';
                badan.style.top = `-${this.gulirY}px`;
                badan.style.left = '0';
                badan.style.right = '0';

                if (bilah > 0) {
                    badan.style.paddingRight = `${bilah}px`;
                }

                this.terkunci = true;
            },

            lepasLatar() {
                const badan = document?.body;

                if (! this.terkunci) {
                    return;
                }

                this.terkunci = false;

                if (! badan) {
                    return;
                }

                badan.style.position = '';
                badan.style.top = '';
                badan.style.left = '';
                badan.style.right = '';
                badan.style.paddingRight = '';

                // Kembali ke tempat yang sama, bukan ke atas halaman: pemilik menutup foto
                // untuk melanjutkan membaca nota yang tadi ia buka.
                window.scrollTo?.(0, this.gulirY);
            },

            /**
             * Livewire boleh merender ulang panelnya kapan saja (toast, morph, navigasi).
             * Kalau itu terjadi saat popup terbuka, komponen Alpine-nya dibuang — dan tanpa
             * pelepasan di sini <body> tertinggal `position: fixed` selamanya: halaman
             * membeku dan satu-satunya jalan keluar adalah memuat ulang.
             */
            destroy() {
                this.lepasLatar();
            },
        }));
    });
}

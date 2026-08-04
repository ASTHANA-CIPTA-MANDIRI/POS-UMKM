/**
 * Baris lembar hitung fisik (stok opname).
 *
 * KENAPA selisihnya dihitung di sini, bukan di server:
 *
 * Pemilik mengetik 80–200 baris dalam satu duduk. Satu perjalanan Livewire per ketikan
 * berarti menunggu jaringan di setiap angka — di warung dengan sinyal seadanya itu berarti
 * kolom yang "macet" tiap kali diketik, dan orangnya berhenti memakai fitur ini sebelum
 * baris kesepuluh. Angkanya tetap dikirim ke server, tapi lewat ikatan `.blur`: satu
 * permintaan per baris SELESAI diketik.
 *
 * Satu definisi dipakai dua bentuk tampilan (kartu di ponsel, tabel di layar lebar). Dua
 * salinan rumus selisih berarti suatu hari keduanya menampilkan angka berbeda untuk barang
 * yang sama, dan tidak ada yang bisa memastikan mana yang benar.
 */

/** Stok disimpan decimal(…,3); pembulatan selisih mengikuti ketelitian yang sama. */
const KETELITIAN = 1000;

export function pasangOpname() {
    document.addEventListener('alpine:init', () => {
        window.Alpine.data('barisOpname', (fisikAwal = '', sistem = 0, alasanAwal = '') => ({
            fisik: fisikAwal ?? '',
            sistem: Number(sistem) || 0,
            alasan: alasanAwal ?? '',

            /**
             * Nol dianggap TERISI.
             *
             * "0" adalah jawaban paling penting yang bisa diberikan lembar ini — raknya
             * kosong — dan memperlakukannya sebagai kolom kosong akan menyembunyikan
             * selisih terbesar yang mungkin ada.
             */
            get terisi() {
                return String(this.fisik).trim() !== '';
            },

            /** null berarti belum dihitung, bukan nol. */
            get beda() {
                if (! this.terisi) {
                    return null;
                }

                const nilai = Number(String(this.fisik).replace(',', '.'));

                if (! Number.isFinite(nilai)) {
                    return null;
                }

                return Math.round((nilai - this.sistem) * KETELITIAN) / KETELITIAN;
            },

            get selisih() {
                return this.beda !== null && this.beda !== 0;
            },

            get teksBeda() {
                if (this.beda === null) {
                    return '—';
                }

                return (this.beda > 0 ? '+' : '')
                    + this.beda.toLocaleString('id-ID', { maximumFractionDigits: 3 });
            },

            /** Hanya "lainnya" yang mewajibkan catatan — sama dengan AlasanOpname::butuhCatatan(). */
            get butuhCatatan() {
                return this.alasan === 'lainnya';
            },

            /**
             * Koma diubah menjadi titik saat diketik.
             *
             * Papan ketik angka di ponsel Indonesia memberi koma, sedangkan validasi server
             * menuntut angka ber-titik (`numeric`). Tanpa ini "1,5" ditolak dengan pesan
             * "harus berupa angka" SESUDAH seluruh lembar diketik, dan orangnya tidak punya
             * cara menduga bahwa yang salah hanya tanda desimalnya.
             *
             * Nilainya hanya ditulis ulang kalau memang ada koma, supaya kursor tidak
             * melompat ke ujung saat angka disunting di tengah.
             */
            ketik(el) {
                let nilai = el.value;

                if (nilai.includes(',')) {
                    nilai = nilai.replace(/,/g, '.');
                    el.value = nilai;
                }

                this.fisik = nilai;
            },
        }));
    });
}

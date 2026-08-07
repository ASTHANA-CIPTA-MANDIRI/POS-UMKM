{{--
    Layar kasir. Tiga mode transaksi sesuai proses bisnis:

    langsung    — bayar seketika (warteg antre, kelontong)
    open_bill   — bill per meja, pesanan menumpuk, bayar di akhir
    pesan_antar — titipan dengan status berjalan sampai diambil & dibayar

    Livewire hanya dipakai untuk gerbang sesi kas dan penutupan shift. Seluruh
    interaksi transaksi ditangani Alpine di sisi klien, sebab tiap ketukan tombol
    yang lewat server berarti kasir berhenti bekerja begitu jaringan mati.
--}}
<div class="app-font min-h-dvh bg-cream">
    @if ($sesi === null)
        {{-- Gerbang: tidak ada transaksi tanpa modal awal yang tercatat. --}}
        <div class="mx-auto flex min-h-dvh max-w-lg items-center px-5">
            <div class="kartu w-full p-7">
                <a href="{{ route('kasir.beranda') }}" wire:navigate
                   class="mb-4 inline-flex items-center gap-1.5 text-[0.8125rem] font-semibold text-umber transition-colors hover:text-ink">
                    <svg viewBox="0 0 20 20" class="size-3.5" fill="none" aria-hidden="true">
                        <path d="M12.5 5 7.5 10l5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    Beranda kasir
                </a>

                <p class="eyebrow">Kasir</p>
                <h1 class="mt-1 text-2xl font-bold text-ink">Buka kasir dulu</h1>
                <p class="mt-2 text-sm leading-relaxed text-umber-soft">
                    Catat uang modal di laci sekarang. Angka ini yang nanti dibandingkan
                    dengan hitungan sistem saat tutup shift.
                </p>

                @if ($galat)
                    <p class="mt-4 rounded-xl bg-merah/10 px-4 py-3 text-sm text-merah-tua">{{ $galat }}</p>
                @endif

                <label for="modal-awal" class="mt-5 block text-sm font-semibold text-ink">
                    Modal awal <span class="text-merah">*</span>
                </label>
                {{--
                    Berformat rupiah, sama seperti medan uang di layar transaksi.
                    Angka "200000" tanpa pemisah mudah salah dibaca satu nol, dan ini
                    angka pembanding untuk seluruh shift.

                    Nilai numeriknya dikirim ke Livewire lewat $wire.set(..., false):
                    tanpa argumen ketiga itu setiap ketikan memicu permintaan ke server.
                --}}
                {{-- Tanpa isian awal: kasir harus menghitung laci lebih dulu. Nominal
                     cepat mempercepat pengetikan tanpa mengisikan angka untuknya. --}}
                <div x-data="{ nilai: null }">
                    <div class="relative mt-1.5">
                        <span class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-sm font-semibold text-umber-soft">Rp</span>
                        <input
                            id="modal-awal"
                            type="text"
                            inputmode="numeric"
                            placeholder="0"
                            :value="nilai === null ? '' : new Intl.NumberFormat('id-ID').format(nilai)"
                            @input="
                                const digit = String($event.target.value).replace(/\D/g, '');
                                nilai = digit === '' ? null : Number(digit);
                                $event.target.value = nilai === null ? '' : new Intl.NumberFormat('id-ID').format(nilai);
                                $wire.set('modalAwal', nilai, false);
                            "
                            class="tabular h-12 w-full rounded-xl border border-line bg-white pr-4 pl-12 text-right text-lg font-semibold text-ink placeholder:text-umber-soft/60 focus:border-terracotta focus:ring-2 focus:ring-terracotta/20"
                        >
                    </div>

                    <div class="mt-2 flex flex-wrap gap-1.5">
                        @foreach ([50000, 100000, 200000, 500000] as $cepat)
                            <button type="button"
                                    @click="nilai = {{ $cepat }}; $wire.set('modalAwal', nilai, false)"
                                    class="tabular h-9 cursor-pointer rounded-lg bg-cream px-2.5 text-xs font-bold text-ink transition-colors hover:bg-cream-deep">
                                {{ number_format($cepat, 0, ',', '.') }}
                            </button>
                        @endforeach
                    </div>

                    <button type="button" wire:click="bukaSesi" wire:loading.attr="disabled"
                            :disabled="nilai === null"
                            class="mt-4 h-12 w-full cursor-pointer rounded-xl bg-terracotta text-sm font-bold text-white transition-colors hover:bg-terracotta-deep disabled:cursor-not-allowed disabled:opacity-40">
                        <span wire:loading.remove wire:target="bukaSesi">Buka kasir</span>
                        <span wire:loading wire:target="bukaSesi">Membuka…</span>
                    </button>
                </div>
            </div>
        </div>
    @else
        <div
            x-data="kasir({
                outletId: @js($outletId),
                deviceId: @js($deviceId),
                sesiId: @js($sesi->getKey()),
                urlKatalog: @js(route('kasir.katalog')),
                urlSisaStok: @js(route('kasir.sisa-stok')),
                urlSinkron: @js(route('sinkronisasi.transaksi')),
                bekalAwal: @js($bekalAwal),
            })"
            class="flex min-h-dvh flex-col"
        >
            {{-- ── Bilah atas ──────────────────────────────────────────────── --}}
            <header class="sticky top-0 z-20 border-b border-line bg-white/85 backdrop-blur">
                <div class="flex flex-wrap items-center gap-3 px-4 py-3">
                    {{-- Jalan keluar dari layar transaksi. Aman ditekan kapan saja:
                         keranjang dan antrean tersimpan di perangkat, jadi kembali ke
                         beranda tidak membuang pesanan yang sedang diketik. --}}
                    <a href="{{ route('kasir.beranda') }}" wire:navigate
                       class="flex h-10 shrink-0 items-center gap-1.5 rounded-full border border-line bg-white px-3.5 text-sm font-semibold text-ink transition hover:border-terracotta">
                        <svg viewBox="0 0 20 20" class="size-4 text-umber-soft" fill="none" aria-hidden="true">
                            <path d="M12.5 5 7.5 10l5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        Beranda
                    </a>

                    {{-- Nama outlet & kasir sudah ada di bilah gelap layout; di sini
                         hanya angka yang dipakai kasir saat menutup shift. --}}
                    <p class="min-w-0 flex-1 truncate text-sm text-umber">
                        Modal laci
                        <span class="tabular font-bold text-ink">Rp {{ number_format((float) $sesi->modal_awal, 0, ',', '.') }}</span>
                    </p>

                    {{-- Status jaringan sengaja selalu terlihat: kasir harus tahu kapan
                         transaksinya masih menumpuk di perangkat. --}}
                    <div class="flex items-center gap-2">
                        <span class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-semibold"
                              :class="online ? 'bg-hijau/10 text-hijau-tua' : 'bg-jingga/15 text-jingga-tua'">
                            {{-- Denyut yang sama dengan lencana status di area owner:
                                 satu bahasa visual untuk "keadaan yang sedang berjalan". --}}
                            <span class="grid size-1.5 place-items-center" :class="online ? 'text-hijau' : 'text-jingga-deep'">
                                <span class="titik-denyut size-1.5 rounded-full bg-current"></span>
                            </span>
                            <span x-text="online ? 'Online' : 'Offline'"></span>
                        </span>

                        <span x-show="antrean.length > 0" x-cloak
                              class="tabular rounded-full bg-jingga/20 px-2.5 py-1 text-xs font-bold text-jingga-tua"
                              x-text="antrean.length + ' antre'"></span>

                        {{-- Suara pindaian, berputar: ucapan → bip → mati.

                             Pilihannya diingat di perangkat. Warung yang buka sebelum subuh
                             dan dua kasir berdampingan adalah keadaan nyata di mana suara
                             mengganggu — dan suara yang tidak bisa dimatikan membuat orang
                             mengecilkan volume perangkat, ikut membungkam tanda-tanda lain
                             yang lebih penting. Mode 'bip' ada di antaranya karena ucapan
                             mulai tertinggal saat memindai puluhan barang berturut-turut. --}}
                        <button type="button" @click="gantiSuara()"
                                :aria-label="labelSuara() + ' — ketuk untuk mengganti'"
                                :title="labelSuara()"
                                :class="suara === 'mati'
                                    ? 'border-line text-umber-soft hover:bg-cream'
                                    : 'border-terracotta bg-terracotta/10 text-terracotta'"
                                class="grid size-11 cursor-pointer place-items-center rounded-xl border transition">
                            {{-- Tiga BENTUK yang berbeda, bukan tiga warna: mulut bicara untuk
                                 ucapan, gelombang untuk bip, silang untuk mati.

                                 x-show, bukan <template x-if>: <template> di dalam <svg> bukan
                                 HTMLTemplateElement dan tidak punya .content, jadi x-if tidak
                                 akan pernah merendernya. --}}
                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"
                                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M11 5 6.5 9H3v6h3.5L11 19V5Z" />

                                {{-- Ucapan: tiga garis mengecil, seperti kata yang keluar. --}}
                                <g x-show="suara === 'ucapan'">
                                    <path d="M15 8.5v7" />
                                    <path d="M18 6.5v11" />
                                    <path d="M21 9.5v5" />
                                </g>

                                <g x-show="suara === 'bip'" x-cloak>
                                    <path d="M15.5 9.5a4 4 0 0 1 0 5" />
                                    <path d="M18.5 7a7.5 7.5 0 0 1 0 10" />
                                </g>

                                <path x-show="suara === 'mati'" x-cloak d="m16 10 4 4m0-4-4 4" />
                            </svg>
                        </button>

                        <button type="button" @click="segarkan()" :disabled="! online"
                                class="grid size-11 place-items-center rounded-xl border border-line text-umber-soft transition hover:bg-cream disabled:opacity-40"
                                aria-label="Segarkan data produk">
                            <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path d="M21 12a9 9 0 1 1-3-6.7M21 4v5h-5" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                        </button>

                        <button type="button" @click="$refs.panelTutup.showModal()"
                                class="h-11 rounded-xl border border-line px-4 text-sm font-semibold text-ink transition hover:bg-cream">
                            Tutup kasir
                        </button>
                    </div>
                </div>

                {{-- Mode dipilih per transaksi, hanya di antara yang diaktifkan outlet. --}}
                {{--
                    Daftar bill dipindahkan ke dialog, bukan kolom tetap.

                    Alasannya ruang: di tablet 1024px kolom bill memakan ~280px yang
                    seharusnya menjadi satu kolom tombol produk lagi. Bill hanya
                    disentuh saat membuka meja dan saat menutupnya — bukan sepanjang
                    transaksi — jadi ia tidak layak menempati ruang tetap.
                --}}
                <div class="flex flex-wrap items-center gap-2 px-4 pb-3">
                    {{--
                        Pil mode MEMBUNGKUS, tidak digulir mendatar.

                        Sebelumnya baris ini `overflow-x-auto`: di 390px dengan tiga mode
                        (depot: titip/antar + bayar langsung + bill) pil kedua terpotong
                        di tengah kata — terbaca "Bayar langsu" — dan kotaknya menyusup ke
                        bawah tombol bill di sebelahnya (terukur tumpangTindih=1 pada
                        tangkapan kasir-depot @390). Mode adalah pilihan yang menentukan
                        ke mana transaksi tercatat; pilihan yang harus dicari dengan
                        menggeser sama saja tidak ada (PATOKAN RESPONSIF — pil saringan).
                    --}}
                    <div x-show="modeTersedia.length > 1" x-cloak class="flex min-w-0 flex-1 flex-wrap gap-2">
                    <template x-for="m in modeTersedia" :key="m">
                        <button type="button" @click="gantiMode(m)"
                                :aria-pressed="mode === m"
                                class="h-11 shrink-0 rounded-full border px-5 text-sm font-semibold transition"
                                :class="mode === m
                                    ? 'border-transparent bg-terracotta text-white'
                                    : 'border-line bg-white text-umber-soft hover:bg-cream'"
                                x-text="{ langsung: 'Bayar langsung', open_bill: 'Buka bill', pesan_antar: 'Titip / antar' }[m] ?? m">
                            </button>
                        </template>
                    </div>

                    {{-- Bill yang sedang dilayani ditulis di tombolnya sendiri, supaya
                         kasir tidak perlu membuka dialog hanya untuk memastikan. --}}
                    <button type="button" x-show="pakaiBill" x-cloak
                            @click="$refs.panelBill.showModal()"
                            class="ml-auto flex h-11 shrink-0 items-center gap-2 rounded-full border border-line bg-white px-4 text-sm font-semibold text-ink transition hover:border-terracotta">
                        <svg viewBox="0 0 24 24" class="size-4 text-umber-soft" fill="none" aria-hidden="true">
                            <path d="M7 4h10v16l-3-2-2 2-2-2-3 2V4Zm3 5h4m-4 4h4" stroke="currentColor"
                                  stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <span x-text="billTerpilih ? billTerpilih.label : (mode === 'open_bill' ? 'Buka bill' : 'Titipan')"></span>
                        <span class="tabular rounded-full bg-cream-deep px-1.5 py-0.5 text-xs text-umber"
                              x-text="daftarBill.length"></span>
                    </button>
                </div>
            </header>

            {{-- Pemberitahuan singkat. aria-live supaya pembaca layar ikut mendengar. --}}
            <div class="pointer-events-none fixed inset-x-0 top-20 z-40 flex justify-center px-4" aria-live="polite">
                <p x-show="pesan" x-cloak x-transition.opacity
                   class="rounded-xl px-4 py-2.5 text-sm font-semibold shadow-lg"
                   {{-- Ada cadangan di ujungnya: jenis yang belum terdaftar di sini akan
                        menghasilkan undefined, dan pemberitahuannya muncul TANPA latar —
                        teks gelap menumpuk di atas bilah kepala dan tidak terbaca. Itu
                        sudah pernah terjadi saat jenis 'peringatan' ditambahkan. --}}
                   :class="({
                       sukses: 'bg-hijau text-white',
                       {{-- Teks GELAP di atas jingga: #ffb547 terlalu terang untuk teks
                            putih (rasio kontras ±1,8:1, jauh di bawah 4,5:1) — di layar
                            kasir yang dibaca sambil berdiri dan sering terkena cahaya
                            matahari, pemberitahuan itu praktis tidak terbaca. --}}
                       peringatan: 'bg-jingga text-ink',
                       galat: 'bg-merah-deep text-white',
                       offline: 'bg-jingga text-ink',
                       info: 'bg-ink text-white',
                   })[jenisPesan] ?? 'bg-ink text-white'"
                   x-text="pesan"></p>
            </div>

            <div class="flex flex-1 flex-col gap-4 p-4 lg:flex-row lg:items-start">
                {{-- ── Katalog ─────────────────────────────────────────────── --}}
                <section class="flex min-w-0 flex-1 flex-col gap-3" aria-label="Daftar produk">
                    {{--
                        Kolom cari sekaligus pintu masuk pemindaian.

                        Tiga jalan masuk ke keranjang lewat satu baris ini:

                        1. Pemindai USB — ditangkap di tingkat jendela (tangkap), jadi
                           memindai barang tanpa menyentuh layar lebih dulu tetap masuk.
                           Kalau fokus sedang di kolom ini, Enter yang dikirim pemindai
                           ditangani pindaiDariPencarian().
                        2. Kamera — tombol di sebelah kanan; hilang sendiri di peramban
                           atau alamat yang tidak diizinkan memakai kamera.
                        3. Mengetik — nama barang, atau angka barcodenya.
                    --}}
                    {{-- 'lanjut': panelnya TIDAK menutup setiap kali satu barcode terbaca.
                         Kelontong memindai sepuluh barang berturut-turut; panel yang
                         menutup sendiri memaksa kasir menekan tombol pindai sepuluh kali
                         sambil pembeli menunggu. --}}
                    <div class="space-y-2"
                         x-data="pemindaiBarcode('lanjut')"
                         @keydown.window="tangkap($event)"
                         {{-- `terbuka` diteruskan sebagai penanda sunyi: saat panel kamera
                              terbuka, hasilnya sudah tampil di dalam panel, dan toast per
                              pindaian hanya menumpuk di atas layar yang sedang dipakai. --}}
                         @barcode-terpindai="terimaKode($event.detail.nilai, terbuka)">
                        <div class="flex items-center gap-2">
                            <div class="relative min-w-0 flex-1">
                                <label for="cari-produk" class="sr-only">Cari produk atau pindai barcode</label>
                                <svg viewBox="0 0 20 20" class="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-umber-soft"
                                     fill="none" aria-hidden="true">
                                    <circle cx="9" cy="9" r="5.5" stroke="currentColor" stroke-width="1.6" />
                                    <path d="m13.5 13.5 3 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                                </svg>
                                <input id="cari-produk" type="search" x-model="pencarian"
                                       placeholder="Cari produk atau pindai barcode…"
                                       autocomplete="off"
                                       @keydown.enter.prevent="pindaiDariPencarian()"
                                       class="h-12 w-full rounded-xl border border-line bg-white pr-10 pl-11 text-sm text-ink focus:border-terracotta focus:ring-2 focus:ring-terracotta/20">
                                {{-- Tombol bersihkan: mengetik ulang untuk mengosongkan pencarian
                                     itu kerja mubazir saat pelanggan sudah menunggu. --}}
                                <button type="button" x-show="pencarian !== ''" x-cloak @click="pencarian = ''"
                                        class="absolute top-1/2 right-2 grid size-8 -translate-y-1/2 place-items-center rounded-lg text-umber-soft transition hover:bg-cream"
                                        aria-label="Bersihkan pencarian">
                                    <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                        <path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                                    </svg>
                                </button>
                            </div>

                            <button type="button" @click="buka()" x-show="bisaKamera" x-cloak
                                    aria-label="Pindai barcode dengan kamera" title="Pindai barcode dengan kamera"
                                    class="grid size-12 shrink-0 cursor-pointer place-items-center rounded-xl bg-ink text-white transition hover:brightness-125">
                                <svg viewBox="0 0 20 20" class="size-5" fill="none" aria-hidden="true">
                                    <path d="M3 7V4.5h2.5M17 7V4.5h-2.5M3 13v2.5h2.5M17 13v2.5h-2.5M6 7v6m2.5-6v6M11 7v6m2.5-6v6"
                                          stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
                                </svg>
                            </button>
                        </div>

                        {{-- Pesan galat pemindai ditampilkan di luar panelnya juga: kalau
                             kamera gagal dibuka, panelnya menutup dan pesannya harus tetap
                             terbaca. Ditaruh di dalam lingkup pemindai — di luar lingkupnya,
                             `galat` tidak dikenal dan pesannya diam-diam tidak pernah muncul. --}}
                        <p x-show="galat" x-cloak
                           class="rounded-xl bg-jingga/10 px-3 py-2 text-[0.8125rem] text-jingga-tua" x-text="galat"></p>

                        <x-panel-pindai judul="Pindai barang">
                            {{-- Umpan hasil di dalam panel. Isinya bertahan sampai pindaian
                                 berikutnya: kode yang tidak dikenal harus tetap terbaca
                                 supaya bisa dicatat, bukan berkedip lalu hilang. --}}
                            <x-slot:umpan>
                                <template x-if="pindaiTerakhir">
                                    <div class="border-t border-line px-4 py-3">
                                    <div class="flex items-center gap-3 rounded-xl px-3 py-2.5"
                                         x-bind:class="pindaiTerakhir.dikenal ? 'bg-hijau/10' : 'bg-jingga/15'">
                                        <span class="grid size-8 shrink-0 place-items-center rounded-lg"
                                              x-bind:class="pindaiTerakhir.dikenal ? 'bg-hijau text-white' : 'bg-jingga text-ink'">
                                            <svg x-show="pindaiTerakhir.dikenal" viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                                <path d="m5 10.5 3.5 3.5L15 7" stroke="currentColor" stroke-width="2"
                                                      stroke-linecap="round" stroke-linejoin="round" />
                                            </svg>
                                            <svg x-show="! pindaiTerakhir.dikenal" viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                                <path d="M10 6v5.5M10 14.5v.5" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                                            </svg>
                                        </span>

                                        <span class="min-w-0 flex-1">
                                            <span class="block truncate text-[0.875rem] font-bold text-ink"
                                                  x-text="pindaiTerakhir.dikenal ? pindaiTerakhir.nama : 'Belum terdaftar'"></span>
                                            <span class="tabular block truncate text-[0.75rem] text-umber"
                                                  x-text="pindaiTerakhir.nilai"></span>
                                        </span>

                                        {{-- Jumlah & total keranjang: kasir bisa memindai sepuluh
                                             barang dan melihat totalnya naik tanpa menutup panel. --}}
                                        <span class="shrink-0 text-right">
                                            <span class="tabular block text-[0.875rem] font-bold text-ink"
                                                  x-text="rupiah(totalKeranjang)"></span>
                                            <span class="block text-[0.75rem] text-umber"
                                                  x-text="keranjang.length + ' barang'"></span>
                                        </span>
                                    </div>
                                    </div>
                                </template>
                            </x-slot:umpan>
                        </x-panel-pindai>
                    </div>

                    {{--
                        Segmented control: satu jalur berlatar lembut dengan chip putih
                        menandai kategori aktif — pola tab Horizon.

                        Mask gradien yang dulu dipasang di sini DIHAPUS. Mask itu
                        memudarkan kedua tepi baris, sehingga kategori pertama (yang
                        justru paling sering aktif) selalu tampak kotor tertutup
                        gradien. Baris ini tetap bisa digulir; penandanya cukup chip
                        yang terpotong di tepi.
                    --}}
                    <div class="-mx-1 overflow-x-auto px-1 pb-1 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                        <div class="inline-flex items-center gap-1 rounded-full bg-cream-deep/70 p-1">
                            <template x-for="k in kategori" :key="k">
                                <button type="button" @click="kategoriAktif = k"
                                        :aria-pressed="kategoriAktif === k"
                                        class="flex h-11 shrink-0 items-center gap-2 rounded-full px-4 text-sm whitespace-nowrap transition"
                                        :class="kategoriAktif === k
                                            ? 'bg-white font-semibold text-terracotta shadow-[0_2px_8px_rgb(112_144_176/0.28)]'
                                            : jumlahKategori(k) === 0
                                                ? 'font-medium text-umber-soft/60'
                                                : 'font-medium text-umber hover:text-ink'">
                                    <span x-text="k"></span>
                                    {{-- Jumlah produk: memberi tahu isi kategori sebelum
                                         ditekan, dan menandai yang kosong saat mencari. --}}
                                    <span class="tabular rounded-full px-1.5 py-0.5 text-xs"
                                          :class="kategoriAktif === k ? 'bg-terracotta/10 text-terracotta' : 'bg-white/70 text-umber-soft'"
                                          x-text="jumlahKategori(k)"></span>
                                </button>
                            </template>
                        </div>
                    </div>

                    <div>
                    <div class="grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-4 2xl:grid-cols-5">
                        <template x-for="p in produkTampil" :key="p.id">
                            {{-- Tombol dibuat besar dan tinggi seragam: kasir menekan
                                 sambil melihat pelanggan, bukan melihat layar. --}}
                            <button type="button" @click="tambah(p)"
                                    class="flex min-h-24 flex-col justify-between rounded-2xl border border-line bg-white p-3 text-left transition active:scale-[0.98] hover:border-terracotta hover:shadow-sm">
                                {{--
                                    Ada foto → dipakai. Tidak ada → gambar default berupa
                                    inisial produk di atas warna tetap dari namanya.

                                    x-on:error mengembalikan ke gambar default kalau foto
                                    gagal dimuat: saat offline berkasnya belum tentu ada di
                                    cache peramban, dan ikon gambar rusak di layar kasir
                                    lebih mengganggu daripada tile default.
                                --}}
                                <span class="mb-2 block aspect-4/3 w-full overflow-hidden rounded-xl">
                                    <template x-if="p.gambar">
                                        <img :src="p.gambar" :alt="p.nama" loading="lazy"
                                             x-on:error="p.gambar = null" class="size-full object-cover">
                                    </template>

                                    <template x-if="! p.gambar">
                                        {{-- aria-hidden: inisialnya hanya pengulangan nama
                                             produk yang sudah dibacakan di bawahnya. --}}
                                        <span aria-hidden="true"
                                              class="grid size-full place-items-center text-lg font-bold tracking-tight"
                                              :class="warnaProduk(p.nama)"
                                              x-text="inisial(p.nama)"></span>
                                    </template>
                                </span>

                                <span class="line-clamp-2 text-sm font-semibold leading-snug text-ink" x-text="p.nama"></span>

                                {{--
                                    Harga dan lencana stok SEBARIS, bukan lencana yang
                                    ditumpangkan di atas gambar. Lencana melayang menutupi
                                    foto produk — satu-satunya penanda yang dipakai kasir
                                    untuk mengenali barang tanpa membaca — dan tumpukan
                                    seperti itu juga yang terhitung sebagai "tumpangTindih"
                                    saat kerapian diukur.

                                    flex-wrap: harga jutaan + "Menipis" tidak muat sebaris
                                    di petak selebar 180px (dua kolom di layar 390px), dan
                                    yang boleh turun baris adalah lencananya, bukan harganya.
                                --}}
                                <span class="mt-2 flex flex-wrap items-center justify-between gap-x-2 gap-y-1">
                                    <span class="tabular text-sm font-bold text-terracotta" x-text="'Rp ' + rupiah(p.harga)"></span>

                                    {{--
                                        Lencana ini MEMBERI TAHU, tidak menghalangi: petaknya
                                        tetap bisa ditekan dan barangnya tetap bisa dijual
                                        (aturan 5 CLAUDE.md — stok boleh minus, penjualan
                                        jangan pernah diblokir). Kasir yang melihat "Habis"
                                        lalu menemukan barangnya di rak tetap menjualnya;
                                        selisihnya diselesaikan lewat hitung stok.

                                        x-if, BUKAN x-show: produk tanpa kabar stok — barang
                                        aman, barang yang belum pernah dihitung, dan seluruh
                                        petak saat kabarnya kedaluwarsa atau gagal diambil —
                                        tidak menyisakan elemen apa pun, jadi petaknya tampil
                                        persis seperti sebelum fitur ini ada.
                                    --}}
                                    <template x-if="statusStok(p.id)">
                                        <span class="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2 py-0.5 text-[0.6875rem] font-bold whitespace-nowrap"
                                              {{-- text-merah-tua, BUKAN merah-deep: di atas bg-merah/10
                                                   merah-deep terukur 4,15:1 — gagal AA. merah-tua
                                                   7,14:1. Polanya sama dengan jingga → jingga-tua
                                                   di sebelahnya (6,14:1), jadi kedua lencana ini
                                                   memakai aturan yang sama. --}}
                                              :class="statusStok(p.id) === 'habis' ? 'bg-merah/10 text-merah-tua' : 'bg-jingga/15 text-jingga-tua'"
                                              :title="keteranganStok(p.id)"
                                              :aria-label="keteranganStok(p.id)">
                                            {{-- Titik di depan teks: statusnya terbaca tanpa
                                                 bergantung warna saja — pola yang sama dengan
                                                 x-lencana di layar owner. --}}
                                            <span class="size-1.5 shrink-0 rounded-full bg-current" aria-hidden="true"></span>
                                            <span x-text="labelStok(p.id)"></span>
                                        </span>
                                    </template>
                                </span>
                            </button>
                        </template>
                    </div>

                    <p x-show="produkTampil.length === 0" x-cloak
                       class="rounded-2xl border border-dashed border-line p-8 text-center text-sm text-umber-soft">
                        Tidak ada produk yang cocok.
                    </p>
                    </div>
                </section>

                {{-- ── Daftar tagihan & pembayaran ─────────────────────────── --}}
                {{--
                    Panel MENEMPEL (sticky) di layar lebar, dengan area isi yang dibatasi
                    tinggi. Dua-duanya perlu: sticky menjaga panel tetap terlihat saat
                    daftar produk digulir, dan batas tinggi menjaga panel tidak pernah
                    lebih panjang dari layar — tanpa itu tombol Bayar tetap bisa jatuh
                    di bawah lipatan pada keranjang yang panjang.

                    Cara ini dipilih setelah pendekatan "seluruh layar setinggi viewport"
                    gagal: tinggi persen tidak bisa dihitung ketika salah satu induknya
                    bertinggi auto, dan rantai itu melewati empat lapis (body → main →
                    komponen → panel) sehingga mudah rusak oleh perubahan layout mana pun.
                --}}
                <section class="flex w-full flex-col lg:sticky lg:top-4 lg:w-[22rem]" aria-label="Tagihan dan pembayaran">
                    <div class="kartu flex flex-1 flex-col p-4">
                        {{--
                            Kepala panel menyebut BILL yang sedang dilayani, bukan selalu
                            "Keranjang". Sebelumnya panel ini tetap berkepala "Keranjang"
                            dan berbunyi "Pilih produk di sebelah kiri" setelah pesanan
                            dipindahkan ke bill — isi bill terdorong ke kotak kecil di
                            bawah, sehingga kasir menyimpulkan pesanannya hilang.
                        --}}
                        <div class="flex items-baseline justify-between gap-2">
                            <div class="min-w-0">
                                {{-- Nama bill sekaligus pintu untuk berpindah: ditekan
                                     membuka dialog bill, jadi kasir yang tiba-tiba dapat
                                     pesanan meja lain tidak perlu mencari tombol lain. --}}
                                <button type="button" x-show="pakaiBill && billTerpilih" x-cloak
                                        @click="$refs.panelBill.showModal()"
                                        class="flex max-w-full items-center gap-1.5 text-left">
                                    <span class="truncate text-sm font-bold text-ink" x-text="billTerpilih?.label"></span>
                                    <svg viewBox="0 0 20 20" class="size-3.5 shrink-0 text-umber-soft" fill="none" aria-hidden="true">
                                        <path d="M7 8l3 3 3-3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </button>

                                <p x-show="! (pakaiBill && billTerpilih)" x-cloak
                                   class="truncate text-sm font-bold text-ink">Keranjang</p>

                                <p x-show="pakaiBill && billTerpilih" x-cloak
                                   class="text-xs text-umber-soft"
                                   x-text="ringkasanBill(billTerpilih)"></p>
                            </div>

                            <div class="flex shrink-0 items-center gap-3">
                                {{-- Lepas: bill TETAP terbuka, kasir hanya berhenti
                                     melayaninya. Beda maksud dari "Batalkan bill" di
                                     dalam dialog, yang menghapusnya.

                                     Diberi bidang tekan 36px, bukan teks telanjang —
                                     tombol sebesar tulisannya sendiri sulit dikenai di
                                     layar sentuh. --}}
                                <button type="button" x-show="pakaiBill && billTerpilih" x-cloak
                                        @click="lepasBill()"
                                        class="h-9 rounded-lg px-2.5 text-sm font-semibold text-merah-tua transition hover:bg-merah/10">
                                    Lepas
                                </button>

                                {{-- Ukurannya disamakan dengan Lepas: dua tombol
                                     bersebelahan dengan tinggi dan bobot berbeda
                                     terbaca sebagai kekeliruan, bukan hierarki. --}}
                                <button type="button" @click="kosongkan()" x-show="keranjang.length > 0" x-cloak
                                        class="h-9 rounded-lg px-2.5 text-sm font-semibold text-merah-tua transition hover:bg-merah/10">
                                    Kosongkan
                                </button>
                            </div>
                        </div>

                        {{--
                            Satu area gulir untuk isi bill, keranjang, DAN kendali
                            pembayaran. Dua area gulir bersarang membuat kasir tidak
                            tahu mana yang sedang digerakkan jarinya.
                        --}}
                        <div class="mt-3 min-h-0 flex-1 space-y-3 overflow-y-auto pr-1" style="max-height: 30vh; min-height: 4.5rem">
                            {{-- Isi bill: daftar UTAMA saat sebuah bill dilayani. --}}
                            <template x-if="pakaiBill && billTerpilih && billTerpilih.pesanan.length > 0">
                                <div data-uji="isi-bill" class="space-y-2">
                                    <p data-uji="judul" class="text-xs font-semibold text-umber">Sudah masuk</p>

                                    <template x-for="i in billTerpilih.pesanan" :key="i.id">
                                        <div class="flex items-center gap-2 rounded-xl bg-cream p-2">
                                            <div class="min-w-0 flex-1">
                                                <p class="truncate text-sm font-semibold text-ink" x-text="i.nama"></p>
                                                <p class="tabular text-xs text-umber-soft"
                                                   x-text="tampilQty(i.qty) + ' × Rp ' + rupiah(i.harga) + ' = Rp ' + rupiah(i.harga * i.qty)"></p>
                                            </div>
                                            <div class="flex items-center gap-1">
                                                <button type="button" @click="ubahQty(billTerpilih.pesanan, i, -1)"
                                                        class="grid size-11 place-items-center rounded-lg bg-white text-lg font-bold text-ink"
                                                        aria-label="Kurangi">−</button>
                                                <span class="tabular w-9 text-center text-sm font-bold" x-text="tampilQty(i.qty)"></span>
                                                <button type="button" @click="ubahQty(billTerpilih.pesanan, i, 1)"
                                                        class="grid size-11 place-items-center rounded-lg bg-white text-lg font-bold text-ink"
                                                        aria-label="Tambah">+</button>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            {{-- Keranjang: pesanan yang belum dimasukkan ke bill. --}}
                            <div x-show="keranjang.length > 0" x-cloak class="space-y-2">
                                <p x-show="pakaiBill" class="text-xs font-semibold text-terracotta">Pesanan baru</p>

                                <template x-for="baris in keranjang" :key="baris.id">
                                    <div class="flex items-center gap-2 rounded-xl p-2"
                                         :class="pakaiBill ? 'bg-terracotta/8 ring-1 ring-terracotta/20' : 'bg-cream'">
                                        <div class="min-w-0 flex-1">
                                            <p class="truncate text-sm font-semibold text-ink" x-text="baris.nama"></p>
                                            <p class="tabular text-xs text-umber-soft"
                                               x-text="tampilQty(baris.qty) + ' × Rp ' + rupiah(baris.harga)"></p>
                                        </div>
                                        <div class="flex items-center gap-1">
                                            <button type="button" @click="ubahQty(keranjang, baris, -1)"
                                                    class="grid size-11 place-items-center rounded-lg bg-white text-lg font-bold text-ink"
                                                    aria-label="Kurangi">−</button>
                                            <span class="tabular w-9 text-center text-sm font-bold" x-text="tampilQty(baris.qty)"></span>
                                            <button type="button" @click="ubahQty(keranjang, baris, 1)"
                                                    class="grid size-11 place-items-center rounded-lg bg-white text-lg font-bold text-ink"
                                                    aria-label="Tambah">+</button>
                                        </div>
                                    </div>
                                </template>
                            </div>

                            {{-- Keadaan kosong dibedakan: penyebabnya tidak sama, jadi
                                 arahannya tidak boleh sama. --}}
                            <template x-if="panelTanpaBill">
                            <div class="py-6 text-center">
                                <p class="text-sm text-umber-soft"
                                   x-text="mode === 'open_bill' ? 'Belum ada bill yang dilayani.' : 'Belum ada titipan yang dilayani.'"></p>
                                <button type="button" @click="$refs.panelBill.showModal()"
                                        class="mt-3 h-11 rounded-xl bg-terracotta px-5 text-sm font-bold text-white transition hover:bg-terracotta-deep">
                                    Buka atau pilih bill
                                </button>
                            </div>
                            </template>
                            <template x-if="panelKosong">
                                <p class="py-6 text-center text-sm text-umber-soft">
                                    Pilih produk dari daftar untuk mulai.
                                </p>
                            </template>

                        </div>

                        {{-- Mode B/C: pesanan baru dipindahkan ke bill, bukan dibayar
                             langsung. Tombolnya hanya muncul kalau ada yang dipindahkan. --}}
                        <button type="button" x-show="pakaiBill && keranjang.length > 0" x-cloak
                                @click="tambahKeBill()" :disabled="! billTerpilih"
                                class="mt-3 h-12 w-full shrink-0 rounded-xl bg-ink text-sm font-bold text-white transition hover:brightness-125 disabled:opacity-40">
                            <span x-text="billTerpilih ? 'Masukkan ke ' + billTerpilih.label : 'Pilih bill dulu'"></span>
                        </button>



                        {{-- Kendali pembayaran DI LUAR area gulir: hanya daftar item yang
                             boleh bergerak. Metode, nominal, dan uang diterima harus
                             selalu di tempat yang sama — kasir mencarinya dengan hafalan
                             posisi, bukan dengan membaca ulang layar tiap transaksi. --}}
                        <div class="mt-3 shrink-0 space-y-2">
                                <template x-for="(p, i) in pembayaran" :key="i">
                                    <div class="rounded-xl border border-line p-2.5">
                                        {{--
                                            Metode dipilih dengan tombol, bukan dropdown.
                                            Dropdown menuntut dua ketukan dan menyembunyikan
                                            pilihannya sampai dibuka — di depan pelanggan yang
                                            menunggu, keempat pilihan harus terlihat sekaligus
                                            dan cukup sekali tekan.
                                        --}}
                                        <div class="-mx-0.5 flex gap-1.5 overflow-x-auto px-0.5 pb-0.5 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden"
                                             role="group" :aria-label="'Metode pembayaran ' + (i + 1)">
                                            <template x-for="m in metodeTersedia" :key="m.kode">
                                                <button type="button" @click="p.metode = m.kode; sinkronOtomatis()"
                                                        :aria-pressed="p.metode === m.kode"
                                                        class="h-10 shrink-0 rounded-lg px-3 text-xs font-semibold transition"
                                                        :class="p.metode === m.kode
                                                            ? 'bg-terracotta text-white'
                                                            : 'bg-cream text-umber hover:text-ink'"
                                                        x-text="m.label"></button>
                                            </template>
                                        </div>

                                        <div class="mt-1.5 flex gap-2">
                                            <label class="sr-only" :for="'jumlah-' + i">Jumlah</label>
                                            {{-- Nilainya mengikuti sisa tagihan dengan
                                                 sendirinya; mengetik di sini membuat baris
                                                 ini berhenti mengikuti.

                                                 type="text" + inputmode numeric: input
                                                 number tidak bisa menampilkan pemisah
                                                 ribuan, dan angka uang tanpa pemisah
                                                 mudah salah dibaca satu nol. --}}
                                            <div class="relative flex-1">
                                                <span class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-xs font-semibold text-umber-soft">Rp</span>
                                                <input :id="'jumlah-' + i" type="text" inputmode="numeric"
                                                       :value="rupiah(p.jumlah)"
                                                       @input="p.jumlah = angkaDari($event.target.value); lepasOtomatis(i); $event.target.value = rupiah(p.jumlah)"
                                                       class="tabular h-11 w-full rounded-lg border border-line pr-3 pl-9 text-right text-sm font-bold">
                                            </div>

                                            <button type="button" @click="hapusPembayaran(i)" x-show="pembayaran.length > 1"
                                                    x-cloak
                                                    class="grid size-11 shrink-0 place-items-center rounded-lg text-merah-tua transition hover:bg-merah/10"
                                                    aria-label="Hapus pembayaran">
                                                <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                                    <path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                                                </svg>
                                            </button>
                                        </div>

                                        {{-- Uang diterima hanya relevan untuk tunai; dari sini
                                             kembalian dihitung, bukan diketik kasir. --}}
                                        <template x-if="p.metode === 'cash'">
                                            <div class="mt-2">
                                                <label :for="'diterima-' + i" class="text-[0.6875rem] font-semibold text-umber-soft">Uang diterima</label>
                                                <div class="relative mt-1">
                                                    <span class="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-xs font-semibold text-umber-soft">Rp</span>
                                                    <input :id="'diterima-' + i" type="text" inputmode="numeric"
                                                           :value="rupiah(p.diterima)"
                                                           @input="p.diterima = angkaDari($event.target.value); $event.target.value = rupiah(p.diterima)"
                                                           class="tabular h-11 w-full rounded-lg border border-line pr-3 pl-9 text-right text-sm font-bold">
                                                </div>
                                                <div class="mt-1.5 -mx-0.5 flex gap-1.5 overflow-x-auto px-0.5 [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                                                    <template x-for="n in [5000, 10000, 20000, 50000, 100000]" :key="n">
                                                        <button type="button" @click="p.diterima = n"
                                                                class="tabular h-9 shrink-0 rounded-lg bg-cream px-2.5 text-xs font-bold text-ink"
                                                                x-text="rupiah(n)"></button>
                                                    </template>
                                                    <button type="button" @click="p.diterima = p.jumlah"
                                                            class="h-9 shrink-0 rounded-lg bg-cream px-3 text-xs font-bold text-ink">Pas</button>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </template>

                                <button type="button" @click="tambahPembayaran()"
                                        class="h-9 w-full rounded-lg border border-dashed border-line text-xs font-bold text-umber-soft transition hover:bg-cream">
                                    + Bayar terpisah (tunai + QRIS)
                                </button>
                            </div>

                            {{-- Kasbon wajib punya pelanggan; tanpa itu piutang tak bisa ditagih. --}}
                            <template x-if="adaKasbon">
                                <div class="mt-3 rounded-xl bg-jingga/12 p-3">
                                    <label for="pelanggan" class="text-xs font-bold text-jingga-tua">
                                        Pelanggan <span aria-hidden="true">*</span>
                                    </label>
                                    <select id="pelanggan" x-model="pelangganId"
                                            class="mt-1 h-11 w-full rounded-lg border border-jingga/35 bg-white px-2 text-sm">
                                        <option value="">— pilih pelanggan —</option>
                                        <template x-for="c in pelanggan" :key="c.id">
                                            <option :value="c.id" x-text="c.nama"></option>
                                        </template>
                                    </select>

                                    <label for="jatuh-tempo" class="mt-2 block text-xs font-bold text-jingga-tua">Jatuh tempo</label>
                                    <input id="jatuh-tempo" type="date" x-model="jatuhTempo"
                                           class="mt-1 h-11 w-full rounded-lg border border-jingga/35 bg-white px-3 text-sm">

                                    <p x-show="! pelangganId" x-cloak class="mt-2 text-xs text-jingga-tua">
                                        Pilih pelanggan dulu — kasbon tanpa nama tidak bisa ditagih.
                                    </p>
                                </div>
                            </template>



                        {{--
                            Dasar panel DIPAKU. Ringkasan uang dan tombol Bayar harus
                            selalu terlihat: keranjang yang panjang dulu mendorong tombol
                            ini ke bawah lipatan, sehingga menutup transaksi menuntut
                            gulir halaman lebih dulu.
                        --}}
                        <div class="mt-4 shrink-0 border-t border-line pt-4">
                            <div class="flex items-baseline justify-between">
                                <span class="text-sm font-semibold text-umber-soft">Total</span>
                                <span class="tabular text-2xl font-bold text-ink" x-text="'Rp ' + rupiah(totalTagihan)"></span>
                            </div>

                            <div class="tabular mt-2 flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 text-sm">
                                <span class="text-umber-soft">Dibayar
                                    <span :class="totalDibayar !== totalTagihan ? 'font-bold text-merah-deep' : 'font-semibold text-ink'"
                                          x-text="'Rp ' + rupiah(totalDibayar)"></span>
                                </span>
                                <span x-show="kembalian > 0" x-cloak class="font-semibold text-ink">Kembalian
                                    <span class="font-bold text-hijau-tua" x-text="'Rp ' + rupiah(kembalian)"></span>
                                </span>

                                {{-- Menyebut arah selisihnya, bukan hanya mewarnainya merah:
                                     "kurang" dan "lebih" menuntun ke tindakan berbeda. --}}
                                <template x-if="selisihBayar !== 0">
                                    <span class="w-full font-semibold text-merah-deep"
                                          x-text="(selisihBayar < 0 ? 'Kurang Rp ' : 'Lebih Rp ') + rupiah(Math.abs(selisihBayar))"></span>
                                </template>
                            </div>

                            <button type="button" data-uji="bayar" @click="bayar()" :disabled="! bisaBayar"
                                    class="mt-3 h-14 w-full rounded-xl bg-terracotta text-base font-bold text-white transition hover:brightness-110 disabled:opacity-40">
                                <span x-show="! sibuk" x-text="adaKasbon ? 'Simpan kasbon' : 'Bayar'"></span>
                                <span x-show="sibuk" x-cloak>Menyimpan…</span>
                            </button>

                            {{-- Alasan tombol Bayar mati harus terbaca; tombol mati
                                 tanpa keterangan membuat kasir menekan berulang kali. --}}
                            <p x-show="pakaiBill && keranjang.length > 0" x-cloak
                               class="mt-2 text-center text-xs font-semibold text-jingga-tua">
                                Masukkan dulu pesanan baru ke bill — belum ikut ditagih.
                            </p>

                            <button type="button" @click="cetakStruk()" x-show="strukTerakhir" x-cloak
                                    class="mt-2 h-11 w-full rounded-xl border border-line text-sm font-semibold text-ink transition hover:bg-cream">
                                Cetak struk terakhir
                            </button>
                        </div>
                    </div>
                </section>
            </div>

            {{-- ── Struk termal 58 mm ──────────────────────────────────────── --}}
            <div id="struk" x-show="strukTerakhir" x-cloak aria-hidden="true">
                <template x-if="strukTerakhir">
                    <div>
                        <p class="s-tengah s-tebal">{{ $usaha }}</p>
                        <p class="s-tengah">{{ $outletNama }}</p>
                        <p class="s-garis"></p>
                        <p x-text="strukTerakhir.nomor"></p>
                        <p x-text="strukTerakhir.waktu"></p>
                        <p>Kasir: {{ $kasirNama }}</p>
                        <p x-show="strukTerakhir.label" x-text="'Untuk: ' + strukTerakhir.label"></p>
                        <p class="s-garis"></p>
                        <template x-for="i in strukTerakhir.items" :key="i.product_id + i.nama_produk">
                            <div>
                                <p x-text="i.nama_produk"></p>
                                <p class="s-baris">
                                    <span x-text="tampilQty(i.qty) + ' x ' + rupiah(i.harga_satuan)"></span>
                                    <span x-text="rupiah(i.subtotal)"></span>
                                </p>
                            </div>
                        </template>
                        <p class="s-garis"></p>
                        <p class="s-baris s-tebal">
                            <span>TOTAL</span>
                            <span x-text="rupiah(strukTerakhir.total)"></span>
                        </p>
                        <template x-for="(p, i) in strukTerakhir.pembayaran" :key="i">
                            <p class="s-baris">
                                <span x-text="labelMetode(p.metode)"></span>
                                <span x-text="rupiah(p.jumlah)"></span>
                            </p>
                        </template>
                        <p class="s-baris" x-show="strukTerakhir.kembalian > 0">
                            <span>Kembali</span>
                            <span x-text="rupiah(strukTerakhir.kembalian)"></span>
                        </p>
                        <p class="s-garis"></p>
                        <p class="s-tengah">Terima kasih</p>
                    </div>
                </template>
            </div>

            {{-- ── Dialog bill (Mode B & C) ────────────────────────────────── --}}
            <dialog x-ref="panelBill" class="m-auto w-[calc(100%-1.5rem)] max-w-md rounded-2xl p-0 backdrop:bg-navy-900/45">
                <div class="flex max-h-[85vh] flex-col">
                    <div class="flex items-start justify-between gap-3 border-b border-line px-5 py-4">
                        <div>
                            <h2 class="text-base font-bold text-ink"
                                x-text="mode === 'open_bill' ? 'Bill meja' : 'Titipan pelanggan'"></h2>
                            <p class="mt-0.5 text-xs text-umber-soft"
                               x-text="daftarBill.length + (mode === 'open_bill' ? ' bill berjalan' : ' titipan berjalan')"></p>
                        </div>

                        {{-- Tombol tutup selalu ada; Esc saja tidak cukup di layar sentuh. --}}
                        <button type="button" @click="$refs.panelBill.close()" aria-label="Tutup"
                                class="grid size-11 shrink-0 place-items-center rounded-xl text-umber transition hover:bg-cream">
                            <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                <path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                            </svg>
                        </button>
                    </div>

                    {{--
                        Form buka bill DIPAKU di atas, di luar area gulir.

                        Dulu ia ikut bergulir bersama daftar: begitu billnya belasan,
                        kasir harus menggulir balik ke atas hanya untuk membuka meja
                        baru — padahal itu justru hal yang paling sering dilakukan.
                    --}}
                    <div class="shrink-0 px-5 pt-4">
                        <div class="rounded-xl bg-cream p-4">
                            {{-- Warteg menyebut meja, warung kopi menyebut nama, dan
                                 keduanya memakai kolom yang sama. Labelnya menyebut dua
                                 kemungkinan itu supaya kasir tidak merasa harus
                                 mengarang nomor meja untuk pelanggan yang berdiri. --}}
                            <label for="label-bill" class="block text-xs font-semibold text-umber"
                                   x-text="mode === 'open_bill' ? 'Nomor meja atau nama' : 'Nama pelanggan'"></label>
                            <input id="label-bill" type="text" x-model="labelBaru" autofocus
                                   :placeholder="mode === 'open_bill' ? 'Meja 4, atau Bu Sinta' : 'Nama pelanggan'"
                                   @keydown.enter.prevent="bukaBill() && $refs.panelBill.close()"
                                   class="mt-1.5 h-11 w-full rounded-xl border border-line bg-white px-3 text-sm text-ink focus:border-terracotta focus:outline-none">

                            {{-- Estimasi selesai hanya bermakna untuk titipan. --}}
                            <template x-if="mode === 'pesan_antar'">
                                <div>
                                    <label for="estimasi" class="mt-3 block text-xs font-semibold text-umber">Estimasi selesai</label>
                                    <input id="estimasi" type="datetime-local" x-model="estimasiBaru"
                                           class="mt-1.5 h-11 w-full rounded-xl border border-line bg-white px-3 text-sm text-ink focus:border-terracotta focus:outline-none">
                                </div>
                            </template>

                            {{-- Dialog hanya ditutup kalau bill benar-benar dibuat. --}}
                            <button type="button" @click="bukaBill() && $refs.panelBill.close()"
                                    class="mt-3 h-11 w-full rounded-xl bg-terracotta text-sm font-bold text-white transition hover:bg-terracotta-deep">
                                Buka & layani
                            </button>
                        </div>
                    </div>

                    {{-- Yang sedang berjalan: satu-satunya bagian yang bergulir. --}}
                    <div class="min-h-0 flex-1 overflow-y-auto px-5 py-4">
                        <div class="space-y-2">
                            <template x-for="b in daftarBill" :key="b.id">
                                <div class="rounded-xl border bg-white p-3 transition"
                                     :class="billAktif === b.id
                                         ? 'border-terracotta ring-2 ring-terracotta/25'
                                         : 'border-line'">
                                    <button type="button"
                                            @click="pilihBill(b.id); $refs.panelBill.close()"
                                            class="w-full text-left">
                                        <div class="flex items-baseline justify-between gap-2">
                                            <span class="truncate text-sm font-bold text-ink" x-text="b.label"></span>
                                            <span class="tabular shrink-0 text-sm font-semibold text-terracotta"
                                                  x-text="'Rp ' + rupiah(b.pesanan.reduce((j, i) => j + i.harga * i.qty, 0))"></span>
                                        </div>
                                        {{-- Badge status hanya dipasang untuk titipan, di
                                             mana statusnya benar-benar berpindah. Pada bill
                                             meja semuanya selalu "Terbuka", jadi badge itu
                                             hanya menambah benda untuk dibaca. --}}
                                        <span x-show="mode === 'pesan_antar'" x-cloak
                                              class="mt-1 inline-block rounded-full bg-cream px-2 py-0.5 text-xs font-medium text-umber"
                                              x-text="labelStatus(b.status)"></span>
                                        <span x-show="mode !== 'pesan_antar'" x-cloak
                                              class="mt-1 block text-xs text-umber-soft"
                                              x-text="ringkasanBill(b)"></span>
                                    </button>

                                    <div class="mt-2 flex gap-2">
                                        <button type="button"
                                                x-show="mode === 'pesan_antar' && b.status !== 'siap_diambil'"
                                                x-cloak @click="majukanStatus(b)"
                                                class="h-10 flex-1 rounded-lg border border-line text-xs font-semibold text-ink transition hover:bg-cream">
                                            Majukan status
                                        </button>

                                        {{-- PEMICU konfirmasi, bukan tindakan merusaknya:
                                             tint `bg-merah/10` + `text-merah-tua`, merah sejak
                                             istirahat — di tablet tidak ada hover, jadi merah
                                             yang menunggu disorot tidak pernah muncul.

                                             Dialognya lewat pembungkus SweetAlert BERSAMA
                                             (window.konfirmasiNampan di resources/js/toast.js),
                                             dipanggil dari mintaBatalkanBill() di kasir.js —
                                             bukan panel dua langkah sebaris, dan bukan
                                             confirm() bawaan peramban yang di layar sentuh
                                             sering tertekan tanpa terbaca. JUMLAH pesanan yang
                                             akan hilang tetap disebut di dalam dialognya; itu
                                             satu-satunya pembeda antara bill kosong dan tujuh
                                             pesanan yang sudah dimasak. Dialognya bukan
                                             pengaman — endpoint sinkronisasi tetap memeriksa
                                             tenant, outlet, dan sesinya. --}}
                                        <button type="button" @click="mintaBatalkanBill(b)"
                                                class="h-10 flex-1 cursor-pointer rounded-lg border border-merah/35 bg-merah/10 text-sm font-semibold text-merah-tua transition hover:border-merah/45 hover:bg-merah/15">
                                            Batalkan bill
                                        </button>
                                    </div>
                                </div>
                            </template>

                            <p x-show="daftarBill.length === 0" x-cloak
                               class="rounded-xl border border-dashed border-line p-5 text-center text-xs text-umber-soft">
                                Belum ada yang berjalan di perangkat ini.
                            </p>
                        </div>
                    </div>
                </div>
            </dialog>

            {{-- ── Tutup kasir ─────────────────────────────────────────────── --}}
            <dialog x-ref="panelTutup" class="m-auto w-[calc(100%-1.5rem)] max-w-sm rounded-2xl p-0 backdrop:bg-navy-900/45">
                <div class="p-6">
                    <h2 class="text-lg font-bold text-ink">Tutup kasir</h2>
                    <p class="mt-1 text-sm leading-relaxed text-umber-soft">
                        Hitung uang di laci, lalu masukkan jumlahnya. Selisih dicatat apa adanya —
                        tidak dibetulkan otomatis.
                    </p>

                    <p class="tabular mt-4 flex justify-between rounded-xl bg-cream px-4 py-3 text-sm">
                        <span class="text-umber-soft">Hitungan sistem</span>
                        <span class="font-bold text-ink">Rp {{ number_format($kasSistem, 0, ',', '.') }}</span>
                    </p>

                    <label for="kas-fisik" class="mt-4 block text-sm font-semibold text-ink">Uang di laci</label>
                    <div class="relative mt-1" x-data="{ nilai: @js((int) $kasFisik) }">
                        <span class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-sm font-semibold text-umber-soft">Rp</span>
                        <input
                            id="kas-fisik"
                            type="text"
                            inputmode="numeric"
                            :value="new Intl.NumberFormat('id-ID').format(nilai)"
                            @input="
                                nilai = Number(String($event.target.value).replace(/\D/g, '')) || 0;
                                $event.target.value = new Intl.NumberFormat('id-ID').format(nilai);
                                $wire.set('kasFisik', nilai, false);
                            "
                            class="tabular h-12 w-full rounded-xl border border-line pr-4 pl-12 text-right text-lg font-semibold text-ink focus:border-terracotta focus:outline-none"
                        >
                    </div>

                    @if ($galat)
                        <p class="mt-3 rounded-xl bg-merah/10 px-4 py-2.5 text-sm text-merah-tua">{{ $galat }}</p>
                    @endif

                    <div class="mt-5 flex gap-2">
                        <button type="button" @click="$refs.panelTutup.close()"
                                class="h-12 flex-1 rounded-xl border border-line text-sm font-semibold text-ink">
                            Batal
                        </button>
                        <button type="button" wire:click="tutupSesi" wire:loading.attr="disabled"
                                class="h-12 flex-1 rounded-xl bg-terracotta text-sm font-bold text-white disabled:opacity-50">
                            Tutup
                        </button>
                    </div>

                    <p x-show="antrean.length > 0" x-cloak class="mt-3 text-xs leading-relaxed text-jingga-tua"
                       x-text="'Masih ada ' + antrean.length + ' transaksi yang belum terkirim. Sambungkan jaringan sebelum menutup shift.'"></p>
                </div>
            </dialog>
        </div>
    @endif

    <style>
        /*
         * Struk dipasang di DOM sepanjang waktu tapi digeser keluar layar, bukan
         * display:none, supaya isi di dalam <template> Alpine tetap terpasang dan
         * perintah cetak tidak menunggu render ulang.
         */
        #struk {
            position: absolute;
            left: -9999px;
            top: 0;
            width: 58mm;
            font-family: var(--font-mono);
            font-size: 10px;
            line-height: 1.45;
            color: #000;
        }

        #struk .s-tengah { text-align: center; }
        #struk .s-tebal { font-weight: 700; }
        #struk .s-baris { display: flex; justify-content: space-between; gap: 4px; }
        #struk .s-garis { border-top: 1px dashed #000; margin: 4px 0; }

        @media print {
            /* Kertas termal 58 mm, panjang mengikuti isi struk. */
            @page { size: 58mm auto; margin: 2mm; }

            /*
             * Menyembunyikan lewat visibility, bukan display. Struk berada di dalam
             * pembungkus Alpine, jadi menyembunyikan elemen non-struk dengan display
             * akan ikut menyembunyikan induknya sendiri.
             */
            body * { visibility: hidden; }
            #struk, #struk * { visibility: visible; }

            #struk {
                left: 0;
                width: auto;
            }
        }
    </style>
</div>

{{--
    Layar stok satu outlet.

    Data dari komponen: $daftar (paginator), $harusBelanja, $ringkasan, $barisKartu,
    $kartu, $outletTersedia, $outletDipakai. Bentuk satu barisnya didokumentasikan di
    App\Actions\Stock\SusunBarisStokAction.

    Tiga keadaan yang paling mudah tercampur di layar ini, dan cara membedakannya:

    - punya_baris = false  → BELUM PERNAH DICATAT. Ditulis "belum dihitung", bukan "0":
      nol adalah pernyataan bahwa raknya kosong, dan itu keterangan yang sama sekali lain.
    - beli = null          → tidak ada konversi satuan; kekurangannya dinyatakan dalam
      satuan dasar apa adanya, bukan dipaksa jadi "dus".
    - perlu_diperiksa      → saldonya mungkin sudah terpotong dua kali oleh penjualan
      offline yang tiba SESUDAH opname. Satu-satunya cara pemilik tahu itu adalah penanda
      di baris ini, jadi penandanya muncul di daftar maupun di blok harus-belanja.
--}}
@php
    $angka = fn ($nilai) => rtrim(rtrim(number_format((float) $nilai, 3, ',', '.'), '0'), ',');

    /*
     * Setiap status disebut SATU-SATU, termasuk yang jatuh ke hijau.
     *
     * `default` di sini pernah hampir jadi cacat yang lebih buruk daripada yang diperbaiki:
     * status baru yang lupa didaftarkan akan tampil hijau "Aman" — bukan sekadar salah,
     * melainkan kebalikan dari keadaan sebenarnya, dan hijau tidak membuat siapa pun
     * memeriksa. Merah setidaknya mengundang orang melihat. Jadi 'aman' ditulis sebagai
     * arm-nya sendiri dan `default` disisakan untuk nilai yang benar-benar tak dikenal,
     * dengan penampilan netral yang tidak mengklaim apa pun.
     */
    $warnaStatus = fn (string $status) => match ($status) {
        'minus', 'habis' => 'merah',
        'menipis' => 'jingga',
        'aman' => 'hijau',
        default => 'netral',
    };

    $labelStatus = fn (string $status) => match ($status) {
        'minus' => 'Minus',
        'habis' => 'Habis',
        'menipis' => 'Menipis',
        'aman' => 'Aman',
        'belum_dihitung' => 'Belum dihitung',
        default => 'Belum dihitung',
    };

    /*
     * Kalimat tindakan untuk blok harus-belanja.
     *
     * Yang dicari pemilik warung bukan "menipis: 4" melainkan "apa yang harus saya beli,
     * berapa banyak" — jadi baris pertamanya selalu perintah dengan angka dan satuan.
     * Empat keadaan sengaja berbunyi BERBEDA, karena tiga di antaranya tidak selesai
     * dengan belanja: barang yang belum pernah dihitung, catatan minus, dan rak kosong
     * yang ambangnya belum disetel.
     *
     * @return array{0: string, 1: string}
     */
    $tindakan = function (array $b) use ($angka): array {
        $dasar = $b['satuan_dasar'] ?? $b['satuan'] ?? '';

        if ($b['beli'] !== null) {
            $satuanBeli = mb_strtolower($b['beli']['satuan']?->label() ?? ($b['satuan'] ?? 'satuan'));
            $satuanDasar = mb_strtolower($b['beli']['satuan_dasar']?->label() ?? $dasar);

            return [
                'Beli '.$angka($b['beli']['jumlah']).' '.$satuanBeli,
                'kurang '.$angka($b['kekurangan']).' '.$satuanDasar.' dari batas minimal '.$angka($b['minimum']).' '.$satuanDasar
                    .' · 1 '.$satuanBeli.' = '.$angka($b['beli']['isi_per_satuan']).' '.$satuanDasar,
            ];
        }

        if ($b['kekurangan'] > 0) {
            return [
                'Beli '.$angka($b['kekurangan']).' '.$dasar,
                'sisa '.$angka($b['sistem']).' '.$dasar.', batas minimal '.$angka($b['minimum']).' '.$dasar,
            ];
        }

        if ($b['status'] === 'minus') {
            return [
                'Catatan minus '.$angka(abs($b['sistem'])).' '.$dasar,
                'Angka minus berarti pencatatannya yang bermasalah, bukan raknya. Belanja tidak menyelesaikan ini — hitung fisiknya dulu.',
            ];
        }

        return [
            'Habis, batas minimal belum diatur',
            'Raknya kosong, tapi jumlah belanjanya belum bisa dihitung. Atur batas minimumnya di daftar bawah.',
        ];
    };
@endphp

<div>
    {{-- ── Angka ringkasan ─────────────────────────────────────────────────
         Layar ini dulu dibuka tanpa satu pun angka: yang pertama terbaca hanyalah prosa
         penjelasan, sementara pertanyaan yang benar-benar dibawa pemilik warung ke sini
         ("berapa uang saya yang sedang berbentuk barang", "berapa yang harus diurus hari
         ini") harus dikumpulkan sendiri dari tabel.

         Gayanya SAMA dengan kartu mini-statistik dasbor — baris mendatar setinggi 90px,
         lencana bundar netral di kiri, label kecil di atas angka besar. Tidak ada gaya baru
         di sini; dua layar yang memakai dua bentuk kartu angka terbaca seperti dua aplikasi.

         Tiga kartu terakhir adalah TOMBOL saringan, bukan angka mati: angka yang tidak bisa
         diklik cuma memberi tahu ada masalah tanpa membawa siapa pun ke barangnya. --}}
    @php
        $rupiah = fn (float $nilai) => 'Rp '.number_format($nilai, 0, ',', '.');
    @endphp

    {{-- Kolom pertama lebih lebar daripada tiga sisanya: isinya nominal rupiah, dan di
         petak empat kolom yang sama lebar "Rp 1.929.800" terpotong menjadi "Rp 1.929.8…".
         Angka uang yang terpotong lebih buruk daripada tidak ditampilkan — pembacanya
         menduga digit yang hilang. --}}
    {{-- mt-2/sm:mt-3 menambal jarak ke kartu judul di atasnya: navbar mengapung dengan
         `sticky top-4 mb-2`, jadi tanpa ini sisa jaraknya hanya ±8px — kartu pertama
         halaman terlihat MENEMPEL ke kartu judul, sementara jarak antar-bagian di bawahnya
         16/20px. Nilainya bukan angka baru, hanya melengkapi irama yang sudah ada. --}}
    {{-- Dua kolom baru dari ≥sm, TIDAK di 390px — dan ini pernah saya salah sekali.
         Dijadikan dua kolom di ponsel, tiap kartu hanya dapat ±150px dan isinya terpotong
         jadi "Nilai per…" / "Rp 1.…". Angka uang yang terpotong lebih buruk daripada tidak
         ditampilkan sama sekali: pembacanya menduga digit yang hilang, dan dugaan itu dipakai
         untuk memutuskan belanja. Kartu "Harus belanja" boleh dua-dua karena isinya nama
         barang pendek, bukan nominal. --}}
    <div class="mt-2 mb-4 grid gap-3 sm:mt-3 sm:mb-5 sm:grid-cols-2 xl:grid-cols-[1.35fr_repeat(3,minmax(0,1fr))]">
        <div class="kartu flex min-h-[5.625rem] items-center gap-4 pr-5 pl-[1.125rem]">
            <span class="lencana-ikon bg-cream-deep text-terracotta">
                <svg viewBox="0 0 24 24" class="size-6" fill="none" aria-hidden="true">
                    <path d="M4 7.5 12 4l8 3.5v9L12 20l-8-3.5v-9Zm0 0 8 3.5m0 0 8-3.5M12 11v9" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </span>
            <div class="min-w-0">
                <p class="truncate text-[0.875rem] font-medium text-umber">Nilai persediaan</p>
                <p class="tabular truncate text-[1.25rem] leading-tight font-bold text-ink">
                    {{ $rupiah($nilaiPersediaan['nilai']) }}
                </p>
                {{-- Barang tanpa harga beli disebut APA ADANYA. Menghitungnya nol diam-diam
                     membuat angka di atas terbaca lebih kecil daripada kenyataan, dan pemilik
                     yang membandingkannya dengan uang belanjanya menyimpulkan pencatatannya
                     kacau — padahal yang kurang hanya harga belinya. --}}
                <p class="mt-0.5 line-clamp-2 text-[0.6875rem] leading-snug {{ $nilaiPersediaan['tanpa_harga'] > 0 ? 'text-jingga-tua' : 'text-umber-soft' }}">
                    @if ($nilaiPersediaan['tanpa_harga'] > 0)
                        {{ $nilaiPersediaan['tanpa_harga'] }} barang belum ada harga belinya
                    @else
                        jumlah sistem × harga beli
                    @endif
                </p>
            </div>
        </div>

        {{-- Tiga kartu berikut memetakan SATU-SATU ke chip saringan di bawahnya. Kartu yang
             menggabungkan dua status (mis. "minus & habis") akan membawa ke daftar yang
             jumlahnya lebih kecil daripada angka di kartunya, dan angka yang tidak cocok
             dengan isi tabel membuat orang berhenti memercayai keduanya. --}}
        @foreach ([
            [
                'nilai' => 'minus',
                'label' => 'Minus',
                'jumlah' => $ringkasan['minus'],
                // Sengaja sependek dua keterangan lainnya: di kolom ~200px, kalimat yang
                // lebih panjang tetap terpotong walau diberi dua baris. Maksudnya yang
                // penting harus utuh — stok minus berarti CATATANNYA salah, bukan raknya
                // berisi minus, dan yang salah membacanya akan mencari barang yang tidak
                // pernah hilang.
                'ket' => 'catatannya, bukan raknya',
                'warna' => 'text-merah-deep',
                'ikon' => 'M12 4.5 21 19.5H3L12 4.5Zm0 5.5v4.5m0 2.7v.3',
            ],
            [
                'nilai' => 'habis',
                'label' => 'Habis',
                'jumlah' => $ringkasan['habis'],
                'ket' => 'raknya kosong sekarang',
                'warna' => 'text-merah-deep',
                'ikon' => 'M5 6.5h14l-1.2 13H6.2L5 6.5Zm3.5 0V4.5h7v2',
            ],
            [
                'nilai' => 'menipis',
                'label' => 'Menipis',
                'jumlah' => $ringkasan['menipis'],
                'ket' => 'sudah di bawah batas minimal',
                'warna' => 'text-jingga-tua',
                'ikon' => 'M5 19.5h14M7 16V9.5M12 16V6M17 16v-4.5',
            ],
        ] as $kartuAngka)
            <button type="button" wire:click="$set('status', '{{ $kartuAngka['nilai'] }}')"
                    aria-pressed="{{ $status === $kartuAngka['nilai'] ? 'true' : 'false' }}"
                    @class([
                        'kartu flex min-h-[5.625rem] cursor-pointer items-center gap-4 pr-5 pl-[1.125rem] text-left transition',
                        'ring-2 ring-terracotta' => $status === $kartuAngka['nilai'],
                        'hover:shadow-md' => $status !== $kartuAngka['nilai'],
                    ])>
                <span class="lencana-ikon bg-cream-deep {{ $kartuAngka['warna'] }}">
                    <svg viewBox="0 0 24 24" class="size-6" fill="none" aria-hidden="true">
                        <path d="{{ $kartuAngka['ikon'] }}" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </span>
                <div class="min-w-0">
                    <p class="truncate text-[0.875rem] font-medium text-umber">{{ $kartuAngka['label'] }}</p>
                    <p class="tabular truncate text-[1.25rem] leading-tight font-bold text-ink">
                        {{ $kartuAngka['jumlah'] }} barang
                    </p>
                    {{-- Dibiarkan turun ke baris kedua, BUKAN `truncate`. Di petak empat
                         kolom, "sudah di bawah ambang minimum" terpotong jadi "sudah di
                         bawah a…" — dan keterangan yang terpotong di tengah kata lebih buruk
                         daripada keterangan yang memakai dua baris: pembacanya berhenti
                         membaca, bukan melanjutkan. Dibatasi dua baris supaya tinggi keempat
                         kartu tetap sama. --}}
                    <p class="mt-0.5 line-clamp-2 text-[0.6875rem] leading-snug {{ $kartuAngka['jumlah'] > 0 ? $kartuAngka['warna'] : 'text-umber-soft' }}">
                        {{ $kartuAngka['ket'] }}
                    </p>
                </div>
            </button>
        @endforeach
    </div>

    <x-kartu-alat
        judul="Stok per outlet"
        jumlah="{{ $daftar->total() }}"
        keterangan="Selalu satu outlet — angka gabungan cabang tidak bisa dibelanjakan."
    >
        <x-slot:aksi>
            {{-- Tautan, bukan tombol wire:click: opname adalah halaman tersendiri, dan
                 tautan bisa dibuka di tab lain saat lembar hitungnya dipegang orang kedua. --}}
            {{-- Seukuran isinya dan SEJAJAR dengan judul seksinya sejak ponsel, bukan tombol
                 selebar kartu yang turun ke bawah judulnya (permintaan pemilik proyek; bentuk
                 selebar kartu adalah permintaan sebelumnya yang digantikan).

                 Tingginya 44px — batas bawah target sentuh, bukan angka yang dipilih supaya
                 muat. Ukurannya persis sama dengan "Daftar stok" di layar hitung stok, jadi
                 pasangan pergi-pulang antara dua layar ini terbaca sebagai satu pasangan.
                 Yang membedakan tindakan utama dari tautan biasa di sini warnanya
                 (tombol-utama terisi penuh), bukan lebarnya. --}}
            <a href="{{ route('owner.stok.opname', $outletDipakai !== null ? ['outlet' => $outletDipakai] : []) }}"
               wire:navigate
               class="tombol-utama h-11 w-auto shrink-0 px-4 text-[0.875rem]">
                <span class="tombol-ikon">
                    <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                        <path d="M6 4h8v12H6V4Zm2.5 3.5h3M8.5 10h3M8.5 12.5h1.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
                    </svg>
                </span>
                {{-- Dua bentuk teks: tombolnya duduk SEJAJAR dengan judul seksinya, jadi
                     lebarnya langsung memakan lebar judul. Terukur di 390px: dengan
                     "Hitung stok sekarang" (211px) sisanya cuma 91px, sehingga "Stok per
                     outlet" pecah dua baris dan lencana jumlahnya turun ke baris ketiga.
                     Bentuk pendek dipakai di bawah 640px saja; maknanya tidak berubah —
                     "sekarang" hanya penegas, dan yang menjelaskan waktunya sudah ada di
                     kalimat di bawah judulnya. --}}
                <span class="sm:hidden">Hitung stok</span>
                <span class="hidden sm:inline">Hitung stok sekarang</span>
            </a>
        </x-slot:aksi>

        <x-slot:saringan>
            {{-- Pencarian selebar penuh, lalu KEDUA dropdown berbagi satu baris di ponsel —
                 bukan tiga kontrol yang menumpuk jadi tiga baris. Di ≥lg ketiganya sebaris.
                 Tidak dipaksa satu baris di 390px: tiga kontrol di situ menyisakan ±120px
                 masing-masing, dan nama cabang ("Benjamin Cabang Seturan") tidak terbaca
                 sama sekali di kotak sesempit itu. --}}
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-[1fr_11rem_13rem]">
                <div class="col-span-2 min-w-0 lg:col-span-1">
                    <label for="cari" class="sr-only">Cari barang</label>
                    <div class="relative">
                        <svg viewBox="0 0 20 20" class="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-umber-soft"
                             fill="none" aria-hidden="true">
                            <circle cx="9" cy="9" r="5.5" stroke="currentColor" stroke-width="1.6" />
                            <path d="m13.5 13.5 3 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                        </svg>
                        <input id="cari" type="search" wire:model.live.debounce.300ms="cari"
                               placeholder="Cari nama barang, SKU, atau barcode…"
                               class="h-11 w-full rounded-xl border border-line bg-white pr-4 pl-11 text-[0.875rem] text-ink focus:border-terracotta focus:outline-none">
                    </div>
                </div>

                <div class="min-w-0">
                    <label for="jenis" class="sr-only">Jenis barang</label>
                    <select id="jenis" wire:model.live="jenis"
                            class="h-11 w-full rounded-xl border border-line bg-white px-3 text-[0.875rem] font-medium text-ink focus:border-terracotta focus:outline-none">
                        <option value="semua">Produk &amp; bahan</option>
                        <option value="produk">Produk saja</option>
                        <option value="bahan">Bahan baku saja</option>
                    </select>
                </div>

                @if ($outletTersedia !== [])
                    {{-- Berbagi baris dengan dropdown jenis (tidak selebar kartu), atas
                         permintaan pemilik proyek. Di layar Opname dropdown ini justru dibuat
                         selebar penuh — di sana cabang yang sedang dilihat menentukan ke mana
                         hasil hitung tersimpan, jadi namanya tidak boleh terpotong. Di sini ia
                         hanya menyaring tampilan, dan salah baca tidak mengubah data apa pun. --}}
                    <div class="min-w-0">
                        <label for="outlet" class="sr-only">Outlet</label>
                        <select id="outlet" wire:model.live="outletId"
                                class="h-11 w-full rounded-xl border border-line bg-white px-3 text-[0.875rem] font-medium text-ink focus:border-terracotta focus:outline-none">
                            @foreach ($outletTersedia as $o)
                                <option value="{{ $o['id'] }}">{{ $o['nama'] }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                {{-- Ringkasan dipasang PADA saringannya, bukan sebagai deretan kartu angka
                     terpisah: angka yang tidak bisa diklik hanya memberi tahu ada masalah
                     tanpa membawa siapa pun ke barangnya. --}}
                {{-- col-span-2 di ponsel. Tanpa itu deret pil ini menempati SATU kolom dari
                     petak dua kolom — terukur 153px dari 318px lebar kartu — sehingga enam
                     dari tujuh pilnya berada di luar layar dan hanya bisa ditemukan dengan
                     menggulir ke samping di dalam kotak selebar ibu jari. --}}
                <div class="col-span-2 -m-px min-w-0 overflow-x-auto p-px lg:col-span-full [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                    {{-- MEMBUNGKUS ke baris berikutnya di ponsel, bukan digulir mendatar.
                         Pil yang harus digulir untuk ditemukan sama saja dengan pil yang tidak
                         ada, dan dua yang paling perlu ditemukan — "Belum dihitung" dan "Perlu
                         dihitung ulang" — justru yang paling ujung. Ketujuhnya TIDAK dipaksa
                         sebaris dengan `flex-1` di 390px: masing-masing cuma kebagian ±50px
                         dan angka di sebelah namanya, yang justru isi utamanya, akan hilang.

                         `grow` membuat pil di setiap baris melar mengisi sisa lebarnya, jadi
                         deretnya rata kanan-kiri alih-alih menggantung setengah baris. Di ≥lg
                         tetap seperti semula: satu baris, setiap pil `flex-1`.

                         Melarnya DIBATASI 20rem, dan barisnya ditengahkan. Di 768px baris
                         kedua kadang hanya berisi satu pil; tanpa batas itu ia melar jadi
                         664px — dan begitu pil itu yang sedang aktif, ia berubah menjadi
                         batang terracotta selebar kartu yang terbaca sebagai tombol, bukan
                         sebagai satu pilihan di antara tujuh. Batasnya sengaja lebih lebar
                         daripada pil terpanjang (168px), jadi tidak pernah ada teks yang
                         terpotong karenanya.

                         overflow-x-auto disisakan sebagai jaring pengaman — kalau satu pil
                         sendirian lebih lebar daripada kartunya (angka lima digit), yang
                         menggulir kotak ini, bukan halamannya. --}}
                    <div class="flex min-w-full flex-wrap items-center justify-center gap-1 rounded-xl bg-white p-1 ring-1 ring-line lg:flex-nowrap">
                        {{-- 'Semua' memakai $ringkasan['semua'] — jumlah baris yang SEBENARNYA,
                             bukan penjumlahan chip di sebelahnya. Bentuk lama menjumlah empat
                             status manual, jadi status kelima membuatnya kurang secara diam-diam:
                             chip berkata 4 sementara tabel menampilkan 5, dan chip yang tidak
                             cocok dengan isi tabel membuat orang berhenti mempercayai keduanya.
                             'perlu_diperiksa' memang tidak boleh ikut dijumlahkan — ia bendera,
                             bukan status, jadi satu baris bisa terhitung di dua tempat. --}}
                        @foreach ([
                            ['semua', 'Semua', $ringkasan['semua']],
                            ['minus', 'Minus', $ringkasan['minus']],
                            ['habis', 'Habis', $ringkasan['habis']],
                            ['menipis', 'Menipis', $ringkasan['menipis']],
                            ['aman', 'Aman', $ringkasan['aman']],
                            ['belum_dihitung', 'Belum dihitung', $ringkasan['belum_dihitung']],
                            ['perlu_diperiksa', 'Perlu dihitung ulang', $ringkasan['perlu_diperiksa']],
                        ] as [$nilai, $teks, $jumlah])
                            <button type="button" wire:click="$set('status', '{{ $nilai }}')"
                                    aria-pressed="{{ $status === $nilai ? 'true' : 'false' }}"
                                    @class([
                                        'flex h-9 max-w-80 shrink-0 grow cursor-pointer items-center justify-center gap-1.5 rounded-lg px-3 text-[0.8125rem] whitespace-nowrap transition lg:max-w-none lg:flex-1',
                                        'bg-terracotta font-semibold text-white' => $status === $nilai,
                                        'font-medium text-umber hover:bg-cream hover:text-ink' => $status !== $nilai,
                                    ])>
                                {{ $teks }}
                                {{-- Angkanya diberi PIL sendiri, bukan diredupkan dengan opacity.
                                     Bentuk lama `text-white opacity-70` di atas terracotta
                                     menghasilkan kontras ±4,2:1 — di bawah 4,5:1, dan angka
                                     itulah isi utama chip-nya. Terukur di peramban: pil putih
                                     dengan tulisan terracotta 7,10:1, dan keadaan tidak aktif
                                     (cream-deep + tinta umber) 9,65:1. --}}
                                <span @class([
                                    'tabular rounded-full px-1.5 py-px text-[0.6875rem] font-semibold',
                                    'bg-white text-terracotta' => $status === $nilai,
                                    'bg-cream-deep text-umber' => $status !== $nilai,
                                ])>{{ $jumlah }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>
        </x-slot:saringan>
    </x-kartu-alat>

    {{-- ── Harus belanja ───────────────────────────────────────────────────
         DAFTAR TINDAKAN, bukan tabel angka. Urutan "paling parah dulu" sudah disusun
         komponen; di sini tidak diurut ulang supaya tidak ada dua definisi untuk hal
         yang sama. --}}
    @if ($harusBelanja->isNotEmpty())
        {{-- Jumlah kartu yang ditampilkan dipilih supaya PETAKNYA SELALU PENUH.
             Grid tiga kolom berisi empat kartu meninggalkan lubang dua kolom di kanan bawah
             — tidak terhitung sebagai cacat oleh alat ukur mana pun, tapi itulah yang
             membuat halaman terbaca setengah jadi. Yang tidak muat disebut di kaki blok,
             jadi tidak ada barang yang hilang tanpa keterangan. --}}
        @php
            $muat = $harusBelanja->count();
            $muat = match (true) {
                $muat >= 6 => 6,
                $muat >= 4 => 4,
                default => $muat,
            };
            $tampil = $harusBelanja->take($muat);
            // DUA kolom sejak ponsel, bukan menumpuk satu per baris. Empat kartu yang
            // menumpuk membuat blok ini setinggi satu layar penuh di HP, dan barang keempat
            // — yang justru paling parah kalau urutannya dibaca dari atas — jatuh di bawah
            // lipatan. Dua kolom membuat keempatnya terlihat sekaligus.
            //
            // Di layar lebar barisnya dipilih supaya tidak ada kartu yatim: 3 kolom kalau
            // jumlahnya habis dibagi 3, kalau tidak 2 kolom.
            // Di ≥lg barisnya dipilih supaya tidak ada kartu yatim: 3 kolom kalau jumlahnya
            // habis dibagi 3, kalau tidak 2 kolom. Di bawah lg tidak dipakai — di situ
            // kartunya digeser mendatar, bukan dipetak.
            $kolomBelanja = match (true) {
                $muat % 3 === 0 => 'lg:grid-cols-3',
                default => 'lg:grid-cols-2',
            };
        @endphp

        <div class="kartu mb-4 overflow-hidden sm:mb-5">
            <div class="border-b border-line-soft px-5 py-4 sm:px-6">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-[1.0625rem] font-bold text-ink">Harus belanja</h2>
                    <span class="tabular rounded-full bg-merah/10 px-2.5 py-0.5 text-[0.75rem] font-semibold text-merah-deep">
                        {{ $harusBelanja->count() }} barang
                    </span>
                </div>
                {{-- Satu kalimat. Yang WAJIB tetap tertulis adalah pembulatan ke ATAS:
                     itu keterangan yang mengubah keputusan di pasar, bukan hiasan. --}}
                <p class="mt-1 text-[0.875rem] text-umber">
                    Paling parah dulu · jumlah belanja dibulatkan ke <span class="font-semibold text-ink">ATAS</span>.
                </p>
            </div>

            {{-- Di bawah lg: satu jalur yang DIGESER mendatar, bukan petak yang memanjang
                 ke bawah. Empat kartu bertumpuk membuat blok ini setinggi satu layar penuh,
                 dan pemilik harus menggulir jauh hanya untuk sampai ke daftar barangnya.

                 Kartu dibuat 78% lebar wadahnya, dan angka itu disengaja: kartu berikutnya
                 IKUT TERLIHAT sepotong di tepi kanan. Tanpa potongan itu tidak ada yang
                 memberi tahu bahwa jalurnya bisa digeser, dan jalur yang tidak diketahui bisa
                 digeser sama saja dengan kartu-kartu berikutnya tidak ada.

                 `snap` supaya berhentinya pas di kartu, bukan menggantung setengah. Batang
                 gulirnya disembunyikan mengikuti pola deret pil di atas — potongan kartunya
                 sudah menjadi penandanya.

                 Di ≥lg kembali jadi petak: layarnya cukup lebar untuk menampilkan semuanya
                 sekaligus, dan menggeser di tetikus lebih repot daripada melihat. --}}
            <div class="flex snap-x snap-mandatory gap-3 overflow-x-auto px-5 py-4 sm:px-6 lg:grid lg:snap-none lg:overflow-visible {{ $kolomBelanja }} [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                @foreach ($tampil as $b)
                    @php [$judulTindakan, $ketTindakan] = $tindakan($b); @endphp

                    {{-- 78% + shrink-0: kartu berikutnya menyembul di tepi kanan, itulah yang
                         memberi tahu jalurnya bisa digeser. Di ≥lg lebarnya dilepas kembali ke
                         petak. --}}
                    <div class="flex w-[78%] shrink-0 snap-start flex-col gap-2 rounded-xl border border-line p-3.5 lg:w-auto lg:shrink lg:snap-align-none">
                        <div class="flex min-w-0 items-start justify-between gap-2">
                            <p class="min-w-0 flex-1 text-[0.9375rem] font-bold text-ink">{{ $b['nama'] }}</p>
                            <x-lencana :warna="$warnaStatus($b['status'])" :denyut="$b['status'] !== 'belum_dihitung'" class="shrink-0">
                                {{ $labelStatus($b['status']) }}
                            </x-lencana>
                        </div>

                        <p class="tabular text-[1rem] leading-tight font-bold text-terracotta">{{ $judulTindakan }}</p>
                        <p class="text-[0.75rem] leading-snug text-umber">{{ $ketTindakan }}</p>

                        <div class="mt-auto flex flex-wrap items-center gap-1.5 pt-1">
                            <span class="rounded-full bg-cream-deep px-2 py-0.5 text-[0.6875rem] font-semibold text-umber">
                                {{ $b['jenis'] === 'produk' ? 'Produk' : 'Bahan baku' }}
                            </span>
                            @if ($b['perlu_diperiksa'])
                                <span class="rounded-full bg-jingga/15 px-2 py-0.5 text-[0.6875rem] font-semibold text-jingga-tua">
                                    perlu dihitung ulang
                                </span>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

            @if ($harusBelanja->count() > $tampil->count())
                <p class="border-t border-line-soft px-5 py-3 text-[0.8125rem] text-umber sm:px-6">
                    {{ $harusBelanja->count() - $tampil->count() }} barang lain juga di bawah batas minimal.
                    Pakai saringan <span class="font-semibold text-ink">Minus</span>,
                    <span class="font-semibold text-ink">Habis</span>, atau
                    <span class="font-semibold text-ink">Menipis</span> di atas untuk melihat semuanya.
                </p>
            @endif
        </div>
    @endif

    {{-- Barang yang belum pernah dihitung diangkat ke spanduk sendiri.
         Ia SENGAJA tidak lagi masuk blok "Harus belanja" (lihat Stok::harusBelanja): blok itu
         cuma punya sembilan kartu dan diurut `sistem − minimum` menaik, sementara barang tanpa
         ambang selalu bernilai 0 di kunci itu — di warung yang baru memasukkan 300 barang,
         kesembilan slotnya habis dipakai barang yang mungkin masih ada di rak, dan kartunya
         bahkan tidak bisa menyebut jumlah belanja. Peringatannya tidak hilang, hanya pindah ke
         bentuk yang lebih jujur: satu kalimat + jalan ke pekerjaan yang benar, yaitu menghitung. --}}
    {{-- Tata letaknya BERTUMPUK di ponsel, sebaris hanya dari 640px ke atas.
         Bentuk lama satu baris flex-wrap dengan dua tombol shrink-0: di 390px kedua tombol
         merebut hampir seluruh lebar, kolom teksnya tergencet jadi ±30px, dan kalimatnya
         jatuh SATU KATA PER BARIS selama belasan baris. Tujuh angka pemeriksa tidak
         menangkapnya — bukan gulir mendatar, bukan tumpang tindih, wadahnya pun tidak
         kosong — jadi cacat ini hanya bisa dilihat di potretnya. --}}
    @if ($ringkasan['belum_dihitung'] > 0 && $status !== 'belum_dihitung')
        <div class="kartu mb-4 flex flex-col gap-3 border border-line px-5 py-4 sm:mb-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <div class="flex min-w-0 items-start gap-3 sm:flex-1">
                <svg viewBox="0 0 20 20" class="mt-0.5 size-4 shrink-0 text-umber-soft" fill="none" aria-hidden="true">
                    <rect x="3.25" y="3.25" width="13.5" height="13.5" rx="3" stroke="currentColor" stroke-width="1.5" />
                    <path d="M7 10h6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                </svg>
                <p class="min-w-0 text-[0.875rem] text-ink">
                    <span class="font-bold">{{ $ringkasan['belum_dihitung'] }} barang belum pernah dihitung di outlet ini.</span>
                    Jumlah belanjanya belum bisa dihitung — hitung fisiknya dulu.
                </p>
            </div>
            {{-- 44px, dan pasangannya sama lebar (flex-1) di layar sempit: tombol sebaris yang
                 lebarnya mengikuti panjang teks terbaca sebagai dua hal yang tidak setara. --}}
            <div class="flex items-center gap-2 sm:shrink-0">
                <button type="button" wire:click="$set('status', 'belum_dihitung')"
                        class="h-11 flex-1 cursor-pointer rounded-xl border border-line px-3.5 text-[0.8125rem] font-semibold text-ink transition-colors hover:bg-cream sm:h-10 sm:flex-none">
                    Lihat barangnya
                </button>
                <a href="{{ route('owner.stok.opname', ['outlet' => $outletDipakai, 'status' => 'belum_pernah']) }}"
                   wire:navigate
                   class="flex h-11 flex-1 items-center justify-center rounded-xl bg-terracotta px-3.5 text-[0.8125rem] font-bold text-white transition-colors hover:bg-terracotta-deep sm:h-10 sm:flex-none">
                    Hitung fisik
                </a>
            </div>
        </div>
    @endif

    {{-- Penanda "perlu dihitung ulang" diangkat ke atas juga: ia tidak selalu berbarengan
         dengan status di bawah ambang, jadi barang yang saldonya masih aman tapi mungkin
         terpotong dua kali tidak akan pernah terlihat di blok harus-belanja. --}}
    @if ($ringkasan['perlu_diperiksa'] > 0 && $status !== 'perlu_diperiksa')
        <div class="kartu mb-4 flex flex-col gap-3 border border-jingga/30 px-5 py-4 sm:mb-5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
            <div class="flex min-w-0 items-start gap-3 sm:flex-1">
                <svg viewBox="0 0 20 20" class="mt-0.5 size-4 shrink-0 text-jingga-tua" fill="none" aria-hidden="true">
                    <circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.5" />
                    <path d="M10 6.5v4M10 13.4v.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                </svg>
                <p class="min-w-0 text-[0.875rem] text-ink">
                    <span class="font-bold">{{ $ringkasan['perlu_diperiksa'] }} barang perlu dihitung ulang.</span>
                    Ada penjualan offline yang masuk setelah opname terakhirnya — saldonya mungkin terpotong dua kali.
                </p>
            </div>
            <button type="button" wire:click="$set('status', 'perlu_diperiksa')"
                    class="h-11 w-full cursor-pointer rounded-xl border border-line px-3.5 text-[0.8125rem] font-semibold text-ink transition-colors hover:bg-cream sm:h-10 sm:w-auto sm:shrink-0">
                Lihat barangnya
            </button>
        </div>
    @endif

    {{-- ── Kartu stok ──────────────────────────────────────────────────────
         Panel DI DALAM alur halaman, bukan dialog mengapung: 30 mutasi tidak pernah muat
         di layar 768px, dan dialog yang harus digulir menyembunyikan baris terakhirnya —
         padahal justru baris terakhir yang menjelaskan angka sekarang. --}}
    @if ($barisKartu !== null)
        <div class="kartu mb-4 overflow-hidden sm:mb-5" wire:key="kartu-{{ $barisKartu['kunci'] }}">
            {{-- Kepala panel: identitas barang di kiri, tiga keterangan barang di kanan,
                 tombol tutup di ujung. Sebelumnya seluruh kepala ini memakai sepertiga kiri
                 saja dan menyisakan bidang kosong selebar dua pertiga panel — sementara
                 pertanyaan yang dibawa orang saat membuka kartu stok ("satuannya apa,
                 ambangnya berapa, terakhir dihitung kapan") tidak terjawab di mana pun tanpa
                 menggulir kembali ke tabel.

                 Kotak-kotaknya memakai POLA YANG SAMA dengan kotak "Sistem"/"Ambang" pada
                 kartu barang di layar sempit: garis rambut, label kecil huruf besar, lalu
                 angkanya di bawah — label dan nilai rata kiri pada satu garis dasar yang
                 sama. Satu kebiasaan untuk hal yang sama, bukan dua.

                 Datanya seluruhnya dari $barisKartu yang sudah ada; tidak ada kueri baru.

                 Jumlah pergerakan TIDAK disebut di sini lagi. Kalimat lamanya berbunyi
                 "30 pergerakan terakhir" sementara daftarnya berhalaman 10 baris dari 31
                 mutasi — keterangan yang salah, dan angka yang benar sudah dicetak oleh
                 navigasi halaman di kakinya. --}}
            @php
                $satuanKartu = $barisKartu['satuan_dasar'] ?? '';
                $konversiKartu = $barisKartu['beli'] !== null
                    ? '1 '.mb_strtolower($barisKartu['beli']['satuan']?->label() ?? '').' = '
                        .$angka($barisKartu['beli']['isi_per_satuan']).' '.$satuanKartu
                    : ($barisKartu['isi_per_satuan'] !== null && $barisKartu['satuan'] !== null
                        ? '1 '.mb_strtolower($barisKartu['satuan']).' = '.$angka($barisKartu['isi_per_satuan']).' '.$satuanKartu
                        : null);
            @endphp

            {{-- flex-wrap + order: di bawah 1024px ketiga kotak keterangan pindah ke barisnya
                 SENDIRI selebar panel (`w-full`), karena di 390px berbagi baris dengan judul
                 menyisakan 41px per kotak dan seluruh isinya luber keluar kotaknya (terukur).
                 Di ≥1024px mereka kembali ke sisi kanan judul — bidang yang tadinya kosong. --}}
            <div class="flex flex-wrap items-start gap-4 border-b border-line px-5 py-4 sm:px-6">
                <div class="order-1 min-w-0 flex-1">
                    <p class="eyebrow text-umber-soft">Riwayat barang</p>
                    <div class="mt-1 flex flex-wrap items-center gap-2">
                        <h2 class="text-[1.0625rem] font-bold text-ink">{{ $barisKartu['nama'] }}</h2>
                        <x-lencana :warna="$warnaStatus($barisKartu['status'])" :denyut="$barisKartu['status'] !== 'belum_dihitung'">
                            {{ $labelStatus($barisKartu['status']) }}
                        </x-lencana>
                    </div>
                    <p class="mt-0.5 text-[0.75rem] text-umber-soft">
                        {{ $barisKartu['jenis'] === 'produk' ? 'Produk' : 'Bahan baku' }}
                        · {{ $barisKartu['kode'] ?: 'tanpa kode' }}
                        @if ($konversiKartu !== null)
                            · {{ $konversiKartu }}
                        @endif
                        · pergerakan terbaru di atas
                    </p>
                </div>

                <div class="order-3 grid w-full min-w-0 grid-cols-3 gap-2 lg:order-2 lg:w-auto lg:max-w-lg lg:flex-1">
                    <div class="min-w-0 rounded-xl border border-line px-3 py-2">
                        <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Sisa sekarang</p>
                        @if ($barisKartu['punya_baris'])
                            <p class="tabular text-[0.9375rem] font-bold text-ink">
                                {{ $angka($barisKartu['sistem']) }}
                                <span class="text-[0.75rem] font-medium text-umber">{{ $satuanKartu }}</span>
                            </p>
                        @else
                            <p class="text-[0.8125rem] font-semibold text-umber-soft">— belum dihitung</p>
                        @endif
                    </div>

                    <div class="min-w-0 rounded-xl border border-line px-3 py-2">
                        <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Batas minimal</p>
                        <p class="tabular text-[0.9375rem] font-bold text-ink">
                            {{ $barisKartu['minimum'] > 0 ? $angka($barisKartu['minimum']) : '—' }}
                            <span class="text-[0.75rem] font-medium text-umber">
                                {{ $barisKartu['minimum'] > 0 ? $satuanKartu : 'tidak dipantau' }}
                            </span>
                        </p>
                    </div>

                    <div class="min-w-0 rounded-xl border border-line px-3 py-2">
                        <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Terakhir dihitung</p>
                        @if ($barisKartu['opname_terakhir_pada'] !== null)
                            <p class="tabular text-[0.8125rem] font-semibold text-ink">
                                {{ $barisKartu['opname_terakhir_pada']->locale('id')->translatedFormat('j M Y') }}
                                <span class="block text-[0.75rem] font-medium text-umber">
                                    {{ $barisKartu['opname_terakhir_pada']->translatedFormat('H:i') }}
                                    {{ $barisKartu['perlu_diperiksa'] ? '· perlu ulang' : '' }}
                                </span>
                            </p>
                        @else
                            <p class="text-[0.8125rem] font-semibold text-umber-soft">belum pernah</p>
                        @endif
                    </div>
                </div>

                <button type="button" wire:click="tutupKartu" aria-label="Tutup kartu stok"
                        class="grid size-10 shrink-0 cursor-pointer place-items-center rounded-lg border border-line text-umber transition-colors hover:bg-cream hover:text-ink">
                    <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                        <path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                    </svg>
                </button>
            </div>

            @if ($kartu->isEmpty())
                {{-- Keadaan kosong memakai x-kosong, komponen yang sama dengan daftar utama:
                     dua bentuk "tidak ada data" di satu halaman terbaca seperti dua aplikasi. --}}
                <div class="px-5 py-5 sm:px-6">
                    <x-kosong judul="Belum ada pergerakan tercatat"
                              keterangan="Riwayat barang terisi sendiri begitu ada penjualan, penerimaan barang, atau hasil hitung stok yang disimpan." />
                </div>
            @else
                {{-- Kartu di layar sempit, tabel di layar lebar — pola yang sama dengan
                     daftar produk. --}}
                <ul class="divide-y divide-line-soft lg:hidden">
                    @foreach ($kartu as $m)
                        <li class="px-5 py-3.5">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-[0.875rem] font-semibold text-ink">{{ $m->tipe->label() }}</p>
                                    <p class="tabular text-[0.75rem] text-umber-soft">
                                        {{ $m->created_at?->locale('id')->translatedFormat('j M Y, H:i') ?? '—' }}
                                        · {{ $m->olehUser?->name ?? 'sistem' }}
                                    </p>
                                </div>
                                <div class="shrink-0 text-right">
                                    <p @class([
                                        'tabular text-[0.9375rem] font-bold',
                                        'text-hijau-tua' => (float) $m->jumlah > 0,
                                        'text-merah-deep' => (float) $m->jumlah < 0,
                                        'text-umber' => (float) $m->jumlah === 0.0,
                                    ])>
                                        {{ (float) $m->jumlah > 0 ? '+' : '' }}{{ $angka($m->jumlah) }}
                                    </p>
                                    <p class="tabular text-[0.75rem] text-umber-soft">sisa {{ $angka($m->saldo_sesudah) }}</p>
                                </div>
                            </div>
                            {{-- Catatan hanya ditulis kalau ada. "— · —" pada baris penjualan
                                 (yang memang tidak punya alasan maupun catatan) menambah satu
                                 baris teks di setiap kartu tanpa mengatakan apa pun. --}}
                            <p class="mt-1 text-[0.75rem] text-umber-soft">
                                {{ $m->alasan?->label() ?? '—' }}@if ($m->catatan) · <span class="text-umber">{{ $m->catatan }}</span>@endif
                            </p>
                        </li>
                    @endforeach
                </ul>

                {{-- Enam kolom, bukan tujuh. ALASAN dulu berdiri sendiri dan hampir seluruh
                     barisnya "—" (penjualan & penerimaan tidak punya alasan opname), jadi satu
                     kolom penuh dipakai untuk memajang tanda hubung. Sekarang ia menempel di
                     bawah JENIS — tempatnya memang di situ: ia keterangan atas jenis
                     pergerakannya, bukan kategori yang sejajar.

                     WAKTU dipecah dua baris: tanggal lalu jamnya. Satu baris "4 Agt 2026,
                     18:09" yang terulang sepuluh kali membuat kolom pertama jadi tembok teks
                     yang berat sama dengan angka di sebelahnya. --}}
                <div class="hidden lg:block">
                    <table class="w-full table-fixed text-center">
                        <thead>
                            <tr class="border-b border-line">
                                {{-- Lebar PERSEN + table-fixed, bukan lebar tetap sebagian.
                                     Kalau ada kolom yang tidak diberi lebar, seluruh sisa
                                     lebar panel menumpuk di kolom itu — dan lubangnya sekadar
                                     BERPINDAH: dulu menganga di kanan kolom Catatan, lalu
                                     menganga di tengah begitu Pergerakan yang menyerapnya.
                                     Dengan persen, sisa itu terbagi ke semua kolom sekaligus,
                                     jadi tidak ada satu bidang kosong yang mencolok.

                                     CATATAN sengaja tidak berkolom sendiri: ia turun jadi
                                     baris kedua di Pergerakan, tempat ia memang menjelaskan —
                                     "Stok Keluar · Penjualan TRX-…" terbaca sebagai satu
                                     keterangan, bukan dua kolom yang harus dipasangkan mata. --}}
                                <th class="w-[18%] px-5 py-3 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Waktu</th>
                                <th class="w-[38%] px-5 py-3 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Pergerakan</th>
                                <th class="w-[12%] px-5 py-3 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Jumlah</th>
                                <th class="w-[12%] px-5 py-3 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Sisa</th>
                                <th class="w-[20%] px-5 py-3 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Oleh</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            @foreach ($kartu as $m)
                                <tr class="transition-colors hover:bg-cream/60">
                                    <td class="tabular px-5 py-3 text-[0.8125rem]">
                                        <span class="block text-umber">
                                            {{ $m->created_at?->locale('id')->translatedFormat('j M Y') ?? '—' }}
                                        </span>
                                        <span class="block text-[0.75rem] text-umber-soft">
                                            {{ $m->created_at?->translatedFormat('H:i') ?? '—' }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3 text-[0.8125rem]">
                                        <span class="block font-semibold text-ink">{{ $m->tipe->label() }}</span>
                                        @php
                                            // Alasan dan catatan digabung dengan pemisah titik tengah,
                                            // dan yang kosong DIBUANG — bukan ditulis "—". Deretan
                                            // "— · —" hanya menambah tanda baca tanpa menambah
                                            // keterangan, dan mata tetap harus memeriksanya satu-satu.
                                            $ket = collect([$m->alasan?->label(), $m->catatan])
                                                ->filter(fn ($x) => filled($x))
                                                ->implode(' · ');
                                        @endphp
                                        <span class="block text-[0.75rem] text-umber-soft">{{ $ket !== '' ? $ket : '—' }}</span>
                                    </td>
                                    <td @class([
                                        'tabular px-5 py-3 text-center text-[0.9375rem] font-bold',
                                        'text-hijau-tua' => (float) $m->jumlah > 0,
                                        'text-merah-deep' => (float) $m->jumlah < 0,
                                        'text-umber-soft' => (float) $m->jumlah === 0.0,
                                    ])>
                                        {{ (float) $m->jumlah > 0 ? '+' : '' }}{{ $angka($m->jumlah) }}
                                    </td>
                                    <td class="tabular px-5 py-3 text-center text-[0.8125rem] text-umber">{{ $angka($m->saldo_sesudah) }}</td>
                                    <td class="px-5 py-3 text-[0.8125rem] text-umber">{{ $m->olehUser?->name ?? 'sistem' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            {{-- Navigasi halaman riwayat WAJIB dirender: kartu stok dipakai untuk
                 menelusuri ke belakang, dan daftar yang memotong diam-diam tanpa penunjuk
                 halaman membuat orang menyimpulkan tidak ada mutasi sebelum itu. --}}
            @if (! $kartu->isEmpty() && method_exists($kartu, 'hasPages') && $kartu->hasPages())
                <div class="border-t border-line-soft px-5 py-3 sm:px-6">
                    {{ $kartu->links() }}
                </div>
            @endif
        </div>
    @endif

    {{-- ── Daftar ──────────────────────────────────────────────────────────── --}}
    @if ($daftar->isEmpty())
        <x-kosong judul="Tidak ada barang yang cocok"
                  keterangan="Coba ubah kata pencarian atau saringannya. Menu berbasis resep tidak muncul di sini — yang berkurang saat menu terjual adalah bahan bakunya.">
            <x-slot:aksi>
                <button type="button" wire:click="$set('status', 'semua')"
                        class="h-11 cursor-pointer rounded-xl bg-terracotta px-5 text-[0.875rem] font-bold text-white transition-colors hover:bg-terracotta-deep">
                    Tampilkan semua
                </button>
            </x-slot:aksi>
        </x-kosong>
    @else
        {{-- Kartu di <lg, tabel di ≥lg. Tabel yang dipaksa masuk ke ponsel menuntut gulir
             mendatar, dan kolom jumlah justru yang pertama hilang dari pandangan. --}}
        <div class="space-y-3 lg:hidden">
            @foreach ($daftar as $b)
                <div class="kartu p-4" wire:key="kartu-baris-{{ $b['kunci'] }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="text-[0.9375rem] font-bold text-ink">{{ $b['nama'] }}</p>
                            <p class="mt-0.5 text-[0.75rem] text-umber">
                                {{ $b['jenis'] === 'produk' ? 'Produk' : 'Bahan baku' }}
                                · {{ $b['kode'] ?: 'tanpa kode' }}
                                @unless ($b['aktif']) · nonaktif @endunless
                            </p>
                        </div>
                        <x-lencana :warna="$warnaStatus($b['status'])" :denyut="$b['status'] !== 'belum_dihitung'" class="shrink-0">
                            {{ $labelStatus($b['status']) }}
                        </x-lencana>
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-3">
                        <div class="min-w-0 rounded-xl border border-line px-3 py-2">
                            <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Tercatat</p>
                            @if ($b['punya_baris'])
                                <p class="tabular text-[0.9375rem] font-bold text-ink">
                                    {{ $angka($b['sistem']) }}
                                    <span class="text-[0.75rem] font-medium text-umber">{{ $b['satuan_dasar'] ?? '' }}</span>
                                </p>
                            @else
                                <p class="text-[0.8125rem] font-semibold text-umber-soft">— belum dihitung</p>
                            @endif
                        </div>

                        <div class="min-w-0 rounded-xl border border-line px-3 py-2">
                            <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Batas minimal</p>
                            <p class="tabular text-[0.9375rem] font-bold text-ink">
                                {{ $b['minimum'] > 0 ? $angka($b['minimum']) : '—' }}
                                <span class="text-[0.75rem] font-medium text-umber">
                                    {{ $b['minimum'] > 0 ? ($b['satuan_dasar'] ?? '') : 'tidak dipantau' }}
                                </span>
                            </p>
                        </div>
                    </div>

                    <p class="mt-2 text-[0.75rem] text-umber">
                        Terakhir dihitung:
                        {{ $b['opname_terakhir_pada']?->locale('id')->translatedFormat('j M Y, H:i') ?? 'belum pernah' }}
                        @if ($b['perlu_diperiksa'])
                            <span class="ml-1 rounded-full bg-jingga/15 px-2 py-0.5 text-[0.6875rem] font-semibold text-jingga-tua">
                                perlu dihitung ulang
                            </span>
                        @endif
                    </p>

                    @if ($ambangKunci === $b['kunci'])
                        <div class="mt-3 rounded-xl border border-line p-3">
                            <label for="ambang-hp-{{ $b['kunci'] }}" class="block text-[0.8125rem] font-semibold text-ink">
                                Batas minimal ({{ $b['satuan_dasar'] ?? 'satuan' }})
                            </label>
                            {{-- value= WAJIB ditulis sendiri: Livewire tidak mencetak nilai
                                 awal untuk kolom ber-wire:model, jadi tanpa ini kolomnya
                                 terbuka KOSONG walau ambangnya sudah 4 — dan menekan Enter
                                 di kolom kosong justru mematikan pemantauan barang itu. --}}
                            <input id="ambang-hp-{{ $b['kunci'] }}" type="text" inputmode="decimal"
                                   wire:model="ambangNilai" wire:keydown.enter="simpanAmbang" placeholder="0"
                                   value="{{ $ambangNilai }}"
                                   class="tabular mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-4 text-[0.9375rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                            <p class="mt-1 text-[0.75rem] text-umber-soft">
                                Kosong atau 0 berarti tidak dipantau — barangnya tidak masuk daftar harus belanja.
                            </p>
                            @error('ambangNilai')
                                <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                            @enderror
                            {{-- Tinggi 44px, bukan 40: sepasang tombol selebar kartu ditekan
                                 dengan jempol sambil memegang lembar hitung, dan 40px baru
                                 cukup untuk tombol ikon yang berdiri sendiri. --}}
                            <div class="mt-3 flex gap-2">
                                <button type="button" wire:click="simpanAmbang"
                                        class="h-11 flex-1 cursor-pointer rounded-xl bg-terracotta px-3 text-[0.8125rem] font-bold text-white transition-colors hover:bg-terracotta-deep">
                                    Simpan batas
                                </button>
                                <button type="button" wire:click="batalUbahAmbang"
                                        class="h-11 flex-1 cursor-pointer rounded-xl border border-line px-3 text-[0.8125rem] font-semibold text-ink transition-colors hover:bg-cream">
                                    Batal
                                </button>
                            </div>
                        </div>
                    @else
                        {{-- HIERARKI: "Kartu stok" tindakan utama (isian penuh), "Setel ambang"
                             tindakan kedua (garis rambut). Dua tombol bergaya sama membuat
                             keduanya terbaca sama penting, padahal yang dicari saat sebuah
                             angka terasa aneh selalu riwayatnya — dan itu pula satu-satunya
                             tombol berwarna di baris tabel versi layar lebar, jadi kedua
                             tampilan sekarang menunjuk tindakan utama yang sama.
                             Lebarnya seragam (flex-1) dan tingginya 44px untuk sentuhan. --}}
                        <div class="mt-3 flex gap-2">
                            <button type="button" wire:click="bukaKartu('{{ $b['kunci'] }}')"
                                    class="h-11 flex-1 cursor-pointer rounded-xl bg-terracotta px-3 text-[0.8125rem] font-bold text-white transition-colors hover:bg-terracotta-deep">
                                {{ $kartuKunci === $b['kunci'] ? 'Tutup kartu stok' : 'Riwayat barang' }}
                            </button>
                            <button type="button" wire:click="ubahAmbang('{{ $b['kunci'] }}')"
                                    class="h-11 flex-1 cursor-pointer rounded-xl border border-line px-3 text-[0.8125rem] font-semibold text-ink transition-colors hover:bg-cream">
                                Atur batas
                            </button>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="kartu hidden overflow-hidden lg:block">
            <table class="w-full table-fixed text-center">
                <thead>
                    {{-- Perataan judul kolom DISAMAKAN dengan perataan isinya, satu per satu:
                         Barang kiri/kiri · Sistem kanan/kanan · Ambang kanan/kanan ·
                         Status tengah/tengah · Opname terakhir kiri/kiri · Kartu stok
                         kanan/kanan. Judul yang tidak berdiri di atas datanya membuat mata
                         menghitung ulang kolomnya setiap kali turun satu baris.

                         `pr-8` pada AMBANG bukan angka karangan: isinya tombol pil dengan
                         padding dalam 12px, jadi angkanya berhenti 12px lebih ke dalam
                         daripada tepi sel. Tanpa tambahan itu judulnya melayang 12px di
                         sebelah kanan angkanya — sudah dibandingkan kotaknya di peramban. --}}
                    <tr class="border-b border-line">
                        {{-- Lebar PERSEN + table-fixed, alasan yang sama dengan tabel riwayat
                             di panel kartu stok: kolom tanpa lebar akan menyerap SELURUH sisa
                             lebar panel, dan lubangnya cuma berpindah tempat. Dibagi persen,
                             sisanya tersebar rata dan jarak antar kolom terbaca seragam. --}}
                        <th class="w-[22%] px-5 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Barang</th>
                        <th class="w-[12%] px-5 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Tercatat</th>
                        <th class="w-[20%] py-3.5 pr-8 pl-5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Batas minimal</th>
                        <th class="w-[16%] px-5 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Status</th>
                        <th class="w-[20%] px-5 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Terakhir dihitung</th>
                        {{-- Kolom terakhir DIBERI NAMA. Ikon tanpa keterangan di ujung baris
                             membuat orang harus mengklik untuk tahu apa yang akan terjadi;
                             judul kolomnya cukup untuk menjelaskannya sekali untuk semua baris. --}}
                        <th class="w-[10%] px-5 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Riwayat</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-soft">
                    @foreach ($daftar as $b)
                        <tr class="transition-colors hover:bg-cream/60" wire:key="baris-{{ $b['kunci'] }}">
                            <td class="px-5 py-3.5">
                                <div class="min-w-0">
                                    <p class="truncate text-[0.9375rem] font-semibold text-ink">{{ $b['nama'] }}</p>
                                    <p class="truncate text-[0.75rem] text-umber-soft">
                                        {{ $b['jenis'] === 'produk' ? 'Produk' : 'Bahan baku' }}
                                        · {{ $b['kode'] ?: 'tanpa kode' }}
                                        @unless ($b['aktif']) · nonaktif @endunless
                                    </p>
                                </div>
                            </td>

                            {{-- "belum dihitung", BUKAN "0": nol adalah pernyataan bahwa
                                 barangnya habis, dan barang yang belum pernah dicatat tidak
                                 pernah menyatakan itu. --}}
                            <td class="tabular px-5 py-3.5 text-center text-[0.9375rem]">
                                @if ($b['punya_baris'])
                                    <span @class([
                                        'font-bold',
                                        'text-merah-deep' => in_array($b['status'], ['minus', 'habis'], true),
                                        'text-jingga-tua' => $b['status'] === 'menipis',
                                        'text-ink' => $b['status'] === 'aman',
                                    ])>{{ $angka($b['sistem']) }}</span>
                                    <span class="block text-[0.6875rem] text-umber-soft">{{ $b['satuan_dasar'] ?? '—' }}</span>
                                @else
                                    <span class="text-umber-soft">—</span>
                                    <span class="block text-[0.6875rem] text-umber-soft">belum dihitung</span>
                                @endif
                            </td>

                            {{-- Ambang bisa diubah di tempat: ini SATU-SATUNYA layar tempat
                                 ambang bahan baku bisa disetel, dan tanpa ambang tidak ada
                                 satu pun barang yang bisa disebut "kurang". --}}
                            <td class="px-5 py-3.5">
                                @if ($ambangKunci === $b['kunci'])
                                    <div class="flex items-start justify-end gap-2">
                                        <div class="w-28 min-w-0">
                                            <label for="ambang-{{ $b['kunci'] }}" class="sr-only">
                                                Batas minimal {{ $b['nama'] }}
                                            </label>
                                            <input id="ambang-{{ $b['kunci'] }}" type="text" inputmode="decimal"
                                                   wire:model="ambangNilai" wire:keydown.enter="simpanAmbang"
                                                   placeholder="0" value="{{ $ambangNilai }}"
                                                   class="tabular h-10 w-full rounded-lg border border-line bg-white px-3 text-center text-[0.875rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                                        </div>
                                        <x-aksi warna="utama" label="Simpan batas minimal {{ $b['nama'] }}"
                                                class="size-10" wire:click="simpanAmbang">
                                            <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                                <path d="m5 10.5 3.5 3.5L15 7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                                            </svg>
                                        </x-aksi>
                                        <x-aksi warna="netral" label="Batal ubah batas" class="size-10"
                                                wire:click="batalUbahAmbang">
                                            <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                                <path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                                            </svg>
                                        </x-aksi>
                                    </div>
                                    @error('ambangNilai')
                                        <p class="mt-1.5 text-center text-[0.75rem] text-merah-deep">{{ $message }}</p>
                                    @enderror
                                @else
                                    {{-- Terbaca BISA DITEKAN tanpa perlu dicoba, dan tanpa
                                         bergantung pada hover — layar ini dipakai di tablet dan
                                         HP, dan di sana hover tidak ada sama sekali (aturan
                                         CLAUDE.md). Bentuknya pil berlatar cream dengan pensil
                                         terracotta yang SELALU terlihat: itu bahasa "tombol",
                                         bukan "kolom isian" seperti kotak putih bergaris yang
                                         sebelumnya membuat seluruh tabel terlihat sebagai
                                         formulir. Pensilnya 16px (size-4) dan berwarna
                                         terracotta di atas cream — terukur 6,6:1.

                                         Ikon di KIRI angka, bukan di kanan: dengan ikon di
                                         kanan, angkanya tidak pernah berhenti di tepi yang sama
                                         dengan judul kolomnya, dan judul "Ambang" jadi melayang
                                         tidak di atas datanya.

                                         Kedua keadaan (sudah ada ambang / belum) memakai bentuk
                                         yang SAMA — sebelumnya yang sudah terisi hampir tak
                                         terlihat sebagai tombol.

                                         aria-label menyebut nama barangnya: delapan tombol
                                         berbunyi "ubah ambang" tanpa nama tidak bisa dipakai
                                         dengan pembaca layar. --}}
                                    <button type="button" wire:click="ubahAmbang('{{ $b['kunci'] }}')"
                                            class="tabular mx-auto flex h-10 cursor-pointer items-center gap-2 rounded-lg bg-cream px-3 text-[0.875rem] text-ink transition-colors hover:bg-cream-deep"
                                            aria-label="Ubah batas minimal {{ $b['nama'] }}"
                                            title="Ubah batas minimal {{ $b['nama'] }}">
                                        <svg viewBox="0 0 20 20" class="size-4 shrink-0 text-terracotta" fill="none" aria-hidden="true">
                                            <path d="M13 3.5 16.5 7 8 15.5H4.5V12L13 3.5Z" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                        @if ($b['minimum'] > 0)
                                            <span class="font-semibold">{{ $angka($b['minimum']) }}</span>
                                            <span class="text-[0.75rem] text-umber-soft">{{ $b['satuan_dasar'] ?? '' }}</span>
                                        @else
                                            <span class="text-[0.8125rem] font-semibold text-terracotta">Atur batas</span>
                                        @endif
                                    </button>
                                @endif
                            </td>

                            <td class="px-5 py-3.5 text-center">
                                <x-lencana :warna="$warnaStatus($b['status'])" :denyut="$b['status'] !== 'belum_dihitung'">{{ $labelStatus($b['status']) }}</x-lencana>
                            </td>

                            {{-- "belum pernah" MEREDUP, tanggal sungguhan tetap penuh.
                                 Dengan satu warna untuk keduanya, kata yang terulang di enam
                                 baris punya berat yang sama dengan keterangan yang sebenarnya
                                 membawa informasi — dan mata berhenti membedakan keduanya. --}}
                            <td class="px-5 py-3.5 text-[0.8125rem]">
                                @if ($b['opname_terakhir_pada'] !== null)
                                    <span class="tabular block text-umber">
                                        {{ $b['opname_terakhir_pada']->locale('id')->translatedFormat('j M Y, H:i') }}
                                    </span>
                                @else
                                    <span class="block text-umber-soft/80">belum pernah</span>
                                @endif
                                @if ($b['perlu_diperiksa'])
                                    <span class="mt-1 inline-block rounded-full bg-jingga/15 px-2 py-0.5 text-[0.6875rem] font-semibold text-jingga-tua">
                                        perlu dihitung ulang
                                    </span>
                                @endif
                            </td>

                            <td class="px-5 py-3.5">
                                <div class="flex items-center justify-end">
                                    <x-aksi warna="utama"
                                            label="{{ $kartuKunci === $b['kunci'] ? 'Tutup kartu stok '.$b['nama'] : 'Riwayat barang '.$b['nama'] }}"
                                            wire:click="bukaKartu('{{ $b['kunci'] }}')">
                                        <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                            <path d="M4 5.5h12M4 10h12M4 14.5h8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                                        </svg>
                                    </x-aksi>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $daftar->links() }}</div>
    @endif
</div>

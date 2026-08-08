{{--
    Daftar nota belanja satu tenant (bisa disaring per outlet).

    Data dari komponen: $daftar (paginator PurchaseOrder), $notaRincian, $barisRincian
    (paginator PurchaseOrderItem, pageName 'baris'), $ringkasan, $outletTersedia,
    $outletDipakai.

    Bentuknya MENYALIN layar Stok, bukan merancang ulang: kartu angka setinggi 90px,
    x-kartu-alat untuk kepala + saringan, panel rincian dengan pola panel "Riwayat barang",
    kartu di <lg dan tabel table-fixed di ≥lg. Dua layar owner yang memakai dua bentuk
    kartu angka terbaca seperti dua aplikasi.

    Bahasa layarnya kata warung, bukan kata gudang: "nota belanja" bukan "purchase order",
    "beli dari" bukan "supplier", "barang sudah datang" bukan "diterima/draft".
--}}
@php
    $rupiah = fn ($nilai) => 'Rp '.number_format((float) $nilai, 0, ',', '.');
    $angka = fn ($nilai) => rtrim(rtrim(number_format((float) $nilai, 3, ',', '.'), '0'), ',');

    /*
     * Status dokumen diterjemahkan ke kalimat yang dipakai orang warung.
     *
     * Setiap status disebut SATU-SATU dan `default` disisakan untuk nilai yang benar-benar
     * tidak dikenal — pola yang sama dengan layar Stok. Nota lama dari data demo bisa
     * berstatus 'draft'/'dikirim'; menyembunyikannya berarti menghilangkan dokumen yang
     * ditunjuk oleh mutasi stok yang sudah terjadi.
     */
    $labelStatus = fn (?\App\Enums\DocumentStatus $status) => match ($status) {
        \App\Enums\DocumentStatus::Diterima => 'Barang sudah datang',
        \App\Enums\DocumentStatus::Dibatalkan => 'Dibatalkan',
        \App\Enums\DocumentStatus::Dikirim => 'Masih di jalan',
        \App\Enums\DocumentStatus::Draft => 'Belum masuk stok',
        default => 'Belum diketahui',
    };

    $warnaStatus = fn (?\App\Enums\DocumentStatus $status) => match ($status) {
        \App\Enums\DocumentStatus::Diterima => 'hijau',
        \App\Enums\DocumentStatus::Dibatalkan => 'merah',
        \App\Enums\DocumentStatus::Dikirim => 'jingga',
        default => 'netral',
    };

    /*
     * Nota yang MASIH BISA ditandai datang.
     *
     * Dipakai di tiga tempat (kartu daftar, baris tabel, kaki panel rincian), jadi
     * syaratnya ditulis SEKALI di sini. Bentuk lama dari cacat yang sudah pernah terjadi di
     * layar lain: syarat yang sama diketik ulang di beberapa tempat lalu salah satunya
     * ketinggalan saat statusnya bertambah, sehingga satu tata letak menampilkan tombol yang
     * tata letak lain sembunyikan.
     *
     * Dibatalkan dikecualikan — barang yang notanya sudah dibatalkan tidak boleh masuk stok.
     * Diterima juga: tombolnya tidak perlu ada untuk nota yang barangnya memang sudah ada di
     * rak, walau aksinya sendiri idempoten.
     */
    $bisaDitandaiDatang = fn (?\App\Enums\DocumentStatus $status) => $status !== null
        && $status !== \App\Enums\DocumentStatus::Diterima
        && $status !== \App\Enums\DocumentStatus::Dibatalkan;

    $menunggu = $ringkasan['menunggu'];

    /*
     * Umur nota tertua dibacakan, bukan dicetak sebagai angka telanjang.
     *
     * "paling lama 0 hari" adalah kalimat yang tidak pernah diucapkan siapa pun; nota yang
     * baru dipesan hari ini memang belum punya umur. `umur_hari` bisa null (tidak ada nota
     * yang menunggu) DAN bisa 0 (dipesan hari ini) — keduanya keadaan yang berbeda dan
     * dibedakan di sini, karena null berarti kartunya tidak punya cerita sama sekali.
     */
    $umurMenunggu = match (true) {
        $menunggu['umur_hari'] === null => null,
        $menunggu['umur_hari'] < 1 => 'baru dipesan hari ini',
        default => 'paling lama '.$menunggu['umur_hari'].' hari',
    };
@endphp

<div>
    {{-- ── Angka ringkasan ─────────────────────────────────────────────────
         Dua pertanyaan yang dibawa pemilik ke layar ini: "berapa uang saya yang keluar
         bulan ini" dan "dari berapa nota". Keduanya tidak bisa dikumpulkan dari tabel yang
         berhalaman 10 baris.

         Bentuknya SAMA PERSIS dengan kartu angka layar Stok: baris mendatar min 90px,
         lencana bundar di kiri, label kecil di atas angka. Di bawah sm ikonnya 36px dan
         angkanya 0,9375rem supaya nominal rupiah UTUH — angka uang yang terpotong lebih
         buruk daripada tidak ditampilkan, karena pembacanya menduga digit yang hilang.

         EMPAT kartu, jadi petak dua kolom di ponsel terisi penuh 2×2 — tidak ada lagi
         kartu yang harus dilebarkan col-span-2 untuk menutup petak yang menganga. Di ≥lg
         keempatnya sebaris, dan dua kartu pertama (keduanya berisi NOMINAL) diberi porsi
         lebih besar: angka uang yang pecah dua baris adalah cacat tersendiri di CLAUDE.md,
         sementara "5 nota" muat di kolom sesempit apa pun. --}}
    <div class="mt-2 mb-4 grid grid-cols-2 gap-3 sm:mt-3 sm:mb-5 lg:grid-cols-[1.3fr_1.3fr_minmax(0,1fr)_minmax(0,1fr)]">
        <div class="kartu flex min-h-[5.625rem] items-center gap-2.5 px-3.5 sm:gap-4 sm:pr-5 sm:pl-[1.125rem]">
            <span class="lencana-ikon size-9 bg-cream-deep text-terracotta sm:size-[3.25rem]">
                <svg viewBox="0 0 24 24" class="size-5 sm:size-6" fill="none" aria-hidden="true">
                    <path d="M4 6.5h3l2 9h9l2-6.5H8" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                    <circle cx="10" cy="19" r="1.3" stroke="currentColor" stroke-width="1.4" />
                    <circle cx="17" cy="19" r="1.3" stroke="currentColor" stroke-width="1.4" />
                </svg>
            </span>
            <div class="min-w-0">
                <p class="text-[0.75rem] font-medium text-umber sm:text-[0.875rem]">Belanja bulan ini</p>
                <p class="tabular text-[0.9375rem] leading-tight font-bold break-words text-ink sm:text-[1.25rem]">
                    {{ $rupiah($ringkasan['belanja']) }}
                </p>
                {{-- Kalimat ini SALAH sebelumnya ("tanpa nota yang dibatalkan"), dan salahnya
                     bukan soal kata: angkanya hanya menjumlah nota yang barangnya sudah
                     datang, jadi keterangan yang menyebut satu-satunya pengecualian membuat
                     pemilik menyimpulkan nota yang barangnya belum sampai IKUT terhitung.
                     Sesudah itu ia mencari selisihnya di tempat yang salah. Uang untuk barang
                     yang belum ada di rak punya kartunya sendiri di sebelah.

                     Dipendekkan sampai MUAT DUA BARIS di kolom ±135px pada 390px. Bentuk
                     panjangnya ("nota yang dibatalkan tidak ikut dihitung") terpotong di
                     tengah kata, dan keterangan yang terpotong di tengah kata lebih buruk
                     daripada keterangan yang lebih ringkas: pembacanya berhenti membaca. --}}
                <p class="mt-0.5 line-clamp-2 text-[0.6875rem] leading-snug text-umber-soft">
                    yang barangnya sudah datang
                </p>
            </div>
        </div>

        {{-- ── Menunggu datang ──────────────────────────────────────────────
             Kartu ini yang membuat keadaan "belum datang" punya wujud. Tanpa ia, nota yang
             barangnya belum sampai hanya bisa ditemukan dengan menyaring daftar — dan yang
             tidak terlihat tidak pernah ditanyakan ke grosirnya.

             SENGAJA tidak dibatasi bulan ini (komponennya juga tidak): nota yang menggantung
             sejak bulan lalu justru yang paling perlu ditagih. Karena itu umur nota tertua
             ikut disebut — "paling lama 19 hari" adalah pertanyaan, sedangkan "3 nota
             menunggu" cuma angka. --}}
        <div class="kartu flex min-h-[5.625rem] items-center gap-2.5 px-3.5 sm:gap-4 sm:pr-5 sm:pl-[1.125rem]">
            <span class="lencana-ikon size-9 bg-cream-deep sm:size-[3.25rem] {{ $menunggu['nota'] > 0 ? 'text-jingga-tua' : 'text-umber-soft' }}">
                <svg viewBox="0 0 24 24" class="size-5 sm:size-6" fill="none" aria-hidden="true">
                    <path d="M3 7.5h9v8H3v-8Zm9 2.5h4l2.5 2.5v3H12v-5.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                    <circle cx="7" cy="18" r="1.4" stroke="currentColor" stroke-width="1.4" />
                    <circle cx="16" cy="18" r="1.4" stroke="currentColor" stroke-width="1.4" />
                </svg>
            </span>
            <div class="min-w-0">
                <p class="text-[0.75rem] font-medium text-umber sm:text-[0.875rem]">Menunggu datang</p>
                {{-- Nominal kalau ada yang ditunggu, kalimat kalau tidak ada. "Rp 0" di kartu
                     ini terbaca sebagai barang gratis yang sedang di jalan, bukan sebagai
                     "tidak ada yang ditunggu" — dan nol yang ambigu di kartu uang adalah
                     tepat jenis angka yang membuat orang berhenti memercayai kartunya. --}}
                <p class="tabular text-[0.9375rem] leading-tight font-bold break-words text-ink sm:text-[1.25rem]">
                    {{ $menunggu['nota'] > 0 ? $rupiah($menunggu['nilai']) : 'Tidak ada' }}
                </p>
                <p class="mt-0.5 line-clamp-2 text-[0.6875rem] leading-snug {{ $menunggu['nota'] > 0 ? 'text-jingga-tua' : 'text-umber-soft' }}">
                    @if ($menunggu['nota'] > 0)
                        {{ $menunggu['nota'] }} nota{{ $umurMenunggu !== null ? ' · '.$umurMenunggu : '' }}
                    @else
                        semua barang sudah sampai
                    @endif
                </p>
            </div>
        </div>

        <div class="kartu flex min-h-[5.625rem] items-center gap-2.5 px-3.5 sm:gap-4 sm:pr-5 sm:pl-[1.125rem]">
            <span class="lencana-ikon size-9 bg-cream-deep text-umber sm:size-[3.25rem]">
                <svg viewBox="0 0 24 24" class="size-5 sm:size-6" fill="none" aria-hidden="true">
                    <path d="M6 3.5h9l3 3v14H6v-17Zm3 6h6M9 13h6M9 16.5h3" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </span>
            <div class="min-w-0">
                <p class="text-[0.75rem] font-medium text-umber sm:text-[0.875rem]">Jumlah nota</p>
                <p class="tabular text-[0.9375rem] leading-tight font-bold text-ink sm:text-[1.25rem]">
                    {{ $ringkasan['nota'] }} nota
                </p>
                <p class="mt-0.5 line-clamp-2 text-[0.6875rem] leading-snug text-umber-soft">
                    tercatat bulan ini
                </p>
            </div>
        </div>

        {{-- TIDAK lagi col-span-2 di ponsel: dengan empat kartu petaknya sudah penuh 2×2, dan
             kartu yang tetap dilebarkan akan menyisakan satu petak kosong di sebelahnya —
             lubang yang persis kebalikan dari alasan col-span-2 dipasang dulu. --}}
        <div class="kartu flex min-h-[5.625rem] items-center gap-2.5 px-3.5 sm:gap-4 sm:pr-5 sm:pl-[1.125rem]">
            <span class="lencana-ikon size-9 bg-cream-deep sm:size-[3.25rem] {{ $ringkasan['dibatalkan'] > 0 ? 'text-merah-deep' : 'text-umber-soft' }}">
                <svg viewBox="0 0 24 24" class="size-5 sm:size-6" fill="none" aria-hidden="true">
                    <circle cx="12" cy="12" r="8" stroke="currentColor" stroke-width="1.5" />
                    <path d="m9 9 6 6m0-6-6 6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                </svg>
            </span>
            <div class="min-w-0">
                <p class="text-[0.75rem] font-medium text-umber sm:text-[0.875rem]">Nota dibatalkan</p>
                <p class="tabular text-[0.9375rem] leading-tight font-bold text-ink sm:text-[1.25rem]">
                    {{ $ringkasan['dibatalkan'] }} nota
                </p>
                <p class="mt-0.5 line-clamp-2 text-[0.6875rem] leading-snug {{ $ringkasan['dibatalkan'] > 0 ? 'text-merah-deep' : 'text-umber-soft' }}">
                    stoknya sudah dikembalikan
                </p>
            </div>
        </div>
    </div>

    {{-- Keterangan seksinya ikut dibetulkan. Bunyi lamanya ("Nota yang tersimpan berarti
         barangnya sudah datang") sudah tidak benar sejak nota bisa dicatat sebagai belum
         datang, dan kalimat yang tidak benar di kepala daftar lebih merugikan daripada tidak
         ada kalimat: pemilik yang membacanya menyimpulkan seluruh nota di bawahnya sudah
         menambah stok. --}}
    <x-kartu-alat
        judul="Nota belanja"
        jumlah="{{ $daftar->total() }}"
        keterangan="Stok outletnya bertambah begitu notanya ditandai barangnya sudah datang."
    >
        <x-slot:aksi>
            {{-- Tautan, bukan tombol: mencatat nota adalah halaman tersendiri dan bisa
                 dibuka di tab lain sambil daftar ini tetap terbuka. Tingginya 44px dan
                 seukuran isinya, SEJAJAR dengan judul seksinya sejak ponsel — bentuk yang
                 sama dengan "Hitung stok sekarang" di layar Stok, supaya pasangan
                 pergi-pulang antar layar owner terbaca sebagai satu bahasa.

                 Dua bentuk teks: "Catat nota belanja" (127px) memepet judul di 390px,
                 jadi di bawah 640px dipendekkan. Maknanya tidak berubah — kata "belanja"
                 sudah ada di judul seksinya. --}}
            <a href="{{ route('owner.pembelian.baru', $outletDipakai !== null ? ['outlet' => $outletDipakai] : []) }}"
               wire:navigate
               class="tombol-utama h-11 w-auto shrink-0 px-4 text-[0.875rem]">
                <span class="tombol-ikon">
                    <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                        <path d="M10 4.5v11M4.5 10h11" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                    </svg>
                </span>
                <span class="sm:hidden">Catat nota</span>
                <span class="hidden sm:inline">Catat nota belanja</span>
            </a>
        </x-slot:aksi>

        <x-slot:saringan>
            {{-- Pencarian selebar penuh, lalu dropdown outlet, lalu deret pil status — pola
                 layar Stok. Dropdown outlet TIDAK dibuat selebar kartu di sini karena di layar
                 ini ia hanya menyaring tampilan: salah membacanya tidak mengubah satu data
                 pun. (Di layar catat nota ia selebar penuh — di sana ia menentukan ke cabang
                 mana barangnya masuk.) --}}
            <div class="grid grid-cols-2 gap-3 lg:grid-cols-[1fr_13rem]">
                <div class="col-span-2 min-w-0 lg:col-span-1">
                    <label for="cari" class="sr-only">Cari nota belanja</label>
                    <div class="relative">
                        <svg viewBox="0 0 20 20" class="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-umber-soft"
                             fill="none" aria-hidden="true">
                            <circle cx="9" cy="9" r="5.5" stroke="currentColor" stroke-width="1.6" />
                            <path d="m13.5 13.5 3 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                        </svg>
                        <input id="cari" type="search" wire:model.live.debounce.300ms="cari"
                               placeholder="Cari nomor nota atau nama tempat belanja…"
                               class="h-11 w-full rounded-xl border border-line bg-white pr-4 pl-11 text-[0.875rem] text-ink focus:border-terracotta focus:outline-none">
                    </div>
                </div>

                {{-- Outlet berbagi baris dengan pencarian di ≥lg, dan selebar petak di ponsel:
                     dengan status berpindah ke deret pil, dropdown ini tinggal SATU-SATUNYA
                     dropdown — separuh baris akan menyisakan petak menganga di sebelahnya. --}}
                @if ($outletTersedia !== [])
                    <div class="col-span-2 min-w-0 lg:col-span-1">
                        <label for="outlet" class="sr-only">Outlet</label>
                        <select id="outlet" wire:model.live="outletId"
                                class="h-11 w-full rounded-xl border border-line bg-white px-3 text-[0.875rem] font-medium text-ink focus:border-terracotta focus:outline-none">
                            <option value="">Semua outlet</option>
                            @foreach ($outletTersedia as $o)
                                <option value="{{ $o['id'] }}">{{ $o['nama'] }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                {{-- ── Pil status ───────────────────────────────────────────
                     Dulu sebuah <select> bertiga pilihan. Diganti PIL karena keadaan
                     "belum datang" harus terlihat tanpa dibuka: pilihan yang tersembunyi di
                     dalam dropdown hanya ditemukan orang yang sudah tahu ia ada, dan yang
                     paling perlu ditemukan di layar ini justru nota yang barangnya belum
                     sampai. Bentuknya SAMA dengan pil saringan layar Stok.

                     col-span-2 dan MEMBUNGKUS ke baris berikutnya — bukan digulir mendatar:
                     pil yang harus dicari dengan menggeser sama saja tidak ada. Empatnya
                     dibagi DUA-DUA di ponsel lewat petak, bukan flex-wrap+grow seperti tujuh
                     pil di layar Stok: dengan empat pil, flex-wrap menyisakan pil keempat
                     SENDIRIAN di baris kedua lalu melebarkannya sampai batas 20rem — dan pil
                     selebar kartu yang sedang aktif terbaca sebagai tombol, bukan sebagai satu
                     pilihan di antara empat. Petak dua kolom membuat keempatnya seukuran.

                     SENGAJA tanpa angka di sebelah namanya, dan itu bukan kelalaian: angka
                     yang tersedia di layar ini ("nota bulan ini", "menunggu datang" sepanjang
                     waktu) punya cakupan yang berbeda-beda, sementara pilnya menyaring
                     SELURUH daftar tanpa batas bulan. Chip yang angkanya tidak cocok dengan
                     isi tabel membuat orang berhenti mempercayai keduanya — cacat yang sudah
                     pernah terjadi di layar Stok. --}}
                <div class="col-span-2 -m-px min-w-0 overflow-x-auto p-px lg:col-span-full [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                    <div role="group" aria-label="Saringan keadaan nota"
                         class="grid min-w-full grid-cols-2 gap-1 rounded-xl bg-white p-1 ring-1 ring-line sm:grid-cols-4">
                        @foreach ([
                            ['semua', 'Semua'],
                            ['diterima', 'Sudah datang'],
                            ['belum', 'Belum datang'],
                            ['dibatalkan', 'Dibatalkan'],
                        ] as [$nilai, $teks])
                            <button type="button" wire:click="$set('status', '{{ $nilai }}')"
                                    aria-pressed="{{ $status === $nilai ? 'true' : 'false' }}"
                                    @class([
                                        'flex h-9 min-w-0 cursor-pointer items-center justify-center rounded-lg px-2 text-[0.8125rem] whitespace-nowrap transition',
                                        'bg-terracotta font-semibold text-white' => $status === $nilai,
                                        'font-medium text-umber hover:bg-cream hover:text-ink' => $status !== $nilai,
                                    ])>
                                {{ $teks }}
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>
        </x-slot:saringan>
    </x-kartu-alat>

    {{-- ── Rincian nota ────────────────────────────────────────────────────
         Panel DI DALAM alur halaman, bukan dialog mengapung — pola panel "Riwayat barang"
         di layar Stok. Nota belanja bulanan kelontong bisa 40 baris; dialog yang harus
         digulir menyembunyikan baris terakhirnya, padahal justru di situ orang mencari
         barang yang dicarinya.

         Halamannya punya penunjuk SENDIRI ('baris', diatur komponen) dan reset ke halaman 1
         tiap panel dibuka: kalau ikut 'page', membuka rincian dari halaman 3 daftar akan
         melompatkan daftarnya. --}}
    @if ($notaRincian !== null)
        @php
            /*
             * Nilai barangnya diturunkan dari angka yang TERSIMPAN (total + diskon − ongkir),
             * bukan dijumlah ulang dari baris yang sedang tampak.
             *
             * Barisnya berhalaman 10, jadi menjumlah $barisRincian hanya akan menghitung
             * halaman ini — dan angka "belanja" yang lebih kecil daripada totalnya membuat
             * pemilik menyimpulkan totalnya salah. Rumusnya kebalikan persis dari rumus di
             * CatatPembelianAction (total = subtotal − diskon + ongkir), jadi tidak ada
             * definisi kedua yang bisa bergeser sendiri.
             */
            $diskonNota = (float) $notaRincian->diskon;
            $ongkirNota = (float) $notaRincian->ongkos_kirim;
            $belanjaNota = (float) $notaRincian->total + $diskonNota - $ongkirNota;
        @endphp

        <div class="kartu mb-4 overflow-hidden sm:mb-5" wire:key="rincian-{{ $notaRincian->getKey() }}">
            {{-- Kepala panel: identitas nota di kiri, tiga keterangan di kanan, tombol tutup
                 di ujung. Di bawah 1024px ketiga kotak pindah ke barisnya sendiri
                 (`w-full` + order): berbagi baris dengan judul di 390px menyisakan ±40px per
                 kotak dan seluruh isinya luber. --}}
            <div class="flex flex-wrap items-start gap-4 border-b border-line px-5 py-4 sm:px-6">
                <div class="order-1 min-w-0 flex-1">
                    <p class="eyebrow text-umber-soft">Isi nota</p>
                    <div class="mt-1 flex flex-wrap items-center gap-2">
                        <h2 class="tabular text-[1.0625rem] font-bold text-ink">{{ $notaRincian->nomor_po }}</h2>
                        <x-lencana :warna="$warnaStatus($notaRincian->status)" :denyut="$notaRincian->status !== \App\Enums\DocumentStatus::Dibatalkan">
                            {{ $labelStatus($notaRincian->status) }}
                        </x-lencana>
                    </div>
                    <p class="mt-0.5 text-[0.75rem] text-umber-soft">
                        {{ $notaRincian->outlet?->outlet_name ?? 'outlet tidak diketahui' }}
                        · {{ $barisRincian instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator ? $barisRincian->total() : $notaRincian->items_count ?? 0 }} baris barang
                        @if (filled($notaRincian->catatan))
                            · {{ $notaRincian->catatan }}
                        @endif
                    </p>
                </div>

                <div class="order-3 grid w-full min-w-0 grid-cols-3 gap-2 lg:order-2 lg:w-auto lg:max-w-lg lg:flex-1">
                    <div class="min-w-0 rounded-xl border border-line px-3 py-2">
                        <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Beli dari</p>
                        <p class="text-[0.8125rem] font-bold break-words text-ink">
                            {{ $notaRincian->supplier?->nama ?: '—' }}
                        </p>
                    </div>
                    <div class="min-w-0 rounded-xl border border-line px-3 py-2">
                        <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Tanggal</p>
                        <p class="tabular text-[0.8125rem] font-bold text-ink">
                            {{ $notaRincian->tanggal?->locale('id')->translatedFormat('j M Y') ?? '—' }}
                        </p>
                    </div>
                    <div class="min-w-0 rounded-xl border border-line px-3 py-2">
                        <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Total belanja</p>
                        <p class="tabular text-[0.8125rem] font-bold break-words text-ink">
                            {{ $rupiah($notaRincian->total) }}
                        </p>
                    </div>
                </div>

                <button type="button" wire:click="tutupRincian" aria-label="Tutup rincian nota {{ $notaRincian->nomor_po }}"
                        class="order-2 grid size-10 shrink-0 cursor-pointer place-items-center rounded-lg border border-line text-umber transition-colors hover:bg-cream hover:text-ink lg:order-3">
                    <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                        <path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                    </svg>
                </button>
            </div>

            @if ($barisRincian->isEmpty())
                <div class="px-5 py-5 sm:px-6">
                    <x-kosong judul="Nota ini tidak berisi barang"
                              keterangan="Barisnya mungkin ikut terhapus. Nota tetap ditampilkan supaya mutasi stok yang menunjuknya masih bisa dibuka."
                              ikon="lembar" />
                </div>
            @else
                {{-- Kartu di <lg, tabel di ≥lg — pola yang sama dengan seluruh daftar owner. --}}
                <ul class="divide-y divide-line-soft lg:hidden">
                    @foreach ($barisRincian as $baris)
                        <li class="px-5 py-3.5" wire:key="baris-{{ $baris->getKey() }}">
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="text-[0.875rem] font-semibold text-ink">{{ $baris->namaItem() }}</p>
                                    <p class="tabular text-[0.75rem] text-umber-soft">
                                        {{ $angka($baris->qty_beli ?? $baris->qty) }}
                                        {{ $baris->satuan_beli ?: 'satuan' }}
                                        × {{ $rupiah($baris->harga_satuan) }}
                                    </p>
                                </div>
                                <p class="tabular shrink-0 text-[0.9375rem] font-bold text-ink">{{ $rupiah($baris->subtotal) }}</p>
                            </div>
                            {{-- Jumlah yang MASUK STOK ditulis terpisah dari jumlah yang dibeli:
                                 2 dus yang menjadi 24 pcs di kartu stok adalah keterangan yang
                                 paling sering ditanyakan saat angka stok terasa aneh. --}}
                            <p class="tabular mt-1 text-[0.75rem] text-umber">
                                masuk stok {{ $angka($baris->qty) }}
                                @if ($baris->isi_per_satuan_beli !== null)
                                    · 1 {{ $baris->satuan_beli ?: 'satuan' }} = {{ $angka($baris->isi_per_satuan_beli) }}
                                @endif
                            </p>
                        </li>
                    @endforeach
                </ul>

                <div class="hidden lg:block">
                    {{-- Lebar PERSEN + table-fixed, jumlahnya 100%: kolom tanpa lebar akan
                         menyerap seluruh sisa lebar panel dan lubangnya cuma berpindah tempat.
                         Judul DAN isi rata tengah (keputusan pemilik proyek). --}}
                    <table class="w-full table-fixed text-center">
                        <thead>
                            <tr class="border-b border-line">
                                <th class="w-[34%] px-5 py-3 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Barang</th>
                                <th class="w-[18%] px-5 py-3 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Jumlah</th>
                                <th class="w-[16%] px-5 py-3 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Masuk stok</th>
                                <th class="w-[16%] px-5 py-3 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Harga</th>
                                <th class="w-[16%] px-5 py-3 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Jumlah uang</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            @foreach ($barisRincian as $baris)
                                <tr class="transition-colors hover:bg-cream/60" wire:key="baris-tabel-{{ $baris->getKey() }}">
                                    <td class="px-5 py-3 text-[0.8125rem]">
                                        <span class="block font-semibold text-ink">{{ $baris->namaItem() }}</span>
                                        {{-- Jenis barangnya, bukan satuannya: satuan sudah dicetak
                                             di bawah angka pada kolom Jumlah, dan mengulangnya di
                                             sini membuat dua kolom bersebelahan berbunyi "kg" dua
                                             kali tanpa menambah satu keterangan pun. --}}
                                        <span class="tabular block text-[0.75rem] text-umber-soft">
                                            {{ $baris->product_id !== null ? 'Produk' : 'Bahan baku' }}
                                            @if ($baris->isi_per_satuan_beli !== null)
                                                · 1 {{ $baris->satuan_beli ?: 'satuan' }} = {{ $angka($baris->isi_per_satuan_beli) }}
                                            @endif
                                        </span>
                                    </td>
                                    <td class="tabular px-5 py-3 text-center text-[0.875rem]">
                                        <span class="font-semibold text-ink">{{ $angka($baris->qty_beli ?? $baris->qty) }}</span>
                                        <span class="block text-[0.6875rem] text-umber-soft">{{ $baris->satuan_beli ?: '—' }}</span>
                                    </td>
                                    <td class="tabular px-5 py-3 text-center text-[0.875rem] text-umber">{{ $angka($baris->qty) }}</td>
                                    <td class="tabular px-5 py-3 text-center text-[0.875rem] text-umber">{{ $rupiah($baris->harga_satuan) }}</td>
                                    <td class="tabular px-5 py-3 text-center text-[0.875rem] font-bold text-ink">{{ $rupiah($baris->subtotal) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif

            {{-- Navigasi halaman rincian SELALU dirender selama ada halaman berikutnya:
                 daftar yang memotong diam-diam membuat orang menyimpulkan notanya cuma
                 berisi 10 baris, lalu menganggap totalnya salah. --}}
            @if ($barisRincian instanceof \Illuminate\Contracts\Pagination\LengthAwarePaginator && $barisRincian->hasPages())
                <div class="border-t border-line-soft px-5 py-3 sm:px-6">
                    {{ $barisRincian->links() }}
                </div>
            @endif

            {{-- Kaki panel: uang nota + jalan keluar kalau notanya salah.
                 Empat kotak yang sama bentuknya dengan kotak keterangan di kepala panel —
                 satu kebiasaan untuk hal yang sama, bukan dua. --}}
            <div class="border-t border-line px-5 py-4 sm:px-6">
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <div class="min-w-0 rounded-xl border border-line px-3 py-2">
                        <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Harga barang</p>
                        <p class="tabular text-[0.875rem] font-bold break-words text-ink">{{ $rupiah($belanjaNota) }}</p>
                    </div>
                    <div class="min-w-0 rounded-xl border border-line px-3 py-2">
                        <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Potongan</p>
                        <p class="tabular text-[0.875rem] font-bold break-words {{ $diskonNota > 0 ? 'text-hijau-tua' : 'text-umber-soft' }}">
                            {{ $diskonNota > 0 ? '− '.$rupiah($diskonNota) : '—' }}
                        </p>
                    </div>
                    <div class="min-w-0 rounded-xl border border-line px-3 py-2">
                        <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Ongkos kirim</p>
                        <p class="tabular text-[0.875rem] font-bold break-words {{ $ongkirNota > 0 ? 'text-ink' : 'text-umber-soft' }}">
                            {{ $ongkirNota > 0 ? '+ '.$rupiah($ongkirNota) : '—' }}
                        </p>
                    </div>
                    <div class="min-w-0 rounded-xl border border-line bg-cream/60 px-3 py-2">
                        <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Total belanja</p>
                        <p class="tabular text-[0.875rem] font-bold break-words text-terracotta">{{ $rupiah($notaRincian->total) }}</p>
                    </div>
                </div>

                {{-- Lampiran bukti: foto struk, kwitansi, PDF invoice — BISA LEBIH DARI SATU.

                     Bentuk sebelumnya satu foto per nota, dan itu tidak cukup: nota grosir
                     sering berlembar-lembar, struk panjang harus difoto dua-tiga potong, dan
                     tagihan kadang datang sebagai PDF lewat WhatsApp. Batasnya 10 — angka
                     dari pemilik proyek, bukan tebakan.

                     TIGA keadaan, dan ketiganya berbunyi berbeda karena artinya berbeda:

                     1. Sudah ada lampirannya → petak-petak yang bisa dibuka besar, masing-
                        masing boleh dibuang (dengan konfirmasi).
                     2. Belum ada             → keadaan NETRAL, bukan peringatan merah. 90% nota
                        warteg memang tanpa struk; kalau barisnya merah, 90% daftar jadi merah
                        dan orang belajar mengabaikan merah — termasuk merah yang penting.
                        Yang ditulis bukan "belum ada bukti" saja, tapi KENAPA menyimpannya
                        berguna: tanpa itu tidak ada alasan untuk memotret apa pun.
                     3. Notanya dibatalkan    → lampirannya TERKUNCI. Tombol yang tidak akan
                        bekerja tidak dirender sama sekali (aksinya di server menolak juga),
                        dan alasannya ditulis apa adanya: kalau barangnya sudah dikembalikan
                        ke grosir, struk itu justru buktinya.

                     Alamat berkasnya SELALU dari $notaRincian->urlLampiran($l) — rute
                     berpenjaga. Menyusun /storage/... dengan tangan akan 404 dengan SUNYI:
                     berkasnya sudah tidak ada di folder publik, dan kotaknya cuma kosong.
                     Ada penjaga sumber kode di PembelianBuktiTest. --}}
                @php
                    $lampiran = $notaRincian->lampiran;
                    $buktiDikunci = $notaRincian->buktiTerkunci();
                    $sisaKuota = \App\Actions\Lampiran\SimpanLampiranAction::MAKS - $lampiran->count();
                @endphp

                {{-- SATU x-data untuk seluruh galeri: satu popup melayani semua petak, dan
                     yang menentukan isinya adalah petak yang barusan diketuk. Popup per foto
                     berarti sepuluh salinan markup yang sama — dan sepuluh tempat untuk lupa
                     memperbaiki. --}}
                <div class="mt-4 border-t border-line-soft pt-4"
                     x-data="lihatBukti" x-on:keydown.escape.window="tutup()">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
                        <p class="text-[0.8125rem] font-semibold text-ink">Foto kwitansi &amp; struk</p>
                        @if ($lampiran->isNotEmpty())
                            <p class="tabular text-[0.75rem] text-umber-soft">
                                {{ $lampiran->count() }} dari {{ \App\Actions\Lampiran\SimpanLampiranAction::MAKS }}
                            </p>
                        @endif
                    </div>

                    @if ($lampiran->isEmpty())
                        <p class="mt-1 text-[0.75rem] leading-relaxed text-umber">
                            Belum ada. Simpan strukmu di sini kalau ada: waktu grosirnya menagih
                            ulang, waktu harga di struk beda dengan yang dicatat, atau waktu
                            barangnya harus dikembalikan — foto ini yang jadi pegangan, dan akhir
                            bulan notanya tidak perlu dicari lagi di laci.
                        </p>
                    @endif

                    {{-- ── Petak lampiran ─────────────────────────────────────── --}}
                    @if ($lampiran->isNotEmpty())
                        {{-- Petak KECIL berukuran TETAP (88px), membungkus ke bawah — bukan kisi
                             yang melebar mengikuti layar.

                             Petak besar membuat lampiran mendominasi panel rincian, padahal
                             yang dicari orang di panel itu biasanya angka notanya; fotonya
                             penguat. Ukuran tetap juga membuat sepuluh lampiran terbaca
                             sebagai satu deret rapi di semua lebar, bukan dua kolom raksasa
                             di ponsel lalu enam kolom kurus di laptop.

                             Yang dikecilkan hanya PRATINJAUNYA. Berkasnya tetap utuh, dan
                             ketukan membukanya sebesar layar. --}}
                        <div class="mt-3 flex flex-wrap gap-2.5">
                            @foreach ($lampiran as $l)
                                {{-- Bentuk BLOK, bukan @php(...) sebaris. Blade mengekstrak blok php dengan
                                     regex `@php(.*?)@endphp` SEBELUM apa pun diproses, dan bentuk
                                     sebarisnya ikut tercocok: satu `@php(` di sini menelan 214 baris
                                     sampai `@endphp` berikutnya jadi PHP mentah, lalu meledak sebagai
                                     "unexpected endif" di ujung berkas — jauh dari sumbernya. --}}
                                @php $alamat = $notaRincian->urlLampiran($l); @endphp
                                <div class="group relative size-22 shrink-0 overflow-hidden rounded-xl border border-line bg-cream/40"
                                     wire:key="lampiran-{{ $l->id }}">
                                    @if (! $l->ada())
                                        {{-- Barisnya ada, berkasnya tidak. Dikatakan apa adanya:
                                             ikon gambar rusak tidak menjelaskan apa pun, dan
                                             menyembunyikannya membuat hitungan "3 dari 10" bohong. --}}
                                        <div class="grid size-full place-items-center px-1.5 text-center">
                                            <p class="text-[0.625rem] leading-tight text-umber-soft">
                                                Berkasnya hilang
                                            </p>
                                        </div>
                                    @elseif ($l->gambar())
                                        {{-- Kotak berukuran TETAP (aspect-square + object-cover):
                                             gambar yang gagal dimuat tidak boleh mengempiskan
                                             petaknya dan menggeser seluruh galeri. --}}
                                        <button type="button" x-ref="pemicu"
                                                x-on:click="buka(@js($alamat), @js($l->namaTampil()))"
                                                aria-label="Buka besar {{ $l->namaTampil() }}"
                                                class="block size-full cursor-pointer">
                                            <img src="{{ $alamat }}" alt="{{ $l->namaTampil() }}"
                                                 class="size-full object-cover transition-transform group-hover:scale-105">
                                        </button>
                                    @else
                                        {{-- PDF: kartu dokumen, BUKAN petak kosong. Halaman
                                             pertamanya sengaja tidak dirender jadi gambar kecil —
                                             itu menuntut pdf.js ±1 MB di bundel, untuk sesuatu
                                             yang dibuka sekali saat ada selisih. --}}
                                        <a href="{{ $alamat }}"
                                           class="flex size-full flex-col items-center justify-center gap-1 px-1.5 text-center transition-colors hover:bg-cream">
                                            <span class="grid size-7 place-items-center rounded-lg bg-merah/10 text-merah-tua" aria-hidden="true">
                                                <svg viewBox="0 0 24 24" class="size-4" fill="none">
                                                    <path d="M7 3h7l5 5v13H7V3Z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" />
                                                    <path d="M14 3v5h5" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" />
                                                </svg>
                                            </span>
                                            <span class="line-clamp-2 text-[0.625rem] leading-tight font-semibold break-all text-ink">
                                                {{ $l->namaTampil(18) }}
                                            </span>
                                            <span class="text-[0.625rem] text-umber-soft">PDF</span>
                                        </a>
                                    @endif

                                    @unless ($buktiDikunci)
                                        {{-- Dialognya BUKAN pengamannya: hapusLampiran() di server
                                             tetap mencari lewat relasi notanya, jadi id nota lain
                                             berakhir "tidak ditemukan". --}}
                                        <button type="button"
                                                x-on:click="window.konfirmasiNampan({
                                                    judul: {{ \Illuminate\Support\Js::from('Hapus '.$l->namaTampil().'?') }},
                                                    pesan: 'Berkasnya dihapus dari penyimpanan dan tidak bisa dikembalikan. Notanya sendiri tetap tersimpan.',
                                                    tombolYa: 'Ya, hapus',
                                                    tombolBatal: 'Tidak jadi',
                                                }).then((ya) => ya && $wire.hapusLampiran({{ \Illuminate\Support\Js::from($l->id) }}))"
                                                aria-label="Hapus {{ $l->namaTampil() }}"
                                                class="tombol-bahaya absolute top-1 right-1 grid size-7 cursor-pointer place-items-center rounded-lg p-0">
                                            <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                                <path d="M4 6h12M8 6V4.5h4V6m-6 0 .8 10h6.4L14 6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                                            </svg>
                                        </button>
                                    @endunless
                                </div>
                            @endforeach
                        </div>
                    @endif

                    {{-- ── Menambah ───────────────────────────────────────────── --}}
                    @if ($buktiDikunci)
                        <p class="mt-3 text-[0.75rem] leading-relaxed text-umber">
                            Nota ini sudah dibatalkan, jadi lampirannya dikunci — justru itu
                            bukti barangnya dikembalikan ke grosir. Yang sudah ada tetap bisa dibuka.
                        </p>
                    @elseif ($sisaKuota <= 0)
                        {{-- Tombol yang pasti menolak TIDAK dirender: yang ditekan berkali-kali
                             lalu diam membuat orang menyimpulkan aplikasinya rusak. --}}
                        <p class="mt-3 text-[0.75rem] text-umber">
                            Sudah {{ \App\Actions\Lampiran\SimpanLampiranAction::MAKS }} lampiran — batasnya di sini.
                            Buang salah satu dulu kalau mau menambah.
                        </p>
                    @else
                        <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center">
                            <label for="lampiran-baru"
                                   class="tombol-kedua flex h-11 cursor-pointer items-center justify-center gap-2 px-4 text-[0.8125rem]">
                                <span class="tombol-ikon">
                                    <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                        <path d="M10 13V4m0 0L6.5 7.5M10 4l3.5 3.5M3.5 13v2A1.5 1.5 0 0 0 5 16.5h10a1.5 1.5 0 0 0 1.5-1.5v-2"
                                              stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </span>
                                Pilih foto atau PDF
                            </label>

                            {{-- `multiple`, dan `accept="image/*,application/pdf"`.

                                 image/* dan BUKAN daftar jenis satu per satu: daftar spesifik
                                 membuat banyak peramban Android tidak menawarkan kamera sama
                                 sekali, karena aplikasi kameranya mendaftar sebagai penghasil
                                 image/* lalu tersaring keluar. `capture` TETAP tidak dipasang —
                                 ia memaksa kamera tapi menghapus pilihan galeri, sehingga struk
                                 yang sudah difoto kemarin tidak bisa dipilih lagi. --}}
                            <input id="lampiran-baru" type="file" multiple wire:model="lampiranBaru"
                                   accept="image/*,application/pdf" class="sr-only">

                            @if (! empty($lampiranBaru))
                                <button type="button" wire:click="pasangLampiran" wire:loading.attr="disabled"
                                        class="tombol-utama h-11 cursor-pointer px-5 text-[0.8125rem]">
                                    <span wire:loading.remove wire:target="pasangLampiran">
                                        Pasang {{ count($lampiranBaru) }} berkas
                                    </span>
                                    <span wire:loading wire:target="pasangLampiran">Menyimpan…</span>
                                </button>
                            @endif
                        </div>

                        <p class="mt-2 text-[0.75rem] text-umber-soft">
                            JPG, PNG, WEBP paling besar {{ $batasBukti }}; PDF paling besar
                            {{ (int) (config('nampan.lampiran_pdf_maks_kb') / 1024) }} MB.
                            Sisa {{ $sisaKuota }} lagi.
                        </p>

                        <p wire:loading wire:target="lampiranBaru" class="mt-1.5 text-[0.75rem] font-semibold text-terracotta">
                            Mengunggah…
                        </p>

                        @error('lampiranBaru.*')
                            <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                        @enderror
                    @endif

                    {{-- ── Popup satu foto ────────────────────────────────────── --}}
                    <template x-if="terbuka">
                        {{-- Latar gelap penuh layar, tapi KOTAKNYA seukuran isi — bukan
                             popup selebar layar. Dialog yang memenuhi layar membuat orang
                             kehilangan tempatnya: ia tidak lagi terlihat sedang menimpa panel
                             nota, dan tombol tutupnya jadi satu-satunya jalan pulang yang
                             kelihatan. --}}
                        <div class="fixed inset-0 z-[60] flex items-center justify-center bg-ink/85 p-4 sm:p-8"
                             x-on:click.self="tutup()" role="dialog" aria-modal="true"
                             :aria-label="'Foto ' + judul">
                            <div class="flex max-h-full w-auto max-w-3xl flex-col overflow-hidden rounded-2xl bg-white shadow-2xl"
                                 x-on:click.stop>
                                <div class="flex shrink-0 items-center justify-between gap-3 border-b border-line px-4 py-2.5">
                                    <p class="truncate text-[0.8125rem] font-semibold text-ink" x-text="judul"></p>
                                    <div class="flex shrink-0 items-center gap-2">
                                        {{-- Jalan keluar yang TIDAK dihapus: zoom bawaan
                                             peramban selalu lebih baik daripada zoom yang kita
                                             tulis sendiri, dan kalau ada yang tetap tidak
                                             terbaca orang harus punya cara lain. --}}
                                        <a :href="alamat" target="_blank" rel="noopener"
                                           class="tombol-kedua flex h-9 items-center px-3 text-[0.8125rem]">
                                            <span class="hidden sm:inline">Buka di tab baru</span>
                                            <span class="sm:hidden">Tab baru</span>
                                        </a>
                                        <button type="button" x-on:click="tutup()" aria-label="Tutup"
                                                class="tombol-ikon size-9 shrink-0 cursor-pointer">
                                            <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                                <path d="m5 5 10 10M15 5 5 15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>

                                {{-- TANPA gulir di keadaan bawaannya: seluruh foto dipaskan ke
                                     dalam kotaknya (`object-contain`), jadi apa yang terlihat
                                     memang seluruh struknya.

                                     Harganya nyata dan disebut apa adanya: struk kelontong bisa
                                     3x lebih tinggi daripada lebar, dan dipaskan utuh hurufnya
                                     mengecil. Karena itu ketuk-untuk-perbesar TETAP ADA — dan
                                     gulir baru muncul saat orang MEMINTA perbesaran, bukan
                                     sebagai keadaan awal yang tidak ia minta. --}}
                                <div x-ref="jalur"
                                     :class="asli ? 'overflow-auto' : 'overflow-hidden'"
                                     class="min-h-0 flex-1 bg-cream/40">
                                    <img x-ref="gambar" :src="alamat" :alt="judul"
                                         x-on:click="ketukGambar($event)"
                                         x-on:pointerdown="mulaiSeret($event)"
                                         x-on:pointermove="lanjutSeret($event)"
                                         x-on:pointerup="akhiriSeret()"
                                         x-on:pointercancel="akhiriSeret()"
                                         :class="asli
                                            ? 'max-w-none cursor-zoom-out'
                                            : 'max-h-[70vh] max-w-full cursor-zoom-in object-contain'"
                                         class="mx-auto block select-none">
                                </div>

                                <p class="shrink-0 border-t border-line px-4 py-2 text-center text-[0.75rem] text-umber-soft">
                                    Ketuk fotonya untuk memperbesar. Di HP bisa dicubit.
                                </p>
                            </div>
                        </div>
                    </template>
                </div>

                @php
                    $bolehTandaiDatang = $bisaDitandaiDatang($notaRincian->status);
                    $bolehBatalkan = $notaRincian->status !== \App\Enums\DocumentStatus::Dibatalkan;

                    /*
                     * Kalimat dialog pembatalan, disusun di sini supaya isinya bisa BERBEDA
                     * menurut keadaan notanya.
                     *
                     * Bentuk lamanya satu kalimat tetap: "Stok yang masuk dari nota ini
                     * dikembalikan seperti sebelum dicatat." Untuk nota yang barangnya BELUM
                     * DATANG itu tidak benar — BatalkanPembelianAction hanya menyentuh stok
                     * dan harga kalau notanya pernah menggerakkannya (status->movesStock()),
                     * dan aksinya sendiri menuliskan peringatan itu: mengaku "stok
                     * dikembalikan" untuk nota seperti ini membuat pemilik mencari barang yang
                     * tidak pernah ada di catatannya. Toast sesudahnya sudah membedakan
                     * keduanya; kalimat SEBELUM ditekan harus ikut membedakan, karena itulah
                     * yang dipakai untuk memutuskan.
                     *
                     * Yang WAJIB ada, sesuai CLAUDE.md: apa yang hilang, apa yang TIDAK
                     * hilang, dan apakah bisa dibalik. Peringatan yang lebih menakutkan
                     * daripada kenyataannya membuat peringatan berikutnya tidak dipercaya —
                     * jadi "notanya tetap tersimpan" disebut sejelas "tidak bisa dibalik".
                     *
                     * Tanpa petik satu pun di dalam kalimatnya bukan kebetulan, walaupun
                     * Js::from() sudah menanganinya: yang lolos dari penyaringan atribut
                     * biasanya justru apostrof.
                     */
                    $pesanBatal = ($notaRincian->status->movesStock()
                        ? 'Stok '.($notaRincian->outlet?->outlet_name ?? 'outlet nota ini')
                            .' yang masuk dari nota ini dikeluarkan kembali, dan harga belinya kembali ke harga nota sebelumnya. '
                        : 'Barangnya belum datang, jadi tidak ada stok maupun harga yang berubah. ')
                        .'Notanya sendiri tetap tersimpan berlencana Dibatalkan — riwayat barangnya tidak hilang, '
                        .'dan foto struknya masih bisa dilihat walau tidak bisa diganti lagi. '
                        .'Pembatalan tidak bisa dibalik: kalau barangnya ternyata tetap datang, catat nota baru.';
                @endphp

                {{-- ── Pemicu tindakan nota: SATU baris, terbaca sebagai sepasang ──────
                     Sebelum ini kedua tombol berdiri di dua `div` bertumpuk `mt-3` dan
                     kelasnya ditulis terpisah — yang satu `.tombol-kedua` dengan wadah ikon,
                     yang lain rangkaian utilitas yang diketik sendiri tanpa wadah ikon. Di
                     desktop hasilnya dua tombol rata kiri di dua baris dengan lebar berbeda:
                     berdampingan keduanya terbaca seperti dari dua aplikasi. Sekarang
                     silhouette-nya SAMA (tinggi 44px, ikon berwadah, sudut & tepi sejajar);
                     yang membedakan hanya WARNA, sesuai perannya.

                     `data-blok="tindakan-nota"` adalah SEAM UJI, bukan hiasan: uji tampilan
                     memotong "blok foto kwitansi" tepat sebelum baris ini, karena tombol
                     "Batalkan nota" di sini memang merah dan jendela yang kelewat lebar
                     membuat uji "belum ada foto tidak merah" gagal karena warna milik tombol
                     lain. Dulu batasnya string `tanya: null`; itu ikut hilang saat pembatalan
                     pindah ke dialog, dan penanda yang bisa hilang tanpa disadari adalah
                     penjaga yang berhenti menjaga.

                     Keadaan Alpine tinggal SATU cabang (`tanyaDatang`), dan hanya dirender
                     kalau "Tandai barang sudah datang" memang ada. Bentuk lamanya
                     `tanya: null | 'datang' | 'batal'`; cabang 'batal' pindah ke dialog
                     SweetAlert bersama, jadi menyimpannya berarti meninggalkan keadaan
                     menganggur yang terbaca seperti pengaman yang masih bekerja. --}}
                @if ($bolehTandaiDatang || $bolehBatalkan)
                    <div class="mt-4 border-t border-line-soft pt-4" data-blok="tindakan-nota"
                         @if ($bolehTandaiDatang) x-data="{ tanyaDatang: false }" @endif>
                        {{-- Jalur pemicu. Di ponsel bertumpuk lebar penuh; di ≥sm sebaris,
                             seukuran isinya, dengan tinggi yang sama. --}}
                        <div @if ($bolehTandaiDatang) x-show="! tanyaDatang" @endif
                             class="flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-center">
                            {{-- "Tandai barang sudah datang" TETAP tidak merah: bukan tindakan
                                 merusak, tidak ada yang hilang, dan aksinya idempoten. Kalau ia
                                 ikut merah, merah pada "Batalkan nota" di sebelahnya melemah dan
                                 warnanya berhenti jadi aturan (lihat CLAUDE.md). --}}
                            @if ($bolehTandaiDatang)
                                <button type="button" x-on:click="tanyaDatang = true"
                                        class="tombol-kedua h-11 w-full cursor-pointer px-4 text-[0.8125rem] sm:w-auto">
                                    <span class="tombol-ikon">
                                        <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                            <path d="M4 10.5l3.5 3.5L16 6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                    </span>
                                    Tandai barang sudah datang
                                </button>
                            @endif

                            {{-- Pemicu tindakan MERUSAK: tint `bg-merah/10` + `text-merah-tua`,
                                 merah SEJAK ISTIRAHAT. Bentuk lamanya kelabu dan baru merah saat
                                 disorot — cacat yang identik dengan tombol ikon hapus dulu: layar
                                 owner dipakai di tablet dan HP, dan di sana hover TIDAK ADA, jadi
                                 tanda bahayanya tidak pernah muncul. `merah-tua` (7,14:1 di atas
                                 tint), bukan `merah-deep` (4,15:1). Bukan `.tombol-bahaya` — itu
                                 untuk tindakan merusaknya sendiri, yaitu tombol "Ya" di dalam
                                 dialognya.

                                 Konfirmasinya lewat pembungkus SweetAlert bersama
                                 (window.konfirmasiNampan, resources/js/toast.js) — aturan pemilik
                                 proyek: "untuk delete gunakan sweet alert, terapkan di semua fitur".
                                 Aturan itu sudah dipakai "Hapus foto" di berkas yang SAMA beberapa
                                 baris di atas, dan tombol ini terlewat: ia masih memakai panel dua
                                 langkah sebaris, jadi tindakan paling merusak di layar ini justru
                                 satu-satunya yang tidak memunculkan dialog. Judulnya MENYEBUT nomor
                                 notanya, dan pesannya (disusun di blok PHP di atas) menyebut apa yang
                                 hilang, apa yang tetap ada, dan bahwa pembatalan tidak bisa dibalik.

                                 Js::from(), bukan echo Blade biasa: echo mengubah apostrof menjadi
                                 &#039;, dan peramban mengurai entitas itu KEMBALI menjadi apostrof di
                                 dalam nilai atribut — memutus string JS-nya dan mematikan tombolnya
                                 tanpa satu pun galat yang terlihat di layar. Nama outlet diketik
                                 pemilik, jadi apostrof di situ bukan hal yang mustahil.

                                 Dialognya BUKAN pengaman: batalkan() di server tetap memeriksa tenant
                                 & outlet, dan BatalkanPembelianAction tetap idempoten — muatan
                                 Livewire bisa dikirim tanpa pernah melewati dialog apa pun.

                                 Pemicunya hidup DI SINI, di kaki panel rincian, dan bukan sebagai
                                 ikon di setiap baris daftar: yang dibatalkan adalah seluruh nota
                                 beserta mutasi stoknya, dan tindakan sebesar itu tidak boleh berjarak
                                 satu ketukan jempol dari tombol "lihat". --}}
                            @if ($bolehBatalkan)
                                <button type="button" x-data
                                        x-on:click="window.konfirmasiNampan({
                                            judul: {{ \Illuminate\Support\Js::from('Batalkan nota '.$notaRincian->nomor_po.'?') }},
                                            pesan: {{ \Illuminate\Support\Js::from($pesanBatal) }},
                                            tombolYa: 'Ya, batalkan nota',
                                            tombolBatal: 'Tidak jadi',
                                        }).then((ya) => ya && $wire.batalkan({{ \Illuminate\Support\Js::from($notaRincian->getKey()) }}))"
                                        class="flex h-11 w-full cursor-pointer items-center justify-center gap-2 rounded-xl border border-merah/25 bg-merah/10 px-4 text-[0.8125rem] font-semibold text-merah-tua transition-colors hover:border-merah/45 hover:bg-merah/15 sm:w-auto">
                                    <span class="tombol-ikon bg-merah/15 text-merah-tua">
                                        <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                            <path d="M4 6h12M8 6V4.5h4V6m-6 0 .8 10h6.4L14 6" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                    </span>
                                    Batalkan nota
                                </button>
                            @endif
                        </div>

                        {{-- Konfirmasi "sudah datang" — TETAP panel dua langkah, dan itu keputusan
                             yang disengaja, bukan yang terlewat.

                             "Tandai barang sudah datang" bukan tindakan merusak: tidak ada yang
                             hilang dan aksinya idempoten. Yang perlu DIBACA sebelum ditekan adalah
                             apa yang terjadi pada barang yang datang TIDAK SESUAI nota — terima
                             sebagian tidak dibangun (isi notanya masuk penuh), jadi kalimat
                             $catatanTerimaSebagian dari komponen WAJIB terpasang di sini. Tanpa itu
                             pemilik yang menerima 8 dari 10 mengarang jalannya sendiri, dan yang
                             dikarang biasanya "biarkan saja".

                             Memaksanya ke dialog SweetAlert akan menghilangkan medan catatan
                             terima-sebagian itu, dan warnanya harus tetap netral supaya merah pada
                             "Batalkan nota" di sebelahnya tidak melemah. Jangan menyatukan keduanya
                             "supaya seragam"; yang seragam adalah aturannya, bukan bentuknya. --}}
                        @if ($bolehTandaiDatang)
                            <div x-cloak x-show="tanyaDatang">
                                <p class="text-[0.8125rem] text-ink">
                                    <span class="font-bold">Tandai nota {{ $notaRincian->nomor_po }} sudah datang?</span>
                                    Stok {{ $notaRincian->outlet?->outlet_name ?? 'outlet nota ini' }} langsung bertambah
                                    sebanyak isi nota ini, dan harga beli barangnya ikut diperbarui.
                                </p>
                                <p class="mt-1.5 text-[0.8125rem] text-umber">{{ $catatanTerimaSebagian }}</p>
                                <div class="mt-2.5 flex gap-2">
                                    <button type="button" wire:click="tandaiDatang('{{ $notaRincian->getKey() }}')"
                                            x-on:click="tanyaDatang = false" wire:loading.attr="disabled"
                                            class="tombol-utama h-11 flex-1 cursor-pointer px-4 text-[0.8125rem] sm:flex-none">
                                        Ya, barangnya sudah datang
                                    </button>
                                    <button type="button" x-on:click="tanyaDatang = false"
                                            class="h-11 flex-1 cursor-pointer rounded-xl border border-line px-4 text-[0.8125rem] font-semibold text-ink transition-colors hover:bg-cream sm:flex-none">
                                        Belum
                                    </button>
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- ── Daftar nota ─────────────────────────────────────────────────────── --}}
    @if ($daftar->isEmpty())
        <x-kosong judul="Belum ada nota belanja"
                  keterangan="Catat belanja begitu barangnya sampai di warung — stok outletnya langsung bertambah dan harga belinya ikut diperbarui."
                  ikon="lembar">
            <x-slot:aksi>
                <a href="{{ route('owner.pembelian.baru', $outletDipakai !== null ? ['outlet' => $outletDipakai] : []) }}"
                   wire:navigate class="tombol-utama h-11 px-5 text-[0.875rem]">
                    Catat nota belanja
                </a>
            </x-slot:aksi>
        </x-kosong>
    @else
        {{-- Kartu di <lg, tabel di ≥lg. Tabel yang dipaksa masuk ke ponsel menuntut gulir
             mendatar, dan kolom uang justru yang pertama hilang dari pandangan. --}}
        <div class="space-y-3 lg:hidden">
            @foreach ($daftar as $nota)
                <div class="kartu p-4" wire:key="kartu-nota-{{ $nota->getKey() }}">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="tabular text-[0.9375rem] font-bold text-ink">{{ $nota->nomor_po }}</p>
                            <p class="mt-0.5 text-[0.75rem] text-umber">
                                {{ $nota->tanggal?->locale('id')->translatedFormat('j M Y') ?? 'tanpa tanggal' }}
                                · {{ $nota->outlet?->outlet_name ?? 'outlet tidak diketahui' }}
                            </p>
                        </div>
                        <x-lencana :warna="$warnaStatus($nota->status)" :denyut="$nota->status !== \App\Enums\DocumentStatus::Dibatalkan" class="shrink-0">
                            {{ $labelStatus($nota->status) }}
                        </x-lencana>
                    </div>

                    <div class="mt-3 grid grid-cols-2 gap-3">
                        <div class="min-w-0 rounded-xl border border-line px-3 py-2">
                            <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Beli dari</p>
                            <p class="text-[0.875rem] font-bold break-words text-ink">{{ $nota->supplier?->nama ?: '—' }}</p>
                        </div>
                        <div class="min-w-0 rounded-xl border border-line px-3 py-2">
                            <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Total belanja</p>
                            <p class="tabular text-[0.875rem] font-bold break-words text-ink">{{ $rupiah($nota->total) }}</p>
                        </div>
                    </div>

                    <p class="mt-2 text-[0.75rem] text-umber">
                        {{ $nota->items_count }} baris barang
                    </p>

                    {{-- Bergaris rambut, BUKAN isian penuh. Sepuluh kartu nota berarti sepuluh
                         batang berwarna penuh berjajar ke bawah, dan begitu semuanya berwarna
                         tidak ada lagi yang menonjol — termasuk satu-satunya tindakan utama
                         layar ini, "Catat nota belanja" di kepala halaman.

                         `.tombol-kedua` + `.tombol-ikon`, bentuk yang sama dengan tombol
                         pembuka rincian di tabel ≥lg: satu layar tidak boleh punya dua bentuk
                         untuk tindakan yang sama. aria-label MENYEBUT nomor notanya — teksnya
                         berbunyi "Lihat isi nota" sepuluh kali, dan pembaca layar yang
                         mendengar kalimat identik sepuluh kali tidak tahu ia sedang di nota
                         yang mana. --}}
                    <div class="mt-3 flex">
                        <button type="button" wire:click="bukaRincian('{{ $nota->getKey() }}')"
                                aria-label="{{ $rincianId === $nota->getKey() ? 'Tutup rincian nota '.$nota->nomor_po : 'Lihat isi nota '.$nota->nomor_po }}"
                                class="tombol-kedua h-11 w-full cursor-pointer px-3 text-[0.8125rem]">
                            <span class="tombol-ikon">
                                @if ($rincianId === $nota->getKey())
                                    <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                        <path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                                    </svg>
                                @else
                                    <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                        <path d="M4 5.5h9M4 10h12M4 14.5h7" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                                    </svg>
                                @endif
                            </span>
                            {{ $rincianId === $nota->getKey() ? 'Tutup rincian' : 'Lihat isi nota' }}
                        </button>
                    </div>

                    {{-- Tombol "sudah datang" HANYA pada nota yang barangnya belum masuk stok.
                         Ditaruh di kartunya, bukan hanya di panel rincian: nota yang ditunggu
                         biasanya ditandai sambil menurunkan barang dari motor, dan memaksa
                         membuka rincian dulu berarti dua ketukan untuk pekerjaan satu ketukan.

                         Keadaannya disimpan Alpine per kartu (`tanyaDatang`), bukan di
                         komponen: pertanyaannya hanya hidup selama kartunya di layar dan
                         server tidak perlu tahu. Tombol "Ya" ikut menutup pertanyaannya supaya
                         daftar yang dirender ulang tidak tertinggal dalam keadaan bertanya. --}}
                    @if ($bisaDitandaiDatang($nota->status))
                        <div class="mt-2" x-data="{ tanyaDatang: false }">
                            <div x-show="! tanyaDatang" class="flex">
                                <button type="button" x-on:click="tanyaDatang = true"
                                        class="tombol-kedua h-11 w-full cursor-pointer px-3 text-[0.8125rem]">
                                    <span class="tombol-ikon">
                                        <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                            <path d="M4 10.5l3.5 3.5L16 6" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                    </span>
                                    Tandai barang sudah datang
                                </button>
                            </div>

                            <div x-cloak x-show="tanyaDatang" class="rounded-xl border border-line bg-cream/60 p-3">
                                <p class="text-[0.8125rem] text-ink">
                                    <span class="font-bold">Tandai nota {{ $nota->nomor_po }} sudah datang?</span>
                                    Stok {{ $nota->outlet?->outlet_name ?? 'outlet nota ini' }} langsung bertambah
                                    sebanyak isi notanya.
                                </p>
                                {{-- WAJIB ada, dan bukan sebagai hiasan: kalimat ini satu-satunya
                                     tempat pemilik diberi tahu apa yang harus dilakukan kalau yang
                                     datang tidak sama dengan notanya. --}}
                                <p class="mt-1.5 text-[0.75rem] text-umber">{{ $catatanTerimaSebagian }}</p>
                                <div class="mt-2.5 flex gap-2">
                                    <button type="button" wire:click="tandaiDatang('{{ $nota->getKey() }}')"
                                            x-on:click="tanyaDatang = false" wire:loading.attr="disabled"
                                            class="tombol-utama h-11 flex-1 cursor-pointer px-3 text-[0.8125rem]">
                                        Ya, sudah datang
                                    </button>
                                    <button type="button" x-on:click="tanyaDatang = false"
                                            class="h-11 flex-1 cursor-pointer rounded-xl border border-line bg-white px-3 text-[0.8125rem] font-semibold text-ink transition-colors hover:bg-cream">
                                        Belum
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        {{-- `tanyaDatang` dipegang SATU keadaan untuk seluruh tabel, berisi id nota yang
             sedang ditanya — bukan satu keadaan per baris. Alasannya bentuk HTML-nya: blok
             konfirmasinya berdiri sebagai <tr> tersendiri di bawah baris notanya (satu baris
             tabel tidak bisa dibungkus <div> tanpa merusak tabelnya), jadi tombol dan bloknya
             adalah dua elemen BERSAUDARA yang harus melihat keadaan yang sama. Efek
             sampingnya justru diinginkan: hanya satu pertanyaan terbuka sekaligus. --}}
        <div class="kartu hidden overflow-hidden lg:block" x-data="{ tanyaDatang: null }">
            <table class="w-full table-fixed text-center">
                <thead>
                    <tr class="border-b border-line">
                        {{-- Lebar persen berjumlah 100%. Tanggal menempel DI BAWAH nomor nota,
                             bukan berkolom sendiri: keduanya satu identitas ("PB-20260805-001,
                             5 Agt"), dan kolom terpisah membuat mata memasangkannya sendiri
                             setiap baris. --}}
                        {{-- LIMA kolom, bukan enam. "Barang: 3 baris" dulu berkolom sendiri dan
                             menyisakan 18% lebar tabel untuk dua kata — sementara kolom Status
                             hanya kebagian 128px dan lencana "Barang sudah datang" (±165px)
                             TERPOTONG oleh tepi kartunya. Terpotongnya tidak terhitung sebagai
                             gulir mendatar (kartunya overflow-hidden), jadi ia hanya terlihat
                             di potretnya. Jumlah barisnya sekarang menempel di bawah total —
                             tempatnya memang di situ: ia menjelaskan uang yang di atasnya. --}}
                        {{-- Nota 1% lebih lebar dan selnya berpadding tipis (px-2, bukan px-5):
                             nomor notanya sekarang berdiri di dalam tombol berikon, dan tombol
                             itu butuh ±180px. Yang ditambah bukan hanya persennya — 24px
                             padding sel yang dibebaskan lebih besar daripada 1% lebar tabel.
                             Sisa 1% diambil dari Outlet dan diberikan ke Total belanja, karena
                             nominal yang pecah dua baris adalah cacat yang sudah diatur
                             tersendiri di CLAUDE.md. --}}
                        <th class="w-[23%] px-3 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Nota</th>
                        <th class="w-[21%] px-5 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Beli dari</th>
                        <th class="w-[17%] px-3 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Outlet</th>
                        <th class="w-[17%] px-3 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Total belanja</th>
                        <th class="w-[22%] px-3 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Status</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-soft">
                    @foreach ($daftar as $nota)
                        <tr @class([
                                'transition-colors hover:bg-cream/60',
                                'bg-cream/60' => $rincianId === $nota->getKey(),
                            ])
                            wire:key="baris-nota-{{ $nota->getKey() }}">
                            <td class="px-3 py-3.5">
                                {{-- Seluruh identitas nota jadi satu tombol: itu satu-satunya
                                     tindakan baris ini, dan tombol ikon kecil di ujung kanan
                                     membuat orang harus membidik sesudah membaca kiri.

                                     Dan tombolnya BERBENTUK tombol — `.tombol-kedua` bergaris
                                     rambut + `.tombol-ikon`, bentuk yang sama dengan kartu di
                                     <lg. Bentuk lama hanya teks berwarna terracotta: satu-satunya
                                     petunjuk bahwa ia bisa ditekan adalah warnanya, dan pemilik
                                     proyek bertanya persis itu ("bagaimana saya tahu
                                     PB-20260801-002 bisa diklik kalau tidak ada ikonnya?").
                                     Penandanya tidak boleh menunggu hover: layar ini dipakai di
                                     tablet dan HP, dan di sana hover TIDAK ADA — cacat yang sama
                                     sudah pernah terjadi pada tombol hapus yang kelabu sampai
                                     disorot. Tingginya min 44px, jadi sasaran sentuhnya sah juga
                                     di tablet lanskap yang memakai tata letak tabel ini.

                                     Selebar selnya (`w-full` + `justify-between`), bukan
                                     seukuran isinya: tanggalnya panjangnya berbeda-beda
                                     ("6 Agt 2026" vs "31 Jul 2026"), jadi tombol yang menyusut
                                     ke isinya membuat ikonnya berpindah tempat sampai 7px dari
                                     baris ke baris (terukur) — dan kolom yang tepinya bergerigi
                                     membuat mata mencari ikonnya lagi setiap turun satu baris.
                                     Nomor notanya rata kiri di posisi yang sama tiap baris. --}}
                                <button type="button" wire:click="bukaRincian('{{ $nota->getKey() }}')"
                                        class="tombol-kedua min-h-11 w-full cursor-pointer justify-between py-1.5 pr-1.5 pl-2.5 text-left"
                                        aria-label="{{ $rincianId === $nota->getKey() ? 'Tutup rincian nota '.$nota->nomor_po : 'Lihat isi nota '.$nota->nomor_po }}">
                                    <span class="min-w-0">
                                        <span class="tabular block truncate text-[0.875rem] font-bold text-terracotta">{{ $nota->nomor_po }}</span>
                                        <span class="tabular block truncate text-[0.75rem] font-normal text-umber-soft">
                                            {{ $nota->tanggal?->locale('id')->translatedFormat('j M Y') ?? '—' }}
                                        </span>
                                    </span>
                                    <span class="tombol-ikon">
                                        @if ($rincianId === $nota->getKey())
                                            <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                                <path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                                            </svg>
                                        @else
                                            <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                                <path d="M4 5.5h9M4 10h12M4 14.5h7" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                                            </svg>
                                        @endif
                                    </span>
                                </button>
                            </td>
                            <td class="px-5 py-3.5 text-[0.875rem] text-ink">{{ $nota->supplier?->nama ?: '—' }}</td>
                            <td class="px-3 py-3.5 text-[0.8125rem] text-umber">{{ $nota->outlet?->outlet_name ?: '—' }}</td>
                            <td class="tabular px-3 py-3.5">
                                <span class="block text-[0.9375rem] font-bold text-ink">{{ $rupiah($nota->total) }}</span>
                                <span class="block text-[0.6875rem] text-umber-soft">
                                    {{ $nota->items_count > 0 ? $nota->items_count.' baris barang' : '—' }}
                                </span>
                            </td>
                            <td class="px-3 py-3.5">
                                <x-lencana :warna="$warnaStatus($nota->status)" :denyut="$nota->status !== \App\Enums\DocumentStatus::Dibatalkan">
                                    {{ $labelStatus($nota->status) }}
                                </x-lencana>

                                {{-- Tombolnya di BAWAH lencananya, di kolom yang sama: lencana
                                     menyatakan keadaannya, tombol mengubah keadaan itu — dan
                                     tindakan yang berdiri jauh dari keterangan yang
                                     menjelaskannya membuat mata berpindah dua kali tiap baris.
                                     Teksnya dipendekkan ("Tandai sudah datang") karena kolomnya
                                     ±180px bersih; kalimat penuhnya ada di blok konfirmasinya,
                                     dan aria-label menyebut nomor notanya supaya pembaca layar
                                     tidak mendengar sepuluh kalimat identik. --}}
                                @if ($bisaDitandaiDatang($nota->status))
                                    <button type="button" x-on:click="tanyaDatang = '{{ $nota->getKey() }}'"
                                            aria-label="Tandai barang nota {{ $nota->nomor_po }} sudah datang"
                                            class="tombol-kedua mt-2 h-9 w-full cursor-pointer px-2 text-[0.75rem]">
                                        <span class="tombol-ikon">
                                            <svg viewBox="0 0 20 20" class="size-3.5" fill="none" aria-hidden="true">
                                                <path d="M4 10.5l3.5 3.5L16 6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                                            </svg>
                                        </span>
                                        Tandai sudah datang
                                    </button>
                                @endif
                            </td>
                        </tr>

                        {{-- Blok konfirmasi selebar tabel, bukan di dalam sel Status yang ±180px:
                             kalimat $catatanTerimaSebagian butuh ruang, dan keterangan yang
                             dipadatkan jadi enam baris huruf kecil di dalam satu sel tidak akan
                             dibaca siapa pun — padahal ia satu-satunya yang menjelaskan barang
                             yang datang tidak sesuai nota. --}}
                        @if ($bisaDitandaiDatang($nota->status))
                            <tr x-cloak x-show="tanyaDatang === '{{ $nota->getKey() }}'"
                                class="bg-cream/60" wire:key="tanya-datang-{{ $nota->getKey() }}">
                                <td colspan="5" class="px-3 py-3.5 text-left">
                                    <p class="text-[0.8125rem] text-ink">
                                        <span class="font-bold">Tandai nota {{ $nota->nomor_po }} sudah datang?</span>
                                        Stok {{ $nota->outlet?->outlet_name ?? 'outlet nota ini' }} langsung bertambah
                                        sebanyak isi nota ini, dan harga beli barangnya ikut diperbarui.
                                    </p>
                                    <p class="mt-1 text-[0.8125rem] text-umber">{{ $catatanTerimaSebagian }}</p>
                                    <div class="mt-2.5 flex gap-2">
                                        <button type="button" wire:click="tandaiDatang('{{ $nota->getKey() }}')"
                                                x-on:click="tanyaDatang = null" wire:loading.attr="disabled"
                                                class="tombol-utama h-11 cursor-pointer px-4 text-[0.8125rem]">
                                            Ya, barangnya sudah datang
                                        </button>
                                        <button type="button" x-on:click="tanyaDatang = null"
                                                class="h-11 cursor-pointer rounded-xl border border-line bg-white px-4 text-[0.8125rem] font-semibold text-ink transition-colors hover:bg-cream">
                                            Belum
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">{{ $daftar->links() }}</div>
    @endif
</div>

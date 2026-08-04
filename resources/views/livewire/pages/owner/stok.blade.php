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
                'kurang '.$angka($b['kekurangan']).' '.$satuanDasar.' dari ambang '.$angka($b['minimum']).' '.$satuanDasar
                    .' · 1 '.$satuanBeli.' = '.$angka($b['beli']['isi_per_satuan']).' '.$satuanDasar,
            ];
        }

        if ($b['kekurangan'] > 0) {
            return [
                'Beli '.$angka($b['kekurangan']).' '.$dasar,
                'sisa '.$angka($b['sistem']).' '.$dasar.', ambang '.$angka($b['minimum']).' '.$dasar,
            ];
        }

        if ($b['status'] === 'minus') {
            return [
                'Catatan minus '.$angka(abs($b['sistem'])).' '.$dasar,
                'Angka minus berarti pencatatannya yang bermasalah, bukan raknya. Belanja tidak menyelesaikan ini — hitung fisiknya dulu.',
            ];
        }

        return [
            'Habis, ambang belum disetel',
            'Raknya kosong, tapi jumlah belanjanya belum bisa dihitung. Setel ambang minimumnya di daftar bawah.',
        ];
    };
@endphp

<div>
    <x-kartu-alat
        judul="Stok per outlet"
        jumlah="{{ $daftar->total() }}"
        keterangan="Stok dicatat per outlet, jadi daftar ini selalu tentang satu outlet — angka gabungan cabang tidak bisa dibelanjakan maupun dihitung fisik."
    >
        <x-slot:aksi>
            {{-- Tautan, bukan tombol wire:click: opname adalah halaman tersendiri, dan
                 tautan bisa dibuka di tab lain saat lembar hitungnya dipegang orang kedua. --}}
            <a href="{{ route('owner.stok.opname', $outletDipakai !== null ? ['outlet' => $outletDipakai] : []) }}"
               wire:navigate
               class="flex h-11 items-center gap-2 rounded-xl bg-terracotta px-5 text-[0.875rem] font-bold text-white transition-colors hover:bg-terracotta-deep">
                <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                    <path d="M6 4h8v12H6V4Zm2.5 3.5h3M8.5 10h3M8.5 12.5h1.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
                </svg>
                Hitung fisik (opname)
            </a>
        </x-slot:aksi>

        <x-slot:saringan>
            <div class="grid gap-3 lg:grid-cols-[1fr_11rem_13rem]">
                <div class="min-w-0">
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
                     tanpa membawa siapa pun ke barangnya. Barisnya digulir mendatar di
                     ponsel alih-alih melebarkan halaman. --}}
                <div class="-m-px max-w-full overflow-x-auto p-px lg:col-span-full [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                    <div class="inline-flex w-fit items-center gap-1 rounded-xl bg-white p-1 ring-1 ring-line">
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
                                        'flex h-9 shrink-0 cursor-pointer items-center gap-1.5 rounded-lg px-3 text-[0.8125rem] whitespace-nowrap transition',
                                        'bg-terracotta font-semibold text-white' => $status === $nilai,
                                        'font-medium text-umber hover:bg-cream hover:text-ink' => $status !== $nilai,
                                    ])>
                                {{ $teks }}
                                <span class="tabular text-[0.6875rem] opacity-70">{{ $jumlah }}</span>
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
        @php $tampil = $harusBelanja->take(9); @endphp

        <div class="kartu mb-4 overflow-hidden sm:mb-5">
            <div class="border-b border-line-soft px-5 py-4 sm:px-6">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-[1.0625rem] font-bold text-ink">Harus belanja</h2>
                    <span class="tabular rounded-full bg-merah/10 px-2.5 py-0.5 text-[0.75rem] font-semibold text-merah-deep">
                        {{ $harusBelanja->count() }} barang
                    </span>
                </div>
                <p class="mt-1 max-w-3xl text-[0.875rem] text-umber">
                    Paling parah di urutan pertama. Jumlahnya dibulatkan ke ATAS ke satuan beli —
                    dibulatkan ke bawah, pulang dari pasar tetap kurang barang.
                </p>
            </div>

            <div class="grid gap-3 px-5 py-4 sm:px-6 lg:grid-cols-2 xl:grid-cols-3">
                @foreach ($tampil as $b)
                    @php [$judulTindakan, $ketTindakan] = $tindakan($b); @endphp

                    <div class="flex min-w-0 flex-col gap-2 rounded-xl border border-line p-3.5">
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
                    {{ $harusBelanja->count() - $tampil->count() }} barang lain juga di bawah ambang.
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
    @if ($ringkasan['belum_dihitung'] > 0 && $status !== 'belum_dihitung')
        <div class="kartu mb-4 flex flex-wrap items-center justify-between gap-3 border border-line px-5 py-4 sm:mb-5 sm:px-6">
            <div class="flex min-w-0 flex-1 items-start gap-3">
                <svg viewBox="0 0 20 20" class="mt-0.5 size-4 shrink-0 text-umber-soft" fill="none" aria-hidden="true">
                    <rect x="3.25" y="3.25" width="13.5" height="13.5" rx="3" stroke="currentColor" stroke-width="1.5" />
                    <path d="M7 10h6" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                </svg>
                <p class="min-w-0 text-[0.875rem] text-ink">
                    <span class="font-bold">{{ $ringkasan['belum_dihitung'] }} barang belum pernah dihitung di outlet ini.</span>
                    Angkanya belum ada, jadi jumlah belanjanya belum bisa dihitung — hitung fisiknya dulu.
                </p>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <button type="button" wire:click="$set('status', 'belum_dihitung')"
                        class="h-10 shrink-0 cursor-pointer rounded-lg border border-line px-3.5 text-[0.8125rem] font-semibold text-ink transition-colors hover:bg-cream">
                    Lihat barangnya
                </button>
                <a href="{{ route('owner.stok.opname', ['outlet' => $outletDipakai, 'status' => 'belum_pernah']) }}"
                   wire:navigate
                   class="flex h-10 shrink-0 items-center rounded-lg bg-terracotta px-3.5 text-[0.8125rem] font-semibold text-white transition-colors hover:bg-terracotta-deep">
                    Hitung fisik
                </a>
            </div>
        </div>
    @endif

    {{-- Penanda "perlu dihitung ulang" diangkat ke atas juga: ia tidak selalu berbarengan
         dengan status di bawah ambang, jadi barang yang saldonya masih aman tapi mungkin
         terpotong dua kali tidak akan pernah terlihat di blok harus-belanja. --}}
    @if ($ringkasan['perlu_diperiksa'] > 0 && $status !== 'perlu_diperiksa')
        <div class="kartu mb-4 flex flex-wrap items-center justify-between gap-3 border border-jingga/30 px-5 py-4 sm:mb-5 sm:px-6">
            <div class="flex min-w-0 flex-1 items-start gap-3">
                <svg viewBox="0 0 20 20" class="mt-0.5 size-4 shrink-0 text-jingga-tua" fill="none" aria-hidden="true">
                    <circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.5" />
                    <path d="M10 6.5v4M10 13.4v.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                </svg>
                <p class="min-w-0 text-[0.875rem] text-ink">
                    <span class="font-bold">{{ $ringkasan['perlu_diperiksa'] }} barang perlu dihitung ulang.</span>
                    Ada penjualan offline yang masuk setelah opname terakhirnya, jadi saldonya mungkin sudah terpotong dua kali.
                </p>
            </div>
            <button type="button" wire:click="$set('status', 'perlu_diperiksa')"
                    class="h-10 shrink-0 cursor-pointer rounded-lg border border-line px-3.5 text-[0.8125rem] font-semibold text-ink transition-colors hover:bg-cream">
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
            <div class="flex flex-wrap items-start justify-between gap-3 border-b border-line px-5 py-4 sm:px-6">
                <div class="min-w-0">
                    <p class="eyebrow text-umber-soft">Kartu stok</p>
                    <h2 class="mt-1 text-[1.0625rem] font-bold text-ink">{{ $barisKartu['nama'] }}</h2>
                    <p class="tabular mt-0.5 text-[0.8125rem] text-umber">
                        Sekarang
                        <span class="font-semibold text-ink">
                            {{ $barisKartu['punya_baris']
                                ? trim($angka($barisKartu['sistem']).' '.($barisKartu['satuan_dasar'] ?? ''))
                                : 'belum dihitung' }}
                        </span>
                        · 30 pergerakan terakhir, terbaru di atas
                    </p>
                </div>
                <button type="button" wire:click="tutupKartu" aria-label="Tutup kartu stok"
                        class="grid size-10 shrink-0 cursor-pointer place-items-center rounded-lg text-umber transition-colors hover:bg-cream">
                    <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                        <path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                    </svg>
                </button>
            </div>

            @if ($kartu->isEmpty())
                <div class="px-5 py-6 sm:px-6">
                    <p class="text-[0.875rem] font-semibold text-ink">Belum ada pergerakan tercatat.</p>
                    <p class="mt-1 max-w-xl text-[0.8125rem] text-umber">
                        Kartu stok terisi sendiri begitu ada penjualan, penerimaan barang, atau hasil hitung fisik yang disimpan.
                    </p>
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
                            <p class="mt-1 text-[0.75rem] text-umber">
                                {{ $m->alasan?->label() ?? '—' }} · {{ $m->catatan ?: '—' }}
                            </p>
                        </li>
                    @endforeach
                </ul>

                <div class="hidden lg:block">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="border-b border-line">
                                <th class="w-44 px-5 py-3 text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Waktu</th>
                                <th class="w-36 px-5 py-3 text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Jenis</th>
                                <th class="w-32 px-5 py-3 text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Alasan</th>
                                <th class="w-28 px-5 py-3 text-right text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Jumlah</th>
                                <th class="w-28 px-5 py-3 text-right text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Saldo</th>
                                <th class="w-36 px-5 py-3 text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Oleh</th>
                                <th class="px-5 py-3 text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Catatan</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-line-soft">
                            @foreach ($kartu as $m)
                                <tr>
                                    <td class="tabular px-5 py-3 text-[0.8125rem] text-umber">
                                        {{ $m->created_at?->locale('id')->translatedFormat('j M Y, H:i') ?? '—' }}
                                    </td>
                                    <td class="px-5 py-3 text-[0.8125rem] font-semibold text-ink">{{ $m->tipe->label() }}</td>
                                    <td class="px-5 py-3 text-[0.8125rem] text-umber">{{ $m->alasan?->label() ?? '—' }}</td>
                                    <td @class([
                                        'tabular px-5 py-3 text-right text-[0.875rem] font-bold',
                                        'text-hijau-tua' => (float) $m->jumlah > 0,
                                        'text-merah-deep' => (float) $m->jumlah < 0,
                                        'text-umber' => (float) $m->jumlah === 0.0,
                                    ])>
                                        {{ (float) $m->jumlah > 0 ? '+' : '' }}{{ $angka($m->jumlah) }}
                                    </td>
                                    <td class="tabular px-5 py-3 text-right text-[0.875rem] text-ink">{{ $angka($m->saldo_sesudah) }}</td>
                                    <td class="px-5 py-3 text-[0.8125rem] text-umber">{{ $m->olehUser?->name ?? 'sistem' }}</td>
                                    <td class="px-5 py-3 text-[0.8125rem] text-umber">{{ $m->catatan ?: '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif

    {{-- ── Daftar ──────────────────────────────────────────────────────────── --}}
    @if ($daftar->isEmpty())
        <x-kosong judul="Tidak ada barang yang cocok"
                  keterangan="Coba ubah kata pencarian atau saringannya. Produk tanpa pelacakan stok dan menu berbasis resep memang tidak muncul di sini — yang berkurang saat menu terjual adalah bahan bakunya.">
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
                            <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Sistem</p>
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
                            <p class="text-[0.6875rem] font-semibold tracking-wide text-umber-soft uppercase">Ambang</p>
                            <p class="tabular text-[0.9375rem] font-bold text-ink">
                                {{ $b['minimum'] > 0 ? $angka($b['minimum']) : '—' }}
                                <span class="text-[0.75rem] font-medium text-umber">
                                    {{ $b['minimum'] > 0 ? ($b['satuan_dasar'] ?? '') : 'tidak dipantau' }}
                                </span>
                            </p>
                        </div>
                    </div>

                    <p class="mt-2 text-[0.75rem] text-umber">
                        Opname terakhir:
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
                                Ambang minimum ({{ $b['satuan_dasar'] ?? 'satuan dasar' }})
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
                            <div class="mt-3 flex gap-2">
                                <button type="button" wire:click="simpanAmbang"
                                        class="h-10 flex-1 cursor-pointer rounded-lg bg-terracotta px-3 text-[0.8125rem] font-bold text-white transition-colors hover:bg-terracotta-deep">
                                    Simpan ambang
                                </button>
                                <button type="button" wire:click="batalUbahAmbang"
                                        class="h-10 flex-1 cursor-pointer rounded-lg border border-line px-3 text-[0.8125rem] font-semibold text-ink transition-colors hover:bg-cream">
                                    Batal
                                </button>
                            </div>
                        </div>
                    @else
                        <div class="mt-3 flex gap-2">
                            <button type="button" wire:click="ubahAmbang('{{ $b['kunci'] }}')"
                                    class="h-10 flex-1 cursor-pointer rounded-lg border border-line px-3 text-[0.8125rem] font-semibold text-ink transition-colors hover:bg-cream">
                                Setel ambang
                            </button>
                            <button type="button" wire:click="bukaKartu('{{ $b['kunci'] }}')"
                                    class="h-10 flex-1 cursor-pointer rounded-lg border border-line px-3 text-[0.8125rem] font-semibold text-ink transition-colors hover:bg-cream">
                                {{ $kartuKunci === $b['kunci'] ? 'Tutup kartu stok' : 'Kartu stok' }}
                            </button>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="kartu hidden overflow-hidden lg:block">
            <table class="w-full text-left">
                <thead>
                    <tr class="border-b border-line">
                        <th class="px-5 py-3.5 text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Barang</th>
                        <th class="w-32 px-5 py-3.5 text-right text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Sistem</th>
                        <th class="w-56 px-5 py-3.5 text-right text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Ambang</th>
                        <th class="w-28 px-5 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Status</th>
                        <th class="w-44 px-5 py-3.5 text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Opname terakhir</th>
                        <th class="w-20 px-5 py-3.5"><span class="sr-only">Aksi</span></th>
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
                            <td class="tabular px-5 py-3.5 text-right text-[0.9375rem]">
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
                                                Ambang minimum {{ $b['nama'] }}
                                            </label>
                                            <input id="ambang-{{ $b['kunci'] }}" type="text" inputmode="decimal"
                                                   wire:model="ambangNilai" wire:keydown.enter="simpanAmbang"
                                                   placeholder="0" value="{{ $ambangNilai }}"
                                                   class="tabular h-10 w-full rounded-lg border border-line bg-white px-3 text-right text-[0.875rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                                        </div>
                                        <x-aksi warna="utama" label="Simpan ambang {{ $b['nama'] }}"
                                                class="size-10" wire:click="simpanAmbang">
                                            <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                                <path d="m5 10.5 3.5 3.5L15 7" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" />
                                            </svg>
                                        </x-aksi>
                                        <x-aksi warna="netral" label="Batal ubah ambang" class="size-10"
                                                wire:click="batalUbahAmbang">
                                            <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                                <path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                                            </svg>
                                        </x-aksi>
                                    </div>
                                    @error('ambangNilai')
                                        <p class="mt-1.5 text-right text-[0.75rem] text-merah-deep">{{ $message }}</p>
                                    @enderror
                                @else
                                    <button type="button" wire:click="ubahAmbang('{{ $b['kunci'] }}')"
                                            class="tabular ml-auto flex h-10 cursor-pointer items-center gap-2 rounded-lg border border-line px-3 text-[0.875rem] text-ink transition-colors hover:border-terracotta hover:text-terracotta"
                                            title="Setel ambang minimum {{ $b['nama'] }}">
                                        @if ($b['minimum'] > 0)
                                            <span class="font-semibold">{{ $angka($b['minimum']) }}</span>
                                            <span class="text-[0.75rem] text-umber-soft">{{ $b['satuan_dasar'] ?? '' }}</span>
                                        @else
                                            <span class="text-[0.8125rem] text-umber">Setel ambang</span>
                                        @endif
                                        <svg viewBox="0 0 20 20" class="size-3.5 shrink-0 text-umber-soft" fill="none" aria-hidden="true">
                                            <path d="M13 3.5 16.5 7 8 15.5H4.5V12L13 3.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                    </button>
                                @endif
                            </td>

                            <td class="px-5 py-3.5 text-center">
                                <x-lencana :warna="$warnaStatus($b['status'])" :denyut="$b['status'] !== 'belum_dihitung'">{{ $labelStatus($b['status']) }}</x-lencana>
                            </td>

                            <td class="px-5 py-3.5 text-[0.8125rem]">
                                <span class="tabular block text-umber">
                                    {{ $b['opname_terakhir_pada']?->locale('id')->translatedFormat('j M Y, H:i') ?? 'belum pernah' }}
                                </span>
                                @if ($b['perlu_diperiksa'])
                                    <span class="mt-1 inline-block rounded-full bg-jingga/15 px-2 py-0.5 text-[0.6875rem] font-semibold text-jingga-tua">
                                        perlu dihitung ulang
                                    </span>
                                @endif
                            </td>

                            <td class="px-5 py-3.5">
                                <div class="flex items-center justify-end">
                                    <x-aksi warna="utama"
                                            label="{{ $kartuKunci === $b['kunci'] ? 'Tutup kartu stok '.$b['nama'] : 'Kartu stok '.$b['nama'] }}"
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

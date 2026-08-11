@php
    /*
     * Kalimat dialog hapus diketik SEKALI di sini, bukan dua kali.
     *
     * Barisnya digambar dua bentuk (kartu di ponsel, tabel di layar lebar), jadi teks yang
     * ditulis di masing-masing tempat pasti bercabang begitu salah satunya diperbaiki — dan
     * yang bercabang adalah peringatan tentang berkas yang tidak bisa dikembalikan.
     *
     * Isinya menyebut APA yang hilang dan apa yang TIDAK hilang. Keduanya perlu: fotonya
     * memang tidak bisa dipulihkan, tapi catatan penjualannya utuh (soft delete) — dan
     * peringatan yang lebih menakutkan daripada kenyataannya membuat orang berhenti
     * memercayai peringatan berikutnya.
     */
    $pesanHapusProduk = 'Barangnya hilang dari layar kasir, dan fotonya ikut dibuang dari '
        .'penyimpanan — foto itu tidak bisa dikembalikan. Catatan penjualan yang sudah lewat '
        .'tetap utuh: omzet bulan lalu tidak berubah.';
@endphp

{{-- Lingkup zoom gambar dipasang di akar halaman, bukan per baris: satu modal
     dipakai bersama seluruh baris, jadi tidak ada puluhan salinan markup modal
     yang ikut dikirim di setiap muatan halaman. --}}
@php
    /*
     * Penolong margin, diketik SEKALI untuk dua bentuk (kartu di ponsel, tabel di ≥lg).
     * Angkanya sendiri datang dari App\Actions\Produk\HitungHppAction lewat $hpp — Blade
     * tidak menghitung apa pun, supaya rumusnya tidak pernah bercabang dari layar lain.
     */
    $rp = fn ($nilai) => 'Rp '.number_format((float) $nilai, 0, ',', '.');

    $marginProduk = function ($produk) use ($hpp) {
        $h = $hpp[$produk->id] ?? null;

        if ($h === null) {
            return null;
        }

        return app(\App\Actions\Produk\HitungHppAction::class)
            ->margin($h['hpp'], (float) $produk->harga_default) + ['info' => $h];
    };
@endphp

<div x-data="{ zoom: null }" @keydown.escape.window="zoom = null">
    <x-kartu-alat
        judul="Daftar produk"
        jumlah="{{ $jumlahAktif + $jumlahNonaktif }}"
        keterangan="Daftar ini yang muncul di layar kasir. Harga di sini adalah harga yang ditagih."
    >
        <x-slot:aksi>
            {{-- Pintu ke impor massal. Ditaruh di sini, bukan hanya di halaman kosong:
                 pemilik yang sudah punya 20 barang lalu mendapat daftar harga dari grosir
                 tetap butuh mengimpor, dan tautan yang cuma muncul saat kosong membuat ia
                 mengetik 200 barang satu per satu tanpa tahu ada jalan lain. --}}
            <a href="{{ route('owner.produk.impor') }}" wire:navigate
               class="tombol-kedua flex h-11 cursor-pointer items-center gap-2 px-4 text-[0.875rem]">
                <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                    <path d="M10 12V3m0 9 3.5-3.5M10 12 6.5 8.5M4 15.5h12" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
                <span class="sm:hidden">Impor</span>
                <span class="hidden sm:inline">Impor dari CSV</span>
            </a>

            <button type="button" wire:click="tambah"
                    class="flex h-11 cursor-pointer items-center gap-2 rounded-xl bg-terracotta px-5 text-[0.875rem] font-bold text-white transition-colors hover:bg-terracotta-deep">
                <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                    <path d="M10 4v12M4 10h12" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                </svg>
                Produk baru
            </button>
        </x-slot:aksi>

        <x-slot:saringan>
            <div class="grid gap-3 lg:grid-cols-[1fr_14rem_auto]">
                <div class="relative min-w-0"
                     @if ($this->pakaiBarcode()) x-data="pemindaiBarcode" @barcode-terpindai="$wire.set('cari', $event.detail.nilai)" @endif>
                    <label for="cari" class="sr-only">Cari produk</label>
                    <svg viewBox="0 0 20 20" class="pointer-events-none absolute top-1/2 left-4 size-4 -translate-y-1/2 text-umber-soft"
                         fill="none" aria-hidden="true">
                        <circle cx="9" cy="9" r="5.5" stroke="currentColor" stroke-width="1.6" />
                        <path d="m13.5 13.5 3 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                    </svg>
                <input id="cari" type="search" wire:model.live.debounce.300ms="cari"
                       placeholder="Cari nama, SKU, atau barcode…"
                       class="h-11 w-full rounded-xl border border-line bg-white pl-11 text-[0.875rem] text-ink focus:border-terracotta focus:outline-none {{ $this->pakaiBarcode() ? 'pr-12' : 'pr-4' }}">

                @if ($this->pakaiBarcode())
                    {{-- Mencari barang juga cukup dipindai: kasir/owner tidak perlu tahu
                         nama persisnya, cukup arahkan barangnya. --}}
                    <button type="button" @click="buka()" x-show="bisaKamera" x-cloak
                            aria-label="Cari dengan memindai barcode"
                            class="absolute top-1/2 right-2 grid size-8 -translate-y-1/2 cursor-pointer place-items-center rounded-lg text-umber-soft transition-colors hover:bg-cream hover:text-ink">
                        <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                            <path d="M3 7V4.5h2.5M17 7V4.5h-2.5M3 13v2.5h2.5M17 13v2.5h-2.5M6 7v6m2.5-6v6M11 7v6m2.5-6v6"
                                  stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
                        </svg>
                    </button>

                    <x-panel-pindai judul="Pindai untuk mencari" />
                @endif
                </div>

                <div class="min-w-0">
                    <label for="kategori" class="sr-only">Kategori</label>
                    <select id="kategori" wire:model.live="kategoriId"
                            class="h-11 w-full rounded-xl border border-line bg-white px-3 text-[0.875rem] font-medium text-ink focus:border-terracotta focus:outline-none">
                        <option value="">Semua kategori</option>
                        @foreach ($kategori as $k)
                            <option value="{{ $k->id }}">{{ $k->nama }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Jumlah dicantumkan di pilihannya: owner sering mencari "yang mana yang
                     saya sembunyikan", dan angkanya menjawab itu tanpa perlu diklik.
                     Barisnya digulir mendatar di ponsel, bukan melebarkan halaman. --}}
                <div class="-m-px max-w-full overflow-x-auto p-px [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
                    <div class="inline-flex w-fit items-center gap-1 rounded-xl bg-white p-1 ring-1 ring-line">
                        {{-- Chip "Menipis" memakai saringan yang BERBEDA ($stok, bukan
                             $status), jadi ia perlu penanda aktif sendiri. Angkanya dihitung
                             komponen dengan aturan yang sama persis seperti dasbor — kalau
                             dihitung ulang di sini, dua angka itu akan berbeda suatu hari. --}}
                        <button type="button" wire:click="$set('stok', '{{ $stok === 'menipis' ? 'semua' : 'menipis' }}')"
                                aria-pressed="{{ $stok === 'menipis' ? 'true' : 'false' }}"
                                @class([
                                    'flex h-9 shrink-0 cursor-pointer items-center gap-1.5 rounded-lg px-3 text-[0.8125rem] whitespace-nowrap transition',
                                    'bg-jingga font-semibold text-ink' => $stok === 'menipis',
                                    'font-medium text-umber hover:bg-cream hover:text-ink' => $stok !== 'menipis',
                                ])>
                            Menipis
                            <span class="tabular text-[0.6875rem] opacity-70">{{ $jumlahMenipis }}</span>
                        </button>

                        @foreach ([
                            ['semua', 'Semua', $jumlahAktif + $jumlahNonaktif],
                            ['aktif', 'Aktif', $jumlahAktif],
                            ['nonaktif', 'Nonaktif', $jumlahNonaktif],
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

    <x-istilah-layar :kunci="['modal', 'untung', 'kode-barang', 'barcode']" />

    {{-- ── Daftar ────────────────────────────────────────────────────────── --}}
    @if ($daftar->isEmpty())
        <x-kosong judul="Tidak ada produk yang cocok"
                  keterangan="Coba ubah kata pencarian atau saringannya, atau tambahkan produk baru.">
            <x-slot:aksi>
                <button type="button" wire:click="tambah"
                        class="h-11 cursor-pointer rounded-xl bg-terracotta px-5 text-[0.875rem] font-bold text-white transition-colors hover:bg-terracotta-deep">
                    Produk baru
                </button>
            </x-slot:aksi>
        </x-kosong>
    @else
        {{--
            Dua bentuk untuk data yang sama: kartu di layar sempit, tabel di layar lebar.
            Tabel yang dipaksa masuk ke ponsel menuntut gulir mendatar, dan kolom harga
            justru yang pertama hilang dari pandangan.
        --}}
        <div class="space-y-3 lg:hidden">
            @foreach ($daftar as $produk)
                <div class="kartu p-4">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex min-w-0 items-center gap-3">
                            @if ($this->punyaGambar($produk->gambar_path))
                                <button
                                    type="button"
                                    @click="zoom = { src: @js(asset('storage/'.$produk->gambar_path)), nama: @js($produk->nama_produk) }"
                                    class="size-12 shrink-0 cursor-zoom-in overflow-hidden rounded-xl"
                                    aria-label="Perbesar gambar {{ $produk->nama_produk }}"
                                >
                                    <img src="{{ asset('storage/'.$produk->gambar_path) }}"
                                         alt="" loading="lazy" class="size-full object-cover">
                                </button>
                            @else
                                <span class="grid size-12 shrink-0 place-items-center rounded-xl bg-cream-deep text-[0.75rem] font-bold text-umber-soft"
                                      aria-hidden="true">
                                    {{ mb_strtoupper(mb_substr($produk->nama_produk, 0, 2)) }}
                                </span>
                            @endif
                            <div class="min-w-0">
                            <p class="truncate text-[0.9375rem] font-bold text-ink">{{ $produk->nama_produk }}</p>
                            <p class="mt-0.5 text-[0.75rem] text-umber">
                                {{ $produk->kategori?->nama ?? 'Tanpa kategori' }}
                                &middot; per {{ $produk->satuan?->label() ?? '—' }}
                            </p>
                            </div>
                        </div>
                        @unless ($produk->is_active)
                            <x-lencana warna="merah" class="shrink-0">Nonaktif</x-lencana>
                        @endunless
                    </div>

                    <div class="mt-3 flex items-end justify-between gap-3">
                        <div>
                            <p class="tabular text-[1.125rem] font-bold text-terracotta">
                                Rp {{ number_format((float) $produk->harga_default, 0, ',', '.') }}
                            </p>
                            @php($m = $marginProduk($produk))
                            @if ($m !== null && $m['rupiah'] !== null)
                                {{-- Modal DAN margin bersama, bukan salah satu. "Beli
                                     Rp 9.000" tidak menjawab pertanyaan yang dibawa orang ke
                                     daftar ini — yaitu apakah barangnya masih untung setelah
                                     harga bahan naik. --}}
                                <p class="tabular text-[0.75rem] text-umber-soft">
                                    modal {{ $rp($m['info']['hpp']) }}
                                    <span @class([
                                        'font-bold',
                                        'text-merah-tua' => $m['rugi'],
                                        'text-hijau-tua' => ! $m['rugi'],
                                    ])>
                                        · {{ $m['rugi'] ? 'rugi ' : '+' }}{{ $rp(abs($m['rupiah'])) }}
                                        @if ($m['persen'] !== null)
                                            ({{ number_format($m['persen'], 1, ',', '.') }}%)
                                        @endif
                                    </span>
                                </p>
                            @elseif ($m !== null && ($m['info']['bahanTanpaHarga'] ?? []) !== [])
                                {{-- Menyebut BAHAN MANA yang kurang, bukan sekadar "belum
                                     bisa dihitung": tanpa nama, pemilik membuka satu per satu
                                     enam bahan untuk mencari yang mana. --}}
                                <p class="text-[0.75rem] text-umber-soft">
                                    modal belum terhitung — {{ collect($m['info']['bahanTanpaHarga'])->join(', ') }} belum ada harganya
                                </p>
                            @else
                                <p class="text-[0.75rem] text-umber-soft">modal belum diisi</p>
                            @endif
                            @php($statusStokKartu = $produk->statusStokTerjoin())
                            @if ($statusStokKartu !== null)
                                <p @class([
                                    'tabular text-[0.75rem] font-semibold',
                                    'text-merah-deep' => in_array($statusStokKartu, ['minus', 'habis'], true),
                                    'text-jingga-tua' => $statusStokKartu === 'menipis',
                                    'text-umber' => $statusStokKartu === 'aman',
                                ])>
                                    sisa {{ rtrim(rtrim(number_format((float) $produk->jumlah_saat_ini, 3, ',', '.'), '0'), ',') }}
                                    @if ($statusStokKartu !== 'aman') · {{ $statusStokKartu }} @endif
                                </p>
                            @endif
                        </div>
                        <div class="flex items-center gap-1.5">
                            <x-aksi warna="utama" label="Ubah {{ $produk->nama_produk }}"
                                    class="size-10" wire:click="ubah('{{ $produk->id }}')">
                                <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                    <path d="M13 3.5 16.5 7 8 15.5H4.5V12L13 3.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </x-aksi>

                            <x-aksi warna="netral" class="size-10"
                                    label="{{ $produk->is_active ? 'Sembunyikan dari kasir' : 'Tampilkan di kasir' }}"
                                    wire:click="ubahAktif('{{ $produk->id }}')">
                                @if ($produk->is_active)
                                    <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                        <path d="M3 3l14 14M8.2 8.3a2.5 2.5 0 0 0 3.5 3.5M6.1 6.3C4.4 7.3 3 9 2.5 10c1 2.2 3.9 4.5 7.5 4.5 1.2 0 2.3-.25 3.3-.66m2.2-1.5c.9-.7 1.6-1.5 2-2.34-1-2.2-3.9-4.5-7.5-4.5-.6 0-1.2.06-1.7.18"
                                              stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                @else
                                    <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                        <path d="M10 5.5c3.6 0 6.5 2.3 7.5 4.5-1 2.2-3.9 4.5-7.5 4.5S3.5 12.2 2.5 10c1-2.2 3.9-4.5 7.5-4.5Z"
                                              stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                        <circle cx="10" cy="10" r="2.25" stroke="currentColor" stroke-width="1.5" />
                                    </svg>
                                @endif
                            </x-aksi>

                            {{-- Konfirmasinya lewat pembungkus SweetAlert bersama
                                 (window.konfirmasiNampan, resources/js/toast.js), bukan panel
                                 dua langkah di dalam barisnya: panel itu menukar isi selnya,
                                 jadi tombol "Ya, hapus" muncul tepat di bawah jempol yang baru
                                 menekan ikon hapus. Bukan pula dialog bawaan peramban — teksnya
                                 tidak bisa menyebut nama barangnya.

                                 Dialog ini BUKAN pengamannya: hapus() di server tetap mencari
                                 produknya lewat scope tenant, jadi muatan Livewire yang dikirim
                                 tanpa dialog tetap tertahan. --}}
                            <x-aksi warna="bahaya" label="Hapus {{ $produk->nama_produk }}" class="size-10"
                                    x-on:click="window.konfirmasiNampan({ judul: {{ \Illuminate\Support\Js::from('Hapus '.$produk->nama_produk.'?') }}, pesan: {{ \Illuminate\Support\Js::from($pesanHapusProduk) }}, tombolYa: 'Ya, hapus', tombolBatal: 'Tidak jadi' }).then((ya) => ya && $wire.hapus({{ \Illuminate\Support\Js::from($produk->id) }}))">
                                <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                    <path d="M4 6h12M8 6V4.5h4V6m-6 0v9.5A1.5 1.5 0 0 0 7.5 17h5a1.5 1.5 0 0 0 1.5-1.5V6M8.5 9v5m3-5v5"
                                          stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                            </x-aksi>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>

        <div class="kartu hidden overflow-hidden lg:block">
            <table class="w-full text-left">
                <thead>
                    {{--
                        Lebar dan perataan kolom ditetapkan, tidak dibiarkan otomatis.

                        Sebelumnya kolom Status rata kiri tepat setelah dua kolom angka
                        yang rata kanan, sehingga teksnya menempel ke "Harga beli"
                        sementara sisi kanannya menganga. Status kini rata TENGAH dengan
                        lebar sendiri, jadi jaraknya seimbang di kedua sisi.
                    --}}
                    <tr class="border-b border-line">
                        <th class="px-5 py-3.5 text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Produk</th>
                        <th class="w-40 px-5 py-3.5 text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Kategori</th>
                        <th class="w-32 px-5 py-3.5 text-right text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Harga jual</th>
                        <th class="w-40 px-5 py-3.5 text-right text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Modal &amp; untung</th>
                        <th class="w-28 px-5 py-3.5 text-right text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Stok</th>
                        <th class="w-28 px-5 py-3.5 text-center text-[0.75rem] font-semibold tracking-wide text-umber uppercase">Status</th>
                        <th class="w-56 px-5 py-3.5"><span class="sr-only">Aksi</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-line-soft">
                    @foreach ($daftar as $produk)
                        <tr class="transition-colors hover:bg-cream/60">
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-3">
                                    {{-- Gambar ditampilkan di daftar juga: owner perlu melihat
                                         produk mana yang belum punya foto tanpa membuka
                                         formulirnya satu per satu. --}}
                                    {{--
                                        asset(), BUKAN Storage::url().

                                        Storage::url() selalu menyusun URL dari APP_URL —
                                        di sini http://localhost — sedangkan aplikasinya
                                        dibuka di 127.0.0.1:8000. Peramban lalu mencari
                                        gambarnya di host yang tidak melayani apa pun dan
                                        gambarnya tidak pernah muncul. asset() mengikuti
                                        host permintaan yang sedang berjalan.
                                    --}}
                                    @if ($this->punyaGambar($produk->gambar_path))
                                        <button
                                            type="button"
                                            @click="zoom = { src: @js(asset('storage/'.$produk->gambar_path)), nama: @js($produk->nama_produk) }"
                                            class="group relative size-11 shrink-0 cursor-zoom-in overflow-hidden rounded-xl"
                                            aria-label="Perbesar gambar {{ $produk->nama_produk }}"
                                        >
                                            <img src="{{ asset('storage/'.$produk->gambar_path) }}"
                                                 alt="" loading="lazy"
                                                 class="size-full object-cover transition-transform duration-200 group-hover:scale-110">
                                            <span class="absolute inset-0 grid place-items-center bg-navy-900/45 opacity-0 transition-opacity group-hover:opacity-100">
                                                <svg viewBox="0 0 20 20" class="size-4 text-white" fill="none" aria-hidden="true">
                                                    <circle cx="9" cy="9" r="5.5" stroke="currentColor" stroke-width="1.7" />
                                                    <path d="M9 6.5v5M6.5 9h5m2 4.5 3 3" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                                                </svg>
                                            </span>
                                        </button>
                                    @else
                                        <span class="grid size-11 shrink-0 place-items-center rounded-xl bg-cream-deep text-[0.6875rem] font-bold text-umber-soft"
                                              aria-hidden="true">
                                            {{ mb_strtoupper(mb_substr($produk->nama_produk, 0, 2)) }}
                                        </span>
                                    @endif
                                    <div class="min-w-0">
                                        <p class="truncate text-[0.9375rem] font-semibold text-ink">{{ $produk->nama_produk }}</p>
                                        <p class="text-[0.75rem] text-umber-soft">per {{ $produk->satuan?->label() ?? '—' }}</p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-5 py-3.5 text-[0.875rem] text-umber">
                                {{ $produk->kategori?->nama ?? '—' }}
                            </td>
                            <td class="tabular px-5 py-3.5 text-right text-[0.9375rem] font-bold text-ink">
                                Rp {{ number_format((float) $produk->harga_default, 0, ',', '.') }}
                            </td>
                            @php($m = $marginProduk($produk))
                            <td class="tabular px-5 py-3.5 text-right text-[0.875rem] text-umber">
                                @if ($m !== null && $m['rupiah'] !== null)
                                    <span class="block">{{ $rp($m['info']['hpp']) }}</span>
                                    <span @class([
                                        'block text-[0.6875rem] font-bold',
                                        'text-merah-tua' => $m['rugi'],
                                        'text-hijau-tua' => ! $m['rugi'],
                                    ])>
                                        {{ $m['rugi'] ? 'rugi ' : '+' }}{{ $rp(abs($m['rupiah'])) }}
                                        @if ($m['persen'] !== null)
                                            ({{ number_format($m['persen'], 1, ',', '.') }}%)
                                        @endif
                                    </span>
                                @else
                                    {{-- "—" WAJIB, bukan sel kosong. Baris keduanya menyebut
                                         SEBABNYA, karena "belum bisa dihitung" tanpa sebab
                                         membuat orang menyimpulkan fiturnya rusak. --}}
                                    <span class="block text-umber-soft">—</span>
                                    <span class="block text-[0.6875rem] text-umber-soft">
                                        @if ($m !== null && ($m['info']['bahanTanpaHarga'] ?? []) !== [])
                                            {{ collect($m['info']['bahanTanpaHarga'])->join(', ') }} belum berharga
                                        @else
                                            modal belum diisi
                                        @endif
                                    </span>
                                @endif
                            </td>
                            {{-- "—" WAJIB, bukan sel kosong: kosong membuat pembacanya
                                 menebak "nol, atau belum diisi?" — dan nol adalah pernyataan
                                 bahwa barangnya habis. --}}
                            @php($statusStok = $produk->statusStokTerjoin())
                            <td class="tabular px-5 py-3.5 text-right text-[0.875rem]">
                                @if ($statusStok === null)
                                    <span class="text-umber-soft">—</span>
                                @else
                                    <span @class([
                                        'font-semibold',
                                        'text-merah-deep' => in_array($statusStok, ['minus', 'habis'], true),
                                        'text-jingga-tua' => $statusStok === 'menipis',
                                        'text-ink' => $statusStok === 'aman',
                                    ])>{{ rtrim(rtrim(number_format((float) $produk->jumlah_saat_ini, 3, ',', '.'), '0'), ',') }}</span>
                                    @if ($statusStok !== 'aman')
                                        <span class="block text-[0.6875rem] text-umber-soft">{{ $statusStok }}</span>
                                    @endif
                                @endif
                            </td>
                            <td class="px-5 py-3.5 text-center">
                                <x-lencana :warna="$produk->is_active ? 'hijau' : 'merah'">
                                    {{ $produk->is_active ? 'Aktif' : 'Nonaktif' }}
                                </x-lencana>
                            </td>
                            <td class="px-5 py-3.5">
                                {{--
                                    Tiga tombol seukuran (36×36) dan sejajar. Bobotnya
                                    dibedakan lewat garis dan warna sentuh, bukan lewat
                                    ukuran — ukuran yang berbeda-beda membuat kolomnya
                                    bergerigi dari baris ke baris.

                                    Kolom ini TIDAK lagi menukar isinya jadi panel
                                    "Ya, hapus / Batal": panel itu menggeser ketiga
                                    tombol setiap kali satu baris ditanyai, jadi baris
                                    di sekitarnya ikut bergerak dan yang ditekan
                                    berikutnya bisa bukan yang dimaksud. Pertanyaannya
                                    sekarang di dialog bersama.
                                --}}
                                <div class="flex items-center justify-end gap-1.5">
                                    <x-aksi warna="utama" label="Ubah {{ $produk->nama_produk }}"
                                            wire:click="ubah('{{ $produk->id }}')">
                                        <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                            <path d="M13 3.5 16.5 7 8 15.5H4.5V12L13 3.5Z" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                        </svg>
                                    </x-aksi>

                                    <x-aksi warna="netral"
                                            label="{{ $produk->is_active ? 'Sembunyikan dari kasir' : 'Tampilkan di kasir' }}"
                                            wire:click="ubahAktif('{{ $produk->id }}')">
                                        @if ($produk->is_active)
                                            <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                                <path d="M3 3l14 14M8.2 8.3a2.5 2.5 0 0 0 3.5 3.5M6.1 6.3C4.4 7.3 3 9 2.5 10c1 2.2 3.9 4.5 7.5 4.5 1.2 0 2.3-.25 3.3-.66m2.2-1.5c.9-.7 1.6-1.5 2-2.34-1-2.2-3.9-4.5-7.5-4.5-.6 0-1.2.06-1.7.18"
                                                      stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                            </svg>
                                        @else
                                            <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                                <path d="M10 5.5c3.6 0 6.5 2.3 7.5 4.5-1 2.2-3.9 4.5-7.5 4.5S3.5 12.2 2.5 10c1-2.2 3.9-4.5 7.5-4.5Z"
                                                      stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                                                <circle cx="10" cy="10" r="2.25" stroke="currentColor" stroke-width="1.5" />
                                            </svg>
                                        @endif
                                    </x-aksi>

                                    {{-- Sama seperti bentuk kartu: konfirmasinya lewat
                                         window.konfirmasiNampan (resources/js/toast.js). --}}
                                    <x-aksi warna="bahaya" label="Hapus {{ $produk->nama_produk }}"
                                            x-on:click="window.konfirmasiNampan({ judul: {{ \Illuminate\Support\Js::from('Hapus '.$produk->nama_produk.'?') }}, pesan: {{ \Illuminate\Support\Js::from($pesanHapusProduk) }}, tombolYa: 'Ya, hapus', tombolBatal: 'Tidak jadi' }).then((ya) => ya && $wire.hapus({{ \Illuminate\Support\Js::from($produk->id) }}))">
                                        <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                            <path d="M4 6h12M8 6V4.5h4V6m-6 0v9.5A1.5 1.5 0 0 0 7.5 17h5a1.5 1.5 0 0 0 1.5-1.5V6M8.5 9v5m3-5v5"
                                                  stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
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

    {{--
        Modal zoom gambar.

        Latarnya bisa ditekan untuk menutup, Esc juga, dan gambarnya memakai
        object-contain dengan batas 85vh — bukan lebar tetap — supaya foto potret
        maupun lanskap sama-sama utuh tanpa terpotong di layar mana pun.
    --}}
    <template x-if="zoom">
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-navy-900/70 p-4 backdrop-blur-sm sm:p-8"
            @click.self="zoom = null"
            role="dialog"
            aria-modal="true"
            :aria-label="'Gambar ' + zoom.nama"
        >
            <div class="flex max-h-full w-full max-w-3xl flex-col overflow-hidden rounded-[20px] bg-white shadow-2xl">
                <div class="flex items-center justify-between gap-3 border-b border-line px-5 py-3.5">
                    <p class="min-w-0 truncate text-[0.9375rem] font-bold text-ink" x-text="zoom.nama"></p>
                    <button
                        type="button"
                        @click="zoom = null"
                        aria-label="Tutup"
                        class="grid size-10 shrink-0 cursor-pointer place-items-center rounded-lg text-umber transition-colors hover:bg-cream"
                    >
                        <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                            <path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" />
                        </svg>
                    </button>
                </div>

                {{-- Latar krem, bukan putih: foto berlatar putih jadi tidak lenyap
                     menyatu dengan wadahnya. --}}
                <div class="grid min-h-0 flex-1 place-items-center bg-cream p-3 sm:p-5">
                    <img :src="zoom.src" :alt="'Gambar ' + zoom.nama"
                         class="max-h-[70vh] w-auto max-w-full rounded-xl object-contain">
                </div>
            </div>
        </div>
    </template>

    {{-- ── Panel formulir ────────────────────────────────────────────────── --}}
    @if ($panel)
        <div class="fixed inset-0 z-40 flex items-end justify-center bg-navy-900/45 p-0 sm:items-center sm:p-4"
             wire:key="panel-produk">
            <div class="kartu max-h-[92vh] w-full max-w-lg overflow-y-auto rounded-b-none sm:rounded-b-[20px] md:max-w-3xl lg:max-w-4xl xl:max-w-6xl">
                <div class="flex items-start justify-between gap-3 border-b border-line px-5 py-3.5">
                    <div>
                        <h2 class="text-[1.0625rem] font-bold text-ink">
                            {{ $produkId ? 'Ubah produk' : 'Produk baru' }}
                        </h2>
                        <p class="mt-0.5 text-[0.8125rem] text-umber">Harga di sini langsung dipakai kasir.</p>
                    </div>
                    <button type="button" wire:click="tutupPanel" aria-label="Tutup"
                            class="grid size-10 shrink-0 cursor-pointer place-items-center rounded-lg text-umber transition hover:bg-cream">
                        <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                            <path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                        </svg>
                    </button>
                </div>

                <form wire:submit="simpan" class="px-5 py-4" enctype="multipart/form-data">
                    {{--
                        Dua kolom di layar lebar, satu kolom di ponsel.

                        Formulir ini punya sembilan medan. Ditumpuk semuanya, ujungnya
                        jatuh di bawah lipatan dan tombol Simpan hanya bisa dicapai dengan
                        menggulir — padahal ruang di sebelah kanan justru kosong. Dilebarkan
                        ke samping, seluruh formulir terlihat sekaligus, dan harga bisa
                        diperiksa sekali lihat sebelum disimpan.

                        Pembagiannya bukan sembarang: kiri untuk yang wajib diisi dan paling
                        sering diubah (nama, harga, satuan), kanan untuk pelengkapnya
                        (gambar, barcode). items-start supaya kolom yang lebih pendek tidak
                        ikut ditarik setinggi kolom sebelahnya.
                    --}}
                    <div class="grid items-start gap-x-6 gap-y-5 md:grid-cols-2 xl:grid-cols-3">
                        <div>
                            <div class="hidden xl:block">
                                <p class="eyebrow text-umber-soft">Barang &amp; harga</p>
                                <div class="mt-1 mb-2.5 h-px bg-line"></div>
                            </div>

                            <div class="space-y-4">
                            <div>
                                <label for="nama" class="block text-[0.8125rem] font-semibold text-ink">
                                    Nama produk <span class="text-merah">*</span>
                                </label>
                                {{-- Fokus awal jatuh ke kolom barcode kalau usahanya memakai barcode
                                     dan produknya baru: di kelontong, memindai barang adalah langkah
                                     pertama. Di luar itu, nama tetap yang pertama. --}}
                                {{-- value= DITULIS SENDIRI. Livewire TIDAK mencetak nilai awal
                                     untuk kolom ber-wire:model, jadi tanpa ini "Ubah produk"
                                     terbuka dengan nama KOSONG walau barangnya jelas punya
                                     nama — lalu menyimpannya ditolak "nama wajib", atau lebih
                                     buruk: pemilik mengetik ulang dan salah eja.

                                     Tidak tertangkap uji Livewire mana pun, karena uji
                                     menyetel properti LANGSUNG dan tidak pernah melewati
                                     rendering. Ketahuan dari melihat potretnya. --}}
                                <input id="nama" type="text" wire:model="nama" value="{{ $nama }}"
                                       @if (! ($this->pakaiBarcode() && ! $produkId)) autofocus @endif
                                       class="mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-4 text-[0.9375rem] text-ink focus:border-terracotta focus:outline-none">
                                @error('nama')
                                    <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="harga" class="block text-[0.8125rem] font-semibold text-ink">
                                        Harga jual <span class="text-merah">*</span>
                                    </label>
                                    {{-- Berformat rupiah seperti medan uang lain di aplikasi ini:
                                         angka tanpa pemisah mudah salah dibaca satu nol, dan di
                                         sini satu nol berarti harga sepuluh kali lipat. --}}
                                    <div class="relative mt-1.5" x-data="{ nilai: @js($harga !== null ? (int) $harga : null) }"
                                         wire:key="harga-{{ $produkId ?? 'baru' }}">
                                        <span class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-sm font-semibold text-umber-soft">Rp</span>
                                        <input id="harga" type="text" inputmode="numeric" placeholder="0"
                                               :value="nilai === null ? '' : new Intl.NumberFormat('id-ID').format(nilai)"
                                               @input="
                                                   const digit = String($event.target.value).replace(/\D/g, '');
                                                   nilai = digit === '' ? null : Number(digit);
                                                   $event.target.value = nilai === null ? '' : new Intl.NumberFormat('id-ID').format(nilai);
                                                   $wire.set('harga', nilai, false);
                                               "
                                               class="tabular h-12 w-full rounded-xl border border-line bg-white pr-4 pl-12 text-right text-[1rem] font-bold text-ink focus:border-terracotta focus:outline-none">
                                    </div>
                                    @error('harga')
                                        <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                                    @enderror

                                    {{--
                                        SARAN, bukan penetapan — dan bentuknya harus mengatakan itu.

                                        Harga adalah keputusan pemilik yang tahu harga warung
                                        sebelah. Aplikasi yang MENIMPA harga dengan hasil
                                        hitungannya akan salah pada barang yang paling menentukan,
                                        dan sesudah itu pemilik berhenti memercayai seluruh
                                        layarnya. Jadi: satu tombol untuk memakainya, tidak
                                        pernah otomatis.

                                        Margin yang ditulis adalah margin NYATA sesudah
                                        pembulatan, bukan targetnya — pemilik yang menghitung
                                        sendiri akan menemukan selisihnya kalau kita membulatkan
                                        angkanya di layar.
                                    --}}
                                    @if ($saranHarga !== null && $saranHarga['hargaBulat'] !== null)
                                        <div class="mt-2 rounded-xl border border-line bg-cream/60 px-3 py-2.5">
                                            <p class="text-[0.8125rem] text-umber">
                                                Saran:
                                                <span class="tabular font-bold text-ink">Rp {{ number_format($saranHarga['hargaBulat'], 0, ',', '.') }}</span>
                                                <span class="text-umber-soft">
                                                    — modal Rp {{ number_format($saranHarga['hpp'], 0, ',', '.') }},
                                                    margin {{ number_format($saranHarga['marginNyata'], 1, ',', '.') }}%
                                                </span>
                                            </p>
                                            <button type="button" wire:click="pakaiSaranHarga"
                                                    class="mt-1.5 h-11 w-full cursor-pointer rounded-lg bg-terracotta px-4 text-[0.875rem] font-bold text-white transition-colors hover:bg-terracotta-deep">
                                                Pakai harga ini
                                            </button>

                                            {{--
                                                Target untung DIUBAH DI SINI, tanpa pindah layar.

                                                Dulu tautan ke layar Biaya operasional, dan pemilik
                                                mengeluhkannya: angka yang dipakai di sini tapi
                                                disetel di sana memaksa orang berpindah tiga layar
                                                untuk mengubah satu angka — lalu berhenti
                                                mengubahnya sama sekali.

                                                Kalimat "untuk semua barang" WAJIB ada. Setelan
                                                global yang bisa diubah dari formulir satu barang
                                                adalah kejutan yang mahal kalau orangnya tidak
                                                diberi tahu — ia akan mengira hanya barang ini
                                                yang berubah.
                                            --}}
                                            <div x-data="{ atur: false }" class="mt-2 border-t border-line pt-2">
                                                <button type="button" x-on:click="atur = ! atur"
                                                        :aria-expanded="atur ? 'true' : 'false'"
                                                        class="cursor-pointer text-[0.8125rem] text-umber underline decoration-line decoration-dotted underline-offset-2">
                                                    Dihitung dari target untung {{ number_format($saranHarga['target'], 0, ',', '.') }}% —
                                                    <span class="font-semibold text-terracotta-deep">ubah</span>
                                                </button>

                                                <div x-show="atur" x-cloak x-collapse class="mt-2">
                                                    <label for="target-untung-produk" class="block text-[0.75rem] font-semibold text-ink">
                                                        Target untung untuk <span class="underline decoration-terracotta/50">semua barang</span>
                                                    </label>
                                                    <div class="mt-1.5 flex items-center gap-2">
                                                        <div class="relative">
                                                            <input id="target-untung-produk" type="text" inputmode="decimal"
                                                                   wire:model="targetMarginBaru" value="{{ $targetMarginBaru }}"
                                                                   class="tabular h-11 w-24 rounded-lg border border-line bg-white pr-8 pl-3 text-right text-[0.9375rem] font-bold text-ink focus:border-terracotta focus:outline-none">
                                                            <span class="pointer-events-none absolute top-1/2 right-3 -translate-y-1/2 text-[0.8125rem] font-semibold text-umber-soft">%</span>
                                                        </div>
                                                        <button type="button" wire:click="ubahTargetMargin"
                                                                class="h-11 cursor-pointer rounded-lg border border-line px-4 text-[0.875rem] font-semibold text-ink transition-colors hover:bg-white">
                                                            Simpan
                                                        </button>
                                                    </div>
                                                    @error('targetMarginBaru')
                                                        <p class="mt-1 text-[0.75rem] text-merah-tua">{{ $message }}</p>
                                                    @enderror
                                                </div>
                                            </div>
                                        </div>
                                    @endif
                                </div>

                                <div>
                                    <label for="harga-beli" class="block text-[0.8125rem] font-semibold text-ink">Harga beli</label>
                                    <div class="relative mt-1.5" x-data="{ nilai: @js($hargaBeli !== null ? (int) $hargaBeli : null) }"
                                         wire:key="beli-{{ $produkId ?? 'baru' }}">
                                        <span class="pointer-events-none absolute top-1/2 left-4 -translate-y-1/2 text-sm font-semibold text-umber-soft">Rp</span>
                                        <input id="harga-beli" type="text" inputmode="numeric" placeholder="0"
                                               :value="nilai === null ? '' : new Intl.NumberFormat('id-ID').format(nilai)"
                                               @input="
                                                   const digit = String($event.target.value).replace(/\D/g, '');
                                                   nilai = digit === '' ? null : Number(digit);
                                                   $event.target.value = nilai === null ? '' : new Intl.NumberFormat('id-ID').format(nilai);
                                                   $wire.set('hargaBeli', nilai, false);"
                                               {{-- @change (saat kotaknya ditinggalkan), BUKAN
                                                    setiap ketukan: sarannya butuh nilai di
                                                    server, tapi menyinkronkan tiap huruf berarti
                                                    satu perjalanan ke server per angka yang
                                                    diketik. Satu kali saat pindah kolom sudah
                                                    cukup, dan di situlah orang berhenti mengetik
                                                    lalu melihat ke bawah. --}}
                                               @change="$wire.set('hargaBeli', nilai)"
                                               "
                                               class="tabular h-12 w-full rounded-xl border border-line bg-white pr-4 pl-12 text-right text-[1rem] font-bold text-ink focus:border-terracotta focus:outline-none">
                                    </div>
                                    <p class="mt-1 text-[0.75rem] text-umber-soft">Untuk menghitung untung. Boleh dikosongkan.</p>
                                </div>
                            </div>

                            <div class="grid gap-3 rounded-xl border border-line p-3.5 sm:grid-cols-2">
                                <label class="flex cursor-pointer items-start gap-3">
                                    <input type="checkbox" wire:model="lacakStok"
                                           class="mt-0.5 size-4 rounded border-line text-terracotta focus:ring-terracotta">
                                    <span>
                                        <span class="block text-[0.875rem] font-semibold text-ink">Lacak stok</span>
                                        <span class="block text-[0.75rem] text-umber">
                                            Mis. es batu, plastik.
                                        </span>
                                    </span>
                                </label>

                                <label class="flex cursor-pointer items-start gap-3">
                                    <input type="checkbox" wire:model="aktif"
                                           class="mt-0.5 size-4 rounded border-line text-terracotta focus:ring-terracotta">
                                    <span>
                                        <span class="block text-[0.875rem] font-semibold text-ink">Tampilkan di kasir</span>
                                        <span class="block text-[0.75rem] text-umber">
                                            Nonaktif: sembunyi, riwayat utuh.
                                        </span>
                                    </span>
                                </label>
                            </div>
                            </div>
                        </div>

                        {{-- Kelompok kedua: penggolongan & saklar. Dipisah supaya di layar
                             sangat lebar formulirnya jadi tiga kolom, bukan dua kolom panjang. --}}
                        <div>
                            <div class="hidden xl:block">
                                <p class="eyebrow text-umber-soft">Penggolongan &amp; stok</p>
                                <div class="mt-1 mb-2.5 h-px bg-line"></div>
                            </div>

                            <div class="space-y-4">
                            <div class="grid gap-4 sm:grid-cols-2">
                                <div>
                                    <label for="kategori-form" class="block text-[0.8125rem] font-semibold text-ink">Kategori</label>
                                    <select id="kategori-form" wire:model="kategoriForm"
                                            class="mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-3 text-[0.9375rem] text-ink focus:border-terracotta focus:outline-none">
                                        <option value="">Tanpa kategori</option>
                                        @foreach ($kategori as $k)
                                            <option value="{{ $k->id }}">{{ $k->nama }}</option>
                                        @endforeach
                                    </select>
                                </div>

                                <div>
                                    <label for="satuan" class="block text-[0.8125rem] font-semibold text-ink">Satuan</label>
                                    <select id="satuan" wire:model="satuan"
                                            class="mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-3 text-[0.9375rem] text-ink focus:border-terracotta focus:outline-none">
                                        @foreach ($satuanTersedia as $s)
                                            <option value="{{ $s->value }}">{{ $s->label() }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>

                            {{--
                                Bagian stok BISA DILIPAT, dan tertutup secara bawaan.

                                Konversi satuan dan ambang minimum hanya dipakai sebagian kecil
                                produk — warung yang jual satuan tunggal tidak pernah
                                menyentuhnya. Dibiarkan terbuka, empat medan ini membuat panel
                                formulir menggulir lagi di layar 768px, dan tombol Simpan
                                kembali di luar jangkauan; itu batas yang sudah dimenangkan
                                dengan susah payah dan tidak boleh hilang untuk medan yang
                                jarang dipakai.

                                Terbuka SENDIRI kalau produknya memang sudah punya konversi
                                atau ambang: mengubah barang yang sudah disetel tidak boleh
                                menyembunyikan setelannya, karena yang tersembunyi akan
                                dianggap tidak ada lalu ditimpa tanpa sadar.
                            --}}
                            <div x-data="{ buka: @js($satuanDasar !== '' || $isiPerSatuan !== null || ($stokMinimum ?? 0) > 0) }"
                                 class="rounded-xl border border-line">
                                <button type="button" @click="buka = ! buka"
                                        class="flex w-full cursor-pointer items-center justify-between gap-3 px-3.5 py-2.5 text-left">
                                    <span>
                                        <span class="block text-[0.8125rem] font-semibold text-ink">Satuan stok &amp; ambang minimum</span>
                                        <span class="block text-[0.75rem] text-umber-soft">
                                            @if ($satuanDasar === '' && ($stokMinimum ?? 0) <= 0)
                                                Belum disetel — stok dicatat dalam satuan jual
                                            @else
                                                @if ($satuanDasar !== '')
                                                    1 {{ $satuan }} = {{ $isiPerSatuan ?: '…' }} {{ $satuanDasar }}
                                                @endif
                                                @if (($stokMinimum ?? 0) > 0)
                                                    · ambang {{ rtrim(rtrim(number_format((float) $stokMinimum, 3, ',', '.'), '0'), ',') }}
                                                @endif
                                            @endif
                                        </span>
                                    </span>
                                    <svg viewBox="0 0 20 20" class="size-4 shrink-0 text-umber-soft transition-transform"
                                         x-bind:class="buka ? 'rotate-180' : ''" fill="none" aria-hidden="true">
                                        <path d="m6 8 4 4 4-4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                                    </svg>
                                </button>

                                <div x-show="buka" x-cloak class="space-y-4 border-t border-line p-3.5">
                                {{--
                                    Konversi satuan: beli dus, jual pcs.

                                    Labelnya menyebut MAKSUDNYA, bukan nama kolom — "isi per
                                    satuan" tidak berarti apa pun bagi pemilik warung. Kalimat di
                                    bawahnya ikut berubah mengikuti isian, jadi orangnya melihat
                                    akibat pilihannya sebelum menyimpan.

                                    Salah mengisi ini berbahaya justru karena tidak terlihat:
                                    kalau satuan jual pcs tapi isinya 12, setiap penjualan 1 pcs
                                    memotong 12 pcs. Tidak ada galat, tidak ada uang yang salah —
                                    hanya stok yang meleleh, dan opname "memperbaikinya" berulang
                                    kali tanpa ada yang tahu sebabnya. Komponennya menolak bentuk
                                    itu; keterangan di sini yang menjelaskan bentuk yang benar.
                                --}}
                                <div class="grid gap-4 sm:grid-cols-2">
                                    <div>
                                        <label for="satuan-dasar" class="block text-[0.8125rem] font-semibold text-ink">
                                            Stok dihitung dalam
                                        </label>
                                        <select id="satuan-dasar" wire:model.live="satuanDasar"
                                                class="mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-3 text-[0.9375rem] text-ink focus:border-terracotta focus:outline-none">
                                            <option value="">Sama dengan satuan jual</option>
                                            @foreach ($satuanTersedia as $sd)
                                                <option value="{{ $sd->value }}">{{ $sd->label() }}</option>
                                            @endforeach
                                        </select>
                                        @error('satuanDasar')
                                            <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                                        @enderror
                                    </div>

                                    <div>
                                        <label for="isi-per-satuan" class="block text-[0.8125rem] font-semibold text-ink">
                                            Isinya berapa
                                        </label>
                                        <input id="isi-per-satuan" type="text" inputmode="decimal" wire:model="isiPerSatuan" value="{{ $isiPerSatuan }}"
                                               placeholder="mis. 12"
                                               class="tabular mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-4 text-[0.9375rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                                        @error('isiPerSatuan')
                                            <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                                        @enderror
                                    </div>

                                    <p class="text-[0.75rem] text-umber sm:col-span-2">
                                        @if ($satuanDasar === '')
                                            Stok dicatat dalam satuan jual.
                                        @else
                                            1 {{ $satuan }} = {{ $isiPerSatuan ?: '…' }} {{ $satuanDasar }} · stok dicatat dalam {{ $satuanDasar }}.
                                        @endif
                                    </p>
                                </div>

                                {{-- Ambang minimum adalah angka PER OUTLET (kolomnya ada di tabel
                                     stocks, bukan products). Nama outletnya wajib terlihat: tanpa
                                     itu ambang bisa tersimpan ke outlet yang tidak disadari
                                     pengetiknya, lalu owner menyimpulkan fiturnya tidak jalan. --}}
                                <div class="grid gap-4 @if ($outletTersedia !== []) sm:grid-cols-2 @endif">
                                    @if ($outletTersedia !== [])
                                        <div>
                                            <label for="outlet-stok" class="block text-[0.8125rem] font-semibold text-ink">Outlet</label>
                                            <select id="outlet-stok" wire:model.live="outletStok"
                                                    class="mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-3 text-[0.9375rem] text-ink focus:border-terracotta focus:outline-none">
                                                @foreach ($outletTersedia as $o)
                                                    <option value="{{ $o['id'] }}">{{ $o['nama'] }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                    @endif

                                    <div>
                                        <label for="stok-minimum" class="block text-[0.8125rem] font-semibold text-ink">
                                            Ambang minimum
                                        </label>
                                        <input id="stok-minimum" type="text" inputmode="decimal" wire:model="stokMinimum" value="{{ $stokMinimum }}"
                                               placeholder="0"
                                               class="tabular mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-4 text-[0.9375rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                                        <p class="mt-1 text-[0.75rem] text-umber-soft">
                                            Di bawah angka ini, barangnya masuk daftar harus belanja.
                                        </p>
                                        @error('stokMinimum')
                                            <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                                        @enderror
                                    </div>
                                </div>
                                </div>
                            </div>

                            </div>
                        </div>

                        {{--
                            Kelompok ketiga ditempatkan EKSPLISIT, tidak dibiarkan
                            mengalir sendiri.

                            Di layar sedang (dua kolom), tiga kelompok yang mengalir bebas
                            akan menaruh kelompok ini di baris kedua — dan tinggi panelnya
                            justru melonjak, persis yang mau dihindari. Dengan ditempel di
                            kolom terakhir setinggi dua baris, dua kelompok pertama menumpuk
                            di kolom pertama dan tingginya tetap pendek.
                        --}}
                        <div class="md:col-start-2 md:row-span-2 md:row-start-1 xl:col-start-3 xl:row-span-1">
                            <div class="hidden xl:block">
                                <p class="eyebrow text-umber-soft"><x-jelaskan kunci="kode-barang" sebagai="Pengenal barang" /></p>
                                <div class="mt-1 mb-2.5 h-px bg-line"></div>
                            </div>

                            <div class="space-y-4">
                            {{-- ── Gambar produk ───────────────────────────────────────
                                 Tampil di grid kasir. Tanpa gambar, kasir memakai tile inisial
                                 berwarna — bukan kotak kosong — jadi mengunggah bersifat pilihan,
                                 bukan keharusan. --}}
                            <div>
                                <span class="block text-[0.8125rem] font-semibold text-ink">Gambar produk</span>

                                <div class="mt-1.5 flex items-start gap-3.5 rounded-xl border border-line p-3.5">
                                    <div class="shrink-0">
                                        {{-- isPreviewable() WAJIB diperiksa: temporaryUrl() melempar
                                             exception untuk berkas yang bukan gambar, jadi memilih
                                             PDF akan merusak halaman alih-alih memunculkan pesan
                                             validasinya. --}}
                                        @if ($gambar && $gambar->isPreviewable())
                                            <img src="{{ $gambar->temporaryUrl() }}" alt="Pratinjau gambar"
                                                 class="size-16 rounded-xl object-cover ring-2 ring-terracotta">
                                        @elseif ($gambar)
                                            <span class="grid size-16 place-items-center rounded-xl bg-merah/10 px-2 text-center text-[0.6875rem] font-semibold text-merah-tua">
                                                Bukan gambar
                                            </span>
                                        @elseif ($gambarLama && ! $hapusGambar && $this->punyaGambar($gambarLama))
                                            <img src="{{ asset('storage/'.$gambarLama) }}" alt="Gambar sekarang"
                                                 class="size-16 rounded-xl object-cover">
                                        @else
                                            <span class="grid size-16 place-items-center rounded-xl bg-cream text-[0.75rem] font-semibold text-umber-soft">
                                                Kosong
                                            </span>
                                        @endif
                                    </div>

                                    <div class="min-w-0 flex-1">
                                        <label
                                            for="gambar"
                                            class="inline-flex h-10 cursor-pointer items-center gap-2 rounded-lg border border-line bg-white px-3.5 text-[0.8125rem] font-semibold text-ink transition-colors hover:bg-cream-deep"
                                        >
                                            <svg viewBox="0 0 20 20" class="size-4 text-umber-soft" fill="none" aria-hidden="true">
                                                <path d="M10 13V4m0 0L6.5 7.5M10 4l3.5 3.5M3.5 13v2A1.5 1.5 0 0 0 5 16.5h10a1.5 1.5 0 0 0 1.5-1.5v-2"
                                                      stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                                            </svg>
                                            {{ ($gambarLama && ! $hapusGambar && $this->punyaGambar($gambarLama)) || $gambar ? 'Ganti gambar' : 'Pilih gambar' }}
                                        </label>

                                        {{--
                                            `image/*`, BUKAN daftar jenis satu per satu.

                                            Daftar spesifik membuat banyak peramban Android tidak
                                            menawarkan kamera sama sekali: aplikasi kameranya
                                            mendaftar sebagai penghasil `image/*` dan tersaring
                                            keluar oleh daftar itu. Pemilik warung memotret
                                            barangnya langsung di rak, jadi kehilangan kamera di
                                            sini berarti kehilangan satu-satunya cara ia memasang
                                            foto produk.

                                            `capture` TETAP tidak dipasang: ia memaksa kamera tapi
                                            menghapus pilihan galeri, sehingga foto yang sudah
                                            diambil kemarin tidak bisa dipilih lagi.

                                            Melonggarkan `accept` berarti HEIC dari iPhone bisa
                                            terpilih; tumpukan ini tidak bisa membacanya (GD tanpa
                                            libheif, tanpa Imagick — terukur), jadi validatornya
                                            tetap menolak dan pesannya menyebut jalan keluarnya.
                                        --}}
                                        <input id="gambar" type="file" wire:model="gambar"
                                               accept="image/*" class="sr-only">

                                        @if (($gambarLama && ! $hapusGambar && $this->punyaGambar($gambarLama)) || $gambar)
                                            <button type="button" wire:click="tandaiHapusGambar"
                                                    class="ml-2 h-10 cursor-pointer rounded-lg px-3 text-[0.8125rem] font-semibold text-merah-tua transition-colors hover:bg-merah/10">
                                                Hapus
                                            </button>
                                        @endif

                                        <p class="mt-2 text-[0.75rem] text-umber">
                                            JPG, PNG, atau WEBP, maksimal 2 MB. Tidak dikecilkan otomatis —
                                            berkas besar lambat dimuat di tablet kasir.
                                        </p>

                                        <p wire:loading wire:target="gambar" class="mt-1 text-[0.75rem] font-semibold text-terracotta">
                                            Mengunggah…
                                        </p>

                                        @error('gambar')
                                            <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                                        @enderror
                                    </div>
                                </div>
                            </div>

                            @if ($this->pakaiBarcode())
                                {{--
                                    Hanya muncul untuk kelontong & usaha campuran. Rumah makan
                                    tidak menjual barang berbarcode pabrik, jadi kolom ini di sana
                                    hanya menambah medan kosong yang harus dilewati tiap kali
                                    menambah menu. Aturannya ada di BusinessType::pakaiBarcode().
                                --}}
                                {{--
                                    @keydown.window: pemindai USB "mengetik" angkanya ke halaman.
                                    Kalau tidak ditangkap di tingkat jendela, memindai barang
                                    sebelum kolomnya diklik tidak menghasilkan apa pun — dan
                                    orangnya tidak punya cara menduga bahwa yang kurang hanya satu
                                    klik. Penjaganya ada di pemindai.js: ketikan hanya diambil
                                    saat fokus tidak berada di kolom mana pun.
                                --}}
                                <div x-data="pemindaiBarcode('lanjut')"
                                     @barcode-terpindai="$wire.set('barcode', $event.detail.nilai, false); $refs.medanBarcode.value = $event.detail.nilai"
                                     @keydown.window="tangkap($event)">
                                    <div class="grid gap-4 sm:grid-cols-2">
                                        <div>
                                            <label for="barcode" class="block text-[0.8125rem] font-semibold text-ink">Barcode</label>

                                            {{--
                                                Enter DICEGAH menyimpan formulir.

                                                Pemindai USB bekerja seperti papan ketik: ia mengetik
                                                angkanya lalu menekan Enter. Kalau Enter langsung
                                                menyimpan, produk tersimpan setengah jadi — hanya
                                                berisi barcode, tanpa nama dan harga. Fokus dipindahkan
                                                ke kolom nama, yang justru langkah berikutnya.

                                                x-init memberi fokus awal ke kolom ini pada produk
                                                BARU: bagi kelontong, memindai barang memang langkah
                                                pertama, dan namanya sering baru diketik sesudahnya.
                                            --}}
                                            <input
                                                id="barcode"
                                                x-ref="medanBarcode"
                                                type="text"
                                                inputmode="numeric"
                                                autocomplete="off"
                                                wire:model="barcode"
                                                placeholder="Pindai atau ketik"
                                                @keydown.enter.prevent="document.getElementById('nama')?.focus()"
                                                @if (! $produkId) x-init="$nextTick(() => $el.focus())" @endif
                                                class="tabular mt-1.5 h-12 w-full min-w-0 rounded-xl border border-line bg-white px-4 text-[0.9375rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none"
                                            >

                                            @error('barcode')
                                                <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div>
                                            <label for="sku" class="block text-[0.8125rem] font-semibold text-ink">Kode internal (SKU)</label>
                                            {{-- Placeholder menyebut apa yang AKAN terjadi, bukan
                                                 "boleh dikosongkan": kolomnya terisi sendiri saat
                                                 disimpan, dan owner berhak tahu itu sebelum menekan
                                                 Simpan — bukan menemukannya sebagai kejutan di
                                                 daftar. Bentuk kodenya dicontohkan di keterangan. --}}
                                            <input id="sku" type="text" wire:model="sku" autocomplete="off"
                                                   placeholder="{{ $produkId ? 'Boleh dikosongkan' : 'Terisi otomatis' }}"
                                                   class="mt-1.5 h-12 w-full rounded-xl border border-line bg-white px-4 text-[0.9375rem] text-ink placeholder:text-umber-soft/70 focus:border-terracotta focus:outline-none">
                                            @if (! $produkId)
                                                <p class="mt-1 text-[0.75rem] text-umber-soft">
                                                    Otomatis, mis. NAS-0001.
                                                </p>
                                            @endif

                                            @error('sku')
                                                <p class="mt-1.5 text-[0.8125rem] text-merah-deep">{{ $message }}</p>
                                            @enderror
                                        </div>
                                    </div>

                                        {{--
                                            Dua cadangan, dan keduanya perlu ada.

                                            "Pindai kamera" hilang sendiri di peramban yang tidak
                                            diizinkan memakai kamera (mis. aplikasi dibuka lewat
                                            alamat LAN, bukan HTTPS). "Dari foto" TIDAK pernah
                                            hilang: ia lewat kamera bawaan sistem, yang tetap boleh
                                            dipakai walau aliran kamera langsung ditolak — itulah
                                            satu-satunya jalur kamera yang tersisa di iPhone dan di
                                            peramban dalam aplikasi.
                                        --}}
                                        <div class="mt-2 flex gap-2">
                                            <button
                                                type="button"
                                                @click="buka()"
                                                x-show="bisaKamera"
                                                x-cloak
                                                class="inline-flex h-11 flex-1 cursor-pointer items-center justify-center gap-2 rounded-xl bg-ink px-3 text-[0.8125rem] font-bold text-white transition hover:brightness-125 sm:w-36 sm:flex-none sm:px-4"
                                            >
                                                <svg viewBox="0 0 20 20" class="size-4 shrink-0" fill="none" aria-hidden="true">
                                                    <path d="M3 7V4.5h2.5M17 7V4.5h-2.5M3 13v2.5h2.5M17 13v2.5h-2.5M6 7v6m2.5-6v6M11 7v6m2.5-6v6"
                                                          stroke="currentColor" stroke-width="1.5" stroke-linecap="round" />
                                                </svg>
                                                Kamera
                                            </button>

                                            <label
                                                class="inline-flex h-11 flex-1 cursor-pointer items-center justify-center gap-2 rounded-xl border border-line bg-white px-3 text-[0.8125rem] font-bold text-ink transition hover:bg-cream has-[:focus-visible]:border-terracotta sm:w-36 sm:flex-none sm:px-4"
                                            >
                                                <svg x-show="! membacaFoto" viewBox="0 0 20 20" class="size-4 shrink-0" fill="none" aria-hidden="true">
                                                    <rect x="2.5" y="5" width="15" height="11" rx="2.5" stroke="currentColor" stroke-width="1.5" />
                                                    <circle cx="10" cy="10.5" r="2.75" stroke="currentColor" stroke-width="1.5" />
                                                    <path d="M7 5l1-1.5h4L13 5" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" />
                                                </svg>
                                                <span x-show="membacaFoto" x-cloak
                                                      class="size-4 shrink-0 animate-spin rounded-full border-2 border-ink/20 border-t-ink"></span>
                                                <span x-text="membacaFoto ? 'Membaca…' : 'Dari foto'">Dari foto</span>
                                                <input type="file" accept="image/*" class="sr-only"
                                                       @change="dariBerkas($event)"
                                                       x-bind:disabled="membacaFoto">
                                            </label>
                                        </div>

                                    {{-- Keterangan & pesan galat memakai lebar penuh kelompoknya, bukan
                                         setengah kolom: di dalam sel, kalimat sepanjang ini pecah jadi
                                         lima baris sempit dan berhenti terbaca. --}}
                                    <p class="mt-3 text-[0.75rem] text-umber">
                                        Pemindai USB: langsung pindai, angkanya masuk sendiri. Tanpa pemindai:
                                        kamera, foto barcodenya, atau ketik angkanya.
                                    </p>

                                    <p x-show="galat" x-cloak class="mt-2 text-[0.8125rem] text-jingga-tua" x-text="galat"></p>

                                    {{--
                                        'lanjut': panel TIDAK menutup setelah satu kode terbaca.

                                        Pembacaan pertama sering salah — satu digit keliru karena labelnya
                                        tertekuk atau memantul. Panel yang menutup sendiri memaksa orangnya
                                        membuka kamera lagi hanya untuk mengulang, padahal mengulang itu
                                        justru hal yang paling sering dilakukan. Pindaian berikutnya menimpa
                                        kolomnya, jadi yang terakhir terbaca itulah yang dipakai.

                                        Aliran kamera dihentikan saat ditutup — kamera yang menyala terus
                                        menguras baterai tablet.
                                    --}}
                                    <x-panel-pindai>
                                        {{-- Umpan WAJIB di mode berkelanjutan: toast per pindaian dimatikan di
                                             mode ini, jadi tanpa baris ini tidak ada kabar sama sekali bahwa
                                             kodenya sudah masuk. --}}
                                        <x-slot:umpan>
                                            <template x-if="terakhir">
                                                <div class="border-t border-line px-4 py-3">
                                                    <div class="flex items-center gap-3 rounded-xl bg-hijau/10 px-3 py-2.5">
                                                        <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-hijau text-white">
                                                            <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                                                                <path d="m5 10.5 3.5 3.5L15 7" stroke="currentColor" stroke-width="2"
                                                                      stroke-linecap="round" stroke-linejoin="round" />
                                                            </svg>
                                                        </span>
                                                        <span class="min-w-0 flex-1">
                                                            <span class="block text-[0.875rem] font-bold text-ink">Kolom barcode terisi</span>
                                                            <span class="tabular block truncate text-[0.75rem] text-umber" x-text="terakhir"></span>
                                                        </span>
                                                    </div>
                                                    <p class="mt-2 text-[0.75rem] text-umber-soft">
                                                        Salah baca? Pindai lagi — kode terakhir yang dipakai.
                                                    </p>
                                                </div>
                                            </template>
                                        </x-slot:umpan>
                                    </x-panel-pindai>
                                </div>
                            @endif
                            </div>
                        </div>
                    </div>

                    <div class="mt-5 flex flex-col gap-2 border-t border-line pt-4 sm:flex-row sm:justify-end">
                        <button type="button" wire:click="tutupPanel"
                                class="h-12 cursor-pointer rounded-xl border border-line px-5 text-[0.9375rem] font-semibold text-ink transition-colors hover:bg-cream sm:order-1">
                            Batal
                        </button>
                        <button type="submit" wire:loading.attr="disabled"
                                class="h-12 cursor-pointer rounded-xl bg-terracotta px-6 text-[0.9375rem] font-bold text-white transition-colors hover:bg-terracotta-deep disabled:opacity-60 sm:order-2">
                            <span wire:loading.remove wire:target="simpan">Simpan</span>
                            <span wire:loading wire:target="simpan">Menyimpan…</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>

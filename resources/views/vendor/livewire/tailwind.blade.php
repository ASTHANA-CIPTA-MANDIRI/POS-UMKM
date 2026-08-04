{{--
    Navigasi halaman Nampan — SATU berkas untuk seluruh aplikasi.

    Ini menimpa `livewire::tailwind`, jadi setiap `->links()` di aplikasi ini (produk, stok,
    lembar opname, riwayat kartu stok, dan daftar apa pun yang datang kemudian) langsung
    memakainya. Sengaja BUKAN potongan Blade yang disalin ke tiap layar: navigasi halaman
    yang berbeda-beda bentuknya di tiap daftar membuat orang kehilangan pegangan, dan
    salinan per layar berarti perbaikan berikutnya harus dikerjakan lima kali.

    Yang diubah dari tampilan bawaan Livewire hanyalah PENAMPILANNYA — kelas Tailwind bawaan
    (abu-abu, sudut kecil, mode gelap yang tidak dipakai aplikasi ini) diganti palet Nampan.
    Yang TIDAK disentuh: wire:click, nama halaman, atribut dusk, wire:key, dan aria — semua
    itu perilaku Livewire, dan mengarangnya ulang berarti mematikan tombol halamannya.

    Jumlah baris per halaman tidak ada di sini. Itu keputusan komponen
    (config('nampan.per_halaman')), dan berkas ini tidak boleh punya angka sendiri.
--}}
@php
    if (! isset($scrollTo)) {
        $scrollTo = 'body';
    }

    $scrollIntoViewJsSnippet = ($scrollTo !== false)
        ? <<<JS
           (\$el.closest('{$scrollTo}') || document.querySelector('{$scrollTo}')).scrollIntoView({ behavior: 'smooth', block: 'start' })
        JS
        : '';

    $namaHalaman = $paginator->getPageName();
    $dusk = $namaHalaman === 'page' ? '' : '.'.$namaHalaman;

    // Kelas dipusatkan di sini supaya tombol panah dan tombol angka benar-benar seukuran.
    // Tinggi 40px = ukuran tombol ikon sentuh yang sudah dipakai di seluruh aplikasi.
    $petak = 'grid h-10 min-w-10 place-items-center rounded-xl px-3 text-[0.8125rem] font-semibold transition-colors';
    $bisa = 'cursor-pointer border border-line bg-white text-ink hover:border-terracotta hover:text-terracotta';
    $mati = 'cursor-default border border-line-soft bg-cream text-umber-soft/70';
@endphp

<div>
    @if ($paginator->hasPages())
        <nav role="navigation" aria-label="Navigasi halaman"
             class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
            {{-- Keterangan jangkauan, bukan hanya nomor halaman: "11–20 dari 37" menjawab
                 "masih berapa lagi", dan itu pertanyaan yang sebenarnya dibawa orang ke
                 baris ini. --}}
            <p class="tabular text-[0.8125rem] text-umber">
                Menampilkan
                <span class="font-semibold text-ink">{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }}</span>
                dari <span class="font-semibold text-ink">{{ $paginator->total() }}</span>
                · halaman {{ $paginator->currentPage() }}/{{ $paginator->lastPage() }}
            </p>

            <div class="flex items-center gap-1.5">
                {{-- Sebelumnya --}}
                @if ($paginator->onFirstPage())
                    <span aria-disabled="true" aria-label="Halaman sebelumnya" class="{{ $petak }} {{ $mati }}">
                        <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                            <path d="M11.5 5.5 7 10l4.5 4.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </span>
                @else
                    <button type="button" wire:click="previousPage('{{ $namaHalaman }}')"
                            x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled"
                            dusk="previousPage{{ $dusk }}.after" aria-label="Halaman sebelumnya"
                            class="{{ $petak }} {{ $bisa }}">
                        <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                            <path d="M11.5 5.5 7 10l4.5 4.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>
                @endif

                {{-- Nomor halaman disembunyikan di ponsel: sebelas tombol angka di layar 390px
                     memaksa gulir mendatar, dan yang dipakai di situ hanya maju/mundur —
                     jangkauannya sudah tertulis di kalimat sebelah. --}}
                <div class="hidden items-center gap-1.5 sm:flex">
                    @foreach ($elements as $element)
                        @if (is_string($element))
                            <span aria-hidden="true" class="px-1 text-[0.8125rem] font-semibold text-umber-soft">{{ $element }}</span>
                        @endif

                        @if (is_array($element))
                            @foreach ($element as $page => $url)
                                <span wire:key="paginator-{{ $namaHalaman }}-page{{ $page }}">
                                    @if ($page == $paginator->currentPage())
                                        <span aria-current="page"
                                              class="{{ $petak }} tabular bg-terracotta text-white">{{ $page }}</span>
                                    @else
                                        <button type="button" wire:click="gotoPage({{ $page }}, '{{ $namaHalaman }}')"
                                                x-on:click="{{ $scrollIntoViewJsSnippet }}"
                                                aria-label="Ke halaman {{ $page }}"
                                                class="{{ $petak }} {{ $bisa }} tabular">{{ $page }}</button>
                                    @endif
                                </span>
                            @endforeach
                        @endif
                    @endforeach
                </div>

                {{-- Berikutnya --}}
                @if ($paginator->hasMorePages())
                    <button type="button" wire:click="nextPage('{{ $namaHalaman }}')"
                            x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled"
                            dusk="nextPage{{ $dusk }}.after" aria-label="Halaman berikutnya"
                            class="{{ $petak }} {{ $bisa }}">
                        <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                            <path d="M8.5 5.5 13 10l-4.5 4.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>
                @else
                    <span aria-disabled="true" aria-label="Halaman berikutnya" class="{{ $petak }} {{ $mati }}">
                        <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                            <path d="M8.5 5.5 13 10l-4.5 4.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </span>
                @endif
            </div>
        </nav>
    @endif
</div>

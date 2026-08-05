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

    /*
     * Bentuk maju/mundur di PONSEL berbeda, dan bukan demi variasi.
     *
     * Sebagai dua kotak ikon 40px yang menggantung di kiri, baris ini menyisakan bidang
     * kosong selebar dua pertiga layar dan tidak terbaca sebagai satu kesatuan dengan
     * kalimat di atasnya. Di ponsel keduanya dibuat berlabel dan berbagi lebar penuh:
     * sasaran sentuhnya besar, maksudnya terbaca tanpa menebak arti panah, dan barisnya
     * penuh. Di ≥sm kembali jadi kotak ikon karena di sana ada tombol angka di antaranya.
     *
     * `max-sm:flex` WAJIB dan bukan sekadar penegas: $petak memakai `grid place-items-center`,
     * dan dua anak di dalam grid tanpa kolom eksplisit tersusun KE BAWAH — ikon di atas
     * labelnya, bukan di sampingnya. Hanya kelihatan setelah tombolnya berlabel; selama
     * isinya cuma ikon, grid dan flex tampak sama.
     */
    $petakArah = $petak.' max-sm:flex max-sm:w-full max-sm:items-center max-sm:justify-center max-sm:gap-2 max-sm:h-11';
    $bisa = 'cursor-pointer border border-line bg-white text-ink hover:border-terracotta hover:text-terracotta';
    $mati = 'cursor-default border border-line-soft bg-cream text-umber-soft/70';
@endphp

<div>
    @if ($paginator->hasPages())
        <nav role="navigation" aria-label="Navigasi halaman"
             class="flex flex-col gap-2.5 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between sm:gap-3">
            {{-- Keterangan jangkauan, bukan hanya nomor halaman: "11–20 dari 37" menjawab
                 "masih berapa lagi", dan itu pertanyaan yang sebenarnya dibawa orang ke
                 baris ini. --}}
            <p class="tabular text-center text-[0.8125rem] text-umber sm:text-left">
                Menampilkan
                <span class="font-semibold text-ink">{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }}</span>
                dari <span class="font-semibold text-ink">{{ $paginator->total() }}</span>
                · halaman {{ $paginator->currentPage() }}/{{ $paginator->lastPage() }}
            </p>

            <div class="grid grid-cols-2 gap-2 max-sm:w-full sm:flex sm:items-center sm:gap-1.5">
                {{-- Sebelumnya --}}
                @if ($paginator->onFirstPage())
                    <span aria-disabled="true" aria-label="Halaman sebelumnya" class="{{ $petakArah }} {{ $mati }}">
                        <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                            <path d="M11.5 5.5 7 10l4.5 4.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <span class="sm:hidden">Sebelumnya</span>
                    </span>
                @else
                    <button type="button" wire:click="previousPage('{{ $namaHalaman }}')"
                            x-on:click="{{ $scrollIntoViewJsSnippet }}" wire:loading.attr="disabled"
                            dusk="previousPage{{ $dusk }}.after" aria-label="Halaman sebelumnya"
                            class="{{ $petakArah }} {{ $bisa }}">
                        <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                            <path d="M11.5 5.5 7 10l4.5 4.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                        <span class="sm:hidden">Sebelumnya</span>
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
                            class="{{ $petakArah }} {{ $bisa }}">
                        {{-- Label SEBELUM ikon: arah "maju" dibaca kiri→kanan, jadi panahnya
                             harus berada di ujung kanan tombol. --}}
                        <span class="sm:hidden">Berikutnya</span>
                        <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                            <path d="M8.5 5.5 13 10l-4.5 4.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>
                @else
                    <span aria-disabled="true" aria-label="Halaman berikutnya" class="{{ $petakArah }} {{ $mati }}">
                        {{-- Label SEBELUM ikon: arah "maju" dibaca kiri→kanan, jadi panahnya
                             harus berada di ujung kanan tombol. --}}
                        <span class="sm:hidden">Berikutnya</span>
                        <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                            <path d="M8.5 5.5 13 10l-4.5 4.5" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </span>
                @endif
            </div>
        </nav>
    @endif
</div>

@php
    $user = auth()->user();

    /*
     * Ikon disimpan sebagai path SVG 24×24 stroke agar konsisten satu keluarga
     * (tebal garis 1.6, ujung membulat). Horizon memberi ikon pada SETIAP butir
     * menu — teks saja membuat sidebar terasa datar dan sulit dipindai.
     */
    $ikon = [
        'grid' => 'M4 4h6v6H4V4Zm10 0h6v6h-6V4ZM4 14h6v6H4v-6Zm10 0h6v6h-6v-6Z',
        'kasir' => 'M4 7h16v12H4V7Zm0 4h16M8 15h3',
        'bill' => 'M7 4h10v16l-3-2-2 2-2-2-3 2V4Zm3 5h4m-4 4h4',
        'kalkulator' => 'M6 3h12v18H6V3Zm2 4h8M8 11h2m3 0h3M8 15h2m3 0h3',
        'kotak' => 'M12 3 4 7v10l8 4 8-4V7l-8-4Zm0 0v18M4 7l8 4 8-4',
        'daun' => 'M5 19c0-8 6-14 14-14 0 8-6 14-14 14Zm0 0 7-7',
        'lapis' => 'M4 8.5 12 4l8 4.5-8 4.5-8-4.5Zm0 4.5 8 4.5 8-4.5',
        'truk' => 'M3 7h10v8H3V7Zm10 3h4l3 3v2h-7v-5ZM6.5 18a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Zm10 0a1.5 1.5 0 1 0 0-3 1.5 1.5 0 0 0 0 3Z',
        'orang' => 'M12 11a3.5 3.5 0 1 0 0-7 3.5 3.5 0 0 0 0 7Zm-7 9c0-3.3 3.1-6 7-6s7 2.7 7 6',
        'buku' => 'M5 4h9a3 3 0 0 1 3 3v13H8a3 3 0 0 1-3-3V4Zm3 4h6m-6 4h6',
        'toko' => 'M4 9h16v11H4V9Zm0 0 2-5h12l2 5M10 20v-6h4v6',
        'grafik' => 'M4 20V6m0 14h16M8 16V11m4 5V8m4 8v-4',
        'kartu' => 'M3 8h18v10H3V8Zm0 3h18M6.5 15H10',
        'perangkat' => 'M8 3h8v18H8V3Zm3 15h2',
        'catatan' => 'M5 4h14v16H5V4Zm3 4h8M8 12h8M8 16h5',
    ];

    /*
     * Struktur navigasi mengikuti pembagian modul di dokumen bisnis. Butir yang
     * belum dibangun sengaja diberi rute NULL dan dirender sebagai teks bertanda
     * "segera" — bukan tautan. Tautan ke route yang belum ada akan melempar error,
     * dan menyembunyikannya sama sekali membuat peta fitur tidak terbaca.
     */
    $menu = $user->isPlatformLevel()
        ? [
            'Platform' => [
                ['Dasbor', 'admin.dasbor', 'grid'],
                ['Merchant', null, 'toko'],
                ['Paket & tagihan', null, 'kartu'],
                ['Aset perangkat', null, 'perangkat'],
                ['Log audit', null, 'catatan'],
            ],
        ]
        : [
            'Operasional' => [
                ['Dasbor', 'owner.dasbor', 'grid'],
                ['Kasir', null, 'kasir'],
                ['Bill terbuka', null, 'bill'],
                ['Tutup kasir', null, 'kalkulator'],
            ],
            'Katalog & stok' => [
                ['Produk', 'owner.produk', 'kotak'],
                ['Bahan baku & resep', null, 'daun'],
                ['Stok & hitung stok', 'owner.stok', 'lapis'],
                ['Pembelian', 'owner.pembelian', 'truk'],
            ],
            'Pelanggan' => [
                ['Pelanggan', null, 'orang'],
                ['Kasbon', null, 'buku'],
            ],
            'Kelola' => [
                ['Karyawan', null, 'orang'],
                ['Outlet & perangkat', null, 'toko'],
                ['Laporan', 'owner.laporan', 'grafik'],
                ['Langganan', 'owner.langganan', 'kartu'],
            ],
        ];
@endphp

{{-- Sidebar Horizon: putih, lebar 300px, brand di atas dengan garis pemisah,
     dan penanda aktif berupa batang di tepi kanan. --}}
<aside
    :class="menuTerbuka ? 'translate-x-0' : '-translate-x-full xl:translate-x-0'"
    class="app-font fixed inset-y-0 left-0 z-40 flex w-[300px] shrink-0 flex-col bg-paper transition-transform duration-300 xl:sticky xl:top-0 xl:h-dvh xl:translate-x-0"
>
    {{-- Wordmark persis pola template: Poppins 26px, huruf besar, kata kedua
         berbobot medium, rata kiri dengan jarak lega dari tepi. --}}
    <div class="relative flex items-center px-8 pt-[3.125rem] pb-0">
        <a href="{{ route($user->rutaBeranda()) }}" wire:navigate class="flex items-center gap-2.5">
            <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-terracotta to-terracotta-deep">
                <svg viewBox="0 0 24 24" class="size-4.5" fill="none" aria-hidden="true">
                    <path d="M4 8.4 12 4l8 4.4-8 4.4-8-4.4Z" fill="#FFFFFF" fill-opacity="0.95" />
                    <path d="M4 12.6 12 17l8-4.4" stroke="#FFFFFF" stroke-opacity="0.6" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </span>
            <span class="font-brand text-[1.375rem] leading-none font-bold tracking-tight text-ink uppercase whitespace-nowrap">
                Nampan<span class="font-medium"> {{ $user->isPlatformLevel() ? 'Platform' : 'POS' }}</span>
            </span>
        </a>

        <button
            type="button"
            @click="menuTerbuka = false"
            aria-label="Tutup menu"
            class="absolute top-[3.125rem] right-5 grid size-9 cursor-pointer place-items-center rounded-lg text-umber transition-colors hover:bg-cream xl:hidden"
        >
            <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                <path d="M5 5l10 10M15 5L5 15" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
            </svg>
        </button>
    </div>

    {{-- Pemisah melebar penuh, berjarak jauh dari wordmark — di template
         mt-[58px] mb-7, dan jarak itulah yang membuat sidebarnya terasa lapang. --}}
    <div class="mt-[3.625rem] mb-7 h-px bg-line-soft"></div>

    <nav aria-label="Navigasi aplikasi" class="flex-1 overflow-y-auto pb-6">
        @foreach ($menu as $grup => $butir)
            <p class="px-8 pt-3 pb-2 font-mono text-[0.5625rem] tracking-[0.14em] text-umber-soft uppercase">{{ $grup }}</p>

            <ul>
                @foreach ($butir as [$label, $rute, $namaIkon])
                    {{-- Sub-rute ikut menyalakan induknya: `owner.stok.opname` harus membuat
                         "Stok & opname" tetap aktif. Tanpa pola `.*`, membuka lembar opname
                         membuat SELURUH menu mati — orang kehilangan petunjuk sedang berada
                         di mana, dan itu paling terasa justru di halaman kerja panjang yang
                         butuh 12 kali pindah halaman. Titik di depan `*` disengaja supaya
                         nama rute yang cuma berawalan sama (mis. `owner.stok-lain`) tidak
                         ikut tersulut — belum ada menu seperti itu, jadi bagian ini
                         kehati-hatian yang belum berpenjaga uji. --}}
                    @php $aktif = $rute !== null && request()->routeIs($rute, $rute.'.*'); @endphp
                    <li class="relative">
                        @if ($rute === null)
                            <span class="flex min-h-11 items-center gap-4 px-8 text-[0.875rem] text-umber-soft">
                                <svg viewBox="0 0 24 24" class="size-5 shrink-0 opacity-60" fill="none" aria-hidden="true">
                                    <path d="{{ $ikon[$namaIkon] }}" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                <span class="flex-1">{{ $label }}</span>
                                <span class="rounded-full bg-cream px-1.5 py-0.5 font-mono text-[0.5rem] tracking-wider uppercase">segera</span>
                            </span>
                        @else
                            <a
                                href="{{ route($rute) }}"
                                wire:navigate
                                @if ($aktif) aria-current="page" @endif
                                class="flex min-h-11 items-center gap-4 px-8 text-[0.875rem] transition-colors {{ $aktif ? 'font-bold text-ink' : 'font-medium text-umber hover:text-ink' }}"
                            >
                                <svg viewBox="0 0 24 24" class="size-5 shrink-0 {{ $aktif ? 'text-terracotta' : '' }}" fill="none" aria-hidden="true">
                                    <path d="{{ $ikon[$namaIkon] }}" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                                </svg>
                                {{ $label }}
                            </a>
                            @if ($aktif)
                                <span aria-hidden="true" class="bar-aktif"></span>
                            @endif
                        @endif
                    </li>
                @endforeach
            </ul>
        @endforeach
    </nav>

    {{-- Horizon menaruh kartu promo di dasar sidebar; di sini diganti identitas
         pengguna dan tombol keluar, yang jauh lebih berguna di aplikasi nyata. --}}
    <div class="p-5">
        <div class="rounded-[20px] bg-gradient-to-br from-terracotta-soft to-terracotta-deep p-4">
            <div class="flex items-center gap-3">
                <span class="grid size-9 shrink-0 place-items-center rounded-full bg-cream/25 font-mono text-[0.6875rem] font-bold text-cream">
                    {{ mb_strtoupper(mb_substr($user->name, 0, 2)) }}
                </span>
                <span class="min-w-0 flex-1">
                    <span class="block truncate text-[0.8125rem] font-bold text-cream">{{ $user->name }}</span>
                    <span class="block truncate text-[0.6875rem] text-cream/75">{{ $user->role->label() }}</span>
                </span>
            </div>

            <form method="POST" action="{{ route('keluar') }}" class="mt-3">
                @csrf
                <button
                    type="submit"
                    class="flex min-h-10 w-full cursor-pointer items-center justify-center gap-2 rounded-xl bg-cream/15 text-[0.8125rem] font-semibold text-cream transition-colors hover:bg-cream/25"
                >
                    <svg viewBox="0 0 20 20" class="size-4" fill="none" aria-hidden="true">
                        <path d="M12.5 6.5V5a1.5 1.5 0 0 0-1.5-1.5H5A1.5 1.5 0 0 0 3.5 5v10A1.5 1.5 0 0 0 5 16.5h6a1.5 1.5 0 0 0 1.5-1.5v-1.5M9 10h7.5m0 0-2.2-2.2M16.5 10l-2.2 2.2" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    Keluar
                </button>
            </form>
        </div>
    </div>
</aside>

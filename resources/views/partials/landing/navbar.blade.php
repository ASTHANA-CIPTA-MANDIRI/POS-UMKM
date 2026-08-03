{{--
    Navbar mengapung. Susunannya mengikuti referensi: logo + tagline di kiri dengan
    bentuk gelombang di belakangnya, navigasi di tengah, tombol CTA dan tombol grid
    di kanan. Warna ungu pada referensi diganti terracotta–amber.

    Catatan: kartu pill ini TIDAK memakai overflow-hidden, supaya panel dropdown bisa
    keluar dari batasnya. Gelombang dekoratif punya wadah kliping sendiri.
--}}
<div data-nav-backdrop hidden class="fixed inset-0 z-40 bg-ink/25 backdrop-blur-[2px] lg:hidden"></div>

<header class="fixed inset-x-0 top-0 z-50 px-4 pt-4 sm:px-6 sm:pt-6">
    <div
        data-navbar
        data-scrolled="false"
        class="mx-auto flex max-w-7xl items-center justify-between gap-4 rounded-[24px] bg-paper/95 px-3 py-2.5 backdrop-blur-xl transition-shadow duration-300 sm:rounded-[28px] sm:px-4 sm:py-3 data-[scrolled=true]:shadow-[0_10px_40px_-16px_rgb(66_42_251/0.28)] data-[scrolled=false]:shadow-[0_2px_12px_-6px_rgb(11_20_55/0.10)]"
    >
        {{-- Kiri: merek --}}
        <a href="{{ route('beranda') }}" class="relative flex min-w-0 shrink items-center gap-2.5 rounded-2xl pr-3 pl-1.5 sm:gap-3 sm:pr-10">
            {{-- Gelombang hangat di belakang logo; wadah ini yang mengkliping, bukan pill. --}}
            <span aria-hidden="true" class="pointer-events-none absolute -inset-y-2.5 -left-1.5 right-0 overflow-hidden rounded-l-[22px]">
                <svg class="absolute inset-y-0 left-0 h-full w-[190px]" viewBox="0 0 190 72" preserveAspectRatio="none" fill="none">
                    <defs>
                        <linearGradient id="navWave" x1="0" y1="0" x2="1" y2="0.4">
                            <stop offset="0" stop-color="#7551FF" stop-opacity="0.42" />
                            <stop offset="0.55" stop-color="#422AFB" stop-opacity="0.30" />
                            <stop offset="1" stop-color="#2111A5" stop-opacity="0.10" />
                        </linearGradient>
                    </defs>
                    <path d="M0 0H128c-26 14-30 44-4 72H0V0Z" fill="url(#navWave)" />
                    <path d="M118 0h16c-26 14-30 44-4 72h-16c-26-28-22-58 4-72Z" fill="#422AFB" fill-opacity="0.14" />
                </svg>
            </span>

            <span class="relative grid size-10 place-items-center rounded-xl bg-gradient-to-br from-terracotta to-terracotta-deep shadow-[0_6px_16px_-6px_rgb(66_42_251/0.55)]">
                {{-- Tanda merek: tiga bidang bertumpuk — nampan yang disusun. --}}
                <svg viewBox="0 0 24 24" class="size-5" fill="none" aria-hidden="true">
                    <path d="M4 8.4 12 4l8 4.4-8 4.4-8-4.4Z" fill="#FFFFFF" fill-opacity="0.95" />
                    <path d="M4 12.6 12 17l8-4.4" stroke="#FFFFFF" stroke-opacity="0.65" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                    <path d="M4 16.6 12 21l8-4.4" stroke="#FFFFFF" stroke-opacity="0.38" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </span>

            <span class="relative leading-none">
                <span class="block font-display text-[1.0625rem] font-semibold tracking-tight text-ink">Nampan</span>
                {{-- Tagline disembunyikan di layar sempit; di 390px baris navbar sudah
                     penuh oleh logo, CTA, dan tombol menu. --}}
                <span class="mt-1 hidden font-mono text-[0.5625rem] tracking-[0.13em] text-umber uppercase sm:block">Kasir. Stok. Kasbon.</span>
            </span>
        </a>

        {{-- Tengah: navigasi desktop. Tiap butir kini menuju halaman tersendiri,
             dan halaman yang sedang dibuka ditandai garis bawah + aria-current. --}}
        <nav aria-label="Navigasi utama" class="hidden items-center gap-1 lg:flex">
            @foreach ([['beranda', 'Beranda'], ['cara-jualan', 'Cara Jualan']] as [$rute, $label])
                @php $aktif = request()->routeIs($rute); @endphp
                <a
                    href="{{ route($rute) }}"
                    @if ($aktif) aria-current="page" @endif
                    class="relative rounded-full px-3.5 py-2 text-sm font-medium transition-colors {{ $aktif ? 'text-ink' : 'text-umber hover:bg-cream-deep hover:text-ink' }}"
                >
                    {{ $label }}
                    @if ($aktif)
                        <span aria-hidden="true" class="absolute inset-x-3.5 -bottom-0.5 h-[2px] rounded-full bg-terracotta"></span>
                    @endif
                </a>
            @endforeach

            @php $aktifSolusi = request()->routeIs('untuk-siapa'); @endphp
            <div class="relative">
                <button
                    type="button"
                    data-dropdown-trigger
                    aria-expanded="false"
                    aria-controls="menu-solusi"
                    class="relative flex cursor-pointer items-center gap-1.5 rounded-full px-3.5 py-2 text-sm font-medium transition-colors {{ $aktifSolusi ? 'text-ink' : 'text-umber hover:bg-cream-deep hover:text-ink' }}"
                >
                    Solusi
                    <svg viewBox="0 0 16 16" class="size-3.5 text-umber-soft" fill="none" aria-hidden="true">
                        <path d="m4 6.5 4 3.5 4-3.5" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                    @if ($aktifSolusi)
                        <span aria-hidden="true" class="absolute inset-x-3.5 -bottom-0.5 h-[2px] rounded-full bg-terracotta"></span>
                    @endif
                </button>

                <div id="menu-solusi" hidden class="absolute top-full left-1/2 z-10 mt-3 w-64 -translate-x-1/2 rounded-2xl border border-line-soft bg-paper p-2 lift">
                    @foreach ([
                        ['warteg', 'Warteg & rumah makan', 'Buka bill per meja'],
                        ['kelontong', 'Toko kelontong', 'Scan cepat, kasbon'],
                        ['depot-air', 'Depot air isi ulang', 'Titip & tukar galon'],
                        ['laundry', 'Laundry', 'Terima, proses, ambil'],
                    ] as [$anchor, $judul, $ket])
                        <a href="{{ route('untuk-siapa') }}#{{ $anchor }}" class="block rounded-xl px-3 py-2.5 transition-colors hover:bg-cream">
                            <span class="block text-sm font-medium text-ink">{{ $judul }}</span>
                            <span class="block text-xs text-umber">{{ $ket }}</span>
                        </a>
                    @endforeach
                </div>
            </div>

            @foreach ([['mode-offline', 'Mode Offline'], ['harga', 'Harga']] as [$rute, $label])
                @php $aktif = request()->routeIs($rute); @endphp
                <a
                    href="{{ route($rute) }}"
                    @if ($aktif) aria-current="page" @endif
                    class="relative rounded-full px-3.5 py-2 text-sm font-medium transition-colors {{ $aktif ? 'text-ink' : 'text-umber hover:bg-cream-deep hover:text-ink' }}"
                >
                    {{ $label }}
                    @if ($aktif)
                        <span aria-hidden="true" class="absolute inset-x-3.5 -bottom-0.5 h-[2px] rounded-full bg-terracotta"></span>
                    @endif
                </a>
            @endforeach
        </nav>

        {{-- Kanan: aksi --}}
        <div class="flex shrink-0 items-center gap-2">
            {{-- Tautan masuk. Tanpa ini tidak ada jalan sama sekali dari situs publik
                 ke aplikasi — pengguna yang sudah berlangganan mentok di halaman harga. --}}
            <a
                href="{{ route('masuk') }}"
                class="hidden rounded-full px-3.5 py-2 text-sm font-medium text-umber transition-colors hover:bg-cream-deep hover:text-ink lg:inline-flex"
            >
                Masuk
            </a>

            <a
                href="{{ route('harga') }}"
                data-tap
                class="group inline-flex items-center gap-2 rounded-full bg-gradient-to-br from-terracotta-soft to-terracotta-deep px-3.5 py-2.5 text-sm font-semibold text-cream shadow-[0_8px_20px_-8px_rgb(66_42_251/0.6)] transition-shadow hover:shadow-[0_10px_26px_-8px_rgb(66_42_251/0.7)] sm:px-5"
            >
                <span class="whitespace-nowrap">Coba<span class="hidden sm:inline"> Gratis</span></span>
                <svg viewBox="0 0 16 16" class="size-3.5 transition-transform duration-200 group-hover:translate-x-0.5" fill="none" aria-hidden="true">
                    <path d="M2.5 8h11M9.5 4l4 4-4 4" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </a>

            {{-- Tombol grid, mengikuti referensi. Ditandai sebagai menu aplikasi. --}}
            <button
                type="button"
                aria-label="Menu aplikasi"
                class="hidden size-10 cursor-pointer place-items-center rounded-full text-umber transition-colors hover:bg-cream-deep hover:text-ink lg:grid"
            >
                <svg viewBox="0 0 16 16" class="size-4" fill="currentColor" aria-hidden="true">
                    @foreach ([2, 7, 12] as $y)
                        @foreach ([2, 7, 12] as $x)
                            <circle cx="{{ $x }}" cy="{{ $y }}" r="1.3" />
                        @endforeach
                    @endforeach
                </svg>
            </button>

            {{-- Pemicu panel mobile. 44x44px minimum untuk target sentuh. --}}
            <button
                type="button"
                data-nav-toggle
                aria-expanded="false"
                aria-controls="panel-nav-mobile"
                aria-label="Buka menu navigasi"
                class="grid size-11 cursor-pointer place-items-center rounded-full text-ink transition-colors hover:bg-cream-deep lg:hidden"
            >
                <svg viewBox="0 0 20 20" class="size-5" fill="none" aria-hidden="true">
                    <path d="M3 6h14M3 10h14M3 14h9" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
                </svg>
            </button>
        </div>
    </div>

    {{-- Panel navigasi mobile --}}
    <div
        id="panel-nav-mobile"
        hidden
        class="mx-auto mt-3 max-w-7xl rounded-[24px] border border-line-soft bg-paper p-3 lift lg:hidden"
    >
        <nav aria-label="Navigasi mobile" class="grid gap-1">
            @foreach ([
                ['beranda', 'Beranda'],
                ['cara-jualan', 'Cara jualan'],
                ['mode-offline', 'Mode offline'],
                ['untuk-siapa', 'Untuk siapa'],
                ['harga', 'Harga'],
            ] as [$rute, $label])
                <a
                    data-nav-item
                    href="{{ route($rute) }}"
                    @if (request()->routeIs($rute)) aria-current="page" @endif
                    class="flex min-h-11 items-center justify-between rounded-xl px-4 text-[0.9375rem] font-medium transition-colors hover:bg-cream {{ request()->routeIs($rute) ? 'bg-cream text-terracotta' : 'text-ink' }}"
                >
                    {{ $label }}
                    <svg viewBox="0 0 16 16" class="size-3.5 text-umber-soft" fill="none" aria-hidden="true">
                        <path d="M6 4l4 4-4 4" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" />
                    </svg>
                </a>
            @endforeach
        </nav>

        <div class="mt-2 grid gap-2 rule pt-3">
            <a
                data-nav-item
                href="{{ route('masuk') }}"
                class="flex min-h-11 items-center justify-center rounded-xl border border-line text-[0.9375rem] font-medium text-ink"
            >
                Masuk
            </a>
            <a
                data-nav-item
                href="{{ route('harga') }}"
                class="flex min-h-11 items-center justify-center gap-2 rounded-xl bg-gradient-to-br from-terracotta-soft to-terracotta-deep text-[0.9375rem] font-semibold text-cream"
            >
                Coba 14 hari gratis
            </a>
        </div>
    </div>
</header>

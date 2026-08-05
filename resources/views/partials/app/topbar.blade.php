@php
    $user = auth()->user();
    $tenant = $user->tenant;

    /*
     * Breadcrumb & judul halaman diturunkan dari nama route, bukan dititipkan dari
     * tiap komponen. Dengan begitu menambah halaman baru tidak menuntut plumbing
     * tambahan — cukup daftarkan di sini.
     */
    $peta = [
    'admin.dasbor' => ['Platform', 'Dasbor'],
    'owner.dasbor' => ['Kelola', 'Dasbor'],
    'owner.produk' => ['Katalog & stok', 'Produk'],
    /*
     * Rute yang belum terpeta jatuh ke cadangan "nama rute sesudah titik terakhir", dan
     * untuk dua rute berikut cadangan itu SALAH secara berbahaya:
     * `owner.stok.opname` jatuh ke "Opname" — kata akuntansi yang justru dilarang tampil
     * oleh bagian "Bahasa layar" di CLAUDE.md, sementara seluruh isi layarnya sudah
     * memakai "hitung stok"; dan `owner.pembelian.baru` jatuh ke "Baru", yang tidak
     * berarti apa pun di HP tempat judul itu satu-satunya penunjuk halaman.
     * Judulnya sengaja pendek: judul 1,5rem ber-truncate di 390px.
     */
    'owner.stok.opname' => ['Katalog & stok', 'Hitung stok'],
    'owner.stok' => ['Katalog & stok', 'Stok'],
    'owner.pembelian.baru' => ['Katalog & stok', 'Catat nota'],
    'owner.pembelian' => ['Katalog & stok', 'Nota belanja'],
    'owner.laporan' => ['Kelola', 'Laporan'],
    'owner.langganan' => ['Kelola', 'Langganan'],
    ];

    /*
     * Judul cadangan diturunkan dari nama rute, bukan dituliskan "Halaman".
     *
     * Peta di atas mudah terlupakan saat menambah halaman baru, dan kalau
     * cadangannya berupa kata mati, halaman baru tampil tanpa judul yang benar
     * tanpa ada yang menyadarinya. Menurunkannya dari nama rute membuat halaman
     * yang belum dipetakan tetap menyebut dirinya dengan benar.
     */
    [$grup, $judul] = collect($peta)->first(fn ($v, $k) => request()->routeIs($k))
    ?? ['Nampan', str(request()->route()?->getName() ?? '')->afterLast('.')->replace(['-', '_'], ' ')->headline()->toString()];
@endphp

{{--
    Navbar: DUA kartu putih mengapung berdampingan — kartu judul di kiri, pil kontrol
    di kanan — di atas latar halaman, bukan judul telanjang di atas bilah kaca.

    Template Horizon menaruh judulnya langsung di atas bilah tembus pandang. Di sini
    judulnya diberi wadah sendiri supaya ia terbaca sebagai satu kesatuan dengan
    breadcrumb-nya, dan bentuknya sejalan dengan kartu-kartu di bawahnya.

    flex-wrap: di layar sempit kedua kartu menumpuk, bukan saling menghimpit sampai
    judulnya tinggal beberapa huruf.
--}}
<header class="app-font sticky top-4 z-20 mx-4 mb-2 flex flex-wrap items-stretch justify-between gap-3 sm:mx-6">
    <div class="kartu flex min-w-0 flex-1 items-center gap-3 px-4 py-3 sm:px-5">
        <button
            type="button"
            @click="menuTerbuka = true"
            aria-label="Buka menu"
            class="grid size-10 shrink-0 cursor-pointer place-items-center rounded-xl text-ink transition-colors hover:bg-cream xl:hidden"
        >
            <svg viewBox="0 0 20 20" class="size-5" fill="none" aria-hidden="true">
                <path d="M3 6h14M3 10h14M3 14h9" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" />
            </svg>
        </button>

        <div class="min-w-0">
            {{-- Disembunyikan di layar terkecil: di 390px ia pecah dua baris sambil
                     mengulang kata yang sudah tercetak besar tepat di bawahnya. --}}
                <nav aria-label="Breadcrumb" class="hidden text-[0.8125rem] text-umber sm:block">
                <span>{{ $grup }}</span>
                <span aria-hidden="true" class="mx-1">/</span>
                <span class="text-ink">{{ $judul }}</span>
            </nav>
            {{-- Judul 33px seperti di template; diturunkan di layar sempit supaya
                 tidak memakan dua baris di ponsel. --}}
            <p class="truncate text-[1.5rem] leading-tight font-bold text-ink sm:text-[2.0625rem]">{{ $judul }}</p>
        </div>
    </div>

    <div class="pil-kontrol flex shrink-0 items-center gap-2 px-2 py-2">
        {{-- Kotak pencarian: siluetnya dipertahankan karena khas Horizon, tapi
             DINONAKTIFKAN karena belum ada apa pun yang bisa dicari. Kontrol yang
             terlihat hidup tapi tidak berfungsi lebih membingungkan daripada
             kontrol yang jelas-jelas belum aktif. --}}
        <div class="hidden h-full items-center gap-2 rounded-full bg-cream px-4 md:flex" title="Pencarian belum tersedia">
            <svg viewBox="0 0 20 20" class="size-4 text-umber-soft" fill="none" aria-hidden="true">
                <circle cx="9" cy="9" r="5.5" stroke="currentColor" stroke-width="1.6" />
                <path d="m13.5 13.5 3 3" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
            </svg>
            <input
                type="search"
                disabled
                placeholder="Cari…"
                aria-label="Pencarian (belum tersedia)"
                class="w-24 bg-transparent text-[0.8125rem] font-medium text-ink placeholder:text-umber-soft focus:outline-none disabled:cursor-not-allowed lg:w-36"
            >
        </div>

        {{-- Status langganan selalu terlihat: merchant yang suspend perlu tahu
             sebabnya tanpa harus mencari ke halaman lain. --}}
        @if ($tenant !== null)
            @php
                $warna = match (true) {
                    ! $tenant->canTransact() => 'bg-terracotta/12 text-terracotta',
                    $tenant->status->value === 'trial' => 'bg-amber-glow/25 text-amber-warm',
                    default => 'bg-olive/12 text-olive',
                };
            @endphp
            <span class="shrink-0 rounded-full px-3 py-1.5 font-mono text-[0.5625rem] tracking-[0.12em] uppercase {{ $warna }}">
                {{ $tenant->status->label() }}
            </span>
        @endif

        <span class="hidden min-w-0 shrink-0 pl-1 text-right lg:block">
            <span class="block truncate text-[0.8125rem] font-bold text-ink">
                {{ $tenant?->business_name ?? 'Pengelola platform' }}
            </span>
            <span class="block truncate text-[0.6875rem] text-umber-soft">
                {{ $tenant ? $tenant->business_type->label() : 'Semua merchant' }}
                @if ($user->outlet)
                    &middot; {{ $user->outlet->outlet_name }}
                @endif
            </span>
        </span>

        <span class="grid size-10 shrink-0 place-items-center rounded-full bg-gradient-to-br from-terracotta-soft to-terracotta-deep font-mono text-[0.6875rem] font-bold text-cream">
            {{ mb_strtoupper(mb_substr($user->name, 0, 2)) }}
        </span>
    </div>
</header>

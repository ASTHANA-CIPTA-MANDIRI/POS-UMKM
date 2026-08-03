<!DOCTYPE html>
<html lang="id" class="antialiased">
<head>
    <meta charset="utf-8">
    {{-- Tanpa maximum-scale: mencegah zoom adalah pelanggaran aksesibilitas. --}}
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#F4F7FE">

    <title>@yield('judul', 'Nampan') &mdash; Kasir untuk warteg, kelontong, depot air &amp; laundry</title>
    <meta name="description" content="@yield('deskripsi', 'Aplikasi kasir untuk usaha kecil: buka bill per meja, kasbon pelanggan langganan, stok bahan baku otomatis, dan tetap jalan saat internet mati.')">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    {{-- display=swap agar teks tidak invisible selama font dimuat. --}}
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,300..700&family=Inter:wght@300..700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <noscript>
        {{-- Lapisan panggung 3D disembunyikan lewat CSS lalu dimunculkan motion.
             Kalau JavaScript mati, tampilkan apa adanya daripada area kosong. --}}
        <style>[data-layer] { opacity: 1 !important; }</style>
    </noscript>
</head>
<body class="min-h-dvh overflow-x-hidden">
    <a href="#isi" class="sr-only focus:not-sr-only focus:fixed focus:top-4 focus:left-4 focus:z-[100] focus:rounded-full focus:bg-ink focus:px-5 focus:py-3 focus:text-sm focus:text-cream">
        Lompat ke konten
    </a>

    @include('partials.landing.navbar')

    <main id="isi">
        @yield('konten')

        {{-- Halaman bisa menonaktifkan penutup dengan mendefinisikan section
             'tanpa_cta' — dipakai halaman harga, yang CTA-nya sudah ada di kartu paket. --}}
        @sectionMissing('tanpa_cta')
            @include('partials.landing.cta')
        @endif
    </main>

    @include('partials.landing.footer')
</body>
</html>

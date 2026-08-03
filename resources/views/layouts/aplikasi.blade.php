<!DOCTYPE html>
<html lang="id" class="antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#F4F7FE">
    <title>@yield('judul', 'Dasbor') &mdash; Nampan</title>
    <meta name="robots" content="noindex">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    {{-- DM Sans adalah font Horizon UI; dipakai untuk seluruh area aplikasi.
         JetBrains Mono tetap dibawa untuk label kecil dan angka. --}}
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400..700;1,9..40,400&family=Poppins:wght@500;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{--
    Kerangka Horizon UI: sidebar putih tetap di kiri, sisa layar berlatar bertint,
    dan navbar mengapung membulat di atas area konten.
--}}
<body class="app-font min-h-dvh bg-cream">
    <div x-data="{ menuTerbuka: false }" class="xl:flex">
        <div
            x-show="menuTerbuka"
            x-transition.opacity
            @click="menuTerbuka = false"
            class="fixed inset-0 z-30 bg-ink/40 xl:hidden"
            style="display: none"
        ></div>

        @include('partials.app.sidebar')

        <div class="min-w-0 flex-1 pb-10">
            @include('partials.app.topbar')

            <main class="px-4 pt-4 sm:px-6">
                @if (session('peringatan'))
                    <div role="alert" class="kartu mb-5 flex items-start gap-3 border border-amber-warm/25 px-5 py-4">
                        <svg viewBox="0 0 20 20" class="mt-0.5 size-4 shrink-0 text-amber-warm" fill="none" aria-hidden="true">
                            <circle cx="10" cy="10" r="7.5" stroke="currentColor" stroke-width="1.5" />
                            <path d="M10 6.5v4M10 13.4v.2" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" />
                        </svg>
                        <p class="text-[0.875rem] text-ink">{{ session('peringatan') }}</p>
                    </div>
                @endif

                {{ $slot }}
            </main>
        </div>
    </div>
</body>
</html>

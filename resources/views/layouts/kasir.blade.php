@php
    // Layar transaksi memakai seluruh lebar layar: grid produk yang terhimpit
    // memaksa kasir membaca nama produk yang terpotong, dan itu sumber salah tap.
    $penuh ??= false;
    $wadah = $penuh ? 'max-w-none' : 'max-w-5xl';
@endphp
<!DOCTYPE html>
<html lang="id" class="antialiased">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0B1437">
    <title>@yield('judul', 'Kasir') &mdash; Nampan</title>
    <meta name="robots" content="noindex">
    {{-- Dibutuhkan klien kasir untuk POST ke endpoint sinkronisasi. --}}
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400..700;1,9..40,400&family=Poppins:wght@500;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
{{--
    Layout kasir sengaja tanpa sidebar. Kasir hanya butuh satu hal — layar transaksi —
    dan navigasi tambahan di layar sentuh sempit justru memperbesar risiko salah tap
    saat sedang melayani pembeli.
--}}
<body class="app-font min-h-dvh bg-cream">
    <header class="sticky top-0 z-30 border-b border-line bg-ink px-4 py-3 text-cream sm:px-6">
        <div class="mx-auto flex {{ $wadah }} items-center gap-3">
            {{-- Logo sekaligus jalan pulang: pola yang sudah diharapkan orang, dan
                 satu-satunya kontrol tetap di bilah ini selain Keluar. --}}
            <a href="{{ route(auth()->user()->rutaBeranda()) }}" wire:navigate aria-label="Beranda"
               class="grid size-9 shrink-0 place-items-center rounded-lg bg-gradient-to-br from-terracotta to-terracotta-deep transition hover:brightness-110">
                <svg viewBox="0 0 24 24" class="size-4.5" fill="none" aria-hidden="true">
                    <path d="M4 8.4 12 4l8 4.4-8 4.4-8-4.4Z" fill="#FFFFFF" fill-opacity="0.95" />
                    <path d="M4 12.6 12 17l8-4.4" stroke="#FFFFFF" stroke-opacity="0.6" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" />
                </svg>
            </a>

            <div class="min-w-0 flex-1">
                <p class="truncate text-[0.9375rem] font-semibold">{{ auth()->user()->name }}</p>
                <p class="truncate font-mono text-[0.5625rem] tracking-[0.12em] text-cream/55 uppercase">
                    {{ auth()->user()->role->label() }}
                    @if (auth()->user()->outlet)
                        &middot; {{ auth()->user()->outlet->outlet_name }}
                    @endif
                </p>
            </div>

            <form method="POST" action="{{ route('keluar') }}">
                @csrf
                <button
                    type="submit"
                    class="min-h-11 cursor-pointer rounded-full border border-cream/20 px-4 text-[0.8125rem] font-medium text-cream/85 transition-colors hover:bg-cream/10"
                >
                    Keluar
                </button>
            </form>
        </div>
    </header>

    <main class="mx-auto {{ $wadah }} {{ $penuh ? '' : 'px-4 py-6 sm:px-6 sm:py-8' }}">
        @if (session('peringatan'))
            <div role="alert" class="mb-6 rounded-xl border border-amber-warm/30 bg-amber-glow/12 px-4 py-3.5">
                <p class="text-[0.875rem] text-ink">{{ session('peringatan') }}</p>
            </div>
        @endif

        {{ $slot }}
    </main>
</body>
</html>

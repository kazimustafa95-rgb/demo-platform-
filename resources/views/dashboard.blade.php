<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Dashboard - {{ config('app.name', 'Laravel') }}</title>

        @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
            @vite(['resources/css/app.css', 'resources/js/app.js'])
        @endif
    </head>
    <body class="min-h-screen bg-stone-100 text-stone-950">
        <main class="mx-auto flex min-h-screen max-w-5xl items-center px-6 py-10">
            <section class="w-full rounded-3xl border border-stone-200 bg-white p-8 shadow-sm sm:p-10">
                <div class="flex flex-col gap-8 lg:flex-row lg:items-start lg:justify-between">
                    <div class="max-w-2xl">
                        <p class="mb-3 text-sm font-semibold uppercase tracking-[0.2em] text-orange-600">
                            User Dashboard
                        </p>

                        <h1 class="text-4xl font-semibold tracking-tight text-stone-950">
                            Welcome back, {{ $user->name }}
                        </h1>

                        <p class="mt-4 text-base leading-7 text-stone-600">
                            You are signed in to the main application dashboard.
                            @if ($user->hasRole('admin'))
                                Your account also has admin access.
                            @endif
                        </p>
                    </div>

                    <div class="shrink-0">
                        <img
                            src="{{ asset('demos-logo.png') }}"
                            alt="{{ config('app.name', 'DEMOS') }}"
                            class="h-14 w-auto"
                        >
                    </div>
                </div>

                <div class="mt-8 grid gap-4 md:grid-cols-2">
                    <div class="rounded-2xl border border-stone-200 bg-stone-50 p-5">
                        <p class="text-sm font-medium text-stone-500">Signed in as</p>
                        <p class="mt-2 text-lg font-semibold text-stone-900">{{ $user->email }}</p>
                    </div>

                    <div class="rounded-2xl border border-stone-200 bg-stone-50 p-5">
                        <p class="text-sm font-medium text-stone-500">Account type</p>
                        <p class="mt-2 text-lg font-semibold text-stone-900">
                            {{ $user->hasRole('admin') ? 'Administrator' : 'User' }}
                        </p>
                    </div>
                </div>

                <div class="mt-8 flex flex-col gap-3 sm:flex-row">
                    @if ($user->hasRole('admin'))
                        <a
                            href="{{ route('filament.admin.pages.dashboard') }}"
                            class="inline-flex items-center justify-center rounded-xl bg-orange-500 px-5 py-3 text-sm font-semibold text-white transition hover:bg-orange-600"
                        >
                            Open Admin Dashboard
                        </a>
                    @endif

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf

                        <button
                            type="submit"
                            class="inline-flex items-center justify-center rounded-xl border border-stone-300 px-5 py-3 text-sm font-semibold text-stone-700 transition hover:bg-stone-100"
                        >
                            Sign out
                        </button>
                    </form>
                </div>
            </section>
        </main>
    </body>
</html>

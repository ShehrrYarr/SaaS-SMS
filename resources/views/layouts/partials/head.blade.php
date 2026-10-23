<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>{{ filled($title ?? null) ? $title.' · ' : '' }}{{ tenant()?->name ?? config('app.name') }}</title>
<link rel="icon" type="image/png" href="{{ asset('images/favicon.png') }}">

{{-- Apply the saved theme and sidebar mode before the first paint, so pages never flash. --}}
<script>
    (function () {
        var root = document.documentElement;
        var read = function (key) { try { return window.localStorage.getItem(key); } catch (e) { return null; } };
        var theme = read('ssms.theme') || 'light';
        var resolved = theme === 'auto' ? (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light') : theme;

        root.setAttribute('data-bs-theme', resolved);
        root.setAttribute('data-sidebar', resolved === 'dark' ? 'dark-sidebar' : 'light-sidebar');

        if (! root.hasAttribute('data-auth-layout')) {
            var width = window.innerWidth;
            root.setAttribute('data-main-layout', width < 769 ? 'close-sidebar' : (width < 1250 ? 'small-icon' : (read('ssms.sidebar') || 'vertical')));
        }
    })();
</script>

@include('layouts.partials.assets')

<footer class="footer d-flex align-items-center text-center">
    <div class="container-fluid">
        <p class="mb-0">
            &copy; {{ now()->year }} {{ $owner }}. {{ __('Powered by :app.', ['app' => config('app.name')]) }}
        </p>
    </div>
</footer>

{{--
    Page title with breadcrumbs and an optional actions slot.
    :breadcrumbs is label => url (null for the current page).
--}}
@props(['title', 'breadcrumbs' => []])

<div class="hstack flex-wrap gap-3 mb-5">
    <div class="flex-grow-1">
        <h4 class="mb-1 fw-semibold">{{ $title }}</h4>
        @if ($breadcrumbs)
            <nav aria-label="{{ __('Breadcrumb') }}">
                <ol class="breadcrumb breadcrumb-arrow mb-0">
                    @foreach ($breadcrumbs as $label => $url)
                        @if ($url && ! $loop->last)
                            <li class="breadcrumb-item"><a href="{{ $url }}" wire:navigate>{{ $label }}</a></li>
                        @else
                            <li class="breadcrumb-item active" aria-current="page">{{ $label }}</li>
                        @endif
                    @endforeach
                </ol>
            </nav>
        @endif
    </div>

    @isset($actions)
        <div class="d-flex flex-wrap align-items-center gap-2 flex-shrink-0">
            {{ $actions }}
        </div>
    @endisset
</div>

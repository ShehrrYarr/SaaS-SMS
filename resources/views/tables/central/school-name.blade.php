<a href="{{ route('central.schools.show', $row) }}" wire:navigate class="d-flex align-items-center gap-3 text-reset text-decoration-none">
    <span class="entity-avatar">
        @if ($row->logoUrl())
            <img src="{{ $row->logoUrl() }}" alt="">
        @else
            {{ Str::upper(Str::substr($row->name, 0, 1)) }}
        @endif
    </span>
    <span class="min-w-0">
        <span class="d-block fw-medium text-body text-truncate">{{ $row->name }}</span>
        @if ($row->email)
            <small class="text-muted text-truncate d-block">{{ $row->email }}</small>
        @endif
    </span>
</a>

@php($limit = $row->plan?->max_students)
<span @class(['text-danger fw-medium' => $limit !== null && $value > $limit])>{{ number_format($value) }}</span>
<small class="text-muted">/ {{ $limit === null ? '∞' : number_format($limit) }}</small>

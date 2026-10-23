{{--
    "18 of 20 school days present · 2 days on leave".
    Days on leave are not school days missed, so they are counted apart.
--}}
@props(['present', 'schoolDays', 'excused' => 0])

<span {{ $attributes }}>
    {{ $schoolDays > 0
        ? trans_choice(':present of :count school day present|:present of :count school days present', $schoolDays, ['present' => $present, 'count' => $schoolDays])
        : __('No school days marked yet') }}@if ($excused > 0) · {{ trans_choice(':count day on leave|:count days on leave', $excused, ['count' => $excused]) }}@endif
</span>

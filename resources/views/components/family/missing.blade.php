{{-- Student and parent portals: there is no student to show yet. --}}
@use('App\Support\Portal')

<div {{ $attributes->class('card mb-0') }}>
    @if (Portal::current() === 'parent')
        <x-empty-state icon="ri-parent-line" :title="__('No children linked yet')"
                       :message="__('Ask the school office to link your children to your account.')" />
    @else
        <x-empty-state icon="ri-graduation-cap-line" :title="__('Your student record isn\'t ready yet')"
                       :message="__('Please contact the school office.')" />
    @endif
</div>

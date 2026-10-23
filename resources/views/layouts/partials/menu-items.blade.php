{{--
    Renders entries from App\Support\Navigation\Menu. Submenus toggle with Alpine:
    open by default on the active trail in the full-width sidebar, and closed as
    click-to-open flyouts in the collapsed (small-icon) sidebar.
--}}
@foreach ($items as $item)
    @if (isset($item['heading']))
        <li class="menu-title" role="presentation">{{ $item['heading'] }}</li>
    @elseif ($item['children'])
        <li class="slide"
            x-data="{ open: @js($item['active']) && document.documentElement.dataset.mainLayout !== 'small-icon' }"
            :class="{ 'open-menu': open }"
            @click.outside="if (document.documentElement.dataset.mainLayout === 'small-icon') open = false"
            @sidebar-mode-changed.window="if ($event.detail === 'small-icon') open = false">
            <a href="#" @class(['side-menu__item', 'active' => $item['active']]) role="menuitem" :aria-expanded="open" @click.prevent="open = ! open">
                @if ($item['icon'])
                    <span class="side_menu_icon"><i class="{{ $item['icon'] }}"></i></span>
                @endif
                <span class="side-menu__label">{{ $item['label'] }}</span>
                <i class="ri-arrow-down-s-line side-menu__angle"></i>
            </a>
            <ul class="slide-menu" role="menu">
                @include('layouts.partials.menu-items', ['items' => $item['children']])
            </ul>
        </li>
    @else
        <li class="slide">
            <a href="{{ $item['url'] }}" wire:navigate @class(['side-menu__item', 'active' => $item['active']]) role="menuitem" @if ($item['active']) aria-current="page" @endif>
                @if ($item['icon'])
                    <span class="side_menu_icon"><i class="{{ $item['icon'] }}"></i></span>
                @endif
                <span class="side-menu__label">{{ $item['label'] }}</span>
            </a>
        </li>
    @endif
@endforeach

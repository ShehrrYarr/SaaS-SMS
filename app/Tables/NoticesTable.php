<?php

namespace App\Tables;

use App\Enums\NoticeAudience;
use App\Enums\NoticeState;
use App\Models\Notice;
use App\Services\Notices\NoticePublisher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;

class NoticesTable extends Table
{
    public function permission(): ?string
    {
        return 'notices.view';
    }

    public function query(): Builder
    {
        return Notice::query()->with(['schoolClass', 'creator']);
    }

    public function defaultSort(): array
    {
        return ['created_at', 'desc'];
    }

    public function columns(): array
    {
        return [
            Column::make('title', __('Notice'))->sortable()->searchable()
                ->format(fn (string $title, Notice $notice) => new HtmlString(
                    ($notice->is_pinned ? '<i class="ri-pushpin-2-fill text-warning me-1" title="'.e(__('Pinned')).'"></i>' : '')
                    .'<a href="'.e(route('tenant.notices.show', $notice->id)).'" wire:navigate class="fw-medium">'.e(\Illuminate\Support\Str::limit($title, 60)).'</a>'
                    .($notice->attachment ? ' <i class="ri-attachment-2 text-muted" title="'.e(__('Has an attachment')).'"></i>' : '')
                    .($notice->creator ? '<small class="d-block text-muted">'.e(__('by :name', ['name' => $notice->creator->name])).'</small>' : '')
                )),
            Column::make('target_audience', __('Audience'))->sortable()
                ->format(fn (NoticeAudience $audience, Notice $notice) => new HtmlString(
                    sprintf('<span class="badge bg-%1$s-subtle text-%1$s">%2$s</span>', $audience->color(), e($audience->label()))
                    .($notice->schoolClass ? ' <small class="text-muted">'.e($notice->schoolClass->name).'</small>' : '')
                )),
            Column::make('state', __('Status'))->virtual()
                ->format(fn ($value, Notice $notice) => new HtmlString(sprintf(
                    '<span class="badge bg-%1$s-subtle text-%1$s">%2$s</span>', $notice->state()->color(), e($notice->state()->label())
                ))),
            Column::make('published_at', __('Published'))->sortable()->dateTime()->hiddenOnMobile(),
            Column::make('expires_at', __('Expires'))->sortable()->dateTime()->hiddenOnMobile(),
        ];
    }

    public function filters(): array
    {
        return [
            Filter::make('state', __('Status'))
                ->options(NoticeState::class)
                ->query(fn (Builder $query, string $state) => match (NoticeState::from($state)) {
                    NoticeState::Draft => $query->whereNull('published_at'),
                    NoticeState::Scheduled => $query->where('published_at', '>', now()),
                    NoticeState::Live => $query->live(),
                    NoticeState::Expired => $query->whereNotNull('published_at')->where('expires_at', '<=', now()),
                }),
            Filter::make('target_audience', __('Audience'))->options(NoticeAudience::class),
        ];
    }

    public function actions(): array
    {
        $publisher = fn () => app(NoticePublisher::class);

        return [
            Action::make('publish', __('Publish now'))
                ->icon('ri-send-plane-line')
                ->color('success')
                ->can('notices.edit')
                ->visible(fn (Notice $notice) => in_array($notice->state(), [NoticeState::Draft, NoticeState::Scheduled], true))
                ->confirm(__('It appears on the notice board and everyone it is for gets a notification.'), __('Publish this notice now?'))
                ->handle(fn (Notice $notice) => $publisher()->publish($notice))
                ->success(__('Notice published.')),

            Action::make('unpublish', __('Unpublish'))
                ->icon('ri-eye-off-line')
                ->color('warning')
                ->can('notices.edit')
                ->visible(fn (Notice $notice) => in_array($notice->state(), [NoticeState::Live, NoticeState::Scheduled], true))
                ->confirm(__('It is taken off the notice board and becomes a draft again.'), __('Unpublish this notice?'))
                ->handle(fn (Notice $notice) => $publisher()->unpublish($notice))
                ->success(__('Notice unpublished.')),

            Action::make('pin', __('Pin or unpin'))
                ->icon('ri-pushpin-2-line')
                ->can('notices.edit')
                ->handle(fn (Notice $notice) => $notice->update(['is_pinned' => ! $notice->is_pinned]))
                ->success(fn (Notice $notice) => $notice->is_pinned ? __('Pinned to the top of the board.') : __('Unpinned.')),

            Action::edit('forms.notice', 'notice', 'notices.edit')
                ->modal('forms.notice', fn (Notice $notice) => ['notice' => $notice->id], 'lg'),

            Action::delete('notices.delete')
                ->success(fn (Notice $notice) => __('Notice deleted.')),
        ];
    }

    public function bulkActions(): array
    {
        return [BulkAction::delete('notices.delete')];
    }

    public function searchPlaceholder(): string
    {
        return __('Search notices…');
    }

    public function emptyTitle(): string
    {
        return __('No notices yet');
    }

    public function emptyMessage(): ?string
    {
        return __('Post announcements for everyone, staff, students or parents.');
    }
}

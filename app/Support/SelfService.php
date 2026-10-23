<?php

namespace App\Support;

use App\Models\ParentProfile;
use App\Models\StudentProfile;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Whose records the student and parent portals show.
 *
 * In the student portal it is the signed-in student. In the parent portal it
 * is the child picked in the header's child switcher (kept in the session,
 * per school), or the first child. A link can pick a child with ?child=ID,
 * which notifications use.
 */
class SelfService
{
    // Per request, so a test's second request doesn't see the first one's student.
    private const MEMO = 'self-service.student';

    /**
     * The student the current self-service page is about, or null (no
     * student record, no children linked, or not a self-service portal).
     */
    public static function student(): ?StudentProfile
    {
        if (request()->attributes->has(self::MEMO)) {
            return request()->attributes->get(self::MEMO);
        }

        $user = auth()->user();
        $student = null;

        if ($user && Portal::current() === 'student') {
            $student = $user->studentProfile()->with(['user', 'schoolClass', 'section'])->first();
        } elseif ($user && Portal::current() === 'parent') {
            $children = static::children($user);

            if (($requested = request()->query('child')) && $children->contains('id', (int) $requested)) {
                static::remember((int) $requested);
            }

            $student = $children->firstWhere('id', session(static::sessionKey())) ?? $children->first();
        }

        request()->attributes->set(self::MEMO, $student);

        return $student;
    }

    /**
     * The student, or a 404-style stop for pages that can't work without one.
     */
    public static function studentOrFail(): StudentProfile
    {
        $student = static::student();

        abort_unless($student, 404);

        return $student;
    }

    /**
     * A parent's linked children, by name.
     *
     * @return Collection<int, StudentProfile>
     */
    public static function children(User $user): Collection
    {
        $parent = $user->parentProfile()->first();

        if (! $parent instanceof ParentProfile) {
            return collect();
        }

        return $parent->children()->with(['user', 'schoolClass', 'section'])->get()
            ->sortBy(fn (StudentProfile $child) => mb_strtolower((string) $child->user?->name))
            ->values();
    }

    /**
     * Switch the parent portal to another child. False when it isn't theirs.
     */
    public static function select(User $user, int $studentId): bool
    {
        if (! static::children($user)->contains('id', $studentId)) {
            return false;
        }

        static::remember($studentId);
        static::flush();

        return true;
    }

    /**
     * Whether the user may see this student's records in a self-service
     * portal: it is them, or one of their children.
     */
    public static function owns(User $user, int $studentId): bool
    {
        if ($user->studentProfile()->whereKey($studentId)->exists()) {
            return true;
        }

        return (bool) $user->parentProfile()->first()?->children()->whereKey($studentId)->exists();
    }

    /**
     * The URL of a self-service page for one student: the student's own
     * portal, or the parent portal switched to that child.
     *
     * @param  array<string, mixed>  $parameters
     */
    public static function url(string $portal, string $page, StudentProfile|int $student, array $parameters = []): string
    {
        $id = $student instanceof StudentProfile ? $student->id : $student;

        return route("{$portal}.{$page}", $portal === 'parent' ? [...$parameters, 'child' => $id] : $parameters);
    }

    /**
     * Breadcrumbs of a self-service page: the portal, then (for parents)
     * the child it shows, then the page and any trail after it.
     *
     * @param  array<string, string|null>  $trail  label => url
     * @return array<string, string|null>
     */
    public static function breadcrumbs(array $trail): array
    {
        $portal = Portal::current() ?? 'student';
        $crumbs = [Portal::label($portal) => route("{$portal}.dashboard")];

        if ($portal === 'parent' && ($name = static::student()?->user?->name)) {
            $crumbs[$name] = route('parent.dashboard');
        }

        return $crumbs + $trail;
    }

    /**
     * Forget the memoised student (tests, and after a switch).
     */
    public static function flush(): void
    {
        request()->attributes->remove(self::MEMO);
    }

    private static function remember(int $studentId): void
    {
        session()->put(static::sessionKey(), $studentId);
    }

    private static function sessionKey(): string
    {
        return 'self-service.child.'.(tenant()?->id ?? 0);
    }
}

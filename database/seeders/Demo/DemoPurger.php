<?php

namespace Database\Seeders\Demo;

use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Removes a demo school and everything in it for good, so the demo can be
 * built again from scratch. Refuses any school that isn't a demo school:
 * real schools are only ever suspended or soft-deleted.
 */
final class DemoPurger
{
    public function purge(School $school): void
    {
        if (! in_array($school->slug, DemoSchools::slugs(), true)) {
            throw new InvalidArgumentException("{$school->slug} is not a demo school.");
        }

        $id = $school->id;
        $userIds = DB::table('users')->where('school_id', $id)->pluck('id');
        $roleIds = DB::table('roles')->where('school_id', $id)->pluck('id');

        // Every table holding the school's rows, found from the schema so new
        // modules are covered without touching this list.
        $tables = collect(Schema::getTables(DB::connection()->getDatabaseName()))
            ->pluck('name')
            ->reject(fn (string $table) => $table === 'schools')
            ->filter(fn (string $table) => Schema::hasColumn($table, 'school_id'))
            ->values();

        DB::transaction(function () use ($school, $id, $userIds, $roleIds, $tables) {
            Schema::withoutForeignKeyConstraints(function () use ($school, $id, $userIds, $roleIds, $tables) {
                foreach ($tables as $table) {
                    DB::table($table)->where('school_id', $id)->delete();
                }

                DB::table('role_has_permissions')->whereIn('role_id', $roleIds)->delete();

                foreach ($userIds->chunk(1000) as $chunk) {
                    DB::table('notifications')->where('notifiable_type', (new User)->getMorphClass())->whereIn('notifiable_id', $chunk)->delete();
                    DB::table('sessions')->whereIn('user_id', $chunk)->delete();
                }

                // Console entries about the school itself (provisioned, payments...).
                DB::table('activity_log')->where('subject_type', $school->getMorphClass())->where('subject_id', $id)->delete();

                DB::table('schools')->where('id', $id)->delete();
            });
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->deleteFiles();
    }

    /**
     * Uploads live under schools/{id}/ on the public and private disks. Removes
     * the folders of every school that no longer exists: the one just purged,
     * and any left behind by earlier rebuilds. Soft-deleted schools keep theirs.
     */
    public function deleteFiles(): void
    {
        $schools = DB::table('schools')->pluck('id')->map(fn ($id) => (string) $id)->all();

        foreach (['public', 'local'] as $disk) {
            foreach (Storage::disk($disk)->directories('schools') as $directory) {
                $id = basename($directory);

                if (ctype_digit($id) && ! in_array($id, $schools, true)) {
                    Storage::disk($disk)->deleteDirectory($directory);
                }
            }
        }
    }
}

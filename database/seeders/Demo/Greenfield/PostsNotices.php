<?php

namespace Database\Seeders\Demo\Greenfield;

use App\Enums\NoticeAudience;
use App\Models\Notice;
use App\Services\Notices\NoticePublisher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * The notice board through the term: for everyone, staff, teachers,
 * students, parents and one class; pinned, expired, scheduled and draft.
 */
trait PostsNotices
{
    private function planNotices(): void
    {
        $midEnd = $this->addSchoolDays($this->today, -5);
        $midStart = $this->addSchoolDays($midEnd, -4);
        $testStart = $this->addSchoolDays($this->yearStart, 18);
        $format = fn (CarbonImmutable $day) => $day->format('l j F');
        // The coming Saturday, at least two days off.
        $meeting = $this->today->addDays(2)->next(CarbonImmutable::SATURDAY);

        $notices = [
            [
                'Welcome to the new academic year',
                "Dear parents and students,\n\nWelcome back! Classes start on {$format($this->yearStart)} at 8:00 a.m. at both campuses. School buses run from the first day; route timings are on the Transport & Hostel page of the parent portal.\n\nWe look forward to a wonderful year together.\n\nAsma Rehman\nPrincipal",
                NoticeAudience::All, null, false, $this->on($this->yearStart->subDays(3), '10:00'), null,
            ],
            [
                'Prep parents: orientation morning',
                "Parents of our new Prep students are invited to an orientation in the Junior Campus activity room on {$format($this->addSchoolDays($this->yearStart, 3))} at 9:00 a.m. Meet the class teachers and see a normal school morning.",
                NoticeAudience::Parents, 'PREP', false, $this->on($this->yearStart->addDay(), '09:00'), $this->on($this->addSchoolDays($this->yearStart, 4), '00:00'),
            ],
            [
                'Uniform and school bag guidelines',
                "Please make sure students come in full uniform with their name on blazers and bags. Bags should not weigh more than a tenth of the child's weight: keep only the day's books.\n\nThe uniform shop at the Main Campus is open 8:00 a.m. to 2:00 p.m.",
                NoticeAudience::Students, null, false, $this->on($this->addSchoolDays($this->yearStart, 2), '11:30'), null,
            ],
            [
                'First Monthly Test schedule',
                "The First Monthly Test runs over three mornings from {$format($testStart)}: English and Urdu on the first day, then Mathematics, then Science (General Knowledge for Prep). Each test is 25 marks. There is no change to school timings.",
                NoticeAudience::Students, null, false, $this->on($this->addSchoolDays($testStart, -6), '12:00'), $this->on($this->addSchoolDays($testStart, 3), '00:00'),
            ],
            [
                'Mid-term examination date sheet',
                "The mid-term examinations run from {$format($midStart)} to {$format($midEnd)}. Papers start at 8:30 a.m.; on days with two papers, the second starts at 11:00 a.m. School closes at 1:00 p.m. as usual and buses run on their normal timings.\n\nThe full date sheet for each class is on the Exams page. Report cards will be shared on the portal after the results are published.",
                NoticeAudience::All, null, true, $this->on($this->addSchoolDays($midStart, -10), '13:00'), $this->on($midEnd->addDay(), '00:00'),
            ],
            [
                'Teachers: mid-term marks deadline',
                "Please enter mid-term marks in the gradebook within three working days of each paper, and class teachers please add a remark for every student. Results will be published once all sheets are complete.",
                NoticeAudience::Teachers, null, false, $this->on($midEnd, '14:00'), $this->on($this->addSchoolDays($midEnd, 5), '00:00'),
            ],
            [
                'Staff meeting: second half of term',
                "There will be a staff meeting in the auditorium on {$format($this->addSchoolDays($this->today, 2))} at 1:45 p.m. Agenda: mid-term results, the science fair and winter timings.",
                NoticeAudience::Staff, null, false, $this->on($this->addSchoolDays($this->today, -2), '15:10'), null,
            ],
            [
                'Parent–teacher meeting on '.$meeting->format('l j F'),
                "Mid-term report cards are now on the parent portal. Class teachers will meet parents on {$format($meeting)} from 9:00 a.m. to 12:00 noon in each class's room. Please bring any questions about your child's results.",
                NoticeAudience::Parents, null, true, $this->on($this->addSchoolDays($this->today, -1), '13:30'), $this->on($meeting->addDay(), '00:00'),
            ],
            [
                'Grade 7 science fair: register your project',
                "Grade 7 students can register a project for the inter-house science fair with Ms Kiran Shahzad by the end of next week. Projects can be done in pairs. Materials are provided by the school.",
                NoticeAudience::Students, 'G7', false, $this->on($this->addSchoolDays($this->today, -1), '10:00'), null,
            ],
        ];

        foreach ($notices as [$title, $body, $audience, $class, $pinned, $publishAt, $expiresAt]) {
            $this->at($publishAt->subMinutes(20), fn () => $this->postNotice($title, $body, $audience, $class, $pinned, $expiresAt, publishAt: null));
        }

        // Written now, going up later.
        $this->at($this->on($this->addSchoolDays($this->today, -1), '11:00'), fn () => $this->postNotice(
            'Winter uniform from next month',
            "Students switch to winter uniform (navy sweater or blazer) from the first of next month. The uniform shop has stock in all sizes.",
            NoticeAudience::All, null, false, null, publishAt: $this->on($this->addSchoolDays($this->today, 3), '08:00'),
        ));

        $this->at($this->on($this->addSchoolDays($this->today, -1), '11:20'), fn () => $this->postNotice(
            'Annual sports day',
            "Draft: date and events to be confirmed with the heads of department.",
            NoticeAudience::All, null, false, null, publishAt: false,
        ));
    }

    /**
     * Create a notice and publish it now (publishAt null), at a later time,
     * or not at all (false: a draft).
     */
    private function postNotice(string $title, string $body, NoticeAudience $audience, ?string $class, bool $pinned, ?CarbonImmutable $expiresAt, CarbonImmutable|false|null $publishAt): void
    {
        $this->as($this->admin, function () use ($title, $body, $audience, $class, $pinned, $expiresAt, $publishAt) {
            $notice = Notice::query()->create([
                'title' => $title,
                'body' => $body,
                'target_audience' => $audience,
                'class_id' => $class ? $this->classes[$class]->id : null,
                'is_pinned' => $pinned,
                'expires_at' => $expiresAt?->utc(),
                'created_by' => $this->admin->id,
            ]);

            if ($publishAt === false) {
                return;
            }

            app(NoticePublisher::class)->publish($notice, $publishAt ? Carbon::instance($publishAt)->utc() : null);
        });
    }
}

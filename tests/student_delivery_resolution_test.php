<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_sitsgradepush;

use advanced_testcase;
use local_sitsgradepush\delivery\db_student_delivery_store;
use local_sitsgradepush\delivery\delivery_service;
use local_sitsgradepush\delivery\idelivery_source;
use local_sitsgradepush\delivery\imembership_checker;
use local_sitsgradepush\delivery\models\delivery;
use local_sitsgradepush\delivery\models\delivery_key;
use local_sitsgradepush\delivery\istudent_delivery_store;

/**
 * Tests for durable student to module delivery resolution.
 *
 * The resolution policy and caching are now isolated behind injectable ports, so they are tested
 * here with in-memory fakes (no SITS, no portico, no database). The durable database store is
 * tested separately against the real database.
 *
 * @package    local_sitsgradepush
 * @category   test
 * @coversDefaultClass \local_sitsgradepush\delivery\delivery_service
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
final class student_delivery_resolution_test extends advanced_testcase {
    /**
     * A single-delivery course resolves without querying membership, and persists the result.
     *
     * @covers \local_sitsgradepush\delivery\live_delivery_resolver::resolve
     * @return void
     */
    public function test_single_delivery_resolves_without_membership_check(): void {
        $key = new delivery_key('LAWS0024', 'A6U', 'T1/2', '2025');
        $source = $this->stub_source([new delivery($key, 'Law', [])]);
        $store = $this->memory_store();

        // A single delivery is resolved without ever querying membership.
        $checker = $this->createMock(imembership_checker::class);
        $checker->expects($this->never())->method('is_member');

        $service = new delivery_service($source, $store, $checker);
        $resolved = $service->resolve_student_deliveries(11, 22);

        $this->assertCount(1, $resolved);
        $this->assertSame('LAWS0024-A6U-T1/2-2025', $resolved[0]->key());
        $this->assertCount(1, $store->saved);
    }

    /**
     * A multi-delivery course resolves only the deliveries the student belongs to.
     *
     * @covers \local_sitsgradepush\delivery\live_delivery_resolver::resolve
     * @return void
     */
    public function test_multi_delivery_resolves_via_membership(): void {
        $keya = new delivery_key('LAWS0024', 'A6U', 'T1/2', '2025');
        $keyb = new delivery_key('LAWS0099', 'A7P', 'T1/2', '2025');
        $source = $this->stub_source([
            new delivery($keya, 'Law A', []),
            new delivery($keyb, 'Law B', []),
        ]);
        $store = $this->memory_store();
        // The student belongs only to delivery B.
        $checker = $this->stub_checker([$keyb->key()]);

        $service = new delivery_service($source, $store, $checker);
        $resolved = $service->resolve_student_deliveries(11, 22);

        $this->assertCount(1, $resolved);
        $this->assertSame($keyb->key(), $resolved[0]->key());
        $this->assertCount(1, $store->saved);
        $this->assertSame($keyb->key(), $store->saved[0]->key());
    }

    /**
     * An unresolved student returns an empty array and persists nothing.
     *
     * @covers \local_sitsgradepush\delivery\caching_delivery_resolver::resolve
     * @return void
     */
    public function test_unresolved_returns_empty_and_persists_nothing(): void {
        // The empty-result path writes a negative marker to MUC, so isolate cache state.
        $this->resetAfterTest();

        $source = $this->stub_source([
            new delivery(new delivery_key('LAWS0024', 'A6U', 'T1/2', '2025'), 'Law A', []),
            new delivery(new delivery_key('LAWS0099', 'A7P', 'T1/2', '2025'), 'Law B', []),
        ]);
        $store = $this->memory_store();
        // The student belongs to no delivery.
        $checker = $this->stub_checker([]);

        $service = new delivery_service($source, $store, $checker);
        $resolved = $service->resolve_student_deliveries(11, 22);

        $this->assertSame([], $resolved);
        $this->assertSame([], $store->saved);
    }

    /**
     * An empty result is negatively cached, so a repeat visit skips the live resolver and its API.
     *
     * @covers \local_sitsgradepush\delivery\caching_delivery_resolver::resolve
     * @return void
     */
    public function test_negative_result_cached_skips_inner_on_repeat(): void {
        $this->resetAfterTest();

        // The source is queried exactly once across both visits: the second is a negative-cache hit.
        $source = $this->createMock(idelivery_source::class);
        $source->expects($this->once())
            ->method('get_course_deliveries')
            ->willReturn([
                new delivery(new delivery_key('LAWS0024', 'A6U', 'T1/2', '2025'), 'Law A', []),
                new delivery(new delivery_key('LAWS0099', 'A7P', 'T1/2', '2025'), 'Law B', []),
            ]);
        // The student belongs to no delivery.
        $checker = $this->stub_checker([]);

        $service = new delivery_service($source, $this->memory_store(), $checker);

        // First visit finds nothing stored, runs the live resolver and caches the empty result;
        // the second visit is served straight from the negative cache, so the live resolver does
        // not run again. Both visits return empty, which these assertions confirm. The "runs only
        // once" guarantee itself is enforced by the expects($this->once()) expectation above:
        // PHPUnit fails the test at teardown if the second visit re-queried the source.
        $this->assertSame([], $service->resolve_student_deliveries(11, 22));
        $this->assertSame([], $service->resolve_student_deliveries(11, 22));
    }

    /**
     * A non-empty result is not negatively cached, so it always falls through to the live resolver.
     *
     * @covers \local_sitsgradepush\delivery\caching_delivery_resolver::resolve
     * @return void
     */
    public function test_positive_result_not_negatively_cached(): void {
        $this->resetAfterTest();

        $keyb = new delivery_key('LAWS0099', 'A7P', 'T1/2', '2025');
        // A non-empty result is never negatively cached, so both visits reach the live resolver.
        $source = $this->createMock(idelivery_source::class);
        $source->expects($this->exactly(2))
            ->method('get_course_deliveries')
            ->willReturn([
                new delivery(new delivery_key('LAWS0024', 'A6U', 'T1/2', '2025'), 'Law A', []),
                new delivery($keyb, 'Law B', []),
            ]);
        // The student belongs only to delivery B.
        $checker = $this->stub_checker([$keyb->key()]);

        $service = new delivery_service($source, $this->memory_store(), $checker);

        $this->assertSame($keyb->key(), $service->resolve_student_deliveries(11, 22)[0]->key());
        $this->assertSame($keyb->key(), $service->resolve_student_deliveries(11, 22)[0]->key());
    }

    /**
     * A stored result is served without recomputing from the source.
     *
     * @covers \local_sitsgradepush\delivery\caching_delivery_resolver::resolve
     * @return void
     */
    public function test_stored_result_served_without_recomputing(): void {
        $key = new delivery_key('LAWS0024', 'A6U', 'T1/2', '2025');
        $store = $this->memory_store([$key]);

        // On a cache hit neither the source nor the membership checker is consulted.
        $source = $this->createMock(idelivery_source::class);
        $source->expects($this->never())->method('get_course_deliveries');
        $checker = $this->createMock(imembership_checker::class);
        $checker->expects($this->never())->method('is_member');

        $service = new delivery_service($source, $store, $checker);
        $resolved = $service->resolve_student_deliveries(11, 22);

        $this->assertCount(1, $resolved);
        $this->assertSame($key->key(), $resolved[0]->key());
        // Nothing new persisted on a cache hit.
        $this->assertSame([], $store->saved);
    }

    /**
     * The database store persists, deduplicates and reads back delivery keys.
     *
     * @covers \local_sitsgradepush\delivery\db_student_delivery_store
     * @return void
     */
    public function test_db_store_persists_dedupes_and_finds(): void {
        global $DB;

        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $store = new db_student_delivery_store();
        $key = new delivery_key('LAWS0024', 'A6U', 'T1/2', '2025');
        $store->save($course->id, $student->id, $key);
        // Saving the same key again is a no-op.
        $store->save($course->id, $student->id, $key);

        $found = $store->find($course->id, $student->id);
        $this->assertCount(1, $found);
        $this->assertSame('LAWS0024', $found[0]->modcode);
        $this->assertSame('A6U', $found[0]->modocc);
        $this->assertSame('2025', $found[0]->academicyear);
        $this->assertSame('T1/2', $found[0]->periodslotcode);
        $this->assertSame(1, $DB->count_records(db_student_delivery_store::TABLE, [
            'courseid' => $course->id,
            'userid' => $student->id,
        ]));
    }

    /**
     * The delivery key builds a stable string and rebuilds from a stored record.
     *
     * @covers \local_sitsgradepush\delivery\delivery_key
     * @return void
     */
    public function test_delivery_key_builds_stable_string_and_from_record(): void {
        $key = new delivery_key('LAWS0024', 'A6U', 'T1/2', '2025');
        $this->assertSame('LAWS0024-A6U-T1/2-2025', $key->key());

        $rebuilt = delivery_key::from_record((object) [
            'modcode' => 'LAWS0024',
            'modocc' => 'A6U',
            'academicyear' => '2025',
            'periodslotcode' => 'T1/2',
        ]);
        $this->assertSame($key->key(), $rebuilt->key());
    }

    /**
     * Build a source stub returning the given deliveries for any course.
     *
     * @param delivery[] $deliveries
     * @return idelivery_source
     */
    private function stub_source(array $deliveries): idelivery_source {
        $source = $this->createMock(idelivery_source::class);
        $source->method('get_course_deliveries')->willReturn($deliveries);
        return $source;
    }

    /**
     * Build a membership checker stub reporting membership for the given delivery keys only.
     *
     * @param string[] $memberkeys
     * @return imembership_checker
     */
    private function stub_checker(array $memberkeys): imembership_checker {
        $checker = $this->createMock(imembership_checker::class);
        $checker->method('is_member')->willReturnCallback(
            fn(int $userid, delivery $delivery): bool => in_array($delivery->key->key(), $memberkeys, true)
        );
        return $checker;
    }

    /**
     * Build an in-memory store, optionally preloaded with stored keys.
     *
     * @param delivery_key[] $preloaded
     * @return istudent_delivery_store
     */
    private function memory_store(array $preloaded = []): istudent_delivery_store {
        return new class ($preloaded) implements istudent_delivery_store {
            /** @var delivery_key[] Keys saved during the test. */
            public array $saved = [];

            /** @var delivery_key[] Keys served as already stored. */
            private array $preloaded;

            /**
             * Constructor.
             *
             * @param delivery_key[] $preloaded
             */
            public function __construct(array $preloaded) {
                $this->preloaded = $preloaded;
            }

            /**
             * Get the preloaded keys.
             *
             * @param int $courseid
             * @param int $userid
             * @return delivery_key[]
             */
            public function find(int $courseid, int $userid): array {
                return $this->preloaded;
            }

            /**
             * Record a saved key.
             *
             * @param int $courseid
             * @param int $userid
             * @param delivery_key $key
             * @return void
             */
            public function save(int $courseid, int $userid, delivery_key $key): void {
                $this->saved[] = $key;
            }
        };
    }
}

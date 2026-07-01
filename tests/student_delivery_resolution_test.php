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
use local_sitsgradepush\delivery\models\delivery;
use local_sitsgradepush\delivery\models\delivery_key;
use local_sitsgradepush\delivery\portico_delivery_source;
use local_sitsgradepush\delivery\sits_membership_checker;

/**
 * Tests for durable student to module delivery resolution.
 *
 * The delivery source and membership checker are stubbed (no SITS, no portico), while the durable
 * store is the real database-backed store, so resolution, caching and persistence are exercised
 * together against the test database.
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
     * A single-delivery course resolves without querying membership, but does not persist the result.
     *
     * @covers \local_sitsgradepush\delivery\student_delivery_resolver::resolve
     * @return void
     */
    public function test_single_delivery_resolves_without_membership_check(): void {
        $this->resetAfterTest();

        $key = new delivery_key('LAWS0024', 'A6U', 'T1/2', '2025');
        $source = $this->stub_source([new delivery($key, 'Law', [])]);
        $store = new db_student_delivery_store();

        // A single delivery is resolved without ever querying membership.
        $checker = $this->createMock(sits_membership_checker::class);
        $checker->expects($this->never())->method('is_member');

        $service = new delivery_service($source, $store, $checker);
        $resolved = $service->resolve_student_deliveries(11, 22);

        $this->assertCount(1, $resolved);
        $this->assertSame('LAWS0024-A6U-T1/2-2025', $resolved[0]->key());
        // The assumed single delivery is not persisted: only API-confirmed memberships are stored.
        $this->assertSame([], $store->find(11, 22));
    }

    /**
     * A multi-delivery course resolves only the deliveries the student belongs to.
     *
     * @covers \local_sitsgradepush\delivery\student_delivery_resolver::resolve
     * @return void
     */
    public function test_multi_delivery_resolves_via_membership(): void {
        $this->resetAfterTest();

        $keya = new delivery_key('LAWS0024', 'A6U', 'T1/2', '2025');
        $keyb = new delivery_key('LAWS0099', 'A7P', 'T1/2', '2025');
        $source = $this->stub_source([
            new delivery($keya, 'Law A', []),
            new delivery($keyb, 'Law B', []),
        ]);
        $store = new db_student_delivery_store();
        // The student belongs only to delivery B.
        $checker = $this->stub_checker([$keyb->key()]);

        $service = new delivery_service($source, $store, $checker);
        $resolved = $service->resolve_student_deliveries(11, 22);

        $this->assertCount(1, $resolved);
        $this->assertSame($keyb->key(), $resolved[0]->key());
        // Only the delivery the student belongs to is persisted.
        $stored = $store->find(11, 22);
        $this->assertCount(1, $stored);
        $this->assertSame($keyb->key(), $stored[0]->key());
    }

    /**
     * An unresolved student returns an empty array and persists nothing.
     *
     * @covers \local_sitsgradepush\delivery\student_delivery_resolver::resolve
     * @return void
     */
    public function test_unresolved_returns_empty_and_persists_nothing(): void {
        // The empty-result path writes a negative marker to MUC, so isolate cache state.
        $this->resetAfterTest();

        $source = $this->stub_source([
            new delivery(new delivery_key('LAWS0024', 'A6U', 'T1/2', '2025'), 'Law A', []),
            new delivery(new delivery_key('LAWS0099', 'A7P', 'T1/2', '2025'), 'Law B', []),
        ]);
        $store = new db_student_delivery_store();
        // The student belongs to no delivery.
        $checker = $this->stub_checker([]);

        $service = new delivery_service($source, $store, $checker);
        $resolved = $service->resolve_student_deliveries(11, 22);

        $this->assertSame([], $resolved);
        $this->assertSame([], $store->find(11, 22));
    }

    /**
     * An empty result is negatively cached, so a repeat visit skips the live resolver and its API.
     *
     * @covers \local_sitsgradepush\delivery\student_delivery_resolver::resolve
     * @return void
     */
    public function test_negative_result_cached_skips_live_resolve_on_repeat(): void {
        $this->resetAfterTest();

        // The source is queried exactly once across both visits: the second is a negative-cache hit.
        $source = $this->createMock(portico_delivery_source::class);
        $source->expects($this->once())
            ->method('get_course_deliveries')
            ->willReturn([
                new delivery(new delivery_key('LAWS0024', 'A6U', 'T1/2', '2025'), 'Law A', []),
                new delivery(new delivery_key('LAWS0099', 'A7P', 'T1/2', '2025'), 'Law B', []),
            ]);
        // The student belongs to no delivery.
        $checker = $this->stub_checker([]);

        $service = new delivery_service($source, new db_student_delivery_store(), $checker);

        // First visit finds nothing stored, runs the live resolver and caches the empty result;
        // the second visit is served straight from the negative cache, so the live resolver does
        // not run again. Both visits return empty, which these assertions confirm. The "runs only
        // once" guarantee itself is enforced by the expects($this->once()) expectation above:
        // PHPUnit fails the test at teardown if the second visit re-queried the source.
        $this->assertSame([], $service->resolve_student_deliveries(11, 22));
        $this->assertSame([], $service->resolve_student_deliveries(11, 22));
    }

    /**
     * A resolved result is persisted, so a repeat visit is served from the durable store.
     *
     * @covers \local_sitsgradepush\delivery\student_delivery_resolver::resolve
     * @return void
     */
    public function test_positive_result_served_from_store_on_repeat(): void {
        $this->resetAfterTest();

        $keyb = new delivery_key('LAWS0099', 'A7P', 'T1/2', '2025');
        // A resolved (non-empty) result is persisted, so the second visit is served from the durable
        // store and the live source is consulted only once.
        $source = $this->createMock(portico_delivery_source::class);
        $source->expects($this->once())
            ->method('get_course_deliveries')
            ->willReturn([
                new delivery(new delivery_key('LAWS0024', 'A6U', 'T1/2', '2025'), 'Law A', []),
                new delivery($keyb, 'Law B', []),
            ]);
        // The student belongs only to delivery B.
        $checker = $this->stub_checker([$keyb->key()]);

        $service = new delivery_service($source, new db_student_delivery_store(), $checker);

        $this->assertSame($keyb->key(), $service->resolve_student_deliveries(11, 22)[0]->key());
        $this->assertSame($keyb->key(), $service->resolve_student_deliveries(11, 22)[0]->key());
    }

    /**
     * When the service is disabled, both entry points return empty without touching the source.
     *
     * @covers ::is_enabled
     * @covers ::get_course_deliveries
     * @covers ::resolve_student_deliveries
     * @return void
     */
    public function test_disabled_service_returns_empty_without_querying_source(): void {
        $this->resetAfterTest();

        set_config('delivery_resolution_enabled', '0', 'local_sitsgradepush');

        // Neither the source nor the membership checker is consulted while disabled.
        $source = $this->createMock(portico_delivery_source::class);
        $source->expects($this->never())->method('get_course_deliveries');
        $checker = $this->createMock(sits_membership_checker::class);
        $checker->expects($this->never())->method('is_member');

        $service = new delivery_service($source, new db_student_delivery_store(), $checker);

        $this->assertFalse(delivery_service::is_enabled());
        $this->assertSame([], $service->get_course_deliveries(11));
        $this->assertSame([], $service->resolve_student_deliveries(11, 22));
    }

    /**
     * Build a source stub returning the given deliveries for any course.
     *
     * @param delivery[] $deliveries
     * @return portico_delivery_source
     */
    private function stub_source(array $deliveries): portico_delivery_source {
        $source = $this->createMock(portico_delivery_source::class);
        $source->method('get_course_deliveries')->willReturn($deliveries);
        return $source;
    }

    /**
     * Build a membership checker stub reporting membership for the given delivery keys only.
     *
     * @param string[] $memberkeys
     * @return sits_membership_checker
     */
    private function stub_checker(array $memberkeys): sits_membership_checker {
        $checker = $this->createMock(sits_membership_checker::class);
        $checker->method('is_member')->willReturnCallback(
            fn(int $userid, delivery $delivery): bool => in_array($delivery->key->key(), $memberkeys, true)
        );
        return $checker;
    }
}

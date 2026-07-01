<?php
// This file is part of Moodle - https://moodle.org/
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
// along with Moodle.  If not, see <https://www.gnu.org/licenses/>.

namespace local_sitsgradepush\delivery;

use local_sitsgradepush\delivery\models\delivery;
use local_sitsgradepush\delivery\models\delivery_key;

/**
 * Public entry point for sourcing and resolving SITS module deliveries.
 *
 * Composes the delivery source, durable store and membership checker into a course-level reader
 * and a cached student resolver. Callers depend on this thin facade; the wiring is an
 * implementation detail. Use {@see self::create()} for the production wiring, or the constructor
 * to inject collaborators (e.g. in tests).
 *
 * @package    local_sitsgradepush
 * @copyright  2026 onwards University College London {@link https://www.ucl.ac.uk/}
 * @license    https://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @author     Alex Yeung <k.yeung@ucl.ac.uk>
 */
class delivery_service {
    /** @var istudent_delivery_resolver Cached resolver composed from the injected collaborators. */
    private readonly istudent_delivery_resolver $resolver;

    /**
     * Constructor.
     *
     * @param idelivery_source $source Source of the course's deliveries.
     * @param istudent_delivery_store $store Durable store for resolved mappings.
     * @param imembership_checker $membership Tests delivery membership.
     */
    public function __construct(
        /** @var idelivery_source Source of the course's deliveries. */
        private readonly idelivery_source $source,
        istudent_delivery_store $store,
        imembership_checker $membership,
    ) {
        $this->resolver = new caching_delivery_resolver(
            new live_delivery_resolver($source, $membership),
            $store,
        );
    }

    /**
     * Build the service with the production wiring.
     *
     * @return self
     */
    public static function create(): self {
        return new self(
            new portico_delivery_source(),
            new db_student_delivery_store(),
            new sits_membership_checker(),
        );
    }

    /**
     * Get the course's SITS module deliveries, each with its resolved components.
     *
     * @param int $courseid
     * @return delivery[]
     */
    public function get_course_deliveries(int $courseid): array {
        return $this->source->get_course_deliveries($courseid);
    }

    /**
     * Resolve the deliveries a student belongs to, persisting newly resolved mappings.
     *
     * @param int $courseid
     * @param int $userid
     * @return delivery_key[]
     */
    public function resolve_student_deliveries(int $courseid, int $userid): array {
        return $this->resolver->resolve($courseid, $userid);
    }
}

<?php

namespace Certigniter\CertificateRenderer\Tests\Unit;

use Certigniter\CertificateRenderer\Data\CertificateProject;
use Certigniter\CertificateRenderer\Data\DesignElement;
use Certigniter\CertificateRenderer\Support\GroupComposer;
use PHPUnit\Framework\TestCase;

class GroupComposerTest extends TestCase
{
    private function project(array $elements): CertificateProject
    {
        return new CertificateProject(
            id: 'p', title: 'Test', width: 200, height: 150, unit: 'mm', elements: $elements,
        );
    }

    public function test_a_90_degree_group_rotation_swings_its_child_around_the_group_center(): void
    {
        // Group spans (0,0)-(40,20), center (20,10). Child spans (25,5)-(35,15),
        // center (30,10) - 10mm to the right of the group's center.
        $group = new DesignElement(
            id: 'g', type: 'group', x: 0, y: 0, width: 40, height: 20,
            properties: ['rotation' => 90.0], childrenIds: ['c'],
        );
        $child = new DesignElement(id: 'c', type: 'shape', x: 25, y: 5, width: 10, height: 10);

        [$resolved] = GroupComposer::resolve($this->project([$group, $child]));

        // A 90 degree rotation swings (+10, 0) relative to center to (0, +10):
        // new center (20, 20), so top-left of the 10x10 box is (15, 15).
        $this->assertEqualsWithDelta(15.0, $resolved->x, 1e-9);
        $this->assertEqualsWithDelta(15.0, $resolved->y, 1e-9);
    }

    public function test_rotation_and_opacity_accumulate_from_both_group_and_child(): void
    {
        $group = new DesignElement(
            id: 'g', type: 'group', x: 0, y: 0, width: 40, height: 20,
            properties: ['rotation' => 30.0, 'opacity' => 0.5], childrenIds: ['c'],
        );
        $child = new DesignElement(
            id: 'c', type: 'shape', x: 25, y: 5, width: 10, height: 10,
            properties: ['rotation' => 15.0, 'opacity' => 0.8],
        );

        [$resolved] = GroupComposer::resolve($this->project([$group, $child]));

        $this->assertEqualsWithDelta(45.0, $resolved->rotation(), 1e-9);
        $this->assertEqualsWithDelta(0.4, $resolved->opacity(), 1e-9);
    }

    public function test_hiding_a_group_hides_its_children_regardless_of_the_childs_own_visibility(): void
    {
        $group = new DesignElement(
            id: 'g', type: 'group', x: 0, y: 0, width: 40, height: 20,
            properties: ['isVisible' => false], childrenIds: ['c'],
        );
        $child = new DesignElement(
            id: 'c', type: 'shape', x: 0, y: 0, width: 10, height: 10,
            properties: ['isVisible' => true],
        );

        $resolved = GroupComposer::resolve($this->project([$group, $child]));

        $this->assertSame([], $resolved);
    }

    public function test_compose_false_leaves_children_with_their_raw_stored_values(): void
    {
        $group = new DesignElement(
            id: 'g', type: 'group', x: 0, y: 0, width: 40, height: 20,
            properties: ['rotation' => 90.0], childrenIds: ['c'],
        );
        $child = new DesignElement(id: 'c', type: 'shape', x: 25, y: 5, width: 10, height: 10);

        [$resolved] = GroupComposer::resolve($this->project([$group, $child]), compose: false);

        $this->assertSame(25.0, $resolved->x);
        $this->assertSame(5.0, $resolved->y);
        $this->assertSame(0.0, $resolved->rotation());
    }

    public function test_group_elements_are_dropped_and_non_grouped_elements_pass_through_in_z_order(): void
    {
        $group = new DesignElement(id: 'g', type: 'group', x: 0, y: 0, width: 10, height: 10, childrenIds: ['c']);
        $child = new DesignElement(id: 'c', type: 'shape', x: 0, y: 0, width: 10, height: 10);
        $solo = new DesignElement(id: 'solo', type: 'text', x: 1, y: 2, width: 3, height: 4);

        $resolved = GroupComposer::resolve($this->project([$group, $child, $solo]));

        $this->assertSame(['c', 'solo'], array_map(fn (DesignElement $e) => $e->id, $resolved));
    }
}

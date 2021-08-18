<?php

declare(strict_types=1);

namespace Doctrine\Tests\ORM\Functional\Ticket;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Tests\OrmFunctionalTestCase;

use function assert;

/**
 * Functional tests for the Single Table Inheritance mapping strategy.
 */
class GH8926Test extends OrmFunctionalTestCase
{
    // Generated before, used as Entity IDs
    private const RANDOM_UUIDS = [
        '6a98354d-57aa-485b-8473-7acdc73ab68c',
        '6eca7f9e-730e-4ea5-bffd-30820d8b2636',
        'a7c91100-24e2-4242-be87-e85fa10644f1',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->_schemaTool->createSchema([
            $this->_em->getClassMetadata(First::class),
            $this->_em->getClassMetadata(Second::class),
            $this->_em->getClassMetadata(Third::class),
        ]);
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        $this->_schemaTool->dropSchema([
            $this->_em->getClassMetadata(First::class),
            $this->_em->getClassMetadata(Second::class),
            $this->_em->getClassMetadata(Third::class),
        ]);
    }

    public function testIssue(): void
    {
        $this->expectNotToPerformAssertions();
        [$firstId, $thirdAId, $thirdBId] = self::RANDOM_UUIDS;

        // Create First- and Second-relation
        $first  = new First($firstId);
        $second = new Second($first);
        $first->getSecond()->add($second);

        // Create Third entities, associate with Second instance
        $thirdA = new Third($thirdAId);
        $thirdB = new Third($thirdBId);
        $second->getThird()->add($thirdA);
        $second->getThird()->add($thirdB);

        // Persist everything, this works fine
        $this->_em->persist($thirdA);
        $this->_em->persist($thirdB);
        $this->_em->persist($first);
        $this->_em->flush();

        // Clear EntityManager to force a reload
        // (This won't crash if the entities are already managed)
        $this->_em->clear();

        // Load First instance from EntityManager,
        // then force loading of Second instance
        $loadedFirst = $this->_em->find(First::class, $firstId);
        assert($loadedFirst instanceof First);
        $loadedFirst->getSecond()->get(0); // <-- This will crash
    }
}

/**
 * @Entity
 */
class First
{
    /**
     * @Id
     * @Column(type="guid")
     * @var string
     */
    private $id;

    /**
     * @OneToMany(targetEntity="Second", mappedBy="first", fetch="EXTRA_LAZY", orphanRemoval="true", cascade={"all"})
     * @var Collection
     */
    private $second;

    public function __construct(string $id)
    {
        $this->id     = $id;
        $this->second = new ArrayCollection();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSecond(): Collection
    {
        return $this->second;
    }
}

/**
 * @Entity
 */
class Second
{
    /**
     * @Id
     * @ManyToOne(targetEntity="First", inversedBy="second", fetch="EAGER")
     * @JoinColumn(
     *     name="first_id",
     *     referencedColumnName="id",
     *     unique="true",
     *     nullable="false",
     *     onDelete="cascade"
     * )
     * @var First
     */
    private $first;

    /**
     * @ManyToMany(targetEntity="Third", fetch="EAGER")
     * @JoinTable(name="second_third",
     *      joinColumns={@JoinColumn(name="second_id", referencedColumnName="first_id")},
     *      inverseJoinColumns={@JoinColumn(
     *     name="third_id",
     *     referencedColumnName="id",
     *     unique=true
     * )})
     * @var Collection
     */
    private $third;

    public function __construct(First $first)
    {
        $this->first = $first;
        $this->third = new ArrayCollection();
    }

    public function getFirst(): First
    {
        return $this->first;
    }

    public function getThird(): Collection
    {
        return $this->third;
    }
}

/**
 * @Entity
 */
class Third
{
    /**
     * @Id
     * @Column(type="guid")
     * @var string
     */
    private $id;

    public function __construct(string $id)
    {
        $this->id = $id;
    }

    public function getId(): string
    {
        return $this->id;
    }
}


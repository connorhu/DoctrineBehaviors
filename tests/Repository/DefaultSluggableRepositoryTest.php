<?php

declare(strict_types=1);

namespace Knp\DoctrineBehaviors\Tests\Repository;

use Doctrine\ORM\AbstractQuery;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use Knp\DoctrineBehaviors\Contract\Entity\SluggableInterface;
use Knp\DoctrineBehaviors\Repository\DefaultSluggableRepository;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class DefaultSluggableRepositoryTest extends TestCase
{
    /**
     * @var EntityManagerInterface&MockObject
     */
    private $entityManager;

    private DefaultSluggableRepository $defaultSluggableRepository;

    protected function setUp(): void
    {
        $this->defaultSluggableRepository = new DefaultSluggableRepository(
            $this->entityManager = $this->createMock(EntityManagerInterface::class)
        );
    }

    public function testIsSlugUniqueFor(): void
    {
        $sluggable = $this->createMock(SluggableInterface::class);
        $entityClass = $sluggable::class;
        $uniqueSlug = 'foobar';

        $this->entityManager->expects(self::once())
            ->method('getClassMetadata')
            ->with($entityClass)
            ->willReturn($metadata = $this->createMock(ClassMetadata::class));

        $metadata->expects(self::once())
            ->method('getIdentifierValues')
            ->with($sluggable)
            ->willReturn([
                'id' => null,
                'slug' => 'foo',
                'id.id' => '123',
            ]);

        $queryBuilder = $this->createMock(QueryBuilder::class);
        $this->entityManager->expects(self::once())
            ->method('createQueryBuilder')
            ->willReturn($queryBuilder);

        $queryBuilder->expects(self::once())
            ->method('select')
            ->with('COUNT(e)')
            ->willReturnSelf();

        $queryBuilder->expects(self::once())
            ->method('from')
            ->with($entityClass, 'e')
            ->willReturnSelf();

        $expected = [
            1 => 'e.slug = :slug',
            2 => 'e.id.id != :id_id',
        ];
        $matcher = $this->exactly(count($expected));
        $queryBuilder
            ->expects($matcher)
            ->method('andWhere')
            ->willReturnCallback(function (string $condition) use ($matcher, $expected, $queryBuilder) {
                $callNumber = $matcher->numberOfInvocations();
                $this->assertSame($expected[$callNumber], $condition);

                return $queryBuilder;
            });

        $expected = [
            1 => ['slug', $uniqueSlug],
            2 => ['id_id', '123'],
        ];
        $matcher = self::exactly(2);
        $queryBuilder->expects($matcher)
            ->method('setParameter')
            ->willReturnCallback(function ($name, $value) use ($matcher, $expected, $queryBuilder) {
                $callNumber = $matcher->numberOfInvocations();
                $this->assertSame($name, $expected[$callNumber][0]);
                $this->assertSame($value, $expected[$callNumber][1]);

                return $queryBuilder;
            });

        $queryBuilder->expects(self::once())
            ->method('getQuery')
            ->willReturn($query = $this->createMock(AbstractQuery::class));

        $query->expects(self::once())
            ->method('getSingleScalarResult')
            ->willReturn(1);

        self::assertFalse($this->defaultSluggableRepository->isSlugUniqueFor($sluggable, $uniqueSlug));
    }
}

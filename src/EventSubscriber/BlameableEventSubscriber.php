<?php

declare(strict_types=1);

namespace Knp\DoctrineBehaviors\EventSubscriber;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Doctrine\ORM\Event\PreUpdateEventArgs;
use Doctrine\ORM\Events;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\UnitOfWork;
use Knp\DoctrineBehaviors\Contract\Entity\BlameableInterface;
use Knp\DoctrineBehaviors\Contract\Provider\UserProviderInterface;
use Knp\DoctrineBehaviors\Exception\InvalidArgumentException;

#[AsDoctrineListener(event: Events::prePersist)]
#[AsDoctrineListener(event: Events::preUpdate)]
#[AsDoctrineListener(event: Events::preRemove)]
#[AsDoctrineListener(event: Events::loadClassMetadata)]
final class BlameableEventSubscriber implements EventSubscriber
{
    use SubscribedEventsWithAttributeTrait;

    /**
     * @var string
     */
    private const DELETED_BY = 'deletedBy';

    /**
     * @var string
     */
    private const UPDATED_BY = 'updatedBy';

    /**
     * @var string
     */
    private const CREATED_BY = 'createdBy';

    public function __construct(
        private UserProviderInterface $userProvider,
        private EntityManagerInterface $entityManager,
        /**
         * @var class-string<object>|null
         */
        private ?string $blameableUserEntity = null
    ) {
    }

    /**
     * Adds metadata about how to store user, either a string or an ManyToOne association on user entity
     */
    public function loadClassMetadata(LoadClassMetadataEventArgs $loadClassMetadataEventArgs): void
    {
        $classMetadata = $loadClassMetadataEventArgs->getClassMetadata();
        if ($classMetadata->reflClass === null) {
            // Class has not yet been fully built, ignore this event
            return;
        }

        if (! is_a($classMetadata->reflClass->getName(), BlameableInterface::class, true)) {
            return;
        }

        $this->mapEntity($classMetadata);
    }

    /**
     * Stores the current user into createdBy and updatedBy properties
     */
    public function prePersist(PrePersistEventArgs $eventArgs): void
    {
        $entity = $eventArgs->getObject();
        if (! $entity instanceof BlameableInterface) {
            return;
        }

        $user = $this->userProvider->provideUser();
        // no user set → skip
        if ($user === null) {
            return;
        }

        if (! $entity->getCreatedBy()) {
            $entity->setCreatedBy($user);

            $this->getUnitOfWork()
                ->propertyChanged($entity, self::CREATED_BY, null, $user);
        }

        if (! $entity->getUpdatedBy()) {
            $entity->setUpdatedBy($user);

            $this->getUnitOfWork()
                ->propertyChanged($entity, self::UPDATED_BY, null, $user);
        }
    }

    /**
     * Stores the current user into updatedBy property
     */
    public function preUpdate(PreUpdateEventArgs $eventArgs): void
    {
        $entity = $eventArgs->getObject();
        if (! $entity instanceof BlameableInterface) {
            return;
        }

        $user = $this->userProvider->provideUser();
        if ($user === null) {
            return;
        }

        $oldValue = $entity->getUpdatedBy();
        $entity->setUpdatedBy($user);

        $this->getUnitOfWork()
            ->propertyChanged($entity, self::UPDATED_BY, $oldValue, $user);
    }

    /**
     * Stores the current user into deletedBy property
     */
    public function preRemove(PreRemoveEventArgs $eventArgs): void
    {
        $entity = $eventArgs->getObject();
        if (! $entity instanceof BlameableInterface) {
            return;
        }

        $user = $this->userProvider->provideUser();
        if ($user === null) {
            return;
        }

        $oldDeletedBy = $entity->getDeletedBy();
        $entity->setDeletedBy($user);

        $this->getUnitOfWork()
            ->propertyChanged($entity, self::DELETED_BY, $oldDeletedBy, $user);
    }

    private function mapEntity(ClassMetadata $classMetadataInfo): void
    {
        if ($this->blameableUserEntity !== null && class_exists($this->blameableUserEntity)) {
            $this->mapManyToOneUser($classMetadataInfo);
        } else {
            $this->mapStringUser($classMetadataInfo);
        }
    }

    private function getUnitOfWork(): UnitOfWork
    {
        return $this->entityManager->getUnitOfWork();
    }

    private function mapManyToOneUser(ClassMetadata $classMetadataInfo): void
    {
        $this->mapManyToOneWithTargetEntity($classMetadataInfo, self::CREATED_BY);
        $this->mapManyToOneWithTargetEntity($classMetadataInfo, self::UPDATED_BY);
        $this->mapManyToOneWithTargetEntity($classMetadataInfo, self::DELETED_BY);
    }

    private function mapStringUser(ClassMetadata $classMetadataInfo): void
    {
        $this->mapStringNullableField($classMetadataInfo, self::CREATED_BY);
        $this->mapStringNullableField($classMetadataInfo, self::UPDATED_BY);
        $this->mapStringNullableField($classMetadataInfo, self::DELETED_BY);
    }

    private function mapManyToOneWithTargetEntity(ClassMetadata $classMetadataInfo, string $fieldName): void
    {
        if ($classMetadataInfo->hasAssociation($fieldName)) {
            return;
        }

        if ($this->blameableUserEntity === null) {
            throw new InvalidArgumentException();
        }

        $userMetadata = $this->entityManager->getClassMetadata($this->blameableUserEntity);

        $classMetadataInfo->mapManyToOne([
            'fieldName' => $fieldName,
            'targetEntity' => $this->blameableUserEntity,
            'joinColumns' => [
                [
                    'referencedColumnName' => $userMetadata->getColumnName(
                        $userMetadata->getSingleIdentifierFieldName()
                    ),
                    'onDelete' => 'SET NULL',
                ],
            ],
        ]);
    }

    private function mapStringNullableField(ClassMetadata $classMetadataInfo, string $fieldName): void
    {
        if ($classMetadataInfo->hasField($fieldName)) {
            return;
        }

        $classMetadataInfo->mapField([
            'fieldName' => $fieldName,
            'type' => 'string',
            'nullable' => true,
        ]);
    }
}

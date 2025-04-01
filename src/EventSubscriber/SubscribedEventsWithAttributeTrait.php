<?php

namespace Knp\DoctrineBehaviors\EventSubscriber;

use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;

trait SubscribedEventsWithAttributeTrait
{
    public function getSubscribedEvents()
    {
        $class = new \ReflectionClass(__CLASS__);
        $attributes = $class->getAttributes(AsDoctrineListener::class);
        return array_map(function (\ReflectionAttribute $attribute) {
            return $attribute->getArguments()['event'];
        }, $attributes);
    }
}

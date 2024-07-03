<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Tests\Fixture\Doctrine;

use Doctrine\ORM\Mapping\Entity;

#[Entity(repositoryClass: EntityWithAttributesRepository::class)]
class EntityWithAttributes
{
}

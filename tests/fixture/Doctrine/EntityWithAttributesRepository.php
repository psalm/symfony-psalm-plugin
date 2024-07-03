<?php

declare(strict_types=1);

namespace Psalm\SymfonyPsalmPlugin\Tests\Fixture\Doctrine;

use Doctrine\ORM\EntityRepository;

/**
 * @extends EntityRepository<EntityWithAttributes>
 */
class EntityWithAttributesRepository extends EntityRepository
{
}

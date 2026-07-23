<?php
/**
 * This file is part of phloxcz/entity-database.
 *
 * (c) Phlox <https://github.com/phloxcz>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Phlox\EntityDatabase\Console;

use Phlox\EntityDatabase\Console\Command\Generate;
use Phlox\EntityDatabase\Generator\EntityGenerator;
use Symfony\Component\Console\Application;

class EntityDatabaseApplication extends Application
{
    public function __construct(EntityGenerator $generator)
    {
        parent::__construct('Entity Database');
        $this->addCommands([new Generate($generator)]);
    }
}

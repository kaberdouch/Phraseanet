<?php

/*
 * This file is part of Phraseanet
 *
 * (c) 2005-2014 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Core\Event\Acl;

class RightsToSbasChangedEvent extends AclEvent
{
    public function getSbasId()
    {
        return $this->args['sbas_id'];
    }

    public function getRights()
    {
        return $this->args['rights'];
    }
}

<?php

declare(strict_types=1);

namespace App\Service\Person\Data;

use App\Entity\Person;

class PersonCombineData
{
    private ?Person $source = null;
    private ?Person $target = null;

    public function getSource(): ?Person
    {
        return $this->source;
    }

    public function setSource(?Person $source): PersonCombineData
    {
        $this->source = $source;
        return $this;
    }

    public function getTarget(): ?Person
    {
        return $this->target;
    }

    public function setTarget(?Person $target): PersonCombineData
    {
        $this->target = $target;
        return $this;
    }
}

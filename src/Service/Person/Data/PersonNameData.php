<?php

declare(strict_types=1);

namespace App\Service\Person\Data;

use App\Entity\Person;

class PersonNameData
{
    private ?string $name = null;

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): PersonNameData
    {
        $this->name = $name;
        return $this;
    }
}

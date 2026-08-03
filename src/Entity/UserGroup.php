<?php

namespace App\Entity;

use App\Repository\UserGroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserGroupRepository::class)]
class UserGroup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $owner = null;

    /**
     * @var Collection<int, UserGroupMember>
     */
    #[ORM\OneToMany(targetEntity: UserGroupMember::class, mappedBy: 'userGroup', cascade: ['persist', 'remove'])]
    private Collection $members;

    public function __construct()
    {
        $this->members = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getOwner(): ?User
    {
        return $this->owner;
    }

    public function setOwner(?User $owner): static
    {
        $this->owner = $owner;

        return $this;
    }

    /**
     * @return Collection<int, UserGroupMember>
     */
    public function getMembers(): Collection
    {
        return $this->members;
    }

    public function addMember(UserGroupMember $member): static
    {
        if (!$this->members->contains($member)) {
            $this->members->add($member);
            $member->setUserGroup($this);
        }

        return $this;
    }

    public function removeMember(UserGroupMember $member): static
    {
        if ($this->members->removeElement($member)) {
            // set the owning side to null (unless already changed)
            if ($member->getUserGroup() === $this) {
                $member->setUserGroup(null);
            }
        }

        return $this;
    }
}

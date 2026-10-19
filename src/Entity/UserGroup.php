<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserGroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

/**
 * Gruppe von Benutzern, in der Oberfläche „Pool“: Ihre Mitglieder sehen die Medien des Pools – einzeln hinzugefügte
 * (`Medium::allowedGroups`) und solche, auf die eine Regel passt (`rules`), ohne die ausgenommenen (`excludedMedia`).
 */
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

    /** Wofür der Pool gedacht ist, z. B. „Mama und Papa: alte Heimvideos“ */
    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $description = null;

    /**
     * @var Collection<int, PoolRule>
     */
    #[ORM\OneToMany(
        targetEntity: PoolRule::class,
        mappedBy: 'pool',
        cascade: ['persist', 'remove'],
        orphanRemoval: true,
    )]
    private Collection $rules;

    /**
     * Medien, die trotz passender Regel nicht zum Pool gehören.
     *
     * @var Collection<int, Medium>
     */
    #[ORM\ManyToMany(targetEntity: Medium::class)]
    #[ORM\JoinTable(name: 'user_group_excluded_medium')]
    #[ORM\JoinColumn(onDelete: 'CASCADE')]
    #[ORM\InverseJoinColumn(onDelete: 'CASCADE')]
    private Collection $excludedMedia;

    public function __construct()
    {
        $this->members = new ArrayCollection();
        $this->rules = new ArrayCollection();
        $this->excludedMedia = new ArrayCollection();
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $description = $description === null ? null : trim($description);
        $this->description = $description === '' ? null : $description;

        return $this;
    }

    /**
     * @return Collection<int, PoolRule>
     */
    public function getRules(): Collection
    {
        return $this->rules;
    }

    public function addRule(PoolRule $rule): static
    {
        if (!$this->rules->contains($rule)) {
            $this->rules->add($rule);
        }

        return $this;
    }

    public function removeRule(PoolRule $rule): static
    {
        $this->rules->removeElement($rule);

        return $this;
    }

    /**
     * @return Collection<int, Medium>
     */
    public function getExcludedMedia(): Collection
    {
        return $this->excludedMedia;
    }

    public function exclude(Medium $medium): static
    {
        if (!$this->excludedMedia->contains($medium)) {
            $this->excludedMedia->add($medium);
        }

        return $this;
    }

    public function unexclude(Medium $medium): static
    {
        $this->excludedMedia->removeElement($medium);

        return $this;
    }

    public function hasMember(User $user): bool
    {
        foreach ($this->members as $member) {
            if ($member->getUser()?->getId() === $user->getId()) {
                return true;
            }
        }

        return false;
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

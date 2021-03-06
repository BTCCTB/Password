<?php

namespace App\Entity\Traits;

use App\Entity\User;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * Trait Blameable
 *
 * @package App\Entity\Traits
 * @author  Damien Lagae <damien.lagae@enabel.be>
 */
trait Blameable
{
    /**
     * @var null|User
     * @Gedmo\Blameable(on="create")
     * @ORM\ManyToOne(targetEntity="App\Entity\User")
     * @ORM\JoinColumn(name="created_by_id", referencedColumnName="id", nullable=true, onDelete="SET NULL")
     */
    protected $createdBy;

    /**
     * @var null|User
     * @Gedmo\Blameable(on="update")
     * @ORM\ManyToOne(targetEntity="App\Entity\User")
     * @ORM\JoinColumn(name="updated_by_id", referencedColumnName="id", nullable=true, onDelete="SET NULL")
     */
    protected $updatedBy;

    /**
     * @return User|null
     */
    public function getCreatedBy():  ? User
    {
        return $this->createdBy;
    }

    /**
     * @param User|null $createdBy
     *
     * @return $this
     */
    public function setCreatedBy(? User $createdBy = null) : self
    {
        $this->createdBy = $createdBy;

        return $this;
    }

    /**
     * @return User|null
     */
    public function getUpdatedBy() :  ? User
    {
        return $this->updatedBy;
    }

    /**
     * @param User|null $updatedBy
     *
     * @return $this
     */
    public function setUpdatedBy(? User $updatedBy = null) : self
    {
        $this->updatedBy = $updatedBy;

        return $this;
    }
}

<?php

namespace BisBundle\Entity;

use Doctrine\ORM\EntityRepository;

/**
 * BisContractSfRepository
 *
 * This class was generated by the PhpStorm "Php Annotations" Plugin. Add your own custom
 * repository methods below.
 */
class BisContractSfRepository extends EntityRepository
{
    public function isPersonActive(string $email)
    {
        $today = new \DateTime();

        $repository = $this->_em->getRepository(BisContractSf::class);
        $query = $repository->createQueryBuilder('con')
            ->innerJoin(BisPersonSf::class, 'per')
            ->andWhere('per.perId = con.conPerId')
            ->andWhere('per.perEmail LIKE :email')
            ->andWhere('con.conDateStop < :now')
            ->orderBy('con.conId', 'DESC')
            ->setParameter('email', $email)
            ->setParameter('now', $today->format('Y-m-d'))
            ->getQuery();

        $contract = $query->getFirstResult();

        if (empty($contract)) {
            return true;
        }

        return false;
    }
}

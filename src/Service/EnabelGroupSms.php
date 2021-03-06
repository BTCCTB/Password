<?php

namespace App\Service;

use App\Dto\ContactSms;
use BisBundle\Service\PhoneDirectory;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Criteria;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Intl\Countries;

/**
 * Class EnabelGroupSms
 *
 * @author Damien Lagae <damien.lagae@enabel.be>
 */
class EnabelGroupSms
{
    const ENABELCOUNTRIES = [
        'BDI',
        'BEL',
        'BEN',
        'BFA',
        'BOL',
        'CAF',
        'COD',
        'GIN',
        'GMB',
        'GNB',
        'JOR',
        'MAR',
        'MLI',
        'MOZ',
        'MRT',
        'NER',
        'PER',
        'PSE',
        'RWA',
        'SEN',
        'TZA',
        'UGA',
    ];

    /**
     * @EntityManagerInterface
     */
    private $em;

    /**
     * @var PhoneDirectory
     */
    private $phoneDirectory;

    public function __construct(EntityManagerInterface $em, PhoneDirectory $phoneDirectory)
    {
        $this->em = $em;
        $this->phoneDirectory = $phoneDirectory;
    }

    /**
     * @return string[]
     */
    public static function getGroups()
    {
        return [
            'all' => 'AllEnabel',
            'hq' => 'EnabelHQ',
            'field' => 'EnabelField',
            'resrep' => 'ResRep',
            'ict_hq' => 'ICT HQ',
        ];
    }

    /**
     * @return array
     */
    public static function getCountries()
    {
        $partnerCountries = [];
        $countries = Countries::getAlpha3Names();

        foreach (self::ENABELCOUNTRIES as $alpha3Code) {
            $partnerCountries[$alpha3Code] = $countries[$alpha3Code];
        }

        asort($partnerCountries);

        return $partnerCountries;
    }

    public static function getAllRecipientOptions(PhoneDirectory $phoneDirectory)
    {
        return [
            'Group' => array_flip(self::getGroups()),
            'Country' => array_flip(self::getCountries()),
            'Enabel Staff' => $phoneDirectory->getRecipientOptions(),
        ];
    }

    public static function getGroupRecipientOptions()
    {
        return [
            'Group' => array_flip(self::getGroups()),
            'Country' => array_flip(self::getCountries()),
        ];
    }

    public static function getPersonRecipientOptions(PhoneDirectory $phoneDirectory)
    {
        return [
            'Enabel Staff' => $phoneDirectory->getRecipientOptions(),
        ];
    }

    public function getName($groupCode)
    {
        $options = array_flip($this->phoneDirectory->getRecipientOptions());
        $groups = self::getCountries() + self::getGroups() + $options;

        if (array_key_exists($groupCode, $groups)) {
            return $groups[$groupCode];
        }

        return $groupCode;
    }

    /**
     * Get a array recipient for a given filter
     *
     * @param string $filter The given filter to apply
     * @return ArrayCollection<ContactSms> List of contact
     */
    public function getRecipients($filter): ArrayCollection
    {
        $recipients = new ArrayCollection();
        $users = [];

        switch ($filter) {
            case 'all':
                $users = $this->phoneDirectory->getAll();
                break;

            case 'hq':
                $users = $this->phoneDirectory->getHQ();
                break;

            case 'field':
                $users = $this->phoneDirectory->getField();
                break;

            case 'resrep':
                $users = $this->phoneDirectory->getResRep();
                break;

            case 'ict_hq':
                $users = $this->phoneDirectory->getIctHq();
                break;

            case 'BDI':
            case 'BEL':
            case 'BEN':
            case 'BFA':
            case 'BOL':
            case 'CAF':
            case 'COD':
            case 'GIN':
            case 'GMB':
            case 'GNB':
            case 'JOR':
            case 'MAR':
            case 'MLI':
            case 'MOZ':
            case 'MRT':
            case 'NER':
            case 'PER':
            case 'PSE':
            case 'RWA':
            case 'SEN':
            case 'TZA':
            case 'UGA':
                $users = $this->phoneDirectory->getByCountry($filter);
                break;

            default:
                $users = $this->phoneDirectory->getContactById($filter);
        }

        foreach ($users as $user) {
            if (!empty($user->getMobile())) {
                $recipients->add(ContactSms::loadFromBisPhone($user));
            }
        }

        return $recipients;
    }

    /**
     * Get a array recipient for a given filter by language
     *
     * @param string $filter The given filter to apply
     * @return ArrayCollection<ContactSms>[] List of contact by language
     */
    public function getRecipientByLanguages($filter)
    {
        $recipients = [
            'EN' => null,
            'FR' => null,
            'NL' => null,
        ];
        $allRecipients = $this->getRecipients($filter);

        $recipients['EN'] = $allRecipients->matching(Criteria::create()->where(Criteria::expr()->eq('language', 'EN')));
        $recipients['FR'] = $allRecipients->matching(Criteria::create()->where(Criteria::expr()->eq('language', 'FR')));
        $recipients['NL'] = $allRecipients->matching(Criteria::create()->where(Criteria::expr()->eq('language', 'NL')));

        return $recipients;
    }
}

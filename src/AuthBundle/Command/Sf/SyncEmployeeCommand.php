<?php

namespace AuthBundle\Command\Sf;

use AuthBundle\Service\ActiveDirectory;
use AuthBundle\Service\ActiveDirectoryNotification;
use AuthBundle\Service\ActiveDirectoryResponseStatus;
use AuthBundle\Service\SuccessFactorApi;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class SyncEmployeeCommand extends Command
{
    protected static $defaultName = 'sf:sync-employee';
    /**
     * @var SuccessFactorApi
     */
    private $sfApi;
    /**
     * @var ActiveDirectory
     */
    private $activeDirectory;

    /**
     * SfSearchEmployeeCommand constructor.
     *
     * @param SuccessFactorApi $sfApi
     * @param ActiveDirectory  $activeDirectory
     * @param null             $name
     */
    public function __construct(SuccessFactorApi $sfApi, ActiveDirectory $activeDirectory, $name = null)
    {
        $this->sfApi = $sfApi;
        $this->activeDirectory = $activeDirectory;
        parent::__construct($name);
    }

    protected function configure()
    {
        $this
            ->setDescription('Sync employee for SuccessFactor [GO4HR] in AD')
            ->addArgument('email', InputArgument::REQUIRED, 'User email [@enabel.be]?')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = $input->getArgument('email');

        if ($email && strpos($email, '@enabel.be')) {
            $message = sprintf('Search employee with email: %s', $email);
            $io->text($message);
            $users = $this->sfApi->searchUsers($email);
            if ($users) {
                $headers = [
                    'id',
                    'lastname',
                    'firstname',
                    'nickname',
                    'gender',
                    'startDate',
                    'endDate',
                    'active',
                    'emailEnabel',
                    'motherLanguage',
                    'preferredLanguage',
                    'phone',
                    'mobile',
                    'position',
                    'jobTitle',
                    'countryWorkplace',
                    'managerId',
                    'jobClass',
                    'AdAccount'
                ];
                foreach ($users as $id => $data) {
                    $users[$id]['startDate'] = ($data['startDate'] === null) ? null : $data['startDate']->format('Y-m-d');
                    $users[$id]['endDate'] = ($data['endDate'] === null) ? null : $data['endDate']->format('Y-m-d');
                    $users[$id]['AdAccount'] = $this->activeDirectory->checkUserExistByEmail($email);
                    if ($users[$id]['AdAccount'] === false) {
                        $message = sprintf('Try to add this users (%s) in AD', $email);
                        $io->comment($message);
                        $sync = $this->activeDirectory->createFromSfApi($data);
                        if ($sync->getStatus() === ActiveDirectoryResponseStatus::DONE) {
                            $io->success($sync->getMessage());
                        } else {
                            $io->error($sync->getMessage());
                        }
                    }
                }
                $io->horizontalTable($headers, $users);
            } else {
                $message = sprintf('No users found with this email: %s', $email);
                $io->warning($message);
            }
        } else {
            $io->error('Email should be contains @enabel.be');
        }

        return 0;
    }
}

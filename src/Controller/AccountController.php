<?php

namespace App\Controller;

use Adldap\Models\User;
use App\Entity\Account;
use App\Entity\UserLanguage;
use App\Form\ActionAuthType;
use App\Form\ChangeEmailType;
use App\Form\ChangePasswordType;
use App\Form\ExternalFormType;
use App\Form\ForceSyncType;
use App\Form\UserLanguageType;
use App\Repository\AccountRepository;
use App\Repository\UserLanguageRepository;
use App\Repository\UserRepository;
use App\Service\Account as AccountService;
use App\Service\Exception\SmsException;
use App\Service\SecurityAudit;
use App\Service\SmsInterface;
use AuthBundle\Service\ActiveDirectory;
use AuthBundle\Service\ActiveDirectoryHelper;
use AuthBundle\Service\ActiveDirectoryResponseStatus;
use AuthBundle\Service\BisDir;
use AuthBundle\Service\SuccessFactorApi;
use BisBundle\Service\BisPersonView;
use Doctrine\ORM\EntityManagerInterface;
use Knp\Component\Pager\PaginatorInterface;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\RouterInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\IsGranted;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Class AccountController
 *
 * @package Controller
 *
 * @author  Damien Lagae <damien.lagae@enabel.be>
 *
 * @Route("/account")
 *
 * @IsGranted("ROLE_USER")
 */
class AccountController extends AbstractController
{
    /**
     * @var ActiveDirectory
     */
    private $activeDirectory;
    /**
     * @var BisDir
     */
    private $bisDir;
    /**
     * @var AccountService
     */
    private $accountService;
    /**
     * @var SecurityAudit
     */
    private $securityAudit;

    public function __construct(ActiveDirectory $activeDirectory, BisDir $bisDir, AccountService $accountService, SecurityAudit $securityAudit)
    {
        $this->activeDirectory = $activeDirectory;
        $this->bisDir = $bisDir;
        $this->accountService = $accountService;
        $this->securityAudit = $securityAudit;
    }

    /**
     * @Route("/", name="account_list", methods={"GET"})
     *
     * @IsGranted("ROLE_ADMIN")
     *
     * @param AccountRepository $accountRepository
     *
     * @param BisPersonView     $bisPersonView
     *
     * @return Response
     */
    public function indexAction(AccountRepository $accountRepository, BisPersonView $bisPersonView, PaginatorInterface $paginator, Request $request)
    {
        $criteria = $request->query->get('v', null);
        $request->query->remove('f');
        $request->query->remove('v');
        $query = $accountRepository->paginateAllActive($criteria);

        $pagination = $paginator->paginate(
            $query, /* query NOT result */
            $request->query->getInt('page', 1), /*page number*/
            20 /*limit per page*/
        );

        return $this->render('Account/index.html.twig', ['pagination' => $pagination]);
    }

    /**
     * @Route("/password-ago", name="account_password_changed_ago", methods={"GET"})
     *
     * @IsGranted("ROLE_ADMIN")
     *
     * @param ActiveDirectory $activeDirectory
     *
     * @return Response
     */
    public function passwordAgoAction(ActiveDirectory $activeDirectory)
    {
        $accounts = $activeDirectory->getAllUsers('email');

        return $this->render('Account/passwordAgo.html.twig', ['accounts' => $accounts]);
    }

    /**
     * @Route("/change-password", name="account_change_password", methods={"GET","POST"})
     *
     * @param Request $request
     *
     * @return Response
     *
     * @throws \Adldap\AdldapException
     */
    public function changeAction(Request $request)
    {
        /** @var \App\Entity\User $user */
        $user = $this->get('security.token_storage')->getToken()->getUser();

        $form = $this->createForm(ChangePasswordType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            if ($this->activeDirectory->checkCredentials($user->getEmail(), $data['current_password'])) {
                $passwordCheck = ActiveDirectoryHelper::checkPasswordComplexity($data['password']);
                if (true === $passwordCheck) {
                    if ($this->activeDirectory->changePassword($user->getEmail(), $data['password'])) {
                        if ($this->bisDir->syncPassword($user->getEmail(), $data['password'])) {
                            $this->securityAudit->changePassword(
                                $this->accountService->getAccount($user->getEmail()),
                                $user
                            );
                            $this->addFlash('success', 'Password successfully changed !');

                            return $this->redirectToRoute('homepage');
                        }
                    } else {
                        $this->addFlash('danger', 'Password cannot be changed !');
                    }
                } else {
                    $this->addFlash('danger', 'The new password don\'t respect the rules of complexity');
                    $this->addFlash('warning', $passwordCheck);
                }
            } else {
                $this->addFlash('danger', 'Current password don\'t match !');
            }
        }

        return $this->render('Account/change.html.twig', ['form' => $form->createView()]);
    }

    /**
     * @Route("/disable/{employeeID}", name="ad_disable_account", methods={"GET"})
     *
     * @IsGranted("ROLE_SUPER_ADMIN")
     *
     * @param integer $employeeID The employee ID
     *
     * @return RedirectResponse
     *
     * @throws \LogicException
     */
    public function disableAction($employeeID): RedirectResponse
    {
        /**
         * @var User $user
         */
        $user = $this->activeDirectory->checkUserExistByEmployeeID($employeeID);
        if ($this->activeDirectory->disableUser($user)) {
            $this->addFlash('success', 'Account [' . $user->getUserPrincipalName() . '] disabled!');
        } else {
            $this->addFlash('danger', 'Can\'t do this action!');
        }

        return $this->redirectToRoute('account_list');
    }

    /**
     * @Route("/enable/{employeeID}", name="ad_enable_account", methods={"GET"})
     *
     * @IsGranted("ROLE_ADMIN")
     *
     * @param integer $employeeID The employee ID
     *
     * @return RedirectResponse
     *
     * @throws \LogicException
     */
    public function enableAction($employeeID): RedirectResponse
    {
        $user = $this->activeDirectory->checkUserExistByEmployeeID($employeeID);

        if ($this->activeDirectory->enableUser($user)) {
            $this->addFlash('success', 'Account [' . $user->getUserPrincipalName() . '] enabled!');
        } else {
            $this->addFlash('danger', 'Can\'t do this action!');
        }

        return $this->redirectToRoute('account_list');
    }

    /**
     * @Route("/reset/{employeeID}", name="account_reset_password", methods={"GET"})
     *
     * @IsGranted("ROLE_ADMIN")
     *
     * @param integer           $employeeID The employee ID     *
     * @param AccountRepository $accountRepository
     *
     * @param BisPersonView     $bisPersonView
     * @param SmsInterface      $sms
     *
     * @return RedirectResponse
     *
     * @throws \Adldap\AdldapException
     */
    public function resetAction($employeeID, AccountRepository $accountRepository, BisPersonView $bisPersonView, SmsInterface $sms): RedirectResponse
    {
        $user = $this->activeDirectory->checkUserExistByEmployeeID($employeeID);
        $account = $accountRepository->find($employeeID);
        $userInfo = $bisPersonView->getUser($user->getUserPrincipalName());

        if (empty($userInfo) || empty($userInfo->getMobile())) {
            $this->addFlash('warning', 'Account [' . $user->getUserPrincipalName() . '] without mobile!');
        }

        $resetPassword = $this->activeDirectory->initAccount($user);
        if ($resetPassword->getStatus() === ActiveDirectoryResponseStatus::DONE) {
//            $activeDirectoryNotification->notifyInitialization($resetPassword);
            $messages = [
                'info' => [
                    'fr' => "Votre mot de passe vient d'être modifié avec un mot de passe temporaire. " .
                    "Changez-le UNIQUEMENT sur (https://password.enabel.be). " .
                    "Vous recevrez ce mot de passe dans un second message. Enabel ICT",
                    'nl' => "Uw wachtwoord is zojuist gewijzigd met een tijdelijk wachtwoord. " .
                    "Wijzig het ENKEL op (https://password.enabel.be). " .
                    "U ontvangt dit wachtwoord in een tweede bericht. Enabel ICT",
                    'en' => "Your password has just been modified with a temporary password. " .
                    "Change it ONLY at (https://password.enabel.be). " .
                    "You will receive this password in a second message. Enabel ICT",
                ],
                'password' => [
                    'fr' => "Mot de passe:    %%_PASSWORD_%%",
                    'nl' => "Wachtword:    %%_PASSWORD_%%",
                    'en' => "Password:    %%_PASSWORD_%%",
                ],
            ];

            $resetData = $resetPassword->getData();
            $language = 'en';
            if ($userInfo !== null) {
                try {
                    $language = $userInfo->getLanguage();
                    $sms->send($messages['info'][$language], $userInfo->getMobile());
                    $messagePassword = str_replace('%%_PASSWORD_%%', $resetData['generatedpassword'], $messages['password'][$language]);
                    $sms->send($messagePassword, $userInfo->getMobile());
                } catch (SmsException $exception) {
                    $this->addFlash('danger', $exception->getMessage() . ' [SMS ' . $exception->getCode() . ']');
                }
            }
            $this->securityAudit->resetPassword($account, $this->get('security.token_storage')->getToken()->getUser());
            $this->addFlash('success', 'Account [' . $user->getUserPrincipalName() . '] initialized! [Password: ' . $resetData['generatedpassword'] . ']');
        } else {
            $this->addFlash('danger', 'Can\'t do this action!');
        }

        return $this->redirectToRoute('account_list');
    }

    /**
     * @Route("/check/{id}", name="account_check_password", methods={"GET","POST"})
     *
     * @ParamConverter("id", class="App:Account")
     *
     * @IsGranted("ROLE_ADMIN")
     *
     * @param Account $account The account to test
     * @param Request $request The request (Form data)
     *
     * @return Response
     */
    public function checkPasswordAction(Account $account, Request $request)
    {
        $form = $this->createForm(ActionAuthType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            if ($this->activeDirectory->checkCredentials($account->getEmail(), $data['password'])) {
                $this->securityAudit->testPassword($account, $this->get('security.token_storage')->getToken()->getUser(), true);
                $this->addFlash('success', 'This password is correct !');
            } else {
                $this->securityAudit->testPassword($account, $this->get('security.token_storage')->getToken()->getUser(), false);
                $this->addFlash('danger', 'This password don\'t match !');
            }
        }

        return $this->render('Account/checkPassword.html.twig', ['form' => $form->createView(), 'account' => $account]);
    }

    /**
     * @Route("/detail/{id}", name="account_detail", methods={"GET"}, requirements={"id"="\d+"})
     *
     * @IsGranted("ROLE_ADMIN")
     *
     * @param int               $id The account id
     * @param BisPersonView     $bisPersonView
     * @param AccountRepository $accountRepository
     *
     * @return Response
     */
    public function detailAction(int $id, BisPersonView $bisPersonView, AccountRepository $accountRepository)
    {
        if (!empty($id)) {
            $bisData = $bisPersonView->findById($id);
            $account = $accountRepository->find($id);
            $adUser = null;
            $ldapUser = null;
            if (!empty($bisData)) {
                $adUser = $this->activeDirectory->getUser($bisData->getEmail());
                $ldapUser = $this->bisDir->getUser($bisData->getEmail());
            } elseif (!empty($account)) {
                $adUser = $this->activeDirectory->getUser($account->getEmail());
                $ldapUser = $this->bisDir->getUser($account->getEmail());
            } else {
                $adUser = $this->activeDirectory->getUserByEmployeeId($id);
                if (!empty($adUser)) {
                    $ldapUser = $this->bisDir->getUser($adUser->getEmail());
                }
            }
        } else {
            $this->addFlash('danger', 'Can\'t do this action!');

            return $this->redirectToRoute('account_list');
        }

        return $this->render('Account/detail.html.twig', ['account' => $account, 'adData' => $adUser, 'ldapData' => $ldapUser, 'bisData' => $bisData]);
    }

    /**
     * @Route("/change-email/{id}", name="account_change_email", methods={"GET","POST"})
     *
     * @ParamConverter("id", class="App:Account")
     *
     * @IsGranted("ROLE_ADMIN")
     *
     * @param Account           $account           The account to test
     * @param Request           $request
     * @param UserRepository    $userRepository
     * @param AccountRepository $accountRepository
     *
     * @return Response
     *
     * @throws \Doctrine\ORM\ORMException
     * @throws \Doctrine\ORM\OptimisticLockException
     *
     * @SuppressWarnings(PHPMD.UnusedLocalVariable)
     */
    public function changeEmailAction(Account $account, Request $request, UserRepository $userRepository, AccountRepository $accountRepository)
    {
        if (!empty($account->getEmail())) {
            $form = $this->createForm(ChangeEmailType::class);
            $form->handleRequest($request);
            $adData = $this->activeDirectory->getUser($account->getEmail());

            if ($form->isSubmitted() && $form->isValid()) {
                $data = $form->getData();
                $emailStringRule = '((?:[a-z][a-z]+))'; // Firstname
                $emailStringRule .= '(\\.)'; // Separator
                $emailStringRule .= '((?:[a-z][a-z]+))'; // Lastname
                $emailStringRule .= '(@enabel\\.be)'; // Fully Qualified Domain Name

                if ($catch = preg_match_all("/" . $emailStringRule . "/is", $data['new_email'], $matches)) {
                    // sanitize email
                    $email = strtolower(trim($data['new_email']));

                    // Apply email change in AD
                    $adUser = $this->activeDirectory->findAndChangeEmail($account->getEmail(), $email, $data['keep_proxy']);

                    if (null !== $adUser && $adUser->getEmail() == $email) {
                        // Apply email change in LDAP
                        $this->bisDir->findAndChangeEmail($account->getEmail(), $email);
                        // Apply email change in User DB
                        $userRepository->changeEmail($account->getAccountName(), $email);
                        // Apply email change in Account DB
                        $account = $accountRepository->changeEmail($account, $email);

                        return $this->redirectToRoute('account_detail', ['id' => $account->getEmployeeId()]);
                    }
                    $form->addError(new FormError('The email address can\' t be changed'));
                } else {
                    $form->get('new_email')->addError(new FormError('The new email address must be a valid Enabel email address [firstname.lastname@enabel.be]'));
                }
            }
        } else {
            $this->addFlash('danger', 'Can\'t do this action!');

            return $this->redirectToRoute('account_list');
        }

        return $this->render('Account/changeEmail.html.twig', ['form' => $form->createView(), 'account' => $account, 'adData' => $adData]);
    }

    /**
     * @Route("/external/create", name="account_create_external", methods={"GET","POST"})
     *
     * @param Request         $request
     * @param ActiveDirectory $activeDirectory
     *
     * @return Response
     */
    public function createExternal(Request $request, ActiveDirectory $activeDirectory)
    {
        $form = $this->createForm(ExternalFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $adAccount = $activeDirectory->createExternal($form->getData());
            if ($adAccount->getStatus() === ActiveDirectoryResponseStatus::DONE) {
                $this->addFlash('success', $adAccount->getMessage());
                return $this->redirectToRoute('homepage');
            } else {
                $this->addFlash('danger', $adAccount->getMessage());
            }
        }

        return $this->render(
            'Account/createExternal.html.twig',
            [
                'form' => $form->createView(),
            ]
        );
    }

    /**
     * @Route("/force-sync", name="account_force_sync", methods={"GET","POST"})
     *
     * @param Request          $request
     * @param SuccessFactorApi $sfApi
     *
     * @return Response
     */
    public function forceSync(
        Request $request,
        SuccessFactorApi $sfApi,
        BisPersonView $bisPersonView,
        ActiveDirectory $activeDirectory,
        BisDir $bisDir,
        EntityManagerInterface $entityManager
    ) {
        $form = $this->createForm(ForceSyncType::class);
        $form->handleRequest($request);
        $user = null;
        $ad = null;
        $ldap = null;
        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $users = $sfApi->searchUsers($data['search']);
            if (isset($users[0]['id'])) {
                $bisPersonView->cleanDataById($users[0]['id']);
                $user = $bisPersonView->createPerson($users[0]);
                if (null !== $user) {
                    $ad = $activeDirectory->forceSync($user);
                    if (is_a($ad, User::class)) {
                        $bisDir->synchronize($ad);
                        $ldap = $bisDir->getUser($ad->getEmail());
                        $account = $entityManager->find(Account::class, $ad->getEmployeeId());
                        if (null === $account) {
                            $account = new Account();
                            $account->setEmployeeId($ad->getEmployeeId());
                        }
                        $account
                            ->setEmail($ad->getEmail())
                            ->setEmailContact($ad->getEmail())
                            ->setAccountName($ad->getAccountName())
                            ->setUserPrincipalName($ad->getUserPrincipalName())
                            ->setLastname($ad->getLastName())
                            ->setFirstname($ad->getFirstName())
                            ->setActive(1)
                            ->setToken(base64_encode($ad->getEmail()))
                        ;

                        $entityManager->persist($account);
                        $entityManager->flush();

                        $userRepo = $entityManager->getRepository(\App\Entity\User::class);
                        $userAccount = $userRepo->findOneBy(['email' => $ad->getEmail()]);
                        if (null !== $userAccount) {
                            $entityManager->remove($userAccount);
                            $entityManager->flush();
                        }
                    } else {
                        $this->addFlash('danger', 'Unable to create a AD account for this user !');
                        $this->addFlash('warning', 'Check GO4HR required data !');
                    }
                }
            }
        }

        /* @var \BisBundle\Entity\BisPersonView $user */
        return $this->render(
            'Account/forceSync.html.twig',
            [
                'form' => $form->createView(),
                'user' => $user,
                'ldap' => $ldap,
                'ad' => $ad,
            ]
        );
    }

    /**
     * @Route("/change-language", name="account_change_language", methods={"GET","POST"})
     *
     * @param Request $request
     *
     * @return Response
     *
     * @throws \Adldap\AdldapException
     */
    public function changeLanguageAction(Request $request, UserLanguageRepository $userLanguageRepository, RouterInterface $router, SessionInterface $session)
    {
        /** @var \App\Entity\User $user */
        $user = $this->get('security.token_storage')->getToken()->getUser();
        $adUser = $this->activeDirectory->getUser($user->getEmail());
        $userLanguage = $userLanguageRepository->findOneBy(['userId'=>$adUser->getEmployeeId()]);
        if (null === $userLanguage) {
            $userLanguage = (new UserLanguage())
                ->setUserId($adUser->getEmployeeId())
                ->setLanguage($adUser->getFirstAttribute('preferredLanguage'))
            ;
        }
        $form = $this->createForm(UserLanguageType::class, $userLanguage);
        $form->remove('userId');
        $form->add('userId', HiddenType::class);
        $form->setData($userLanguage);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            $em = $this->getDoctrine()->getManager();
            $em->persist($data);
            $em->flush();
            $adUser->setFirstAttribute('preferredLanguage', $data->getLanguage());
            $adUser->save();
            $locale = substr(strtolower($data->getLanguage()), 0, 2);
            $session->set('_locale', $locale);
            $request->setLocale($locale);
            $localRoute = $router->generate("homepage", ["_locale"=>$locale]);
            return $this->redirect($localRoute);
        }

        return $this->render('Account/changeLanguage.html.twig', ['form' => $form->createView()]);
    }
}

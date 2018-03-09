<?php

namespace UnitedCMS\CoreBundle\Security;


use Doctrine\ORM\EntityManager;
use Symfony\Component\Security\Core\Exception\TokenNotFoundException;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use UnitedCMS\CoreBundle\Entity\ApiClient;
use UnitedCMS\CoreBundle\Service\UnitedCMSManager;

class ApiClientUserProvider implements UserProviderInterface
{
    /**
     * @var UnitedCMSManager $unitedCMSManager
     */
    private $unitedCMSManager;

    /**
     * @var EntityManager $entityManager
     */
    private $entityManager;

    public function __construct(UnitedCMSManager $unitedCMSManager, EntityManager $entityManager)
    {
        $this->unitedCMSManager = $unitedCMSManager;
        $this->entityManager = $entityManager;
    }

    /**
     * Loads the user for the given username.
     *
     * This method must throw UsernameNotFoundException if the user is not
     * found.
     *
     * @param string $username The username
     *
     * @return UserInterface|ApiClient
     *
     * @throws TokenNotFoundException if the token is not found
     */
    public function loadUserByUsername($username)
    {
        if(($domain = $this->unitedCMSManager->getDomain()) && ($token = $this->entityManager->getRepository('UnitedCMSCoreBundle:ApiClient')->findOneBy([
            'token' => $username,
            'domain' => $domain,
        ]))) {
            return $token;
        }
        throw new TokenNotFoundException("An API Client with token $username was not found for the current domain");
    }

    /**
     * Refreshes the user.
     *
     * It is up to the implementation to decide if the user data should be
     * totally reloaded (e.g. from the database), or if the UserInterface
     * object can just be merged into some internal array of users / identity
     * map.
     *
     * @param UserInterface $user
     *
     * @return UserInterface
     *
     * @throws UnsupportedUserException if the user is not supported
     */
    public function refreshUser(UserInterface $user)
    {
        if(!$user instanceof ApiClient) {
            throw new UnsupportedUserException('This provider ony supports API Clients');
        }

        return $this->loadUserByUsername($user->getToken());
    }

    /**
     * Whether this provider supports the given user class.
     *
     * @param string $class
     *
     * @return bool
     */
    public function supportsClass($class)
    {
        return $class === ApiClient::class;
    }
}
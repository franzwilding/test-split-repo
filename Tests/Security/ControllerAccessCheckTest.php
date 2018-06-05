<?php

namespace src\UniteCMS\CoreBundle\Tests\Security;


use Symfony\Component\BrowserKit\Cookie;
use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use UniteCMS\CoreBundle\Entity\ApiKey;
use UniteCMS\CoreBundle\Entity\Content;
use UniteCMS\CoreBundle\Entity\Domain;
use UniteCMS\CoreBundle\Entity\DomainMember;
use UniteCMS\CoreBundle\Entity\Organization;
use UniteCMS\CoreBundle\Entity\Invitation;
use UniteCMS\CoreBundle\Entity\OrganizationMember;
use UniteCMS\CoreBundle\Entity\User;
use UniteCMS\CoreBundle\Security\Voter\DomainVoter;
use UniteCMS\CoreBundle\Tests\DatabaseAwareTestCase;

/**
 * @group slow
 */
class ControllerAccessCheckTest extends DatabaseAwareTestCase
{

    /**
     * @var Client
     */
    private $client;

    /**
     * @var Organization
     */
    private $organization;

    /**
     * @var Domain
     */
    private $domain;

    /**
     * @var User[]
     */
    private $users = [];

    /**
     * @var Content
     */
    private $content1;

    /**
     * @var Invitation
     */
    private $invite1;

    /**
     * @var ApiKey $apiKey1
     */
    private $apiKey1;

    /**
     * @var string
     */
    private $domainConfiguration = '{
    "title": "Test controller access check domain",
    "identifier": "access_check", 
    "content_types": [
      {
        "title": "CT 1",
        "identifier": "ct1"
      }
    ], 
    "setting_types": [
      {
        "title": "ST 1",
        "identifier": "st1"
      }
    ]
  }';

    public function setUp()
    {
        parent::setUp();
        $this->client = static::$container->get('test.client');
        $this->client->followRedirects(false);

        // Create Test Organization and import Test Domain.
        $this->organization = new Organization();
        $this->organization->setTitle('Test controller access check')->setIdentifier('access_check');
        $this->domain = static::$container->get('unite.cms.domain_definition_parser')->parse($this->domainConfiguration);
        $this->domain->setOrganization($this->organization);
        $this->domain->addPermission(DomainVoter::UPDATE, 'member.type == "editor"');

        $this->em->persist($this->organization);
        $this->em->persist($this->domain);
        $this->em->flush();
        $this->em->refresh($this->organization);
        $this->em->refresh($this->domain);

        $this->users['domain_editor'] = new User();
        $this->users['domain_editor']
            ->setEmail('domain_editor@example.com')
            ->setName('Domain Editor')
            ->setRoles([User::ROLE_USER])
            ->setPassword('XXX');

        $domainEditorOrgMember = new OrganizationMember();
        $domainEditorOrgMember->setRoles([Organization::ROLE_USER])->setOrganization($this->organization);
        $domainEditorDomainMember = new DomainMember();
        $domainEditorDomainMember->setDomain($this->domain)->setDomainMemberType($this->domain->getDomainMemberTypes()->get('editor'));
        $this->users['domain_editor']->addOrganization($domainEditorOrgMember);
        $this->users['domain_editor']->addDomain($domainEditorDomainMember);

        $this->users['domain_viewer'] = new User();
        $this->users['domain_viewer']
            ->setEmail('domain_viewer@example.com')
            ->setName('Domain Viewer')
            ->setRoles([User::ROLE_USER])
            ->setPassword('XXX');

        $domainViewerOrgMember = new OrganizationMember();
        $domainViewerOrgMember->setRoles([Organization::ROLE_USER])->setOrganization($this->organization);
        $domainViewerDomainMember = new DomainMember();
        $domainViewerDomainMember->setDomain($this->domain)->setDomainMemberType($this->domain->getDomainMemberTypes()->get('viewer'));
        $this->users['domain_viewer']->addOrganization($domainViewerOrgMember);
        $this->users['domain_viewer']->addDomain($domainViewerDomainMember);


        $this->users['organization_member'] = new User();
        $this->users['organization_member']
            ->setEmail('organization_member@example.com')
            ->setName('Organization Member')
            ->setRoles([User::ROLE_USER])
            ->setPassword('XXX');

        $orgMemberOrgMember = new OrganizationMember();
        $orgMemberOrgMember->setRoles([Organization::ROLE_USER])->setOrganization($this->organization);
        $this->users['organization_member']->addOrganization($orgMemberOrgMember);

        $this->users['organization_admin'] = new User();
        $this->users['organization_admin']
            ->setEmail('organization_admin@example.com')
            ->setName('Organization Admin')
            ->setRoles([User::ROLE_USER])
            ->setPassword('XXX');

        $orgAdminOrgMember = new OrganizationMember();
        $orgAdminOrgMember->setRoles([Organization::ROLE_ADMINISTRATOR])->setOrganization($this->organization);
        $this->users['organization_admin']->addOrganization($orgAdminOrgMember);

        $this->users['platform'] = new User();
        $this->users['platform']
            ->setEmail('platform@example.com')
            ->setName('Platform')
            ->setRoles([User::ROLE_PLATFORM_ADMIN])
            ->setPassword('XXX');

        foreach ($this->users as $user) {
            $this->em->persist($user);
        }

        $this->em->flush();

        foreach ($this->users as $user) {
            $this->em->refresh($user);
        }

        // Create Test Content
        $this->content1 = new Content();
        $this->content1->setContentType($this->domain->getContentTypes()->get('ct1'));
        $this->em->persist($this->content1);

        // Create Test invite
        $this->invite1 = new Invitation();
        $this->invite1->setEmail('invite@example.com')->setDomainMemberType($this->domain->getDomainMemberTypes()->first());
        $this->em->persist($this->invite1);

        // Create Test API Client
        $this->apiKey1 = new ApiKey();
        $this->apiKey1->setOrganization($this->organization);
        $domainEditor = new DomainMember();
        $domainEditor->setDomain($this->domain)->setDomainMemberType($this->domain->getDomainMemberTypes()->get('viewer'));
        $this->apiKey1
            ->setName('API Client 1')
            ->setToken('xxx')
            ->addDomain($domainEditor);

        $this->em->persist($this->apiKey1);

        $this->em->flush();
        $this->em->refresh($this->content1);
        $this->em->refresh($this->invite1);
        $this->em->refresh($this->apiKey1);
    }

    private function assertAccess($route, $canAccess, $substitutions = [], $methods = ['GET'], $parameters = [])
    {

        $route = 'http://localhost'.$route;

        foreach ($substitutions as $substitution => $value) {
            $route = str_replace($substitution, $value, $route);
        }

        foreach ($methods as $method) {
            $this->client->request($method, $route, $parameters);

            if ($canAccess) {

                // Only check redirection if it is redirecting to another route.
                if (!$this->client->getResponse()->isRedirect($route) && !$this->client->getResponse()->isRedirect(
                        $route.'/'
                    )) {
                    $this->assertFalse($this->client->getResponse()->isRedirect('http://localhost/profile/login'));
                }
                $this->assertFalse($this->client->getResponse()->isForbidden());
                $this->assertFalse($this->client->getResponse()->isServerError());
                $this->assertFalse($this->client->getResponse()->isClientError());
            } else {
                $forbidden = ($this->client->getResponse()->isForbidden() || ($this->client->getResponse()->isRedirect(
                        'http://localhost/profile/login'
                    )));
                $this->assertTrue($forbidden);
            }
        }

        // Check, that all other methods are not allowed (Http 405).
        // This check does not works for the login action, because this action will
        // redirect the user to the invalid route login/ if method is not GET or POST.
        if ($canAccess && $route != 'http://localhost/profile/login') {
            $methodsAvailable = ['GET', 'POST', 'PUT', 'DELETE'];
            foreach (array_diff($methodsAvailable, $methods) as $method) {
                $this->client->request($method, $route);
                if (!$this->client->getResponse()->isRedirect()) {
                    $this->assertEquals(
                        405,
                        $this->client->getResponse()
                            ->getStatusCode()
                    );
                }
            }
        }
    }

    private function assertRedirect($route, $destination, $substitutions = [], $methods = ['GET'])
    {
        $route = 'http://localhost'.$route;

        foreach ($substitutions as $substitution => $value) {
            $route = str_replace($substitution, $value, $route);
        }

        foreach ($methods as $method) {
            $this->client->request($method, $route);
            $this->assertTrue($this->client->getResponse()->isRedirect($destination));
        }
    }

    private function login(User $user)
    {

        $token = new UsernamePasswordToken($user, null, 'main', $user->getRoles());
        $session = $this->client->getContainer()->get('session');
        $session->set('_security_main', serialize($token));
        $session->save();
        $cookie = new Cookie($session->getName(), $session->getId());
        $this->client->getCookieJar()->set($cookie);
    }

    public function testControllerActionAccessForAnonymous()
    {
        $substitutions = [
            '{organization}' => 'access_check',
            '{domain}' => 'access_check',
            '{content_type}' => 'ct1',
            '{setting_type}' => 'st1',
            '{view}' => 'all',
            '{content}' => $this->content1->getId(),
            '{member}' => $this->users['domain_editor']->getId(),
            '{invite}' => $this->invite1->getId(),
            '{apiKey}' => $this->apiKey1->getId(),
            '{member_type}' => $this->domain->getDomainMemberTypes()->first(),
        ];

        $this->assertAccess('/', false, $substitutions);
        $this->assertAccess('/profile/login', true, $substitutions, ['GET', 'POST'], [
            '_username' => '',
            '_password' => '',
        ]);
        $this->assertAccess('/profile/reset-password', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/profile/reset-password-confirm', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/profile/accept-invitation', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/profile/update', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/user/', false, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/user/update/{member}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/user/delete/{member}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/', false, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/create', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/view/{domain}', false, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/update/{domain}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/delete/{domain}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/{domain}/member/{member_type}', false, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/{domain}/member/{member_type}/create', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/{domain}/member/{member_type}/update/{member}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/{domain}/member/{member_type}/delete/{member}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess(
            '/{organization}/{domain}/member/{member_type}/delete-invite/{invite}',
            false,
            $substitutions,
            ['GET', 'POST']
        );
        $this->assertAccess('/{organization}/apikeys/', false, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/apikeys/create', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/apikeys/update/{apiKey}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/apikeys/delete/{apiKey}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess(
            '/{organization}/{domain}/content/{content_type}/{view}',
            false,
            $substitutions,
            ['GET']
        );
        $this->assertAccess(
            '/{organization}/{domain}/content/{content_type}/{view}/create',
            false,
            $substitutions,
            ['GET', 'POST']
        );
        $this->assertAccess(
            '/{organization}/{domain}/content/{content_type}/{view}/update/{content}',
            false,
            $substitutions,
            ['GET', 'POST']
        );
        $this->assertAccess(
            '/{organization}/{domain}/content/{content_type}/{view}/delete/{content}',
            false,
            $substitutions,
            ['GET', 'POST']
        );
        $this->assertAccess('/{organization}/{domain}/setting/{setting_type}', false, $substitutions, ['GET', 'POST']);
    }

    public function testControllerActionAccessForDomainEditor()
    {
        $this->login($this->users['domain_editor']);

        $substitutions = [
            '{organization}' => 'access_check',
            '{domain}' => 'access_check',
            '{content_type}' => 'ct1',
            '{setting_type}' => 'st1',
            '{view}' => 'all',
            '{content}' => $this->content1->getId(),
            '{member}' => $this->users['domain_editor']->getOrganizations()->first()->getId(),
            '{invite}' => $this->invite1->getId(),
            '{apiKey}' => $this->apiKey1->getId(),
            '{member_type}' => $this->domain->getDomainMemberTypes()->first()->getIdentifier(),
        ];

        $this->assertAccess('/', true, $substitutions, ['GET']);
        $this->assertRedirect('/profile/login', '/', $substitutions);
        $this->assertRedirect('/profile/reset-password', '/', $substitutions);
        $this->assertRedirect('/profile/reset-password-confirm', '/', $substitutions);
        $this->assertAccess('/profile/accept-invitation', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/profile/update', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/user/', false, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/user/update/{member}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/user/delete/{member}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/', true, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/create', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/view/{domain}', true, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/update/{domain}', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/delete/{domain}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/{domain}/member/{member_type}', true, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/{domain}/member/{member_type}/create', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/{domain}/member/{member_type}/update/{member}', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/{domain}/member/{member_type}/delete/{member}', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess(
            '/{organization}/{domain}/member/{member_type}/delete-invite/{invite}',
            true,
            $substitutions,
            ['GET', 'POST']
        );
        $this->assertAccess('/{organization}/apikeys/', false, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/apikeys/create', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/apikeys/update/{apiKey}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/apikeys/delete/{apiKey}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess(
            '/{organization}/{domain}/content/{content_type}/{view}',
            true,
            $substitutions,
            ['GET']
        );
        $this->assertAccess(
            '/{organization}/{domain}/content/{content_type}/{view}/create',
            true,
            $substitutions,
            ['GET', 'POST']
        );
        $this->assertAccess(
            '/{organization}/{domain}/content/{content_type}/{view}/update/{content}',
            true,
            $substitutions,
            ['GET', 'POST']
        );
        $this->assertAccess(
            '/{organization}/{domain}/content/{content_type}/{view}/delete/{content}',
            true,
            $substitutions,
            ['GET', 'POST']
        );
        $this->assertAccess('/{organization}/{domain}/setting/{setting_type}', true, $substitutions, ['GET', 'POST']);

        $org = $this->em->getRepository('UniteCMSCoreBundle:Organization')->findOneBy(
            ['identifier' => 'access_check',]
        );
        $domain2 = static::$container->get('unite.cms.domain_definition_parser')->parse($this->domainConfiguration);
        $domain2->setIdentifier('access_check2')->setTitle('Domain 2')->setOrganization($org);

        $content2 = new Content();
        $content2->setContentType($domain2->getContentTypes()->get('ct1'));
        $this->em->persist($domain2);
        $this->em->persist($content2);
        $this->em->flush();

        $substitutions['{domain}'] = $domain2->getIdentifier();
        $substitutions['{content}'] = $content2->getId();

        $this->assertAccess('/', true, $substitutions, ['GET']);
        $this->assertRedirect('/profile/login', '/', $substitutions);
        $this->assertRedirect('/profile/reset-password', '/', $substitutions);
        $this->assertRedirect('/profile/reset-password-confirm', '/', $substitutions);
        $this->assertAccess('/profile/accept-invitation', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/profile/update', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/user/', false, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/user/update/{member}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/user/delete/{member}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/', true, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/create', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/view/{domain}', false, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/update/{domain}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/delete/{domain}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/{domain}/member/{member_type}', false, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/{domain}/member/{member_type}/create', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/{domain}/member/{member_type}/update/{member}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/{domain}/member/{member_type}/delete/{member}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess(
            '/{organization}/{domain}/member/{member_type}/delete-invite/{invite}',
            false,
            $substitutions,
            ['GET', 'POST']
        );
        $this->assertAccess('/{organization}/apikeys/', false, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/apikeys/create', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/apikeys/update/{apiKey}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/apikeys/delete/{apiKey}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess(
            '/{organization}/{domain}/content/{content_type}/{view}',
            false,
            $substitutions,
            ['GET']
        );
        $this->assertAccess(
            '/{organization}/{domain}/content/{content_type}/{view}/create',
            false,
            $substitutions,
            ['GET', 'POST']
        );
        $this->assertAccess(
            '/{organization}/{domain}/content/{content_type}/{view}/update/{content}',
            false,
            $substitutions,
            ['GET', 'POST']
        );
        $this->assertAccess(
            '/{organization}/{domain}/content/{content_type}/{view}/delete/{content}',
            false,
            $substitutions,
            ['GET', 'POST']
        );
        $this->assertAccess('/{organization}/{domain}/setting/{setting_type}', false, $substitutions, ['GET', 'POST']);
    }

    public function testControllerActionAccessForDomainViewer()
    {
        $this->login($this->users['domain_viewer']);

        $substitutions = [
            '{organization}' => 'access_check',
            '{domain}' => 'access_check',
            '{content_type}' => 'ct1',
            '{setting_type}' => 'st1',
            '{view}' => 'all',
            '{content}' => $this->content1->getId(),
            '{member}' => $this->users['domain_viewer']->getOrganizations()->first()->getId(),
            '{invite}' => $this->invite1->getId(),
            '{apiKey}' => $this->apiKey1->getId(),
            '{member_type}' => $this->domain->getDomainMemberTypes()->first()->getIdentifier(),
        ];

        $this->assertAccess('/', true, $substitutions, ['GET']);
        $this->assertRedirect('/profile/login', '/', $substitutions);
        $this->assertRedirect('/profile/reset-password', '/', $substitutions);
        $this->assertRedirect('/profile/reset-password-confirm', '/', $substitutions);
        $this->assertAccess('/profile/accept-invitation', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/profile/update', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/user/', false, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/user/update/{member}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/user/delete/{member}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/', true, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/create', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/view/{domain}', true, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/update/{domain}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/delete/{domain}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/{domain}/member/{member_type}', false, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/{domain}/member/{member_type}/create', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/{domain}/member/{member_type}/update/{member}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/{domain}/member/{member_type}/delete/{member}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess(
            '/{organization}/{domain}/member/{member_type}/delete-invite/{invite}',
            false,
            $substitutions,
            ['GET', 'POST']
        );
        $this->assertAccess('/{organization}/apikeys/', false, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/apikeys/create', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/apikeys/update/{apiKey}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/apikeys/delete/{apiKey}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess(
            '/{organization}/{domain}/content/{content_type}/{view}',
            true,
            $substitutions,
            ['GET']
        );
        $this->assertAccess(
            '/{organization}/{domain}/content/{content_type}/{view}/create',
            false,
            $substitutions,
            ['GET', 'POST']
        );
        $this->assertAccess(
            '/{organization}/{domain}/content/{content_type}/{view}/update/{content}',
            false,
            $substitutions,
            ['GET', 'POST']
        );
        $this->assertAccess(
            '/{organization}/{domain}/content/{content_type}/{view}/delete/{content}',
            false,
            $substitutions,
            ['GET', 'POST']
        );
        $this->assertAccess('/{organization}/{domain}/setting/{setting_type}', false, $substitutions, ['GET', 'POST']);
    }

    public function testControllerActionAccessForOrganizationMember()
    {
        $this->login($this->users['organization_member']);

        $substitutions = [
            '{organization}' => 'access_check',
            '{domain}' => 'access_check',
            '{content_type}' => 'ct1',
            '{setting_type}' => 'st1',
            '{view}' => 'all',
            '{content}' => $this->content1->getId(),
            '{member}' => $this->users['domain_editor']->getOrganizations()->first()->getId(),
            '{invite}' => $this->invite1->getId(),
            '{apiKey}' => $this->apiKey1->getId(),
            '{member_type}' => $this->domain->getDomainMemberTypes()->first()->getIdentifier(),
        ];

        $this->assertAccess('/', true, $substitutions, ['GET']);
        $this->assertRedirect('/profile/login', '/', $substitutions);
        $this->assertRedirect('/profile/reset-password', '/', $substitutions);
        $this->assertRedirect('/profile/reset-password-confirm', '/', $substitutions);
        $this->assertAccess('/profile/accept-invitation', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/profile/update', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/user/', false, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/user/update/{member}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/user/delete/{member}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/', true, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/create', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/view/{domain}', false, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/update/{domain}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/delete/{domain}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/{domain}/member/{member_type}', false, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/{domain}/member/{member_type}/create', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/{domain}/member/{member_type}/update/{member}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/{domain}/member/{member_type}/delete/{member}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess(
            '/{organization}/{domain}/member/{member_type}/delete-invite/{invite}',
            false,
            $substitutions,
            ['GET', 'POST']
        );
        $this->assertAccess('/{organization}/apikeys/', false, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/apikeys/create', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/apikeys/update/{apiKey}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/apikeys/delete/{apiKey}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess(
            '/{organization}/{domain}/content/{content_type}/{view}',
            false,
            $substitutions,
            ['GET']
        );
        $this->assertAccess(
            '/{organization}/{domain}/content/{content_type}/{view}/create',
            false,
            $substitutions,
            ['GET', 'POST']
        );
        $this->assertAccess(
            '/{organization}/{domain}/content/{content_type}/{view}/update/{content}',
            false,
            $substitutions,
            ['GET', 'POST']
        );
        $this->assertAccess(
            '/{organization}/{domain}/content/{content_type}/{view}/delete/{content}',
            false,
            $substitutions,
            ['GET', 'POST']
        );
        $this->assertAccess('/{organization}/{domain}/setting/{setting_type}', false, $substitutions, ['GET', 'POST']);

        $org2 = new Organization();
        $org2->setTitle('Org 2')->setIdentifier('access_check2');
        $domain2 = static::$container->get('unite.cms.domain_definition_parser')->parse($this->domainConfiguration);
        $domain2->setIdentifier('access_check2')->setTitle('Domain 2')->setOrganization($org2);

        $content2 = new Content();
        $content2->setContentType($domain2->getContentTypes()->get('ct1'));
        $this->em->persist($org2);
        $this->em->persist($domain2);
        $this->em->persist($content2);
        $this->em->flush();

        $substitutions['{organization}'] = $org2->getIdentifier();
        $substitutions['{domain}'] = $domain2->getIdentifier();
        $substitutions['{content}'] = $content2->getId();

        $this->assertAccess('/', true, $substitutions, ['GET']);
        $this->assertRedirect('/profile/login', '/', $substitutions);
        $this->assertRedirect('/profile/reset-password', '/', $substitutions);
        $this->assertRedirect('/profile/reset-password-confirm', '/', $substitutions);
        $this->assertAccess('/profile/accept-invitation', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/profile/update', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/user/', false, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/user/update/{member}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/user/delete/{member}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/', false, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/create', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/view/{domain}', false, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/update/{domain}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/delete/{domain}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/{domain}/member/{member_type}', false, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/{domain}/member/{member_type}/create', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/{domain}/member/{member_type}/update/{member}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/{domain}/member/{member_type}/delete/{member}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess(
            '/{organization}/{domain}/member/{member_type}/delete-invite/{invite}',
            false,
            $substitutions,
            ['GET', 'POST']
        );
        $this->assertAccess('/{organization}/apikeys/', false, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/apikeys/create', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/apikeys/update/{apiKey}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/apikeys/delete/{apiKey}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess(
            '/{organization}/{domain}/content/{content_type}/{view}',
            false,
            $substitutions,
            ['GET']
        );
        $this->assertAccess(
            '/{organization}/{domain}/content/{content_type}/{view}/create',
            false,
            $substitutions,
            ['GET', 'POST']
        );
        $this->assertAccess(
            '/{organization}/{domain}/content/{content_type}/{view}/update/{content}',
            false,
            $substitutions,
            ['GET', 'POST']
        );
        $this->assertAccess(
            '/{organization}/{domain}/content/{content_type}/{view}/delete/{content}',
            false,
            $substitutions,
            ['GET', 'POST']
        );
        $this->assertAccess('/{organization}/{domain}/setting/{setting_type}', false, $substitutions, ['GET', 'POST']);
    }

    public function testControllerActionAccessForOrganizationAdmin()
    {
        $this->login($this->users['organization_admin']);

        $substitutions = [
            '{organization}' => 'access_check',
            '{domain}' => 'access_check',
            '{content_type}' => 'ct1',
            '{setting_type}' => 'st1',
            '{view}' => 'all',
            '{content}' => $this->content1->getId(),
            '{member}' => $this->users['domain_editor']->getOrganizations()->first()->getId(),
            '{invite}' => $this->invite1->getId(),
            '{apiKey}' => $this->apiKey1->getId(),
            '{member_type}' => $this->domain->getDomainMemberTypes()->first()->getIdentifier(),
        ];

        $this->assertAccess('/', true, $substitutions, ['GET']);
        $this->assertRedirect('/profile/login', '/', $substitutions);
        $this->assertRedirect('/profile/reset-password', '/', $substitutions);
        $this->assertRedirect('/profile/reset-password-confirm', '/', $substitutions);
        $this->assertAccess('/profile/accept-invitation', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/profile/update', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/user/', true, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/user/update/{member}', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/user/delete/{member}', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/', true, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/create', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/view/{domain}', true, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/update/{domain}', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/delete/{domain}', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/{domain}/member/{member_type}', true, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/{domain}/member/{member_type}/create', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/{domain}/member/{member_type}/update/{member}', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/{domain}/member/{member_type}/delete/{member}', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess(
            '/{organization}/{domain}/member/{member_type}/delete-invite/{invite}',
            true,
            $substitutions,
            ['GET', 'POST']
        );
        $this->assertAccess('/{organization}/apikeys/', true, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/apikeys/create', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/apikeys/update/{apiKey}', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/apikeys/delete/{apiKey}', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess(
            '/{organization}/{domain}/content/{content_type}/{view}',
            true,
            $substitutions,
            ['GET']
        );
        $this->assertAccess(
            '/{organization}/{domain}/content/{content_type}/{view}/create',
            true,
            $substitutions,
            ['GET', 'POST']
        );
        $this->assertAccess(
            '/{organization}/{domain}/content/{content_type}/{view}/update/{content}',
            true,
            $substitutions,
            ['GET', 'POST']
        );
        $this->assertAccess(
            '/{organization}/{domain}/content/{content_type}/{view}/delete/{content}',
            true,
            $substitutions,
            ['GET', 'POST']
        );
        $this->assertAccess('/{organization}/{domain}/setting/{setting_type}', true, $substitutions, ['GET', 'POST']);

        $org2 = new Organization();
        $org2->setTitle('Org 2')->setIdentifier('access_check2');
        $domain2 = static::$container->get('unite.cms.domain_definition_parser')->parse($this->domainConfiguration);
        $domain2->setIdentifier('access_check2')->setTitle('Domain 2')->setOrganization($org2);

        $content2 = new Content();
        $content2->setContentType($domain2->getContentTypes()->get('ct1'));
        $this->em->persist($org2);
        $this->em->persist($domain2);
        $this->em->persist($content2);
        $this->em->flush();

        $substitutions['{organization}'] = $org2->getIdentifier();
        $substitutions['{domain}'] = $domain2->getIdentifier();
        $substitutions['{content}'] = $content2->getId();

        $this->assertAccess('/', true, $substitutions, ['GET']);
        $this->assertRedirect('/profile/login', '/', $substitutions);
        $this->assertRedirect('/profile/reset-password', '/', $substitutions);
        $this->assertRedirect('/profile/reset-password-confirm', '/', $substitutions);
        $this->assertAccess('/profile/accept-invitation', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/profile/update', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/user/', false, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/user/update/{member}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/user/delete/{member}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/', false, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/create', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/view/{domain}', false, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/update/{domain}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/delete/{domain}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/{domain}/member/{member_type}', false, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/{domain}/member/{member_type}/create', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/{domain}/member/{member_type}/update/{member}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/{domain}/member/{member_type}/delete/{member}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess(
            '/{organization}/{domain}/member/{member_type}/delete-invite/{invite}',
            false,
            $substitutions,
            ['GET', 'POST']
        );
        $this->assertAccess('/{organization}/apikeys/', false, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/apikeys/create', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/apikeys/update/{apiKey}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/apikeys/delete/{apiKey}', false, $substitutions, ['GET', 'POST']);
        $this->assertAccess(
            '/{organization}/{domain}/content/{content_type}/{view}',
            false,
            $substitutions,
            ['GET']
        );
        $this->assertAccess(
            '/{organization}/{domain}/content/{content_type}/{view}/create',
            false,
            $substitutions,
            ['GET', 'POST']
        );
        $this->assertAccess(
            '/{organization}/{domain}/content/{content_type}/{view}/update/{content}',
            false,
            $substitutions,
            ['GET', 'POST']
        );
        $this->assertAccess(
            '/{organization}/{domain}/content/{content_type}/{view}/delete/{content}',
            false,
            $substitutions,
            ['GET', 'POST']
        );
        $this->assertAccess('/{organization}/{domain}/setting/{setting_type}', false, $substitutions, ['GET', 'POST']);
    }

    public function testControllerActionAccessForPlatformAdmin()
    {
        $this->login($this->users['platform']);

        $substitutions = [
            '{organization}' => 'access_check',
            '{domain}' => 'access_check',
            '{content_type}' => 'ct1',
            '{setting_type}' => 'st1',
            '{view}' => 'all',
            '{content}' => $this->content1->getId(),
            '{member}' => $this->users['domain_editor']->getOrganizations()->first()->getId(),
            '{invite}' => $this->invite1->getId(),
            '{apiKey}' => $this->apiKey1->getId(),
            '{member_type}' => $this->domain->getDomainMemberTypes()->first()->getIdentifier(),
        ];

        $this->assertAccess('/', true, $substitutions, ['GET']);
        $this->assertRedirect('/profile/login', '/', $substitutions);
        $this->assertRedirect('/profile/reset-password', '/', $substitutions);
        $this->assertRedirect('/profile/reset-password-confirm', '/', $substitutions);
        $this->assertAccess('/profile/accept-invitation', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/profile/update', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/user/', true, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/user/update/{member}', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/user/delete/{member}', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/', true, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/create', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/view/{domain}', true, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/update/{domain}', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/delete/{domain}', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/{domain}/member/{member_type}', true, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/{domain}/member/{member_type}/create', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/{domain}/member/{member_type}/update/{member}', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/{domain}/member/{member_type}/delete/{member}', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess(
            '/{organization}/{domain}/member/{member_type}/delete-invite/{invite}',
            true,
            $substitutions,
            ['GET', 'POST']
        );
        $this->assertAccess('/{organization}/apikeys/', true, $substitutions, ['GET']);
        $this->assertAccess('/{organization}/apikeys/create', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/apikeys/update/{apiKey}', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess('/{organization}/apikeys/delete/{apiKey}', true, $substitutions, ['GET', 'POST']);
        $this->assertAccess(
            '/{organization}/{domain}/content/{content_type}/{view}',
            true,
            $substitutions,
            ['GET']
        );
        $this->assertAccess(
            '/{organization}/{domain}/content/{content_type}/{view}/create',
            true,
            $substitutions,
            ['GET', 'POST']
        );
        $this->assertAccess(
            '/{organization}/{domain}/content/{content_type}/{view}/update/{content}',
            true,
            $substitutions,
            ['GET', 'POST']
        );
        $this->assertAccess(
            '/{organization}/{domain}/content/{content_type}/{view}/delete/{content}',
            true,
            $substitutions,
            ['GET', 'POST']
        );
        $this->assertAccess('/{organization}/{domain}/setting/{setting_type}', true, $substitutions, ['GET', 'POST']);
    }

}
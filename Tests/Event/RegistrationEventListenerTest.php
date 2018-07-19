<?php
/**
 * Created by PhpStorm.
 * User: franzwilding
 * Date: 24.05.18
 * Time: 13:36
 */

namespace UniteCMS\CoreBundle\Tests\Event;

use Symfony\Bundle\FrameworkBundle\Client;
use Symfony\Bundle\FrameworkBundle\Routing\Router;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use UniteCMS\CoreBundle\Entity\Domain;
use UniteCMS\CoreBundle\Entity\Invitation;
use UniteCMS\CoreBundle\Entity\Organization;
use UniteCMS\CoreBundle\Event\RegistrationEvent;
use UniteCMS\CoreBundle\Tests\DatabaseAwareTestCase;

class RegistrationEventListenerTest extends DatabaseAwareTestCase
{
    /**
     * @var Client $client
     */
    private $client;

    /**
     * @var Invitation $invitation
     */
    private $invitation;

    /**
     * @var Domain $domain
     */
    private $domain;

    public function setUp()
    {
        parent::setUp();

        $this->client = static::$container->get('test.client');
        $this->client->followRedirects(false);
        $this->client->disableReboot();

        $org = new Organization();
        $org->setIdentifier('org')->setTitle('Org');
        $this->domain = new Domain();
        $this->domain->setIdentifier('domain')->setTitle('Domain')->setOrganization($org);

        $this->invitation = new Invitation();
        $this->invitation
            ->setEmail('test@example.com')
            ->setToken('token')
            ->setOrganization($org)
            ->setRequestedAt(new \DateTime('now'));

        static::$container->get('doctrine.orm.entity_manager')->persist($org);
        static::$container->get('doctrine.orm.entity_manager')->persist($this->domain);
        static::$container->get('doctrine.orm.entity_manager')->persist($this->invitation);
        static::$container->get('doctrine.orm.entity_manager')->flush();
    }

    public function testInvitationRegistrationEvents() {

        $subscriberMock = new class implements EventSubscriberInterface
        {
            public $events = [];

            public static function getSubscribedEvents()
            {
                return [
                    RegistrationEvent::REGISTRATION_COMPLETE => 'onComplete',
                    RegistrationEvent::REGISTRATION_SUCCESS => 'onSuccess',
                    RegistrationEvent::REGISTRATION_FAILURE => 'onFailure',
                ];
            }

            public function onComplete(RegistrationEvent $event)
            {
                $this->events[] = ['type' => RegistrationEvent::REGISTRATION_COMPLETE, 'event' => $event];
            }

            public function onSuccess(RegistrationEvent $event)
            {
                $this->events[] = ['type' => RegistrationEvent::REGISTRATION_SUCCESS, 'event' => $event];
            }

            public function onFailure(RegistrationEvent $event)
            {
                $this->events[] = ['type' => RegistrationEvent::REGISTRATION_FAILURE, 'event' => $event];
            }
        };
        $this->client->getContainer()->get('event_dispatcher')->addSubscriber($subscriberMock);
        $this->client->disableReboot();

        $crawler = $this->client->request('GET', static::$container->get('router')->generate(
            'unitecms_core_profile_acceptinvitation',
            ['token' => $this->invitation->getToken()],
            Router::ABSOLUTE_URL
        ));

        $form = $crawler->filter('form');
        $this->assertCount(1, $form);
        $form = $form->form();

        $form['invitation_registration[name]'] = 'foo';
        $form['invitation_registration[email]'] = 'baa@baa.com';
        $form['invitation_registration[password][first]'] = 'password';
        $form['invitation_registration[password][second]'] = 'password';

        // Submitting valid data should result in an success and complete invitation action.
        $this->client->submit($form);

        $this->assertCount(2, $subscriberMock->events);
        $this->assertEquals(RegistrationEvent::REGISTRATION_SUCCESS, $subscriberMock->events[0]['type']);
        $this->assertEquals(RegistrationEvent::REGISTRATION_COMPLETE, $subscriberMock->events[1]['type']);

        $this->assertEquals('foo', $subscriberMock->events[0]['event']->getRegistrationModel()->getName());
        $this->assertEquals('test@example.com', $subscriberMock->events[0]['event']->getRegistrationModel()->getEmail());
        $this->assertEmpty($subscriberMock->events[0]['event']->getRegistrationModel()->getPassword());

        $this->assertEquals('foo', $subscriberMock->events[1]['event']->getRegistrationModel()->getName());
        $this->assertEquals('test@example.com', $subscriberMock->events[1]['event']->getRegistrationModel()->getEmail());
        $this->assertEmpty($subscriberMock->events[1]['event']->getRegistrationModel()->getPassword());
    }

    public function testInvitationRegistrationFailure() {

        $subscriberMock = new class implements EventSubscriberInterface
        {
            public $events = [];

            public static function getSubscribedEvents()
            {
                return [
                    RegistrationEvent::REGISTRATION_COMPLETE => 'onComplete',
                    RegistrationEvent::REGISTRATION_SUCCESS => 'onSuccess',
                    RegistrationEvent::REGISTRATION_FAILURE => 'onFailure',
                ];
            }

            public function onComplete(RegistrationEvent $event)
            {
                $this->events[] = ['type' => RegistrationEvent::REGISTRATION_COMPLETE, 'event' => $event];
            }

            public function onSuccess(RegistrationEvent $event)
            {
                $this->events[] = ['type' => RegistrationEvent::REGISTRATION_SUCCESS, 'event' => $event];
            }

            public function onFailure(RegistrationEvent $event)
            {
                $this->events[] = ['type' => RegistrationEvent::REGISTRATION_FAILURE, 'event' => $event];
            }
        };
        $this->client->getContainer()->get('event_dispatcher')->addSubscriber($subscriberMock);
        $this->client->disableReboot();

        $crawler = $this->client->request('GET', static::$container->get('router')->generate(
            'unitecms_core_profile_acceptinvitation',
            ['token' => $this->invitation->getToken()],
            Router::ABSOLUTE_URL
        ));

        $form = $crawler->filter('form');
        $this->assertCount(1, $form);
        $form = $form->form();

        $form['invitation_registration[name]'] = 'foo';
        $form['invitation_registration[email]'] = 'baa@baa.com';
        $form['invitation_registration[password][first]'] = 'password';
        $form['invitation_registration[password][second]'] = 'password';

        // Manipulate domain org, so user validation will fail.
        $org2 = new Organization();
        $org2->setIdentifier('org2')->setTitle('org2')->setId(2);
        $this->invitation->setOrganization($org2)->setDomainMemberType($this->domain->getDomainMemberTypes()->first());
        $org2->setDomains([]);

        // Submitting valid data should result in an success and complete invitation action.
        $this->client->submit($form);

        $this->assertCount(1, $subscriberMock->events);
        $this->assertEquals(RegistrationEvent::REGISTRATION_FAILURE, $subscriberMock->events[0]['type']);

        $this->assertEquals('foo', $subscriberMock->events[0]['event']->getRegistrationModel()->getName());
        $this->assertEquals('test@example.com', $subscriberMock->events[0]['event']->getRegistrationModel()->getEmail());
        $this->assertEmpty($subscriberMock->events[0]['event']->getRegistrationModel()->getPassword());
    }

}
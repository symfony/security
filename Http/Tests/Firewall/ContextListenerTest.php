<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Security\Http\Tests\Firewall;

use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\AnonymousToken;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\UsageTrackingTokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UsernameNotFoundException;
use Symfony\Component\Security\Core\User\User;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;
use Symfony\Component\Security\Http\Event\DeauthenticatedEvent;
use Symfony\Component\Security\Http\Firewall\ContextListener;
use Symfony\Component\Security\Http\RememberMe\RememberMeServicesInterface;
use Symfony\Contracts\Service\ServiceLocatorTrait;

class ContextListenerTest extends TestCase
{
    public function testItRequiresContextKey()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$contextKey must not be empty');
        new ContextListener(
            $this->createMock(TokenStorageInterface::class),
            [],
            ''
        );
    }

    public function testUserProvidersNeedToImplementAnInterface()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('User provider "stdClass" must implement "Symfony\Component\Security\Core\User\UserProviderInterface');
        $this->handleEventWithPreviousSession([new \stdClass()]);
    }

    public function testOnKernelResponseWillAddSession()
    {
        $session = $this->runSessionOnKernelResponse(
            new UsernamePasswordToken('test1', 'pass1', 'phpunit'),
            null
        );

        $token = unserialize($session->get('_security_session'));
        $this->assertInstanceOf(UsernamePasswordToken::class, $token);
        $this->assertEquals('test1', $token->getUsername());
    }

    public function testOnKernelResponseWillReplaceSession()
    {
        $session = $this->runSessionOnKernelResponse(
            new UsernamePasswordToken('test1', 'pass1', 'phpunit'),
            'C:10:"serialized"'
        );

        $token = unserialize($session->get('_security_session'));
        $this->assertInstanceOf(UsernamePasswordToken::class, $token);
        $this->assertEquals('test1', $token->getUsername());
    }

    public function testOnKernelResponseWillRemoveSession()
    {
        $session = $this->runSessionOnKernelResponse(
            null,
            'C:10:"serialized"'
        );

        $this->assertFalse($session->has('_security_session'));
    }

    public function testOnKernelResponseWillRemoveSessionOnAnonymousToken()
    {
        $session = $this->runSessionOnKernelResponse(new AnonymousToken('secret', 'anon.'), 'C:10:"serialized"');

        $this->assertFalse($session->has('_security_session'));
    }

    public function testOnKernelResponseWithoutSession()
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken('test1', 'pass1', 'phpunit'));
        $request = new Request();
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);

        $event = new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MASTER_REQUEST,
            new Response()
        );

        $listener = new ContextListener($tokenStorage, [], 'session', null, new EventDispatcher());
        $listener->onKernelResponse($event);

        $this->assertTrue($session->isStarted());
    }

    public function testOnKernelResponseWithoutSessionNorToken()
    {
        $request = new Request();
        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);

        $event = new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MASTER_REQUEST,
            new Response()
        );

        $listener = new ContextListener(new TokenStorage(), [], 'session', null, new EventDispatcher());
        $listener->onKernelResponse($event);

        $this->assertFalse($session->isStarted());
    }

    /**
     * @dataProvider provideInvalidToken
     */
    public function testInvalidTokenInSession($token)
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $request = $this->createMock(Request::class);
        $session = $this->createMock(SessionInterface::class);

        $request->expects($this->any())
            ->method('hasPreviousSession')
            ->willReturn(true);
        $request->expects($this->any())
            ->method('getSession')
            ->willReturn($session);
        $session->expects($this->any())
            ->method('get')
            ->with('_security_key123')
            ->willReturn($token);
        $tokenStorage->expects($this->once())
            ->method('setToken')
            ->with(null);

        $listener = new ContextListener($tokenStorage, [], 'key123');
        $listener(new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MASTER_REQUEST));
    }

    public function provideInvalidToken()
    {
        return [
            ['foo'],
            ['O:8:"NotFound":0:{}'],
            [serialize(new \__PHP_Incomplete_Class())],
            [serialize(null)],
            [null],
        ];
    }

    public function testHandleAddsKernelResponseListener()
    {
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);
        $listener = new ContextListener($tokenStorage, [], 'key123', null, $dispatcher);

        $dispatcher->expects($this->once())
            ->method('addListener')
            ->with(KernelEvents::RESPONSE, [$listener, 'onKernelResponse']);

        $listener(new RequestEvent($this->createMock(HttpKernelInterface::class), new Request(), HttpKernelInterface::MASTER_REQUEST));
    }

    public function testOnKernelResponseListenerRemovesItself()
    {
        $session = $this->createMock(SessionInterface::class);
        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $dispatcher = $this->createMock(EventDispatcherInterface::class);

        $listener = new ContextListener($tokenStorage, [], 'key123', null, $dispatcher);

        $request = $this->createMock(Request::class);
        $request->expects($this->any())
            ->method('hasSession')
            ->willReturn(true);
        $request->expects($this->any())
            ->method('getSession')
            ->willReturn($session);

        $event = new ResponseEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MASTER_REQUEST, new Response());

        $dispatcher->expects($this->once())
            ->method('removeListener')
            ->with(KernelEvents::RESPONSE, [$listener, 'onKernelResponse']);

        $listener->onKernelResponse($event);
    }

    public function testHandleRemovesTokenIfNoPreviousSessionWasFound()
    {
        $request = $this->createMock(Request::class);
        $request->expects($this->any())->method('hasPreviousSession')->willReturn(false);

        $tokenStorage = $this->createMock(TokenStorageInterface::class);
        $tokenStorage->expects($this->once())->method('setToken')->with(null);

        $listener = new ContextListener($tokenStorage, [], 'key123');
        $listener(new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MASTER_REQUEST));
    }

    public function testIfTokenIsDeauthenticated()
    {
        $refreshedUser = new User('foobar', 'baz');
        $tokenStorage = $this->handleEventWithPreviousSession([new NotSupportingUserProvider(true), new NotSupportingUserProvider(false), new SupportingUserProvider($refreshedUser)]);

        $this->assertNull($tokenStorage->getToken());
    }

    public function testIfTokenIsNotDeauthenticated()
    {
        $tokenStorage = new TokenStorage();
        $badRefreshedUser = new User('foobar', 'baz');
        $goodRefreshedUser = new User('foobar', 'bar');
        $tokenStorage = $this->handleEventWithPreviousSession([new SupportingUserProvider($badRefreshedUser), new SupportingUserProvider($goodRefreshedUser)], $goodRefreshedUser);
        $this->assertSame($goodRefreshedUser, $tokenStorage->getToken()->getUser());
    }

    public function testRememberMeGetsCanceledIfTokenIsDeauthenticated()
    {
        $tokenStorage = new TokenStorage();
        $refreshedUser = new User('foobar', 'baz');

        $rememberMeServices = $this->createMock(RememberMeServicesInterface::class);
        $rememberMeServices->expects($this->once())->method('loginFail');

        $tokenStorage = $this->handleEventWithPreviousSession([new NotSupportingUserProvider(true), new NotSupportingUserProvider(false), new SupportingUserProvider($refreshedUser)], null, $rememberMeServices);

        $this->assertNull($tokenStorage->getToken());
    }

    public function testTryAllUserProvidersUntilASupportingUserProviderIsFound()
    {
        $refreshedUser = new User('foobar', 'baz');
        $tokenStorage = $this->handleEventWithPreviousSession([new NotSupportingUserProvider(true), new NotSupportingUserProvider(false), new SupportingUserProvider($refreshedUser)], $refreshedUser);

        $this->assertSame($refreshedUser, $tokenStorage->getToken()->getUser());
    }

    public function testNextSupportingUserProviderIsTriedIfPreviousSupportingUserProviderDidNotLoadTheUser()
    {
        $refreshedUser = new User('foobar', 'baz');
        $tokenStorage = $this->handleEventWithPreviousSession([new NotSupportingUserProvider(true), new NotSupportingUserProvider(false), new SupportingUserProvider($refreshedUser)], $refreshedUser);

        $this->assertSame($refreshedUser, $tokenStorage->getToken()->getUser());
    }

    public function testTokenIsSetToNullIfNoUserWasLoadedByTheRegisteredUserProviders()
    {
        $tokenStorage = $this->handleEventWithPreviousSession([new NotSupportingUserProvider(true), new NotSupportingUserProvider(false), new SupportingUserProvider()]);

        $this->assertNull($tokenStorage->getToken());
    }

    public function testRuntimeExceptionIsThrownIfNoSupportingUserProviderWasRegistered()
    {
        $this->expectException(\RuntimeException::class);
        $this->handleEventWithPreviousSession([new NotSupportingUserProvider(false), new NotSupportingUserProvider(true)]);
    }

    public function testAcceptsProvidersAsTraversable()
    {
        $refreshedUser = new User('foobar', 'baz');
        $tokenStorage = $this->handleEventWithPreviousSession(new \ArrayObject([new NotSupportingUserProvider(true), new NotSupportingUserProvider(false), new SupportingUserProvider($refreshedUser)]), $refreshedUser);

        $this->assertSame($refreshedUser, $tokenStorage->getToken()->getUser());
    }

    public function testDeauthenticatedEvent()
    {
        $tokenStorage = new TokenStorage();
        $refreshedUser = new User('foobar', 'baz');

        $user = new User('foo', 'bar');
        $session = new Session(new MockArraySessionStorage());
        $session->set('_security_context_key', serialize(new UsernamePasswordToken($user, '', 'context_key', ['ROLE_USER'])));

        $request = new Request();
        $request->setSession($session);
        $request->cookies->set('MOCKSESSID', true);

        $eventDispatcher = new EventDispatcher();
        $eventDispatcher->addListener(DeauthenticatedEvent::class, function (DeauthenticatedEvent $event) use ($user) {
            $this->assertTrue($event->getOriginalToken()->isAuthenticated());
            $this->assertEquals($event->getOriginalToken()->getUser(), $user);
            $this->assertFalse($event->getRefreshedToken()->isAuthenticated());
            $this->assertNotEquals($event->getRefreshedToken()->getUser(), $user);
        });

        $listener = new ContextListener($tokenStorage, [new NotSupportingUserProvider(true), new NotSupportingUserProvider(false), new SupportingUserProvider($refreshedUser)], 'context_key', null, $eventDispatcher);
        $listener(new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MASTER_REQUEST));

        $this->assertNull($tokenStorage->getToken());
    }

    /**
     * @requires function \Symfony\Component\HttpFoundation\Request::getPreferredFormat
     */
    public function testWithPreviousNotStartedSession()
    {
        $session = new Session(new MockArraySessionStorage());

        $request = new Request();
        $request->setSession($session);
        $request->cookies->set('MOCKSESSID', true);

        $usageIndex = $session->getUsageIndex();

        $tokenStorage = new TokenStorage();
        $listener = new ContextListener($tokenStorage, [], 'context_key', null, null, null, [$tokenStorage, 'getToken']);
        $listener(new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MASTER_REQUEST));

        $this->assertSame($usageIndex, $session->getUsageIndex());
    }

    public function testOnKernelResponseRemoveListener()
    {
        $tokenStorage = new TokenStorage();
        $tokenStorage->setToken(new UsernamePasswordToken('test1', 'pass1', 'phpunit', ['ROLE_USER']));

        $request = new Request();
        $request->attributes->set('_security_firewall_run', '_security_session');

        $session = new Session(new MockArraySessionStorage());
        $request->setSession($session);

        $dispatcher = new EventDispatcher();
        $httpKernel = $this->createMock(HttpKernelInterface::class);

        $listener = new ContextListener($tokenStorage, [], 'session', null, $dispatcher, null, \Closure::fromCallable([$tokenStorage, 'getToken']));
        $this->assertEmpty($dispatcher->getListeners());

        $listener(new RequestEvent($httpKernel, $request, HttpKernelInterface::MASTER_REQUEST));
        $this->assertNotEmpty($dispatcher->getListeners());

        $listener->onKernelResponse(new ResponseEvent($httpKernel, $request, HttpKernelInterface::MASTER_REQUEST, new Response()));
        $this->assertEmpty($dispatcher->getListeners());
    }

    protected function runSessionOnKernelResponse($newToken, $original = null)
    {
        $session = new Session(new MockArraySessionStorage());

        if (null !== $original) {
            $session->set('_security_session', $original);
        }

        $tokenStorage = new UsageTrackingTokenStorage(new TokenStorage(), new class(['session' => function () use ($session) {
            return $session;
        },
        ]) implements ContainerInterface {
            use ServiceLocatorTrait;
        });

        $tokenStorage->setToken($newToken);

        $request = new Request();
        $request->setSession($session);
        $request->cookies->set('MOCKSESSID', true);

        $sessionId = $session->getId();
        $usageIndex = method_exists(Request::class, 'getPreferredFormat') ? $session->getUsageIndex() : null;

        $event = new ResponseEvent(
            $this->createMock(HttpKernelInterface::class),
            $request,
            HttpKernelInterface::MASTER_REQUEST,
            new Response()
        );

        $listener = new ContextListener($tokenStorage, [], 'session', null, new EventDispatcher(), null, [$tokenStorage, 'enableUsageTracking']);
        $listener->onKernelResponse($event);

        if (null !== $usageIndex) {
            if ($session->getId() === $sessionId) {
                $this->assertSame($usageIndex, $session->getUsageIndex());
            } else {
                $this->assertNotSame($usageIndex, $session->getUsageIndex());
            }
        }

        return $session;
    }

    private function handleEventWithPreviousSession($userProviders, UserInterface $user = null, RememberMeServicesInterface $rememberMeServices = null)
    {
        $tokenUser = $user ?? new User('foo', 'bar');
        $session = new Session(new MockArraySessionStorage());
        $session->set('_security_context_key', serialize(new UsernamePasswordToken($tokenUser, '', 'context_key', ['ROLE_USER'])));

        $request = new Request();
        $request->setSession($session);
        $request->cookies->set('MOCKSESSID', true);

        $tokenStorage = new TokenStorage();
        $usageIndex = null;
        $sessionTrackerEnabler = null;

        if (method_exists(Request::class, 'getPreferredFormat')) {
            $usageIndex = $session->getUsageIndex();
            $tokenStorage = new UsageTrackingTokenStorage($tokenStorage, new class(['session' => function () use ($session) {
                return $session;
            },
            ]) implements ContainerInterface {
                use ServiceLocatorTrait;
            });
            $sessionTrackerEnabler = [$tokenStorage, 'enableUsageTracking'];
        }

        $listener = new ContextListener($tokenStorage, $userProviders, 'context_key', null, null, null, $sessionTrackerEnabler);

        if ($rememberMeServices) {
            $listener->setRememberMeServices($rememberMeServices);
        }
        $listener(new RequestEvent($this->createMock(HttpKernelInterface::class), $request, HttpKernelInterface::MASTER_REQUEST));

        if (null !== $usageIndex) {
            if (null !== $user) {
                ++$usageIndex;
            }

            $this->assertSame($usageIndex, $session->getUsageIndex());
            $tokenStorage->getToken();
            $this->assertSame(1 + $usageIndex, $session->getUsageIndex());
        }

        return $tokenStorage;
    }
}

class NotSupportingUserProvider implements UserProviderInterface
{
    /** @var bool */
    private $throwsUnsupportedException;

    public function __construct($throwsUnsupportedException)
    {
        $this->throwsUnsupportedException = $throwsUnsupportedException;
    }

    public function loadUserByUsername($username): UserInterface
    {
        throw new UsernameNotFoundException();
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if ($this->throwsUnsupportedException) {
            throw new UnsupportedUserException();
        }

        return $user;
    }

    public function supportsClass($class): bool
    {
        return false;
    }
}

class SupportingUserProvider implements UserProviderInterface
{
    private $refreshedUser;

    public function __construct(User $refreshedUser = null)
    {
        $this->refreshedUser = $refreshedUser;
    }

    public function loadUserByUsername($username): UserInterface
    {
    }

    public function refreshUser(UserInterface $user): UserInterface
    {
        if (!$user instanceof User) {
            throw new UnsupportedUserException();
        }

        if (null === $this->refreshedUser) {
            throw new UsernameNotFoundException();
        }

        return $this->refreshedUser;
    }

    public function supportsClass($class): bool
    {
        return 'Symfony\Component\Security\Core\User\User' === $class;
    }
}

<?php
/**
 * @link      http://github.com/zendframework/zend-servicemanager for the canonical source repository
 * @copyright Copyright (c) 2005-2016 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */

namespace ZendTest\ServiceManager;

use DateTime;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use stdClass;
use Zend\ServiceManager\Factory\FactoryInterface;
use Zend\ServiceManager\Factory\InvokableFactory;
use Zend\ServiceManager\ServiceManager;
use ZendTest\ServiceManager\TestAsset\Foo;
use ZendTest\ServiceManager\TestAsset\InvokableObject;
use ZendTest\ServiceManager\TestAsset\PreconfiguredServiceManager;
use ZendTest\ServiceManager\TestAsset\SimpleServiceManager;

/**
 * @covers \Zend\ServiceManager\ServiceManager
 */
class ServiceManagerTest extends TestCase
{
    use CommonServiceLocatorBehaviorsTrait;

    public function createContainer(array $config = [])
    {
        $this->creationContext = new ServiceManager($config);
        return $this->creationContext;
    }

    public function testServiceManagerIsAPsr11Container()
    {
        $container = $this->createContainer();
        $this->assertInstanceOf(ContainerInterface::class, $container);
    }

    public function testConfigurationCanBeMerged()
    {
        $serviceManager = new SimpleServiceManager([
            'factories' => [
                DateTime::class => InvokableFactory::class
            ]
        ]);

        $this->assertTrue($serviceManager->has(DateTime::class));
        // stdClass service is inlined in SimpleServiceManager
        $this->assertTrue($serviceManager->has(stdClass::class));
    }

    public function testConfigurationTakesPrecedenceWhenMerged()
    {
        $factory = $this->getMockBuilder(FactoryInterface::class)
            ->getMock();

        $factory->expects($this->once())->method('__invoke');

        $serviceManager = new SimpleServiceManager([
            'factories' => [
                stdClass::class => $factory
            ]
        ]);

        $serviceManager->get(stdClass::class);
    }

    /**
     * @covers \Zend\ServiceManager\ServiceManager::doCreate
     * @covers \Zend\ServiceManager\ServiceManager::createDelegatorFromName
     */
    public function testCanWrapCreationInDelegators()
    {
        $config = [
            'option' => 'OPTIONED',
        ];
        $serviceManager = new ServiceManager([
            'services'  => [
                'config' => $config,
            ],
            'factories' => [
                stdClass::class => InvokableFactory::class,
            ],
            'delegators' => [
                stdClass::class => [
                    TestAsset\PreDelegator::class,
                    function ($container, $name, $callback) {
                        $instance = $callback();
                        $instance->foo = 'bar';
                        return $instance;
                    },
                ],
            ],
        ]);

        $instance = $serviceManager->get(stdClass::class);
        $this->assertTrue(isset($instance->option), 'Delegator-injected option was not found');
        $this->assertEquals(
            $config['option'],
            $instance->option,
            'Delegator-injected option does not match configuration'
        );
        $this->assertEquals('bar', $instance->foo);
    }

    public function shareProvider()
    {
        $sharedByDefault          = true;
        $serviceShared            = true;
        $serviceDefined           = true;
        $shouldReturnSameInstance = true;

        // @codingStandardsIgnoreStart
        return [
            // Description => [$sharedByDefault, $serviceShared, $serviceDefined, $expectedInstance]
            'SharedByDefault: T, ServiceIsExplicitlyShared: T, ServiceIsDefined: T' => [ $sharedByDefault,  $serviceShared,  $serviceDefined,  $shouldReturnSameInstance],
            'SharedByDefault: T, ServiceIsExplicitlyShared: T, ServiceIsDefined: F' => [ $sharedByDefault,  $serviceShared, !$serviceDefined,  $shouldReturnSameInstance],
            'SharedByDefault: T, ServiceIsExplicitlyShared: F, ServiceIsDefined: T' => [ $sharedByDefault, !$serviceShared,  $serviceDefined, !$shouldReturnSameInstance],
            'SharedByDefault: T, ServiceIsExplicitlyShared: F, ServiceIsDefined: F' => [ $sharedByDefault, !$serviceShared, !$serviceDefined,  $shouldReturnSameInstance],
            'SharedByDefault: F, ServiceIsExplicitlyShared: T, ServiceIsDefined: T' => [!$sharedByDefault,  $serviceShared,  $serviceDefined,  $shouldReturnSameInstance],
            'SharedByDefault: F, ServiceIsExplicitlyShared: T, ServiceIsDefined: F' => [!$sharedByDefault,  $serviceShared, !$serviceDefined, !$shouldReturnSameInstance],
            'SharedByDefault: F, ServiceIsExplicitlyShared: F, ServiceIsDefined: T' => [!$sharedByDefault, !$serviceShared,  $serviceDefined, !$shouldReturnSameInstance],
            'SharedByDefault: F, ServiceIsExplicitlyShared: F, ServiceIsDefined: F' => [!$sharedByDefault, !$serviceShared, !$serviceDefined, !$shouldReturnSameInstance],
        ];
        // @codingStandardsIgnoreEnd
    }

    /**
     * @dataProvider shareProvider
     */
    public function testShareability($sharedByDefault, $serviceShared, $serviceDefined, $shouldBeSameInstance)
    {
        $config = [
            'shared_by_default' => $sharedByDefault,
            'factories'         => [
                stdClass::class => InvokableFactory::class,
            ]
        ];

        if ($serviceDefined) {
            $config['shared'] = [
                stdClass::class => $serviceShared
            ];
        }

        $serviceManager = new ServiceManager($config);

        $a = $serviceManager->get(stdClass::class);
        $b = $serviceManager->get(stdClass::class);

        $this->assertEquals($shouldBeSameInstance, $a === $b);
    }

    public function testMapsOneToOneInvokablesAsInvokableFactoriesInternally()
    {
        $config = [
            'invokables' => [
                InvokableObject::class => InvokableObject::class,
            ],
        ];

        $serviceManager = new ServiceManager($config);
        $this->assertAttributeSame([
            InvokableObject::class => InvokableFactory::class,
        ], 'factories', $serviceManager, 'Invokable object factory not found');
    }

    public function testMapsNonSymmetricInvokablesAsAliasPlusInvokableFactory()
    {
        $config = [
            'invokables' => [
                'Invokable' => InvokableObject::class,
            ],
        ];

        $serviceManager = new ServiceManager($config);
        $this->assertAttributeSame([
            'Invokable' => InvokableObject::class,
        ], 'aliases', $serviceManager, 'Alias not found for non-symmetric invokable');
        $this->assertAttributeSame([
            InvokableObject::class => InvokableFactory::class,
        ], 'factories', $serviceManager, 'Factory not found for non-symmetric invokable target');
    }

    /**
     * @depends testMapsNonSymmetricInvokablesAsAliasPlusInvokableFactory
     */
    public function testSharedServicesReferencingInvokableAliasShouldBeHonored()
    {
        $config = [
            'invokables' => [
                'Invokable' => InvokableObject::class,
            ],
            'shared' => [
                'Invokable' => false,
            ],
        ];

        $serviceManager = new ServiceManager($config);
        $instance1 = $serviceManager->get('Invokable');
        $instance2 = $serviceManager->get('Invokable');

        $this->assertNotSame($instance1, $instance2);
    }

    public function testSharedServicesReferencingAliasShouldBeHonored()
    {
        $config = [
            'aliases' => [
                'Invokable' => InvokableObject::class,
            ],
            'factories' => [
                InvokableObject::class => InvokableFactory::class,
            ],
            'shared' => [
                'Invokable' => false,
            ],
        ];

        $serviceManager = new ServiceManager($config);
        $instance1 = $serviceManager->get('Invokable');
        $instance2 = $serviceManager->get('Invokable');

        $this->assertNotSame($instance1, $instance2);
    }

    public function testAliasToAnExplicitServiceShouldWork()
    {
        $config = [
            'aliases' => [
                'Invokable' => InvokableObject::class,
            ],
            'services' => [
                InvokableObject::class => new InvokableObject(),
            ],
        ];

        $serviceManager = new ServiceManager($config);

        $service = $serviceManager->get(InvokableObject::class);
        $alias   = $serviceManager->get('Invokable');

        $this->assertSame($service, $alias);
    }

    /**
     * @depends testAliasToAnExplicitServiceShouldWork
     */
    public function testSetAliasShouldWorkWithRecursiveAlias()
    {
        $config = [
            'aliases' => [
                'Alias' => 'TailInvokable',
            ],
            'services' => [
                InvokableObject::class => new InvokableObject(),
            ],
        ];
        $serviceManager = new ServiceManager($config);
        $serviceManager->setAlias('HeadAlias', 'Alias');
        $serviceManager->setAlias('TailInvokable', InvokableObject::class);

        $service   = $serviceManager->get(InvokableObject::class);
        $alias     = $serviceManager->get('Alias');
        $headAlias = $serviceManager->get('HeadAlias');

        $this->assertSame($service, $alias);
        $this->assertSame($service, $headAlias);
    }

    public static function sampleFactory()
    {
        return new stdClass();
    }

    public function testFactoryMayBeStaticMethodDescribedByCallableString()
    {
        $config = [
            'factories' => [
                stdClass::class => 'ZendTest\ServiceManager\ServiceManagerTest::sampleFactory',
            ]
        ];
        $serviceManager = new SimpleServiceManager($config);
        $this->assertEquals(stdClass::class, get_class($serviceManager->get(stdClass::class)));
    }

    public function testMemberBasedConfigurationGetsApplied()
    {
        $sm = new PreconfiguredServiceManager();

        // will be true if $aliases array is properly setup and
        // recursive alias resolution works
        $this->assertTrue($sm->has('alias1'));
        $this->assertInstanceOf(stdClass::class, $sm->get('alias1'));

        // will be true if $aliases array is properly setup and
        // simple alias resolution works
        $this->assertTrue($sm->has('alias2'));
        $this->assertInstanceOf(stdClass::class, $sm->get('alias2'));

        // will return true if $services array is properly setup
        $this->assertTrue($sm->has('service'));
        $this->assertInstanceOf(stdClass::class, $sm->get('service'));

        // will be true if factory array is properly setup
        $this->assertTrue($sm->has('delegator'));
        $this->assertInstanceOf(InvokableObject::class, $sm->get('delegator'));

        // will be true if initializer is present
        $this->assertTrue($sm->get('delegator')->initializerPresent);

        // will be true if factory array is properly setup
        $this->assertTrue($sm->has('factory'));
        $this->assertInstanceOf(InvokableObject::class, $sm->get('factory'));

        // will be true if initializer is present
        $this->assertTrue($sm->get('factory')->initializerPresent);

        // same problem with invokables
        // see next commit

        // will succeed if invokable is properly set up
//         $this->assertTrue($sm->has('invokable'));
//         $this->assertInstanceOf(stdClass::class, $sm->get('invokable'));

        // will be true if initializer is present
//        $this->assertTrue($sm->get('invokable')->initializerPresent);

        // will succeed if abstract factory is available
        $this->assertTrue($sm->has('foo'));
        $this->assertInstanceOf(Foo::class, $sm->get('foo'));

        // will be true if initializer is present
        $this->assertTrue($sm->get('foo')->initializerPresent);
    }
}

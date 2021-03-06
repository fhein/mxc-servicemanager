<?php
/**
 * @link      http://github.com/zendframework/zend-servicemanager for the canonical source repository
 * @copyright Copyright (c) 2016 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   http://framework.zend.com/license/new-bsd New BSD License
 */

namespace ZendBench\ServiceManager;

use PhpBench\Benchmark\Metadata\Annotations\Iterations;
use PhpBench\Benchmark\Metadata\Annotations\Revs;
use PhpBench\Benchmark\Metadata\Annotations\Warmup;
use Zend\ServiceManager\ServiceManager;

/**
 * @Revs(500)
 * @Iterations(20)
 * @Warmup(2)
 */
class FetchNewServiceManagerBench
{
    const NUM_SERVICES = 1000;

    /**
     * @var array
     */
    private $config = [];

    public function __construct()
    {
        $config = [
            'factories'          => [],
            'invokables'         => [],
            'services'           => [],
            'aliases'            => [],
            'abstract_factories' => [
                BenchAsset\AbstractFactoryFoo::class,
            ],
        ];

        $service = new \stdClass();

        for ($i = 0; $i <= self::NUM_SERVICES; $i++) {
            $config['factories']["factory_$i"]    = BenchAsset\FactoryFoo::class;
            $config['invokables']["invokable_$i"] = BenchAsset\Foo::class;
            $config['services']["service_$i"]     = $service;
            $config['aliases']["alias_$i"]        = "service_$i";
        }
        $this->config = $config;
    }

    public function benchFetchServiceManagerCreation()
    {
        new ServiceManager($this->config);
    }
}

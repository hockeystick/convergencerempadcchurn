<?php

namespace Crm\ChurnPredictionModule\DI;

use Nette\DI\CompilerExtension;
use Nette\Schema\Expect;
use Nette\Schema\Schema;

class ChurnPredictionModuleExtension extends CompilerExtension
{
    public function getConfigSchema(): Schema
    {
        return Expect::structure([
            'bigquery' => Expect::structure([
                'service_account_path' => Expect::string()->dynamic()->nullable(),
                'project' => Expect::string()->dynamic()->nullable(),
                'dataset' => Expect::string()->dynamic()->nullable(),
            ])->castTo('array'),
        ])->castTo('array');
    }

    public function loadConfiguration()
    {
        $builder = $this->getContainerBuilder();

        // set extension parameters for use in config
        $builder->parameters['churnPrediction'] = $this->config;

        // load services from config and register them to Nette\DI Container
        $this->compiler->loadDefinitionsFromConfig(
            $this->loadFromFile(__DIR__ . '/../config/config.neon')['services'],
        );
    }
}

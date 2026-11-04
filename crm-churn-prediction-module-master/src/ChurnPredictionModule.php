<?php

namespace Crm\ChurnPredictionModule;

use Crm\ApplicationModule\Application\CommandsContainerInterface;
use Crm\ApplicationModule\CrmModule;
use Crm\ChurnPredictionModule\Commands\DownloadChurnPredictionsCommand;
use Crm\ChurnPredictionModule\Commands\RunChurnPipelineCommand;

class ChurnPredictionModule extends CrmModule
{
    public function registerCommands(CommandsContainerInterface $commandsContainer)
    {
        $commandsContainer->registerCommand($this->getInstance(RunChurnPipelineCommand::class));
        $commandsContainer->registerCommand($this->getInstance(DownloadChurnPredictionsCommand::class));
    }
}

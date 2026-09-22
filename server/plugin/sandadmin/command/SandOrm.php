<?php

namespace plugin\sandadmin\command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Keeps the fork from overwriting its local core with an upstream package.
 */
class SandOrm extends Command
{
    protected static $defaultName = 'sand:orm';
    protected static $defaultDescription = '显示 SandAdmin ORM 维护说明';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->warning('SandAdmin 不支持在运行时用第三方 vendor 覆盖核心 ORM。请通过受审查的 Git 变更维护 ORM 实现。');
        return Command::SUCCESS;
    }
}

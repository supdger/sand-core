<?php

namespace plugin\sandadmin\command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * SandAdmin is updated through its own reviewed Git releases.
 */
class SandUpgrade extends Command
{
    protected static $defaultName = 'sand:upgrade';
    protected static $defaultDescription = '显示 SandAdmin 升级说明';

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->note('请通过 SandAdmin 的已审查 Git 发布版本升级；此命令不会从第三方 vendor 覆盖本地核心。');
        return Command::SUCCESS;
    }
}
